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

// 3. SÉCURITÉ : Vérifier si une candidature est active
$stmt_check = $pdo->prepare("SELECT id, status, updated_at FROM applications WHERE user_id = ? AND status != 'archive' ORDER BY created_at DESC LIMIT 1");
$stmt_check->execute([$user_id]);
$existing_app = $stmt_check->fetch(PDO::FETCH_ASSOC);

if ($existing_app) {
    if ($existing_app['status'] === 'refuse') {
        $reapply_date = (new DateTime($existing_app['updated_at']))->modify('+' . ($GLOBALS['APPLICATION_REJECTION_COOLDOWN'] ?? '1 month'));
        if ($reapply_date > new DateTime()) {
            // Le délai de cooldown est toujours actif
            header('Location: /choisir_candidature');
            exit;
        }
        // Cooldown expiré : le candidat peut postuler à nouveau
    } else {
        // Candidature en cours de traitement, intégration ou période d'essai
        header('Location: /ma_candidature');
        exit;
    }
}

function getAge($date) {
    if (empty($date)) return 'N/A';
    try {
        if (strpos($date, '/') !== false) {
            $parts = explode('/', $date);
            if (count($parts) == 3 && strlen($parts[0]) == 2) { $date = $parts[2] . '-' . $parts[1] . '-' . $parts[0]; }
        }
        $birthDate = new DateTime($date);
        $today = new DateTime();
        return $birthDate->diff($today)->y . ' ans';
    } catch (Exception $e) { return 'N/A'; }
}

// 3. Traitement du formulaire (POST)
if ($_SERVER['REQUEST_METHOD']==='POST') {
  // Valider le jeton CSRF
  if (!isset($_POST['csrf_token']) || empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
    http_response_code(403); exit('Erreur de validation (CSRF).');
  }
  unset($_SESSION['csrf_token']);

  $answers = [
    'discord_pseudo' => $_SESSION['user']['username'],
    'email' => strtolower($_POST['email'] ?? ''), 'birthdate' => $_POST['birthdate'] ?? '',
    'activity' => $_POST['activity'] ?? '', 'is_pro' => $_POST['is_pro'] ?? '', 'service_pro_details' => $_POST['service_pro_details'] ?? '',
    'has_mic' => $_POST['has_mic'] ?? '', 'has_gta' => $_POST['has_gta'] ?? '', 'motivations' => $_POST['motivations'] ?? '',
    'experience' => $_POST['experience'] ?? '', 'scenario' => $_POST['scenario'] ?? '',
    'certify_age' => isset($_POST['certify_age']), 'accept_terms' => isset($_POST['accept_terms']),
  ];
  
  $pdo->prepare("INSERT INTO applications (user_id, application_type, answers_json, status) VALUES (?, 'player', ?, 'attente')")->execute([$_SESSION['user']['id'], json_encode($answers)]);
  $application_id = $pdo->lastInsertId();
  $pdo->prepare("UPDATE users SET status='attente' WHERE id=?")->execute([$_SESSION['user']['id']]);
  
  $candidature_url = ($GLOBALS['APP_BASE_URL'] ?? '') . '/ma_candidature';
  $recruiter_url = ($GLOBALS['APP_BASE_URL'] ?? '') . '/candidat?id=' . $application_id;
  $now = date('Y-m-d H:i:s');

  // --- SYSTÈME DE TRAITEMENT AUTOMATIQUE ---
  $auto_rejection_reason = null;
  $auto_accept = false;

  if ($answers['activity'] !== 'developpement') {
      // Vérification des prérequis pour le rejet automatique
      if (($answers['has_gta'] ?? 'non') === 'non') {
          $auto_rejection_reason = 'Prérequis non rempli (ne possède pas GTA V)';
      }
      elseif (($answers['has_mic'] ?? 'non') === 'non') {
          $auto_rejection_reason = 'Prérequis non rempli (ne possède pas de micro)';
      }
      elseif (!empty($answers['birthdate'])) {
          $birthDate = DateTime::createFromFormat('d/m/Y', $answers['birthdate']);
          if ($birthDate && $birthDate->diff(new DateTime())->y < 15) {
              $auto_rejection_reason = 'Âge minimum de 15 ans non atteint';
          }
      }

      // Si aucun motif de rejet => toutes les conditions sont remplies => acceptation automatique
      if (!$auto_rejection_reason) {
          $auto_accept = true;
      }
  }

      // Préparation des détails pour l'embed Discord
      $details_embed = [
          'Pseudo Discord' => $answers['discord_pseudo'] ?? 'N/A',
          'Âge' => getAge($answers['birthdate']),
          'Activité' => $answers['activity'] === 'developpement' ? '💻 Développement' : '🎮 Joueur',
          'Intervention' => mb_strimwidth($answers['scenario'] ?? 'N/A', 0, 500, '...'),
          'Motivations' => mb_strimwidth($answers['motivations'] ?? 'N/A', 0, 250, '...'),
          'Expérience' => mb_strimwidth($answers['experience'] ?? 'N/A', 0, 250, '...')
      ];

      if ($auto_rejection_reason) {
          // --- REJET AUTOMATIQUE ---
          $status_history = [['status' => 'refuse', 'reason' => $auto_rejection_reason, 'author' => 'Système', 'date' => $now]];
          $pdo->prepare("UPDATE applications SET status='refuse', rejection_reason=?, decision_by='Système', updated_at=?, status_history=? WHERE id=?")->execute([$auto_rejection_reason, $now, json_encode($status_history), $application_id]);
          $pdo->prepare("UPDATE users SET status='refuse' WHERE id=?")->execute([$_SESSION['user']['id']]);
          $_SESSION['user']['status'] = 'refuse';
          discord_send_dm($_SESSION['user']['discord_id'], "ℹ️ Votre candidature a été automatiquement traitée et refusée.\nMotif : {$auto_rejection_reason}.\n[Consultez son statut ici]({$candidature_url})", $DISCORD_DM_IMAGE_URL);
          
          if (!empty($DISCORD_APP_CHANNEL_PLAYER)) {
              discord_send_application_message($DISCORD_APP_CHANNEL_PLAYER, $application_id, 'player', $_SESSION['user']['username'], $recruiter_url, false, null, "❌ Auto-rejetée ({$auto_rejection_reason})", $details_embed);
          }

      } elseif ($auto_accept) {
          // --- ACCEPTATION AUTOMATIQUE ---
          $token = bin2hex(random_bytes(16));
          $status_history = [['status' => 'accepte_integration', 'reason' => null, 'author' => 'Système (auto-acceptation)', 'date' => $now, 'history_status' => 'active']];
          $pdo->prepare("UPDATE applications SET status='accepte_integration', integration_token=?, decision_by='Système', updated_at=?, status_history=? WHERE id=?")->execute([$token, $now, json_encode($status_history), $application_id]);
          $pdo->prepare("UPDATE users SET status='accepte_integration', trial_start_date = IF(trial_start_date IS NULL, ?, trial_start_date) WHERE id=?")->execute([$now, $_SESSION['user']['id']]);
          $_SESSION['user']['status'] = 'accepte_integration';
          $integration_url = ($GLOBALS['APP_BASE_URL'] ?? '') . "/integration?token={$token}";
          discord_send_dm($_SESSION['user']['discord_id'], "✅ Votre candidature de **Joueur** a été acceptée automatiquement ! Vous remplissez les conditions de base et pouvez donc commencer votre intégration sans attendre.\n\n⚠️ **Note :** Cette validation est temporaire. Une vérification manuelle approfondie de votre dossier sera effectuée ultérieurement par notre équipe de recruteurs.\n\n➡️ [Démarrez votre intégration ici]({$integration_url})", $DISCORD_DM_IMAGE_URL);

          if (!empty($DISCORD_APP_CHANNEL_PLAYER)) {
              discord_send_application_message($DISCORD_APP_CHANNEL_PLAYER, $application_id, 'player', $_SESSION['user']['username'], $recruiter_url, true, null, null, $details_embed);
          }

      } else {
          // --- EN ATTENTE (ex: activité développement) ---
          $_SESSION['user']['status'] = 'attente';
          discord_send_dm($_SESSION['user']['discord_id'], "✅ Votre candidature de **Joueur** a bien été enregistrée.\nNous l'examinerons sous peu. [Suivez son statut ici]({$candidature_url})", $DISCORD_DM_IMAGE_URL);
          
          if (!empty($DISCORD_APP_CHANNEL_PLAYER)) {
              if ($answers['activity'] === 'developpement') {
                  global $DISCORD_DEV_NOTIF_ROLE_ID;
                  $dev_role = $DISCORD_DEV_NOTIF_ROLE_ID ?? '1220533779031003197';
                  discord_send_application_message($DISCORD_APP_CHANNEL_PLAYER, $application_id, 'developer', $_SESSION['user']['username'], $recruiter_url, true, $dev_role, "⏳ En attente de traitement", $details_embed);
              } else {
                  discord_send_application_message($DISCORD_APP_CHANNEL_PLAYER, $application_id, 'player', $_SESSION['user']['username'], $recruiter_url, true, null, null, $details_embed);
              }
          }
      }

  header('Location: /ma_candidature');
  exit;
}

// Pour les affichages de page (GET), on génère toujours un nouveau jeton
$_SESSION['csrf_token'] = bin2hex(random_bytes(32));
$csrf_token = $_SESSION['csrf_token'];

// Pré-remplissage (optionnel, si on veut garder les infos d'une ancienne candi archivée par exemple)
$app = $pdo->prepare('SELECT * FROM applications WHERE user_id=? AND application_type="player" ORDER BY created_at DESC LIMIT 1');
$app->execute([$_SESSION['user']['id']]);
$application = $app->fetch(PDO::FETCH_ASSOC);
$answers = $application ? json_decode($application['answers_json'] ?: '{}', true) : [];
?>
<!DOCTYPE html><html lang="fr"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Recrutement Joueur — Studios DCPJ</title>
<link rel="icon" type="image/png" href="assets/logo.png">
<link rel="stylesheet" href="assets/styles.css">
<style>
    .recrutement-section { padding: 24px; border-radius: 16px; background: var(--bg-secondary); margin-bottom: 24px; }
    .recrutement-section h3 { font-size: 1.5rem; color: var(--accent-red); margin-bottom: 16px; }
    .recrutement-section ul { list-style-position: inside; padding-left: 8px; }
    .recrutement-section li { margin-bottom: 8px; }
    .form-group { margin-bottom: 20px; }
    .form-group label { font-weight: 600; color: var(--text-secondary); display: block; margin-bottom: 8px; }
    .form-group label.required::after { content: ' *'; color: var(--accent-red); }
    .form-group input[type="text"], .form-group input[type="email"], .form-group textarea { width: 100%; }
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
    <h2 style="text-align:center; font-size: 2.5rem; margin-bottom: 40px;">Recrutement Joueur - STUDIOS DCPJ</h2>
    <div class="recrutement-section"><h3>Modalités de recrutement :</h3><ul><li>Avoir 15 ans minimum</li><li>Avoir GTA version PC + FiveM</li><li>Avoir un micro</li><li>Être sérieux et respectueux</li></ul></div>
    <form method="post" id="application-form">
        <h2 style="text-align:center; font-size: 2rem; margin: 40px 0;">Formulaire de recrutement</h2>
        <p style="text-align:center; color: var(--text-secondary); margin-bottom: 30px;">Les questions qui comportent un * sont à caractère obligatoire.</p>
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
        
        <div class="form-group">
            <label for="email" class="required">Votre adresse email</label>
            <input type="email" id="email" name="email" class="card" placeholder="exemple@domaine.fr" value="<?= htmlspecialchars($answers['email'] ?? '') ?>" required>
            <small class="error-message">Veuillez entrer une adresse e-mail valide.</small>
        </div>
        <div class="form-group">
            <label for="birthdate" class="required">Votre date de naissance</label>
            <input type="text" id="birthdate" name="birthdate" class="card" placeholder="jj/mm/aaaa" value="<?= htmlspecialchars($answers['birthdate'] ?? '') ?>" required>
            <small class="error-message">Veuillez entrer une date de naissance valide (jj/mm/aaaa).</small>
        </div>
        <div class="form-group">
            <label class="required">Pour quelle activité souhaitez-vous nous rejoindre ?</label>
            <div class="button-radios"><input type="radio" id="activity-gta" name="activity" value="gta_rp" checked required><label for="activity-gta">SIMULATION SUR GTA 5 RP | FiveM</label></div>
        </div>
        
        <div class="form-group">
            <label class="required">Travaillez-vous dans un service "pro" ?</label>
            <div class="button-radios"><input type="radio" id="pro-oui" name="is_pro" value="oui" <?= (($answers['is_pro'] ?? '') === 'oui') ? 'checked' : '' ?> required><label for="pro-oui">Oui</label><input type="radio" id="pro-non" name="is_pro" value="non" <?= (($answers['is_pro'] ?? '') === 'non') ? 'checked' : '' ?>><label for="pro-non">Non</label></div>
        </div>
        
        <div class="form-group" id="service-pro-details-container" style="display: none;">
            <label for="service_pro_details" class="required">Dans quel service ? (Ex: Pompier volontaire, Policier, Gendarme...)</label>
            <input type="text" id="service_pro_details" name="service_pro_details" class="card" value="<?= htmlspecialchars($answers['service_pro_details'] ?? '') ?>">
            <small class="error-message">Ce champ est obligatoire si vous avez répondu "Oui".</small>
        </div>

        <div class="form-group">
            <label class="required">Avez-vous un microphone ?</label>
            <div class="button-radios"><input type="radio" id="mic-oui" name="has_mic" value="oui" <?= (($answers['has_mic'] ?? '') === 'oui') ? 'checked' : '' ?> required><label for="mic-oui">Oui</label><input type="radio" id="mic-non" name="has_mic" value="non" <?= (($answers['has_mic'] ?? '') === 'non') ? 'checked' : '' ?>><label for="mic-non">Non</label></div>
        </div>
        <div class="form-group">
            <label class="required">Avez-vous GTA V sur PC ?</label>
            <div class="button-radios"><input type="radio" id="gta-oui" name="has_gta" value="oui" <?= (($answers['has_gta'] ?? '') === 'oui') ? 'checked' : '' ?> required><label for="gta-oui">Oui</label><input type="radio" id="gta-non" name="has_gta" value="non" <?= (($answers['has_gta'] ?? '') === 'non') ? 'checked' : '' ?>><label for="gta-non">Non</label></div>
        </div>
        <div class="form-group">
            <label for="motivations" class="required">Expliquez-nous vos motivations pour nous rejoindre</label>
            <textarea id="motivations" name="motivations" class="card" rows="4" maxlength="255" required><?= htmlspecialchars($answers['motivations'] ?? '') ?></textarea>
            <small class="error-message">Ce champ est obligatoire.</small>
        </div>
        
        <div class="form-group">
            <div style="margin-bottom: 10px;">
                <button type="button" id="btn-no-exp" class="btn" style="background-color: #6c757d; font-size: 0.9em; padding: 5px 10px; border:none; cursor:pointer; color:white; border-radius:5px;">
                    Je n'ai pas d'expérience
                </button>
            </div>
            <label for="experience" class="required">Avez-vous une expérience en simulation ou RolePlay ?</label>
            <textarea id="experience" name="experience" class="card" rows="3" maxlength="255" required><?= htmlspecialchars($answers['experience'] ?? '') ?></textarea>
            <small class="error-message">Ce champ est obligatoire.</small>
        </div>
        <div class="form-group">
            <label for="scenario" class="required">Inventez une intervention</label>
            <textarea id="scenario" name="scenario" class="card" rows="4" maxlength="255" required><?= htmlspecialchars($answers['scenario'] ?? '') ?></textarea>
            <small class="error-message">Ce champ est obligatoire.</small>
        </div>
        
        <div class="form-group checkbox-group">
            <label><input type="checkbox" name="certify_age" required>Je certifie avoir 15 ans (ou plus).</label>
            <label><input type="checkbox" name="accept_terms" required>J'ai pris connaissance et j'accepte les conditions générales.</label>
        </div>

        <div class="alert alert-info" style="margin-top: 20px; margin-bottom: 0;">
            <div style="display: flex; align-items: center; gap: 15px;">
                <span style="font-size: 1.8rem;">⚠️</span>
                <div>
                    <strong>Important :</strong> Pour recevoir les notifications par message privé concernant l'évolution de votre statut, vous devez être présent sur notre serveur Discord public : <a href="https://discord.gg/g8Td5auzev" target="_blank" style="color: inherit; text-decoration: underline;">Studios DCPJ Public</a>.
                    <br>Si vous n'y êtes pas, vous devrez consulter la page "Ma Candidature" régulièrement pour suivre votre candidature.
                </div>
            </div>
        </div>
        
        <button type="submit" class="btn btn-primary" style="width:100%; padding: 14px; font-size: 1.1rem; justify-content: center;">Envoyer ma candidature</button>
    </form>
</div>

<footer class="ftr" style="margin-top: 40px;"><div class="wrap">© Studios DCPJ | 2025</div></footer>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const form = document.getElementById('application-form');
    const emailInput = document.getElementById('email');
    const birthdateInput = document.getElementById('birthdate');
    const btnNoExp = document.getElementById('btn-no-exp');

    // Fonction pour afficher/cacher les messages d'erreur
    function toggleError(input, isValid, message) {
        const errorMessage = input.nextElementSibling;
        if (errorMessage && errorMessage.classList.contains('error-message')) {
            errorMessage.textContent = message;
            errorMessage.style.display = isValid ? 'none' : 'block';
            input.style.borderColor = isValid ? '' : 'var(--accent-red)';
        }
    }

    // Gestion du bouton "Pas d'expérience"
    if (btnNoExp) {
        btnNoExp.addEventListener('click', function() {
            const expTextarea = document.getElementById('experience');
            if (expTextarea) {
                expTextarea.value = "Je n'ai pas d'expérience particulière en RolePlay/Simulation. Je suis ici pour découvrir le serveur, apprendre les bases du RP et m'investir sérieusement.";
                toggleError(expTextarea, true, '');
                expTextarea.style.backgroundColor = "#e8f0fe";
                setTimeout(() => { expTextarea.style.backgroundColor = ""; }, 500);
            }
        });
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
    form.querySelectorAll('textarea[required], input[type="text"][required]').forEach(input => {
        input.addEventListener('blur', function() {
            toggleError(this, validateNotEmpty(this), 'Ce champ est obligatoire.');
        });
    });


    if (form) {
        form.addEventListener('submit', function(e) {
            let isFormValid = true;
            const submitButton = this.querySelector('button[type="submit"]');

            // Valider tous les champs requis
            form.querySelectorAll('textarea[required], input[type="text"][required], input[type="email"][required]').forEach(input => {
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

    // Affichage conditionnel du champ "service pro"
    const serviceProDetailsContainer = document.getElementById('service-pro-details-container');
    const radioProOui = document.getElementById('pro-oui');
    const radioProNon = document.getElementById('pro-non');

    function toggleServiceProDetails() {
        if (radioProOui.checked) {
            serviceProDetailsContainer.style.display = 'block';
            serviceProDetailsContainer.querySelector('input').required = true;
        } else {
            serviceProDetailsContainer.style.display = 'none';
            serviceProDetailsContainer.querySelector('input').required = false;
        }
    }
    if (radioProOui && radioProNon) {
        radioProOui.addEventListener('change', toggleServiceProDetails);
        radioProNon.addEventListener('change', toggleServiceProDetails);
        toggleServiceProDetails();
    }
});
</script>

</body></html>