<?php
// api_heartbeat.php : Met à jour le statut et la page actuelle
require_once __DIR__.'/config.php';
require_once __DIR__.'/bdd.php';

if (session_status() == PHP_SESSION_NONE) session_start();
if (empty($_SESSION['user'])) exit;

$pdo = db();
$json = file_get_contents('php://input');
$data = json_decode($json, true);

$state = $data['state'] ?? 'closed';
$page = $data['page'] ?? '';
$now = date('Y-m-d H:i:s');

// Vérifier si c'est une connexion en session (entre 21h et 23h59)
$current_hour = (int)date('H');
$is_session = ($current_hour >= 21 && $current_hour <= 23);

if ($is_session) {
    // Si la dernière connexion de session n'était pas aujourd'hui, on incrémente le compteur
    $stmt = $pdo->prepare("UPDATE users SET 
        last_activity = ?, 
        window_state = ?, 
        current_page = ?, 
        last_connection = ?, 
        session_presence_count = IF(DATE(last_session_connection) != DATE(?) OR last_session_connection IS NULL, session_presence_count + 1, session_presence_count),
        last_session_connection = ? 
        WHERE id = ?");
    $stmt->execute([$now, $state, $page, $now, $now, $now, $_SESSION['user']['id']]);
} else {
    $stmt = $pdo->prepare("UPDATE users SET last_activity = ?, window_state = ?, current_page = ?, last_connection = ? WHERE id = ?");
    $stmt->execute([$now, $state, $page, $now, $_SESSION['user']['id']]);
}