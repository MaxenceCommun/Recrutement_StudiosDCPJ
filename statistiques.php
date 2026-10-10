<?php
require_once __DIR__.'/config.php';
require_once __DIR__.'/bdd.php';

if (empty($_SESSION['user']['is_recruiter'])) {
    http_response_code(403);
    exit('Accès refusé');
}

$pdo = db();

// Données : Candidatures par mois (les 12 derniers mois)
$st = $pdo->query("
    SELECT 
        DATE_FORMAT(created_at, '%Y-%m') as mois,
        status,
        COUNT(*) as total
    FROM applications
    WHERE application_type = 'player' AND created_at >= DATE_SUB(NOW(), INTERVAL 12 MONTH)
    GROUP BY DATE_FORMAT(created_at, '%Y-%m'), status
    ORDER BY mois ASC
");
$raw_data = $st->fetchAll(PDO::FETCH_ASSOC);

$months = [];
$stats = [];
foreach ($raw_data as $row) {
    $m = $row['mois'];
    if (!in_array($m, $months)) {
        $months[] = $m;
        $stats[$m] = ['total' => 0, 'accepte' => 0, 'refuse' => 0];
    }
    $stats[$m]['total'] += $row['total'];
    if (in_array($row['status'], ['accepte_integration', 'essai', 'essai_adhesion', 'essai_termine', 'joueur'])) {
        $stats[$m]['accepte'] += $row['total'];
    } elseif (in_array($row['status'], ['refuse', 'archive'])) {
        $stats[$m]['refuse'] += $row['total'];
    }
}

// Convert data for Chart.js
$labels = [];
$data_total = [];
$data_accepte = [];
$data_refuse = [];

$mois_fr = ['01'=>'Jan', '02'=>'Fév', '03'=>'Mar', '04'=>'Avr', '05'=>'Mai', '06'=>'Juin', '07'=>'Juil', '08'=>'Aoû', '09'=>'Sep', '10'=>'Oct', '11'=>'Nov', '12'=>'Déc'];

foreach ($months as $m) {
    $parts = explode('-', $m);
    $labels[] = ($mois_fr[$parts[1]] ?? $parts[1]) . ' ' . $parts[0];
    $data_total[] = $stats[$m]['total'];
    $data_accepte[] = $stats[$m]['accepte'];
    $data_refuse[] = $stats[$m]['refuse'];
}

$chart_labels = json_encode($labels);
$chart_total = json_encode($data_total);
$chart_accepte = json_encode($data_accepte);
$chart_refuse = json_encode($data_refuse);

// Quelques stats globales
$total_all_time = $pdo->query("SELECT COUNT(*) FROM applications WHERE application_type = 'player'")->fetchColumn();
$total_players = $pdo->query("SELECT COUNT(*) FROM users WHERE status IN ('joueur', 'active')")->fetchColumn();
$total_trials = $pdo->query("SELECT COUNT(*) FROM users WHERE status IN ('essai', 'essai_adhesion')")->fetchColumn();

?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Statistiques — Studios DCPJ</title>
    <link rel="icon" type="image/png" href="assets/logo.png">
    <link rel="stylesheet" href="assets/styles.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 20px; margin-bottom: 40px; }
        .stat-card { background: var(--bg-secondary); border-radius: 16px; padding: 24px; border: 1px solid var(--border-color); }
        .stat-card .val { font-size: 2.5rem; font-weight: 800; margin-bottom: 5px; color: var(--text-primary); }
        .stat-card .lbl { font-size: 0.9rem; color: var(--text-secondary); text-transform: uppercase; letter-spacing: 1px; }
        .chart-container { background: var(--bg-secondary); border: 1px solid var(--border-color); border-radius: 16px; padding: 20px; position: relative; height: 400px; width: 100%; }
    </style>
</head>
<body>
<?php include __DIR__.'/partiels_entete.php'; ?>
<div class="wrap page-container">
    <div style="margin-bottom: 30px; display: flex; justify-content: space-between; align-items: center;">
        <div>
            <h1 class="mb-1">Statistiques <span class="grad">Globales</span></h1>
            <p class="text-muted">Aperçu des recrutements et du suivi des joueurs.</p>
        </div>
        <a href="/espace_recruteur" class="btn">Retour Recrutement</a>
    </div>

    <div class="stats-grid">
        <div class="stat-card" style="border-left: 4px solid var(--accent-tertiary);">
            <div class="val"><?= $total_all_time ?></div>
            <div class="lbl">Candidatures (Historique)</div>
        </div>
        <div class="stat-card" style="border-left: 4px solid var(--accent-success);">
            <div class="val"><?= $total_players ?></div>
            <div class="lbl">Joueurs Actifs</div>
        </div>
        <div class="stat-card" style="border-left: 4px solid var(--accent-primary);">
            <div class="val"><?= $total_trials ?></div>
            <div class="lbl">Joueurs en PE</div>
        </div>
    </div>

    <h2 class="mb-2">Évolution des candidatures (12 derniers mois)</h2>
    <div class="chart-container">
        <canvas id="recruitmentChart"></canvas>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const ctx = document.getElementById('recruitmentChart').getContext('2d');
    
    // Style variables for charts
    const primaryColor = getComputedStyle(document.documentElement).getPropertyValue('--accent-primary').trim() || '#3498db';
    const successColor = getComputedStyle(document.documentElement).getPropertyValue('--accent-success').trim() || '#2ecc71';
    const redColor = getComputedStyle(document.documentElement).getPropertyValue('--accent-red').trim() || '#e74c3c';
    const textColor = getComputedStyle(document.documentElement).getPropertyValue('--text-primary').trim() || '#ffffff';
    const gridColor = 'rgba(255, 255, 255, 0.1)';

    new Chart(ctx, {
        type: 'bar',
        data: {
            labels: <?= $chart_labels ?>,
            datasets: [
                {
                    label: 'Acceptées / Intégrées',
                    data: <?= $chart_accepte ?>,
                    backgroundColor: successColor,
                    borderRadius: 4,
                    stack: 'Stack 0'
                },
                {
                    label: 'Refusées',
                    data: <?= $chart_refuse ?>,
                    backgroundColor: redColor,
                    borderRadius: 4,
                    stack: 'Stack 0'
                },
                {
                    label: 'Total Candidatures',
                    data: <?= $chart_total ?>,
                    type: 'line',
                    borderColor: primaryColor,
                    borderWidth: 3,
                    fill: false,
                    tension: 0.3,
                    pointBackgroundColor: primaryColor
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    labels: { color: textColor }
                },
                tooltip: {
                    mode: 'index',
                    intersect: false
                }
            },
            scales: {
                x: {
                    stacked: true,
                    ticks: { color: textColor },
                    grid: { color: 'transparent' }
                },
                y: {
                    stacked: true,
                    ticks: { color: textColor, precision: 0 },
                    grid: { color: gridColor },
                    beginAtZero: true
                }
            }
        }
    });
});
</script>
</body>
</html>
