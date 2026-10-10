<?php
require_once __DIR__.'/config.php';
require_once __DIR__.'/bdd.php';

// Sécurité : Vérifie si l'utilisateur a le droit de modifier le FTA
if (empty($_SESSION['user']['can_edit_fta'])) {
    http_response_code(403);
    exit('Accès refusé');
}

$pdo = db();
$feedback_message = '';
$edit_mode = false;
$article_to_edit = null;

// CSRF Protection
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

// --- TRAITEMENT DES ACTIONS (POST) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        http_response_code(403);
        exit('Erreur CSRF : session invalide ou expirée.');
    }
    $action = $_POST['action'] ?? '';

    if ($action === 'add' || $action === 'update') {
        $article_code = trim($_POST['article_code'] ?? '');
        $description = trim($_POST['description'] ?? '');

        if (!empty($article_code) && !empty($description)) {
            if ($action === 'add') {
                $stmt = $pdo->prepare("INSERT INTO fta_infraction_articles (article_code, description) VALUES (?, ?)");
                $stmt->execute([$article_code, $description]);
                $feedback_message = "L'article a été ajouté avec succès.";
            } else {
                $id = (int)($_POST['article_id'] ?? 0);
                if ($id > 0) {
                    $stmt = $pdo->prepare("UPDATE fta_infraction_articles SET article_code = ?, description = ? WHERE id = ?");
                    $stmt->execute([$article_code, $description, $id]);
                    $feedback_message = "L'article a été mis à jour avec succès.";
                }
            }
        } else {
            $feedback_message = "Erreur : Le code de l'article et la description ne peuvent pas être vides.";
        }
    }

    if ($action === 'toggle_status') {
        $id = (int)($_POST['article_id'] ?? 0);
        if ($id > 0) {
            // On inverse le statut actuel
            $stmt = $pdo->prepare("UPDATE fta_infraction_articles SET is_active = 1 - is_active WHERE id = ?");
            $stmt->execute([$id]);
            $feedback_message = "Le statut de l'article a été modifié.";
        }
    }

    // Redirection pour éviter la resoumission du formulaire
    header('Location: /gestion_infractions?feedback=' . urlencode($feedback_message));
    exit;
}

// --- LOGIQUE D'AFFICHAGE (GET) ---

// Vérifier si un message de feedback est passé dans l'URL
if (isset($_GET['feedback'])) {
    $feedback_message = htmlspecialchars($_GET['feedback']);
}

// Vérifier si on est en mode édition
if (isset($_GET['edit_id'])) {
    $edit_mode = true;
    $id_to_edit = (int)$_GET['edit_id'];
    $stmt = $pdo->prepare("SELECT * FROM fta_infraction_articles WHERE id = ?");
    $stmt->execute([$id_to_edit]);
    $article_to_edit = $stmt->fetch(PDO::FETCH_ASSOC);
}

// Récupérer tous les articles pour les afficher
$articles = $pdo->query("SELECT * FROM fta_infraction_articles ORDER BY article_code ASC")->fetchAll(PDO::FETCH_ASSOC);

?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Gestion des Infractions — Studios DCPJ</title>
    <link rel="icon" type="image/png" href="assets/logo.png">
    <link rel="stylesheet" href="assets/styles.css">
</head>
<body>
<?php include __DIR__.'/partiels_entete.php'; ?>
<div class="wrap page-container">
    <h2 class="mb-3">Gestion des Articles d'Infraction (FTA)</h2>

    <?php if ($feedback_message): ?>
        <div class="alert alert-info"><?= $feedback_message ?></div>
    <?php endif; ?>

    <div class="card mb-3" id="form-card">
        <h3><?= $edit_mode ? 'Modifier un article' : 'Ajouter un nouvel article' ?></h3>
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
            <input type="hidden" name="action" value="<?= $edit_mode ? 'update' : 'add' ?>">
            <?php if ($edit_mode && $article_to_edit): ?>
                <input type="hidden" name="article_id" value="<?= $article_to_edit['id'] ?>">
            <?php endif; ?>

            <div style="display: grid; grid-template-columns: 1fr 2fr 1fr; gap: 20px; align-items: flex-end;">
                <div class="form-group">
                    <label for="article_code" class="required">Code de l'article</label>
                    <input type="text" id="article_code" name="article_code" class="card" placeholder="Ex: A-1.1" value="<?= htmlspecialchars($article_to_edit['article_code'] ?? '') ?>" required>
                </div>

                <div class="form-group">
                    <label for="description" class="required">Description de l'infraction</label>
                    <input type="text" id="description" name="description" class="card" placeholder="Décrivez l'infraction..." value="<?= htmlspecialchars($article_to_edit['description'] ?? '') ?>" required>
                </div>
                
                <div class="form-group">
                    <button type="submit" class="btn btn-primary" style="width: 100%; justify-content: center;"><?= $edit_mode ? 'Mettre à jour' : 'Ajouter l\'article' ?></button>
                    <?php if ($edit_mode): ?>
                        <a href="/gestion_infractions" class="btn mt-1" style="width: 100%; justify-content: center;">Annuler l'édition</a>
                    <?php endif; ?>
                </div>
            </div>
        </form>
    </div>

    <div class="card">
        <h3>Liste des articles actuels</h3>
        <table style="width:100%;border-collapse:separate;border-spacing:0 8px">
            <thead>
                <tr style="color:#94a3b8">
                    <th>Code</th>
                    <th>Description</th>
                    <th style="text-align: center;">Statut</th>
                    <th style="text-align: right;">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($articles)): ?>
                    <tr><td colspan="4" style="text-align:center;padding: 20px;">Aucun article d'infraction n'a été créé pour le moment.</td></tr>
                <?php else: ?>
                    <?php foreach ($articles as $article): ?>
                        <tr>
                            <td><strong><?= htmlspecialchars($article['article_code']) ?></strong></td>
                            <td><?= htmlspecialchars($article['description']) ?></td>
                            <td style="text-align: center;">
                                <span class="status-badge <?= $article['is_active'] ? 'status-accepted' : 'status-rejected' ?>">
                                    <?= $article['is_active'] ? 'Actif' : 'Inactif' ?>
                                </span>
                            </td>
                            <td style="text-align: right;">
                                <a href="?edit_id=<?= $article['id'] ?>#form-card" class="btn">Modifier</a>
                                <form method="POST" style="display: inline-block; margin-left: 10px;">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                                    <input type="hidden" name="action" value="toggle_status">
                                    <input type="hidden" name="article_id" value="<?= $article['id'] ?>">
                                    <button type="submit" class="btn" onclick="return confirm('Êtes-vous sûr de vouloir changer le statut de cet article ?');">
                                        <?= $article['is_active'] ? 'Désactiver' : 'Activer' ?>
                                    </button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
</body>
</html>