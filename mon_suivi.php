<?php
require_once __DIR__.'/config.php';
require_once __DIR__.'/bdd.php';

if (empty($_SESSION['user'])) {
    header('Location: /connexion_discord');
    exit;
}

// --- SÉCURITÉ : Redirection des rôles ---
// Si l'utilisateur est un membre du staff, on le redirige vers l'espace recruteur.
if (!empty($_SESSION['user']['is_recruiter']) || !empty($_SESSION['user']['is_bureau']) || !empty($_SESSION['user']['is_secretariat'])) {
    header('Location: /espace_recruteur');
    exit;
}

$pdo = db();
$user_id = $_SESSION['user']['id'];

// Récupérer les informations de l'utilisateur
$stmt = $pdo->prepare("SELECT trial_start_date, total_playtime, status FROM users WHERE id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

// SÉCURITÉ : Vérifier que l'utilisateur est bien dans un statut de période d'essai
$trial_statuses = ['essai', 'essai_adhesion', 'essai_termine'];
if (empty($user) || !in_array($user['status'], $trial_statuses)) {
    header('Location: /connexion_discord');
    exit;
}

if (empty($user['trial_start_date'])) {
    // Si la date de début d'essai est vide, on l'initialise à maintenant pour éviter tout blocage.
    $now_str = date('Y-m-d H:i:s');
    $pdo->prepare("UPDATE users SET trial_start_date = ? WHERE id = ?")->execute([$now_str, $user_id]);
    $user['trial_start_date'] = $now_str;
}

$is_in_trial = true;
$now = new DateTime();

// --- Calculs pour l'affichage initial ---
// Le JavaScript mettra à jour ces valeurs en temps réel si le joueur est connecté.

$playtime_seconds = (int)($user['total_playtime'] ?? 0);
$playtime_goal_seconds = 10 * 3600;
$playtime_progress = min(100, ($playtime_seconds / $playtime_goal_seconds) * 100);
$hours = floor($playtime_seconds / 3600);
$minutes = floor(($playtime_seconds % 3600) / 60);
$formatted_playtime = sprintf('%dh %02dm', $hours, $minutes);

$trial_start_dt = new DateTime($user['trial_start_date']);
$trial_start_date_only = clone $trial_start_dt;
$trial_start_date_only->setTime(0, 0, 0);

// Règle : Si terminé après 22h, l'ancienneté ne commence à compter qu'un jour plus tard
if ((int)$trial_start_dt->format('G') >= 22) {
    $trial_start_date_only->modify('+1 day');
}

$now_date_only = new DateTime();
$now_date_only->setTime(0, 0, 0);

// Nombre de jours entiers passés depuis la date de référence
// Si on est le lendemain du jour de référence (à 00h00), on est à 1 jour.
$days_passed = $trial_start_date_only->diff($now_date_only)->days;
if ($now_date_only < $trial_start_date_only) $days_passed = 0; // Sécurité si date future

$seniority_goal_days = 7;
$seniority_progress = min(100, ($days_passed / $seniority_goal_days) * 100);

$seniority_achieved = $days_passed >= $seniority_goal_days;
$playtime_achieved = $playtime_seconds >= $playtime_goal_seconds;
$all_objectives_met = $seniority_achieved && $playtime_achieved;

$csrf_token = $_SESSION['csrf_token'] ?? bin2hex(random_bytes(32));
$_SESSION['csrf_token'] = $csrf_token;

// Traitement du formulaire d'adhésion
if ($all_objectives_met && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['request_membership'])) {
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        http_response_code(403);
        exit('Erreur de validation (CSRF).');
    }
    $pdo->prepare("UPDATE users SET membership_requested_at = NOW(), status = 'essai_adhesion' WHERE id = ?")->execute([$user_id]);
    $_SESSION['user']['status'] = 'essai_adhesion';

    // Journaliser l'action
    log_user_action($user_id, 'membership_request', "Période d'essai validée par le joueur. Redirection vers HelloAsso.");

    // Envoi de la notification Discord (DM)
    if (!empty($_SESSION['user']['discord_id'])) {
        require_once __DIR__.'/api_discord.php';
        $helloasso_link = $GLOBALS['HELLOASSO_MEMBERSHIP_URL'] ?? 'https://www.helloasso.com';
        $dm_content = "🎉 **Félicitations " . ($_SESSION['user']['display_name'] ?? $_SESSION['user']['username']) . " !**\n\n";
        $dm_content .= "Vous avez terminé et validé les objectifs de votre période d'essai sur les Studios DCPJ (ancienneté et temps de jeu atteints) !\n\n";
        $dm_content .= "Pour finaliser votre intégration associative et recevoir votre grade officiel de membre, veuillez procéder à votre adhésion sur notre page HelloAsso :\n";
        $dm_content .= "👉 <" . $helloasso_link . ">\n\n";
        $dm_content .= "À très vite sur nos serveurs !\n— L'équipe des Studios DCPJ";
        try {
            discord_send_dm($_SESSION['user']['discord_id'], $dm_content);
        } catch (Exception $e) {}

        // Notification Webhook Staff
        if (!empty($GLOBALS['DISCORD_NEW_APPLICATION_WEBHOOK_URL'])) {
            $embed = [
                'title' => "🎉 Période d'Essai Validée",
                'description' => "Le joueur **" . htmlspecialchars($_SESSION['user']['username']) . "** a validé ses objectifs de période d'essai et a été redirigé vers l'adhésion HelloAsso.",
                'color' => hexdec('2ecc71'),
                'fields' => [
                    ['name' => 'Temps de jeu', 'value' => $formatted_playtime, 'inline' => true],
                    ['name' => 'Ancienneté', 'value' => $days_passed . ' jours', 'inline' => true]
                ],
                'timestamp' => date('c')
            ];
            try {
                discord_send_webhook_message($GLOBALS['DISCORD_NEW_APPLICATION_WEBHOOK_URL'], '', [$embed]);
            } catch (Exception $e) {}
        }
    }

    // Redirection immédiate vers la page HelloAsso
    $helloasso_url = $GLOBALS['HELLOASSO_MEMBERSHIP_URL'] ?? 'https://www.helloasso.com';
    header('Location: ' . $helloasso_url);
    exit;
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Mon Suivi de Période d'Essai — Studios DCPJ</title>
    <link rel="icon" type="image/png" href="assets/logo.png">
    <link rel="stylesheet" href="assets/styles.css">
    <style>
        .progress-card { background: var(--bg-secondary); border-radius: 16px; padding: 30px; margin-bottom: 24px; }
        .progress-label { display: flex; justify-content: space-between; align-items: baseline; margin-bottom: 12px; font-weight: 600; }
        .progress-bar-container { width: 100%; height: 12px; background: var(--bg-primary); border-radius: 6px; overflow: hidden; }
        .progress-bar { height: 100%; background: var(--gradient-primary); border-radius: 6px; transition: width 0.5s linear; }
        .membership-form-container { filter: grayscale(<?= ($all_objectives_met ?? false) ? '0' : '1' ?>); opacity: <?= ($all_objectives_met ?? false) ? '1' : '0.6' ?>; transition: all 0.5s ease; }
        
        .status-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 24px; margin-bottom: 24px; }
        .status-indicator-card { display: flex; align-items: center; gap: 15px; background: var(--bg-secondary); padding: 20px; border-radius: 12px; }
        .status-dot { width: 16px; height: 16px; border-radius: 50%; animation: pulse 2s infinite; transition: background-color 0.5s ease; }
        .status-dot.online { background-color: var(--accent-success); box-shadow: 0 0 10px var(--accent-success); }
        .status-dot.offline { background-color: var(--accent-red); animation: none; }
        .status-dot.active-time { background-color: #3498db; box-shadow: 0 0 10px #3498db; }
        .status-dot.inactive-time { background-color: #7f8c8d; animation: none; }
        @keyframes pulse { 0% { opacity: 1; } 50% { opacity: 0.5; } 100% { opacity: 1; } }
        
        .info-accordion details { background: var(--bg-secondary); border: 1px solid var(--border-color); border-radius: 8px; margin-bottom: 12px; }
        .info-accordion summary { padding: 15px 20px; font-weight: bold; font-size: 1.2rem; cursor: pointer; display: flex; justify-content: space-between; align-items: center; }
        .info-accordion summary:hover { background: var(--bg-tertiary); }
        .info-accordion .info-content { padding: 0 20px 20px 20px; line-height: 1.8; color: var(--text-secondary); }
        .info-accordion summary::after { content: '▼'; font-size: 0.8em; transition: transform 0.2s; }
        .info-accordion details[open] summary::after { transform: rotate(180deg); }
        .info-content h4 { color: var(--accent-primary); margin-top: 15px; margin-bottom: 5px; }
        .info-content ul { list-style: none; padding: 0; margin-top: 10px; }
        .info-content ul li { 
            background: rgba(255, 255, 255, 0.02); 
            border: 1px solid var(--border-color); 
            padding: 10px 15px; 
            border-radius: 8px; 
            margin-bottom: 8px;
            color: var(--text-secondary);
            border-left: 3px solid var(--accent-secondary);
        }
        .info-content code { background: var(--bg-primary); padding: 3px 6px; border-radius: 4px; font-family: monospace; }
        .info-content kbd { background: var(--bg-tertiary); border: 1px solid var(--border-color); padding: 3px 8px; border-radius: 4px; font-family: monospace; font-weight: bold; }

        /* Discord Links Style */
        .discord-channel-link {
            display: flex;
            align-items: center;
            padding: 10px 14px;
            background: rgba(255, 255, 255, 0.03);
            border: 1px solid var(--border-color);
            border-radius: 10px;
            color: #e2e8f0;
            text-decoration: none !important;
            transition: all 0.3s ease;
            font-weight: 500;
            margin-bottom: 8px;
            cursor: pointer;
        }
        .discord-channel-link:hover {
            background: rgba(52, 152, 219, 0.1);
            border-color: var(--accent-secondary);
            color: white;
            transform: translateX(4px);
        }
        .discord-channel-link .icon {
            color: var(--accent-secondary);
            font-weight: bold;
            margin-right: 8px;
        }
    </style>
</head>
<body>
<?php include __DIR__.'/partiels_entete.php'; ?>
<div class="wrap page-container">
    <h2 class="mb-1" style="text-align: center;">Suivi de ma Période d'Essai</h2>
    <?php $current_step_status = $user['status'] ?? 'essai'; include __DIR__.'/partiels_stepper.php'; ?>

    <?php if ($user['status'] === 'essai_adhesion' || $user['status'] === 'essai_termine'): ?>
        <div class="card" style="background: linear-gradient(135deg, rgba(39, 174, 96, 0.15) 0%, rgba(41, 128, 185, 0.15) 100%); border: 2px solid var(--accent-success); border-radius: 16px; padding: 30px; text-align: center; margin-bottom: 30px;">
            <div style="font-size: 3.5rem; margin-bottom: 12px;">🎉</div>
            <h3 style="color: var(--accent-success); margin-bottom: 12px; font-size: 1.8rem;">Période d'essai terminée avec succès !</h3>
            <p style="font-size: 1.1rem; color: #e2e8f0; max-width: 650px; margin: 0 auto 24px auto; line-height: 1.6;">
                Félicitations ! Vos objectifs de période d'essai ont été validés. Pour finaliser votre intégration officielle au sein des Studios DCPJ, veuillez procéder à votre adhésion associative sur HelloAsso.
            </p>
            <a href="<?= htmlspecialchars($GLOBALS['HELLOASSO_MEMBERSHIP_URL'] ?? 'https://www.helloasso.com') ?>" target="_blank" rel="noopener noreferrer" class="btn btn-primary" style="font-size: 1.15rem; padding: 14px 36px; display: inline-flex; align-items: center; gap: 12px; box-shadow: 0 4px 20px rgba(39, 174, 96, 0.4);">
                <span>💳</span> Accéder à mon adhésion sur HelloAsso
            </a>
            <p class="text-muted" style="margin-top: 18px; font-size: 0.9rem;">
                Dès confirmation de votre paiement, le bureau finalisera vos accès sur le serveur Discord.
            </p>
        </div>
    <?php else: ?>
        <p class="text-secondary mb-3">Voici votre progression vers la validation de votre période d'essai. Une fois les deux objectifs atteints, vous pourrez soumettre votre demande d'adhésion.</p>

        <div class="status-grid">
            <div class="status-indicator-card">
                <div id="player-status-dot" class="status-dot offline"></div>
                <div><h4 style="margin: 0;">Statut en jeu</h4><span id="player-status-text" class="text-secondary">Chargement...</span></div>
            </div>
            <div class="status-indicator-card">
                <div id="session-status-dot" class="status-dot inactive-time"></div>
                <div><h4 style="margin: 0;">Comptabilisation</h4><span id="session-status-text" class="text-secondary">Chargement...</span></div>
            </div>
        </div>

        <div class="progress-card">
            <div class="progress-label"><span>Objectif 1 : Ancienneté</span><span><?= $days_passed ?> / <?= $seniority_goal_days ?> jours</span></div>
            <div class="progress-bar-container"><div class="progress-bar" style="width: <?= $seniority_progress ?>%;"></div></div>
        </div>

        <div class="progress-card">
            <div class="progress-label">
                <span>Objectif 2 : Temps de jeu en session</span>
                <span id="playtime-display"><?= $formatted_playtime ?> / 10h 00m</span>
            </div>
            <div class="progress-bar-container"><div id="playtime-bar" class="progress-bar" style="width: <?= $playtime_progress ?>%;"></div></div>
        </div>

        <div class="membership-form-container card">
            <h3>Demande d'Adhésion</h3>
            <p class="text-secondary">Ce formulaire se débloquera automatiquement une fois vos objectifs atteints. Vous serez ensuite directement redirigé vers la page d'adhésion HelloAsso.</p>
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                <button type="submit" name="request_membership" class="btn btn-primary" style="width: 100%; justify-content: center; padding: 14px;" <?= !$all_objectives_met ? 'disabled' : '' ?>><?= $all_objectives_met ? '🎉 Finaliser ma période d\'essai et accéder à l\'adhésion HelloAsso' : 'Objectifs non atteints' ?></button>
            </form>
        </div>
    <?php endif; ?>

        <h3 class="mt-3 mb-2">Informations utiles</h3>
        <div class="info-accordion">
             <details><summary>Rappels sur le fonctionnement</summary><div class="info-content"><p>En tant que civil, votre rôle est de créer des interventions réalistes pour les services de secours.</p><ul><li><b>Analysez :</b> Avant 21h, consultez les plannings sur Discord pour connaître les effectifs présents.</li><li><b>Dimensionnez :</b> Adaptez la gravité de votre scène au nombre de joueurs. Ne surchargez pas les services !</li><li><b>Diversifiez :</b> Inspirez-vous des idées de scénarios pour varier les plaisirs.</li></ul></div></details>
             
             <details><summary>Touches & Raccourcis en jeu</summary><div class="info-content">
                <h4>⚙️ Menus Principaux</h4>
                <ul>
                    <li><kbd>F1</kbd> : vMenu (Options globales)</li>
                    <li><kbd>F2</kbd> : Noclip (Réservé aux secours)</li>
                </ul>
                <h4>🕹️ Menus de jeu</h4>
                <p>Touches <kbd>1</kbd> à <kbd>8</kbd> :</p>
                <ul>
                    <li><kbd>1</kbd> : Menu Interactif (Props)</li>
                    <li><kbd>2</kbd> : Menu Personnel (Apparence)</li>
                    <li><kbd>3</kbd> : Téléphone</li>
                    <li><kbd>4</kbd> : Animations</li>
                    <li><kbd>8</kbd> : Menu Plastron (Civils uniquement)</li>
                </ul>
                <h4>🚶 Postures & Déplacement</h4>
                <ul>
                    <li><kbd>VERR. MAJ</kbd> : Se coucher sur le ventre</li>
                    <li><kbd>CTRL</kbd> / <kbd>ALT</kbd> : Se baisser / S'accroupir</li>
                    <li><kbd>B</kbd> : Phares en journée</li>
                </ul>
             </div></details>

             <details><summary>Commandes utiles (/)</summary><div class="info-content">
                <ul>
                    <li><code>/victime</code> : Pour être repéré par les secours au sol.</li>
                    <li><code>/constantes</code> : Donne vos infos santé (chat).</li>
                    <li><code>/startfire</code> : Allume un feu paramétrable.</li>
                    <li><code>/info fb [TEXTE]</code> : Faire un post Facebook RP.</li>
                    <li><code>/appel [SERVICE] [TEXTE]</code> : Appeler un service (sans opérateur).</li>
                    <li><code>/dv</code> : Supprime le véhicule où vous êtes.</li>
                </ul>
             </div></details>

             <details><summary>Salons Discord à suivre</summary><div class="info-content">
                <p>Consultez régulièrement ces salons pour rester informé et préparer vos sessions :</p>
                
                <a href="discord://discord.com/channels/293135138517286922/293140024751292419" class="discord-channel-link">
                    <span class="icon">#</span><span class="name">🔷informations-générales</span>
                </a>
                <a href="discord://discord.com/channels/293135138517286922/766337485374488626" class="discord-channel-link">
                    <span class="icon">#</span><span class="name">📆-présence-ce-soir</span>
                </a>
                <a href="discord://discord.com/channels/293135138517286922/877317216725516368" class="discord-channel-link">
                    <span class="icon">#</span><span class="name">🤕・aide-interventions</span>
                </a>
                <a href="discord://discord.com/channels/293135138517286922/319232458899980288" class="discord-channel-link">
                    <span class="icon">#</span><span class="name">🛑absences</span>
                </a>
             </div></details>
        </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    
    const playerStatusDot = document.getElementById('player-status-dot');
    const playerStatusText = document.getElementById('player-status-text');
    const sessionStatusDot = document.getElementById('session-status-dot');
    const sessionStatusText = document.getElementById('session-status-text');
    const playtimeDisplay = document.getElementById('playtime-display');
    const playtimeBar = document.getElementById('playtime-bar');

    async function updateStatus() {
        try {
            const response = await fetch('/api_get_player_status');
            if (!response.ok) return;

            const data = await response.json();

            playerStatusDot.className = `status-dot ${data.is_player_online ? 'online' : 'offline'}`;
            playerStatusText.textContent = data.is_player_online ? 'Connecté actuellement' : 'Déconnecté';

            sessionStatusDot.className = `status-dot ${data.is_session_time ? 'active-time' : 'inactive-time'}`;
            sessionStatusText.textContent = data.is_session_time ? 'Temps de jeu enregistré' : 'Hors des heures de session';

            playtimeDisplay.textContent = `${data.formatted_playtime} / 10h 00m`;
            const progressPercentage = Math.min(100, (data.total_playtime_seconds / data.playtime_goal_seconds) * 100);
            playtimeBar.style.width = `${progressPercentage}%`;

        } catch (error) {
            console.error("Erreur lors de la mise à jour du statut:", error);
        }
    }

    updateStatus();
    setInterval(updateStatus, 10000);

});
</script>

</body>
</html>