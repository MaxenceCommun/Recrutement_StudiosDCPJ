<?php
require_once __DIR__.'/config.php';
require_once __DIR__.'/bdd.php';

// Sécurité : Réservé au Bureau, Secrétariat ou possesseur de la permission
if (empty($_SESSION['user']) || (empty($_SESSION['user']['is_bureau']) && empty($_SESSION['user']['is_secretariat']) && empty($_SESSION['user']['can_manage_quiz']))) {
    http_response_code(403);
    exit('Accès refusé');
}

$pdo = db();

// --- CRÉATION DE LA TABLE SI ELLE N'EXISTE PAS ---
$pdo->exec("CREATE TABLE IF NOT EXISTS integration_questions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    section INT NOT NULL,
    question TEXT NOT NULL,
    options_json TEXT NOT NULL,
    answer_index INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
)");

// --- MIGRATION INITIALE SI VIDE ---
$count = $pdo->query("SELECT COUNT(*) FROM integration_questions")->fetchColumn();
if ($count == 0) {
    foreach ($QUIZ_QUESTIONS as $section_id => $questions) {
        foreach ($questions as $q) {
            $pdo->prepare("INSERT INTO integration_questions (section, question, options_json, answer_index) VALUES (?, ?, ?, ?)")
                ->execute([
                    $section_id,
                    $q['question'],
                    json_encode($q['options']),
                    $q['answer']
                ]);
        }
    }
}

$message = '';

// CSRF Protection
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

// --- TRAITEMENT DES ACTIONS ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        http_response_code(403);
        exit('Erreur CSRF : session invalide ou expirée.');
    }
    // AJOUTER UNE QUESTION
    if (isset($_POST['action']) && $_POST['action'] === 'add') {
        $section = (int)$_POST['section'];
        $question = trim($_POST['question']);
        $options = $_POST['options'];
        $answer = (int)$_POST['answer_index'];
        
        if (!empty($question) && count($options) >= 2) {
            $pdo->prepare("INSERT INTO integration_questions (section, question, options_json, answer_index) VALUES (?, ?, ?, ?)")
                ->execute([$section, $question, json_encode($options), $answer]);
            $message = "Question ajoutée avec succès.";
        } else {
            $message = "Erreur : La question et au moins deux options sont requises.";
        }
    }
    // MODIFIER UNE QUESTION
    elseif (isset($_POST['action']) && $_POST['action'] === 'edit') {
        $id = (int)$_POST['id'];
        $section = (int)$_POST['section'];
        $question = trim($_POST['question']);
        $options = $_POST['options'];
        $answer = (int)$_POST['answer_index'];
        
        if (!empty($question) && count($options) >= 2) {
            $pdo->prepare("UPDATE integration_questions SET section = ?, question = ?, options_json = ?, answer_index = ? WHERE id = ?")
                ->execute([$section, $question, json_encode($options), $answer, $id]);
            $message = "Question mise à jour.";
        }
    }
    // SUPPRIMER UNE QUESTION
    elseif (isset($_POST['action']) && $_POST['action'] === 'delete') {
        $id = (int)$_POST['id'];
        $pdo->prepare("DELETE FROM integration_questions WHERE id = ?")->execute([$id]);
        $message = "Question supprimée.";
    }
    
    // Pour éviter le double envoi lors d'un rafraichissement, rediriger si nécessaire
    // header("Location: gestion_quiz.php"); exit;
}

// --- RÉCUPÉRATION DES QUESTIONS ---
$questions = $pdo->query("SELECT * FROM integration_questions ORDER BY section ASC, id ASC")->fetchAll(PDO::FETCH_ASSOC);

?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Gestion du Quiz d'Intégration — Studios DCPJ</title>
    <link rel="icon" type="image/png" href="assets/logo.png">
    <link rel="stylesheet" href="assets/styles.css">
    <style>
        :root { --accent-primary: #e74c3c; --accent-secondary: #ff6b6b; --border-color: rgba(255,255,255,0.08); }
        .section-separator { border-top: 2px solid var(--accent-primary); margin: 40px 0 20px; padding-top: 15px; font-weight: 800; font-size: 1.4rem; color: #fff; text-transform: uppercase; letter-spacing: 1px; display: flex; align-items: center; gap: 15px; }
        .section-separator::after { content: ''; flex: 1; height: 1px; background: linear-gradient(to right, var(--accent-primary), transparent); }
        .question-card { background: rgba(255,255,255,0.03); backdrop-filter: blur(10px); border: 1px solid var(--border-color); border-radius: 16px; padding: 25px; margin-bottom: 25px; transition: transform 0.3s ease, box-shadow 0.3s ease; }
        .question-card:hover { transform: translateY(-5px); box-shadow: 0 10px 30px rgba(0,0,0,0.3); border-color: rgba(255,255,255,0.15); }
        .question-card h4 { font-size: 1.25rem; font-weight: 600; margin-bottom: 20px; color: #fff; }
        .option-display { padding: 10px 15px; border-radius: 10px; margin-bottom: 8px; background: rgba(255,255,255,0.02); border: 1px solid transparent; display: flex; align-items: center; gap: 12px; }
        .option-display.text-success { background: rgba(46, 204, 113, 0.1); border-color: rgba(46, 204, 113, 0.2); color: #2ecc71; font-weight: 600; }
        .option-item { display: flex; align-items: center; gap: 12px; margin-bottom: 12px; }
        .option-item input[type=text] { flex: 1; padding: 10px 15px; border-radius: 8px; }
        .action-btns { display: flex; gap: 12px; margin-top: 25px; justify-content: flex-end; }
        .btn-sm { padding: 6px 12px; font-size: 0.85rem; }
        .hint { font-size: 0.85rem; color: #94a3b8; font-style: italic; margin-top: 5px; display: block; }
        .alert { padding: 15px 20px; border-radius: 10px; margin-bottom: 20px; }
        .alert-success { background: rgba(46, 204, 113, 0.1); border-left: 4px solid #2ecc71; color: #2ecc71; }
    </style>
</head>
<body>
<?php include __DIR__.'/partiels_entete.php'; ?>
<div class="wrap page-container">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
        <h2>Gestion du Quiz d'Intégration</h2>
        <button class="btn btn-primary" onclick="showAddForm()">+ Ajouter une question</button>
    </div>

    <?php if ($message): ?>
        <div class="alert alert-success mt-1 mb-2"><strong>Info :</strong> <?= htmlspecialchars($message) ?></div>
    <?php endif; ?>

    <p class="mb-3 text-secondary">Les questions sont regroupées par section (correspondant aux étapes de lecture 1 à 5). Un candidat devra répondre à une question aléatoire par section.</p>

    <!-- FORMULAIRE D'AJOUT (Caché par défaut) -->
    <div id="add-form-container" class="card" style="display: none; margin-bottom: 30px; border: 2px solid var(--accent-primary);">
        <h3>Nouvelle Question</h3>
        <hr>
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
            <input type="hidden" name="action" value="add">
            <div class="form-group">
                <label>Section (Etape de lecture)</label>
                <select name="section" required>
                    <?php for($i=1;$i<=5;$i++): ?>
                        <option value="<?= $i ?>">Section <?= $i ?></option>
                    <?php endfor; ?>
                </select>
                <span class="hint">La question sera posée après la lecture de la section correspondante.</span>
            </div>
            <div class="form-group">
                <label>Question</label>
                <input type="text" name="question" placeholder="Texte de la question..." required>
            </div>
            <div class="form-group">
                <label>Options de réponse (Cochez la bonne réponse)</label>
                <div id="new-options">
                    <?php for($j=0;$j<4;$j++): ?>
                        <div class="option-item">
                            <input type="radio" name="answer_index" value="<?= $j ?>" <?= $j==0?'checked':'' ?> required>
                            <input type="text" name="options[]" placeholder="Option <?= $j+1 ?>" required>
                        </div>
                    <?php endfor; ?>
                </div>
            </div>
            <div class="action-btns">
                <button type="button" class="btn btn-outline" onclick="hideAddForm()">Annuler</button>
                <button type="submit" class="btn btn-primary">Créer la question</button>
            </div>
        </form>
    </div>

    <!-- LISTE DES QUESTIONS -->
    <?php 
    $current_section = 0;
    if (empty($questions)): ?>
        <div class="card text-center">Aucune question trouvée.</div>
    <?php else: 
        foreach ($questions as $q):
            if ($q['section'] != $current_section):
                $current_section = $q['section'];
                echo "<div class='section-separator'>Section $current_section</div>";
            endif;
            $opts = json_decode($q['options_json'], true);
    ?>
        <div class="question-card" id="q-card-<?= $q['id'] ?>">
            <div class="q-view">
                <h4><?= htmlspecialchars($q['question']) ?></h4>
                <div class="options-list">
                    <?php foreach ($opts as $idx => $opt): ?>
                        <div class="option-display <?= $idx == $q['answer_index'] ? 'text-success font-bold' : '' ?>">
                            <?= $idx == $q['answer_index'] ? '✅' : '⚪' ?> <?= htmlspecialchars($opt) ?>
                        </div>
                    <?php endforeach; ?>
                </div>
                <div class="action-btns">
                    <button class="btn btn-outline btn-sm" onclick="editQuestion(<?= $q['id'] ?>)">Modifier</button>
                    <form method="POST" style="display:inline;" onsubmit="return confirm('Confirmer la suppression ?');">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="id" value="<?= $q['id'] ?>">
                        <button type="submit" class="btn btn-outline btn-sm" style="color:var(--accent-red); border-color:var(--accent-red);">Supprimer</button>
                    </form>
                </div>
            </div>

            <!-- Form Edit (Caché) -->
            <div class="q-edit" style="display:none;">
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                    <input type="hidden" name="action" value="edit">
                    <input type="hidden" name="id" value="<?= $q['id'] ?>">
                    <div class="form-group">
                        <label>Section</label>
                        <select name="section">
                            <?php for($i=1;$i<=5;$i++): ?>
                                <option value="<?= $i ?>" <?= $q['section'] == $i ? 'selected' : '' ?>>Section <?= $i ?></option>
                            <?php endfor; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Question</label>
                        <input type="text" name="question" value="<?= htmlspecialchars($q['question']) ?>" required>
                    </div>
                    <div class="form-group">
                        <label>Options</label>
                        <?php foreach ($opts as $idx => $opt): ?>
                            <div class="option-item">
                                <input type="radio" name="answer_index" value="<?= $idx ?>" <?= $idx == $q['answer_index'] ? 'checked' : '' ?> required>
                                <input type="text" name="options[]" value="<?= htmlspecialchars($opt) ?>" required>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <div class="action-btns">
                        <button type="button" class="btn btn-outline" onclick="cancelEdit(<?= $q['id'] ?>)">Annuler</button>
                        <button type="submit" class="btn btn-primary">Enregistrer</button>
                    </div>
                </form>
            </div>
        </div>
    <?php endforeach; endif; ?>
</div>

<script>
function showAddForm() {
    document.getElementById('add-form-container').style.display = 'block';
    window.scrollTo({top: 0, behavior: 'smooth'});
}
function hideAddForm() {
    document.getElementById('add-form-container').style.display = 'none';
}
function editQuestion(id) {
    const card = document.getElementById('q-card-' + id);
    card.querySelector('.q-view').style.display = 'none';
    card.querySelector('.q-edit').style.display = 'block';
}
function cancelEdit(id) {
    const card = document.getElementById('q-card-' + id);
    card.querySelector('.q-view').style.display = 'block';
    card.querySelector('.q-edit').style.display = 'none';
}
</script>
</body>
</html>
