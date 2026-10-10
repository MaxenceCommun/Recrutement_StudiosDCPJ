<?php
require_once __DIR__.'/config.php';
require_once __DIR__.'/bdd.php';
require_once __DIR__.'/api_discord.php';

// Sécurité
if (empty($_SESSION['user'])) { header('Location: /connexion_discord'); exit; }
if (!empty($_SESSION['user']['is_recruiter']) || !empty($_SESSION['user']['is_bureau']) || !empty($_SESSION['user']['is_secretariat'])) { header('Location: /espace_recruteur'); exit; }

// Synchronisation en temps réel du statut de l'utilisateur avec la base de données
try {
    $pdo_sync = db();
    $stmt_sync = $pdo_sync->prepare("SELECT status FROM users WHERE id = ?");
    $stmt_sync->execute([$_SESSION['user']['id']]);
    $db_status = $stmt_sync->fetchColumn();
    if ($db_status && $db_status !== $_SESSION['user']['status']) {
        $_SESSION['user']['status'] = $db_status;
    }
} catch (Exception $e) { /* Ignore */ }

$trial_statuses = ['essai', 'essai_adhesion', 'essai_termine'];
if (isset($_SESSION['user']['status']) && in_array($_SESSION['user']['status'], $trial_statuses)) { header('Location: /mon_suivi'); exit; }

$pdo = db();
$st = $pdo->prepare("SELECT a.* FROM applications a WHERE a.user_id=? AND a.status != 'archive' ORDER BY a.created_at DESC LIMIT 1");
$st->execute([$_SESSION['user']['id']]);
$app = $st->fetch(PDO::FETCH_ASSOC);

if (!$app) { header('Location: /choisir_candidature'); exit; }

// Envoi Message
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_message'])) {
    if (isset($_POST['csrf_token'], $_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $_POST['csrf_token']) && !empty(trim($_POST['message_text']))) {
        $msg = trim($_POST['message_text']);
        $pdo->prepare("INSERT INTO application_messages (application_id, user_id, message, is_staff) VALUES (?, ?, ?, 0)")->execute([$app['id'], $_SESSION['user']['id'], $msg]);
        
        // Notif si aucun recruteur n'est sur la page
        $searchPage = "%candidat?id=" . $app['id']; 
        $limitTime = date('Y-m-d H:i:s', time() - 180);
        $stmtCheck = $pdo->prepare("SELECT COUNT(*) FROM users WHERE is_recruiter = 1 AND last_activity > ? AND current_page LIKE ?");
        $stmtCheck->execute([$limitTime, $searchPage]);
        if ($stmtCheck->fetchColumn() == 0) {
            $recruiter_url = ($GLOBALS['APP_BASE_URL'] ?? '') . '/candidat?id=' . $app['id'];
            discord_send_webhook_message($DISCORD_NEW_APPLICATION_WEBHOOK_URL, "💬 Nouveau message de `{$_SESSION['user']['username']}` sur la candidature #{$app['id']}.\n➡️ <{$recruiter_url}>");
        }
        unset($_SESSION['csrf_token']); header('Location: ' . $_SERVER['PHP_SELF']); exit;
    }
}
$_SESSION['csrf_token'] = bin2hex(random_bytes(32)); $csrf_token = $_SESSION['csrf_token'];

// Cooldown
$is_on_cooldown = false; $cooldown_ends_at = null; $rejection_reason_cooldown = '';

$stmt_u = $pdo->prepare("SELECT can_reapply_after FROM users WHERE id = ?");
$stmt_u->execute([$_SESSION['user']['id']]);
$u_row = $stmt_u->fetch(PDO::FETCH_ASSOC);
$now_dt = new DateTime();

if ($app['status'] === 'refuse') {
    $reapply_date = null;
    if ($u_row && !empty($u_row['can_reapply_after'])) {
        $reapply_date = new DateTime($u_row['can_reapply_after']);
    } else {
        $reapply_date = (new DateTime($app['updated_at']))->modify('+' . ($GLOBALS['APPLICATION_REJECTION_COOLDOWN'] ?? '1 month'));
    }

    if ($reapply_date > $now_dt) {
        $is_on_cooldown = true;
        $is_permanent = ($reapply_date->diff($now_dt)->y >= 50);
        $cooldown_ends_at = $reapply_date;
        $rejection_reason_cooldown = $app['rejection_reason'] ?? 'Délai de blocage en cours';
    } else {
        header('Location: /choisir_candidature');
        exit;
    }
}

$answers = json_decode($app['answers_json'] ?: '{}', true);
$app_type = $app['application_type'] ?? 'player';
$is_operator = ($app_type === 'operator');
$is_developer = ($app_type === 'developer');
$type_label = $is_developer ? 'Développeur' : ($is_operator ? 'Opérateur' : 'Joueur');

$all_statuses = [ 'attente' => 'En attente', 'accepte_integration' => "Accepté, en attente d'intégration", 'refuse' => 'Refusé', 'essai' => 'Période d\'essai', 'essai_adhesion' => 'Période d\'essai (adhésion en cours)', 'essai_termine' => 'Période d\'essai terminée', 'joueur' => 'Joueur', 'non_adherent' => 'Non adhérent', 'ancien_joueur' => 'Ancien joueur', 'archive' => 'Archivée', ];
$final_status = $app['status']; $label = $all_statuses[$final_status] ?? ucfirst(str_replace('_', ' ', $final_status)); $css_class = 'status-' . str_replace('_', '-', $final_status);

$stmtMsgs = $pdo->prepare("SELECT m.*, u.display_name, u.username FROM application_messages m LEFT JOIN users u ON m.user_id = u.id WHERE m.application_id = ? ORDER BY m.created_at ASC");
$stmtMsgs->execute([$app['id']]);
$messages = $stmtMsgs->fetchAll(PDO::FETCH_ASSOC);

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

function display_answers($answers, $app_type) { 
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

    if ($app_type === 'operator') {
        $config = $labels_o;
    } elseif ($app_type === 'developer') {
        $config = $labels_d;
    } else {
        $config = $labels_p;
    }

    $html = '<div class="dossier-grid" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 12px;">'; 
    
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

        if ($key == 'is_pro') {
            if (strtolower($val) == 'non' && empty($answers['service_pro_details'])) continue;
            if (!empty($answers['service_pro_details'])) {
                $val = "Oui : " . $answers['service_pro_details'];
            }
        }
        
        if ($key == 'experience_irl' && !empty($answers['experience_irl_details'])) {
            $val .= "\nDétails : " . $answers['experience_irl_details'];
        }
        if ($key == 'experience_rp' && !empty($answers['experience_rp_details'])) {
            $val .= "\nDétails : " . $answers['experience_rp_details'];
        }

        $is_full = !empty($info['full']);
        $grid_style = $is_full ? 'grid-column: 1 / -1;' : '';
        $box_style = $is_full ? 'background: rgba(0,0,0,0.08); padding: 10px; border-radius: 8px; border-left: 3px solid var(--accent-secondary);' : 'background: rgba(0,0,0,0.03); padding: 10px; border-radius: 8px; border: 1px solid var(--border-color);';
        
        $html .= '<div class="dossier-item '.$is_full.'" style="'.$grid_style.' overflow: hidden;">';
        $html .= '<div class="dossier-label" style="display: block; color: var(--text-muted); margin-bottom: 4px; font-size: 0.75rem; text-transform: uppercase; font-weight: bold;">'.$info['icon'].' '.$info['label'].'</div>';
        $html .= '<div class="dossier-value" style="'.$box_style.' font-size: 0.95rem; line-height: 1.4; color: var(--text-primary); overflow-wrap: break-word; word-break: break-word;">'.nl2br(htmlspecialchars($val)).'</div>';
        $html .= '</div>';
    } 
    $html .= '</div>'; 
    return $html; 
}
?>
<!DOCTYPE html><html lang="fr"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Ma candidature — Studios DCPJ</title>
<link rel="icon" type="image/png" href="assets/logo.png">
<link rel="stylesheet" href="assets/styles.css">
<style>
    .chat-box { background: var(--bg-tertiary); border: 1px solid var(--border-color); border-radius: 8px; height: 350px; overflow-y: auto; padding: 15px; display: flex; flex-direction: column; gap: 12px; margin-bottom: 12px; }
    .message-wrapper { display: flex; width: 100%; }
    
    .msg-left { justify-content: flex-start; }
    .msg-right { justify-content: flex-end; }
    
    .message { max-width: 80%; padding: 8px 12px; border-radius: 10px; font-size: 0.9rem; line-height: 1.4; position: relative; word-wrap: break-word; }
    
    .msg-bubble-other { background-color: var(--bg-secondary); border: 1px solid var(--border-color); border-bottom-left-radius: 2px; }
    .msg-bubble-me { background-color: var(--accent-primary); color: white; border-bottom-right-radius: 2px; }
    .msg-bubble-me .msg-header { color: rgba(255,255,255,0.9); }

    .msg-header { font-size: 0.75rem; margin-bottom: 4px; opacity: 0.9; display: flex; gap: 8px; align-items: center; justify-content: space-between; }
    
    .cooldown-box { background: var(--bg-secondary); border-radius: 16px; padding: 40px; max-width: 800px; margin: 40px auto; text-align: center; border: 1px solid var(--border-color); }
    #countdown-timer { font-size: 2rem; font-weight: bold; color: var(--accent-primary); margin: 20px 0; display: block; }
    
    @media (max-width: 900px) {
        .candidate-layout { grid-template-columns: 1fr !important; }
        .candidate-sidebar { position: static !important; top: auto !important; }
        .cooldown-box { padding: 25px 15px; }
        #countdown-timer { font-size: 1.5rem; }
    }
</style>
</head><body>
<?php include __DIR__.'/partiels_entete.php'; ?>
<div class="wrap page-container">
    <?php if ($is_on_cooldown): ?>
        <div class="cooldown-box">
            <h2 style="color: var(--accent-red);"><?= !empty($is_permanent) ? '🚫 Interdiction Définitive de Repostuler' : 'Candidature Refusée' ?></h2>
            <p class="mt-2"><strong>Motif :</strong> <?= htmlspecialchars($rejection_reason_cooldown) ?></p>
            <?php if (!empty($is_permanent)): ?>
                <p class="text-secondary mt-3">Une décision du staff interdit définitivement le dépôt de nouvelles candidatures avec ce compte.</p>
            <?php else: ?>
                <p class="mt-3">Réessayer dans :</p>
                <div id="countdown-timer" data-date="<?= $cooldown_ends_at->format('Y-m-d H:i:s') ?>">--:--:--:--</div>
            <?php endif; ?>
        </div>
    <?php else: ?>
        <h2 style="margin-bottom:10px; text-align: center;">Ma candidature <?= htmlspecialchars($type_label) ?></h2>
        <?php $current_step_status = $final_status; include __DIR__.'/partiels_stepper.php'; ?>
        <div class="about candidate-layout" style="grid-template-columns:1fr 2fr;gap:24px;align-items: flex-start;">
            <div class="card">
                <h3>Votre dossier <?= htmlspecialchars($type_label) ?></h3>
                <hr style="margin: 15px 0; border-color: var(--border-color);">
                <?= display_answers($answers, $app['application_type']) ?>

                <?php if (!empty($answers['portfolio_links']) || !empty($answers['portfolio_images'])): ?>
                    <hr style="margin: 20px 0; border-color: var(--border-color);">
                    <h4 style="color: #9b59b6; margin-bottom: 12px; display: flex; align-items: center; gap: 8px;">
                        <span>📂</span> Votre Portfolio Développeur
                    </h4>
                    <?php if (!empty($answers['portfolio_links'])): ?>
                        <div style="display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 15px;">
                            <?php foreach ($answers['portfolio_links'] as $pl): ?>
                                <a href="<?= htmlspecialchars($pl['url'] ?? '#') ?>" target="_blank" rel="noopener noreferrer" class="btn btn-sm" style="background: var(--bg-tertiary); border: 1px solid var(--border-color); color: var(--text-primary);">
                                    🔗 <?= htmlspecialchars($pl['title'] ?: ($pl['type'] ?? 'Lien')) ?> ↗
                                </a>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                    <?php if (!empty($answers['portfolio_images'])): ?>
                        <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(130px, 1fr)); gap: 10px;">
                            <?php foreach ($answers['portfolio_images'] as $img_url): ?>
                                <a href="<?= htmlspecialchars($img_url) ?>" target="_blank" style="aspect-ratio: 16/10; border-radius: 8px; overflow: hidden; border: 1px solid var(--border-color); display: block;">
                                    <img src="<?= htmlspecialchars($img_url) ?>" alt="Portfolio" style="width:100%; height:100%; object-fit: cover;">
                                </a>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
            <div class="candidate-sidebar" style="display: flex; flex-direction: column; gap: 24px; position: sticky; top: 90px;">
                <div class="card"><h3 class="mb-1">Statut</h3><p><span class="status-badge <?= $css_class ?>" id="app-status-badge"><?= htmlspecialchars($label) ?></span></p>
                    <?php if ($final_status === 'accepte_integration' && !empty($app['integration_token'])): ?><div class="alert alert-success" style="margin-top:15px;"><strong>Félicitations !</strong> Candidature acceptée.</div><a href="/integration?token=<?= htmlspecialchars($app['integration_token']) ?>" class="btn btn-primary" style="width: 100%; justify-content: center; margin-top: 10px;">Commencer l'intégration</a><?php endif; ?>
                    <?php if (in_array($final_status, ['essai_adhesion', 'essai_termine'])): ?>
                        <div class="alert alert-success" style="margin-top:15px;">
                            <strong>🎉 Période d'essai validée !</strong><br>
                            Votre période d'essai est terminée. Vous pouvez finaliser votre adhésion associative officielle sur HelloAsso.
                        </div>
                        <a href="<?= htmlspecialchars($GLOBALS['HELLOASSO_MEMBERSHIP_URL'] ?? 'https://www.helloasso.com') ?>" target="_blank" rel="noopener noreferrer" class="btn btn-primary" style="width: 100%; justify-content: center; margin-top: 10px; gap: 8px;">
                            <span>💳</span> Adhérer sur HelloAsso
                        </a>
                    <?php endif; ?>
                </div>
                <div class="card" id="tchat-section">
                    <h3 class="mb-1">Espace de discussion</h3>
                    <div class="chat-box" id="chat-container">
                        <?php if(empty($messages)): ?><div style="text-align:center; padding: 20px; color: var(--text-muted); font-style: italic;" id="no-msg-placeholder">Début de la conversation</div><?php else: foreach($messages as $msg): 
                            if ($msg['is_staff']) {
                                // RECRUTEUR -> GAUCHE / GRIS
                                $wrapper_cls = 'msg-left'; $msg_cls = 'msg-bubble-other'; $display_name = "Recruteur"; $role_badge = "";
                            } else {
                                // MOI -> DROITE / BLEU
                                $wrapper_cls = 'msg-right'; $msg_cls = 'msg-bubble-me'; $display_name = ""; $role_badge = "";
                            }
                        ?>
                            <div class="message-wrapper <?= $wrapper_cls ?>" data-id="<?= $msg['id'] ?>"><div class="message <?= $msg_cls ?>"><div class="msg-header"><strong><?= $display_name ?></strong><?= $role_badge ?><small><?= date('H:i', strtotime($msg['created_at'])) ?></small></div><div class="msg-content"><?= nl2br(htmlspecialchars($msg['message'])) ?></div></div></div>
                        <?php endforeach; endif; ?>
                    </div>
                    <form method="POST" id="chat-form"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>"><div style="display:flex; gap:10px;"><input type="text" name="message_text" id="message-input" class="card" placeholder="Écrivez votre message..." autocomplete="off" required style="flex:1; margin:0;"><button type="submit" name="send_message" class="btn btn-primary">Envoyer</button></div></form>
                </div>
            </div>
        </div>
    <?php endif; ?>
</div>
<audio id="notif-sound" src="sounds/notif.mp3" preload="auto"></audio>
<script>
document.addEventListener('DOMContentLoaded', function() {
    // --- CORRECTIF AUDIO : DÉVERROUILLAGE AU PREMIER CLIC ---
    document.body.addEventListener('click', function() { 
        const audio = document.getElementById('notif-sound'); 
        if (audio) { 
            audio.play().then(() => { audio.pause(); audio.currentTime = 0; }).catch(e => { /* Déjà bloqué ou erreur */ }); 
        } 
    }, { once: true });
    
    const timerElement = document.getElementById('countdown-timer');
    if (timerElement) {
        const endDate = new Date(timerElement.getAttribute('data-date')).getTime();
        const countdown = setInterval(function() {
            const distance = endDate - new Date().getTime();
            if (distance < 0) { clearInterval(countdown); timerElement.innerHTML = "Vous pouvez maintenant postuler !"; window.location.href = '/choisir_candidature'; return; }
            const days = Math.floor(distance / (1000 * 60 * 60 * 24)); const hours = Math.floor((distance % (1000 * 60 * 60 * 24)) / (1000 * 60 * 60)); const minutes = Math.floor((distance % (1000 * 60 * 60)) / (1000 * 60));
            timerElement.innerHTML = `${days}j ${hours}h ${minutes}m`;
        }, 1000);
    }

    const chatContainer = document.getElementById('chat-container');
    if (chatContainer) {
        chatContainer.scrollTop = chatContainer.scrollHeight;
        const appId = "<?= $app['id'] ?? 0 ?>"; let lastMsgId = 0;
        const messagesElements = document.querySelectorAll('.message-wrapper'); if (messagesElements.length > 0) lastMsgId = messagesElements[messagesElements.length - 1].getAttribute('data-id') || 0;
        const notifSound = document.getElementById('notif-sound');

        function sendHeartbeat() {
            const state = document.visibilityState; const payload = JSON.stringify({ state: state, page: window.location.href }); 
            if (navigator.sendBeacon) { navigator.sendBeacon('/api_heartbeat', new Blob([payload], {type: 'application/json'})); } else { fetch('/api_heartbeat', { method: 'POST', headers: {'Content-Type': 'application/json'}, body: payload }); }
        }
        setInterval(sendHeartbeat, 5000); document.addEventListener("visibilitychange", sendHeartbeat); sendHeartbeat();

        function checkUpdates() {
            fetch(`/api_poll_updates?app_id=${appId}&last_msg_id=${lastMsgId}&mode=candidate`)
            .then(response => response.json())
            .then(data => {
                if (data.new_messages && data.new_messages.length > 0) {
                    const noMsgPlaceholder = document.getElementById('no-msg-placeholder'); if (noMsgPlaceholder) noMsgPlaceholder.remove();
                    let soundPlayed = false;
                    data.new_messages.forEach(html => {
                        chatContainer.insertAdjacentHTML('beforeend', html);
                        // Son si c'est "other" (le recruteur)
                        if (html.includes('msg-bubble-other')) { if (!soundPlayed) { notifSound.play().catch(e => {}); soundPlayed = true; } }
                    });
                    lastMsgId = data.last_message_id; chatContainer.scrollTop = chatContainer.scrollHeight;
                }
            }).catch(e => {});
        }
        setInterval(checkUpdates, 3000);
    }
});
</script>
</body></html>