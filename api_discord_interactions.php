<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/bdd.php';
require_once __DIR__ . '/api_discord.php';

// --- 1. VÉRIFICATION DE LA SIGNATURE DISCORD (OBLIGATOIRE) ---
$signature = $_SERVER['HTTP_X_SIGNATURE_ED25519'] ?? '';
$timestamp = $_SERVER['HTTP_X_SIGNATURE_TIMESTAMP'] ?? '';
$raw_body = file_get_contents('php://input');

// Si la clé publique n'est pas configurée, on refuse
if (empty($DISCORD_PUBLIC_KEY) || $DISCORD_PUBLIC_KEY === 'METTEZ_VOTRE_CLE_PUBLIQUE_ICI') {
    http_response_code(500);
    die('Erreur: Clé publique non configurée sur le serveur.');
}

if (empty($signature) || empty($timestamp)) {
    http_response_code(401);
    die('Signature verification failed');
}

// On utilise l'extension Sodium (disponible par défaut sur PHP >= 7.2)
try {
    $public_key = hex2bin($DISCORD_PUBLIC_KEY);
    $signature_bin = hex2bin($signature);
    $message = $timestamp . $raw_body;

    if (!sodium_crypto_sign_verify_detached($signature_bin, $message, $public_key)) {
        http_response_code(401);
        die('Invalid signature');
    }
} catch (Exception $e) {
    http_response_code(500);
    die('Erreur interne de cryptographie: ' . $e->getMessage());
}

$data = json_decode($raw_body, true);

// --- 2. RÉPONSE AU PING DISCORD (Vérification de l'URL) ---
if ($data['type'] == 1) { 
    header('Content-Type: application/json');
    echo json_encode(['type' => 1]); // 1 = PONG
    exit;
}

// --- 3. TRAITEMENT DU CLIC SUR UN BOUTON ---
if ($data['type'] == 3) { // 3 = MESSAGE_COMPONENT
    $custom_id = $data['data']['custom_id'] ?? ''; 
    $discord_user_id = $data['member']['user']['id'] ?? '';
    
    // On s'attend à un ID de type "vote_pour_123"
    if (preg_match('/^vote_(pour|neutre|contre)_(\d+)$/', $custom_id, $matches)) {
        $vote_type = $matches[1];
        $app_id = (int)$matches[2];
        
        $pdo = db();
        
        // On enregistre ou on met à jour le vote du recruteur
        // "ON DUPLICATE KEY UPDATE" permet au recruteur de changer d'avis
        $stmt = $pdo->prepare("
            INSERT INTO application_votes (application_id, discord_user_id, vote_type)
            VALUES (?, ?, ?)
            ON DUPLICATE KEY UPDATE vote_type = VALUES(vote_type), created_at = CURRENT_TIMESTAMP
        ");
        $stmt->execute([$app_id, $discord_user_id, $vote_type]);
        
        // On recompte les votes pour donner le résultat en direct
        $st = $pdo->prepare("SELECT vote_type, COUNT(*) as c FROM application_votes WHERE application_id = ? GROUP BY vote_type");
        $st->execute([$app_id]);
        $votes = ['pour' => 0, 'neutre' => 0, 'contre' => 0];
        foreach ($st->fetchAll() as $row) {
            $votes[$row['vote_type']] = $row['c'];
        }
        
        // On répond de façon "Éphémère" (seul le recruteur voit ce message)
        header('Content-Type: application/json');
        echo json_encode([
            'type' => 4, // 4 = CHANNEL_MESSAGE_WITH_SOURCE
            'data' => [
                'content' => "✅ Votre vote **" . strtoupper($vote_type) . "** a bien été pris en compte pour la candidature #$app_id.\n*Score actuel : ✅ {$votes['pour']} | ➖ {$votes['neutre']} | ❌ {$votes['contre']}*",
                'flags' => 64 // 64 = Message éphémère
            ]
        ]);
        exit;
    }
}

http_response_code(400);
