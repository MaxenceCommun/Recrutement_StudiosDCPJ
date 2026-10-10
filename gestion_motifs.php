<?php
require_once __DIR__.'/config.php';
require_once __DIR__.'/bdd.php';

// Security
if (empty($_SESSION['user']) || (empty($_SESSION['user']['is_bureau']) && empty($_SESSION['user']['is_secretariat']) && empty($_SESSION['user']['can_manage_reasons']))) {
    http_response_code(403);
    exit('Accès refusé');
}

$pdo = db();
$feedback = '';

// CSRF Protection
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        http_response_code(403);
        exit('Erreur CSRF : session invalide ou expirée.');
    }
    if (isset($_POST['add_reason'])) {
        $delay_unit = in_array($_POST['delay_unit'] ?? '', ['days', 'months', 'years']) ? $_POST['delay_unit'] : 'months';
        $st = $pdo->prepare("INSERT INTO rejection_reasons (reason_text, sanction_type, delay_value, delay_unit, discord_ban, roles_added, roles_removed) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $roles_added = !empty($_POST['roles_added']) ? json_encode(array_map('trim', explode(',', $_POST['roles_added']))) : null;
        $roles_removed = !empty($_POST['roles_removed']) ? json_encode(array_map('trim', explode(',', $_POST['roles_removed']))) : null;
        
        $st->execute([
            $_POST['reason_text'],
            $_POST['sanction_type'],
            (int)$_POST['delay_value'],
            $delay_unit,
            isset($_POST['discord_ban']) ? 1 : 0,
            $roles_added,
            $roles_removed
        ]);
        $feedback = "Motif ajouté avec succès.";
    } elseif (isset($_POST['delete_reason'])) {
        $st = $pdo->prepare("UPDATE rejection_reasons SET is_active = 0 WHERE id = ?");
        $st->execute([(int)$_POST['reason_id']]);
        $feedback = "Motif désactivé avec succès.";
    }
}

$reasons = $pdo->query("SELECT * FROM rejection_reasons WHERE is_active = 1 ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Gestion des Motifs de Refus — Studios DCPJ</title>
    <link rel="icon" type="image/png" href="assets/logo.png">
    <link rel="stylesheet" href="assets/styles.css">
    <style>
        .table { width: 100%; border-collapse: collapse; margin-top: 20px; }
        .table th, .table td { padding: 12px; border: 1px solid var(--border-color); text-align: left; }
        .table th { background: var(--bg-secondary); }
    </style>
</head>
<body>
<?php include __DIR__.'/partiels_entete.php'; ?>
<div class="wrap page-container">
    <h2>Gestion des Motifs de Refus & Invalidation</h2>
    
    <?php if ($feedback): ?><div class="alert alert-success"><?= $feedback ?></div><?php endif; ?>

    <div class="card mb-3">
        <h3>Ajouter un nouveau motif</h3>
        <form method="POST" style="display: grid; gap: 15px; grid-template-columns: 1fr 1fr;">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
            <input type="hidden" name="add_reason" value="1">
            <div class="form-group" style="grid-column: 1 / -1;">
                <label>Texte du motif</label>
                <input type="text" name="reason_text" class="card" required placeholder="Ex: Manque de sérieux...">
            </div>
            <div class="form-group">
                <label>Type de sanction</label>
                <select name="sanction_type" class="card" required>
                    <option value="delay">Délai avant repostulation</option>
                    <option value="temp_ban">Bannissement Temporaire</option>
                    <option value="def_ban">Bannissement Définitif</option>
                </select>
            </div>
            <div class="form-group" style="display: flex; gap: 10px; align-items: flex-end;">
                <div style="flex: 1;">
                    <label>Valeur du délai</label>
                    <input type="number" name="delay_value" class="card" value="0" min="0" max="999" style="margin: 0; width: 100%;">
                </div>
                <div style="width: 120px;">
                    <label>Unité</label>
                    <select name="delay_unit" class="card" style="margin: 0; width: 100%;">
                        <option value="days">Jour(s)</option>
                        <option value="months" selected>Mois</option>
                        <option value="years">An(s)</option>
                    </select>
                </div>
            </div>
            <div class="form-group checkbox-group" style="grid-column: 1 / -1;">
                <label style="flex-direction: row; align-items: center;">
                    <input type="checkbox" name="discord_ban"> Bannissement du Discord Public
                </label>
            </div>
            <div class="form-group">
                <label>Rôles à ajouter (IDs séparés par des virgules)</label>
                <input type="text" name="roles_added" class="card" placeholder="123456789,987654321">
            </div>
            <div class="form-group">
                <label>Rôles à retirer (IDs séparés par des virgules)</label>
                <input type="text" name="roles_removed" class="card" placeholder="123456789">
            </div>
            <div style="grid-column: 1 / -1;">
                <button type="submit" class="btn btn-primary">Ajouter le motif</button>
            </div>
        </form>
    </div>

    <div class="card">
        <h3>Motifs Actifs</h3>
        <table class="table">
            <thead>
                <tr>
                    <th>Raison</th>
                    <th>Sanction</th>
                    <th>Délai</th>
                    <th>Effets</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($reasons as $r): 
                    $u_label = 'mois';
                    if (($r['delay_unit'] ?? '') === 'days') $u_label = 'jour(s)';
                    elseif (($r['delay_unit'] ?? '') === 'years') $u_label = 'an(s)';
                ?>
                <tr>
                    <td><?= htmlspecialchars($r['reason_text']) ?></td>
                    <td><?= $r['sanction_type'] == 'def_ban' ? 'Définitif' : ($r['sanction_type'] == 'temp_ban' ? 'Ban Temp.' : 'Délai simple') ?></td>
                    <td><?= $r['sanction_type'] == 'def_ban' ? 'Définitif' : ($r['delay_value'] > 0 ? $r['delay_value'] . ' ' . $u_label : 'Sans délai') ?></td>
                    <td>
                        <?= $r['discord_ban'] ? 'Ban Discord<br>' : '' ?>
                        <?= $r['roles_added'] ? 'Ajout rôles: ' . htmlspecialchars($r['roles_added']) . '<br>' : '' ?>
                        <?= $r['roles_removed'] ? 'Retrait rôles: ' . htmlspecialchars($r['roles_removed']) : '' ?>
                    </td>
                    <td>
                        <form method="POST" onsubmit="return confirm('Supprimer ce motif ?');">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                            <input type="hidden" name="delete_reason" value="1">
                            <input type="hidden" name="reason_id" value="<?= $r['id'] ?>">
                            <button type="submit" class="btn" style="background-color: var(--accent-red);">Supprimer</button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
</body>
</html>
