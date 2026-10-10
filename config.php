<?php
// --- Force la connexion sécurisée en HTTPS (sauf en local pour le développement) ---
$isHttps = false;
if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
    $isHttps = true;
} elseif (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https') {
    $isHttps = true;
}
if (!$isHttps && isset($_SERVER['HTTP_HOST']) && $_SERVER['HTTP_HOST'] !== 'localhost' && $_SERVER['HTTP_HOST'] !== '127.0.0.1' && !str_starts_with($_SERVER['HTTP_HOST'], '192.168.')) {
    $redirect = 'https://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
    header('HTTP/1.1 301 Moved Permanently');
    header('Location: ' . $redirect);
    exit();
}

// Configuration sécurisée des cookies de session
if (session_status() == PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'domain' => '',
        'secure' => $isHttps,
        'httponly' => true,
        'samesite' => 'Lax'
    ]);
    session_start();
}

$APP_BASE_URL = 'https://recrutement.sdcpj.fr';

// ----- Discord OAuth2 -----
$DISCORD_CLIENT_ID     = '1403504811596320878';
$DISCORD_CLIENT_SECRET = '4L2xJ0kB-cNXYu4veAg_bK_W_BLzbSqZ';
$DISCORD_REDIRECT_URI  = $APP_BASE_URL . '/connexion_discord';

// ----- Serveurs Discord -----
// Serveur Staff / Interne (où sont vérifiés les rôles de management)
$DISCORD_GUILD_ID          = '1403505054555832453'; 

// Serveur Public (OBLIGATOIRE pour se connecter)
$DISCORD_GUILD_PUBLIC_ID   = '885542517955780648';

// NOUVEAU : Rôles de Management (Sur le serveur Staff)
$DISCORD_BUREAU_ROLE_ID      = '1403860543445532775'; 
$DISCORD_SECRETARIAT_ROLE_ID = '1403860770881536182'; 
$DISCORD_CADRE_ROLE_ID       = '1549513494590656682'; 

// Rôles Joueurs
$DISCORD_RECRUITER_ROLE_ID = '1403507671314731049';
$DISCORD_PLAYER_ROLE_ID    = '1403507715229220954';
$DISCORD_INTEGRATION_ROLE_ID = '1403784104792690789'; 

// NOUVEAUX RôLES : Suivi et Adhésion
$DISCORD_TRIAL_ROLE_ID      = '1505199594039017482'; // Période d'essai
$DISCORD_NON_MEMBER_ROLE_ID = '1505199359287889930'; // Non adhérent
$DISCORD_MEMBER_ROLE_ID     = '1505199438103187528'; // Adhérent

// Rôles Opérateurs
$DISCORD_RECRUITER_OP_FDO_ROLE_ID = '1403586732254822420';
$DISCORD_RECRUITER_OP_SP_ROLE_ID  = '1403586777532334081';
$DISCORD_OPERATOR_FDO_ROLE_ID     = '1403586792514388078';
$DISCORD_OPERATOR_SP_ROLE_ID      = '1403586832955867187';

// Responsable Développement & Rôle notifié pour les candidatures Dev
$DISCORD_RESP_DEV_ROLE_ID  = '1505202049493504090';
$DISCORD_DEV_NOTIF_ROLE_ID = '1220533779031003197';

// ----- Bot -----
$DISCORD_BOT_TOKEN = 'MTQwMzUwNDgxMTU5NjMyMDg3OA.GB9_oP.WAetDpvEehnfX9I9K6TxKNKJxoHE1-cMuwKCuw';

// ----- Webhooks -----
$DISCORD_NEW_APPLICATION_WEBHOOK_URL = 'https://discord.com/api/webhooks/1403505095672467467/bwZhrcs27huq3PYiX1-4BYbF29kAbdThkKxbtOn6agUFRvZbvfrkdJa6znN7Iw36qdBQ';

// ----- Liens & Salons -----
$DISCORD_SERVER_INVITE_LINK = 'https://discord.gg/g8Td5auzev'; 
$DISCORD_WELCOME_CHANNEL_ID = '1403505055142903840'; 

// Salons pour les Candidatures
$DISCORD_APP_CHANNEL_PLAYER  = '1505198034609508393';
$DISCORD_APP_CHANNEL_OP_SP   = '1505197963646074991';
$DISCORD_APP_CHANNEL_OP_FDO  = '1505197994503569428';
$DISCORD_APP_CHANNEL_DEV     = '1505198034609508393'; // Salon candidatures développement

// Lien officiel d'adhésion associative HelloAsso
$HELLOASSO_MEMBERSHIP_URL = 'https://www.helloasso.com/associations/sdcpj/adhesions/adhesion-2026';

// Clé publique Discord
$DISCORD_PUBLIC_KEY = 'a00a06c3654e2d1d499f8c72fe5e283b5da929c3780b454d2a85100839608fb6';

// Image personnalisée pour les messages privés Discord (DMs)
$DISCORD_DM_IMAGE_URL = 'https://recrutement.sdcpj.fr/assets/banner_recrutement.png';


// ----- DÉLAIS & DURÉES -----
$APPLICATION_REJECTION_COOLDOWN = '1 month'; 
$TRIAL_MAX_DURATION = '1 month'; 

// ----- QUIZ D'INTÉGRATION -----
$QUIZ_QUESTIONS = [
    1 => [ 
        [
            'question' => "Quel est le rôle principal d'un civil sur les Studios DCPJ ?",
            'options' => [
                "Vivre sa vie et gagner de l'argent comme sur un serveur 'Life'.",
                "Changer de rôle à chaque scène pour créer des interventions variées.",
                "Devenir membre d'un gang et contrôler un territoire.",
                "Conduire des voitures de sport et faire des courses."
            ],
            'answer' => 1 
        ]
    ],
    2 => [ 
        [
            'question' => "Avant de lancer une intervention complexe, quelle est la première chose à faire ?",
            'options' => [
                "Contacter les secours directement en jeu avec /appel.",
                "Analyser les plannings sur Discord pour connaître les effectifs disponibles.",
                "Utiliser le menu F5 pour prendre le plus gros véhicule possible.",
                "Lancer un incendie pour être sûr d'attirer l'attention des secours."
            ],
            'answer' => 1 
        ]
    ],
    3 => [ 
        [
            'question' => "Quelle touche permet d'ouvrir le menu Plastron (véhicules, armes, tenues) pour les civils ?",
            'options' => [
                "Touche 1 (Menu Interactif)",
                "Touche 8 (Menu Plastron)",
                "Touche F1 (vMenu)",
                "Touche 2 (Menu Personnel)"
            ],
            'answer' => 1 
        ]
    ],
    4 => [ 
        [
            'question' => "Quelles sont les conditions minimales pour terminer sa période d'essai ?",
            'options' => [
                "Jouer pendant 10 heures, peu importe le nombre de jours.",
                "Être présent depuis une semaine, même sans avoir beaucoup joué.",
                "Attendre un mois complet après son arrivée sur le serveur.",
                "Avoir joué au minimum 10 heures ET être présent depuis au moins une semaine."
            ],
            'answer' => 3 
        ]
    ],
    5 => [ 
        [
            'question' => "Quels sont les trois pôles qui articulent la simulation sur le serveur ?",
            'options' => [
                "Police, Mafia et Civils",
                "Pôle Civil, Pôle Forces de l'Ordre et Pôle Secours",
                "Pompiers, GIGN et SAMU",
                "Pilotes, Mécaniciens et Civils"
            ],
            'answer' => 1 
        ]
    ],
];
?>