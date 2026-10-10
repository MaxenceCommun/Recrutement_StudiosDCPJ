<?php
// /api_get_player_status.php
// Ce script renvoie le statut actuel du joueur et son temps de jeu au format JSON.

require_once __DIR__.'/config.php';
require_once __DIR__.'/bdd.php';

// On s'assure qu'un utilisateur est bien connecté
if (empty($_SESSION['user']['id'])) {
    http_response_code(403);
    echo json_encode(['error' => 'Accès non autorisé']);
    exit;
}

$user_id = $_SESSION['user']['id'];
$pdo = db();

// On récupère le temps de jeu total déjà enregistré en base de données
$stmt_user = $pdo->prepare("SELECT total_playtime FROM users WHERE id = ?");
$stmt_user->execute([$user_id]);
$user = $stmt_user->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    http_response_code(404);
    echo json_encode(['error' => 'Utilisateur non trouvé']);
    exit;
}

// Initialisation des valeurs
$playtime_seconds = $user['total_playtime'];
$is_player_online = false;
$is_session_time = false;
$session_start_timestamp = 0; // On enverra 0 si le joueur n'est pas en session comptabilisée

// --- LOGIQUE DE VÉRIFICATION EN TEMPS RÉEL ---
$now = new DateTime();

// --- CONFIGURATION DES HEURES DE SESSION ---
// C'est ici que vous définissez la plage horaire de comptabilisation du temps de jeu.
// L'heure est au format 24h (0 à 23).
$heure_de_debut = 19; // Le temps compte à partir de 21h (inclus)
$heure_de_fin = 3;   // Le temps compte jusqu'à 3h (exclus, donc jusqu'à 02:59)

$current_hour = (int)$now->format('G');

// Si la plage horaire ne passe pas par minuit (ex: de 14h à 22h)
if ($heure_de_debut < $heure_de_fin) {
    $is_session_time = ($current_hour >= $heure_de_debut && $current_hour < $heure_de_fin);
} 
// Si la plage horaire passe par minuit (ex: de 21h à 3h)
else {
    $is_session_time = ($current_hour >= $heure_de_debut || $current_hour < $heure_de_fin);
}

// On cherche une session active
$stmt_online = $pdo->prepare("SELECT session_start FROM playtime_logs WHERE user_id = ? AND session_end IS NULL ORDER BY session_start DESC LIMIT 1");
$stmt_online->execute([$user_id]);
$active_session = $stmt_online->fetch(PDO::FETCH_ASSOC);
$is_player_online = (bool)$active_session;

// Si le joueur est en ligne ET que c'est l'heure de session, on calcule le temps en direct
if ($is_player_online && $is_session_time) {
    $session_start_time = new DateTime($active_session['session_start']);
    
    // On définit le début effectif de la comptabilisation pour aujourd'hui
    $effective_start_of_session = (clone $now)->setTime($heure_de_debut, 0);
    
    // Si le joueur s'est connecté avant 21h, on commence à compter à partir de 21h
    $start_to_count_from = ($session_start_time < $effective_start_of_session) ? $effective_start_of_session : $session_start_time;

    $current_session_duration = $now->getTimestamp() - $start_to_count_from->getTimestamp();
    
    if ($current_session_duration > 0) {
        $playtime_seconds += $current_session_duration;
        $session_start_timestamp = $start_to_count_from->getTimestamp();
    }
}

// Formatage du temps pour l'affichage
$hours = floor($playtime_seconds / 3600);
$minutes = floor(($playtime_seconds % 3600) / 60);
$formatted_playtime = sprintf('%dh %02dm', $hours, $minutes);

// On prépare le tableau de données à renvoyer
$data = [
    'is_player_online' => $is_player_online,
    'is_session_time' => $is_session_time,
    'total_playtime_seconds' => $playtime_seconds,
    'formatted_playtime' => $formatted_playtime,
    'playtime_goal_seconds' => 10 * 3600, // Objectif de 10h
];

// On envoie la réponse au format JSON
header('Content-Type: application/json');
echo json_encode($data);
?>