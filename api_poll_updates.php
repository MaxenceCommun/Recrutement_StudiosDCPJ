<?php
// api_poll_updates.php : Logique de Tchat et de Présence
require_once __DIR__.'/config.php';
require_once __DIR__.'/bdd.php';

if (session_status() == PHP_SESSION_NONE) session_start();
if (empty($_SESSION['user'])) { http_response_code(403); exit; }

$pdo = db();

$application_id = isset($_GET['app_id']) ? (int)$_GET['app_id'] : 0;
$last_message_id = isset($_GET['last_msg_id']) ? (int)$_GET['last_msg_id'] : 0;
$mode = $_GET['mode'] ?? 'candidate';

$response = [
    'new_messages' => [],
    'app_status' => null,
    'candidate_presence' => 'offline',
    'last_message_id' => $last_message_id
];

// 1. État et Présence
$stmt = $pdo->prepare("SELECT status, user_id FROM applications WHERE id = ?");
$stmt->execute([$application_id]);
$app = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$app) {
    http_response_code(404);
    echo json_encode(['error' => 'Candidature non trouvée']);
    exit;
}

// SÉCURITÉ : Vérifier que l'utilisateur est soit le propriétaire de la candidature, soit un recruteur/staff
$is_staff_user = !empty($_SESSION['user']['is_recruiter']) || !empty($_SESSION['user']['is_bureau']) || !empty($_SESSION['user']['is_secretariat']);
if (!$is_staff_user && (int)$app['user_id'] !== (int)$_SESSION['user']['id']) {
    http_response_code(403);
    echo json_encode(['error' => 'Accès refusé']);
    exit;
}

$response['app_status'] = $app['status'];

// Vérification présence (Uniquement pour le recruteur qui regarde le candidat)
if ($mode === 'recruiter') {
    $stmtUser = $pdo->prepare("SELECT last_activity, window_state FROM users WHERE id = ?");
    $stmtUser->execute([$app['user_id']]);
    $candidate = $stmtUser->fetch(PDO::FETCH_ASSOC);
    if ($candidate && $candidate['last_activity']) {
        $diff = time() - strtotime($candidate['last_activity']);
        // Si onglet masqué, on tolère 3 min, sinon 20 sec
        $timeout = ($candidate['window_state'] === 'hidden') ? 180 : 20;
        
        if ($diff > $timeout) $response['candidate_presence'] = 'red';
        elseif ($candidate['window_state'] === 'hidden') $response['candidate_presence'] = 'orange';
        else $response['candidate_presence'] = 'green';
    } else { $response['candidate_presence'] = 'red'; }
}

// 2. Récupération des messages (LEFT JOIN pour inclure les messages système automatiques)
$stmtMsg = $pdo->prepare("SELECT m.*, u.display_name, u.username FROM application_messages m LEFT JOIN users u ON m.user_id = u.id WHERE m.application_id = ? AND m.id > ? ORDER BY m.created_at ASC");
$stmtMsg->execute([$application_id, $last_message_id]);
$messages = $stmtMsg->fetchAll(PDO::FETCH_ASSOC);

foreach ($messages as $msg) {
    $is_me = ($msg['user_id'] == $_SESSION['user']['id']);
    $is_staff_message = $msg['is_staff'];
    $time = date('H:i', strtotime($msg['created_at']));
    
    // --- LOGIQUE D'AFFICHAGE CSS ---
    $raw_name = $msg['display_name'] ?? ($is_staff_message ? 'Système' : 'Candidat');
    $display_name = htmlspecialchars($raw_name);
    $role_badge = '';
    
    if ($mode === 'candidate') {
        // --- VUE CANDIDAT ---
        if ($is_staff_message) {
            // Message du Recruteur (L'autre) -> GAUCHE / GRIS
            $wrapper_cls = 'msg-left';
            $msg_cls = 'msg-bubble-other'; // Classe clé pour le son
            $display_name = "Recruteur";   // Anonyme
            $role_badge = ''; 
        } else {
            // Message du Candidat (Moi) -> DROITE / BLEU
            $wrapper_cls = 'msg-right';
            $msg_cls = 'msg-bubble-me';
            $display_name = ""; // Pas de nom pour moi
        }
    } else {
        // --- VUE RECRUTEUR ---
        if ($is_staff_message) {
            // Message du Staff (Moi ou Collègue) -> DROITE
            $wrapper_cls = 'msg-right';
            if ($is_me) {
                $msg_cls = 'msg-bubble-me'; // Bleu (Moi)
            } else {
                $msg_cls = 'msg-bubble-staff-other'; // Violet (Collègue)
            }
            $role_badge = '<span class="badge-staff">Staff</span>';
        } else {
            // Message du Candidat (L'autre) -> GAUCHE / GRIS
            $wrapper_cls = 'msg-left';
            $msg_cls = 'msg-bubble-other'; // Classe clé pour le son
        }
    }

    $html = '<div class="message-wrapper '.$wrapper_cls.'">';
    $html .= '<div class="message '.$msg_cls.'">';
    $html .= '<div class="msg-header">';
    $html .= '<strong>'.$display_name.'</strong> ' . $role_badge;
    $html .= ' <small>'.$time.'</small>';
    $html .= '</div>';
    $html .= '<div class="msg-content">'.nl2br(htmlspecialchars($msg['message'])).'</div>';
    $html .= '</div></div>';

    $response['new_messages'][] = $html;
    $response['last_message_id'] = $msg['id'];
}

header('Content-Type: application/json');
echo json_encode($response);