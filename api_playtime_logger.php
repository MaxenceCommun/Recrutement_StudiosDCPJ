<?php
// /recrutement/api_playtime_logger.php

// --- Configuration du débogage ---
// Met les erreurs dans un fichier de log dédié pour ne pas bloquer la réponse JSON.
ini_set('log_errors', 1);
ini_set('error_log', __DIR__.'/data/debug_api_playtime.log');
error_reporting(E_ALL);

require_once __DIR__.'/config.php';
require_once __DIR__.'/bdd.php';

// --- Sécurité ---
// Récupère le "secret" depuis les en-têtes de la requête.
// Votre script FiveM devra inclure cet en-tête pour prouver son identité.
$auth_header = $_SERVER['HTTP_X_FIVEM_SECRET'] ?? '';

// !! IMPORTANT : Cette clé secrète DOIT être la même que dans votre script LUA.
$API_FIVEM_SECRET = '5615119qzf196s1f65sedf1635s21dfs96ed415f';

// Ajout d'un log pour vérifier la clé reçue
error_log("[Playtime API] Requête reçue. Clé secrète reçue : " . $auth_header);

if (empty($API_FIVEM_SECRET) || $auth_header !== $API_FIVEM_SECRET) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Accès non autorisé. Clé secrète invalide.']);
    error_log("[Playtime API] ERREUR : Accès refusé. Clé attendue : " . $API_FIVEM_SECRET);
    exit;
}
// --- Fin Sécurité ---

header('Content-Type: application/json');

// --- CORRECTION : Récupération des données d'entrée ---
// On tente de lire le flux JSON, et si c'est vide, on se rabat sur $_POST.
$input_raw = file_get_contents('php://input');
$input = json_decode($input_raw, true);

// Si le JSON est vide ou invalide, on vérifie si les données sont dans $_POST
// (ce qui peut arriver selon la configuration du serveur)
if (json_last_error() !== JSON_ERROR_NONE || !$input) {
    $input = $_POST;
}

// Log des données brutes reçues pour le débogage
error_log("[Playtime API] Données brutes reçues : " . $input_raw);
error_log("[Playtime API] Données interprétées : " . print_r($input, true));


if (!$input || !isset($input['discord_id']) || !isset($input['event'])) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Données manquantes ou invalides.']);
    error_log("[Playtime API] ERREUR : Données manquantes. 'discord_id' ou 'event' non trouvé.");
    exit;
}

$discord_id = $input['discord_id'];
$event = $input['event']; // 'start_session' ou 'end_session'
$pdo = db();

// On récupère l'ID de l'utilisateur dans notre base de données
$stmt = $pdo->prepare("SELECT id FROM users WHERE discord_id = ?");
$stmt->execute([$discord_id]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    http_response_code(404);
    echo json_encode(['status' => 'error', 'message' => 'Utilisateur non trouvé.']);
    error_log("[Playtime API] ERREUR : Utilisateur avec Discord ID " . $discord_id . " non trouvé dans la base de données.");
    exit;
}
$user_id = $user['id'];
error_log("[Playtime API] Utilisateur trouvé (ID BDD: " . $user_id . ") pour l'événement '" . $event . "'.");

if ($event === 'start_session') {
    // Crée un nouvel enregistrement de session avec une date de début
    $stmt = $pdo->prepare("INSERT INTO playtime_logs (user_id, session_start) VALUES (?, NOW())");
    $stmt->execute([$user_id]);
    echo json_encode(['status' => 'success', 'message' => 'Session démarrée.']);
    error_log("[Playtime API] Session démarrée pour l'utilisateur ID " . $user_id);

} elseif ($event === 'end_session') {
    // On cherche la dernière session non terminée pour cet utilisateur
    $stmt = $pdo->prepare("SELECT id, session_start FROM playtime_logs WHERE user_id = ? AND session_end IS NULL ORDER BY session_start DESC LIMIT 1");
    $stmt->execute([$user_id]);
    $session = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($session) {
        $start_time = new DateTime($session['session_start']);
        $end_time = new DateTime();
        $duration = $end_time->getTimestamp() - $start_time->getTimestamp(); // Durée en secondes

        // Met à jour la session avec la date de fin et la durée
        $stmt_update = $pdo->prepare("UPDATE playtime_logs SET session_end = NOW(), duration = ? WHERE id = ?");
        $stmt_update->execute([$duration, $session['id']]);

        // Met à jour le temps de jeu total de l'utilisateur
        $stmt_total = $pdo->prepare("UPDATE users SET total_playtime = total_playtime + ? WHERE id = ?");
        $stmt_total->execute([$duration, $user_id]);

        echo json_encode(['status' => 'success', 'message' => 'Session terminée.', 'duration_added' => $duration]);
        error_log("[Playtime API] Session terminée pour l'utilisateur ID " . $user_id . ". Durée ajoutée : " . $duration . "s.");
    } else {
        http_response_code(404);
        echo json_encode(['status' => 'error', 'message' => 'Aucune session active trouvée pour terminer.']);
        error_log("[Playtime API] ERREUR : Tentative de terminer une session inexistante pour l'utilisateur ID " . $user_id);
    }
} else {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Événement non valide.']);
    error_log("[Playtime API] ERREUR : Événement '" . $event . "' non reconnu.");
}
?>