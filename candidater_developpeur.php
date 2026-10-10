<?php
require_once __DIR__.'/config.php';
require_once __DIR__.'/bdd.php';
require_once __DIR__.'/api_discord.php';

// 1. Vérification de la connexion
if (empty($_SESSION['user'])) { 
    header('Location: /connexion_discord'); 
    exit; 
}

$pdo = db();
$user_id = $_SESSION['user']['id'];
$today = date('Y-m-d H:i:s');

// 1. Vérification interdiction FTA
$stmt_fta = $pdo->prepare("SELECT reason, system_tags_json FROM fta_sanctions WHERE user_id = ? AND sanction_type LIKE 'Exclusion%' ORDER BY start_date DESC");
$stmt_fta->execute([$user_id]);
foreach ($stmt_fta->fetchAll(PDO::FETCH_ASSOC) as $sanction) {
    $tags = json_decode($sanction['system_tags_json'] ?? '[]', true);
    foreach ($tags as $tag) {
        if ($tag['tag'] === 'Interdiction de postuler' && (empty($tag['end_date']) || $tag['end_date'] > $today)) {
            header('Location: /choisir_candidature');
            exit;
        }
    }
}

// 2. Vérification blocage utilisateur (can_reapply_after)
$stmt_u = $pdo->prepare("SELECT can_reapply_after FROM users WHERE id = ?");
$stmt_u->execute([$user_id]);
$u_row = $stmt_u->fetch(PDO::FETCH_ASSOC);
if ($u_row && !empty($u_row['can_reapply_after']) && new DateTime($u_row['can_reapply_after']) > new DateTime()) {
    header('Location: /choisir_candidature');
    exit;
}

// 3. SÉCURITÉ : Vérifier si une candidature est déjà active
$stmt_check = $pdo->prepare("SELECT id, status, updated_at FROM applications WHERE user_id = ? AND status != 'archive' ORDER BY created_at DESC LIMIT 1");
$stmt_check->execute([$user_id]);
$existing_app = $stmt_check->fetch(PDO::FETCH_ASSOC);

if ($existing_app) {
    if ($existing_app['status'] === 'refuse') {
        $reapply_date = (new DateTime($existing_app['updated_at']))->modify('+' . ($GLOBALS['APPLICATION_REJECTION_COOLDOWN'] ?? '1 month'));
        if ($reapply_date > new DateTime()) {
            header('Location: /choisir_candidature');
            exit;
        }
    } else {
        header('Location: /ma_candidature');
        exit;
    }
}

// 4. Traitement de la soumission du formulaire
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        http_response_code(403);
        exit('Erreur de validation de sécurité (CSRF invalide).');
    }
    unset($_SESSION['csrf_token']);

    // Nettoyage et récupération des liens du portfolio
    $raw_links = $_POST['portfolio_links'] ?? [];
    $portfolio_links = [];
    if (is_array($raw_links)) {
        foreach ($raw_links as $link) {
            $url = trim($link['url'] ?? '');
            $type = trim($link['type'] ?? 'Autre');
            $title = trim($link['title'] ?? '');
            if (!empty($url)) {
                $portfolio_links[] = [
                    'type' => htmlspecialchars($type),
                    'title' => htmlspecialchars($title ?: $type),
                    'url' => filter_var($url, FILTER_SANITIZE_URL)
                ];
            }
        }
    }

    // Récupération des images du portfolio uploadées
    $portfolio_images = [];
    if (!empty($_POST['portfolio_images']) && is_array($_POST['portfolio_images'])) {
        foreach ($_POST['portfolio_images'] as $img) {
            $cleaned = trim($img);
            if (str_starts_with($cleaned, 'uploads/portfolios/')) {
                $portfolio_images[] = $cleaned;
            }
        }
    }

    // Spécialités cochées
    $specialties = $_POST['specialties'] ?? [];
    if (!is_array($specialties)) $specialties = [];

    $answers = [
        'discord_pseudo'   => $_SESSION['user']['username'],
        'email'            => strtolower(trim($_POST['email'] ?? '')),
        'birthdate'        => trim($_POST['birthdate'] ?? ''),
        'specialties'      => $specialties,
        'experience_years' => trim($_POST['experience_years'] ?? ''),
        'frameworks'       => trim($_POST['frameworks'] ?? ''),
        'weekly_hours'     => trim($_POST['weekly_hours'] ?? ''),
        'motivations'      => trim($_POST['motivations'] ?? ''),
        'achievements'     => trim($_POST['achievements'] ?? ''),
        'portfolio_links'  => $portfolio_links,
        'portfolio_images' => $portfolio_images,
        'certify_exact'    => isset($_POST['certify_exact']),
        'accept_terms'     => isset($_POST['accept_terms']),
    ];

    $pdo->prepare("INSERT INTO applications (user_id, application_type, answers_json, status, recruiter_review_status) VALUES (?, 'developer', ?, 'attente', 'none')")
        ->execute([$_SESSION['user']['id'], json_encode($answers, JSON_UNESCAPED_UNICODE)]);
    $application_id = $pdo->lastInsertId();

    $pdo->prepare("UPDATE users SET status='attente' WHERE id=?")->execute([$_SESSION['user']['id']]);
    $_SESSION['user']['status'] = 'attente';

    // Discord Notifications
    global $APP_BASE_URL, $DISCORD_APP_CHANNEL_DEV, $DISCORD_NEW_APPLICATION_WEBHOOK_URL;
    $candidature_url = ($APP_BASE_URL ?? '') . '/ma_candidature';
    $recruiter_url   = ($APP_BASE_URL ?? '') . '/candidat?id=' . $application_id;

    // DM au candidat
    if (!empty($_SESSION['user']['discord_id'])) {
        $dm_txt = "✅ Bonjour " . ($_SESSION['user']['display_name'] ?? $_SESSION['user']['username']) . ",\n\n";
        $dm_txt .= "Votre candidature au poste de **Développeur** chez les Studios DCPJ a bien été reçue avec votre portfolio !\n";
        $dm_txt .= "Le pôle développement examinera vos réalisations dans les meilleurs délais.\n";
        $dm_txt .= "➡️ [Suivre mon dossier]({$candidature_url})\n\nMerci de votre intérêt pour nos projets !";
        try {
            discord_send_dm($_SESSION['user']['discord_id'], $dm_txt);
        } catch (Exception $e) {}
    }

    // Alerte Discord identique aux candidatures normales avec mention du rôle Développeur
    $specs_str = !empty($specialties) ? implode(', ', $specialties) : 'Non spécifié';
    $links_cnt = count($portfolio_links);
    $imgs_cnt  = count($portfolio_images);

    $details_embed = [
        'Pseudo Discord' => $_SESSION['user']['username'],
        'Spécialités' => $specs_str,
        'Expérience' => $answers['experience_years'] ?: 'N/A',
        'Disponibilité' => $answers['weekly_hours'] ?: 'N/A',
        'Portfolio' => "$links_cnt lien(s), $imgs_cnt capture(s)",
        'Réalisations' => mb_strimwidth($answers['achievements'] ?? 'N/A', 0, 300, '...'),
        'Motivations' => mb_strimwidth($answers['motivations'] ?? 'N/A', 0, 300, '...')
    ];

    $target_channel = !empty($GLOBALS['DISCORD_APP_CHANNEL_PLAYER']) ? $GLOBALS['DISCORD_APP_CHANNEL_PLAYER'] : ($GLOBALS['DISCORD_APP_CHANNEL_DEV'] ?? '');
    $role_mention = $GLOBALS['DISCORD_DEV_NOTIF_ROLE_ID'] ?? '1220533779031003197';

    if (!empty($target_channel)) {
        try {
            discord_send_application_message(
                $target_channel,
                $application_id,
                'developer',
                $_SESSION['user']['username'],
                $recruiter_url,
                true, // Boutons de vote interactifs (Pour, Neutre, Contre, Ouvrir le dossier)
                $role_mention, // Mention du rôle Discord 1220533779031003197
                "⏳ En attente de traitement",
                $details_embed
            );
        } catch (Exception $e) {}
    } elseif (!empty($GLOBALS['DISCORD_NEW_APPLICATION_WEBHOOK_URL'])) {
        try {
            $embed = [
                'title' => "💻 Nouvelle Candidature Développeur #$application_id",
                'description' => "Candidat : **" . htmlspecialchars($_SESSION['user']['username']) . "**\nSpécialités : `$specs_str`\nExpérience : " . htmlspecialchars($answers['experience_years']) . "\nPortfolio : **$links_cnt lien(s)** et **$imgs_cnt capture(s)**.",
                'color' => hexdec('9b59b6'),
                'fields' => [
                    ['name' => 'Lien Dossier', 'value' => "[Consulter la candidature]($recruiter_url)", 'inline' => true]
                ],
                'timestamp' => date('c')
            ];
            discord_send_webhook_message($GLOBALS['DISCORD_NEW_APPLICATION_WEBHOOK_URL'], "<@&$role_mention>", [$embed]);
        } catch (Exception $e) {}
    }

    header('Location: /ma_candidature');
    exit;
}

$_SESSION['csrf_token'] = bin2hex(random_bytes(32));
$csrf_token = $_SESSION['csrf_token'];
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Candidature Développeur — Studios DCPJ</title>
    <link rel="icon" type="image/png" href="assets/logo.png">
    <link rel="stylesheet" href="assets/styles.css">
    <style>
        .dev-form-hero {
            background: linear-gradient(135deg, rgba(155, 89, 182, 0.2) 0%, rgba(52, 152, 219, 0.2) 100%);
            border: 1px solid rgba(155, 89, 182, 0.4);
            border-radius: 16px;
            padding: 30px;
            margin-bottom: 30px;
            text-align: center;
        }
        .form-section {
            background: var(--bg-secondary);
            border: 1px solid var(--border-color);
            border-radius: 16px;
            padding: 24px;
            margin-bottom: 24px;
        }
        .form-section-title {
            display: flex;
            align-items: center;
            gap: 12px;
            font-size: 1.3rem;
            color: var(--accent-primary);
            margin-top: 0;
            margin-bottom: 18px;
            padding-bottom: 12px;
            border-bottom: 1px solid var(--border-color);
        }
        .specialties-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 12px;
            margin-bottom: 15px;
        }
        .spec-item {
            display: flex;
            align-items: center;
            gap: 10px;
            background: var(--bg-tertiary);
            padding: 12px 16px;
            border-radius: 10px;
            border: 1px solid var(--border-color);
            cursor: pointer;
            transition: all 0.2s ease;
        }
        .spec-item:hover {
            border-color: var(--accent-primary);
            background: rgba(52, 152, 219, 0.08);
        }
        .spec-item input[type="checkbox"] {
            width: 18px;
            height: 18px;
            cursor: pointer;
        }
        .dropzone-box {
            border: 2px dashed var(--accent-primary);
            border-radius: 14px;
            padding: 30px 20px;
            text-align: center;
            background: rgba(52, 152, 219, 0.03);
            cursor: pointer;
            transition: all 0.25s ease;
            position: relative;
        }
        .dropzone-box:hover, .dropzone-box.drag-over {
            background: rgba(52, 152, 219, 0.12);
            border-color: #2ecc71;
            transform: scale(1.005);
        }
        .portfolio-grid-preview {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(140px, 1fr));
            gap: 14px;
            margin-top: 20px;
        }
        .portfolio-thumb {
            position: relative;
            aspect-ratio: 16/10;
            border-radius: 10px;
            overflow: hidden;
            border: 1px solid var(--border-color);
            background: #000;
        }
        .portfolio-thumb img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            display: block;
        }
        .portfolio-thumb .remove-btn {
            position: absolute;
            top: 6px;
            right: 6px;
            background: rgba(231, 76, 60, 0.9);
            color: white;
            border: none;
            border-radius: 50%;
            width: 26px;
            height: 26px;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            font-weight: bold;
            font-size: 14px;
            transition: background 0.2s;
        }
        .portfolio-thumb .remove-btn:hover {
            background: #c0392b;
        }
        .link-row {
            display: flex;
            gap: 10px;
            margin-bottom: 12px;
            align-items: center;
            flex-wrap: wrap;
        }
        @media (max-width: 768px) {
            .link-row {
                flex-direction: column;
                align-items: stretch;
            }
        }
    </style>
</head>
<body>
<?php include __DIR__.'/partiels_entete.php'; ?>

<div class="wrap page-container" style="max-width: 900px;">
    <div class="dev-form-hero">
        <div style="font-size: 3rem; margin-bottom: 10px;">💻</div>
        <h2 style="margin: 0 0 10px 0;">Candidature Pôle Développement</h2>
        <p class="text-secondary" style="margin: 0 auto; max-width: 650px;">
            Rejoignez notre équipe technique et participez au développement de nos serveurs, scripts, outils web, bots et univers 3D. Présentez-nous votre travail et votre portfolio !
        </p>
    </div>

    <form method="POST" id="dev-application-form">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">

        <!-- 1. Informations générales -->
        <div class="form-section">
            <h3 class="form-section-title"><span>👤</span> Vos Informations</h3>
            
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-bottom: 16px;">
                <div>
                    <label style="display:block; font-weight:600; margin-bottom:6px; color:var(--text-secondary);">Pseudo Discord :</label>
                    <input type="text" class="card" value="<?= htmlspecialchars($_SESSION['user']['username']) ?>" disabled style="width: 100%; margin:0; opacity: 0.8;">
                </div>
                <div>
                    <label style="display:block; font-weight:600; margin-bottom:6px; color:var(--text-secondary);">Date de naissance : *</label>
                    <input type="date" name="birthdate" required class="card" style="width: 100%; margin:0;">
                </div>
            </div>

            <div>
                <label style="display:block; font-weight:600; margin-bottom:6px; color:var(--text-secondary);">Adresse Email : *</label>
                <input type="email" name="email" required placeholder="contact@exemple.fr" class="card" style="width: 100%; margin:0;">
            </div>
        </div>

        <!-- 2. Profil Technique -->
        <div class="form-section">
            <h3 class="form-section-title"><span>⚡</span> Vos Spécialités & Compétences</h3>
            <p class="text-secondary" style="margin-top:0; margin-bottom: 16px; font-size: 0.95rem;">
                Cochez vos domaines d'expertise principaux :
            </p>
            
            <div class="specialties-grid">
                <label class="spec-item"><input type="checkbox" name="specialties[]" value="LUA / FiveM Scripting"> <span>🎮 LUA FiveM</span></label>
                <label class="spec-item"><input type="checkbox" name="specialties[]" value="Web Frontend (HTML/CSS/JS/React)"> <span>🌐 Web Frontend</span></label>
                <label class="spec-item"><input type="checkbox" name="specialties[]" value="Web Backend (PHP/Node/Python)"> <span>⚙️ Web Backend</span></label>
                <label class="spec-item"><input type="checkbox" name="specialties[]" value="Bots Discord (JS/Python)"> <span>🤖 Bot Discord</span></label>
                <label class="spec-item"><input type="checkbox" name="specialties[]" value="Mapping 3D / Props (3ds Max/Blender)"> <span>🏗️ Mapping 3D</span></label>
                <label class="spec-item"><input type="checkbox" name="specialties[]" value="Base de données SQL / Architecture"> <span>🗄️ Base de données SQL</span></label>
                <label class="spec-item"><input type="checkbox" name="specialties[]" value="UI / UX & NUI FiveM"> <span>🎨 UI/UX & NUI</span></label>
                <label class="spec-item"><input type="checkbox" name="specialties[]" value="DevOps & Systèmes Linux"> <span>🐧 DevOps / Linux</span></label>
            </div>

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-top: 20px;">
                <div>
                    <label style="display:block; font-weight:600; margin-bottom:6px; color:var(--text-secondary);">Années d'expérience : *</label>
                    <select name="experience_years" required class="card" style="width: 100%; margin:0;">
                        <option value="Débutant (< 1 an)">Débutant (&lt; 1 an)</option>
                        <option value="1 à 2 ans">1 à 2 ans</option>
                        <option value="3 à 5 ans" selected>3 à 5 ans</option>
                        <option value="Plus de 5 ans">Plus de 5 ans</option>
                    </select>
                </div>
                <div>
                    <label style="display:block; font-weight:600; margin-bottom:6px; color:var(--text-secondary);">Temps disponible / semaine : *</label>
                    <select name="weekly_hours" required class="card" style="width: 100%; margin:0;">
                        <option value="2 à 5 heures / semaine">2 à 5 heures / semaine</option>
                        <option value="5 à 10 heures / semaine" selected>5 à 10 heures / semaine</option>
                        <option value="10 à 20 heures / semaine">10 à 20 heures / semaine</option>
                        <option value="Plus de 20 heures / semaine">Plus de 20 heures / semaine</option>
                    </select>
                </div>
            </div>

            <div style="margin-top: 16px;">
                <label style="display:block; font-weight:600; margin-bottom:6px; color:var(--text-secondary);">Frameworks, langages & technologies maîtrisés :</label>
                <input type="text" name="frameworks" placeholder="Ex: ESX, OxLib, React, Vue, PHP 8, MySQL, Git, Docker..." class="card" style="width: 100%; margin:0;">
            </div>
        </div>

        <!-- 3. Portfolio & Réalisations -->
        <div class="form-section">
            <h3 class="form-section-title"><span>📂</span> Portfolio, Liens & Captures d'Écran</h3>
            <p class="text-secondary" style="margin-top:0; margin-bottom: 18px; font-size: 0.95rem;">
                Fournissez vos liens et captures d'écran démontrant votre travail (interfaces, architectures de code, scripts, mappings, etc.).
            </p>

            <h4 style="margin: 15px 0 10px 0; color: var(--text-primary);">🔗 Liens de vos projets</h4>
            <div id="portfolio-links-container">
                <div class="link-row">
                    <select name="portfolio_links[0][type]" class="card" style="margin:0; width: 180px;">
                        <option value="GitHub">GitHub</option>
                        <option value="GitLab">GitLab</option>
                        <option value="Site Web">Site Web</option>
                        <option value="Vidéo Démo">Vidéo / YouTube</option>
                        <option value="Autre">Autre</option>
                    </select>
                    <input type="text" name="portfolio_links[0][title]" placeholder="Titre (ex: Mon Profil GitHub)" class="card" style="margin:0; width: 220px;">
                    <input type="url" name="portfolio_links[0][url]" placeholder="https://github.com/..." class="card" style="margin:0; flex:1;">
                </div>
                <div class="link-row">
                    <select name="portfolio_links[1][type]" class="card" style="margin:0; width: 180px;">
                        <option value="Site Web" selected>Site Web / Portfolio</option>
                        <option value="GitHub">GitHub</option>
                        <option value="GitLab">GitLab</option>
                        <option value="Vidéo Démo">Vidéo / YouTube</option>
                        <option value="Autre">Autre</option>
                    </select>
                    <input type="text" name="portfolio_links[1][title]" placeholder="Titre (ex: Portfolio en ligne)" class="card" style="margin:0; width: 220px;">
                    <input type="url" name="portfolio_links[1][url]" placeholder="https://..." class="card" style="margin:0; flex:1;">
                </div>
            </div>
            <button type="button" class="btn btn-sm" onclick="addLinkRow()" style="margin-top: 8px; border: 1px dashed var(--border-color);">+ Ajouter un autre lien</button>

            <h4 style="margin: 25px 0 10px 0; color: var(--text-primary);">🖼️ Captures d'écran & Images de réalisations</h4>
            <div class="dropzone-box" id="dev-dropzone" onclick="document.getElementById('portfolio-file-input').click();">
                <input type="file" id="portfolio-file-input" accept="image/png, image/jpeg, image/webp, image/gif" multiple style="display:none;">
                <div style="font-size: 2.2rem; margin-bottom: 8px;">📸</div>
                <strong style="font-size: 1.1rem; color: var(--text-primary); display:block; margin-bottom: 4px;">
                    Glissez-déposez vos images ici ou cliquez pour parcourir
                </strong>
                <p class="text-secondary" style="margin: 0; font-size: 0.9rem;">
                    💡 <strong>Astuce :</strong> Vous pouvez également faire un <kbd>Ctrl+V</kbd> n'importe où pour coller directement une capture d'écran !
                </p>
                <div id="upload-status" style="margin-top: 10px; font-weight: bold; font-size: 0.9rem; color: var(--accent-primary); display: none;"></div>
            </div>

            <div class="portfolio-grid-preview" id="portfolio-preview-grid">
                <!-- Les vignettes s'ajouteront ici dynamiquement -->
            </div>
        </div>

        <!-- 4. Motivations & Projets -->
        <div class="form-section">
            <h3 class="form-section-title"><span>💡</span> Motivations & Réalisations</h3>
            
            <div style="margin-bottom: 20px;">
                <label style="display:block; font-weight:600; margin-bottom:6px; color:var(--text-secondary);">
                    Décrivez un ou plusieurs projets dont vous êtes le plus fier : *
                </label>
                <textarea name="achievements" rows="4" required class="card" style="width:100%; margin:0;" placeholder="Parlez-nous des fonctionnalités techniques développées, des défis rencontrés et des solutions trouvées..."></textarea>
            </div>

            <div>
                <label style="display:block; font-weight:600; margin-bottom:6px; color:var(--text-secondary);">
                    Vos motivations pour rejoindre l'équipe des Studios DCPJ : *
                </label>
                <textarea name="motivations" rows="4" required class="card" style="width:100%; margin:0;" placeholder="Pourquoi vouloir contribuer au projet Studios DCPJ ? Qu'aimeriez-vous apporter ou apprendre ?"></textarea>
            </div>
        </div>

        <!-- 5. Validation & Engagement -->
        <div class="form-section">
            <label style="display: flex; align-items: center; gap: 10px; margin-bottom: 12px; cursor: pointer;">
                <input type="checkbox" name="certify_exact" required style="width: 18px; height: 18px;">
                <span>Je certifie sur l'honneur l'exactitude des informations et réalisations présentées.</span>
            </label>
            <label style="display: flex; align-items: center; gap: 10px; margin-bottom: 20px; cursor: pointer;">
                <input type="checkbox" name="accept_terms" required style="width: 18px; height: 18px;">
                <span>J'accepte le règlement interne et la charte de confidentialité des Studios DCPJ.</span>
            </label>

            <button type="submit" class="btn btn-primary" style="width: 100%; justify-content: center; padding: 15px; font-size: 1.15rem; gap: 10px;">
                <span>🚀</span> Envoyer ma candidature Développeur
            </button>
        </div>
    </form>
</div>

<script>
const CSRF_TOKEN = "<?= htmlspecialchars($csrf_token) ?>";
let linkCounter = 2;

function addLinkRow() {
    const container = document.getElementById('portfolio-links-container');
    const row = document.createElement('div');
    row.className = 'link-row';
    row.innerHTML = `
        <select name="portfolio_links[${linkCounter}][type]" class="card" style="margin:0; width: 180px;">
            <option value="GitHub">GitHub</option>
            <option value="GitLab">GitLab</option>
            <option value="Site Web">Site Web</option>
            <option value="Vidéo Démo">Vidéo / YouTube</option>
            <option value="Autre" selected>Autre</option>
        </select>
        <input type="text" name="portfolio_links[${linkCounter}][title]" placeholder="Titre du lien" class="card" style="margin:0; width: 220px;">
        <input type="url" name="portfolio_links[${linkCounter}][url]" placeholder="https://..." class="card" style="margin:0; flex:1;">
        <button type="button" class="btn btn-sm" onclick="this.parentElement.remove()" style="background: rgba(231,76,60,0.2); color: #e74c3c; border:none; padding:8px 12px;">✕</button>
    `;
    container.appendChild(row);
    linkCounter++;
}

// Gestion de l'upload des images de portfolio
const dropzone = document.getElementById('dev-dropzone');
const fileInput = document.getElementById('portfolio-file-input');
const previewGrid = document.getElementById('portfolio-preview-grid');
const uploadStatus = document.getElementById('upload-status');

['dragenter', 'dragover'].forEach(eventName => {
    dropzone.addEventListener(eventName, (e) => {
        e.preventDefault();
        e.stopPropagation();
        dropzone.classList.add('drag-over');
    }, false);
});

['dragleave', 'drop'].forEach(eventName => {
    dropzone.addEventListener(eventName, (e) => {
        e.preventDefault();
        e.stopPropagation();
        dropzone.classList.remove('drag-over');
    }, false);
});

dropzone.addEventListener('drop', (e) => {
    const dt = e.dataTransfer;
    if (dt && dt.files && dt.files.length > 0) {
        handleFiles(dt.files);
    }
});

fileInput.addEventListener('change', (e) => {
    if (fileInput.files && fileInput.files.length > 0) {
        handleFiles(fileInput.files);
    }
});

// Support du collage Ctrl+V
window.addEventListener('paste', (e) => {
    const items = (e.clipboardData || e.originalEvent.clipboardData).items;
    const imageFiles = [];
    for (let item of items) {
        if (item.type.indexOf('image') !== -1) {
            const file = item.getAsFile();
            if (file) imageFiles.push(file);
        }
    }
    if (imageFiles.length > 0) {
        handleFiles(imageFiles);
    }
});

function handleFiles(files) {
    Array.from(files).forEach(uploadFile);
}

function uploadFile(file) {
    if (!file.type.startsWith('image/')) {
        alert('Seules les images sont acceptées (PNG, JPG, WEBP, GIF).');
        return;
    }
    if (file.size > 10 * 1024 * 1024) {
        alert('L\'image dépasse la taille maximale autorisée (10 Mo).');
        return;
    }

    uploadStatus.style.display = 'block';
    uploadStatus.textContent = `Téléversement de ${file.name}...`;

    const formData = new FormData();
    formData.append('file', file);
    formData.append('csrf_token', CSRF_TOKEN);

    fetch('/api_upload_portfolio.php', {
        method: 'POST',
        headers: {
            'X-CSRF-Token': CSRF_TOKEN
        },
        body: formData
    })
    .then(r => r.json())
    .then(data => {
        uploadStatus.style.display = 'none';
        if (data.success) {
            addPortfolioThumb(data.url, data.original_name);
        } else {
            alert('Erreur : ' + (data.error || 'Échec du téléversement'));
        }
    })
    .catch(err => {
        uploadStatus.style.display = 'none';
        alert('Erreur réseau lors de l\'envoi de l\'image.');
        console.error(err);
    });
}

function addPortfolioThumb(url, name) {
    const div = document.createElement('div');
    div.className = 'portfolio-thumb';
    div.title = name || '';
    div.innerHTML = `
        <img src="${url}" alt="${name || 'Capture'}">
        <button type="button" class="remove-btn" onclick="this.parentElement.remove()" title="Supprimer">✕</button>
        <input type="hidden" name="portfolio_images[]" value="${url}">
    `;
    previewGrid.appendChild(div);
}
</script>

</body>
</html>
