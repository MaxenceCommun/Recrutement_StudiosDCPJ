<?php
// /recrutement/cron_check_trials.php

// Précaution pour s'assurer que le script n'est pas exécuté via un navigateur
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    die('Accès interdit. Ce script doit être exécuté via la ligne de commande (CLI).');
}

require_once __DIR__.'/config.php';
require_once __DIR__.'/bdd.php';
require_once __DIR__.'/api_discord.php';

echo "--- Début de la vérification globale (".date('Y-m-d H:i:s').") ---\n";

$pdo = db();

// --- 1. SYNCHRONISATION DES RÔLES (DISCORD -> SITE) ---
echo "Synchronisation des rôles Discord...\n";
try {
    $discord_members = discord_get_all_guild_members();
    if (!empty($discord_members)) {
        foreach ($discord_members as $member) {
            $discord_id = $member['user']['id'];
            $roles = json_encode($member['roles']);
            
            // On met à jour les rôles connus pour chaque utilisateur présent en BDD
            $stmt = $pdo->prepare("UPDATE users SET last_synced_roles = ? WHERE discord_id = ?");
            $stmt->execute([$roles, $discord_id]);
            
            // Optionnel : On pourrait ici déclencher des changements de statut auto 
            // si un rôle spécifique est détecté (ex: rôle Membre -> statut 'joueur')
        }
        echo " -> " . count($discord_members) . " membres synchronisés.\n";
    }
} catch (Exception $e) {
    echo " -> ERREUR synchro rôles : " . $e->getMessage() . "\n";
}

// --- 2. VÉRIFICATION DES ADHÉSIONS EXPIRÉES ---
echo "Vérification des adhésions...\n";
$now_str = date('Y-m-d H:i:s');
$stmt = $pdo->prepare("SELECT id, discord_id, username FROM users WHERE membership_expires_at < ?");
$stmt->execute([$now_str]);
$expired_members = $stmt->fetchAll();

foreach ($expired_members as $m) {
    echo " -> Adhésion expirée pour " . $m['username'] . "\n";
    
    // On retire le rôle Discord "Membre" (à définir dans config.php)
    if (!empty($GLOBALS['DISCORD_PLAYER_ROLE_ID'])) {
        try {
            discord_remove_role($m['discord_id'], $GLOBALS['DISCORD_PLAYER_ROLE_ID']);
            echo "    -> Rôle Discord retiré.\n";
        } catch (Exception $e) {
            echo "    -> ERREUR retrait rôle : " . $e->getMessage() . "\n";
        }
    }
    
    // On réinitialise la date pour ne pas traiter en boucle et on change le statut
    $pdo->prepare("UPDATE users SET membership_expires_at = NULL, status = 'archive' WHERE id = ?")->execute([$m['id']]);
    log_user_action($m['id'], 'membership', "Adhésion expirée automatiquement au 31/12.");
    
    // Notification DM
    $msg = "Bonjour {$m['username']},\n\nVotre adhésion aux Studios DCPJ est arrivée à son terme (expiration annuelle). Vos accès ont été suspendus.\nN'hésitez pas à renouveler votre adhésion via le site pour retrouver vos droits.\n\nL'équipe des Studios DCPJ.";
    try { discord_send_dm($m['discord_id'], $msg); } catch (Exception $e) {}
}

// --- 3. VÉRIFICATION DES PÉRIODES D'ESSAI ---
echo "Vérification des périodes d'essai...\n";
$stmt_trials = $pdo->prepare("SELECT id, discord_id, username, trial_start_date FROM users WHERE status IN ('essai', 'essai_adhesion') AND trial_start_date IS NOT NULL");
$stmt_trials->execute();
$trial_users = $stmt_trials->fetchAll(PDO::FETCH_ASSOC);

$trial_max_duration = $GLOBALS['TRIAL_MAX_DURATION'] ?? '1 month';

if (empty($trial_users)) {
    echo "Aucun joueur en période d'essai à vérifier.\n";
} else {
    echo count($trial_users) . " joueur(s) en période d'essai trouvé(s).\n";

    $STAFF_ALERT_WEBHOOK_URL = $GLOBALS['DISCORD_NEW_APPLICATION_WEBHOOK_URL'] ?? '';

    foreach ($trial_users as $user) {
        $trial_start = new DateTime($user['trial_start_date']);
        $trial_deadline = (clone $trial_start)->modify('+' . $trial_max_duration);
        $now = new DateTime();

        // Vérifier si la date limite est dépassée
        if ($now > $trial_deadline) {
            echo "Le joueur " . $user['username'] . " (ID: " . $user['id'] . ") a dépassé sa période d'essai.\n";

            // Envoyer un MP de rappel au joueur sur Discord
            $helloasso_url = $GLOBALS['HELLOASSO_MEMBERSHIP_URL'] ?? 'https://www.helloasso.com';
            $dm_message = "Bonjour " . $user['username'] . ",\n\n";
            $dm_message .= "Votre période d'essai est arrivée à son terme. Il est maintenant temps de finaliser votre parcours associative.\n";
            $dm_message .= "Si vous avez complété vos objectifs, vous pouvez dès à présent finaliser votre adhésion sur notre page officielle HelloAsso :\n";
            $dm_message .= "👉 <" . $helloasso_url . ">\n\n";
            $dm_message .= "Si vous rencontrez des difficultés, n'hésitez pas à contacter un membre du staff.\n\n";
            $dm_message .= "L'équipe des Studios DCPJ.";

            try {
                discord_send_dm($user['discord_id'], $dm_message);
                echo " -> MP de rappel envoyé au joueur avec lien HelloAsso.\n";
            } catch (Exception $e) {
                echo " -> ERREUR lors de l'envoi du MP : " . $e->getMessage() . "\n";
            }

            // Créer une alerte sur le tableau de bord du staff via Webhook
            if (!empty($STAFF_ALERT_WEBHOOK_URL)) {
                $embed = [
                    'title' => "🔔 Fin de Période d'Essai",
                    'description' => "Le joueur **" . htmlspecialchars($user['username']) . "** a dépassé la durée maximale de sa période d'essai.",
                    'color' => hexdec('f39c12'), // Orange
                    'fields' => [
                        [
                            'name' => 'Début de la période',
                            'value' => date('d/m/Y', strtotime($user['trial_start_date'])),
                            'inline' => true
                        ],
                        [
                            'name' => 'Action requise',
                            'value' => 'Contacter le joueur pour faire le point sur sa situation.',
                            'inline' => false
                        ]
                    ],
                    'footer' => [
                        'text' => 'Alerte automatique du système de recrutement'
                    ],
                    'timestamp' => date('c')
                ];

                try {
                    discord_send_webhook_message($STAFF_ALERT_WEBHOOK_URL, '', [$embed]);
                    echo " -> Alerte envoyée sur le salon du staff.\n";
                } catch (Exception $e) {
                    echo " -> ERREUR lors de l'envoi du webhook : " . $e->getMessage() . "\n";
                }
            }
        }
    }
}

echo "--- Vérification des Inactivités Candidatures ---\n";
try {
    $st_apps = $pdo->query("
        SELECT a.id, a.user_id, u.discord_id, u.username,
            (SELECT created_at FROM application_messages WHERE application_id = a.id ORDER BY created_at DESC LIMIT 1) as last_msg_date,
            (SELECT is_staff FROM application_messages WHERE application_id = a.id ORDER BY created_at DESC LIMIT 1) as last_msg_is_staff,
            (SELECT message FROM application_messages WHERE application_id = a.id ORDER BY created_at DESC LIMIT 1) as last_msg_text
        FROM applications a
        JOIN users u ON a.user_id = u.id
        WHERE a.status = 'attente'
    ");
    $pending_apps = $st_apps->fetchAll(PDO::FETCH_ASSOC);

    foreach ($pending_apps as $app) {
        if (empty($app['last_msg_date']) || empty($app['last_msg_is_staff'])) continue;
        
        $last_msg_date = new DateTime($app['last_msg_date']);
        $now = new DateTime();
        $diff_days = $last_msg_date->diff($now)->days;

        // Si le dernier message vient du staff et date de + de 3 jours
        if ($app['last_msg_is_staff'] == 1 && $diff_days >= 3) {
            $is_system_msg = (strpos($app['last_msg_text'], '[SYSTÈME]') === 0);

            if (!$is_system_msg) {
                // ÉTAPE 1: Envoi de la relance
                echo "Relance envoyée pour la candidature #{$app['id']} ({$app['username']})\n";
                $candidature_url = ($GLOBALS['APP_BASE_URL'] ?? '') . '/ma_candidature';
                $dm = "Bonjour {$app['username']},\n\nVotre candidature sur les Studios DCPJ est en attente d'une réponse de votre part depuis plus de 3 jours.\nMerci de consulter votre dossier et de répondre au recruteur, sans quoi votre candidature sera automatiquement archivée d'ici 3 jours.\n➡️ $candidature_url";
                
                try { discord_send_dm($app['discord_id'], $dm); } catch(Exception $e) {}
                
                $sys_msg = "[SYSTÈME] Relance automatique envoyée par MP au candidat. Sans réponse d'ici 3 jours, la candidature sera archivée.";
                $pdo->prepare("INSERT INTO application_messages (application_id, user_id, message, is_staff) VALUES (?, NULL, ?, 1)")->execute([$app['id'], $sys_msg]);
            } 
            elseif ($is_system_msg && $diff_days >= 3) {
                // ÉTAPE 2: Archivage après 3 jours supplémentaires (donc 6 jours au total)
                echo "Archivage automatique de la candidature #{$app['id']} ({$app['username']})\n";
                $pdo->prepare("UPDATE applications SET status = 'archive', updated_at = NOW() WHERE id = ?")->execute([$app['id']]);
                
                $dm = "Bonjour {$app['username']},\n\nSuite à une inactivité prolongée, votre candidature a été automatiquement archivée. Vous pourrez repostuler ultérieurement.\nL'équipe des Studios DCPJ.";
                try { discord_send_dm($app['discord_id'], $dm); } catch(Exception $e) {}
                
                $sys_msg = "[SYSTÈME] Candidature archivée automatiquement pour cause d'inactivité.";
                $pdo->prepare("INSERT INTO application_messages (application_id, user_id, message, is_staff) VALUES (?, NULL, ?, 1)")->execute([$app['id'], $sys_msg]);
            }
        }
    }
} catch (Exception $e) {
    echo "Erreur lors de la vérification des inactivité candidatures: " . $e->getMessage() . "\n";
}

echo "--- Vérification des départs Discord ---\n";
try {
    // Vérifier les départs sur le serveur Discord Public
    $all_users = $pdo->query("SELECT id, discord_id, username FROM users WHERE status IN ('essai', 'essai_adhesion', 'active', 'joueur', 'accepte_integration')")->fetchAll(PDO::FETCH_ASSOC);
    $target_guild_for_departures = !empty($GLOBALS['DISCORD_GUILD_PUBLIC_ID']) ? $GLOBALS['DISCORD_GUILD_PUBLIC_ID'] : $GLOBALS['DISCORD_GUILD_ID'];
    $discord_members = discord_get_all_guild_members($target_guild_for_departures);

    if (empty($discord_members)) {
        echo "Attention : Aucun membre récupéré depuis Discord (possible erreur d'API/Intents). Vérification des départs ignorée pour éviter les faux départs.\n";
    } else {
        $discord_member_ids = array_column(array_column($discord_members, 'user'), 'id');

        foreach ($all_users as $u) {
            if (!in_array($u['discord_id'], $discord_member_ids)) {
                echo "Joueur " . $u['username'] . " a quitté le Discord.\n";
            
            $token = bin2hex(random_bytes(16));
            $pdo->prepare("UPDATE users SET status = 'left', departure_date = NOW(), departure_token = ? WHERE id = ?")->execute([$token, $u['id']]);
            
            $reason_link = ($GLOBALS['APP_BASE_URL'] ?? '') . "/indiquer_depart?token=" . $token;
            $rejoin_link = $GLOBALS['DISCORD_SERVER_INVITE_LINK'] ?? '';
            
            $dm = "Bonjour {$u['username']},\n\nNous avons remarqué que vous avez quitté notre serveur Discord.\n";
            $dm .= "Pourriez-vous nous indiquer la raison de votre départ via ce lien ? Cela nous aide à nous améliorer :\n$reason_link\n\n";
            $dm .= "Si c'était une erreur, vous pouvez revenir via ce lien : $rejoin_link\n\nL'équipe des Studios DCPJ.";
            
            try { discord_send_dm($u['discord_id'], $dm); } catch(Exception $e) { echo "Impossible d'envoyer MP à {$u['username']}\n"; }
            
            // Alerte Staff
            $embed = [
                'title' => "🚪 Départ Discord",
                'description' => "Le joueur **" . htmlspecialchars($u['username']) . "** a quitté le serveur Discord.",
                'color' => hexdec('e74c3c'),
                'fields' => [['name' => 'Statut', 'value' => 'Marqué comme "Quitté". MP envoyé.', 'inline' => false]]
            ];
            try { discord_send_webhook_message($STAFF_ALERT_WEBHOOK_URL, '', [$embed]); } catch(Exception $e) {}
        }
    }
}
} catch (Exception $e) {
    echo "Erreur lors de la vérification des départs: " . $e->getMessage() . "\n";
}

echo "--- Fin du script de vérification ---\n";