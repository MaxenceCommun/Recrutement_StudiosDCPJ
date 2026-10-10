<?php
require_once __DIR__.'/config.php';
require_once __DIR__.'/bdd.php';
if (empty($_SESSION['user']['is_recruiter'])) { http_response_code(403); echo 'Accès refusé'; exit; }
$pdo = db();
ensure_database_schema($pdo);

// Liste complète des statuts pour référence
$all_statuses = [
    'attente' => 'En attente',
    'envoyee' => 'Envoyée (Opérateur)',
    'en_etude' => 'En étude (Opérateur)',
    'accepte_integration' => "Accepté (Intégration)",
    'accepte' => 'Acceptée',
    'refuse' => 'Refusé',
    'essai' => 'Période d\'essai',
    'essai_adhesion' => 'Essai (Adhésion)',
    'essai_termine' => 'Essai terminé',
    'joueur' => 'Joueur',
    'non_adherent' => 'Non adhérent',
    'ancien_joueur' => 'Ancien joueur',
    'archive' => 'Archivée',
];

// Récupération des rôles et droits de l'utilisateur connecté
$user_roles = [];
$db_recruiter_type = $_SESSION['user']['recruiter_type'] ?? null;
$db_is_bureau = !empty($_SESSION['user']['is_bureau']);
$db_is_secretariat = !empty($_SESSION['user']['is_secretariat']);

if (!empty($_SESSION['user']['id'])) {
    $st_u = $pdo->prepare("SELECT recruiter_type, is_bureau, is_secretariat, last_synced_roles FROM users WHERE id = ?");
    $st_u->execute([$_SESSION['user']['id']]);
    $u_data = $st_u->fetch(PDO::FETCH_ASSOC);
    if ($u_data) {
        if (!empty($u_data['last_synced_roles'])) {
            $user_roles = json_decode($u_data['last_synced_roles'], true) ?: [];
        }
        if (!empty($u_data['recruiter_type'])) {
            $db_recruiter_type = $u_data['recruiter_type'];
        }
        $db_is_bureau = $db_is_bureau || !empty($u_data['is_bureau']);
        $db_is_secretariat = $db_is_secretariat || !empty($u_data['is_secretariat']);
    }
}

// Direction / Administration (accès complet)
$is_admin = $db_is_bureau 
    || $db_is_secretariat 
    || !empty($_SESSION['user']['can_manage_access'])
    || (!empty($DISCORD_BUREAU_ROLE_ID) && in_array($DISCORD_BUREAU_ROLE_ID, $user_roles))
    || (!empty($DISCORD_SECRETARIAT_ROLE_ID) && in_array($DISCORD_SECRETARIAT_ROLE_ID, $user_roles));

// Droits d'accès par pôle
$can_view_players = $is_admin 
    || (!empty($DISCORD_RECRUITER_ROLE_ID) && in_array($DISCORD_RECRUITER_ROLE_ID, $user_roles))
    || (!empty($_SESSION['user']['can_manage_players']))
    || ($db_recruiter_type === 'player');

$can_view_operators = $is_admin 
    || (!empty($DISCORD_RECRUITER_OP_FDO_ROLE_ID) && in_array($DISCORD_RECRUITER_OP_FDO_ROLE_ID, $user_roles))
    || (!empty($DISCORD_RECRUITER_OP_SP_ROLE_ID) && in_array($DISCORD_RECRUITER_OP_SP_ROLE_ID, $user_roles))
    || in_array($db_recruiter_type, ['operator', 'operator_fdo', 'operator_sp']);

$can_view_developers = $is_admin 
    || (!empty($DISCORD_RESP_DEV_ROLE_ID) && in_array($DISCORD_RESP_DEV_ROLE_ID, $user_roles))
    || (!empty($DISCORD_DEV_NOTIF_ROLE_ID) && in_array($DISCORD_DEV_NOTIF_ROLE_ID, $user_roles))
    || ($db_recruiter_type === 'developer');

// Fallback pour recruteur général si aucun pôle spécifique n'est détecté
if (!$can_view_players && !$can_view_operators && !$can_view_developers) {
    $can_view_players = true;
}

$allowed_poles = [];
if ($can_view_players) $allowed_poles[] = 'player';
if ($can_view_operators) $allowed_poles[] = 'operator';
if ($can_view_developers) $allowed_poles[] = 'developer';

// Validation du filtre pôle demandé
$pole_filter = $_GET['pole'] ?? 'all';
if ($pole_filter === 'player' && !$can_view_players) {
    $pole_filter = 'all';
} elseif ($pole_filter === 'operator' && !$can_view_operators) {
    $pole_filter = 'all';
} elseif ($pole_filter === 'developer' && !$can_view_developers) {
    $pole_filter = 'all';
} elseif (!in_array($pole_filter, ['all', 'player', 'operator', 'developer'])) {
    $pole_filter = 'all';
}

// Statistiques rapides
$cnt_players = (int)$pdo->query("SELECT COUNT(*) FROM applications WHERE application_type = 'player'")->fetchColumn();
$cnt_players_attente = (int)$pdo->query("SELECT COUNT(*) FROM applications WHERE application_type = 'player' AND status = 'attente'")->fetchColumn();

$cnt_operators = (int)$pdo->query("SELECT COUNT(*) FROM applications WHERE application_type = 'operator'")->fetchColumn();
$cnt_operators_attente = (int)$pdo->query("SELECT COUNT(*) FROM applications WHERE application_type = 'operator' AND status IN ('envoyee', 'en_etude')")->fetchColumn();

$cnt_devs = (int)$pdo->query("SELECT COUNT(*) FROM applications WHERE application_type = 'developer'")->fetchColumn();
$cnt_devs_attente = (int)$pdo->query("SELECT COUNT(*) FROM applications WHERE application_type = 'developer' AND status = 'attente'")->fetchColumn();

$in_allowed_sql = "'" . implode("','", array_map('addslashes', $allowed_poles)) . "'";
$stats = [
    'total' => (int)$pdo->query("SELECT COUNT(*) FROM applications WHERE application_type IN ($in_allowed_sql)")->fetchColumn(),
    'attente' => (int)$pdo->query("SELECT COUNT(*) FROM applications WHERE application_type IN ($in_allowed_sql) AND status IN ('attente', 'envoyee', 'en_etude')")->fetchColumn(),
    'accepte' => (int)$pdo->query("SELECT COUNT(*) FROM applications WHERE application_type IN ($in_allowed_sql) AND status IN ('accepte_integration', 'accepte')")->fetchColumn(),
    'refuse' => (int)$pdo->query("SELECT COUNT(*) FROM applications WHERE application_type IN ($in_allowed_sql) AND status = 'refuse'")->fetchColumn(),
];

// Actions requises (To-Do)
$todo_items = [];

// 1. Demandes d'adhésion (PE) - Joueurs
if ($can_view_players) {
    $st_adhesion = $pdo->query("SELECT id, display_name FROM users WHERE status = 'essai_adhesion' LIMIT 5");
    $adhesions = $st_adhesion->fetchAll(PDO::FETCH_ASSOC);
    foreach ($adhesions as $u) {
        $todo_items[] = [
            'icon' => '🏆',
            'title' => 'Demande d\'adhésion',
            'desc' => htmlspecialchars($u['display_name']) . ' a validé sa PE (adhésion HelloAsso).',
            'link' => '/profil_joueur?id=' . $u['id'],
            'color' => 'var(--accent-success)'
        ];
    }
}

// 2. Période d'essai proche de la fin - Joueurs
if ($can_view_players) {
    $st_pe_fin = $pdo->query("SELECT id, display_name FROM users WHERE status = 'essai' AND trial_start_date <= DATE_SUB(NOW(), INTERVAL 25 DAY) LIMIT 5");
    $pe_fins = $st_pe_fin->fetchAll(PDO::FETCH_ASSOC);
    foreach ($pe_fins as $u) {
        $todo_items[] = [
            'icon' => '⚠️',
            'title' => 'Fin de PE imminente',
            'desc' => htmlspecialchars($u['display_name']) . ' arrive au bout de ses 30 jours d\'essai.',
            'link' => '/profil_joueur?id=' . $u['id'],
            'color' => 'var(--accent-tertiary)'
        ];
    }
}

// 3. Candidatures Développeur en attente
if ($can_view_developers) {
    $st_dev_attente = $pdo->query("SELECT a.id, u.display_name FROM applications a JOIN users u ON a.user_id = u.id WHERE a.application_type = 'developer' AND a.status = 'attente' ORDER BY a.created_at ASC LIMIT 5");
    $dev_attentes = $st_dev_attente->fetchAll(PDO::FETCH_ASSOC);
    foreach ($dev_attentes as $a) {
        $todo_items[] = [
            'icon' => '💻',
            'title' => 'Candidature Développeur',
            'desc' => 'Dossier et portfolio de ' . htmlspecialchars($a['display_name']) . ' à évaluer.',
            'link' => '/candidat?id=' . $a['id'],
            'color' => '#9b59b6'
        ];
    }
}

// 4. Candidatures Opérateur en attente
if ($can_view_operators) {
    $st_op_attente = $pdo->query("SELECT a.id, a.application_subtype, u.display_name FROM applications a JOIN users u ON a.user_id = u.id WHERE a.application_type = 'operator' AND a.status IN ('envoyee', 'en_etude') ORDER BY a.created_at ASC LIMIT 5");
    $op_attentes = $st_op_attente->fetchAll(PDO::FETCH_ASSOC);
    foreach ($op_attentes as $a) {
        $st_label = !empty($a['application_subtype']) ? ' (' . htmlspecialchars($a['application_subtype']) . ')' : '';
        $todo_items[] = [
            'icon' => '🎧',
            'title' => 'Candidature Opérateur' . $st_label,
            'desc' => 'Dossier de ' . htmlspecialchars($a['display_name']) . ' à étudier.',
            'link' => '/candidat?id=' . $a['id'],
            'color' => '#3498db'
        ];
    }
}

// 5. Candidatures Joueur en attente
if ($can_view_players) {
    $st_attente = $pdo->query("SELECT a.id, u.display_name FROM applications a JOIN users u ON a.user_id = u.id WHERE a.application_type = 'player' AND a.status = 'attente' ORDER BY a.created_at ASC LIMIT 5");
    $attentes = $st_attente->fetchAll(PDO::FETCH_ASSOC);
    foreach ($attentes as $a) {
        $todo_items[] = [
            'icon' => '⏳',
            'title' => 'Candidature Joueur',
            'desc' => 'La candidature de ' . htmlspecialchars($a['display_name']) . ' nécessite un avis.',
            'link' => '/candidat?id=' . $a['id'],
            'color' => 'var(--accent-primary)'
        ];
    }
}

// 6. Départs détectés sans note d'incident / départ rédigée
if ($can_view_players) {
    try {
        $st_dep_unnoted = $pdo->query("
            SELECT u.id, u.display_name, u.departure_date 
            FROM users u 
            WHERE u.status = 'left' 
              AND NOT EXISTS (
                  SELECT 1 FROM user_notes n 
                  WHERE n.user_id = u.id AND n.note_type IN ('departure', 'incident')
              )
            ORDER BY u.departure_date DESC LIMIT 5
        ");
        $unnoted_departures = $st_dep_unnoted->fetchAll(PDO::FETCH_ASSOC);
        foreach ($unnoted_departures as $dep) {
            $todo_items[] = [
                'icon' => '🚪',
                'title' => 'Départ à documenter',
                'desc' => htmlspecialchars($dep['display_name']) . ' a quitté le serveur (ajouter motif/captures).',
                'link' => '/profil_joueur?id=' . $dep['id'] . '#notes',
                'color' => 'var(--accent-red)'
            ];
        }
    } catch (Exception $e) {
        // Ne bloque pas la page
    }
}

$default_filters = ['attente', 'envoyee', 'en_etude', 'accepte_integration', 'accepte', 'refuse'];
$status_filters = $_GET['status'] ?? $default_filters;
if (!is_array($status_filters)) { $status_filters = $default_filters; }

$search_term = $_GET['search'] ?? '';
$date_after = $_GET['date_after'] ?? '';
$date_before = $_GET['date_before'] ?? '';

$params = [];
$query = "SELECT a.id, a.application_type, a.application_subtype, a.status as app_status, u.status as user_status, a.created_at, u.username, u.display_name 
          FROM applications a 
          JOIN users u ON u.id=a.user_id 
          WHERE 1=1";

if ($pole_filter === 'player' && $can_view_players) {
    $query .= " AND a.application_type = 'player'";
} elseif ($pole_filter === 'operator' && $can_view_operators) {
    $query .= " AND a.application_type = 'operator'";
} elseif ($pole_filter === 'developer' && $can_view_developers) {
    $query .= " AND a.application_type = 'developer'";
} else {
    $placeholders_poles = implode(',', array_fill(0, count($allowed_poles), '?'));
    $query .= " AND a.application_type IN ($placeholders_poles)";
    $params = array_merge($params, $allowed_poles);
}

if (!empty($status_filters)) {
    $placeholders = implode(',', array_fill(0, count($status_filters), '?'));
    $query .= " AND (a.status IN ($placeholders) OR u.status IN ($placeholders))";
    $params = array_merge($params, $status_filters, $status_filters);
}

if (!empty($search_term)) {
    $query .= " AND ( CAST(a.id AS CHAR) = ? OR u.username LIKE ? OR u.display_name LIKE ? OR a.answers_json LIKE ? )";
    $params[] = $search_term;
    $params[] = '%' . $search_term . '%';
    $params[] = '%' . $search_term . '%';
    $params[] = '%"email":"%' . $search_term . '%';
}

if (!empty($date_after)) { $query .= " AND date(a.created_at) >= ?"; $params[] = $date_after; }
if (!empty($date_before)) { $query .= " AND date(a.created_at) <= ?"; $params[] = $date_before; }

$query .= " ORDER BY a.created_at DESC";
$st = $pdo->prepare($query);
$st->execute($params);
$rows = $st->fetchAll(PDO::FETCH_ASSOC);

function get_final_status_info($app_status, $user_status, $all_statuses) {
    $status_priority = ['refuse', 'attente', 'envoyee', 'en_etude', 'accepte_integration', 'accepte', 'essai', 'essai_adhesion', 'essai_termine', 'joueur', 'non_adherent', 'ancien_joueur', 'archive'];
    $final_status = 'archive';
    if (in_array($user_status, $status_priority)) $final_status = $user_status;
    if (in_array($app_status, ['attente', 'envoyee', 'en_etude', 'accepte_integration', 'accepte', 'refuse'])) $final_status = $app_status;
    if ($app_status === 'archive') $final_status = 'archive';

    $label = $all_statuses[$final_status] ?? ucfirst(str_replace('_', ' ', $final_status));
    $css_class = 'status-' . str_replace('_', '-', $final_status);
    return ['label' => $label, 'class' => $css_class];
}
?>
<!DOCTYPE html><html lang="fr"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Espace Recruteur — Studios DCPJ</title>
<link rel="icon" type="image/png" href="assets/logo.png">
<link rel="stylesheet" href="assets/styles.css">
<style>
    .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px; margin-bottom: 40px; }
    .stat-card { background: var(--bg-card); border-radius: 16px; padding: 24px; border: 1px solid var(--border-color); position: relative; overflow: hidden; }
    .stat-card .val { font-size: 2.5rem; font-weight: 800; margin-bottom: 5px; color: var(--text-primary); }
    .stat-card .lbl { font-size: 0.9rem; color: var(--text-secondary); text-transform: uppercase; letter-spacing: 1px; }
    .stat-card::after { content: ''; position: absolute; right: -20px; bottom: -20px; width: 100px; height: 100px; background: var(--accent-primary); opacity: 0.05; border-radius: 50%; }

    .search-bar { background: rgba(255, 255, 255, 0.03); backdrop-filter: blur(10px); border: 1px solid var(--border-color); border-radius: 16px; padding: 20px; margin-bottom: 30px; }
    
    .app-table { 
        width: 100%; border-collapse: separate; border-spacing: 0 10px; margin-top: -10px;
    }
    .app-table th { padding: 15px 20px; color: var(--text-muted); font-size: 0.8rem; text-transform: uppercase; letter-spacing: 1px; border: none; }
    .app-table tr.row-item { background: var(--bg-secondary); transition: all 0.2s ease; cursor: pointer; }
    .app-table tr.row-item:hover { background: var(--bg-tertiary); transform: scale(1.005); box-shadow: 0 4px 20px rgba(0,0,0,0.2); }
    .app-table td { padding: 18px 20px; border: none; }
    .app-table td:first-child { border-radius: 12px 0 0 12px; }
    .app-table td:last-child { border-radius: 0 12px 12px 0; }

    .user-info { display: flex; align-items: center; gap: 15px; }
    .user-avatar { width: 36px; height: 36px; border-radius: 10px; background: var(--gradient-primary); display: flex; align-items: center; justify-content: center; font-weight: bold; color: white; font-size: 0.9rem; }
    
    .filter-pills { display: flex; flex-wrap: wrap; gap: 10px; margin-top: 15px; }
    .pill { padding: 6px 12px; border-radius: 20px; border: 1px solid var(--border-color); font-size: 0.8rem; color: var(--text-secondary); cursor: pointer; transition: all 0.2s; }
    .pill.active { background: var(--accent-primary); border-color: var(--accent-primary); color: white; }
    
    .status-badge { padding: 6px 14px; border-radius: 8px; font-weight: bold; font-size: 0.75rem; }
    .status-envoyee { background: rgba(52, 152, 219, 0.15); color: #3498db; }
    .status-en-etude { background: rgba(243, 156, 18, 0.15); color: #f39c12; }
    .status-accepte { background: rgba(39, 174, 96, 0.15); color: #27ae60; }
    
    .date-inputs { display: grid; grid-template-columns: 1fr 1fr; gap: 15px; margin-top: 15px; }
    
    .page-container { padding-bottom: 80px; }

    .recruiter-footer {
        margin-top: 70px;
        padding: 35px 0 45px;
        background: rgba(10, 14, 23, 0.75);
        border-top: 1px solid var(--border-color);
        width: 100%;
    }
    
    @media (max-width: 768px) { 
        .stats-grid { grid-template-columns: 1fr 1fr; }
        .recruiter-footer .footer-content { flex-direction: column; text-align: center; gap: 15px; }
    }
</style>
</head><body>
<?php include __DIR__.'/partiels_entete.php'; ?>
<div class="wrap page-container">
    
    <div style="margin-bottom: 30px;">
        <h1 class="mb-1">Espace <span class="grad">Recruteur</span></h1>
        <p class="text-muted">Gestion des candidatures Joueurs et suivi des intégrations.</p>
    </div>

    <!-- Statistiques -->
    <div class="stats-grid">
        <div class="stat-card">
            <div class="val"><?= $stats['total'] ?></div>
            <div class="lbl">Total Candidatures</div>
        </div>
        <div class="stat-card" style="border-left: 4px solid var(--accent-tertiary);">
            <div class="val"><?= $stats['attente'] ?></div>
            <div class="lbl">En Attente</div>
        </div>
        <div class="stat-card" style="border-left: 4px solid var(--accent-success);">
            <div class="val"><?= $stats['accepte'] ?></div>
            <div class="lbl">Acceptées / Intégration</div>
        </div>
        <div class="stat-card" style="border-left: 4px solid var(--accent-primary);">
            <div class="val"><?= $stats['refuse'] ?></div>
            <div class="lbl">Refusées</div>
        </div>
    </div>

    <!-- Dashboard "À Faire" -->
    <?php if (!empty($todo_items)): ?>
    <div class="card mb-4" style="border-left: 4px solid var(--accent-tertiary);">
        <h3 style="margin-top: 0; margin-bottom: 15px; display: flex; align-items: center; gap: 10px;">📋 Actions Prioritaires</h3>
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); gap: 15px;">
            <?php foreach ($todo_items as $item): ?>
            <a href="<?= htmlspecialchars($item['link']) ?>" style="display: block; text-decoration: none; background: rgba(0,0,0,0.1); border: 1px solid var(--border-color); border-radius: 10px; padding: 15px; transition: transform 0.2s, box-shadow 0.2s;">
                <div style="display: flex; gap: 15px; align-items: center;">
                    <div style="font-size: 2rem; background: <?= $item['color'] ?>; width: 50px; height: 50px; display: flex; align-items: center; justify-content: center; border-radius: 12px; border: 2px solid rgba(255,255,255,0.1);"><?= $item['icon'] ?></div>
                    <div>
                        <h4 style="margin: 0; color: var(--text-primary); font-size: 1.1rem;"><?= htmlspecialchars($item['title']) ?></h4>
                        <p style="margin: 5px 0 0 0; color: var(--text-secondary); font-size: 0.9rem;"><?= htmlspecialchars($item['desc']) ?></p>
                    </div>
                </div>
            </a>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- Onglets de sélection de pôle -->
    <div style="display: flex; gap: 10px; margin-bottom: 20px; flex-wrap: wrap;">
        <a href="?pole=all" class="btn <?= $pole_filter === 'all' ? 'btn-primary' : '' ?>" style="border-radius: 8px;">Tous</a>
        
        <?php if ($can_view_players): ?>
            <a href="?pole=player" class="btn <?= $pole_filter === 'player' ? 'btn-primary' : '' ?>" style="border-radius: 8px;">
                🎮 Joueurs (<?= $cnt_players ?>)
                <?php if ($cnt_players_attente > 0): ?>
                    <span style="background: var(--accent-tertiary, #e67e22); color: white; padding: 2px 7px; border-radius: 10px; font-size: 0.75rem; margin-left: 6px; font-weight: bold;"><?= $cnt_players_attente ?></span>
                <?php endif; ?>
            </a>
        <?php endif; ?>

        <?php if ($can_view_operators): ?>
            <a href="?pole=operator" class="btn <?= $pole_filter === 'operator' ? 'btn-primary' : '' ?>" style="border-radius: 8px; <?= $pole_filter === 'operator' ? 'background: #3498db; border-color: #3498db;' : '' ?>">
                🎧 Opérateurs (<?= $cnt_operators ?>)
                <?php if ($cnt_operators_attente > 0): ?>
                    <span style="background: #e74c3c; color: white; padding: 2px 7px; border-radius: 10px; font-size: 0.75rem; margin-left: 6px; font-weight: bold;"><?= $cnt_operators_attente ?></span>
                <?php endif; ?>
            </a>
        <?php endif; ?>

        <?php if ($can_view_developers): ?>
            <a href="?pole=developer" class="btn <?= $pole_filter === 'developer' ? 'btn-primary' : '' ?>" style="border-radius: 8px; <?= $pole_filter === 'developer' ? 'background: #9b59b6; border-color: #9b59b6;' : '' ?>">
                💻 Développeurs (<?= $cnt_devs ?>)
                <?php if ($cnt_devs_attente > 0): ?>
                    <span style="background: #e74c3c; color: white; padding: 2px 7px; border-radius: 10px; font-size: 0.75rem; margin-left: 6px; font-weight: bold;"><?= $cnt_devs_attente ?></span>
                <?php endif; ?>
            </a>
        <?php endif; ?>
    </div>

    <!-- Filtres & Recherche -->
    <div class="search-bar card">
        <form method="get" id="filter-form">
            <input type="hidden" name="pole" value="<?= htmlspecialchars($pole_filter) ?>">
            <div style="display: flex; gap: 15px; align-items: center; flex-wrap: wrap;">
                <div style="flex: 1; min-width: 220px;">
                    <input type="search" name="search" id="search" placeholder="Rechercher un candidat (ID, Discord, email...)" value="<?= htmlspecialchars($search_term) ?>" style="margin-bottom: 0;">
                </div>
                <button type="button" id="toggle-filters" class="btn"><span id="filter-icon">⚙️</span> Filtres avancés</button>
                <button type="submit" class="btn btn-primary">Rechercher</button>
            </div>

            <div id="filter-options" style="display: none; margin-top: 25px; pt-25: 25px; border-top: 1px solid var(--border-color);">
                <div style="margin-top:20px;">
                    <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 10px;">
                        <p class="mb-0" style="font-weight: bold; font-size: 0.9rem;">Statuts affichés :</p>
                        <button type="button" id="select-all-statuses" class="btn btn-sm" style="padding: 4px 10px; font-size: 0.75rem; border-radius: 6px; background: rgba(255,255,255,0.05); border: 1px solid var(--border-color); color: var(--text-secondary);">Tout sélectionner</button>
                    </div>
                    <div class="filter-pills">
                        <?php foreach ($all_statuses as $status_key => $status_label): ?>
                            <label class="pill <?= in_array($status_key, $status_filters) ? 'active' : '' ?>">
                                <input type="checkbox" name="status[]" value="<?= $status_key ?>" <?= in_array($status_key, $status_filters) ? 'checked' : '' ?> style="display:none;">
                                <?= htmlspecialchars($status_label) ?>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>
                
                <div class="date-inputs">
                    <div>
                        <label for="date_after" class="mb-1">Candidatures après le :</label>
                        <input type="date" name="date_after" id="date_after" value="<?= htmlspecialchars($date_after) ?>">
                    </div>
                    <div>
                        <label for="date_before" class="mb-1">Candidatures avant le :</label>
                        <input type="date" name="date_before" id="date_before" value="<?= htmlspecialchars($date_before) ?>">
                    </div>
                </div>
            </div>
        </form>
    </div>

    <!-- Liste -->
    <div style="overflow-x: auto;">
        <table class="app-table">
            <thead>
                <tr>
                    <th style="width: 80px;">ID</th>
                    <th>Candidat</th>
                    <th>Statut Actuel</th>
                    <th>Date de dépôt</th>
                    <th style="width: 120px; text-align: right;">Action</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($rows)): ?>
                    <tr><td colspan="5" class="text-center card" style="padding: 40px; border: none; background: transparent;">
                        <div style="font-size: 3rem; margin-bottom: 10px;">🔍</div>
                        <p class="text-muted">Aucune candidature trouvée avec ces critères.</p>
                        <a href="/espace_recruteur" class="btn mt-2">Réinitialiser les filtres</a>
                    </td></tr>
                <?php else: ?>
                    <?php foreach ($rows as $r): 
                        $status_info = get_final_status_info($r['app_status'], $r['user_status'], $all_statuses);
                        $initial = strtoupper(substr($r['display_name'] ?? $r['username'], 0, 1));
                    ?>
                        <tr class="row-item" onclick="window.location='/candidat?id=<?=$r['id']?>'">
                            <td style="font-weight: bold; color: var(--text-muted);">#<?=$r['id']?></td>
                            <td>
                                <div class="user-info">
                                    <div class="user-avatar"><?= $initial ?></div>
                                    <div>
                                        <div style="font-weight: 600; display: flex; align-items: center; gap: 6px; flex-wrap: wrap;">
                                            <span><?= htmlspecialchars($r['display_name'] ?? $r['username']) ?></span>
                                            <?php if (($r['application_type'] ?? '') === 'developer'): ?>
                                                <span style="font-size: 0.7rem; background: rgba(155, 89, 182, 0.2); color: #9b59b6; border: 1px solid rgba(155, 89, 182, 0.4); padding: 1px 6px; border-radius: 4px; font-weight: bold;">💻 Dev</span>
                                            <?php elseif (($r['application_type'] ?? '') === 'operator'): ?>
                                                <span style="font-size: 0.7rem; background: rgba(52, 152, 219, 0.2); color: #3498db; border: 1px solid rgba(52, 152, 219, 0.4); padding: 1px 6px; border-radius: 4px; font-weight: bold;">🎧 Opérateur<?= !empty($r['application_subtype']) ? ' ('.htmlspecialchars($r['application_subtype']).')' : '' ?></span>
                                            <?php elseif (($r['application_type'] ?? '') === 'player' && $pole_filter === 'all'): ?>
                                                <span style="font-size: 0.7rem; background: rgba(46, 204, 113, 0.15); color: #2ecc71; border: 1px solid rgba(46, 204, 113, 0.3); padding: 1px 6px; border-radius: 4px; font-weight: bold;">🎮 Joueur</span>
                                            <?php endif; ?>
                                        </div>
                                        <div style="font-size: 0.75rem; color: var(--text-muted);">@<?= htmlspecialchars($r['username']) ?></div>
                                    </div>
                                </div>
                            </td>
                            <td>
                                <span class="status-badge <?= $status_info['class'] ?>"><?= htmlspecialchars($status_info['label']) ?></span>
                            </td>
                            <td style="color: var(--text-secondary);">
                                <?= date('d/m/Y', strtotime($r['created_at'])) ?>
                                <small style="display: block; font-size: 0.7rem; opacity: 0.6;"><?= date('H:i', strtotime($r['created_at'])) ?></small>
                            </td>
                            <td style="text-align: right;">
                                <a class="btn btn-sm" href="/candidat?id=<?=$r['id']?>" onclick="event.stopPropagation();">Ouvrir</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<footer class="recruiter-footer">
    <div class="wrap footer-content" style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 20px;">
        <div style="display: flex; align-items: center; gap: 12px;">
            <img src="assets/logo.png" alt="Studios DCPJ" style="width: 32px; height: 32px; border-radius: 8px;">
            <div>
                <div style="font-weight: 700; color: var(--text-primary); font-size: 0.95rem;">Studios DCPJ</div>
                <div style="font-size: 0.8rem; color: var(--text-muted);">Plateforme de recrutement & gestion des effectifs</div>
            </div>
        </div>
        <div style="font-size: 0.85rem; color: var(--text-muted);">
            © <?= date('Y') ?> Studios DCPJ • Tous droits réservés
        </div>
        <div style="display: flex; gap: 20px; font-size: 0.85rem;">
            <a href="/espace_recruteur" style="color: var(--text-secondary); text-decoration: none;">Recrutement</a>
            <a href="/statistiques" style="color: var(--text-secondary); text-decoration: none;">Statistiques</a>
            <?php if (!empty($_SESSION['user']['can_manage_access']) || !empty($_SESSION['user']['is_bureau']) || !empty($_SESSION['user']['is_secretariat'])): ?>
                <a href="/gestion_acces" style="color: var(--text-secondary); text-decoration: none;">Gestion des Accès</a>
            <?php endif; ?>
        </div>
    </div>
</footer>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const filterOptions = document.getElementById('filter-options');
    const filterForm = document.getElementById('filter-form');
    const toggleBtn = document.getElementById('toggle-filters');

    // Toggle filtres
    toggleBtn.addEventListener('click', function() {
        if (filterOptions.style.display === 'none' || filterOptions.style.display === '') {
            filterOptions.style.display = 'block';
            this.classList.add('btn-primary');
        } else {
            filterOptions.style.display = 'none';
            this.classList.remove('btn-primary');
        }
    });

    // Tout sélectionner
    const selectAllBtn = document.getElementById('select-all-statuses');
    if (selectAllBtn) {
        selectAllBtn.addEventListener('click', function() {
            const checkboxes = filterForm.querySelectorAll('input[name="status[]"]');
            const allChecked = Array.from(checkboxes).every(cb => cb.checked);
            
            checkboxes.forEach(cb => {
                cb.checked = !allChecked;
                const pill = cb.closest('.pill');
                if (cb.checked) pill.classList.add('active');
                else pill.classList.remove('active');
            });
            
            // Mise à jour du texte du bouton (optionnel mais sympa)
            // this.textContent = !allChecked ? 'Tout déselectionner' : 'Tout sélectionner';
            
            filterForm.submit();
        });
    }

    // Auto-soumission sur pills et dates
    filterForm.querySelectorAll('.pill input, input[type="date"]').forEach(input => {
        input.addEventListener('change', () => filterForm.submit());
    });
    
    // Visuel des pills
    filterForm.querySelectorAll('.pill').forEach(label => {
        label.addEventListener('click', function() {
            this.classList.toggle('active');
        });
    });

    // Garder ouvert si filtres actifs
    const urlParams = new URLSearchParams(window.location.search);
    if (urlParams.has('date_after') || urlParams.has('date_before') || urlParams.has('status[]')) {
        filterOptions.style.display = 'block';
        toggleBtn.classList.add('btn-primary');
    }
});
</script>

</body></html>

