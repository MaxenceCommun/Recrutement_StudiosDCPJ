<?php
require_once __DIR__.'/config.php';
require_once __DIR__.'/bdd.php';
require_once __DIR__.'/api_discord.php';

// Sécurité : Vérifie les permissions de l'utilisateur
if (empty($_SESSION['user']['can_manage_players'])) {
    http_response_code(403);
    exit('Accès refusé');
}

$pdo = db();
$feedback_message = '';

// CSRF Protection
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

// Traitement du formulaire de changement de statut
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['user_id'], $_POST['new_status'])) {
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        http_response_code(403);
        exit('Erreur CSRF : session invalide ou expirée.');
    }
    $user_id = (int)$_POST['user_id'];
    $new_status = in_array($_POST['new_status'], ['active', 'left']) ? $_POST['new_status'] : 'active';

    if ($new_status === 'left') {
        $pdo->prepare("UPDATE users SET status = 'left', departure_date = COALESCE(departure_date, NOW()) WHERE id = ?")->execute([$user_id]);
        log_user_action($user_id, 'status_change', "Joueur marqué comme ayant quitté via la gestion des joueurs.", $_SESSION['user']['id']);
        $feedback_message = "Le joueur a été marqué comme ayant quitté. Vous pouvez ajouter une note d'incident sur son profil.";
    } else {
        $pdo->prepare("UPDATE users SET status = ? WHERE id = ?")->execute([$new_status, $user_id]);
        log_user_action($user_id, 'status_change', "Statut mis à jour vers : $new_status", $_SESSION['user']['id']);
        $feedback_message = "Le statut du joueur a bien été mis à jour.";
    }
}

$search_query = $_GET['search'] ?? '';
$status_filter = $_GET['status'] ?? 'active_all';

$sql = "SELECT id, discord_id, username, display_name, status FROM users WHERE 1=1";
$params = [];

if (!empty($search_query)) {
    $sql .= " AND (username LIKE ? OR display_name LIKE ?)";
    $params[] = '%' . $search_query . '%';
    $params[] = '%' . $search_query . '%';
}

if ($status_filter === 'active_all') {
    $sql .= " AND status IN ('essai', 'essai_adhesion', 'essai_termine', 'joueur', 'active')";
} elseif ($status_filter !== 'all') {
    $sql .= " AND status = ?";
    $params[] = $status_filter;
}

$sql .= " ORDER BY display_name ASC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$users = $stmt->fetchAll(PDO::FETCH_ASSOC);

?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Gestion des Joueurs — Studios DCPJ</title>
    <link rel="icon" type="image/png" href="assets/logo.png">
    <link rel="stylesheet" href="assets/styles.css">
</head>
<body>
<?php include __DIR__.'/partiels_entete.php'; ?>
<div class="wrap page-container">
    <h2 class="mb-3">Gestion des Joueurs</h2>

    <?php if ($feedback_message): ?>
        <div class="alert alert-success"><?= htmlspecialchars($feedback_message) ?></div>
    <?php endif; ?>

    <div class="card mb-3">
        <form method="GET" style="display: flex; gap: 20px; align-items: center;">
            <input type="search" name="search" class="card" placeholder="Rechercher par pseudo..." value="<?= htmlspecialchars($search_query) ?>" style="flex-grow: 1; padding: 10px 16px;">
            <select name="status" class="card" onchange="this.form.submit()" style="padding: 10px 16px;">
                <option value="active_all" <?= $status_filter === 'active_all' ? 'selected' : '' ?>>Tous les Actifs (Joueurs + Essai)</option>
                <option value="joueur" <?= $status_filter === 'joueur' ? 'selected' : '' ?>>Joueurs</option>
                <option value="essai" <?= $status_filter === 'essai' ? 'selected' : '' ?>>En Période d'Essai</option>
                <option value="left" <?= $status_filter === 'left' ? 'selected' : '' ?>>Ayant Quitté</option>
                <option value="archive" <?= $status_filter === 'archive' ? 'selected' : '' ?>>Archivés</option>
                <option value="all" <?= $status_filter === 'all' ? 'selected' : '' ?>>Tout voir</option>
            </select>
            <button type="submit" class="btn btn-primary">Rechercher</button>
        </form>
    </div>

    <table style="width:100%;border-collapse:separate;border-spacing:0 8px">
        <thead>
            <tr style="color:#94a3b8">
                <th>Pseudo Discord</th>
                <th>Statut Actuel</th>
                <th style="text-align: right;">Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($users)): ?>
                <tr><td colspan="3" style="text-align:center;padding: 20px;">Aucun joueur ne correspond à votre recherche.</td></tr>
            <?php else: ?>
                <?php foreach ($users as $user): ?>
                    <tr>
                        <td>
                            <a href="/profil_joueur?id=<?= $user['id'] ?>" style="font-weight: bold; color: var(--text-primary); text-decoration: none;">
                                <?= htmlspecialchars($user['display_name'] ?? $user['username']) ?>
                            </a>
                        </td>
                        <td>
                            <?php 
                            $badge_class = 'status-rejected';
                            $status_text = strtoupper($user['status']);
                            if (in_array($user['status'], ['joueur', 'active'])) { $badge_class = 'status-accepted'; $status_text = 'JOUEUR'; }
                            elseif (in_array($user['status'], ['essai', 'essai_adhesion'])) { $badge_class = 'status-pending'; $status_text = 'ESSAI'; }
                            ?>
                            <span class="status-badge <?= $badge_class ?>"><?= $status_text ?></span>
                        </td>
                        <td style="text-align: right; display: flex; gap: 10px; justify-content: flex-end;">
                            <a href="/profil_joueur?id=<?= $user['id'] ?>" class="btn btn-sm">Profil complet</a>
                            <form method="POST" style="display: inline-block;">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                                <input type="hidden" name="user_id" value="<?= $user['id'] ?>">
                                <?php if (!in_array($user['status'], ['left', 'archive'])): ?>
                                    <input type="hidden" name="new_status" value="left">
                                    <button type="submit" class="btn btn-sm" style="background-color: var(--accent-red); border-color: var(--accent-red);" onclick="return confirm('Voulez-vous vraiment marquer ce joueur comme ayant quitté ?');">Quitté</button>
                                <?php endif; ?>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>
</body>
</html>