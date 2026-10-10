<?php
require_once __DIR__.'/config.php';
require_once __DIR__.'/bdd.php';
require_once __DIR__.'/api_discord.php';
if (empty($_SESSION['user'])) { header('Location: /connexion_discord'); exit; }
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

// 3. SÉCURITÉ : Vérifier si une candidature est active
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

if ($_SERVER['REQUEST_METHOD']==='POST') {
  if (!isset($_POST['csrf_token']) || empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
    http_response_code(403); exit('Erreur de validation (CSRF).');
  }
  unset($_SESSION['csrf_token']);

  $answers = [
    'discord_pseudo' => $_SESSION['user']['username'], // Ajout automatique du pseudo Discord depuis la session
    'activity' => $_POST['activity'] ?? '', 'has_gta' => $_POST['has_gta'] ?? '', 'birthdate' => $_POST['birthdate'] ?? '',
    'email' => strtolower($_POST['email'] ?? ''), // Convertit l'email en minuscules
    'experience_irl' => $_POST['experience_irl'] ?? '', 'experience_irl_details' => $_POST['experience_irl_details'] ?? '',
    'has_mic' => $_POST['has_mic'] ?? '', 'char_lastname' => $_POST['char_lastname'] ?? '', 'char_firstname' => $_POST['char_firstname'] ?? '',
    'how_know' => $_POST['how_know'] ?? '', 'already_member' => $_POST['already_member'] ?? '', 'experience_rp' => $_POST['experience_rp'] ?? '',
    'experience_rp_details' => $_POST['experience_rp_details'] ?? '', 'experience_op' => $_POST['experience_op'] ?? '', 'comfortable_it' => $_POST['comfortable_it'] ?? '',
    'motivations' => $_POST['motivations'] ?? '', 'certify_exact' => isset($_POST['certify_exact']), 'aware_no_game' => isset($_POST['aware_no_game']),
  ];
  
  $subtype = (strpos(strtolower($answers['activity']), 'pompier') !== false || strpos(strtolower($answers['activity']), 'samu') !== false) ? 'SP' : 'FDO';

  $pdo->prepare("INSERT INTO applications (user_id, application_type, application_subtype, answers_json, status, recruiter_review_status) VALUES (?, 'operator', ?, ?, 'envoyee', 'none')")
      ->execute([$_SESSION['user']['id'], $subtype, json_encode($answers)]);
  $application_id = $pdo->lastInsertId();
  $pdo->prepare("UPDATE users SET status='envoyee' WHERE id=?")->execute([$_SESSION['user']['id']]);
  
  // CORRECTION : Mettre à jour la session en cours pour refléter le nouveau statut
  $_SESSION['user']['status'] = 'envoyee';

  global $DISCORD_NEW_APPLICATION_WEBHOOK_URL, $APP_BASE_URL;
  $candidature_url = ($APP_BASE_URL ?? '') . '/ma_candidature.php';
  
  discord_send_dm($_SESSION['user']['discord_id'], "✅ Votre candidature d'**Opérateur** a bien été enregistrée.\nNous l'examinerons sous peu. [Suivez son statut ici]({$candidature_url})");

  $recruiter_url = ($APP_BASE_URL ?? '') . '/candidat?id=' . $application_id;
  // discord_send_webhook_message($DISCORD_NEW_APPLICATION_WEBHOOK_URL, "📝 Nouvelle candidature **Opérateur ({$subtype})** de `{$_SESSION['user']['username']}` reçue.\n➡️ Pour l'examiner : <{$recruiter_url}>");

  // NOUVEAU: Envoi du message interactif dans le salon défini selon le type
  global $DISCORD_APP_CHANNEL_OP_SP, $DISCORD_APP_CHANNEL_OP_FDO;
  if ($subtype === 'SP' && !empty($DISCORD_APP_CHANNEL_OP_SP)) {
      discord_send_application_message($DISCORD_APP_CHANNEL_OP_SP, $application_id, "opérateur ($subtype)", $_SESSION['user']['username'], $recruiter_url);
  } elseif ($subtype === 'FDO' && !empty($DISCORD_APP_CHANNEL_OP_FDO)) {
      discord_send_application_message($DISCORD_APP_CHANNEL_OP_FDO, $application_id, "opérateur ($subtype)", $_SESSION['user']['username'], $recruiter_url);
  }

  header('Location: /ma_candidature');
  exit;
}

$_SESSION['csrf_token'] = bin2hex(random_bytes(32));
$csrf_token = $_SESSION['csrf_token'];
?>
<!DOCTYPE html><html lang="fr"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Candidature Opérateur — Studios DCPJ</title>
<link rel="icon" type="image/png" href="assets/logo.png">
<link rel="stylesheet" href="assets/styles.css">
<style>
    .form-group { margin-bottom: 20px; }
    .form-group label { font-weight: 600; color: var(--text-secondary); display: block; margin-bottom: 8px; }
    .form-group label.required::after { content: ' *'; color: var(--accent-red); }
    .form-group input[type="text"], .form-group input[type="email"], .form-group textarea, .form-group select { width: 100%; }
    .form-group .checkbox-group label { display: flex; align-items: center; gap: 8px; font-weight: normal; margin-bottom: 8px; }
    .form-group input[type="checkbox"] { width: auto; }
    .button-radios { display: flex; flex-wrap: wrap; gap: 12px; }
    .button-radios input[type="radio"] { display: none; }
    .button-radios label { flex: 1; display: inline-block; padding: 12px 20px; border: 1px solid var(--border-color); background: var(--bg-secondary); border-radius: 12px; cursor: pointer; transition: all 0.2s ease; text-align: center; margin-bottom: 0; }
    .button-radios label:hover { border-color: var(--border-hover); background: var(--bg-tertiary); }
    .button-radios input[type="radio"]:checked + label { background: var(--accent-red); border-color: var(--accent-red); color: white; font-weight: 600; box-shadow: 0 0 20px rgba(220, 38, 38, 0.4); }
    .error-message { color: var(--accent-red); font-size: 0.9rem; margin-top: 6px; display: none; }
</style>
</head><body>
<?php include __DIR__.'/partiels_entete.php'; ?>
<div class="wrap page-container">
    <h2 style="text-align:center; font-size: 2.5rem; margin-bottom: 40px;">Candidature Opérateur</h2>
    <form method="post" id="application-form-op">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
        <div class="form-group">
            <label for="activity" class="required">Quel(s) pôle(s) d'activité souhaiteriez vous intégrer ?</label>
            <div class="button-radios">
                <input type="radio" id="act-police" name="activity" value="Opérateur police" required><label for="act-police">Opérateur police</label>
                <input type="radio" id="act-pompier" name="activity" value="Opérateur pompiers"><label for="act-pompier">Opérateur pompiers</label>
                <input type="radio" id="act-samu" name="activity" value="Assistant de régulation SAMU"><label for="act-samu">Assistant de régulation SAMU</label>
            </div>
            <div id="samu-warning" class="alert alert-info" style="display: none; margin-top: 15px;">
                ⚠️ <strong>Attention :</strong> Ce rôle requiert de solides connaissances dans le domaine de la santé et des secours.
            </div>
        </div>
        <div class="form-group"><label class="required">AVEZ VOUS ACCES AU JEU GTA 5 ?</label><div class="button-radios"><input type="radio" id="gta-oui" name="has_gta" value="Oui" required><label for="gta-oui">Oui</label><input type="radio" id="gta-bug" name="has_gta" value="Oui, mais bug"><label for="gta-bug">Oui, mais je n'y joue plus (bug)</label><input type="radio" id="gta-non" name="has_gta" value="Non"><label for="gta-non">Non</label></div></div>
        <div class="form-group">
            <label for="birthdate" class="required">Date de naissance</label>
            <input type="text" id="birthdate" name="birthdate" class="card" placeholder="jj/mm/aaaa" required>
            <small class="error-message">Veuillez entrer une date de naissance valide (jj/mm/aaaa).</small>
        </div>
        <div class="form-group">
            <label for="email" class="required">Adresse mail du candidat</label>
            <input type="email" id="email" name="email" class="card" placeholder="exemple@domaine.fr" required>
            <small class="error-message">Veuillez entrer une adresse e-mail valide.</small>
        </div>
        
        <div class="form-group">
            <label class="required">Avez-vous une expérience IRL dans les secours ou les forces de l'ordre ?</label>
            <div class="button-radios">
                <input type="radio" id="irl-oui" name="experience_irl" value="Oui" required><label for="irl-oui">Oui</label>
                <input type="radio" id="irl-non" name="experience_irl" value="Non"><label for="irl-non">Non</label>
            </div>
        </div>
        <div class="form-group" id="experience-irl-details-container" style="display: none;">
            <label for="experience_irl_details">Si oui, laquelle ?</label>
            <input type="text" id="experience_irl_details" name="experience_irl_details" class="card">
        </div>

        <div class="form-group"><label class="required">Possédez-vous un micro et un casque ?</label><div class="button-radios"><input type="radio" id="mic-oui" name="has_mic" value="Oui" required><label for="mic-oui">Oui</label><input type="radio" id="mic-non" name="has_mic" value="Non"><label for="mic-non">Non</label></div></div>
        <h3 style="margin: 30px 0;">Information RolePlay</h3>
        <div class="form-group"><label for="char_lastname">Nom du personnage</label><input type="text" id="char_lastname" name="char_lastname" class="card"></div>
        <div class="form-group"><label for="char_firstname">Prénom du personnage</label><input type="text" id="char_firstname" name="char_firstname" class="card"></div>
        <h3 style="margin: 30px 0;">Motivations</h3>
        <div class="form-group"><label for="how_know">Comment avez-vous connu les Studios DCPJ ?</label><input type="text" id="how_know" name="how_know" class="card"></div>
        <div class="form-group"><label class="required">Avez-vous déjà été membre ou déjà postulé aux Studios DCPJ ?</label><div class="button-radios"><input type="radio" id="member-oui" name="already_member" value="Oui" required><label for="member-oui">Oui</label><input type="radio" id="member-non" name="already_member" value="Non" checked><label for="member-non">Non</label></div></div>
        
        <div class="form-group">
            <label class="required">Avez-vous une expérience RolePlay ?</label>
            <div class="button-radios">
                <input type="radio" id="rp-oui" name="experience_rp" value="Oui" required><label for="rp-oui">Oui</label>
                <input type="radio" id="rp-non" name="experience_rp" value="Non"><label for="rp-non">Non</label>
            </div>
        </div>
        <div class="form-group" id="experience-rp-details-container" style="display: none;">
            <label for="experience_rp_details">Si oui, laquelle ?</label>
            <input type="text" id="experience_rp_details" name="experience_rp_details" class="card">
        </div>

        <div class="form-group"><label class="required">Avez-vous une expérience d'opérateur ?</label><div class="button-radios"><input type="radio" id="op-oui" name="experience_op" value="Oui" required><label for="op-oui">Oui</label><input type="radio" id="op-non" name="experience_op" value="Non"><label for="op-non">Non</label></div></div>
        <div class="form-group"><label class="required">Etes-vous à l'aise avec les outils informatiques ?</label><div class="button-radios"><input type="radio" id="it-oui" name="comfortable_it" value="Oui" required><label for="it-oui">Oui</label><input type="radio" id="it-non" name="comfortable_it" value="Non"><label for="it-non">Non</label></div></div>
        <div class="form-group"><label for="motivations" class="required">Décrivez en quelques lignes vos motivations pour nous rejoindre ?</label><textarea id="motivations" name="motivations" class="card" rows="4" required></textarea><small class="error-message">Ce champ est obligatoire.</small></div>
        <div class="form-group checkbox-group"><label><input type="checkbox" name="certify_exact" required> Je certifie que toutes les informations sont exactes.</label><label><input type="checkbox" name="aware_no_game" required> J'ai conscience qu'en tant qu'opérateur, je ne serai pas en jeu.</label></div>
        
        <div class="alert alert-info" style="margin-top: 20px; margin-bottom: 0;">
            <div style="display: flex; align-items: center; gap: 15px;">
                <span style="font-size: 1.8rem;">⚠️</span>
                <div>
                    <strong>Important :</strong> Pour recevoir les notifications par message privé concernant l'évolution de votre statut, vous devez être présent sur notre serveur Discord public : <a href="https://discord.gg/g8Td5auzev" target="_blank" style="color: inherit; text-decoration: underline;">Studios DCPJ Public</a>.
                    <br>Si vous n'y êtes pas, vous devrez consulter cette page régulièrement pour suivre votre candidature.
                </div>
            </div>
        </div>
        
        <button type="submit" class="btn btn-primary" style="width:100%; padding: 14px; font-size: 1.1rem; justify-content: center;">Envoyer ma candidature</button>
    </form>
</div>

<footer class="ftr" style="margin-top: 40px;"><div class="wrap">© Studios DCPJ | 2025</div></footer>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const form = document.getElementById('application-form-op');
    const emailInput = document.getElementById('email');
    const birthdateInput = document.getElementById('birthdate');

    // Fonction pour afficher/cacher les messages d'erreur
    function toggleError(input, isValid, message) {
        const errorMessage = input.nextElementSibling;
        if (errorMessage && errorMessage.classList.contains('error-message')) {
            errorMessage.textContent = message;
            errorMessage.style.display = isValid ? 'none' : 'block';
            input.style.borderColor = isValid ? '' : 'var(--accent-red)';
        }
    }

    // Validation de l'email
    function validateEmail(email) {
        const re = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
        return re.test(String(email).toLowerCase());
    }

    // Validation de la date de naissance
    function validateBirthdate(dateString) {
        const regex = /^(\d{2})\/(\d{2})\/(\d{4})$/;
        const parts = dateString.match(regex);
        if (!parts) return false;

        const day = parseInt(parts[1], 10);
        const month = parseInt(parts[2], 10);
        const year = parseInt(parts[3], 10);

        if (year < 1900 || year > new Date().getFullYear()) return false;
        if (month < 1 || month > 12) return false;
        if (day < 1 || day > 31) return false;

        const date = new Date(year, month - 1, day);
        return date.getFullYear() === year && date.getMonth() === month - 1 && date.getDate() === day;
    }

    // Validation champ non vide
    function validateNotEmpty(input) {
        return input.value.trim() !== '';
    }

    // Ajout des écouteurs d'événements pour la validation instantanée
    if(emailInput) {
        emailInput.addEventListener('blur', function() {
            toggleError(this, validateEmail(this.value), 'Veuillez entrer une adresse e-mail valide.');
        });
    }
    if(birthdateInput) {
        birthdateInput.addEventListener('blur', function() {
            toggleError(this, validateBirthdate(this.value), 'Veuillez entrer une date de naissance valide (jj/mm/aaaa).');
        });
    }
    form.querySelectorAll('textarea[required]').forEach(input => {
        input.addEventListener('blur', function() {
            toggleError(this, validateNotEmpty(this), 'Ce champ est obligatoire.');
        });
    });

    if (form) {
        form.addEventListener('submit', function(e) {
            let isFormValid = true;
            const submitButton = this.querySelector('button[type="submit"]');

            // Valider tous les champs requis
            form.querySelectorAll('textarea[required], input[type="email"][required], input[type="text"][required]').forEach(input => {
                let isValid = true;
                let message = 'Ce champ est obligatoire.';
                if (input.type === 'email') {
                    isValid = validateEmail(input.value);
                    message = 'Veuillez entrer une adresse e-mail valide.';
                } else if (input.id === 'birthdate') {
                    isValid = validateBirthdate(input.value);
                    message = 'Veuillez entrer une date de naissance valide (jj/mm/aaaa).';
                } else {
                    isValid = validateNotEmpty(input);
                }
                toggleError(input, isValid, message);
                if (!isValid) isFormValid = false;
            });

            if (!isFormValid) {
                e.preventDefault(); // Empêche l'envoi du formulaire
            } else {
                emailInput.value = emailInput.value.toLowerCase();
                if (submitButton) {
                    submitButton.disabled = true;
                    submitButton.textContent = 'Envoi en cours...';
                }
            }
        });
    }

    // Formatage de la date de naissance
    if (birthdateInput) {
        birthdateInput.addEventListener('input', function(e) {
            let value = e.target.value.replace(/\D/g, '');
            let formattedValue = '';
            if (value.length > 0) formattedValue = value.substring(0, 2);
            if (value.length > 2) formattedValue += '/' + value.substring(2, 4);
            if (value.length > 4) formattedValue += '/' + value.substring(4, 8);
            e.target.value = formattedValue;
        });
    }

    // Affichage conditionnel des champs "Si oui..."
    function setupConditionalField(radioYesId, radioNoId, containerId) {
        const radioYes = document.getElementById(radioYesId);
        const radioNo = document.getElementById(radioNoId);
        const container = document.getElementById(containerId);
        
        function toggle() {
            if (radioYes && radioYes.checked) {
                container.style.display = 'block';
            } else {
                container.style.display = 'none';
            }
        }

        if (radioYes && radioNo && container) {
            radioYes.addEventListener('change', toggle);
            radioNo.addEventListener('change', toggle);
            toggle(); // Appel initial pour définir l'état au chargement
        }
    }

    setupConditionalField('irl-oui', 'irl-non', 'experience-irl-details-container');
    setupConditionalField('rp-oui', 'rp-non', 'experience-rp-details-container');

    // Affichage de l'avertissement SAMU
    const samuWarning = document.getElementById('samu-warning');
    const activityRadios = document.querySelectorAll('input[name="activity"]');
    activityRadios.forEach(radio => {
        radio.addEventListener('change', function() {
            if (document.getElementById('act-samu').checked) {
                samuWarning.style.display = 'block';
            } else {
                samuWarning.style.display = 'none';
            }
        });
    });
});
</script>
</body>
</html>