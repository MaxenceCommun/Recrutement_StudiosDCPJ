<?php
// On démarre la session en tout premier, avant toute autre instruction.
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}
// On démarre la mise en mémoire tampon pour gérer les en-têtes.
ob_start();

require_once __DIR__.'/config.php';
require_once __DIR__.'/bdd.php';
require_once __DIR__.'/api_discord.php';

// On récupère les questions (DB avec fallback config)
$QUIZ_QUESTIONS = get_quiz_questions();

// *** SÉCURITÉ : AUTHENTIFICATION DISCORD OBLIGATOIRE ***
if (empty($_SESSION['user'])) {
    header('Location: connexion_discord.php?return_to=' . urlencode('integration'));
    exit;
}
$user_id = $_SESSION['user']['id'];

$pdo = db();
// On cherche la candidature de l'utilisateur connecté avec un statut d'intégration
$st = $pdo->prepare("SELECT a.*, u.discord_id, u.id as user_id FROM applications a LEFT JOIN users u ON u.id=a.user_id WHERE a.user_id=? AND a.status IN ('accepte_integration','quiz_done') ORDER BY a.created_at DESC LIMIT 1");
$st->execute([$user_id]);
$app = $st->fetch(PDO::FETCH_ASSOC);

if (!$app) {
    // Pas de candidature en intégration, on redirige
    header('Location: /ma_candidature');
    exit;
}

// *** LOGIQUE DE PROGRESSION BASÉE SUR LA BDD ***
$db_current_step = (int)($app['integration_step'] ?? 1);

// Navigation "Précédent" via l'URL
$displayStep = $db_current_step;
if (isset($_GET['step'])) {
    $requestedStep = (int)$_GET['step'];
    // On n'autorise que de revenir en arrière
    if ($requestedStep < $db_current_step) {
        $displayStep = $requestedStep;
    } else {
        // Si on essaie de tricher via l'URL, on revient à l'étape réelle
        header('Location: /integration');
        exit;
    }
}

// --- TRAITEMENT DES FORMULAIRES (POST) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $candidature_url = ($GLOBALS['APP_BASE_URL'] ?? '') . '/ma_candidature';

    // ACTION: Validation des étapes de lecture (1 à 5)
    if (isset($_POST['validate_step'])) {
        $step_validated = (int)$_POST['validate_step'];
        if ($step_validated >= 1 && $step_validated <= 5) {
            // Mise à jour de l'étape uniquement si on est sur la bonne étape (évite les bugs)
            $pdo->prepare("UPDATE applications SET integration_step = integration_step + 1 WHERE id = ? AND integration_step = ?")->execute([$app['id'], $step_validated]);
            
            header('Location: /integration');
            exit;
        }
    }
    
    // ACTION: Soumission du quiz principal (étape 6)
    elseif (isset($_POST['submit_quiz']) && (int)$db_current_step === 6) {
        if (!isset($_SESSION['quiz_questions_for_'.$app['id']])) {
            $selected_questions = [];
            foreach ($QUIZ_QUESTIONS as $section_id => $questions) { if (!empty($questions)) { $rand_key = array_rand($questions); $selected_questions[$section_id] = ['id' => $rand_key, 'section' => $section_id]; } }
            $_SESSION['quiz_questions_for_'.$app['id']] = $selected_questions;
            $_SESSION['quiz_attempt_for_'.$app['id']] = $_SESSION['quiz_attempt_for_'.$app['id']] ?? 1;
        }
        $user_answers = $_POST['answers'] ?? [];
        $errors = [];
        foreach ($_SESSION['quiz_questions_for_'.$app['id']] as $section_id => $q_info) {
            $question = $QUIZ_QUESTIONS[$q_info['section']][$q_info['id']];
            if (!isset($user_answers[$section_id]) || (int)$user_answers[$section_id] !== $question['answer']) { $errors[] = $section_id; }
        }
        $error_count = count($errors);

        if ($error_count === 0) { // Succès
            $pdo->prepare("UPDATE applications SET status='quiz_done', integration_step = 8, updated_at=NOW() WHERE id=?")->execute([$app['id']]);
            discord_send_dm($app['discord_id'], "🎉 Félicitations ! Vous avez réussi le questionnaire d'intégration. Il ne vous reste plus qu'à rejoindre notre serveur Discord pour finaliser votre parcours.");
        } elseif ($error_count === 1) { // Rattrapage
            $failed_section_id = $errors[0];
            $questions_for_section = $QUIZ_QUESTIONS[$failed_section_id];
            $new_question_key = array_rand($questions_for_section);
            $_SESSION['quiz_retake_for_'.$app['id']] = ['section_id' => $failed_section_id, 'question' => $questions_for_section[$new_question_key]];
            $pdo->prepare("UPDATE applications SET integration_step = 7 WHERE id = ?")->execute([$app['id']]);
        } else { // Trop d'erreurs
            if (($_SESSION['quiz_attempt_for_'.$app['id']] ?? 1) >= 2) { // Échec final
                $pdo->prepare("UPDATE applications SET status='refuse', rejection_reason='Erreurs multiples au questionnaire', updated_at=NOW() WHERE id=?")->execute([$app['id']]);
                discord_send_dm($app['discord_id'], "❌ Malheureusement, vous avez fait trop d'erreurs au questionnaire. Votre candidature a été refusée.\n[Cliquez ici pour voir les détails]({$candidature_url})");
                unset($_SESSION['quiz_questions_for_'.$app['id']], $_SESSION['quiz_attempt_for_'.$app['id']], $_SESSION['quiz_retake_for_'.$app['id']]);
                session_write_close();
                header('Location: ' . $candidature_url . '?quiz=failed');
                exit;
            } else { // Deuxième chance
                $_SESSION['quiz_attempt_for_'.$app['id']] = 2;
                unset($_SESSION['quiz_questions_for_'.$app['id']]);
                $pdo->prepare("UPDATE applications SET integration_step = 6 WHERE id = ?")->execute([$app['id']]);
                session_write_close();
                header('Location: /integration?retry=1');
                exit;
            }
        }
        session_write_close();
        header('Location: /integration');
        exit;
    }
    
    // ACTION: Soumission du rattrapage (étape 7)
    elseif (isset($_POST['submit_retake']) && (int)$db_current_step === 7) {
        if (isset($_SESSION['quiz_retake_for_'.$app['id']])) {
            $retake_data = $_SESSION['quiz_retake_for_'.$app['id']];
            $user_answer = (int)($_POST['retake_answer'] ?? -1);
            if ($user_answer === $retake_data['question']['answer']) {
                $pdo->prepare("UPDATE applications SET status='quiz_done', integration_step = 8, updated_at=NOW() WHERE id=?")->execute([$app['id']]);
                unset($_SESSION['quiz_retake_for_'.$app['id']], $_SESSION['quiz_questions_for_'.$app['id']]);
                discord_send_dm($app['discord_id'], "🎉 Félicitations ! Vous avez réussi le rattrapage. Rejoignez le Discord pour terminer.");
            } else {
                $pdo->prepare("UPDATE applications SET status='refuse', rejection_reason='Échec au rattrapage du questionnaire', updated_at=NOW() WHERE id=?")->execute([$app['id']]);
                $pdo->prepare("UPDATE users SET status='refuse' WHERE id=?")->execute([$app['user_id']]);
                discord_send_dm($app['discord_id'], "❌ Échec au rattrapage. Votre candidature a été refusée.");
                header('Location: ' . $candidature_url . '?quiz=failed');
                exit;
            }
        }
    }

    // ACTION: Vérification présence Discord (étape 8)
    elseif (isset($_POST['verify_discord_presence']) && $db_current_step == 8) {
        try {
            if (discord_is_user_in_guild($app['discord_id'], $GLOBALS['DISCORD_GUILD_PUBLIC_ID'])) {
                $_SESSION['discord_verified_for_' . $app['id']] = true;
            } else {
                $_SESSION['flash_error'] = "Vous n'êtes pas encore sur le serveur Discord. Veuillez le rejoindre via le lien.";
            }
        } catch (Exception $e) {
            $_SESSION['flash_error'] = "Erreur lors de la vérification Discord.";
        }
    }

    // ACTION: Finalisation (étape 8)
    elseif (isset($_POST['finalize_integration']) && $db_current_step == 8) {
        $pdo->prepare("UPDATE applications SET status='essai', integration_step = 9, updated_at=NOW() WHERE id=?")->execute([$app['id']]);
        $pdo->prepare("UPDATE users SET status='essai', trial_start_date=NOW() WHERE id=?")->execute([$app['user_id']]);
        $_SESSION['user']['status'] = 'essai';
        
        // Attribution des rôles Discord
        if (!empty($GLOBALS['DISCORD_PLAYER_ROLE_ID'])) {
            try { discord_add_role($app['discord_id'], $GLOBALS['DISCORD_PLAYER_ROLE_ID']); } catch(Exception $e) { error_log("Erreur rôle joueur: ".$e->getMessage()); }
        }
        if (!empty($GLOBALS['DISCORD_TRIAL_ROLE_ID'])) {
            try { discord_add_role($app['discord_id'], $GLOBALS['DISCORD_TRIAL_ROLE_ID']); } catch(Exception $e) { error_log("Erreur rôle PE: ".$e->getMessage()); }
        }
        if (!empty($GLOBALS['DISCORD_NON_MEMBER_ROLE_ID'])) {
            try { discord_add_role($app['discord_id'], $GLOBALS['DISCORD_NON_MEMBER_ROLE_ID']); } catch(Exception $e) { error_log("Erreur rôle non-adhérent: ".$e->getMessage()); }
        }
        if (!empty($GLOBALS['DISCORD_INTEGRATION_ROLE_ID'])) {
            try { discord_remove_role($app['discord_id'], $GLOBALS['DISCORD_INTEGRATION_ROLE_ID']); } catch(Exception $e) { error_log("Erreur retrait rôle integration: ".$e->getMessage()); }
        }

        discord_send_dm($app['discord_id'], "🚀 Intégration terminée ! Bienvenue en période d'essai sur les Studios DCPJ. Vous avez maintenant accès aux salons Joueurs.");
        header('Location: /mon_suivi');
        exit;
    }
    
    // Fallback
    session_write_close();
    header('Location: /integration');
    exit;
}

// --- VARIABLES POUR L'AFFICHAGE ---
$play_intro_video = false;
if ($displayStep == 1 && !isset($_SESSION['intro_played_for_' . $app['id']])) {
    $play_intro_video = true;
    $_SESSION['intro_played_for_' . $app['id']] = true;
}

$is_discord_verified = $_SESSION['discord_verified_for_' . $app['id']] ?? false;
$invite_link = '';
if ($db_current_step == 8 && !$is_discord_verified) {
    try {
        if (!empty($GLOBALS['DISCORD_INTEGRATION_ROLE_ID'])) {
            try { 
                discord_add_role($app['discord_id'], $GLOBALS['DISCORD_INTEGRATION_ROLE_ID']); 
            } catch (Exception $e) { 
                error_log("Erreur ajout rôle integration #{$app['id']}: " . $e->getMessage()); 
            }
        }
        
        if (!empty($GLOBALS['DISCORD_WELCOME_CHANNEL_ID'])) {
            try {
                $invite_link = discord_create_invite($GLOBALS['DISCORD_WELCOME_CHANNEL_ID']);
            } catch (Exception $e) {
                error_log("API Discord Invite failed #{$app['id']}: " . $e->getMessage());
                // Fallback sur une invitation générique si l'API échoue
                if (!empty($GLOBALS['DISCORD_SERVER_INVITE_LINK']) && $GLOBALS['DISCORD_SERVER_INVITE_LINK'] !== 'VOTRE_LIEN_INVITATION_DISCORD') {
                    $invite_link = $GLOBALS['DISCORD_SERVER_INVITE_LINK'];
                } else {
                    throw $e; // On relance si aucune alternative n'existe
                }
            }
        } else {
            throw new Exception("DISCORD_WELCOME_CHANNEL_ID n'est pas configuré.");
        }
    } catch (Exception $e) {
        $invite_link = '#';
        $_SESSION['flash_error'] = "Erreur technique lors de la création de l'invitation Discord. Veuillez essayer manuellement ou contacter un administrateur.";
        error_log("Erreur critique invitation #{$app['id']}: " . $e->getMessage());
    }
}

// On envoie le contenu du tampon de sortie à la toute fin
ob_end_flush();
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Intégration — Studios DCPJ</title>
    <link rel="icon" type="image/png" href="assets/logo.png">
    <link rel="stylesheet" href="assets/styles.css">
    <style>
        :root { --onboarding-max-width: 1100px; } 
        body { background-color: var(--bg-secondary); }
        .wrap.page-container { max-width: var(--onboarding-max-width); } 
        .progress-container { background: var(--bg-primary); border: 1px solid var(--border-color); border-radius: 20px; padding: 30px; margin-bottom: 25px; }
        .progress-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; } 
        .progress-title { font-size: 1.5rem; font-weight: 700; }
        .progress-step-indicator { color: var(--text-secondary); } 
        .progress-bar { display: flex; gap: 8px; }
        .progress-segment { flex: 1; height: 8px; background: var(--bg-tertiary); border-radius: 4px; transition: all 0.4s ease; }
        .progress-segment.completed { background: var(--accent-success); } 
        .progress-segment.active { background: var(--accent-secondary); }
        .info-box { background: rgba(52, 152, 219, 0.1); border-left: 4px solid var(--accent-secondary); color: #a5b4fc; border-radius: 12px; padding: 20px; margin-bottom: 40px; display: flex; align-items: center; gap: 16px; }
        .info-box-icon { font-size: 2rem; flex-shrink: 0; } 
        .section-content { display: none; background: var(--bg-primary); padding: 40px; border-radius: 20px; }
        .section-content.active { display: block; animation: fadeIn 0.5s ease-out; } 
        @keyframes fadeIn { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }
        .section-header { margin-bottom: 40px; text-align: center; } 
        .section-header h2 { font-size: 2.8rem; font-weight: 800; margin-bottom: 8px; }
        .section-header p { color: var(--text-secondary); font-size: 1.2rem; max-width: 700px; margin: auto; }
        .content-layout { display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 24px; }
        .content-card { background: var(--bg-secondary); border-radius: 16px; padding: 24px; border: 1px solid var(--border-color); }
        .content-card h3 { display: flex; align-items: center; gap: 12px; font-size: 1.3rem; color: var(--accent-tertiary); margin-bottom: 12px; }
        .content-card p, .content-card li { color: var(--text-secondary); line-height: 1.7; } 
        .content-card ul { list-style: none; padding: 0; margin-top: 10px; }
        .content-card ul li { 
            background: rgba(255, 255, 255, 0.02); 
            border: 1px solid var(--border-color); 
            padding: 8px 12px; 
            border-radius: 8px; 
            margin-bottom: 6px;
            border-left: 3px solid var(--accent-secondary);
        }
        .action-buttons { display: flex; align-items: center; justify-content: flex-end; margin-top: 40px; padding-top: 24px; border-top: 1px solid var(--border-color); }
        .btn-validate { background: var(--gradient-primary); color: white; border: none; padding: 12px 28px; font-weight: 600; cursor: pointer; border-radius: 12px; }
        .button-radios { display: flex; flex-wrap: wrap; gap: 12px; flex-direction: column; } 
        .button-radios input[type="radio"] { display: none; }
        .button-radios label { flex: 1; display: inline-block; padding: 12px 20px; border: 1px solid var(--border-color); background: var(--bg-secondary); border-radius: 12px; cursor: pointer; text-align: left; }
        .button-radios label:hover { border-color: var(--border-hover); background: var(--bg-tertiary); }
        .button-radios input[type="radio"]:checked + label { background: var(--accent-secondary); border-color: var(--accent-secondary); color: white; font-weight: 600; }
        .discord-channel-link {
            display: flex;
            align-items: center;
            padding: 12px 16px;
            background: rgba(255, 255, 255, 0.03);
            border: 1px solid var(--border-color);
            border-radius: 12px;
            color: #e2e8f0;
            text-decoration: none !important;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            font-weight: 500;
            cursor: pointer;
        }
        .discord-channel-link:hover {
            background: rgba(52, 152, 219, 0.1);
            border-color: var(--accent-secondary);
            color: white;
            transform: translateX(6px);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
        }
        .discord-channel-link .icon {
            color: var(--accent-secondary);
            font-family: monospace;
            font-weight: bold;
            font-size: 1.2rem;
            margin-right: 8px;
        }
        .discord-channel-link.visited {
            background: rgba(46, 204, 113, 0.05);
            border-color: var(--accent-success);
        }
        .discord-channel-link.visited .icon {
            color: var(--accent-success);
        }
    </style>
</head>
<body>
    <?php include __DIR__.'/partiels_entete.php'; ?>
    
    <div class="wrap page-container <?php echo $play_intro_video ? 'hidden-by-video' : ''; ?>" id="mainContent">
        <?php $current_step_status = $app['status'] ?? 'accepte_integration'; include __DIR__.'/partiels_stepper.php'; ?>
        <div class="progress-container">
            <div class="progress-header">
                <h1 class="progress-title">Parcours d'Intégration</h1>
                <span class="progress-step-indicator">
                    <?php 
                        if ($displayStep <= 5) echo "Lecture (Étape " . floor($displayStep) . " sur 5)";
                        elseif ($displayStep == 6) echo "Questionnaire Final";
                        elseif ($displayStep == 7) echo "Questionnaire (Rattrapage)";
                        elseif ($displayStep >= 8) echo "Finalisation";
                    ?>
                </span>
            </div>
            <div class="progress-bar">
                <?php for ($i = 1; $i <= 5; $i++): 
                    $segment_class = ($displayStep > $i || $displayStep >= 6) ? 'completed' : (($displayStep == $i) ? 'active' : '');
                ?>
                    <div class="progress-segment <?= $segment_class ?>"></div>
                <?php endfor; ?>
            </div>
        </div>
        
        <?php if ($displayStep == 1): ?>
        <div class="info-box"><span class="info-box-icon">⚠️</span><div><strong>Information importante :</strong> Prenez le temps de lire attentivement chaque section. À la fin, un questionnaire vérifiera votre compréhension. Des erreurs répétées pourraient entraîner l'annulation de votre candidature.</div></div>
        <?php endif; ?>

        <?php if (isset($_SESSION['flash_error']) && !empty($_SESSION['flash_error'])): ?>
            <div class="alert alert-error mb-3"><?= htmlspecialchars($_SESSION['flash_error']) ?></div>
            <?php unset($_SESSION['flash_error']); ?>
        <?php endif; ?>
        
        <div id="step-1" class="section-content <?= $displayStep == 1 ? 'active' : '' ?>">
            <div class="section-header"><h2>1. Fonctionnement du serveur</h2><p>Découvrez l'univers des Studios DCPJ et notre approche unique de la simulation.</p></div>
            <div class="content-layout">
                <div class="content-card"><h3>🚒 Notre Mission</h3><p>Les Studios DCPJ sont une plateforme de simulation des services de secours français. Notre objectif est de promouvoir et faire découvrir leur travail à travers le roleplay sur FiveM, dans un cadre pédagogique et réaliste.</p></div>
                <div class="content-card"><h3>🎭 Civils Plastrons</h3><p>Contrairement au RP "Life", nos civils ne sont pas bloqués dans un personnage unique. Ils changent de rôle à chaque scène pour créer des interventions variées et donner vie au serveur.</p></div>
                <div class="content-card"><h3>📍 Zone d'intervention</h3><p>Notre serveur est basé dans le département du Val d'Aure et sa ville principale, Mistral. Nous utilisons un système de zones (Nord/Sud) pour alterner entre interventions urbaines et rurales, selon un planning défini.</p></div>
            </div>
            <div class="action-buttons"><form method="post"><button type="submit" name="validate_step" value="1" class="btn btn-validate">Section lue, passer à la suite →</button></form></div>
        </div>
        <div id="step-2" class="section-content <?= $displayStep == 2 ? 'active' : '' ?>">
            <div class="section-header"><h2>2. Les Pôles de Simulation</h2><p>Notre serveur s'articule autour de trois pôles complémentaires et interdépendants.</p></div>
            <div class="content-layout">
                <div class="content-card"><h3>👥 Pôle Civil</h3><p>Le cœur du serveur. Les civils créent les scénarios et les interventions qui rythment la vie de la simulation. Leur créativité est essentielle pour l'immersion de tous.</p></div>
                <div class="content-card"><h3>👮 Pôle Forces de l'Ordre</h3><p>Regroupe la Police et la Gendarmerie. Une simulation aboutie grâce aux mécaniques de FiveM pour la conduite, les interpellations et l'utilisation de l'équipement.</p></div>
                <div class="content-card"><h3>🚑 Pôle Secours</h3><p>Comprend les Sapeurs-Pompiers et le SAMU. Ils collaborent pour gérer les urgences, des gestes de secours réalistes sur victimes à la lutte contre les incendies.</p></div>
            </div>
            <div class="action-buttons" style="justify-content: space-between;"><a href="?step=1" class="btn">← Précédent</a><form method="post"><button type="submit" name="validate_step" value="2" class="btn btn-validate">Section lue, passer à la suite →</button></form></div>
        </div>
        <div id="step-3" class="section-content <?= $displayStep == 3 ? 'active' : '' ?>">
            <div class="section-header"><h2>3. Mon Rôle de Civil</h2><p>Votre mission principale : générer des interventions réalistes et adaptées aux effectifs.</p></div>
            <div class="content-layout" style="grid-template-columns: 1fr 1fr;"><div class="content-card"><h3>1️⃣ Analyser</h3><p>Avant chaque session (21h), consultez les plannings sur Discord pour connaître les effectifs des services de secours qui seront disponibles.</p></div><div class="content-card"><h3>2️⃣ Dimensionner</h3><p>Adaptez la complexité et la gravité de votre intervention au nombre de joueurs présents. Une scène trop grande peut paralyser le serveur.</p></div><div class="content-card"><h3>3️⃣ Diversifier</h3><p>Variez les scénarios (accidents, malaises, incendies...). Le salon Discord `#aide-interventions` est une excellente source d'inspiration.</p></div><div class="content-card"><h3>4️⃣ Coordonner</h3><p>N'hésitez pas à communiquer avec les autres civils pour créer des scènes cohérentes à plusieurs (témoin, victime, agresseur) pour plus de réalisme.</p></div></div>
            <div class="action-buttons" style="justify-content: space-between;"><a href="?step=2" class="btn">← Précédent</a><form method="post"><button type="submit" name="validate_step" value="3" class="btn btn-validate">Section lue, passer à la suite →</button></form></div>
        </div>
        <div id="step-4" class="section-content <?= $displayStep == 4 ? 'active' : '' ?>">
            <div class="section-header"><h2>4. Les Outils à Disposition</h2><p>Les commandes et raccourcis essentiels pour créer vos interventions en jeu.</p></div>
            <div class="content-layout">
                <div class="content-card" style="grid-column: 1 / -1;">
                    <h3>⚙️ Les Menus Principaux (touches F)</h3>
                    <p><strong>F1 : vMenu</strong> - Ce menu principal te donne accès à une multitude d'options. Le contenu varie en fonction de ton rôle (civil, policier, pompier, etc.).</p>
                    <p><strong>F2 : Noclip</strong> - Réservée aux services de secours. Elle permet de se déplacer librement à travers les bâtiments pour les reconnaissances.</p>
                </div>
                <div class="content-card">
                    <h3>🕹️ Menus & Commandes de jeu</h3>
                    <ul>
                        <li><strong>1</strong> : Menu Interactif (Props / Accessoires)</li>
                        <li><strong>2</strong> : Menu Personnel (Vêtements, ID, etc.)</li>
                        <li><strong>3</strong> : Téléphone (Appels RP sans opérateur)</li>
                        <li><strong>4</strong> : Menu d'animations (<code>/emotebind</code> pour sauvegarder)</li>
                        <li><strong>5</strong> : Menu des documents</li>
                        <li><strong>6 / 7</strong> : Freeze / Unfreeze un véhicule</li>
                        <li><strong>8</strong> : Menu Plastron (Véhicules, armes, tenues pour civils)</li>
                    </ul>
                </div>
                <div class="content-card">
                    <h3>🚶 Raccourcis & Postures</h3>
                    <ul>
                        <li><strong>= / ,</strong> : Menus de job secondaire / principal</li>
                        <li><strong>VERR. MAJ</strong> : Se coucher sur le ventre</li>
                        <li><strong>CTRL / ALT</strong> : Se baisser / S'accroupir</li>
                        <li><strong>B</strong> : Allumer ses phares en journée</li>
                    </ul>
                </div>
                <div class="content-card" style="grid-column: 1 / -1;">
                    <h3>⌨️ Commandes utiles</h3>
                    <p><code>/dv</code> : Despawn le véhicule | <code>/appel [SERVICE] [TEXTE]</code> : Contacter un service</p>
                    <p><code>/info fb [TEXTE]</code> : Post Facebook RP | <code>/constantes</code> : Infos santé (pouls, respiration...)</p>
                    <p><code>/startfire</code> : Allumer un feu (rayon, flammes, explosion) | <code>/victime</code> : Commande à faire au sol</p>
                </div>
            </div>
            <div class="action-buttons" style="justify-content: space-between;"><a href="?step=3" class="btn">← Précédent</a><form method="post"><button type="submit" name="validate_step" value="4" class="btn btn-validate">Section lue, passer à la suite →</button></form></div>
        </div>
        <div id="step-5" class="section-content <?= $displayStep == 5 ? 'active' : '' ?>">
            <div class="section-header"><h2>5. La Période d'Essai</h2><p>Votre parcours d'intégration final avant de pouvoir postuler à un service d'urgence.</p></div>
            <div class="content-layout"><div class="content-card"><h3>⏱️ Durée</h3><p><strong>Minimum :</strong> 1 semaine ET 10 heures de jeu.<br><strong>Maximum :</strong> 1 mois. Ce rythme flexible permet à chacun de s'intégrer correctement.</p></div><div class="content-card"><h3>🎯 Objectifs</h3><ul><li>Maîtriser les outils et commandes.</li><li>Comprendre et appliquer les attentes du RolePlay.</li><li>Créer des interventions de qualité pour les services.</li><li>S'intégrer à la communauté sur Discord et en jeu.</li></ul></div><div class="content-card"><h3>🎓 Après l'essai</h3><p>Une fois votre période d'essai validée par l'équipe, vous pourrez enfin postuler aux services de secours (Pompiers, SAMU, Police...) et, si vous le souhaitez, devenir membre de l'association.</p><p style="margin-top: 10px; font-size: 0.9rem; color: #cbd5e1; font-style: italic;">Note : Il est tout à fait possible de postuler en parallèle dans un pôle durant votre période d'essai (PE).</p></div></div>
            <div class="action-buttons" style="justify-content: space-between;"><a href="?step=4" class="btn">← Précédent</a><form method="post"><button type="submit" name="validate_step" value="5" class="btn btn-validate">Terminer la lecture et passer au quiz →</button></form></div>
        </div>
        
        <div id="step-6" class="section-content <?= $displayStep == 6 ? 'active' : '' ?>">
            <div class="section-header"><h2>Questionnaire de Validation</h2><p>Répondez à ces questions pour finaliser votre intégration.</p></div>
            <?php if (isset($_GET['retry'])): ?>
                <div class="info-box" style="background: rgba(220, 38, 38, 0.1); border-left-color: var(--accent-red); color: #fca5a5;"><span class="info-box-icon">🔁</span><div><strong>Seconde Chance :</strong> Vous avez fait trop d'erreurs. De nouvelles questions vous sont posées. Concentrez-vous bien, c'est votre dernière tentative !</div></div>
            <?php endif; ?>
            
            <?php if ($displayStep == 6): ?>
            <form method="post">
                <input type="hidden" name="submit_quiz" value="1">
                <?php 
                if (!isset($_SESSION['quiz_questions_for_'.$app['id']])) {
                    $selected_questions = [];
                    if (isset($QUIZ_QUESTIONS) && is_array($QUIZ_QUESTIONS)) {
                        foreach ($QUIZ_QUESTIONS as $section_id => $questions) {
                            if (!empty($questions)) {
                                $rand_key = array_rand($questions);
                                $selected_questions[$section_id] = ['id' => $rand_key, 'section' => $section_id];
                            }
                        }
                    }
                    $_SESSION['quiz_questions_for_'.$app['id']] = $selected_questions;
                }
                
                if (isset($_SESSION['quiz_questions_for_'.$app['id']]) && is_array($_SESSION['quiz_questions_for_'.$app['id']])) {
                    foreach ($_SESSION['quiz_questions_for_'.$app['id']] as $section_id => $q_info) {
                        if (isset($q_info['section'], $q_info['id']) && isset($QUIZ_QUESTIONS[$q_info['section']][$q_info['id']])) {
                            $q = $QUIZ_QUESTIONS[$q_info['section']][$q_info['id']];
                            echo '<div class="form-group" style="margin-bottom: 30px;">';
                            echo '<label class="required" style="font-size: 1.2rem; margin-bottom: 15px;">' . htmlspecialchars($q['question']) . '</label>';
                            echo '<div class="button-radios">';
                            foreach ($q['options'] as $key => $option) {
                                echo '<input type="radio" id="q'.$section_id.'-opt'.$key.'" name="answers['.$section_id.']" value="'.$key.'" required>';
                                echo '<label for="q'.$section_id.'-opt'.$key.'">' . htmlspecialchars($option) . '</label>';
                            }
                            echo '</div></div>';
                        }
                    }
                }
                ?>
                <div class="action-buttons"><button type="submit" class="btn btn-primary">Valider mes réponses</button></div>
            </form>
            <?php endif; ?>

            <?php if ($displayStep == 7): ?>
            <div class="info-box" style="background: rgba(243, 156, 18, 0.1); border-left-color: var(--accent-tertiary); color: #fce2b3;"><span class="info-box-icon">💡</span><div><strong>Une seule erreur, pas de panique !</strong> Répondez correctement à cette nouvelle question sur le même thème pour valider votre intégration.</div></div>
            <?php if (isset($_SESSION['quiz_retake_for_'.$app['id']])): $retake_data = $_SESSION['quiz_retake_for_'.$app['id']]; $q = $retake_data['question']; ?>
            <form method="post">
                <input type="hidden" name="submit_retake" value="1">
                <div class="form-group" style="margin-bottom: 30px;">
                    <label class="required" style="font-size: 1.2rem; margin-bottom: 15px;"><?= htmlspecialchars($q['question']) ?></label>
                    <div class="button-radios">
                        <?php foreach ($q['options'] as $key => $option): ?>
                            <input type="radio" id="retake-opt<?=$key?>" name="retake_answer" value="<?=$key?>" required>
                            <label for="retake-opt<?=$key?>"><?= htmlspecialchars($option) ?></label>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div class="action-buttons"><button type="submit" class="btn btn-primary">Valider ma réponse</button></div>
            </form>
            <?php endif; ?>
            <?php endif; ?>
        </div>
        
        <div id="step-7" class="section-content <?= $displayStep == 8 ? 'active' : '' ?>">
            <div class="section-header"><h2>Dernière Étape : Rejoindre le Discord (privé)</h2></div>
            <?php if (!$is_discord_verified): ?>
                <p class="text-center text-secondary">Félicitations, vous avez réussi le quiz ! Pour finaliser votre intégration, rejoignez notre serveur Discord (privé) via le lien unique ci-dessous.</p>
                <div class="info-box"><span class="info-box-icon">⚠️</span><div><strong>Information importante :</strong> Ce lien d'invitation est à usage unique et n'est valable que pour vous. Une fois sur le serveur, revenez sur cette page et cliquez sur le bouton de vérification.</div></div>
                <div style="text-align: center; margin: 40px 0;"><a href="<?= htmlspecialchars($invite_link) ?>" target="_blank" class="btn btn-primary" style="padding: 14px 28px; font-size: 1.2rem;">Rejoindre notre discord (privé)</a></div>
                <form method="post" class="mt-3">
                    <button type="submit" name="verify_discord_presence" class="btn btn-validate" style="width: 100%; justify-content: center; padding: 14px;">J'ai rejoint, vérifier ma présence →</button>
                </form>
            <?php else: 
                $channel_categories = [
                    'Association' => [
                        ['name' => '📜journal-officiel', 'url' => 'discord://discord.com/channels/293135138517286922/318782684727869451', 'desc' => 'Consultez les décisions et annonces officielles.'],
                        ['name' => 'ℹ-periode-essai', 'url' => 'discord://discord.com/channels/293135138517286922/937795965979598918', 'desc' => 'Toutes les informations sur le déroulement de votre période d\'essai.']
                    ],
                    'Informations' => [
                        ['name' => '🔷informations-générales', 'url' => 'discord://discord.com/channels/293135138517286922/293140024751292419', 'desc' => 'Annonces importantes concernant le serveur.'],
                        ['name' => '🛑absences', 'url' => 'discord://discord.com/channels/293135138517286922/319232458899980288', 'desc' => 'Signalez ici vos indisponibilités.']
                    ],
                    'Civils' => [
                        ['name' => '📆-présence-ce-soir', 'url' => 'discord://discord.com/channels/293135138517286922/766337485374488626', 'desc' => 'Indiquez votre présence pour les sessions du soir.'],
                        ['name' => '🤕・aide-interventions', 'url' => 'discord://discord.com/channels/293135138517286922/877317216725516368', 'desc' => 'Trouvez l\'inspiration pour vos scènes RP.']
                    ],
                ];
            ?>
                <div class="info-box"><span class="info-box-icon">✅</span><div><strong>Présence confirmée !</strong> Pour terminer, veuillez prendre connaissance des salons importants ci-dessous. Cliquez sur chaque lien pour le marquer comme lu.</div></div>
                <div class="content-layout">
                    <?php foreach ($channel_categories as $category => $channels): ?>
                        <div class="content-card">
                            <h3 style="color: var(--accent-secondary);"><?= htmlspecialchars($category) ?></h3>
                            <ul style="list-style-type: none; padding-left: 0; display: flex; flex-direction: column; gap: 8px;">
                                <?php foreach ($channels as $channel): ?>
                                    <li>
                                        <a href="<?= $channel['url'] ?>" target="_blank" rel="noopener noreferrer" class="discord-channel-link" data-channel-name="<?= htmlspecialchars($channel['name']) ?>">
                                            <span class="icon">#</span> <?= htmlspecialchars($channel['name']) ?> <span class="icon" style="margin-left: auto;"></span>
                                        </a>
                                        <p style="font-size: 0.85rem; color: var(--text-muted); margin-top: 4px; padding-left: 5px;"><?= htmlspecialchars($channel['desc']) ?></p>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    <?php endforeach; ?>
                </div>
                <form method="post" class="mt-3">
                    <input type="hidden" name="finalize_integration" value="1">
                    <button id="finalizeBtn" type="submit" class="btn btn-validate" style="width: 100%; justify-content: center; padding: 14px;" disabled>Veuillez consulter tous les salons ci-dessus</button>
                </form>
            <?php endif; ?>
        </div>

    </div>
    
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        // ... (Le JavaScript reste le même)
        const channelLinks = document.querySelectorAll('.discord-channel-link');
        const finalizeBtn = document.getElementById('finalizeBtn');
        if (channelLinks.length > 0 && finalizeBtn) {
            const visitedChannels = new Set();
            const requiredChannels = channelLinks.length;

            channelLinks.forEach(link => {
                link.addEventListener('click', () => {
                    const channelName = link.getAttribute('data-channel-name');
                    if (!visitedChannels.has(channelName)) {
                        visitedChannels.add(channelName);
                        link.classList.add('visited');
                        const iconSpan = link.querySelector('.icon[style*="margin-left"]');
                        if(iconSpan) iconSpan.textContent = '✅';
                    }
                    if (visitedChannels.size >= requiredChannels) {
                        finalizeBtn.disabled = false;
                        finalizeBtn.textContent = "J'ai lu le tutoriel, terminer l'intégration →";
                    }
                });
            });
        }

        document.querySelectorAll('form').forEach(form => {
            form.addEventListener('submit', function(e) {
                const buttons = form.querySelectorAll('button[type="submit"]');
                // On utilise un court délai pour laisser le temps au navigateur d'inclure le bouton dans le POST
                setTimeout(() => {
                    buttons.forEach(button => {
                        button.disabled = true;
                        if (!button.dataset.originalText) button.dataset.originalText = button.textContent;
                        button.textContent = 'Envoi...';
                    });
                }, 10);
            });
        });
    });
    </script>
</body>
</html>