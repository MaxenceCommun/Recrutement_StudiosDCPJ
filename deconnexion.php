<?php
// On démarre la session si ce n'est pas déjà fait pour pouvoir accéder aux données et les détruire
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__.'/config.php';

// 1. On vide toutes les variables de session
$_SESSION = array();

// 2. Si on utilise des cookies de session, on demande au navigateur de supprimer le cookie
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}

// 3. On détruit la session côté serveur
session_destroy();

// 4. On redirige vers l'accueil (racine du sous-domaine)
header('Location: /accueil');
exit;
?>