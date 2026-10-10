<?php
require_once __DIR__.'/config.php';
require_once __DIR__.'/bdd.php';
require_once __DIR__.'/api_discord.php';

if (empty($_SESSION['user']['is_recruiter']) && empty($_SESSION['user']['can_manage_players']) && empty($_SESSION['user']['is_bureau']) && empty($_SESSION['user']['is_secretariat'])) {
    http_response_code(403); exit('Accès refusé');
}

$pdo = db();
$user_id = (int)($_GET['id'] ?? 0);

// Fetch user data
$st = $pdo->prepare('SELECT u.*, a.id as application_id, a.answers_json, a.application_type FROM users u LEFT JOIN applications a ON u.id = a.user_id AND a.status != "archive" WHERE u.id = ?');
$st->execute([$user_id]);
$u = $st->fetch(PDO::FETCH_ASSOC);

if (!$u) { http_response_code(404); exit('Not found'); }

$csrf_token = $_SESSION['csrf_token'] ?? bin2hex(random_bytes(32));
$_SESSION['csrf_token'] = $csrf_token;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) { http_response_code(403); exit; }
    
    if (isset($_POST['validate_membership'])) {
        if (empty($_SESSION['user']['is_bureau']) && empty($_SESSION['user']['can_validate_membership'])) { 
            http_response_code(403); exit('Permission refusée pour valider une adhésion.'); 
        }
        $expiry = date('Y-12-31 23:59:59');
        $pdo->prepare("UPDATE users SET status = 'joueur', membership_expires_at = ? WHERE id = ?")->execute([$expiry, $user_id]);
        if (!empty($GLOBALS['DISCORD_PLAYER_ROLE_ID'])) {
            try { discord_add_role($u['discord_id'], $GLOBALS['DISCORD_PLAYER_ROLE_ID']); } catch (Exception $e) {}
        }
        if (!empty($GLOBALS['DISCORD_MEMBER_ROLE_ID'])) {
            try { discord_add_role($u['discord_id'], $GLOBALS['DISCORD_MEMBER_ROLE_ID']); } catch (Exception $e) {}
        }
        if (!empty($GLOBALS['DISCORD_TRIAL_ROLE_ID'])) {
            try { discord_remove_role($u['discord_id'], $GLOBALS['DISCORD_TRIAL_ROLE_ID']); } catch (Exception $e) {}
        }
        if (!empty($GLOBALS['DISCORD_NON_MEMBER_ROLE_ID'])) {
            try { discord_remove_role($u['discord_id'], $GLOBALS['DISCORD_NON_MEMBER_ROLE_ID']); } catch (Exception $e) {}
        }
        log_user_action($user_id, 'membership', "Adhésion validée jusqu'au 31/12.", $_SESSION['user']['id']);
        header("Location: /profil_joueur?id=$user_id"); exit;
    }
    
    if (isset($_POST['extend_trial'])) {
        if (empty($_SESSION['user']['is_bureau']) && empty($_SESSION['user']['is_secretariat']) && empty($_SESSION['user']['can_extend_trial']) && empty($_SESSION['user']['can_manage_players'])) {
            http_response_code(403); exit('Permission refusée pour prolonger la période d\'essai.');
        }
        $extend_val = max(1, (int)($_POST['trial_extend_value'] ?? 1));
        $extend_unit = in_array($_POST['trial_extend_unit'] ?? '', ['days', 'months']) ? $_POST['trial_extend_unit'] : 'days';
        $unit_word = ($extend_unit === 'months') ? 'MONTH' : 'DAY';
        
        $pdo->prepare("UPDATE users SET trial_start_date = DATE_ADD(trial_start_date, INTERVAL ? $unit_word) WHERE id = ?")->execute([$extend_val, $user_id]);
        
        $unit_fr = ($extend_unit === 'months') ? 'mois' : ($extend_val > 1 ? 'jours' : 'jour');
        log_user_action($user_id, 'status_change', "Période d'essai prolongée de $extend_val $unit_fr.", $_SESSION['user']['id']);
        header("Location: /profil_joueur?id=$user_id"); exit;
    }
    
    if (isset($_POST['change_status'])) {
        if (empty($_SESSION['user']['is_bureau']) && empty($_SESSION['user']['is_secretariat']) && empty($_SESSION['user']['can_change_player_status']) && empty($_SESSION['user']['can_manage_players'])) {
            http_response_code(403); exit('Permission refusée pour changer le statut du joueur.');
        }
        $new_status = $_POST['new_status'];
        $reason = trim($_POST['status_reason'] ?? '');
        $reapply_val = isset($_POST['reapply_value']) ? (int)$_POST['reapply_value'] : (isset($_POST['reapply_months']) ? (int)$_POST['reapply_months'] : -1);
        $reapply_unit = in_array($_POST['reapply_unit'] ?? '', ['days', 'months', 'years']) ? $_POST['reapply_unit'] : 'months';
        $is_def_ban = !empty($_POST['reapply_permanent']);
        
        $pdo->prepare("UPDATE users SET status = ? WHERE id = ?")->execute([$new_status, $user_id]);
        
        // Gestion du délai de repostulation si renseigné
        if ($is_def_ban) {
            $reapply_time = date('Y-m-d H:i:s', strtotime("+99 years"));
            $pdo->prepare("UPDATE users SET can_reapply_after = ? WHERE id = ?")->execute([$reapply_time, $user_id]);
            log_user_action($user_id, 'reapply_delay', "Interdiction définitive de repostuler appliquée suite au changement de statut ($new_status).", $_SESSION['user']['id']);
        } elseif ($reapply_val >= 0) {
            $reapply_time = $reapply_val === 0 ? null : date('Y-m-d H:i:s', strtotime("+$reapply_val $reapply_unit"));
            $pdo->prepare("UPDATE users SET can_reapply_after = ? WHERE id = ?")->execute([$reapply_time, $user_id]);
            if ($reapply_val > 0) {
                $unit_fr = ['days' => 'jour(s)', 'months' => 'mois', 'years' => 'an(s)'][$reapply_unit] ?? $reapply_unit;
                log_user_action($user_id, 'reapply_delay', "Délai avant repostulation fixé à $reapply_val $unit_fr.", $_SESSION['user']['id']);
            } else {
                log_user_action($user_id, 'reapply_delay', "Délai avant repostulation révoqué (aucun blocage).", $_SESSION['user']['id']);
            }
        }
        
        $log_message = "Changement de statut vers : $new_status";
        if (!empty($reason) && in_array($new_status, ['exclu', 'ban', 'left'])) {
            $log_message .= " | Motif : $reason";
        }
        
        log_user_action($user_id, 'status_change', $log_message, $_SESSION['user']['id']);
        header("Location: /profil_joueur?id=$user_id"); exit;
    }
    
    if (isset($_POST['set_reapply_delay'])) {
        if (empty($_SESSION['user']['is_bureau']) && empty($_SESSION['user']['is_secretariat']) && empty($_SESSION['user']['can_set_reapply_delay']) && empty($_SESSION['user']['can_manage_players'])) {
            http_response_code(403); exit('Permission refusée pour modifier le délai de repostulation.');
        }
        $is_def_ban = !empty($_POST['reapply_permanent']);
        $reapply_val = (int)($_POST['reapply_value'] ?? ($_POST['reapply_months'] ?? 0));
        $reapply_unit = in_array($_POST['reapply_unit'] ?? '', ['days', 'months', 'years']) ? $_POST['reapply_unit'] : 'months';
        
        if ($is_def_ban) {
            $reapply_time = date('Y-m-d H:i:s', strtotime("+99 years"));
            $pdo->prepare("UPDATE users SET can_reapply_after = ? WHERE id = ?")->execute([$reapply_time, $user_id]);
            log_user_action($user_id, 'reapply_delay', "Interdiction définitive de repostuler appliquée manuellement.", $_SESSION['user']['id']);
        } elseif ($reapply_val > 0) {
            $reapply_time = date('Y-m-d H:i:s', strtotime("+$reapply_val $reapply_unit"));
            $pdo->prepare("UPDATE users SET can_reapply_after = ? WHERE id = ?")->execute([$reapply_time, $user_id]);
            $unit_fr = ['days' => 'jour(s)', 'months' => 'mois', 'years' => 'an(s)'][$reapply_unit] ?? $reapply_unit;
            log_user_action($user_id, 'reapply_delay', "Délai de repostulation fixé à $reapply_val $unit_fr.", $_SESSION['user']['id']);
        } else {
            $pdo->prepare("UPDATE users SET can_reapply_after = NULL WHERE id = ?")->execute([$user_id]);
            log_user_action($user_id, 'reapply_delay', "Délai de repostulation révoqué (libre de postuler).", $_SESSION['user']['id']);
        }
        header("Location: /profil_joueur?id=$user_id"); exit;
    }
    
    if (isset($_POST['add_note'])) {
        $note_text = trim($_POST['note_text'] ?? '');
        $note_type = in_array($_POST['note_type'] ?? '', ['incident', 'departure', 'trial', 'general']) ? $_POST['note_type'] : 'general';
        $attachments_json = null;
        
        if (!empty($_POST['attachments_json'])) {
            $decoded = json_decode($_POST['attachments_json'], true);
            if (is_array($decoded) && !empty($decoded)) {
                $attachments_json = json_encode(array_values($decoded), JSON_UNESCAPED_SLASHES);
            }
        }
        
        // Si un délai de repostulation est associé à cette note (ex: incident ou départ)
        if (!empty($_POST['note_apply_reapply_delay'])) {
            $note_reapply_val = (int)($_POST['note_reapply_value'] ?? ($_POST['note_reapply_months'] ?? 0));
            $note_reapply_unit = in_array($_POST['note_reapply_unit'] ?? '', ['days', 'months', 'years']) ? $_POST['note_reapply_unit'] : 'months';
            $note_reapply_perm = !empty($_POST['note_reapply_permanent']);
            if ($note_reapply_perm) {
                $pdo->prepare("UPDATE users SET can_reapply_after = ? WHERE id = ?")->execute([date('Y-m-d H:i:s', strtotime("+99 years")), $user_id]);
                log_user_action($user_id, 'reapply_delay', "Délai définitif appliqué suite à la note : " . $note_type, $_SESSION['user']['id']);
            } elseif ($note_reapply_val > 0) {
                $pdo->prepare("UPDATE users SET can_reapply_after = ? WHERE id = ?")->execute([date('Y-m-d H:i:s', strtotime("+$note_reapply_val $note_reapply_unit")), $user_id]);
                $unit_fr = ['days' => 'jour(s)', 'months' => 'mois', 'years' => 'an(s)'][$note_reapply_unit] ?? $note_reapply_unit;
                log_user_action($user_id, 'reapply_delay', "Délai de $note_reapply_val $unit_fr appliqué suite à la note : " . $note_type, $_SESSION['user']['id']);
            }
        }
        
        if (!empty($note_text) || !empty($attachments_json)) {
            $pdo->prepare("INSERT INTO user_notes (user_id, recruiter_id, note_type, note_text, attachments_json) VALUES (?, ?, ?, ?, ?)")
                ->execute([$user_id, $_SESSION['user']['id'], $note_type, $note_text, $attachments_json]);
            
            $type_labels = [
                'incident' => 'Incident documenté',
                'departure' => 'Motif de départ',
                'trial' => 'Note de période d\'essai',
                'general' => 'Note générale'
            ];
            $type_label = $type_labels[$note_type] ?? 'Note personnalisée';
            log_user_action($user_id, 'note_added', "$type_label ajoutée (avec pièces jointes : " . ($attachments_json ? 'Oui' : 'Non') . ").", $_SESSION['user']['id']);
        }
        header("Location: /profil_joueur?id=$user_id"); exit;
    }
}

// Fetch logs avec informations complètes sur le rôle de l'auteur
$stmtLogs = $pdo->prepare("
    SELECT l.*, 
           u.display_name as recruiter_name, 
           u.username as recruiter_username,
           u.is_bureau, 
           u.is_secretariat, 
           u.is_recruiter, 
           u.recruiter_type, 
           u.can_manage_access,
           u.can_manage_players,
           u.can_validate_membership,
           u.can_extend_trial,
           u.can_change_player_status,
           u.can_set_reapply_delay,
           u.last_synced_roles 
    FROM user_logs l 
    LEFT JOIN users u ON l.recruiter_id = u.id 
    WHERE l.user_id = ? 
    ORDER BY l.created_at DESC
");
$stmtLogs->execute([$user_id]);
$user_logs = $stmtLogs->fetchAll(PDO::FETCH_ASSOC);

// Fetch custom notes avec informations complètes sur le rôle de l'auteur
$stmtNotes = $pdo->prepare("
    SELECT n.*, 
           u.display_name as recruiter_name, 
           u.username as recruiter_username,
           u.is_bureau, 
           u.is_secretariat, 
           u.is_recruiter, 
           u.recruiter_type, 
           u.can_manage_access,
           u.can_manage_players,
           u.can_validate_membership,
           u.can_extend_trial,
           u.can_change_player_status,
           u.can_set_reapply_delay,
           u.last_synced_roles 
    FROM user_notes n 
    LEFT JOIN users u ON n.recruiter_id = u.id 
    WHERE n.user_id = ? 
    ORDER BY n.created_at DESC
");
$stmtNotes->execute([$user_id]);
$user_notes = $stmtNotes->fetchAll(PDO::FETCH_ASSOC);

function get_user_role_info($row) {
    global $DISCORD_BUREAU_ROLE_ID, $DISCORD_SECRETARIAT_ROLE_ID, $DISCORD_CADRE_ROLE_ID, $DISCORD_RESP_DEV_ROLE_ID, $DISCORD_RECRUITER_ROLE_ID, $DISCORD_RECRUITER_OP_FDO_ROLE_ID, $DISCORD_RECRUITER_OP_SP_ROLE_ID;

    if (empty($row['recruiter_id']) || ($row['recruiter_name'] ?? '') === 'Système') {
        return [
            'key' => 'system',
            'label' => 'Système',
            'icon' => '🤖',
            'class' => 'role-system'
        ];
    }

    $roles = [];
    if (!empty($row['last_synced_roles'])) {
        $roles = json_decode($row['last_synced_roles'], true) ?: [];
    }

    // 1. Bureau (DG)
    if (!empty($row['is_bureau']) || (!empty($DISCORD_BUREAU_ROLE_ID) && in_array($DISCORD_BUREAU_ROLE_ID, $roles))) {
        return [
            'key' => 'bureau',
            'label' => 'Bureau',
            'icon' => '👑',
            'class' => 'role-bureau'
        ];
    }

    // 2. Secrétariat
    if (!empty($row['is_secretariat']) || (!empty($DISCORD_SECRETARIAT_ROLE_ID) && in_array($DISCORD_SECRETARIAT_ROLE_ID, $roles))) {
        return [
            'key' => 'secretariat',
            'label' => 'Secrétariat',
            'icon' => '🏛️',
            'class' => 'role-secretariat'
        ];
    }

    // 3. Cadre Staff
    if (!empty($row['can_manage_access']) || !empty($row['can_manage_players']) || (!empty($DISCORD_CADRE_ROLE_ID) && in_array($DISCORD_CADRE_ROLE_ID, $roles))) {
        return [
            'key' => 'cadre',
            'label' => 'Cadre',
            'icon' => '🛡️',
            'class' => 'role-cadre'
        ];
    }

    // 4. Recruteur SP
    if (($row['recruiter_type'] ?? '') === 'operator_sp' || (!empty($DISCORD_RECRUITER_OP_SP_ROLE_ID) && in_array($DISCORD_RECRUITER_OP_SP_ROLE_ID, $roles))) {
        return [
            'key' => 'recruiter_sp',
            'label' => 'Recruteur SP',
            'icon' => '🚒',
            'class' => 'role-recruiter-sp'
        ];
    }

    // 5. Recruteur FDO
    if (($row['recruiter_type'] ?? '') === 'operator_fdo' || (!empty($DISCORD_RECRUITER_OP_FDO_ROLE_ID) && in_array($DISCORD_RECRUITER_OP_FDO_ROLE_ID, $roles))) {
        return [
            'key' => 'recruiter_fdo',
            'label' => 'Recruteur FDO',
            'icon' => '👮',
            'class' => 'role-recruiter-fdo'
        ];
    }

    // 6. Responsable Développement
    if (($row['recruiter_type'] ?? '') === 'developer' || (!empty($DISCORD_RESP_DEV_ROLE_ID) && in_array($DISCORD_RESP_DEV_ROLE_ID, $roles))) {
        return [
            'key' => 'dev',
            'label' => 'Développeur',
            'icon' => '💻',
            'class' => 'role-dev'
        ];
    }

    // 7. Recruteur Joueur
    if (!empty($row['is_recruiter']) || ($row['recruiter_type'] ?? '') === 'player' || (!empty($DISCORD_RECRUITER_ROLE_ID) && in_array($DISCORD_RECRUITER_ROLE_ID, $roles))) {
        return [
            'key' => 'recruiter',
            'label' => 'Recruteur',
            'icon' => '🎮',
            'class' => 'role-recruiter'
        ];
    }

    // 8. Staff général
    return [
        'key' => 'staff',
        'label' => 'Staff',
        'icon' => '👤',
        'class' => 'role-staff'
    ];
}

function getAge($date) {
    if (empty($date)) return '';
    try {
        if (strpos($date, '/') !== false) {
            $parts = explode('/', $date);
            if (count($parts) == 3 && strlen($parts[0]) == 2) { $date = $parts[2] . '-' . $parts[1] . '-' . $parts[0]; }
        }
        $birthDate = new DateTime($date);
        $today = new DateTime();
        return $birthDate->diff($today)->y . ' ans';
    } catch (Exception $e) { return ''; }
}

$is_in_trial = in_array($u['status'], ['essai', 'essai_adhesion']);
$formatted_playtime = "0h 00m";
$playtime_progress = 0;
$days_passed = 0;
$seniority_progress = 0;

if ($is_in_trial && !empty($u['trial_start_date'])) {
    $now = new DateTime();
    $playtime_seconds = (int)$u['total_playtime'];
    $playtime_goal_seconds = 10 * 3600;
    $playtime_progress = min(100, ($playtime_seconds / $playtime_goal_seconds) * 100);
    $hours = floor($playtime_seconds / 3600);
    $minutes = floor(($playtime_seconds % 3600) / 60);
    $formatted_playtime = sprintf('%dh %02dm', $hours, $minutes);

    $trial_start_dt = new DateTime($u['trial_start_date']);
    $trial_start_date_only = clone $trial_start_dt;
    $trial_start_date_only->setTime(0, 0, 0);
    
    if ((int)$trial_start_dt->format('G') >= 22) {
        $trial_start_date_only->modify('+1 day');
    }
    
    $now_date_only = new DateTime();
    $now_date_only->setTime(0, 0, 0);
    
    $days_passed = $trial_start_date_only->diff($now_date_only)->days;
    if ($now_date_only < $trial_start_date_only) $days_passed = 0;

    $seniority_goal_days = 7;
    $seniority_progress = min(100, ($days_passed / $seniority_goal_days) * 100);
}

function display_answers($answers, $is_operator = false) { 
    $labels_p = [ 
        'discord_pseudo' => ['label' => 'Discord', 'icon' => '🎮'], 
        'email' => ['label' => 'Email', 'icon' => '📧'], 
        'birthdate' => ['label' => 'Naissance', 'icon' => '🎂'], 
        'activity' => ['label' => 'Activité', 'icon' => '🎯'], 
        'has_mic' => ['label' => 'Micro', 'icon' => '🎙️'], 
        'has_gta' => ['label' => 'GTA V', 'icon' => '🚗'],
        'is_pro' => ['label' => 'Service Pro', 'icon' => '💼', 'full' => true],
        'motivations' => ['label' => 'Motivations', 'icon' => '💡', 'full' => true], 
        'experience' => ['label' => 'Expérience RP', 'icon' => '📜', 'full' => true], 
        'scenario' => ['label' => 'Intervention inventée', 'icon' => '🎬', 'full' => true], 
    ]; 
    $config = $labels_p; 
    $html = '<div class="dossier-grid" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 15px;">'; 
    foreach ($config as $key => $info) { 
        if (empty($answers[$key]) && !is_bool($answers[$key])) continue; 
        $val = $answers[$key]; 
        if (is_bool($val)) $val = $val ? 'Oui' : 'Non';
        if ($key == 'activity' && $val == 'gta_rp') $val = 'GTA V RP';
        if ($key == 'birthdate') {
            $ageText = getAge($val);
            $valTime = strtotime(str_replace('/', '-', $val));
            if ($valTime) { $val = date('d/m/Y', $valTime) . ($ageText ? " ($ageText)" : ""); }
        }
        if ($key == 'is_pro') {
            if (strtolower($val) == 'non' && empty($answers['service_pro_details'])) continue;
            if (!empty($answers['service_pro_details'])) { $val = "Oui : " . $answers['service_pro_details']; }
        }
        $is_full = !empty($info['full']);
        $grid_style = $is_full ? 'grid-column: 1 / -1;' : '';
        $box_style = $is_full ? 'background: rgba(0,0,0,0.1); padding: 12px; border-radius: 10px; border-left: 4px solid var(--accent-secondary);' : 'background: rgba(0,0,0,0.05); padding: 12px; border-radius: 10px; border: 1px solid var(--border-color);';
        
        $html .= '<div class="dossier-item '.$is_full.'" style="'.$grid_style.' overflow: hidden;">';
        $html .= '<div class="dossier-label" style="display: block; color: var(--text-muted); margin-bottom: 6px; font-size: 0.75rem; text-transform: uppercase; font-weight: bold; letter-spacing: 0.5px;">'.$info['icon'].' '.$info['label'].'</div>';
        $html .= '<div class="dossier-value" style="'.$box_style.' font-size: 0.95rem; line-height: 1.5; color: var(--text-primary); overflow-wrap: break-word; word-break: break-word;">'.nl2br(htmlspecialchars($val)).'</div>';
        $html .= '</div>';
    } 
    $html .= '</div>'; 
    return $html; 
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Profil Joueur — <?= htmlspecialchars($u['display_name']) ?></title>
    <link rel="icon" type="image/png" href="assets/logo.png">
    <link rel="stylesheet" href="assets/styles.css">
    <style>
        .main-grid { display: grid; grid-template-columns: 2fr 1fr; gap: 24px; align-items: flex-start; }
        .sidebar { position: sticky; top: 90px; display: flex; flex-direction: column; gap: 24px; }
        .tabs { display: flex; border-bottom: 1px solid var(--border-color); margin-bottom: 15px; }
        .tab-link { padding: 8px 16px; cursor: pointer; color: var(--text-secondary); border-bottom: 2px solid transparent; }
        .tab-link.active { color: var(--text-primary); border-color: var(--accent-primary); }
        .tab-content { display: none; }
        .tab-content.active { display: block; }
        .badge-status { display: inline-block; padding: 4px 10px; border-radius: 6px; font-size: 0.85rem; font-weight: bold; text-transform: uppercase; margin-left: 10px; }
        .badge-joueur { background: rgba(46, 204, 113, 0.15); color: #2ecc71; border: 1px solid rgba(46, 204, 113, 0.3); }
        .badge-essai { background: rgba(243, 156, 18, 0.15); color: #f39c12; border: 1px solid rgba(243, 156, 18, 0.3); }
        .badge-danger { background: rgba(231, 76, 60, 0.15); color: #e74c3c; border: 1px solid rgba(231, 76, 60, 0.3); }
        .progress-card { background: var(--bg-secondary); border-radius: 12px; padding: 20px; margin-bottom: 20px; border: 1px solid var(--border-color); }
        .progress-label { display: flex; justify-content: space-between; align-items: baseline; margin-bottom: 10px; font-weight: 600; font-size: 0.9rem; }
        .progress-bar-container { width: 100%; height: 10px; background: var(--bg-primary); border-radius: 5px; overflow: hidden; }
        .progress-bar { height: 100%; background: var(--gradient-primary); border-radius: 5px; transition: width 0.5s linear; }
        
        /* Styles notes enrichies & drag/drop */
        .note-card { padding: 16px; border: 1px solid var(--border-color); border-radius: 10px; margin-bottom: 15px; background: rgba(255,255,255,0.02); }
        .note-card.incident { border-left: 4px solid var(--accent-red); background: rgba(231, 76, 60, 0.05); }
        .note-card.departure { border-left: 4px solid var(--accent-tertiary); background: rgba(243, 156, 18, 0.05); }
        .note-card.trial { border-left: 4px solid var(--accent-primary); background: rgba(52, 152, 219, 0.05); }
        .note-badge { display: inline-block; padding: 2px 8px; border-radius: 4px; font-size: 0.75rem; font-weight: bold; text-transform: uppercase; margin-bottom: 8px; }
        .note-badge.incident { background: rgba(231,76,60,0.2); color: #e74c3c; }
        .note-badge.departure { background: rgba(243,156,18,0.2); color: #f39c12; }
        .note-badge.trial { background: rgba(52,152,219,0.2); color: #3498db; }
        .note-badge.general { background: rgba(255,255,255,0.1); color: var(--text-secondary); }
        .attachments-grid { display: flex; flex-wrap: wrap; gap: 10px; margin-top: 12px; }
        .attachment-thumb { width: 90px; height: 90px; border-radius: 8px; object-fit: cover; border: 1px solid var(--border-color); cursor: pointer; transition: transform 0.2s, box-shadow 0.2s; }
        .attachment-thumb:hover { transform: scale(1.05); box-shadow: 0 4px 15px rgba(0,0,0,0.3); border-color: var(--accent-primary); }
        .dropzone { border: 2px dashed var(--border-color); border-radius: 8px; padding: 20px; text-align: center; background: rgba(0,0,0,0.1); cursor: pointer; transition: all 0.2s; margin-bottom: 12px; }
        .dropzone.dragover { border-color: var(--accent-primary); background: rgba(231, 76, 60, 0.1); }
        .upload-previews { display: flex; flex-wrap: wrap; gap: 10px; margin-bottom: 12px; }
        .upload-preview-item { position: relative; width: 75px; height: 75px; border-radius: 6px; overflow: hidden; border: 1px solid var(--border-color); }
        .upload-preview-item img { width: 100%; height: 100%; object-fit: cover; }
        .upload-preview-item .remove-btn { position: absolute; top: 2px; right: 2px; background: rgba(0,0,0,0.7); color: white; border: none; border-radius: 50%; width: 20px; height: 20px; font-size: 11px; cursor: pointer; display: flex; align-items: center; justify-content: center; }
        .lightbox-modal { display: none; position: fixed; z-index: 9999; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.85); align-items: center; justify-content: center; }
        .lightbox-modal img { max-width: 90%; max-height: 90%; border-radius: 8px; box-shadow: 0 5px 30px rgba(0,0,0,0.5); }

        /* Styles des étiquettes de rôle staff / auteur */
        .role-badge {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 3px 8px;
            border-radius: 6px;
            font-size: 0.72rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.3px;
            white-space: nowrap;
        }
        .role-bureau { background: rgba(241, 196, 15, 0.15); color: #f1c40f; border: 1px solid rgba(241, 196, 15, 0.4); }
        .role-secretariat { background: rgba(52, 152, 219, 0.15); color: #3498db; border: 1px solid rgba(52, 152, 219, 0.4); }
        .role-cadre { background: rgba(155, 89, 182, 0.15); color: #9b59b6; border: 1px solid rgba(155, 89, 182, 0.4); }
        .role-recruiter-sp { background: rgba(231, 76, 60, 0.15); color: #e74c3c; border: 1px solid rgba(231, 76, 60, 0.4); }
        .role-recruiter-fdo { background: rgba(41, 128, 185, 0.15); color: #3498db; border: 1px solid rgba(41, 128, 185, 0.4); }
        .role-recruiter { background: rgba(46, 204, 113, 0.15); color: #2ecc71; border: 1px solid rgba(46, 204, 113, 0.4); }
        .role-dev { background: rgba(142, 68, 173, 0.15); color: #af7ac5; border: 1px solid rgba(142, 68, 173, 0.4); }
        .role-staff { background: rgba(255, 255, 255, 0.08); color: var(--text-secondary); border: 1px solid var(--border-color); }
        .role-system { background: rgba(149, 165, 166, 0.15); color: #bdc3c7; border: 1px solid rgba(149, 165, 166, 0.3); }

        /* Barre d'outils filtre et recherche historique */
        .history-toolbar {
            background: rgba(255, 255, 255, 0.02);
            border: 1px solid var(--border-color);
            border-radius: 12px;
            padding: 14px;
            margin-bottom: 16px;
        }
        .history-controls {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            align-items: center;
        }
        .history-search-input {
            flex: 2;
            min-width: 200px;
            margin-bottom: 0 !important;
            padding: 9px 14px;
            font-size: 0.9rem;
        }
        .history-filter-select {
            flex: 1;
            min-width: 150px;
            margin-bottom: 0 !important;
            padding: 9px 12px;
            font-size: 0.85rem;
            background: var(--bg-card);
            color: var(--text-primary);
            border: 1px solid var(--border-color);
            border-radius: 8px;
        }
        .history-item {
            padding: 14px;
            border-bottom: 1px solid var(--border-color);
            display: flex;
            justify-content: space-between;
            gap: 15px;
            transition: background-color 0.2s ease;
        }
        .history-item:hover {
            background: rgba(255, 255, 255, 0.02);
        }
    </style>
</head>
<body>
<?php include __DIR__.'/partiels_entete.php'; ?>
<div class="wrap page-container">
    <h2 class="mb-2" style="display:flex;align-items:center;flex-wrap:wrap;gap:10px;">
        Profil de <?= htmlspecialchars($u['display_name'] ?? $u['username']) ?>
        <?php
        $badge_class = 'badge-essai';
        if (in_array($u['status'], ['joueur', 'active'])) $badge_class = 'badge-joueur';
        elseif (in_array($u['status'], ['exclu', 'ban'])) $badge_class = 'badge-danger';
        ?>
        <span class="badge-status <?= $badge_class ?>"><?= strtoupper($u['status']) ?></span>
    </h2>

    <div class="main-grid">
        <div class="main-content">
            <div class="card mb-3" style="display: flex; gap: 20px; flex-wrap: wrap;">
                <div style="flex: 1;">
                    <h4 style="margin-top:0;">Dernière connexion globale</h4>
                    <p class="text-secondary"><?= $u['last_connection'] ? date('d/m/Y H:i', strtotime($u['last_connection'])) : 'Inconnue' ?></p>
                </div>
                <div style="flex: 1;">
                    <h4 style="margin-top:0;">Présences en Session</h4>
                    <p class="text-secondary">
                        <?= $u['session_presence_count'] ?> jours 
                        <br><small style="font-size: 0.8em; color: var(--text-muted);">(Dernière: <?= $u['last_session_connection'] ? date('d/m/Y', strtotime($u['last_session_connection'])) : 'Jamais' ?>)</small>
                    </p>
                </div>
                <div style="flex: 1;">
                    <h4 style="margin-top:0;">Adhésion Annuelle</h4>
                    <?php if (!empty($u['membership_expires_at'])): ?>
                        <p class="text-secondary" style="color: var(--accent-success); font-weight: bold;">Valide jusqu'au <?= date('d/m/Y', strtotime($u['membership_expires_at'])) ?></p>
                    <?php else: ?>
                        <p class="text-secondary" style="color: var(--accent-red);">Non membre / Expirée</p>
                    <?php endif; ?>
                </div>
            </div>

            <?php if ($is_in_trial): ?>
            <div class="card mb-3">
                <h4 style="margin-top:0; margin-bottom: 15px;">Progression Période d'Essai</h4>
                <div style="display: flex; gap: 20px; flex-wrap: wrap;">
                    <div class="progress-card" style="flex: 1; margin: 0;">
                        <div class="progress-label"><span>Ancienneté</span><span><?= $days_passed ?> / 7 jours</span></div>
                        <div class="progress-bar-container"><div class="progress-bar" style="width: <?= $seniority_progress ?>%;"></div></div>
                    </div>
                    <div class="progress-card" style="flex: 1; margin: 0;">
                        <div class="progress-label">
                            <span>Temps de jeu en session</span>
                            <span><?= $formatted_playtime ?> / 10h</span>
                        </div>
                        <div class="progress-bar-container"><div class="progress-bar" style="width: <?= $playtime_progress ?>%;"></div></div>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <div class="card">
                <div class="tabs">
                    <div class="tab-link active" onclick="openTab(event, 'history')">Suivi & Historique</div>
                    <div class="tab-link" onclick="openTab(event, 'notes')">Notes Personnalisées</div>
                    <?php if ($u['answers_json']): ?>
                    <div class="tab-link" onclick="openTab(event, 'candidature')">Candidature Initiale</div>
                    <?php endif; ?>
                </div>

                <div id="history" class="tab-content active">
                    <!-- Barre d'outils Recherche & Filtres -->
                    <div class="history-toolbar">
                        <div class="history-controls">
                            <input type="text" id="history-search" class="history-search-input" placeholder="🔍 Rechercher dans l'historique (action, auteur, détails)...">
                            <select id="history-filter-type" class="history-filter-select">
                                <option value="">Toutes les actions</option>
                                <option value="STATUS_CHANGE">Statut</option>
                                <option value="ROLE_CHANGE">Rôles Discord</option>
                                <option value="SANCTION">Sanctions</option>
                                <option value="MEMBERSHIP">Adhésions</option>
                                <option value="DEPARTURE">Départs</option>
                            </select>
                            <select id="history-filter-role" class="history-filter-select">
                                <option value="">Tous les rôles</option>
                                <option value="bureau">👑 Bureau</option>
                                <option value="secretariat">🏛️ Secrétariat</option>
                                <option value="cadre">🛡️ Cadre</option>
                                <option value="recruiter_sp">🚒 Recruteur SP</option>
                                <option value="recruiter_fdo">👮 Recruteur FDO</option>
                                <option value="recruiter">🎮 Recruteur Joueur</option>
                                <option value="dev">💻 Développeur</option>
                                <option value="staff">👤 Staff</option>
                                <option value="system">🤖 Système</option>
                            </select>
                        </div>
                        <div id="history-counter" style="margin-top: 10px; font-size: 0.8rem; color: var(--text-muted); display: flex; justify-content: space-between; align-items: center;">
                            <span id="history-count-text"><?= count($user_logs) ?> événement(s) au total</span>
                            <button type="button" id="history-reset-btn" style="background: none; border: none; color: var(--accent-secondary); font-size: 0.8rem; cursor: pointer; text-decoration: underline; display: none;" onclick="resetHistoryFilters()">Réinitialiser les filtres</button>
                        </div>
                    </div>

                    <div id="history-list" style="max-height: 500px; overflow-y: auto; font-size: 0.9rem;">
                        <?php if(empty($user_logs)): ?>
                            <p class="text-muted" style="padding: 15px;">Aucun historique enregistré.</p>
                        <?php else: foreach($user_logs as $log): 
                            $tag = strtoupper((string)($log['action_type'] ?? ''));
                            $tag_colors = ['STATUS_CHANGE' => 'STATUT', 'ROLE_CHANGE' => 'RÔLES DISCORD', 'SANCTION' => 'SANCTION', 'MEMBERSHIP' => 'ADHÉSION', 'DEPARTURE' => 'DÉPART'];
                            $tag_text = $tag_colors[$tag] ?? $tag;
                            $role_info = get_user_role_info($log);
                            $author_name = (string)($log['recruiter_name'] ?? $log['recruiter_username'] ?? 'Système');
                            $log_details = (string)($log['details'] ?? '');
                            $role_key = (string)($role_info['key'] ?? 'system');
                            $role_label = (string)($role_info['label'] ?? 'Système');
                            $search_corpus = strtolower($tag_text . ' ' . $tag . ' ' . $log_details . ' ' . $author_name . ' ' . $role_label);
                        ?>
                            <div class="history-item" data-type="<?= htmlspecialchars($tag) ?>" data-role="<?= htmlspecialchars($role_key) ?>" data-search="<?= htmlspecialchars($search_corpus) ?>">
                                <div style="flex: 1;">
                                    <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 6px; flex-wrap: wrap;">
                                        <span class="badge-status" style="font-size: 0.7rem; padding: 2px 6px; background: rgba(0,0,0,0.1); border: 1px solid var(--border-color); color: var(--text-primary); margin: 0;"><?= htmlspecialchars($tag_text) ?></span>
                                    </div>
                                    <p style="margin: 0; line-height: 1.45;"><?= nl2br(htmlspecialchars($log_details)) ?></p>
                                </div>
                                <div style="text-align: right; min-width: 170px; display: flex; flex-direction: column; align-items: flex-end; justify-content: center; gap: 4px;">
                                    <small class="text-muted"><?= !empty($log['created_at']) ? date('d/m/Y H:i', strtotime($log['created_at'])) : '' ?></small>
                                    <div style="display: flex; align-items: center; gap: 6px; flex-wrap: wrap; justify-content: flex-end;">
                                        <small>Par : <strong><?= htmlspecialchars($author_name) ?></strong></small>
                                        <span class="role-badge <?= htmlspecialchars($role_info['class'] ?? 'role-system') ?>" title="<?= htmlspecialchars($role_label) ?>">
                                            <?= $role_info['icon'] ?? '👤' ?> <?= htmlspecialchars($role_label) ?>
                                        </span>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; endif; ?>
                        <div id="history-no-result" style="display: none; padding: 25px; text-align: center; color: var(--text-muted);">
                            <p style="margin: 0 0 5px 0; font-size: 1.1rem;">🔍 Aucun événement ne correspond à vos filtres.</p>
                            <small>Essayez d'ajuster vos mots-clés ou de réinitialiser les critères.</small>
                        </div>
                    </div>
                </div>

                <div id="notes" class="tab-content">
                    <div style="margin-bottom: 25px; padding: 20px; background: var(--bg-secondary); border: 1px solid var(--border-color); border-radius: 12px;">
                        <h4 style="margin-top: 0; margin-bottom: 15px; display: flex; align-items: center; gap: 8px;">
                            <span>📝</span> Ajouter une note / Incident / Motif de départ
                        </h4>
                        <form method="post" id="note-form">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                            <input type="hidden" name="add_note" value="1">
                            <input type="hidden" name="attachments_json" id="note_attachments_input" value="[]">

                            <div style="display: flex; gap: 15px; margin-bottom: 12px; flex-wrap: wrap;">
                                <div style="flex: 1; min-width: 200px;">
                                    <label style="font-size: 0.85rem; color: var(--text-muted); display: block; margin-bottom: 5px;">Type de note :</label>
                                    <select name="note_type" id="note_type_select" class="card" style="margin: 0; width: 100%; padding: 8px 12px;" onchange="toggleNoteDelayBlock(this.value)">
                                        <option value="general">Note générale / Suivi</option>
                                        <option value="trial">Période d'essai (évaluation, rapport)</option>
                                        <option value="incident">⚠️ Incident / Avertissement / Fait marquant</option>
                                        <option value="departure">🚪 Motif de départ / Démission / Radiation</option>
                                    </select>
                                </div>
                            </div>

                            <div class="form-group" style="margin-bottom: 12px;">
                                <label style="font-size: 0.85rem; color: var(--text-muted); display: block; margin-bottom: 5px;">Description détaillée :</label>
                                <textarea name="note_text" id="note_textarea" rows="4" style="width: 100%; box-sizing: border-box; margin: 0; padding: 12px;" placeholder="Expliquez ici les faits, le déroulement de la PE ou les circonstances du départ (vous pouvez aussi coller des captures avec Ctrl+V)..." required></textarea>
                            </div>

                            <!-- Zone Drag & Drop et Coller d'images -->
                            <div class="dropzone" id="note-dropzone">
                                <div style="font-size: 1.8rem; margin-bottom: 5px;">📸</div>
                                <div style="font-weight: 600; color: var(--text-primary);">Glissez-déposez des captures d'écran ici, ou collez-les avec <kbd style="background: rgba(255,255,255,0.1); padding: 2px 6px; border-radius: 4px;">Ctrl+V</kbd></div>
                                <div style="font-size: 0.8rem; color: var(--text-muted); margin-top: 4px;">Formats acceptés : PNG, JPG, WEBP, GIF (Max 8 Mo) • <span style="text-decoration: underline; color: var(--accent-secondary);">Parcourir les fichiers</span></div>
                                <input type="file" id="note-file-input" multiple accept="image/*" style="display: none;">
                            </div>

                            <div class="upload-previews" id="note-upload-previews"></div>

                            <!-- Option de délai de repostulation liée à l'incident / départ -->
                            <div id="note-delay-block" style="display: none; padding: 12px; background: rgba(231,76,60,0.08); border: 1px solid rgba(231,76,60,0.3); border-radius: 8px; margin-bottom: 15px;">
                                <label style="display: flex; align-items: center; gap: 8px; cursor: pointer; font-weight: 600; color: var(--accent-red); margin-bottom: 8px;">
                                    <input type="checkbox" name="note_apply_reapply_delay" id="note_apply_reapply_delay" value="1" onchange="document.getElementById('note_delay_fields').style.display = this.checked ? 'flex' : 'none';">
                                    Appliquer un délai avant repostulation à ce joueur
                                </label>
                                <div id="note_delay_fields" style="display: none; gap: 15px; align-items: center; flex-wrap: wrap;">
                                    <div style="display: flex; align-items: center; gap: 8px;">
                                        <label style="font-size: 0.85rem;">Durée :</label>
                                        <input type="number" name="note_reapply_value" id="note_reapply_value" min="1" max="999" value="1" class="card" style="width: 70px; padding: 6px; margin: 0;">
                                        <select name="note_reapply_unit" id="note_reapply_unit" class="card" style="width: auto; padding: 6px; margin: 0;">
                                            <option value="days">Jour(s)</option>
                                            <option value="months" selected>Mois</option>
                                            <option value="years">An(s)</option>
                                        </select>
                                    </div>
                                    <label style="display: flex; align-items: center; gap: 6px; font-size: 0.85rem; cursor: pointer;">
                                        <input type="checkbox" name="note_reapply_permanent" id="note_reapply_permanent" value="1" onchange="const d = this.checked; document.getElementById('note_reapply_value').disabled = d; document.getElementById('note_reapply_unit').disabled = d;">
                                        Interdiction définitive (Bannissement)
                                    </label>
                                </div>
                            </div>

                            <button type="submit" class="btn btn-primary" id="note-submit-btn">Enregistrer la note</button>
                        </form>
                    </div>

                    <div style="max-height: 600px; overflow-y: auto;">
                        <?php if(empty($user_notes)): ?>
                            <p class="text-muted">Aucune note enregistrée pour ce joueur.</p>
                        <?php else: foreach($user_notes as $note): 
                            $note_role_info = get_user_role_info($note);
                            $note_author_name = (string)($note['recruiter_name'] ?? $note['recruiter_username'] ?? 'Système');
                            $note_role_label = (string)($note_role_info['label'] ?? 'Système');
                            $n_type = (string)($note['note_type'] ?? 'general');
                            $type_titles = [
                                'incident' => '⚠️ Incident / Fait marquant',
                                'departure' => '🚪 Motif de départ',
                                'trial' => '📋 Suivi Période d\'essai',
                                'general' => '📌 Note de suivi'
                            ];
                            $note_title = $type_titles[$n_type] ?? 'Note';
                            $attachments = !empty($note['attachments_json']) ? json_decode($note['attachments_json'], true) : [];
                        ?>
                            <div class="note-card <?= htmlspecialchars($n_type) ?>">
                                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px; flex-wrap: wrap; gap: 8px;">
                                    <span class="note-badge <?= htmlspecialchars($n_type) ?>"><?= $note_title ?></span>
                                    <div style="display: flex; align-items: center; gap: 8px; flex-wrap: wrap;">
                                        <small class="text-muted"><?= !empty($note['created_at']) ? date('d/m/Y H:i', strtotime($note['created_at'])) : '' ?></small>
                                        <small>Par : <strong><?= htmlspecialchars($note_author_name) ?></strong></small>
                                        <span class="role-badge <?= htmlspecialchars($note_role_info['class'] ?? 'role-system') ?>" title="<?= htmlspecialchars($note_role_label) ?>">
                                            <?= $note_role_info['icon'] ?? '👤' ?> <?= htmlspecialchars($note_role_label) ?>
                                        </span>
                                    </div>
                                </div>
                                
                                <div style="color: var(--text-primary); line-height: 1.5; font-size: 0.95rem;">
                                    <?= nl2br(htmlspecialchars((string)($note['note_text'] ?? ''))) ?>
                                </div>

                                <?php if (!empty($attachments) && is_array($attachments)): ?>
                                <div class="attachments-grid">
                                    <?php foreach ($attachments as $att): ?>
                                        <img src="<?= htmlspecialchars($att) ?>" alt="Capture" class="attachment-thumb" onclick="openLightbox('<?= htmlspecialchars($att) ?>')" title="Cliquez pour agrandir">
                                    <?php endforeach; ?>
                                </div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; endif; ?>
                    </div>
                </div>

                <?php if ($u['answers_json']): ?>
                <div id="candidature" class="tab-content">
                    <?= display_answers(json_decode($u['answers_json'], true), $u['application_type'] === 'operator') ?>
                    <br>
                    <a href="/candidat.php?id=<?= $u['application_id'] ?>" class="btn">Voir la fiche de recrutement complète</a>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <aside class="sidebar">
            <!-- Bloc Délai de Repostulation -->
            <div class="card" style="border-left: 4px solid var(--accent-primary);">
                <h4 style="margin-top:0;">Délai de Repostulation</h4>
                <?php if (!empty($u['can_reapply_after'])): 
                    $reapply_dt = new DateTime($u['can_reapply_after']);
                    $now_dt = new DateTime();
                    $is_blocked = ($reapply_dt > $now_dt);
                    $is_permanent = ($reapply_dt->diff($now_dt)->y >= 50);
                ?>
                    <?php if ($is_permanent): ?>
                        <p style="color: var(--accent-red); font-weight: bold; margin: 5px 0;">🚫 Interdiction définitive de repostuler</p>
                    <?php elseif ($is_blocked): ?>
                        <p style="color: var(--accent-tertiary); font-weight: bold; margin: 5px 0;">
                            ⏳ Bloqué jusqu'au <?= $reapply_dt->format('d/m/Y H:i') ?>
                            <br><small style="font-weight: normal; color: var(--text-muted);">(Reste ~<?= $now_dt->diff($reapply_dt)->days ?> jours)</small>
                        </p>
                    <?php else: ?>
                        <p style="color: var(--accent-success); margin: 5px 0;">✅ Délai expiré (peut repostuler)</p>
                    <?php endif; ?>
                <?php else: ?>
                    <p style="color: var(--text-muted); margin: 5px 0;">Aucun délai de blocage actif.</p>
                <?php endif; ?>

                <form method="post" style="margin-top: 12px;">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                    <input type="hidden" name="set_reapply_delay" value="1">
                    <label style="font-size: 0.8rem; color: var(--text-muted); display: block; margin-bottom: 4px;">Modifier le délai :</label>
                    <div style="display: flex; gap: 6px; align-items: center; margin-bottom: 8px; flex-wrap: wrap;">
                        <input type="number" name="reapply_value" id="sidebar_reapply_value" min="0" max="999" value="1" class="card" style="width: 55px; padding: 6px; margin: 0;">
                        <select name="reapply_unit" id="sidebar_reapply_unit" class="card" style="width: auto; padding: 6px; margin: 0; font-size: 0.85rem;">
                            <option value="days">Jours</option>
                            <option value="months" selected>Mois</option>
                            <option value="years">Ans</option>
                        </select>
                        <button type="submit" class="btn btn-sm" style="margin-left: auto;">Définir</button>
                    </div>
                    <div style="display: flex; justify-content: space-between; align-items: center; gap: 8px;">
                        <label style="font-size: 0.8rem; display: flex; align-items: center; gap: 4px; cursor: pointer;">
                            <input type="checkbox" name="reapply_permanent" value="1" onchange="const d = this.checked; document.getElementById('sidebar_reapply_value').disabled = d; document.getElementById('sidebar_reapply_unit').disabled = d;"> Définitif
                        </label>
                        <?php if (!empty($u['can_reapply_after'])): ?>
                            <button type="submit" name="reapply_value" value="0" class="btn btn-sm" style="background: transparent; color: var(--accent-success); border-color: var(--accent-success); padding: 2px 6px; font-size: 0.75rem;">Révoquer</button>
                        <?php endif; ?>
                    </div>
                </form>
            </div>

            <div class="card">
                <h4>Gestion du Joueur</h4>
                
                <?php if (in_array($u['status'], ['essai', 'essai_adhesion'])): ?>
                <p class="text-secondary" style="font-size:0.85rem;">Période d'essai : débutée le <?= date('d/m/Y', strtotime($u['trial_start_date'])) ?></p>
                <?php if (!empty($_SESSION['user']['is_bureau']) || !empty($_SESSION['user']['can_validate_membership'])): ?>
                <form method="post" style="margin-bottom: 10px;" onsubmit="return confirm('Valider l\'adhésion définitive jusqu\'au 31/12 ?');">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                    <input type="hidden" name="validate_membership" value="1">
                    <button type="submit" class="btn btn-primary" style="width:100%;justify-content:center;background-color:var(--accent-success);">🏆 Valider Adhésion (31/12)</button>
                </form>
                <?php endif; ?>
                <form method="post" onsubmit="return confirm('Confirmer la prolongation de la période d\'essai ?');" style="margin-bottom: 12px;">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                    <input type="hidden" name="extend_trial" value="1">
                    <label style="font-size: 0.8rem; color: var(--text-muted); display: block; margin-bottom: 4px;">Prolonger la période d'essai :</label>
                    <div style="display: flex; gap: 6px; align-items: center;">
                        <input type="number" name="trial_extend_value" value="7" min="1" max="365" class="card" style="width: 55px; padding: 6px; margin: 0;" placeholder="7" required>
                        <select name="trial_extend_unit" class="card" style="padding: 6px; margin: 0; width: auto; font-size: 0.85rem;">
                            <option value="days" selected>Jours</option>
                            <option value="months">Mois</option>
                        </select>
                        <button type="submit" class="btn" style="flex: 1; justify-content: center; background-color: var(--accent-tertiary); color: white; padding: 6px 10px; font-size: 0.85rem; white-space: nowrap;">+ Prolonger</button>
                    </div>
                </form>
                <hr style="margin: 15px 0;">
                <?php endif; ?>

                <form method="post">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                    <input type="hidden" name="change_status" value="1">
                    <div class="form-group">
                        <label>Forcer un statut :</label>
                        <div style="display: flex; gap: 10px; margin-bottom: 10px;">
                            <select name="new_status" id="new_status_select" style="margin:0; flex: 1;" onchange="toggleReasonField()">
                                <option value="essai" <?= $u['status'] == 'essai' ? 'selected' : '' ?>>Essai</option>
                                <option value="joueur" <?= $u['status'] == 'joueur' ? 'selected' : '' ?>>Joueur</option>
                                <option value="left" <?= $u['status'] == 'left' ? 'selected' : '' ?>>Ayant Quitté</option>
                                <option value="archive" <?= $u['status'] == 'archive' ? 'selected' : '' ?>>Archivé</option>
                                <option value="exclu" <?= $u['status'] == 'exclu' ? 'selected' : '' ?>>Exclu</option>
                                <option value="ban" <?= $u['status'] == 'ban' ? 'selected' : '' ?>>Banni (Ban)</option>
                            </select>
                            <button type="submit" class="btn btn-primary">Go</button>
                        </div>
                        <div id="reason_container" style="display: none;">
                            <label style="font-size: 0.85rem; color: var(--text-muted); display: block; margin-bottom: 5px;">Motif (obligatoire) :</label>
                            <input type="text" name="status_reason" id="status_reason" style="margin: 0 0 10px 0; width: 100%; box-sizing: border-box; padding: 10px;" placeholder="Indiquez la raison...">
                            
                            <label style="font-size: 0.85rem; color: var(--text-muted); display: block; margin-bottom: 5px;">Délai avant repostulation :</label>
                            <div style="display: flex; gap: 8px; align-items: center; margin-bottom: 8px; flex-wrap: wrap;">
                                <input type="number" name="reapply_value" id="status_reapply_value" min="0" max="999" value="3" class="card" style="width: 55px; padding: 6px; margin: 0;">
                                <select name="reapply_unit" id="status_reapply_unit" class="card" style="width: auto; padding: 6px; margin: 0; font-size: 0.85rem;">
                                    <option value="days">Jours</option>
                                    <option value="months" selected>Mois</option>
                                    <option value="years">Ans</option>
                                </select>
                            </div>
                            <label style="display: flex; align-items: center; gap: 6px; font-size: 0.85rem; cursor: pointer;">
                                <input type="checkbox" name="reapply_permanent" value="1" onchange="const d = this.checked; document.getElementById('status_reapply_value').disabled = d; document.getElementById('status_reapply_unit').disabled = d;"> Bannissement définitif
                            </label>
                        </div>
                    </div>
                </form>
            </div>
            
            <a href="/fta" class="btn" style="width:100%; justify-content:center; background-color:var(--accent-red); color: white;">Ajouter une sanction (FTA)</a>
        </aside>
    </div>
</div>

<!-- Modal Lightbox -->
<div class="lightbox-modal" id="lightbox-modal" onclick="this.style.display='none'">
    <img id="lightbox-img" src="" alt="Aperçu">
</div>

<script>
    const CSRF_TOKEN = "<?= htmlspecialchars($csrf_token) ?>";
    let uploadedAttachments = [];

    function toggleReasonField() {
        var status = document.getElementById('new_status_select').value;
        var reasonContainer = document.getElementById('reason_container');
        var reasonInput = document.getElementById('status_reason');
        if (status === 'exclu' || status === 'ban' || status === 'left') {
            reasonContainer.style.display = 'block';
            reasonInput.required = (status === 'exclu' || status === 'ban');
        } else {
            reasonContainer.style.display = 'none';
            reasonInput.required = false;
        }
    }

    function toggleNoteDelayBlock(noteType) {
        const block = document.getElementById('note-delay-block');
        if (!block) return;
        if (noteType === 'incident' || noteType === 'departure') {
            block.style.display = 'block';
        } else {
            block.style.display = 'none';
            document.getElementById('note_apply_reapply_delay').checked = false;
            document.getElementById('note_delay_fields').style.display = 'none';
        }
    }

    function openLightbox(src) {
        const modal = document.getElementById('lightbox-modal');
        const img = document.getElementById('lightbox-img');
        img.src = src;
        modal.style.display = 'flex';
    }

    // Gestion Drag & Drop et Coller d'images
    document.addEventListener('DOMContentLoaded', function() {
        if (document.getElementById('new_status_select')) {
            toggleReasonField();
        }

        const dropzone = document.getElementById('note-dropzone');
        const fileInput = document.getElementById('note-file-input');
        const textarea = document.getElementById('note_textarea');
        const previewsContainer = document.getElementById('note-upload-previews');
        const attachmentsInput = document.getElementById('note_attachments_input');

        function updateAttachmentsInput() {
            attachmentsInput.value = JSON.stringify(uploadedAttachments);
        }

        function renderPreviews() {
            previewsContainer.innerHTML = '';
            uploadedAttachments.forEach((url, index) => {
                const item = document.createElement('div');
                item.className = 'upload-preview-item';
                item.innerHTML = `
                    <img src="${url}" alt="Capture">
                    <button type="button" class="remove-btn" title="Supprimer" onclick="removeAttachment(${index})">×</button>
                `;
                previewsContainer.appendChild(item);
            });
            updateAttachmentsInput();
        }

        window.removeAttachment = function(index) {
            uploadedAttachments.splice(index, 1);
            renderPreviews();
        };

        async function uploadFile(file) {
            if (!file || !file.type.startsWith('image/')) return;
            const formData = new FormData();
            formData.append('file', file);
            formData.append('csrf_token', CSRF_TOKEN);

            const tempId = 'up_' + Date.now();
            const placeholder = document.createElement('div');
            placeholder.className = 'upload-preview-item';
            placeholder.id = tempId;
            placeholder.style.display = 'flex';
            placeholder.style.alignItems = 'center';
            placeholder.style.justifyContent = 'center';
            placeholder.style.background = 'rgba(255,255,255,0.05)';
            placeholder.innerHTML = '<span style="font-size:0.75rem; color: var(--accent-tertiary);">Upload...</span>';
            previewsContainer.appendChild(placeholder);

            try {
                const resp = await fetch('/api_upload_note_attachment.php', {
                    method: 'POST',
                    headers: { 'X-CSRF-Token': CSRF_TOKEN },
                    body: formData
                });
                const data = await resp.json();
                placeholder.remove();
                if (data.success) {
                    uploadedAttachments.push(data.url);
                    renderPreviews();
                } else {
                    alert('Erreur upload : ' + (data.error || 'Erreur inconnue'));
                }
            } catch (err) {
                placeholder.remove();
                alert('Erreur réseau lors du téléversement de la capture.');
            }
        }

        if (dropzone) {
            dropzone.addEventListener('click', () => fileInput.click());
            dropzone.addEventListener('dragover', (e) => { e.preventDefault(); dropzone.classList.add('dragover'); });
            dropzone.addEventListener('dragleave', () => dropzone.classList.remove('dragover'));
            dropzone.addEventListener('drop', (e) => {
                e.preventDefault();
                dropzone.classList.remove('dragover');
                if (e.dataTransfer.files && e.dataTransfer.files.length > 0) {
                    for (const file of e.dataTransfer.files) {
                        uploadFile(file);
                    }
                }
            });
        }

        if (fileInput) {
            fileInput.addEventListener('change', () => {
                if (fileInput.files && fileInput.files.length > 0) {
                    for (const file of fileInput.files) {
                        uploadFile(file);
                    }
                    fileInput.value = '';
                }
            });
        }

        // Écoute du Presse-papier (Ctrl+V) sur toute la page ou sur le textarea
        window.addEventListener('paste', (e) => {
            const items = (e.clipboardData || e.originalEvent.clipboardData).items;
            let imagePasted = false;
            for (const item of items) {
                if (item.kind === 'file' && item.type.startsWith('image/')) {
                    const blob = item.getAsFile();
                    uploadFile(blob);
                    imagePasted = true;
                }
            }
            if (imagePasted) {
                // Activer l'onglet notes si on n'y est pas
                const notesTab = document.getElementById('notes');
                if (notesTab && notesTab.style.display !== 'block') {
                    const tabBtn = document.querySelectorAll('.tab-link')[1];
                    if (tabBtn) tabBtn.click();
                }
            }
        });
    });

    function openTab(evt, tabName) {
        var i, tabcontent, tablinks; 
        tabcontent = document.getElementsByClassName("tab-content"); 
        for (i = 0; i < tabcontent.length; i++) { tabcontent[i].style.display = "none"; }
        tablinks = document.getElementsByClassName("tab-link"); 
        for (i = 0; i < tablinks.length; i++) { tablinks[i].className = tablinks[i].className.replace(" active", ""); }
        document.getElementById(tabName).style.display = "block"; 
        if (evt && evt.currentTarget) evt.currentTarget.className += " active";
    }

    // Gestion de la recherche et des filtres de l'Historique
    function filterHistory() {
        const searchInput = document.getElementById('history-search');
        const typeSelect = document.getElementById('history-filter-type');
        const roleSelect = document.getElementById('history-filter-role');
        const counterText = document.getElementById('history-count-text');
        const resetBtn = document.getElementById('history-reset-btn');
        const noResult = document.getElementById('history-no-result');

        if (!searchInput || !typeSelect || !roleSelect) return;

        const query = (searchInput.value || '').trim().toLowerCase();
        const selectedType = typeSelect.value;
        const selectedRole = roleSelect.value;

        const isFiltered = query !== '' || selectedType !== '' || selectedRole !== '';
        if (resetBtn) resetBtn.style.display = isFiltered ? 'inline-block' : 'none';

        const items = document.querySelectorAll('#history-list .history-item');
        let visibleCount = 0;

        items.forEach(item => {
            const itemType = item.getAttribute('data-type') || '';
            const itemRole = item.getAttribute('data-role') || '';
            const itemSearch = item.getAttribute('data-search') || '';

            const matchesSearch = !query || itemSearch.includes(query);
            const matchesType = !selectedType || itemType === selectedType;
            const matchesRole = !selectedRole || itemRole === selectedRole;

            if (matchesSearch && matchesType && matchesRole) {
                item.style.display = 'flex';
                visibleCount++;
            } else {
                item.style.display = 'none';
            }
        });

        if (counterText) {
            if (isFiltered) {
                counterText.textContent = `${visibleCount} sur ${items.length} événement(s) affiché(s)`;
            } else {
                counterText.textContent = `${items.length} événement(s) au total`;
            }
        }

        if (noResult) {
            noResult.style.display = (items.length > 0 && visibleCount === 0) ? 'block' : 'none';
        }
    }

    function resetHistoryFilters() {
        const searchInput = document.getElementById('history-search');
        const typeSelect = document.getElementById('history-filter-type');
        const roleSelect = document.getElementById('history-filter-role');

        if (searchInput) searchInput.value = '';
        if (typeSelect) typeSelect.value = '';
        if (roleSelect) roleSelect.value = '';

        filterHistory();
    }

    document.addEventListener('DOMContentLoaded', () => {
        const searchInput = document.getElementById('history-search');
        const typeSelect = document.getElementById('history-filter-type');
        const roleSelect = document.getElementById('history-filter-role');

        if (searchInput) searchInput.addEventListener('input', filterHistory);
        if (typeSelect) typeSelect.addEventListener('change', filterHistory);
        if (roleSelect) roleSelect.addEventListener('change', filterHistory);
    });
</script>
</body>
</html>
