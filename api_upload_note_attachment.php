<?php
require_once __DIR__.'/config.php';
require_once __DIR__.'/bdd.php';

header('Content-Type: application/json; charset=utf-8');

// Sécurité : Réservé au staff
if (empty($_SESSION['user']) || (
    empty($_SESSION['user']['is_recruiter']) && 
    empty($_SESSION['user']['can_manage_players']) && 
    empty($_SESSION['user']['is_bureau']) && 
    empty($_SESSION['user']['is_secretariat'])
)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Accès refusé']);
    exit;
}

// Vérification CSRF
$headers = getallheaders();
$csrf_header = $headers['X-CSRF-Token'] ?? $_POST['csrf_token'] ?? '';
if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $csrf_header)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Jeton CSRF invalide']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_FILES['file'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Aucun fichier reçu']);
    exit;
}

$file = $_FILES['file'];

if ($file['error'] !== UPLOAD_ERR_OK) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Erreur lors du transfert du fichier (code ' . $file['error'] . ')']);
    exit;
}

// Limite de taille : 8 Mo max
if ($file['size'] > 8 * 1024 * 1024) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Le fichier dépasse la taille maximale autorisée (8 Mo)']);
    exit;
}

// Vérification MIME réelle
$finfo = new finfo(FILEINFO_MIME_TYPE);
$mime = $finfo->file($file['tmp_name']);

$allowed_mimes = [
    'image/png' => 'png',
    'image/jpeg' => 'jpg',
    'image/webp' => 'webp',
    'image/gif' => 'gif'
];

if (!isset($allowed_mimes[$mime])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Format de fichier non autorisé. Formats acceptés : PNG, JPG, WEBP, GIF']);
    exit;
}

// Générer un nom unique sécurisé
$ext = $allowed_mimes[$mime];
$filename = 'note_' . date('Ymd_His') . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
$target_dir = __DIR__ . '/uploads/notes/';

if (!is_dir($target_dir)) {
    mkdir($target_dir, 0755, true);
}

$target_path = $target_dir . $filename;

if (!move_uploaded_file($file['tmp_name'], $target_path)) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Impossible de déplacer le fichier uploadé']);
    exit;
}

$relative_url = 'uploads/notes/' . $filename;

echo json_encode([
    'success' => true,
    'url' => $relative_url,
    'filename' => $filename,
    'original_name' => htmlspecialchars($file['name'])
]);
