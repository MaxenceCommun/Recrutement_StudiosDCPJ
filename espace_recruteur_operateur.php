<?php
require_once __DIR__.'/config.php';
require_once __DIR__.'/bdd.php';
if (empty($_SESSION['user']['is_recruiter'])) { http_response_code(403); echo 'Accès refusé'; exit; }
$pdo = db();

$recruiter_type = $_SESSION['user']['recruiter_type'] ?? null;
$subtype_filter = null;
if ($recruiter_type === 'operator_fdo') {
    $subtype_filter = 'FDO';
    $page_title = 'Opérateurs FDO';
} elseif ($recruiter_type === 'operator_sp') {
    $subtype_filter = 'SP';
    $page_title = 'Opérateurs SP';
} else {
    header('Location: /espace_recruteur');
    exit;
}

// Statistiques spécifiques sécurisées
$st_tot = $pdo->prepare("SELECT COUNT(*) FROM applications WHERE application_type = 'operator' AND application_subtype = ?");
$st_tot->execute([$subtype_filter]);
$st_env = $pdo->prepare("SELECT COUNT(*) FROM applications WHERE application_type = 'operator' AND application_subtype = ? AND status = 'envoyee'");
$st_env->execute([$subtype_filter]);
$st_etu = $pdo->prepare("SELECT COUNT(*) FROM applications WHERE application_type = 'operator' AND application_subtype = ? AND status = 'en_etude'");
$st_etu->execute([$subtype_filter]);

$stats = [
    'total' => $st_tot->fetchColumn(),
    'envoyee' => $st_env->fetchColumn(),
    'en_etude' => $st_etu->fetchColumn(),
];

// Récupération des filtres
$show_archived = isset($_GET['archived']);
$search_term = $_GET['search'] ?? '';
$date_after = $_GET['date_after'] ?? '';
$date_before = $_GET['date_before'] ?? '';

$query = "SELECT a.id, a.status, a.recruiter_review_status, a.private_comments, a.created_at, u.username, u.display_name, a.answers_json 
          FROM applications a 
          JOIN users u ON u.id=a.user_id 
          WHERE a.application_type='operator' AND a.application_subtype=?";
$params = [$subtype_filter];

if (!$show_archived) { $query .= " AND a.status != 'archive'"; }

if (!empty($search_term)) {
    $query .= " AND (CAST(a.id AS CHAR) = ? OR u.username LIKE ? OR u.display_name LIKE ? OR a.answers_json LIKE ?)";
    $params[] = $search_term;
    $params[] = '%' . $search_term . '%';
    $params[] = '%' . $search_term . '%';
    $params[] = '%' . $search_term . '%';
}

if (!empty($date_after)) { $query .= " AND date(a.created_at) >= ?"; $params[] = $date_after; }
if (!empty($date_before)) { $query .= " AND date(a.created_at) <= ?"; $params[] = $date_before; }

$query .= " ORDER BY a.created_at DESC";

$status_labels = ['envoyee' => 'Envoyée', 'en_etude' => 'En étude', 'accepte' => 'Acceptée', 'refuse' => 'Refusée', 'archive' => 'Archivée', 'integration_faite' => 'Intégration faite'];
$review_labels = ['none' => 'Aucun', 'positive' => 'Positif ✅', 'negative' => 'Négatif ❌', 'dg_review' => 'Avis DG 👑'];

$st = $pdo->prepare($query);
$st->execute($params);
$rows = $st->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html><html lang="fr"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= $page_title ?> — Studios DCPJ</title>
<link rel="icon" type="image/png" href="assets/logo.png">
<link rel="stylesheet" href="assets/styles.css">
<style>
    .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 20px; margin-bottom: 40px; }
    .stat-card { background: var(--bg-card); border-radius: 16px; padding: 24px; border: 1px solid var(--border-color); position: relative; overflow: hidden; }
    .stat-card .val { font-size: 2.5rem; font-weight: 800; color: var(--text-primary); margin-bottom: 5px; }
    .stat-card .lbl { font-size: 0.9rem; color: var(--text-secondary); text-transform: uppercase; letter-spacing: 1px; }

    .search-bar { background: rgba(255, 255, 255, 0.03); backdrop-filter: blur(10px); border: 1px solid var(--border-color); border-radius: 16px; padding: 20px; margin-bottom: 30px; }
    
    .app-table { width: 100%; border-collapse: separate; border-spacing: 0 10px; margin-top: -10px; }
    .app-table th { padding: 15px 20px; color: var(--text-muted); font-size: 0.8rem; text-transform: uppercase; letter-spacing: 1px; border: none; }
    .app-table tr.row-item { background: var(--bg-secondary); transition: all 0.2s ease; cursor: pointer; }
    .app-table tr.row-item:hover { background: var(--bg-tertiary); transform: scale(1.005); box-shadow: 0 4px 20px rgba(0,0,0,0.2); }
    .app-table td { padding: 18px 20px; border: none; }
    .app-table td:first-child { border-radius: 12px 0 0 12px; }
    .app-table td:last-child { border-radius: 0 12px 12px 0; }

    .user-info { display: flex; align-items: center; gap: 15px; }
    .user-avatar { width: 40px; height: 40px; border-radius: 12px; background: var(--gradient-secondary); display: flex; align-items: center; justify-content: center; font-weight: bold; color: white; font-size: 1rem; }
    
    .status-badge { padding: 6px 12px; border-radius: 8px; font-weight: bold; font-size: 0.75rem; text-transform: uppercase; }
    .status-envoyee { background: rgba(52, 152, 219, 0.15); color: #3498db; }
    .status-en-etude { background: rgba(243, 156, 18, 0.15); color: #f39c12; }
    .status-accepte { background: rgba(39, 174, 96, 0.15); color: #27ae60; }
    .status-refuse { background: rgba(231, 76, 60, 0.15); color: #e74c3c; }

    .review-tag { font-size: 0.8rem; padding: 4px 10px; border-radius: 6px; background: rgba(255,255,255,0.05); color: var(--text-secondary); border: 1px solid var(--border-color); }
    .review-positive { border-color: #27ae60; color: #27ae60; }
    .review-negative { border-color: #e74c3c; color: #e74c3c; }
</style>
</head><body>
<?php include __DIR__.'/partiels_entete.php'; ?>
<div class="wrap page-container">
    
    <div style="display: flex; justify-content: space-between; align-items: flex-end; margin-bottom: 30px; flex-wrap: wrap; gap: 20px;">
        <div>
            <h1 class="mb-1">Recrutement <span class="grad"><?= $page_title ?></span></h1>
            <p class="text-muted">Gestion spécifique des pôles opérationnels.</p>
        </div>
        <a href="/espace_recruteur_operateur?<?= $show_archived ? '' : 'archived=1' ?>" class="btn <?= $show_archived ? 'btn-primary' : '' ?>">
            <?= $show_archived ? '📂 Cacher les archives' : '📁 Voir les archives' ?>
        </a>
    </div>

    <!-- Stats -->
    <div class="stats-grid">
        <div class="stat-card">
            <div class="val"><?= $stats['total'] ?></div>
            <div class="lbl">Total Dossiers</div>
        </div>
        <div class="stat-card" style="border-left: 4px solid var(--accent-secondary);">
            <div class="val"><?= $stats['envoyee'] ?></div>
            <div class="lbl">Nouvelles demandes</div>
        </div>
        <div class="stat-card" style="border-left: 4px solid var(--accent-tertiary);">
            <div class="val"><?= $stats['en_etude'] ?></div>
            <div class="lbl">En cours d'étude</div>
        </div>
    </div>

    <!-- Filtres -->
    <div class="search-bar card">
        <form method="get" id="filter-form">
            <div style="display: flex; gap: 15px; align-items: center; flex-wrap: wrap;">
                <div style="flex: 2; min-width: 220px;">
                    <input type="search" name="search" placeholder="Rechercher par ID, nom, Discord..." value="<?= htmlspecialchars($search_term) ?>" style="margin-bottom: 0;">
                </div>
                <div style="flex: 1; min-width: 200px; display: flex; gap: 10px;">
                    <input type="date" name="date_after" value="<?= htmlspecialchars($date_after) ?>" title="Après le" style="margin-bottom:0;">
                    <input type="date" name="date_before" value="<?= htmlspecialchars($date_before) ?>" title="Avant le" style="margin-bottom:0;">
                </div>
                <button type="submit" class="btn btn-primary">Filtrer</button>
            </div>
            <?php if ($show_archived): ?><input type="hidden" name="archived" value="1"><?php endif; ?>
        </form>
    </div>

    <!-- Table -->
    <div style="overflow-x: auto;">
        <table class="app-table">
            <thead>
                <tr>
                    <th style="width: 80px;">ID</th>
                    <th>Candidat</th>
                    <th>Pôle</th>
                    <th>Statut</th>
                    <th>Avis Staff</th>
                    <th>Date</th>
                    <th style="text-align: right;">Action</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($rows)): ?>
                    <tr><td colspan="7" class="text-center card" style="padding: 40px; border: none; background: transparent;">
                        <div style="font-size: 3rem; margin-bottom: 10px;">🤷‍♂️</div>
                        <p class="text-muted">Aucun dossier correspondant.</p>
                    </td></tr>
                <?php else: ?>
                    <?php foreach ($rows as $r): 
                        $answers = json_decode($r['answers_json'], true);
                        $initial = strtoupper(substr($r['display_name'] ?? $r['username'], 0, 1));
                        $rev_class = 'review-'.$r['recruiter_review_status'];
                    ?>
                        <tr class="row-item" onclick="window.location='/candidat?id=<?=$r['id']?>'">
                            <td style="font-weight: bold; color: var(--text-muted);">#<?=$r['id']?></td>
                            <td>
                                <div class="user-info">
                                    <div class="user-avatar"><?= $initial ?></div>
                                    <div>
                                        <div style="font-weight: 600;"><?= htmlspecialchars($r['display_name'] ?? $r['username']) ?></div>
                                        <div style="font-size: 0.75rem; color: var(--text-muted);">@<?= htmlspecialchars($r['username']) ?></div>
                                    </div>
                                </div>
                            </td>
                            <td style="font-weight: 500;"><?= htmlspecialchars($answers['activity'] ?? 'N/A') ?></td>
                            <td>
                                <span class="status-badge status-<?= $r['status'] ?>"><?= $status_labels[$r['status']] ?? $r['status'] ?></span>
                            </td>
                            <td>
                                <span class="review-tag <?= $rev_class ?>"><?= $review_labels[$r['recruiter_review_status']] ?? $r['recruiter_review_status'] ?></span>
                                <?php if (!empty($r['private_comments']) && $r['private_comments'] !== '[]'): ?>
                                    <span title="Notes présentes" style="margin-left:5px;">💬</span>
                                <?php endif; ?>
                            </td>
                            <td style="color: var(--text-muted); font-size: 0.85rem;">
                                <?= date('d/m/Y', strtotime($r['created_at'])) ?>
                            </td>
                            <td style="text-align: right;">
                                <a class="btn btn-sm" href="/candidat?id=<?=$r['id']?>" onclick="event.stopPropagation();">Dossier</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
</body></html>