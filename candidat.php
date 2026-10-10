<?php
require_once __DIR__.'/config.php';
require_once __DIR__.'/bdd.php';
require_once __DIR__.'/api_discord.php';
if (empty($_SESSION['user']) || empty($_SESSION['user']['is_recruiter'])) { http_response_code(403); exit('Accès refusé'); }

$pdo = db();
$id = (int)($_GET['id'] ?? 0);
$st = $pdo->prepare('SELECT a.*, u.username, u.discord_id, u.display_name, u.status as user_status, u.last_activity, u.window_state, u.last_connection, u.last_session_connection, u.can_reapply_after, u.trial_start_date, u.departure_reason, u.departure_date FROM applications a JOIN users u ON u.id=a.user_id WHERE a.id=?');
$st->execute([$id]);
$app = $st->fetch(PDO::FETCH_ASSOC);
if (!$app) { http_response_code(404); exit('Not found'); }

// POST (Identique, compressé)
if ($_SERVER['REQUEST_METHOD']==='POST') {
    if (!isset($_POST['csrf_token']) || empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) { http_response_code(403); exit; }
    unset($_SESSION['csrf_token']); $now = date('Y-m-d H:i:s'); $recruiterDisplayName = $_SESSION['user']['display_name'] ?? $_SESSION['user']['username']; $candidature_url = ($GLOBALS['APP_BASE_URL'] ?? '') . '/ma_candidature';
    
    if (isset($_POST['send_message']) && !empty(trim($_POST['message_text']))) {
        $msg = trim($_POST['message_text']);
        $pdo->prepare("INSERT INTO application_messages (application_id, user_id, message, is_staff) VALUES (?, ?, ?, 1)")->execute([$id, $_SESSION['user']['id'], $msg]);
        $pdo->prepare("UPDATE applications SET updated_at=? WHERE id=?")->execute([$now, $id]);
        if (isset($_POST['send_notification'])) { 
            $candidateIsOnline = false; if ($app['last_activity']) { $diff = time() - strtotime($app['last_activity']); if ($diff < 20 && $app['window_state'] === 'visible') { $candidateIsOnline = true; } }
            if (!$candidateIsOnline) { try { discord_send_dm($app['discord_id'], "💬 Nouveau message d'un recruteur sur votre candidature.\n[Consultez-le ici]({$candidature_url})", $DISCORD_DM_IMAGE_URL); } catch (Throwable $e) {} }
        }
        header('Location: /candidat?id='.$id); exit;
    }
    // ... Autres POST conservés (status, delete, etc.) ...
    if (isset($_POST['add_private_comment']) && !empty(trim($_POST['private_comment_text']))) {
        $private_comments_decoded = json_decode($app['private_comments'] ?: '[]', true);
        $private_comments_decoded[] = ['author' => $recruiterDisplayName, 'text' => trim($_POST['private_comment_text']), 'date' => $now];
        $pdo->prepare("UPDATE applications SET private_comments=?, updated_at=? WHERE id=?")->execute([json_encode($private_comments_decoded, JSON_UNESCAPED_UNICODE), $now, $id]);
        header('Location: /candidat?id='.$id); exit;
    }
    if (isset($_POST['add_recruiter_review'])) {
        $current_reviews = json_decode($app['recruiter_reviews'] ?: '[]', true);
        foreach ($current_reviews as &$review) { if ($review['recruiter_id'] === $_SESSION['user']['discord_id'] && !isset($review['status'])) { $review['status'] = 'archived'; } }
        unset($review);
        $current_reviews[] = [ 'recruiter_id' => $_SESSION['user']['discord_id'], 'recruiter_name' => $recruiterDisplayName, 'avis' => $_POST['avis_recruteur'], 'comment' => trim($_POST['comment_recruteur']), 'date' => $now ];
        $pdo->prepare("UPDATE applications SET recruiter_reviews=?, updated_at=? WHERE id=?")->execute([json_encode($current_reviews, JSON_UNESCAPED_UNICODE), $now, $id]);
        header('Location: /candidat?id='.$id); exit;
    }
    if (isset($_POST['set_status'])) {
        $finalized = in_array(($app['status'] ?? ''), ['refuse','accepte_integration','accepted'], true);
        $can_change_final = !$finalized || !empty($_SESSION['user']['can_override_decision']);
        if (!$can_change_final) { header('Location: /candidat?id='.$id); exit; }
        $new_status = $_POST['status_value'];
        $status_history = json_decode($app['status_history'] ?: '[]', true);
        foreach ($status_history as &$item) { if (isset($item['history_status']) && $item['history_status'] === 'active') { $item['history_status'] = 'overridden'; } }
        if ($new_status === 'refuse') {
            $reason = '';
            $delay_applied = false;

            if ($_POST['rejection_reason'] === 'autre') {
                $reason = trim($_POST['rejection_reason_custom'] ?? 'Autre (non précisé)');
            } else {
                $reason_id = (int)$_POST['rejection_reason'];
                $st_reason = $pdo->prepare("SELECT * FROM rejection_reasons WHERE id = ?");
                $st_reason->execute([$reason_id]);
                $reason_data = $st_reason->fetch(PDO::FETCH_ASSOC);
                if ($reason_data) {
                    $reason = $reason_data['reason_text'];
                    if (!empty($reason_data['roles_added'])) {
                        $roles = json_decode($reason_data['roles_added'], true) ?: [];
                        foreach ($roles as $role) { try { discord_add_role($app['discord_id'], trim($role)); } catch (Exception $e) {} }
                    }
                    if (!empty($reason_data['roles_removed'])) {
                        $roles = json_decode($reason_data['roles_removed'], true) ?: [];
                        foreach ($roles as $role) { try { discord_remove_role($app['discord_id'], trim($role)); } catch (Exception $e) {} }
                    }
                }
            }

            // Gestion du délai de repostulation personnalisé ou automatique (jours, mois, années)
            $is_permanent_refusal = !empty($_POST['rejection_reapply_permanent']);
            $delay_applied = false;

            if ($is_permanent_refusal) {
                $reapply_time = date('Y-m-d H:i:s', strtotime("+99 years"));
                $pdo->prepare("UPDATE users SET can_reapply_after = ? WHERE id = ?")->execute([$reapply_time, $app['user_id']]);
                log_user_action($app['user_id'], 'reapply_delay', "Interdiction définitive de repostuler appliquée suite au refus.", $_SESSION['user']['id']);
                $delay_applied = true;
            } elseif (isset($_POST['rejection_reapply_value']) || isset($_POST['rejection_reapply_months'])) {
                $reapply_val = isset($_POST['rejection_reapply_value']) ? (int)$_POST['rejection_reapply_value'] : (int)$_POST['rejection_reapply_months'];
                $reapply_unit = in_array($_POST['rejection_reapply_unit'] ?? '', ['days', 'months', 'years']) ? $_POST['rejection_reapply_unit'] : 'months';
                
                if ($reapply_val > 0) {
                    $reapply_time = date('Y-m-d H:i:s', strtotime("+$reapply_val $reapply_unit"));
                    $pdo->prepare("UPDATE users SET can_reapply_after = ? WHERE id = ?")->execute([$reapply_time, $app['user_id']]);
                    $unit_fr = ['days' => 'jour(s)', 'months' => 'mois', 'years' => 'an(s)'][$reapply_unit] ?? $reapply_unit;
                    log_user_action($app['user_id'], 'reapply_delay', "Délai avant repostulation fixé à $reapply_val $unit_fr suite au refus.", $_SESSION['user']['id']);
                    $delay_applied = true;
                } else {
                    $pdo->prepare("UPDATE users SET can_reapply_after = NULL WHERE id = ?")->execute([$app['user_id']]);
                    $delay_applied = true;
                }
            }

            // Fallback si non fourni via formulaire : appliquer le délai du motif en base
            if (!$delay_applied && !empty($reason_data)) {
                if ($reason_data['sanction_type'] === 'delay' || $reason_data['sanction_type'] === 'temp_ban') {
                    $val = (int)$reason_data['delay_value'];
                    $unit = (!empty($reason_data['delay_unit']) && in_array($reason_data['delay_unit'], ['days', 'months', 'years'])) ? $reason_data['delay_unit'] : 'months';
                    if ($val > 0) {
                        $reapply_time = date('Y-m-d H:i:s', strtotime("+$val $unit"));
                        $pdo->prepare("UPDATE users SET can_reapply_after = ? WHERE id = ?")->execute([$reapply_time, $app['user_id']]);
                    }
                } elseif ($reason_data['sanction_type'] === 'def_ban') {
                    $reapply_time = date('Y-m-d H:i:s', strtotime("+99 years"));
                    $pdo->prepare("UPDATE users SET can_reapply_after = ? WHERE id = ?")->execute([$reapply_time, $app['user_id']]);
                }
            }

            if (empty($reason)) $reason = "Refusé";
            
            $status_history[] = ['status' => 'refuse', 'reason' => $reason, 'author' => $recruiterDisplayName, 'date' => $now, 'history_status' => 'active'];
            $pdo->prepare("UPDATE applications SET status='refuse', rejection_reason=?, decision_by=?, updated_at=?, status_history=? WHERE id=?")->execute([$reason, $recruiterDisplayName, $now, json_encode($status_history, JSON_UNESCAPED_UNICODE), $id]);
            $pdo->prepare("UPDATE users SET status='refuse' WHERE id=?")->execute([$app['user_id']]);
            log_user_action($app['user_id'], 'status_change', "Candidature refusée. Motif : $reason", $_SESSION['user']['id']);
            if (isset($_POST['send_notification'])) { try { discord_send_dm($app['discord_id'], "ℹ️ Candidature refusée.\nMotif : {$reason}\n[Consultez les détails ici]({$candidature_url})", $DISCORD_DM_IMAGE_URL); } catch (Throwable $e) {} }
        } elseif ($new_status === 'accepte_integration') {
            $token = empty($app['integration_token']) ? bin2hex(random_bytes(16)) : $app['integration_token'];
            $status_history[] = ['status' => 'accepte_integration', 'reason' => null, 'author' => $recruiterDisplayName, 'date' => $now, 'history_status' => 'active'];
            $pdo->prepare("UPDATE applications SET status='accepte_integration', integration_token=?, updated_at=?, status_history=? WHERE id=?")->execute([$token, $now, json_encode($status_history, JSON_UNESCAPED_UNICODE), $id]);
            $pdo->prepare("UPDATE users SET status='accepte_integration', trial_start_date = IF(trial_start_date IS NULL, ?, trial_start_date) WHERE id = ?")->execute([$now, $app['user_id']]);
            log_user_action($app['user_id'], 'status_change', "Candidature acceptée (Passage en intégration)", $_SESSION['user']['id']);
            if (isset($_POST['send_notification'])) { try { discord_send_dm($app['discord_id'], "✅ Candidature acceptée ! [Démarrez votre intégration ici](" . ($GLOBALS['APP_BASE_URL'] ?? '') . "/integration?token={$token})", $DISCORD_DM_IMAGE_URL); } catch (Throwable $e) {} }
        }
        header('Location: /candidat?id='.$id); exit;
    }
    if (isset($_POST['delete_application'])) {
        if (empty($_SESSION['user']['can_delete_application'])) { http_response_code(403); exit; }
        if ((int)$_POST['application_id'] === $id) {
            $pdo->prepare("UPDATE users SET status = 'non_member', trial_start_date = NULL WHERE id = ?")->execute([$app['user_id']]);
            $pdo->prepare("DELETE FROM applications WHERE id = ?")->execute([$id]);
            header('Location: /espace_recruteur'); exit;
        }
    }
    if (isset($_POST['extend_reapply'])) {
        $extra_val = (int)($_POST['extra_value'] ?? ($_POST['extra_months'] ?? 1));
        $extra_unit = in_array($_POST['extra_unit'] ?? '', ['days', 'months', 'years']) ? $_POST['extra_unit'] : 'months';
        if ($extra_val > 0) {
            $current_reapply = !empty($app['can_reapply_after']) ? strtotime($app['can_reapply_after']) : time();
            if ($current_reapply < time()) $current_reapply = time();
            $new_reapply = date('Y-m-d H:i:s', strtotime("+$extra_val $extra_unit", $current_reapply));
            $pdo->prepare("UPDATE users SET can_reapply_after = ? WHERE id = ?")->execute([$new_reapply, $app['user_id']]);
            $unit_fr = ['days' => 'jour(s)', 'months' => 'mois', 'years' => 'an(s)'][$extra_unit] ?? $extra_unit;
            log_user_action($app['user_id'], 'reapply_delay', "Délai avant repostulation prolongé de $extra_val $unit_fr.", $_SESSION['user']['id']);
        }
        header('Location: /candidat?id='.$id); exit;
    }
    header('Location: /candidat?id='.$id); exit;
}
$_SESSION['csrf_token'] = bin2hex(random_bytes(32)); $csrf_token = $_SESSION['csrf_token'];

$rejection_reasons = [];
try { $rejection_reasons = $pdo->query("SELECT * FROM rejection_reasons WHERE is_active = 1 ORDER BY reason_text ASC")->fetchAll(PDO::FETCH_ASSOC); } catch (Exception $e) {}

$recruiter_reviews = json_decode($app['recruiter_reviews'] ?: '[]', true);
$private_comments = json_decode($app['private_comments'] ?: '[]', true);
$status_history = json_decode($app['status_history'] ?: '[]', true);
$answers = json_decode($app['answers_json'] ?: '{}', true);
$stmtMsgs = $pdo->prepare("SELECT m.*, u.display_name, u.username FROM application_messages m LEFT JOIN users u ON m.user_id = u.id WHERE m.application_id = ? ORDER BY m.created_at ASC");
$stmtMsgs->execute([$app['id']]);
$messages = $stmtMsgs->fetchAll(PDO::FETCH_ASSOC);
// Récupération des votes Discord
$discord_votes = ['pour' => 0, 'neutre' => 0, 'contre' => 0];
$votes_history_items = [];
try {
    // Totaux pour les compteurs
    $stVotes = $pdo->prepare("SELECT vote_type, COUNT(*) as count FROM application_votes WHERE application_id = ? GROUP BY vote_type");
    $stVotes->execute([$app['id']]);
    foreach ($stVotes->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $discord_votes[$row['vote_type']] = $row['count'];
    }

    // Détails pour l'historique (avec liaison au compte site)
    $stVotesDetail = $pdo->prepare("
        SELECT v.*, u.display_name 
        FROM application_votes v 
        LEFT JOIN users u ON v.discord_user_id = u.discord_id 
        WHERE v.application_id = ?
    ");
    $stVotesDetail->execute([$app['id']]);
    foreach ($stVotesDetail->fetchAll(PDO::FETCH_ASSOC) as $v) {
        $votes_history_items[] = [
            'type' => 'discord_vote',
            'author' => $v['display_name'] ?: 'Discord User',
            'vote_type' => $v['vote_type'],
            'date' => $v['created_at']
        ];
    }
} catch (Exception $e) {}

// Récupération des notes du joueur (incidents, départs, PE, etc.)
$player_notes = [];
try {
    $stPN = $pdo->prepare("SELECT n.*, u.display_name as recruiter_name, u.recruiter_type FROM user_notes n LEFT JOIN users u ON n.recruiter_id = u.id WHERE n.user_id = ? ORDER BY n.created_at DESC");
    $stPN->execute([$app['user_id']]);
    $player_notes = $stPN->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

// Récupération des sanctions FTA éventuelles
$player_sanctions = [];
try {
    $stFS = $pdo->prepare("SELECT s.*, a.display_name as author_name FROM fta_sanctions s LEFT JOIN users a ON s.author_id = a.id WHERE s.user_id = ? ORDER BY s.start_date DESC");
    $stFS->execute([$app['user_id']]);
    $player_sanctions = $stFS->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

$has_player_background = (!empty($player_notes) || !empty($player_sanctions) || !empty($app['departure_reason']) || in_array($app['user_status'], ['left', 'exclu', 'ban', 'ancien_joueur']));

$history = []; 
foreach ($recruiter_reviews as $r) { $history[] = array_merge($r, ['type' => 'review']); } 
foreach ($status_history as $s) { $history[] = array_merge($s, ['type' => 'status_change']); } 
foreach ($votes_history_items as $vh) { $history[] = $vh; }

usort($history, function($a, $b) { return strtotime($b['date']) - strtotime($a['date']); });

$active_reviews = array_filter($recruiter_reviews, function($r) { return !isset($r['status']) || $r['status'] !== 'archived'; });
$avis_scores = array_reduce($active_reviews, function ($carry, $review) { if ($review['avis'] === 'positif') $carry[$review['recruiter_id']] = 1; elseif ($review['avis'] === 'negatif') $carry[$review['recruiter_id']] = -1; else $carry[$review['recruiter_id']] = 0; return $carry; }, []);
$avis_score = array_sum($avis_scores);
if (empty($active_reviews)) { $global_avis_text = "Aucun avis"; $global_avis_class = "avis-neutre"; } elseif ($avis_score > 0) { $global_avis_text = "Majoritairement Positif"; $global_avis_class = "avis-positif"; } elseif ($avis_score < 0) { $global_avis_text = "Majoritairement Négatif"; $global_avis_class = "avis-negatif"; } else { $global_avis_text = "Neutre / Partagé"; $global_avis_class = "avis-neutre"; }
$current_recruiter_review = null; foreach (array_reverse($recruiter_reviews) as $review) { if ($review['recruiter_id'] === $_SESSION['user']['discord_id'] && (!isset($review['status']) || $review['status'] !== 'archived')) { $current_recruiter_review = $review; break; } }

$status_labels = [ 'attente' => ['text' => 'En attente', 'class' => 'badge-neutre'], 'accepte_integration' => ['text' => 'Accepté', 'class' => 'badge-positif'], 'refuse' => ['text' => 'Refusé', 'class' => 'badge-negatif'], 'accepte_integration_long' => ['text' => 'Attente Intég.', 'class' => 'badge-positif'] ];
$current_status = $app['status'] ?? 'attente'; $status_info = $status_labels[$current_status] ?? ['text' => $current_status, 'class' => 'badge-neutre'];
function getAge($date) {
    if (empty($date)) return '';
    try {
        if (strpos($date, '/') !== false) {
            $parts = explode('/', $date);
            if (count($parts) == 3 && strlen($parts[0]) == 2) {
                $date = $parts[2] . '-' . $parts[1] . '-' . $parts[0];
            }
        }
        $birthDate = new DateTime($date);
        $today = new DateTime();
        return $birthDate->diff($today)->y . ' ans';
    } catch (Exception $e) { return ''; }
}

function display_answers($answers, $app_type = 'player') { 
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
    
    $labels_o = [ 
        'discord_pseudo' => ['label' => 'Discord', 'icon' => '🎮'], 
        'activity' => ['label' => 'Pôle', 'icon' => '🎯'], 
        'birthdate' => ['label' => 'Naissance', 'icon' => '🎂'], 
        'email' => ['label' => 'Email', 'icon' => '📧'], 
        'char_firstname' => ['label' => 'Prénom RP', 'icon' => '👤'],
        'char_lastname' => ['label' => 'Nom RP', 'icon' => '👤'],
        'has_mic' => ['label' => 'Micro/Casque', 'icon' => '🎙️'], 
        'has_gta' => ['label' => 'GTA V', 'icon' => '🚗'],
        'comfortable_it' => ['label' => 'Informatique', 'icon' => '💻'],
        'already_member' => ['label' => 'Déjà membre', 'icon' => '🔄'],
        'how_know' => ['label' => 'Source', 'icon' => '🔍'],
        'experience_irl' => ['label' => 'Exp. IRL', 'icon' => '💼', 'full' => true],
        'experience_rp' => ['label' => 'Exp. RP', 'icon' => '📜', 'full' => true],
        'experience_op' => ['label' => 'Exp. Opérateur', 'icon' => '🎧', 'full' => true],
        'motivations' => ['label' => 'Motivations', 'icon' => '💡', 'full' => true], 
    ];

    $labels_d = [
        'discord_pseudo'   => ['label' => 'Discord', 'icon' => '🎮'],
        'email'            => ['label' => 'Email', 'icon' => '📧'],
        'birthdate'        => ['label' => 'Naissance', 'icon' => '🎂'],
        'experience_years' => ['label' => 'Expérience', 'icon' => '⏳'],
        'weekly_hours'     => ['label' => 'Disponibilité', 'icon' => '⏱️'],
        'specialties'      => ['label' => 'Domaines & Spécialités', 'icon' => '⚡', 'full' => true],
        'frameworks'       => ['label' => 'Technologies & Frameworks', 'icon' => '🛠️', 'full' => true],
        'achievements'     => ['label' => 'Réalisations & Projets Phares', 'icon' => '🏆', 'full' => true],
        'motivations'      => ['label' => 'Motivations', 'icon' => '💡', 'full' => true],
    ];

    if ($app_type === true || $app_type === 'operator') {
        $config = $labels_o;
    } elseif ($app_type === 'developer') {
        $config = $labels_d;
    } else {
        $config = $labels_p;
    }

    $html = '<div class="dossier-grid" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 15px;">'; 
    
    foreach ($config as $key => $info) { 
        if (empty($answers[$key]) && !is_bool($answers[$key])) continue; 
        
        $val = $answers[$key]; 
        if (is_bool($val)) $val = $val ? 'Oui' : 'Non';
        if ($key == 'activity' && $val == 'gta_rp') $val = 'GTA V RP';

        if ($key == 'specialties' && is_array($val)) {
            $val = implode(', ', $val);
        }
        
        if ($key == 'birthdate') {
            $ageText = getAge($val);
            $valTime = strtotime(str_replace('/', '-', $val));
            if ($valTime) {
                $val = date('d/m/Y', $valTime) . ($ageText ? " ($ageText)" : "");
            }
        }

        // Cas spécial Pro
        if ($key == 'is_pro') {
            if (strtolower($val) == 'non' && empty($answers['service_pro_details'])) continue;
            if (!empty($answers['service_pro_details'])) {
                $val = "Oui : " . $answers['service_pro_details'];
            }
        }
        
        // Cas spécial Détails IRL / RP pour Opérateur
        if ($key == 'experience_irl' && !empty($answers['experience_irl_details'])) {
            $val .= "\nDétails : " . $answers['experience_irl_details'];
        }
        if ($key == 'experience_rp' && !empty($answers['experience_rp_details'])) {
            $val .= "\nDétails : " . $answers['experience_rp_details'];
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
<!DOCTYPE html><html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Candidat #<?=$app['id']?></title><link rel="icon" type="image/png" href="assets/logo.png"><link rel="stylesheet" href="assets/styles.css">
<style>
    /* CSS Lightbox */
    .lightbox-modal { display: none; position: fixed; z-index: 9999; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.88); align-items: center; justify-content: center; backdrop-filter: blur(4px); cursor: zoom-out; }
    .lightbox-modal img { max-width: 90%; max-height: 90%; border-radius: 8px; box-shadow: 0 10px 40px rgba(0,0,0,0.7); border: 1px solid rgba(255,255,255,0.1); }

    /* CSS TCHAT VUE RECRUTEUR */
    .chat-box { background: var(--bg-tertiary); border: 1px solid var(--border-color); border-radius: 8px; height: 500px; overflow-y: auto; padding: 15px; display: flex; flex-direction: column; gap: 15px; margin-bottom: 15px; }
    .message-wrapper { display: flex; width: 100%; }
    
    .msg-left { justify-content: flex-start; }  /* Candidat */
    .msg-right { justify-content: flex-end; }   /* Staff (Moi & Autres) */
    
    .message { max-width: 80%; padding: 10px 14px; border-radius: 12px; font-size: 0.95rem; line-height: 1.4; position: relative; word-wrap: break-word; }
    
    /* Candidat (L'autre) -> GRIS */
    .msg-bubble-other { background-color: var(--bg-secondary); border: 1px solid var(--border-color); border-bottom-left-radius: 2px; }
    
    /* Moi (Recruteur) -> BLEU */
    .msg-bubble-me { background-color: var(--accent-primary); color: white; border-bottom-right-radius: 2px; }
    .msg-bubble-me .msg-header { color: rgba(255,255,255,0.9); }

    /* Autre Recruteur (Collègue) -> VIOLET */
    .msg-bubble-staff-other { background-color: var(--accent-tertiary); color: white; border-bottom-right-radius: 2px; }
    .msg-bubble-staff-other .msg-header { color: rgba(255,255,255,0.9); }

    .msg-header { font-size: 0.75rem; margin-bottom: 4px; opacity: 0.9; display: flex; gap: 8px; align-items: center; justify-content: space-between; }
    .badge-staff { background: rgba(0,0,0,0.2); color: white; padding: 1px 5px; border-radius: 4px; font-size: 0.65rem; font-weight: bold; text-transform: uppercase; }

    /* Reste CSS */
    .main-grid { display: grid; grid-template-columns: 2fr 1fr; gap: 24px; align-items: flex-start; }
    .sidebar { position: sticky; top: 90px; display: flex; flex-direction: column; gap: 24px; }
    .main-content { display: flex; flex-direction: column; gap: 24px; }
    .status-dot { display: inline-block; width: 12px; height: 12px; border-radius: 50%; background-color: #ccc; margin-left: 10px; border: 2px solid white; box-shadow: 0 0 5px rgba(0,0,0,0.1); transition: background-color 0.3s ease; vertical-align: middle; }
    .status-green { background-color: #2ecc71; box-shadow: 0 0 8px #2ecc71; }
    .status-orange { background-color: #f39c12; }
    .status-red { background-color: #e74c3c; }
    .private-comment { background: rgba(142, 68, 173, 0.1); border-left: 3px solid #9b59b6; padding: 12px; border-radius: 4px; margin-bottom: 10px; }
    .tabs { display: flex; border-bottom: 1px solid var(--border-color); margin-bottom: 15px; }
    .tab-link { padding: 8px 16px; cursor: pointer; color: var(--text-secondary); border-bottom: 2px solid transparent; }
    .tab-link.active { color: var(--text-primary); border-color: var(--accent-primary); }
    .tab-content { display: none; }
    .tab-content.active { display: block; }
    .button-radios { display: flex; gap: 10px; }
    .button-radios input[type="radio"] { display: none; }
    .button-radios label { flex: 1; padding: 8px 12px; border: 1px solid var(--border-color); border-radius: 8px; cursor: pointer; text-align: center; }
    .button-radios input[value="positif"]:checked + label { background: var(--accent-success); border-color: var(--accent-success); color: white; }
    .button-radios input[value="negatif"]:checked + label { background: var(--accent-primary); border-color: var(--accent-primary); color: white; }
    .button-radios input[value="neutre"]:checked + label { background: var(--accent-secondary); border-color: var(--accent-secondary); color: white; }
    .modal-overlay { position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.7); display: none; align-items: center; justify-content: center; z-index: 2000; }
    .modal-content { background: var(--bg-secondary); padding: 30px; border-radius: 16px; width: 90%; max-width: 500px; }
    .btn.btn-danger { background-color: #58151c; border-color: #82202b; color: #fecdd3; }
    .avis-positif { color: #2ecc71; font-weight: bold; }
    .avis-negatif { color: #e74c3c; font-weight: bold; }
    .avis-neutre  { color: #f39c12; font-weight: bold; }
    .badge-status { display: inline-block; padding: 4px 10px; border-radius: 6px; font-size: 0.85rem; font-weight: bold; text-transform: uppercase; margin-left: 10px; vertical-align: middle; }
    .badge-positif { background: rgba(46, 204, 113, 0.15); color: #2ecc71; border: 1px solid rgba(46, 204, 113, 0.3); }
    .badge-negatif { background: rgba(231, 76, 60, 0.15); color: #e74c3c; border: 1px solid rgba(231, 76, 60, 0.3); }
    .badge-neutre { background: rgba(243, 156, 18, 0.15); color: #f39c12; border: 1px solid rgba(243, 156, 18, 0.3); }
    
    /* CSS DOSSIER CANDIDAT */
    .dossier-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 16px; margin-top: 20px; }
    .dossier-item { background: var(--bg-primary); border: 1px solid var(--border-color); border-radius: 12px; padding: 16px; display: flex; flex-direction: column; gap: 8px; }
    .dossier-item.full-width { grid-column: 1 / -1; }
    .dossier-label { font-size: 0.85rem; color: var(--text-muted); text-transform: uppercase; font-weight: bold; }
    .dossier-value { font-size: 1rem; color: var(--text-primary); line-height: 1.5; word-wrap: break-word; }
</style>
</head><body>
<?php include __DIR__.'/partiels_entete.php'; ?>
<div class="wrap page-container">
  <h2 class="mb-2" style="display:flex;align-items:center;flex-wrap:wrap;gap:10px;">Candidature de <?=htmlspecialchars($app['display_name'] ?? $app['username'])?> <span style="font-size: 1rem; color: var(--text-muted)">(ID: <?=$app['id']?>)</span> <span id="candidate-status-dot" class="status-dot status-red" title="Hors ligne" style="margin-left:0;"></span> <span class="badge-status <?= $status_info['class'] ?>"><?= $status_info['text'] ?></span></h2>
  
  <?php if (in_array($app['status'], ['essai', 'essai_adhesion', 'essai_termine', 'joueur'])): ?>
    <div class="alert alert-success" style="margin-bottom: 20px;">
        <strong>Information :</strong> Ce candidat a été recruté. Pour gérer sa période d'essai ou son adhésion, <a href="/profil_joueur?id=<?= $app['user_id'] ?>">consultez son Profil Joueur</a>.
    </div>
  <?php endif; ?>

  <?php if ($has_player_background): ?>
    <div class="alert alert-error" style="margin-bottom: 20px; background: rgba(231, 76, 60, 0.1); border-left: 4px solid var(--accent-red); color: var(--text-primary);">
        <h4 style="margin: 0 0 8px 0; color: var(--accent-red); display: flex; align-items: center; gap: 8px;">
            <span>⚠️</span> Dossier Repostulant — Antécédents Joueur Détectés
        </h4>
        <p style="margin: 0 0 8px 0; font-size: 0.95rem;">
            Ce candidat a des antécédents enregistrés sur la plateforme (Statut compte : <strong><?= strtoupper(htmlspecialchars($app['user_status'])) ?></strong>).
            <?php if (!empty($app['departure_reason'])): ?>
                <br><strong>Motif de départ déclaré :</strong> « <?= htmlspecialchars($app['departure_reason']) ?> » <?= $app['departure_date'] ? '(le ' . date('d/m/Y', strtotime($app['departure_date'])) . ')' : '' ?>
            <?php endif; ?>
        </p>
        <div style="display: flex; gap: 10px; flex-wrap: wrap; margin-top: 10px;">
            <a href="#tab-player-history" onclick="document.getElementById('tab-btn-player-history').click();" class="btn btn-sm" style="background: var(--accent-red); color: white;">
                🔍 Voir les <?= count($player_notes) ?> note(s) & captures d'incidents
            </a>
            <a href="/profil_joueur?id=<?= $app['user_id'] ?>" class="btn btn-sm" style="border-color: var(--border-color);">
                Consulter Profil Joueur Complet
            </a>
        </div>
    </div>
  <?php endif; ?>
  
  <div class="main-grid">
    <div class="main-content">
        <div class="card mb-3" style="display: flex; gap: 20px; flex-wrap: wrap;">
            <div style="flex: 1;">
                <h4 style="margin-top:0;">Dernière connexion globale</h4>
                <p class="text-secondary"><?= $app['last_connection'] ? date('d/m/Y H:i', strtotime($app['last_connection'])) : 'Inconnue' ?></p>
            </div>
            <div style="flex: 1;">
                <h4 style="margin-top:0;">Dernière connexion en Session (21h-00h)</h4>
                <p class="text-secondary"><?= $app['last_session_connection'] ? date('d/m/Y H:i', strtotime($app['last_session_connection'])) : 'Aucune' ?></p>
            </div>
            <?php if (!empty($app['can_reapply_after'])): 
                $c_reapply_dt = new DateTime($app['can_reapply_after']);
                $c_now_dt = new DateTime();
                $c_is_permanent = ($c_reapply_dt->diff($c_now_dt)->y >= 50);
            ?>
            <div style="flex: 1; border-left: 2px solid var(--accent-primary); padding-left: 15px;">
                <h4 style="margin-top:0; color: var(--accent-primary);">Blocage de repostulation</h4>
                <?php if ($c_is_permanent): ?>
                    <p style="color: var(--accent-red); font-weight: bold; margin: 5px 0;">🚫 Interdiction définitive</p>
                <?php else: ?>
                    <p class="text-secondary" style="margin: 5px 0;">Jusqu'au <?= $c_reapply_dt->format('d/m/Y H:i') ?></p>
                    <form method="post" style="display:flex; gap:5px; margin-top:5px; align-items: center; flex-wrap: wrap;">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                        <input type="hidden" name="extend_reapply" value="1">
                        <span style="font-size:0.8rem;">+</span>
                        <input type="number" name="extra_value" value="1" min="1" max="999" class="card" style="width: 50px; padding: 4px; margin:0;">
                        <select name="extra_unit" class="card" style="padding: 4px; margin:0; width:auto; font-size:0.85rem;">
                            <option value="days">Jours</option>
                            <option value="months" selected>Mois</option>
                            <option value="years">Ans</option>
                        </select>
                        <button type="submit" class="btn btn-sm">Prolonger</button>
                    </form>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>
        
        <div class="card"><h3>Dossier <?= ($app['application_type'] === 'developer') ? 'Développeur' : (($app['application_type'] === 'operator') ? 'Opérateur' : 'Joueur') ?></h3><?= display_answers($answers, $app['application_type']) ?></div>
        
        <?php if (!empty($answers['portfolio_links']) || !empty($answers['portfolio_images'])): ?>
            <div class="card" style="border-left: 4px solid #9b59b6; margin-top: 20px;">
                <h3 style="display:flex; align-items:center; gap: 10px; margin-top:0; color: #9b59b6;">
                    <span>📂</span> Portfolio & Réalisations du Développeur
                </h3>

                <?php if (!empty($answers['portfolio_links'])): ?>
                    <h4 style="margin: 15px 0 10px 0; font-size: 0.95rem; text-transform: uppercase; color: var(--text-muted); letter-spacing: 0.5px;">🔗 Liens Projets & Démos :</h4>
                    <div style="display: flex; gap: 10px; flex-wrap: wrap; margin-bottom: 20px;">
                        <?php foreach ($answers['portfolio_links'] as $pl): 
                            $type = htmlspecialchars($pl['type'] ?? 'Lien');
                            $title = htmlspecialchars($pl['title'] ?? $type);
                            $url = htmlspecialchars($pl['url'] ?? '#');
                            $icon = '🔗';
                            if (stripos($type, 'github') !== false) $icon = '🐙';
                            elseif (stripos($type, 'gitlab') !== false) $icon = '🦊';
                            elseif (stripos($type, 'vidéo') !== false || stripos($type, 'video') !== false || stripos($type, 'youtube') !== false) $icon = '🎬';
                            elseif (stripos($type, 'site') !== false || stripos($type, 'web') !== false) $icon = '🌐';
                        ?>
                            <a href="<?= $url ?>" target="_blank" rel="noopener noreferrer" class="btn btn-sm" style="display: inline-flex; align-items: center; gap: 8px; background: var(--bg-tertiary); border: 1px solid var(--border-color); color: var(--text-primary); text-decoration: none; padding: 8px 14px; border-radius: 8px; transition: all 0.2s ease;">
                                <span style="font-size: 1.1rem;"><?= $icon ?></span>
                                <span><strong><?= $title ?></strong> (<?= $type ?>)</span>
                                <span style="font-size: 0.8rem; opacity: 0.6;">↗</span>
                            </a>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <?php if (!empty($answers['portfolio_images'])): ?>
                    <h4 style="margin: 15px 0 10px 0; font-size: 0.95rem; text-transform: uppercase; color: var(--text-muted); letter-spacing: 0.5px;">🖼️ Galerie de Captures & Créations (<?= count($answers['portfolio_images']) ?>) :</h4>
                    <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(170px, 1fr)); gap: 14px;">
                        <?php foreach ($answers['portfolio_images'] as $img_url): ?>
                            <div style="aspect-ratio: 16/10; border-radius: 10px; overflow: hidden; border: 1px solid var(--border-color); background: #000; cursor: pointer; position: relative;" onclick="openPortfolioLightbox('<?= htmlspecialchars($img_url) ?>')">
                                <img src="<?= htmlspecialchars($img_url) ?>" alt="Capture Portfolio" style="width: 100%; height: 100%; object-fit: cover; transition: transform 0.2s ease;" onmouseover="this.style.transform='scale(1.06)'" onmouseout="this.style.transform='scale(1)'">
                                <div style="position: absolute; bottom: 5px; right: 5px; background: rgba(0,0,0,0.7); color: white; padding: 3px 8px; border-radius: 6px; font-size: 0.75rem; font-weight: 600;">🔍 Zoom</div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>
        
        <div class="card" id="commentaires">
            <h3>Discussion, Notes & Historique</h3>
            <div class="tabs">
                <div class="tab-link active" onclick="openTab(event, 'public')">Tchat avec le Candidat</div>
                <div class="tab-link" onclick="openTab(event, 'private')">Notes Internes</div>
                <?php if ($has_player_background): ?>
                <div class="tab-link" id="tab-btn-player-history" onclick="openTab(event, 'player-history')" style="color: var(--accent-red); font-weight: bold;">
                    ⚠️ Antécédents Joueur (<?= count($player_notes) ?> notes)
                </div>
                <?php endif; ?>
            </div>
            <div id="public" class="tab-content active">
                <div class="chat-box" id="chat-container">
                    <?php if(empty($messages)): ?><div style="text-align:center; padding: 20px; color: var(--text-muted);">Aucun message.</div><?php else: foreach($messages as $msg): 
                        $is_me = ($msg['user_id'] == $_SESSION['user']['id']);
                        $is_staff = $msg['is_staff'];
                        
                        // LOGIQUE VUE RECRUTEUR
                        if ($is_staff) {
                            // STAFF -> DROITE
                            $wrapper_cls = 'msg-right';
                            if ($is_me) {
                                $msg_cls = 'msg-bubble-me'; // Bleu
                            } else {
                                $msg_cls = 'msg-bubble-staff-other'; // Violet
                            }
                            $role_badge = '<span class="badge-staff">Staff</span>';
                        } else {
                            // CANDIDAT -> GAUCHE
                            $wrapper_cls = 'msg-left';
                            $msg_cls = 'msg-bubble-other'; // Gris
                            $role_badge = '';
                        }
                    ?>
                        <div class="message-wrapper <?= $wrapper_cls ?>" data-id="<?= $msg['id'] ?>">
                            <div class="message <?= $msg_cls ?>">
                                <div class="msg-header"><strong><?= htmlspecialchars($msg['display_name'] ?? ($is_staff ? 'Système' : 'Candidat')) ?></strong><?= $role_badge ?> <small><?= date('d/m H:i', strtotime($msg['created_at'])) ?></small></div>
                                <div class="msg-content"><?= nl2br(htmlspecialchars($msg['message'])) ?></div>
                            </div>
                        </div>
                    <?php endforeach; endif; ?>
                </div>
                <form method="post" id="chat-form">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                    <div style="display:flex; gap:10px;"><input type="text" name="message_text" class="card" placeholder="Répondre au candidat..." required style="flex:1; margin:0;" autocomplete="off"><button type="submit" name="send_message" class="btn btn-primary">Envoyer</button></div>
                    <div class="form-group checkbox-group mt-1"><label style="flex-direction: row; align-items: center; font-size:0.85rem;"><input type="checkbox" name="send_notification" checked> Notifier le candidat par MP</label></div>
                </form>
            </div>
            <div id="private" class="tab-content">
                <?php if(empty($private_comments)): ?><p class="text-muted">Aucune note interne.</p><?php else: foreach(array_reverse($private_comments) as $c): ?>
                    <div class="private-comment"><p style="display:flex;justify-content:space-between;align-items:baseline;margin-bottom:5px;"><strong><?= htmlspecialchars($c['author']) ?></strong><small style="color:var(--text-muted);font-size:0.8rem;"><?= date('d/m/Y H:i', strtotime($c['date'])) ?></small></p><p><?= nl2br(htmlspecialchars($c['text'])) ?></p></div>
                <?php endforeach; endif; ?>
                <hr style="margin: 15px 0;">
                <form method="post"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>"><textarea name="private_comment_text" rows="3" placeholder="Ajouter une note visible uniquement par le staff..." required></textarea><button type="submit" name="add_private_comment" class="btn" style="width:100%;justify-content:center;margin-top:10px;">🔒 Enregistrer Note</button></form>
            </div>
            
            <?php if ($has_player_background): ?>
            <div id="player-history" class="tab-content">
                <?php if (!empty($app['departure_reason'])): ?>
                    <div class="card mb-3" style="border-left: 4px solid var(--accent-tertiary); background: rgba(243, 156, 18, 0.05);">
                        <h4 style="margin: 0 0 5px 0; color: var(--accent-tertiary);">🚪 Motif de départ renseigné par l'utilisateur</h4>
                        <p style="margin: 0; font-size: 1rem;">« <?= nl2br(htmlspecialchars($app['departure_reason'])) ?> »</p>
                        <small class="text-muted">Date du départ : <?= $app['departure_date'] ? date('d/m/Y H:i', strtotime($app['departure_date'])) : 'Inconnue' ?></small>
                    </div>
                <?php endif; ?>

                <?php if (!empty($player_sanctions)): ?>
                    <h4 style="color: var(--accent-red); margin-bottom: 10px;">Sanctions FTA antérieures</h4>
                    <?php foreach ($player_sanctions as $sanct): ?>
                        <div class="card mb-2" style="border-left: 4px solid var(--accent-red); padding: 12px;">
                            <div style="display:flex; justify-content:space-between; align-items:baseline;">
                                <strong><?= htmlspecialchars($sanct['sanction_type']) ?></strong>
                                <small class="text-muted"><?= date('d/m/Y', strtotime($sanct['start_date'])) ?> par <?= htmlspecialchars($sanct['author_name'] ?? 'Staff') ?></small>
                            </div>
                            <p style="margin: 5px 0 0 0; font-size: 0.9rem;"><?= htmlspecialchars($sanct['reason']) ?></p>
                        </div>
                    <?php endforeach; ?>
                    <hr style="margin: 15px 0;">
                <?php endif; ?>

                <h4 style="margin-bottom: 10px;">Notes d'incidents, départs & suivi de Période d'Essai</h4>
                <?php if (empty($player_notes)): ?>
                    <p class="text-muted">Aucune note spécifique rédigée sur ce joueur.</p>
                <?php else: foreach ($player_notes as $pnote): 
                    $n_type = $pnote['note_type'] ?? 'general';
                    $type_titles = [
                        'incident' => '⚠️ Incident / Fait marquant',
                        'departure' => '🚪 Motif de départ',
                        'trial' => '📋 Suivi Période d\'essai',
                        'general' => '📌 Note de suivi'
                    ];
                    $note_title = $type_titles[$n_type] ?? 'Note';
                    $attachments = !empty($pnote['attachments_json']) ? json_decode($pnote['attachments_json'], true) : [];
                ?>
                    <div class="card mb-2" style="border-left: 4px solid <?= $n_type === 'incident' ? 'var(--accent-red)' : ($n_type === 'departure' ? 'var(--accent-tertiary)' : 'var(--accent-primary)') ?>; padding: 15px;">
                        <div style="display:flex; justify-content:space-between; align-items:baseline; margin-bottom: 8px;">
                            <span style="font-weight:bold; font-size:0.85rem; text-transform:uppercase; color: <?= $n_type === 'incident' ? 'var(--accent-red)' : ($n_type === 'departure' ? 'var(--accent-tertiary)' : 'var(--accent-primary)') ?>;">
                                <?= $note_title ?>
                            </span>
                            <small class="text-muted"><?= date('d/m/Y H:i', strtotime($pnote['created_at'])) ?> par <strong><?= htmlspecialchars($pnote['recruiter_name'] ?? 'Staff') ?></strong></small>
                        </div>
                        <div style="font-size: 0.95rem; line-height: 1.5;">
                            <?= nl2br(htmlspecialchars($pnote['note_text'])) ?>
                        </div>
                        <?php if (!empty($attachments) && is_array($attachments)): ?>
                        <div style="display:flex; flex-wrap:wrap; gap:10px; margin-top:10px;">
                            <?php foreach ($attachments as $att): ?>
                                <a href="<?= htmlspecialchars($att) ?>" target="_blank" title="Agrandir la capture">
                                    <img src="<?= htmlspecialchars($att) ?>" style="width: 80px; height: 80px; object-fit: cover; border-radius: 6px; border: 1px solid var(--border-color);" alt="Capture">
                                </a>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; endif; ?>
            </div>
            <?php endif; ?>
        </div>
    </div>
    <aside class="sidebar"> 
        <div class="card">
            <h4>Votes (Discord)</h4>
            <div style="display: flex; justify-content: space-between; text-align: center; margin: 15px 0;">
                <div style="flex:1;"><div style="font-size: 1.5rem;">✅</div><div style="font-weight: bold; color: #2ecc71; font-size: 1.2rem;"><?= $discord_votes['pour'] ?></div><div style="font-size: 0.8rem; color: var(--text-muted);">POUR</div></div>
                <div style="flex:1;"><div style="font-size: 1.5rem;">➖</div><div style="font-weight: bold; color: #f39c12; font-size: 1.2rem;"><?= $discord_votes['neutre'] ?></div><div style="font-size: 0.8rem; color: var(--text-muted);">NEUTRE</div></div>
                <div style="flex:1;"><div style="font-size: 1.5rem;">❌</div><div style="font-weight: bold; color: #e74c3c; font-size: 1.2rem;"><?= $discord_votes['contre'] ?></div><div style="font-size: 0.8rem; color: var(--text-muted);">CONTRE</div></div>
            </div>
            <hr style="margin: 15px 0;">
            <h4>Avis Global (Site)</h4><div style="text-align: center; padding: 12px 0; font-weight: 600; font-size: 1.1rem;" class="<?= $global_avis_class ?>"><?= $global_avis_text ?></div><hr style="margin: 15px 0;"><h5>Mon Avis Détaillé</h5>
            <form method="post" id="recruiter-review-form"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>"><div class="form-group"><div class="button-radios"><input type="radio" id="avis-negatif" name="avis_recruteur" value="negatif" <?= ($current_recruiter_review['avis'] ?? '') === 'negatif' ? 'checked' : '' ?>><label for="avis-negatif">Négatif</label><input type="radio" id="avis-neutre" name="avis_recruteur" value="neutre" <?= (!isset($current_recruiter_review['avis']) || $current_recruiter_review['avis'] === 'neutre') ? 'checked' : '' ?>><label for="avis-neutre">Neutre</label><input type="radio" id="avis-positif" name="avis_recruteur" value="positif" <?= ($current_recruiter_review['avis'] ?? '') === 'positif' ? 'checked' : '' ?>><label for="avis-positif">Positif</label></div></div><div class="form-group"><textarea name="comment_recruteur" rows="4" placeholder="Commentaire..."><?= htmlspecialchars($current_recruiter_review['comment'] ?? '') ?></textarea></div><button type="submit" name="add_recruiter_review" class="btn" style="width:100%;justify-content:center;">Enregistrer</button></form>
            <hr style="margin: 15px 0;"><h5>Décision Finale</h5>
            <form method="post" id="status-form"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>"><input type="hidden" name="status_value" id="status-hidden-input"><div style="display: flex; gap: 10px;"><button type="button" data-status="accepte_integration" class="btn" style="flex:1;justify-content:center;background-color:var(--accent-success);">✅ Accepter</button><button type="button" data-status="refuse" class="btn" style="flex:1;justify-content:center;background-color:var(--accent-primary);">❌ Refuser</button></div></form>
        </div>
        <div class="card"><h4>Historique de Décision</h4><div style="max-height: 400px; overflow-y: auto;"><?php if (empty($history)): ?><p class="text-muted">Aucun événement.</p><?php else: foreach ($history as $item): ?><div style="display:flex;gap:15px;padding:10px 0;border-bottom:1px solid var(--border-color);"><div><p><strong><?= htmlspecialchars($item['type'] === 'review' ? $item['recruiter_name'] : $item['author']) ?></strong></p><?php if($item['type'] === 'review'): $avis_class = 'avis-' . strtolower($item['avis']); ?><p>Avis <span class="<?= $avis_class ?>"><?= strtoupper(htmlspecialchars($item['avis'])) ?></span>.</p><?php elseif($item['type'] === 'status_change'): 
                                    $s = $item['status']; 
                                    $status_map = [
                                        'attente' => 'EN ATTENTE',
                                        'accepte_integration' => 'ACCEPTÉ (INTÉGRATION)',
                                        'refuse' => 'REFUSÉ',
                                        'essai' => 'PÉRIODE D\'ESSAI',
                                        'joueur' => 'JOUEUR',
                                        'archive' => 'ARCHIVÉ'
                                    ];
                                    $label_display = $status_map[$s] ?? strtoupper(str_replace('_', ' ', htmlspecialchars($s)));
                                    $status_class = (in_array($s, ['accepted', 'accepte_integration']) ? 'avis-positif' : ($s === 'refuse' || $s === 'rejected' ? 'avis-negatif' : 'avis-neutre')); 
                                ?>
                                    <p>Statut : <strong class="<?= $status_class ?>"><?= $label_display ?></strong>.</p>
                                <?php elseif($item['type'] === 'discord_vote'): 
                                    $v = strtolower($item['vote_type']);
                                    $vote_label = ($v === 'pour' ? 'POUR ✅' : ($v === 'contre' ? 'CONTRE ❌' : 'NEUTRE ➖'));
                                    $vote_class = ($v === 'pour' ? 'avis-positif' : ($v === 'contre' ? 'avis-negatif' : 'avis-neutre'));
                                ?>
                                    <p>A voté <strong class="<?= $vote_class ?>"><?= $vote_label ?></strong> (via Discord).</p>
                                <?php endif; ?></div><small style="margin-left:auto;color:var(--text-muted);font-size:0.8rem;"><?= date('d/m H:i', strtotime($item['date'])) ?></small></div><?php endforeach; endif; ?></div></div>
         <?php if (!empty($_SESSION['user']['can_delete_application'])): ?><button type="button" id="delete-app-btn" class="btn btn-danger" style="width:100%;justify-content:center;">Supprimer la candidature</button><?php endif; ?>
    </aside>
  </div>
</div>
<div id="confirmation-modal" class="modal-overlay"><div class="modal-content"><h3 id="modal-title">Confirmer</h3><p id="modal-text"></p><form method="post" id="confirmation-form" action="<?= htmlspecialchars($_SERVER['REQUEST_URI']) ?>"></form><div class="form-group checkbox-group mt-2"><label style="flex-direction: row; align-items: center;"><input type="checkbox" id="modal-send-notification" name="send_notification" checked> Notifier</label></div><div class="row mt-2" style="justify-content: flex-end; gap: 10px;"><button type="button" class="btn modal-cancel">Annuler</button><button type="button" class="btn btn-primary modal-confirm">Confirmer</button></div></div></div>
<div id="rejection-modal" class="modal-overlay">
    <div class="modal-content">
        <h3>Motif du refus & Délai de Repostulation</h3>
        <form method="post" id="rejection-form" class="mt-2">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
            <input type="hidden" name="set_status" value="1">
            <input type="hidden" name="status_value" value="refuse">
            
            <div class="form-group">
                <label style="font-size: 0.85rem; color: var(--text-muted); display: block; margin-bottom: 4px;">Motif de refus :</label>
                <select name="rejection_reason" id="rejection_reason_select" required onchange="onRejectionReasonChange(this)">
                    <option value="" disabled selected>Choisir un motif...</option>
                    <?php foreach ($rejection_reasons as $reason): 
                        $u_lbl = 'mois';
                        if (($reason['delay_unit'] ?? '') === 'days') $u_lbl = 'jour(s)';
                        elseif (($reason['delay_unit'] ?? '') === 'years') $u_lbl = 'an(s)';
                        $delay_desc = $reason['sanction_type'] === 'def_ban' ? 'Définitif' : ($reason['delay_value'] > 0 ? $reason['delay_value'] . ' ' . $u_lbl : 'Sans délai');
                    ?>
                        <option value="<?= $reason['id'] ?>" 
                                data-sanction="<?= htmlspecialchars($reason['sanction_type']) ?>" 
                                data-delay="<?= (int)$reason['delay_value'] ?>"
                                data-unit="<?= htmlspecialchars($reason['delay_unit'] ?? 'months') ?>">
                            <?= htmlspecialchars($reason['reason_text']) ?> (<?= $delay_desc ?>)
                        </option>
                    <?php endforeach; ?>
                    <option value="autre" data-sanction="delay" data-delay="1" data-unit="months">Autre (préciser)</option>
                </select>
            </div>

            <div class="form-group" id="custom_reason" style="display:none;">
                <input type="text" name="rejection_reason_custom" class="card" placeholder="Précisez la raison du refus...">
            </div>

            <div class="form-group" style="background: rgba(255,255,255,0.03); padding: 12px; border-radius: 8px; border: 1px solid var(--border-color);">
                <label style="font-size: 0.85rem; font-weight: bold; color: var(--text-primary); display: block; margin-bottom: 6px;">
                    Délai avant de pouvoir repostuler (automatique & modifiable) :
                </label>
                <div style="display: flex; gap: 8px; align-items: center; margin-bottom: 8px; flex-wrap: wrap;">
                    <input type="number" name="rejection_reapply_value" id="rejection_reapply_value" min="0" max="999" value="1" class="card" style="width: 70px; padding: 6px; margin: 0;">
                    <select name="rejection_reapply_unit" id="rejection_reapply_unit" class="card" style="width: auto; padding: 6px; margin: 0;">
                        <option value="days">Jour(s)</option>
                        <option value="months" selected>Mois</option>
                        <option value="years">An(s)</option>
                    </select>
                    <small style="color: var(--text-muted); margin-left: 5px;">(0 = aucun délai d'attente)</small>
                </div>
                <label style="display: flex; align-items: center; gap: 6px; font-size: 0.85rem; cursor: pointer;">
                    <input type="checkbox" name="rejection_reapply_permanent" id="rejection_reapply_permanent" value="1" onchange="const d = this.checked; document.getElementById('rejection_reapply_value').disabled = d; document.getElementById('rejection_reapply_unit').disabled = d;"> 
                    Interdiction définitive de repostuler (Bannissement)
                </label>
            </div>

            <div class="form-group checkbox-group">
                <label style="flex-direction: row; align-items: center;">
                    <input type="checkbox" name="send_notification" checked> Notifier le candidat par message privé Discord
                </label>
            </div>

            <div class="row" style="justify-content: flex-end; gap: 10px;">
                <button type="button" class="btn modal-cancel">Annuler</button>
                <button type="submit" class="btn" style="background-color: var(--accent-red);">Confirmer refus</button>
            </div>
        </form>
    </div>
</div>
<div id="delete-modal" class="modal-overlay"><div class="modal-content"><h3>Supprimer ?</h3><form method="post" class="mt-2"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>"><input type="hidden" name="delete_application" value="1"><input type="hidden" name="application_id" value="<?= $app['id'] ?>"><div class="row" style="justify-content: flex-end; gap: 10px;"><button type="button" class="btn modal-cancel">Annuler</button><button type="submit" class="btn btn-danger">Oui, supprimer</button></div></form></div></div>
<audio id="notif-sound" src="sounds/notif.mp3" preload="auto"></audio>
<script>
    function openTab(evt, tabName) {
        var i, tabcontent, tablinks; tabcontent = document.getElementsByClassName("tab-content"); for (i = 0; i < tabcontent.length; i++) { tabcontent[i].style.display = "none"; }
        tablinks = document.getElementsByClassName("tab-link"); for (i = 0; i < tablinks.length; i++) { tablinks[i].className = tablinks[i].className.replace(" active", ""); }
        document.getElementById(tabName).style.display = "block"; evt.currentTarget.className += " active";
    }
    function onRejectionReasonChange(select) {
        const customDiv = document.getElementById('custom_reason');
        const valInput = document.getElementById('rejection_reapply_value');
        const unitSelect = document.getElementById('rejection_reapply_unit');
        const permCheckbox = document.getElementById('rejection_reapply_permanent');

        if (select.value === 'autre') {
            customDiv.style.display = 'block';
            valInput.value = 1;
            unitSelect.value = 'months';
            valInput.disabled = false;
            unitSelect.disabled = false;
            permCheckbox.checked = false;
            return;
        }

        customDiv.style.display = 'none';
        const opt = select.options[select.selectedIndex];
        const sanctionType = opt.getAttribute('data-sanction');
        const delayVal = parseInt(opt.getAttribute('data-delay') || 0, 10);
        const delayUnit = opt.getAttribute('data-unit') || 'months';

        if (sanctionType === 'def_ban') {
            permCheckbox.checked = true;
            valInput.disabled = true;
            unitSelect.disabled = true;
        } else {
            permCheckbox.checked = false;
            valInput.disabled = false;
            unitSelect.disabled = false;
            valInput.value = delayVal > 0 ? delayVal : 1;
            unitSelect.value = delayUnit;
        }
    }

    document.addEventListener('DOMContentLoaded', function() {
        // --- CORRECTIF AUDIO ---
        document.body.addEventListener('click', function() { const audio = document.getElementById('notif-sound'); if (audio) { audio.play().then(() => { audio.pause(); audio.currentTime = 0; }).catch(e => {}); } }, { once: true });
        
        const rejectionModal = document.getElementById('rejection-modal'); const confirmationModal = document.getElementById('confirmation-modal'); const deleteModal = document.getElementById('delete-modal'); const statusForm = document.getElementById('status-form'); const deleteAppBtn = document.getElementById('delete-app-btn'); let activeModalForm = null;
        function hideModals() { rejectionModal.style.display = 'none'; confirmationModal.style.display = 'none'; deleteModal.style.display = 'none'; activeModalForm = null; }
        document.querySelectorAll('.modal-cancel').forEach(btn => btn.addEventListener('click', hideModals));
        statusForm.querySelectorAll('button').forEach(button => {
            button.addEventListener('click', function() { 
                if (this.hasAttribute('disabled')) return; 
                const statusValue = this.dataset.status; 
                if (statusValue === 'refuse') { 
                    rejectionModal.style.display = 'flex'; 
                } else if (statusValue === 'accepte_integration') { 
                    confirmationModal.querySelector('#modal-title').textContent = 'Accepter la candidature'; 
                    confirmationModal.querySelector('#modal-text').textContent = 'Voulez-vous accepter ce candidat ?'; 
                    activeModalForm = statusForm; 
                    confirmationModal.style.display = 'flex'; 
                } 
            });
        });
        if (deleteAppBtn) deleteAppBtn.addEventListener('click', () => deleteModal.style.display = 'flex');
        confirmationModal.querySelector('.modal-confirm').addEventListener('click', function() { 
            if (!activeModalForm) return; 
            const confirmationForm = document.getElementById('confirmation-form'); 
            confirmationForm.innerHTML = ''; 
            
            const actionInput = document.createElement('input'); 
            actionInput.type = 'hidden'; 
            actionInput.name = 'set_status'; 
            actionInput.value = '1'; 
            confirmationForm.appendChild(actionInput);

            const statusInput = document.createElement('input'); 
            statusInput.type = 'hidden'; 
            statusInput.name = 'status_value'; 
            statusInput.value = 'accepte_integration'; 
            confirmationForm.appendChild(statusInput); 

            const notifInput = document.createElement('input'); 
            notifInput.type = 'hidden'; 
            notifInput.name = 'send_notification'; 
            notifInput.value = document.getElementById('modal-send-notification').checked ? '1' : '0'; 
            confirmationForm.appendChild(notifInput); 
            
            const csrfClone = activeModalForm.querySelector('input[name="csrf_token"]').cloneNode(); 
            confirmationForm.appendChild(csrfClone); 
            confirmationForm.submit(); 
        });
        
        // --- LOGIQUE TEMPS RÉEL (VUE RECRUTEUR) ---
        const appId = "<?= $app['id'] ?>"; let lastMsgId = 0; const chatContainer = document.getElementById('chat-container'); const statusDot = document.getElementById('candidate-status-dot'); const notifSound = document.getElementById('notif-sound');
        if (chatContainer) {
            chatContainer.scrollTop = chatContainer.scrollHeight; const messagesElements = document.querySelectorAll('.message-wrapper'); if (messagesElements.length > 0) { lastMsgId = messagesElements[messagesElements.length - 1].getAttribute('data-id') || 0; }
        }
        function sendHeartbeat() {
            const state = document.visibilityState; const payload = JSON.stringify({ state: state, page: window.location.href }); 
            if (navigator.sendBeacon) { navigator.sendBeacon('/api_heartbeat', new Blob([payload], {type: 'application/json'})); } else { fetch('/api_heartbeat', { method: 'POST', headers: {'Content-Type': 'application/json'}, body: payload }); }
        }
        setInterval(sendHeartbeat, 5000); document.addEventListener("visibilitychange", sendHeartbeat); sendHeartbeat();
        function checkUpdates() {
            fetch(`/api_poll_updates?app_id=${appId}&last_msg_id=${lastMsgId}&mode=recruiter`)
            .then(response => response.json())
            .then(data => {
                if (statusDot) {
                    statusDot.className = 'status-dot';
                    if (data.candidate_presence === 'green') { statusDot.classList.add('status-green'); statusDot.title = "En ligne"; } else if (data.candidate_presence === 'orange') { statusDot.classList.add('status-orange'); statusDot.title = "Absent / Masqué"; } else { statusDot.classList.add('status-red'); statusDot.title = "Hors ligne"; }
                }
                if (data.new_messages && data.new_messages.length > 0) {
                    let soundPlayed = false;
                    data.new_messages.forEach(html => {
                        chatContainer.insertAdjacentHTML('beforeend', html);
                        // Son uniquement si c'est le CANDIDAT qui parle (bubble-other)
                        if (html.includes('msg-bubble-other')) { if (!soundPlayed) { notifSound.play().catch(e=>{}); soundPlayed = true; } }
                    });
                    lastMsgId = data.last_message_id; chatContainer.scrollTop = chatContainer.scrollHeight;
                }
            }).catch(e => {});
        }
        setInterval(checkUpdates, 3000);
    });

    function openPortfolioLightbox(url) {
        const modal = document.getElementById('portfolio-lightbox-modal');
        const img = document.getElementById('portfolio-lightbox-img');
        if (modal && img) {
            img.src = url;
            modal.style.display = 'flex';
        }
    }
</script>

<!-- Modal Lightbox Portfolio -->
<div class="lightbox-modal" id="portfolio-lightbox-modal" onclick="this.style.display='none'">
    <img id="portfolio-lightbox-img" src="" alt="Aperçu Portfolio">
</div>
</body></html>