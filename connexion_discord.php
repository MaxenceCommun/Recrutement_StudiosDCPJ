<?php
// On s'assure que la session est démarrée
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__.'/config.php';
require_once __DIR__.'/bdd.php';
require_once __DIR__.'/api_discord.php';

function safe_redirect_path($path) {
    if (empty($path) || !is_string($path)) return '';
    $path = ltrim($path, "/\\");
    if (preg_match('#^[a-z]+://#i', $path) || str_starts_with($path, '//') || str_contains($path, '\\')) {
        return '';
    }
    if (!preg_match('#^[a-zA-Z0-9_\-/?=&#.]+$#', $path)) {
        return '';
    }
    return '/' . $path;
}

if (isset($_GET['return_to'])) {
    $clean_return = safe_redirect_path($_GET['return_to']);
    if (!empty($clean_return)) {
        $_SESSION['return_to'] = $clean_return;
    }
}

// --- FONCTIONS UTILITAIRES POUR CETTE PAGE ---
function check_guild_presence($guild_id, $user_id, $bot_token) {
    // Vérifie simplement si un membre est dans le serveur (GET)
    $url = "https://discord.com/api/guilds/$guild_id/members/$user_id";
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ["Authorization: Bot $bot_token"]);
    $result = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    // 200 = Membre présent. 404 = Non membre.
    return ($http_code === 200);
}

// --- SÉCURITÉ ANTI-BOUCLE ET REDIRECTION LOGIQUE ---
if (!empty($_SESSION['user']) && !isset($_GET['code'])) {
    // Si un return_to est présent, on l'utilise en priorité (très utile pour l'intégration)
    if (!empty($_GET['return_to'])) {
        $safe_target = safe_redirect_path($_GET['return_to']);
        if (!empty($safe_target)) {
            header('Location: ' . $safe_target);
            exit;
        }
    }

    $pdo = db();
    $user_id_from_session = $_SESSION['user']['id'];
    $user_status_from_session = $_SESSION['user']['status'];
    
    // Redirection Staff
    if (!empty($_SESSION['user']['is_recruiter']) || !empty($_SESSION['user']['is_bureau']) || !empty($_SESSION['user']['is_secretariat'])) {
        header('Location: /espace_recruteur'); exit;
    }
    
    // Redirection Période d'essai
    $trial_statuses = ['essai', 'essai_adhesion', 'essai_termine'];
    if (in_array($user_status_from_session, $trial_statuses)) {
        header('Location: /mon_suivi'); exit;
    }

    // Redirection Joueur officiel (Adhésion validée)
    if (in_array($user_status_from_session, ['joueur', 'active'])) {
        header('Location: /espace_joueur'); exit;
    }
    
    // Vérification candidature
    $st_app = $pdo->prepare("SELECT id, status, integration_token FROM applications WHERE user_id=? AND status != 'archive' ORDER BY created_at DESC LIMIT 1");
    $st_app->execute([$user_id_from_session]);
    $app = $st_app->fetch(PDO::FETCH_ASSOC);

    if ($app) {
      if ($app['status'] === 'accepte_integration' && !empty($app['integration_token'])) {
          header('Location: /integration?token=' . $app['integration_token']); exit;
      }
      header('Location: /ma_candidature'); exit;
    } else {
      header('Location: /choisir_candidature'); exit;
    }
}

// --- PROCESSUS DE CONNEXION VIA DISCORD ---

if (isset($_GET['error'])) {
    // Gestion propre de l'annulation par l'utilisateur
    echo "<h3>Connexion annulée ou échouée</h3><p>Discord a renvoyé une erreur : " . htmlspecialchars($_GET['error_description'] ?? 'Erreur inconnue') . "</p><p><a href='/connexion_discord'>Réessayer</a></p>";
    exit;
}

if (isset($_GET['code'])) {
  try {
    // Vérification anti-CSRF du jeton d'état (state)
    if (empty($_GET['state']) || empty($_SESSION['oauth_state']) || !hash_equals($_SESSION['oauth_state'], $_GET['state'])) {
        session_destroy();
        http_response_code(403);
        die("<div style='font-family:sans-serif; text-align:center; margin-top:50px;'>
                <h3>Échec de validation de sécurité</h3>
                <p>Le jeton de sécurité (state) Discord est invalide ou a expiré.</p>
                <p><a href='/connexion_discord'>Réessayer la connexion</a></p>
             </div>");
    }
    unset($_SESSION['oauth_state']);

    // 1. Échange du code contre un token
    $token = discord_exchange_code($_GET['code']);
    
    // 2. Infos utilisateur
    $me = discord_me($token['access_token']);
    $display_name = $me['global_name'] ?? $me['username'];

    // --- VÉRIFICATION PRESENCE SERVEUR PUBLIC ---
    if (!empty($DISCORD_BOT_TOKEN) && !empty($DISCORD_GUILD_PUBLIC_ID)) {
        
        // Tentative 1 : AJOUTER le membre (PUT) - Idéal pour les nouveaux
        $join_url = "https://discord.com/api/guilds/$DISCORD_GUILD_PUBLIC_ID/members/" . $me['id'];
        $join_data = json_encode(['access_token' => $token['access_token']]);
        
        $ch = curl_init($join_url);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "PUT");
        curl_setopt($ch, CURLOPT_POSTFIELDS, $join_data);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "Authorization: Bot $DISCORD_BOT_TOKEN",
            "Content-Type: application/json"
        ]);
        $result = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        // Analyse du résultat
        $is_in_server = false;
        
        if ($http_code == 201 || $http_code == 204) {
            // 201 = Rejoint, 204 = Déjà là (Succès)
            $is_in_server = true;
        } elseif ($http_code >= 400) {
            // Echec du PUT (Ex: 403 Forbidden si le bot ne peut pas modifier le propriétaire).
            // Tentative 2 : VÉRIFIER simplement la présence (GET)
            $is_in_server = check_guild_presence($DISCORD_GUILD_PUBLIC_ID, $me['id'], $DISCORD_BOT_TOKEN);
        }

        // Si après les deux tentatives, l'utilisateur n'est toujours pas détecté
        if (!$is_in_server) {
            session_destroy();
            die("<div style='font-family:sans-serif; text-align:center; margin-top:50px; max-width:600px; margin-left:auto; margin-right:auto;'>
                    <h1>Accès Refusé</h1>
                    <p>Vous devez obligatoirement être membre du serveur Discord Public pour vous connecter.</p>
                    <p>Nous n'avons pas réussi à vous ajouter automatiquement.</p>
                    <hr>
                    <p><strong>Action requise :</strong></p>
                    <p><a href='https://discord.gg/g8Td5auzev' target='_blank' style='background:#5865F2; color:white; padding:10px 20px; text-decoration:none; border-radius:5px;'>Rejoindre le Discord Public Manuellement</a></p>
                    <br>
                    <p>Une fois sur le serveur, <a href='/connexion_discord'>cliquez ici pour réessayer</a>.</p>
                 </div>");
        }
    }
    // -----------------------------------------------------------
    
    $pdo = db();
    
    // Enregistrement utilisateur
    $stmt = $pdo->prepare(
      "INSERT INTO users (discord_id, username, display_name, avatar) VALUES (?, ?, ?, ?)
       ON DUPLICATE KEY UPDATE username=VALUES(username), display_name=VALUES(display_name), avatar=VALUES(avatar)"
    );
    $stmt->execute([$me['id'], $me['username'], $display_name, $me['avatar'] ?? '']);
    
    $st_user = $pdo->prepare('SELECT * FROM users WHERE discord_id=?');
    $st_user->execute([$me['id']]);
    $user = $st_user->fetch(PDO::FETCH_ASSOC);

    // Récupération rôles (Serveurs Staff et Public)
    $isRecruiter = false; $recruiterType = null; $isBureau = false; $isSecretariat = false;
    $user_roles_on_discord = [];

    if (!empty($DISCORD_BOT_TOKEN)) {
      // 1. Récupération depuis le serveur Staff
      if (!empty($DISCORD_GUILD_ID)) {
        try {
          $member = discord_get_member($me['id'], $DISCORD_GUILD_ID);
          if ($member && !empty($member['roles'])) {
            $user_roles_on_discord = array_merge($user_roles_on_discord, $member['roles']);
          }
        } catch (Exception $e) { /* Ignore */ }
      }
      // 2. Récupération depuis le serveur Public
      if (!empty($DISCORD_GUILD_PUBLIC_ID)) {
        try {
          $member_pub = discord_get_member($me['id'], $DISCORD_GUILD_PUBLIC_ID);
          if ($member_pub && !empty($member_pub['roles'])) {
            $user_roles_on_discord = array_merge($user_roles_on_discord, $member_pub['roles']);
          }
        } catch (Exception $e) { /* Ignore */ }
      }
      
      $user_roles_on_discord = array_unique($user_roles_on_discord);
    }

    if (!empty($user_roles_on_discord)) {
      if (in_array($DISCORD_BUREAU_ROLE_ID, $user_roles_on_discord)) $isBureau = true;
      if (in_array($DISCORD_SECRETARIAT_ROLE_ID, $user_roles_on_discord)) $isSecretariat = true;
      if (in_array($DISCORD_RECRUITER_ROLE_ID, $user_roles_on_discord)) { $isRecruiter = true; $recruiterType = 'player'; }
      elseif (in_array($DISCORD_RECRUITER_OP_FDO_ROLE_ID, $user_roles_on_discord)) { $isRecruiter = true; $recruiterType = 'operator_fdo'; }
      elseif (in_array($DISCORD_RECRUITER_OP_SP_ROLE_ID, $user_roles_on_discord)) { $isRecruiter = true; $recruiterType = 'operator_sp'; }
      elseif (!empty($DISCORD_RESP_DEV_ROLE_ID) && in_array($DISCORD_RESP_DEV_ROLE_ID, $user_roles_on_discord)) { $isRecruiter = true; $recruiterType = 'developer'; }

      if ($isBureau || $isSecretariat) {
          $isRecruiter = true;
      }
    }

    // NOUVEAU : Synchronisation automatique du statut de Période d'Essai (PE)
    $trial_statuses = ['essai', 'essai_adhesion', 'essai_termine'];
    if (!empty($DISCORD_TRIAL_ROLE_ID) && in_array($DISCORD_TRIAL_ROLE_ID, $user_roles_on_discord)) {
        if (!in_array($user['status'], $trial_statuses)) {
            $user['status'] = 'essai';
            $now_str = date('Y-m-d H:i:s');
            $pdo->prepare("UPDATE users SET status = 'essai', trial_start_date = IF(trial_start_date IS NULL, ?, trial_start_date) WHERE id = ?")->execute([$now_str, $user['id']]);
            if (empty($user['trial_start_date'])) {
                $user['trial_start_date'] = $now_str;
            }
        }
    }

    $pdo->prepare('UPDATE users SET is_recruiter=?, recruiter_type=?, is_bureau=?, is_secretariat=?, last_synced_roles=? WHERE id=?')->execute([
        $isRecruiter ? 1 : 0, $recruiterType, $isBureau ? 1 : 0, $isSecretariat ? 1 : 0, json_encode($user_roles_on_discord), $user['id']
    ]);

    // Détection de changement de rôles pour l'historique
    $old_roles = json_decode($user['last_synced_roles'] ?: '[]', true);
    if (serialize($old_roles) !== serialize($user_roles_on_discord)) {
        log_user_action($user['id'], 'role_change', "Rôles Discord mis à jour lors de la connexion.");
    }

    // Permissions
    $stmt_roles = $pdo->query("SELECT role_id, permissions_json FROM role_permissions");
    $role_perms_from_db = $stmt_roles->fetchAll(PDO::FETCH_KEY_PAIR);
    $inherited_perms = [];
    foreach($user_roles_on_discord as $user_role_id) {
        if (isset($role_perms_from_db[$user_role_id])) {
            $perms_for_role = json_decode($role_perms_from_db[$user_role_id], true);
            foreach($perms_for_role as $perm_key => $perm_value) {
                if ($perm_value) $inherited_perms[$perm_key] = true;
            }
        }
    }

    $specific_permission_keys = [
        'can_manage_access', 'can_view_fta', 'can_add_fta', 'can_edit_fta', 
        'can_delete_application', 'can_override_decision', 'can_manage_players',
        'can_validate_membership', 'can_extend_trial', 'can_change_player_status', 
        'can_set_reapply_delay', 'can_manage_quiz', 'can_manage_reasons', 'can_view_stats'
    ];
    $effective_permissions = [];
    foreach ($specific_permission_keys as $perm_key) {
        $user_override = $user[$perm_key] ?? null;
        if ($user_override !== null) {
            $effective_permissions[$perm_key] = (bool)$user_override;
        } else {
            $effective_permissions[$perm_key] = !empty($inherited_perms[$perm_key]);
        }
    }

    // Régénération de l'ID de session pour prévenir la fixation de session
    session_regenerate_id(true);

    // Session
    $_SESSION['user'] = [
      'id' => $user['id'], 'discord_id' => $me['id'], 'username' => $me['username'],
      'display_name' => $display_name, 'avatar' => $me['avatar'] ?? '',
      'is_recruiter' => $isRecruiter, 'recruiter_type' => $recruiterType,
      'is_bureau' => $isBureau, 'is_secretariat' => $isSecretariat,
      'status' => $user['status']
    ];
    $_SESSION['user'] = array_merge($_SESSION['user'], $effective_permissions);

    $return_to = $_SESSION['return_to'] ?? '';
    unset($_SESSION['return_to']);

    session_write_close();

    $safe_target = safe_redirect_path($return_to);
    if (!empty($safe_target)) {
        header('Location: ' . $safe_target);
    } else {
        header('Location: /connexion_discord');
    }
    exit;

  } catch (Exception $e) {
    http_response_code(500);
    session_destroy();
    echo "Une erreur critique est survenue. <a href='/connexion_discord'>Réessayer</a>";
    error_log("ERREUR OAUTH : " . $e->getMessage());
    exit;
  }
}

// Génération du jeton anti-CSRF (state) pour OAuth2
$oauth_state = bin2hex(random_bytes(16));
$_SESSION['oauth_state'] = $oauth_state;

// Redirection vers Discord
header('Location: https://discord.com/oauth2/authorize?client_id='.$DISCORD_CLIENT_ID.'&response_type=code&redirect_uri='.urlencode($DISCORD_REDIRECT_URI).'&scope=identify+guilds+guilds.join&state='.$oauth_state);
exit;