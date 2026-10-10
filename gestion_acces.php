<?php
require_once __DIR__.'/config.php';
require_once __DIR__.'/bdd.php';
require_once __DIR__.'/api_discord.php';

// Sécurité
if (empty($_SESSION['user']) || (empty($_SESSION['user']['is_bureau']) && empty($_SESSION['user']['is_secretariat']) && empty($_SESSION['user']['can_manage_access']))) {
    http_response_code(403);
    exit('Accès refusé');
}

$pdo = db();
ensure_database_schema($pdo);

// Définition exhaustive et catégorisée de tous les accès du système
$permission_categories = [
    'Administration & Paramètres' => [
        'can_manage_access' => [
            'name' => 'Gérer les Accès',
            'desc' => "Donne accès à la page de gestion des permissions par rôle et par utilisateur.",
            'icon' => '🔐'
        ],
        'can_manage_quiz' => [
            'name' => 'Gestion du Quiz d\'Intégration',
            'desc' => "Permet d'ajouter, modifier et supprimer les questions et réponses du quiz d'intégration.",
            'icon' => '❓'
        ],
        'can_manage_reasons' => [
            'name' => 'Gestion des Motifs de Refus',
            'desc' => "Permet d'administrer la liste des motifs de refus de candidature et les délais associés.",
            'icon' => '📋'
        ],
    ],
    'Recrutement & Candidatures' => [
        'can_override_decision' => [
            'name' => 'Modifier une Décision Finale',
            'desc' => "Permet de modifier le statut d'une candidature déjà finalisée (ex: réouvrir ou changer Acceptée / Refusée).",
            'icon' => '⚖️'
        ],
        'can_delete_application' => [
            'name' => 'Supprimer une Candidature',
            'desc' => "Permet la suppression définitive d'un dossier de candidature de la base de données.",
            'icon' => '🗑️'
        ],
        'can_view_stats' => [
            'name' => 'Consulter les Statistiques',
            'desc' => "Donne accès à la page des statistiques et métriques globales de recrutement.",
            'icon' => '📊'
        ],
    ],
    'Gestion des Joueurs' => [
        'can_manage_players' => [
            'name' => 'Outils Gestion Joueurs',
            'desc' => "Accès à la page de gestion rapide des statuts des joueurs et de leurs départs.",
            'icon' => '👥'
        ],
        'can_change_player_status' => [
            'name' => 'Forcer le Statut d\'un Joueur',
            'desc' => "Autorise à forcer le statut sur le profil d'un joueur (Joueur, Essai, Exclu, Banni, Ayant Quitté).",
            'icon' => '🔄'
        ],
        'can_validate_membership' => [
            'name' => 'Valider l\'Adhésion Annuelle',
            'desc' => "Autorise à valider l'adhésion officielle d'un joueur jusqu'au 31/12 avec attribution des rôles Discord.",
            'icon' => '🏆'
        ],
        'can_extend_trial' => [
            'name' => 'Prolonger la Période d\'Essai',
            'desc' => "Permet de prolonger la durée de la période d'essai d'un joueur depuis sa fiche de profil.",
            'icon' => '⏳'
        ],
        'can_set_reapply_delay' => [
            'name' => 'Définir un Délai de Repostulation',
            'desc' => "Autorise à fixer ou modifier le délai de blocage avant repostulation (ou bannissement définitif).",
            'icon' => '🚫'
        ],
    ],
    'Discipline & Sanctions (FTA)' => [
        'can_view_fta' => [
            'name' => 'Consulter le FTA',
            'desc' => "Donne un accès en lecture au Fichier des Sanctions et aux dossiers disciplinaires.",
            'icon' => '👁️'
        ],
        'can_add_fta' => [
            'name' => 'Inscrire une Sanction au FTA',
            'desc' => "Autorise l'inscription et la publication d'une nouvelle sanction dans le FTA.",
            'icon' => '➕'
        ],
        'can_edit_fta' => [
            'name' => 'Modifier / Supprimer une Sanction',
            'desc' => "Autorise la modification ou la suppression de sanctions existantes et la gestion des infractions.",
            'icon' => '✏️'
        ],
    ]
];

// Liste aplatie de toutes les permissions pour les boucles SQL
$specific_permissions = [];
foreach ($permission_categories as $cat => $perms) {
    foreach ($perms as $pkey => $pdata) {
        $specific_permissions[$pkey] = $pdata;
    }
}

// Définition des rôles Discord gérables
$managed_roles = [
    'Bureau (Direction Générale)' => $DISCORD_BUREAU_ROLE_ID,
    'Secrétariat Général' => $DISCORD_SECRETARIAT_ROLE_ID,
    'Cadre' => $DISCORD_CADRE_ROLE_ID,
    'Responsable Développement' => $DISCORD_RESP_DEV_ROLE_ID ?? '1505202049493504090',
    'Recruteur Joueur' => $DISCORD_RECRUITER_ROLE_ID,
    'Recruteur Opérateurs FDO' => $DISCORD_RECRUITER_OP_FDO_ROLE_ID,
    'Recruteur Opérateurs SP' => $DISCORD_RECRUITER_OP_SP_ROLE_ID,
];

// CSRF Protection
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

// Traitement du formulaire POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        http_response_code(403);
        exit('Erreur CSRF : session invalide ou expirée.');
    }
    
    if (isset($_POST['update_role_permissions'])) {
        foreach ($managed_roles as $role_name => $role_id) {
            if (empty($role_id)) continue;
            $perms_for_role = [];
            foreach ($specific_permissions as $perm_key => $perm_data) {
                if (!empty($_POST['role_perms'][$role_id][$perm_key])) {
                    $perms_for_role[$perm_key] = 1;
                }
            }
            $pdo->prepare("REPLACE INTO role_permissions (role_id, permissions_json) VALUES (?, ?)")
                ->execute([$role_id, json_encode($perms_for_role)]);
        }
        log_user_action($_SESSION['user']['id'], 'perms_update', "Mise à jour des permissions de rôles dans la Gestion des Accès.", $_SESSION['user']['id']);
        $_SESSION['flash_success'] = "Les permissions des rôles ont été enregistrées avec succès.";
    }
    elseif (isset($_POST['update_user_permissions'])) {
        $target_user_id = (int)$_POST['user_id'];
        $sql_parts = [];
        $params = ['id' => $target_user_id];
        foreach ($specific_permissions as $perm_key => $perm_data) {
            $sql_parts[] = "$perm_key = :$perm_key";
            $value = $_POST['user_perms'][$target_user_id][$perm_key] ?? 'inherit';
            $params[$perm_key] = ($value === 'inherit') ? null : (int)$value;
        }
        $sql = "UPDATE users SET " . implode(', ', $sql_parts) . " WHERE id = :id";
        $pdo->prepare($sql)->execute($params);
        log_user_action($target_user_id, 'perms_update', "Permissions individuelles mises à jour pour l'utilisateur #$target_user_id.", $_SESSION['user']['id']);
        $_SESSION['flash_success'] = "Les permissions de l'utilisateur ont été mises à jour.";
    }
    
    header("Location: /gestion_acces?tab=" . ($_POST['active_tab'] ?? 'roles'));
    exit;
}

// Récupération des données pour l'affichage
$role_perms_from_db = $pdo->query("SELECT * FROM role_permissions")->fetchAll(PDO::FETCH_KEY_PAIR);
$all_users_from_db = $pdo->query("SELECT * FROM users ORDER BY display_name ASC, username ASC")->fetchAll(PDO::FETCH_ASSOC);
$active_tab = $_GET['tab'] ?? 'roles';
$flash_success = $_SESSION['flash_success'] ?? null;
unset($_SESSION['flash_success']);
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Gestion Avancée des Accès — Studios DCPJ</title>
    <link rel="icon" type="image/png" href="assets/logo.png">
    <link rel="stylesheet" href="assets/styles.css">
    <style>
        .page-container.wide-container {
            max-width: 96vw;
            width: 96%;
            margin: 0 auto;
            padding-left: 15px;
            padding-right: 15px;
        }
        @media (min-width: 1800px) {
            .page-container.wide-container {
                max-width: 1720px;
            }
        }
        .page-header-box {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
            margin-bottom: 20px;
        }
        .tabs { 
            display: flex; 
            border-bottom: 1px solid var(--border-color); 
            margin-bottom: 24px; 
            gap: 10px;
        }
        .tab-link { 
            padding: 12px 24px; 
            cursor: pointer; 
            color: var(--text-secondary); 
            text-decoration: none; 
            font-weight: 600;
            border-radius: 8px 8px 0 0;
            transition: all 0.2s ease;
        }
        .tab-link:hover {
            color: var(--text-primary);
            background: rgba(255,255,255,0.03);
        }
        .tab-link.active { 
            color: var(--text-primary); 
            border-bottom: 3px solid var(--accent-primary); 
            background: rgba(231, 76, 60, 0.08);
        }
        .tab-content { display: none; }
        .tab-content.active { display: block; animation: fadeIn 0.3s; }
        @keyframes fadeIn { from { opacity: 0; transform: translateY(4px); } to { opacity: 1; transform: translateY(0); } }

        /* Style tableau moderne */
        .perm-card-table {
            background: var(--bg-secondary);
            border: 1px solid var(--border-color);
            border-radius: 12px;
            overflow: hidden;
            margin-bottom: 20px;
        }
        .perm-category-header {
            background: rgba(255,255,255,0.03);
            border-bottom: 1px solid var(--border-color);
            padding: 12px 18px;
            font-weight: 700;
            font-size: 0.95rem;
            color: var(--accent-secondary);
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .permissions-table { 
            width: 100%; 
            border-collapse: collapse;
            font-size: 0.88rem; 
        }
        .permissions-table th, .permissions-table td { 
            padding: 10px 10px; 
            border-bottom: 1px solid var(--border-color);
            vertical-align: middle;
        }
        .permissions-table tbody tr:hover {
            background: rgba(255, 255, 255, 0.02);
        }
        .permissions-table th {
            background: var(--bg-tertiary);
            color: var(--text-secondary);
            font-weight: 600;
            text-align: center;
            font-size: 0.76rem;
            text-transform: uppercase;
            letter-spacing: 0.3px;
            line-height: 1.25;
            padding: 10px 6px;
            white-space: normal;
            word-break: break-word;
        }
        .permissions-table th:first-child { text-align: left; }
        .permissions-table td:first-child { text-align: left; }
        .permissions-table td { text-align: center; }

        .perm-desc-text {
            font-size: 0.76rem;
            color: var(--text-muted);
            margin-top: 2px;
            font-weight: normal;
            line-height: 1.3;
        }
        .perm-title {
            font-weight: 600;
            color: var(--text-primary);
            display: flex;
            align-items: center;
            gap: 6px;
        }

        /* Checkbox stylée */
        .perm-checkbox {
            width: 18px;
            height: 18px;
            cursor: pointer;
            accent-color: var(--accent-primary);
        }

        /* Radio group */
        .permission-radio-group { 
            display: inline-flex; 
            background: var(--bg-primary); 
            border: 1px solid var(--border-color); 
            border-radius: 8px; 
            padding: 3px; 
            gap: 2px; 
        }
        .permission-radio-group label { 
            display: flex; 
            align-items: center; 
            gap: 5px; 
            padding: 5px 10px; 
            font-size: 0.78rem; 
            cursor: pointer; 
            border-radius: 6px; 
            transition: all 0.2s;
            margin: 0;
            color: var(--text-secondary);
        }
        .permission-radio-group input[type=radio] { display: none; }
        .permission-radio-group label:has(input[value="1"]:checked) { 
            background: rgba(46, 204, 113, 0.2); 
            color: #2ecc71; 
            font-weight: bold; 
        }
        .permission-radio-group label:has(input[value="inherit"]:checked) { 
            background: rgba(52, 152, 219, 0.2); 
            color: #3498db; 
            font-weight: bold; 
        }
        .permission-radio-group label:has(input[value="0"]:checked) { 
            background: rgba(231, 76, 60, 0.2); 
            color: #e74c3c; 
            font-weight: bold; 
        }
        .permission-radio-group label:hover { 
            background: rgba(255,255,255,0.05); 
            color: var(--text-primary); 
        }

        /* Accordion utilisateurs */
        .user-accordion details { 
            background: var(--bg-secondary); 
            border: 1px solid var(--border-color); 
            border-radius: 10px; 
            margin-bottom: 10px; 
            transition: border-color 0.2s;
        }
        .user-accordion details[open] {
            border-color: var(--accent-primary);
        }
        .user-accordion summary { 
            padding: 15px 18px; 
            font-weight: bold; 
            cursor: pointer; 
            display: flex; 
            justify-content: space-between; 
            align-items: center; 
            border-radius: 10px;
        }
        .user-accordion summary:hover { 
            background: var(--bg-tertiary); 
        }
        .user-accordion .permissions-content { 
            padding: 15px 18px 20px 18px; 
            border-top: 1px solid var(--border-color); 
        }
        .user-accordion summary::after { 
            content: '▼'; 
            font-size: 0.8em; 
            transition: transform 0.2s; 
            color: var(--text-muted); 
        }
        .user-accordion details[open] summary::after { 
            transform: rotate(180deg); 
            color: var(--accent-primary); 
        }

        #search-user { 
            width: 100%; 
            padding: 13px 18px; 
            margin-bottom: 20px; 
            background: var(--bg-secondary); 
            border: 1px solid var(--border-color); 
            border-radius: 10px; 
            color: var(--text-primary); 
            font-size: 0.95rem;
            box-sizing: border-box;
        }
        #search-user:focus {
            border-color: var(--accent-primary);
            outline: none;
        }

        .role-pill {
            display: inline-block;
            font-size: 0.75rem;
            padding: 3px 8px;
            border-radius: 5px;
            background: rgba(255,255,255,0.08);
            margin-left: 8px;
            color: var(--text-secondary);
            font-weight: normal;
        }
        .badge-effective-yes {
            background: rgba(46, 204, 113, 0.15);
            color: #2ecc71;
            border: 1px solid rgba(46, 204, 113, 0.3);
            padding: 3px 10px;
            border-radius: 6px;
            font-weight: 700;
            font-size: 0.8rem;
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }
        .badge-effective-no {
            background: rgba(231, 76, 60, 0.12);
            color: #e74c3c;
            border: 1px solid rgba(231, 76, 60, 0.25);
            padding: 3px 10px;
            border-radius: 6px;
            font-weight: 700;
            font-size: 0.8rem;
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }
        .role-legend-banner {
            background: rgba(52, 152, 219, 0.08);
            border: 1px solid rgba(52, 152, 219, 0.3);
            border-radius: 10px;
            padding: 12px 18px;
            margin-bottom: 20px;
            font-size: 0.88rem;
            color: var(--text-secondary);
            display: flex;
            align-items: center;
            gap: 12px;
        }
    </style>
</head>
<body>
<?php include __DIR__.'/partiels_entete.php'; ?>
<div class="wrap page-container wide-container">
    <div class="page-header-box">
        <div>
            <h2 class="mb-1">Gestion Avancée des Accès</h2>
            <p class="text-secondary" style="margin:0;">Configurez l'ensemble des autorisations applicatives par rôle Discord ou individuellement par utilisateur.</p>
        </div>
    </div>

    <?php if ($flash_success): ?>
        <div class="card mb-3" style="background: rgba(46, 204, 113, 0.15); border-color: rgba(46, 204, 113, 0.4); color: #2ecc71; font-weight: 600;">
            ✓ <?= htmlspecialchars($flash_success) ?>
        </div>
    <?php endif; ?>

    <div class="tabs">
        <a href="?tab=roles" class="tab-link <?= $active_tab === 'roles' ? 'active' : '' ?>">🏢 Permissions par Rôle Discord</a>
        <a href="?tab=users" class="tab-link <?= $active_tab === 'users' ? 'active' : '' ?>">👤 Permissions par Utilisateur</a>
    </div>

    <!-- ================= ONGLET 1 : PAR RÔLE ================= -->
    <div id="roles" class="tab-content <?= $active_tab === 'roles' ? 'active' : '' ?>">
        <div class="role-legend-banner">
            <span style="font-size: 1.4rem;">💡</span>
            <div>
                <strong>Attribution automatique par rôles Discord :</strong>
                Les membres héritent automatiquement des permissions cochées dès qu'ils possèdent le rôle Discord correspondant.
            </div>
        </div>

        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
            <input type="hidden" name="update_role_permissions" value="1">
            <input type="hidden" name="active_tab" value="roles">

            <?php foreach ($permission_categories as $category_name => $category_perms): ?>
                <div class="perm-card-table">
                    <div class="perm-category-header">
                        <?= htmlspecialchars($category_name) ?>
                    </div>
                    <div style="overflow-x: auto;">
                        <table class="permissions-table">
                            <thead>
                                <tr>
                                    <th style="min-width: 240px;">Permission & Description</th>
                                    <?php foreach ($managed_roles as $role_name => $role_id): ?>
                                        <th style="min-width: 105px; text-align: center;"><?= htmlspecialchars($role_name) ?></th>
                                    <?php endforeach; ?>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($category_perms as $perm_key => $perm_data): ?>
                                    <tr>
                                        <td>
                                            <div class="perm-title">
                                                <span><?= $perm_data['icon'] ?></span>
                                                <span><?= htmlspecialchars($perm_data['name']) ?></span>
                                            </div>
                                            <div class="perm-desc-text"><?= htmlspecialchars($perm_data['desc']) ?></div>
                                        </td>
                                        <?php foreach ($managed_roles as $role_name => $role_id): 
                                            $current_perms = json_decode($role_perms_from_db[$role_id] ?? '{}', true);
                                            $is_checked = !empty($current_perms[$perm_key]);
                                        ?>
                                            <td>
                                                <input type="checkbox" 
                                                       class="perm-checkbox" 
                                                       name="role_perms[<?= htmlspecialchars($role_id) ?>][<?= htmlspecialchars($perm_key) ?>]" 
                                                       value="1" 
                                                       <?= $is_checked ? 'checked' : '' ?>
                                                       title="<?= htmlspecialchars($role_name . ' -> ' . $perm_data['name']) ?>">
                                            </td>
                                        <?php endforeach; ?>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php endforeach; ?>

            <div style="position: sticky; bottom: 20px; z-index: 10; background: rgba(18, 18, 18, 0.95); backdrop-filter: blur(8px); padding: 15px; border-radius: 12px; border: 1px solid var(--border-color); display: flex; justify-content: flex-end;">
                <button type="submit" class="btn btn-primary" style="padding: 12px 30px; font-size: 1rem;">💾 Enregistrer les Permissions des Rôles</button>
            </div>
        </form>
    </div>

    <!-- ================= ONGLET 2 : PAR UTILISATEUR ================= -->
    <div id="users" class="tab-content user-accordion <?= $active_tab === 'users' ? 'active' : '' ?>">
        <div class="role-legend-banner">
            <span style="font-size: 1.4rem;">⚙️</span>
            <div>
                <strong>Affinage individuel :</strong> Par défaut, un utilisateur hérite des rôles Discord qu'il possède. Vous pouvez forcer individuellement une permission sur <strong>Autoriser</strong> ou <strong>Refuser</strong> pour passer outre ses rôles.
            </div>
        </div>

        <input type="text" id="search-user" placeholder="🔍 Rechercher un utilisateur par son pseudo ou nom d'affichage...">
        
        <div id="users-accordion-list">
            <?php foreach ($all_users_from_db as $user): 
                // Récupération des rôles Discord enregistrés localement ou synchronisés
                $user_roles_on_discord = [];
                if (!empty($user['last_synced_roles'])) {
                    $user_roles_on_discord = json_decode($user['last_synced_roles'], true) ?: [];
                }
                
                // Calcul des permissions héritées des rôles
                $inherited_perms = [];
                foreach ($user_roles_on_discord as $user_role_id) {
                    if (isset($role_perms_from_db[$user_role_id])) {
                        $perms_for_role = json_decode($role_perms_from_db[$user_role_id], true) ?: [];
                        foreach ($perms_for_role as $perm_key => $perm_value) {
                            if ($perm_value) $inherited_perms[$perm_key] = true;
                        }
                    }
                }
                
                $user_display = $user['display_name'] ?? $user['username'];
                $has_custom_overrides = false;
                foreach ($specific_permissions as $pkey => $pdata) {
                    if ($user[$pkey] !== null) {
                        $has_custom_overrides = true;
                        break;
                    }
                }
            ?>
                <details class="user-item" data-user-name="<?= strtolower(htmlspecialchars($user_display . ' ' . $user['username'])) ?>">
                    <summary>
                        <div>
                            <span class="user-name"><?= htmlspecialchars($user_display) ?></span>
                            <span class="text-secondary" style="font-size:0.85rem; font-weight:normal;">(@<?= htmlspecialchars($user['username']) ?>)</span>
                            <?php if (!empty($user['status'])): ?>
                                <span class="role-pill"><?= strtoupper($user['status']) ?></span>
                            <?php endif; ?>
                            <?php if ($has_custom_overrides): ?>
                                <span class="role-pill" style="background: rgba(243, 156, 18, 0.2); color: #f39c12; border: 1px solid rgba(243, 156, 18, 0.4);">⚙️ Personnalisé</span>
                            <?php endif; ?>
                        </div>
                    </summary>
                    <div class="permissions-content">
                        <form method="POST">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                            <input type="hidden" name="update_user_permissions" value="1">
                            <input type="hidden" name="active_tab" value="users">
                            <input type="hidden" name="user_id" value="<?= $user['id'] ?>">
                            
                            <?php foreach ($permission_categories as $category_name => $category_perms): ?>
                                <div class="perm-card-table" style="margin-bottom: 15px;">
                                    <div class="perm-category-header" style="font-size: 0.88rem; padding: 10px 14px;">
                                        <?= htmlspecialchars($category_name) ?>
                                    </div>
                                    <table class="permissions-table">
                                        <thead>
                                            <tr>
                                                <th style="width: 38%;">Permission</th>
                                                <th style="width: 42%;">Réglage Spécifique</th>
                                                <th style="width: 20%;">Accès Effectif</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($category_perms as $perm_key => $perm_data): 
                                                $user_override = $user[$perm_key] ?? null;
                                                $has_access = false;
                                                $reason = '';
                                                
                                                if ($user_override !== null) {
                                                    $has_access = (bool)$user_override;
                                                    $reason = $has_access ? "Forcé manuellement (Autorisé)" : "Forcé manuellement (Refusé)";
                                                } elseif (!empty($inherited_perms[$perm_key])) {
                                                    $has_access = true;
                                                    $reason = "Hérité d'un rôle Discord";
                                                } else {
                                                    $reason = "Par défaut (Non accordé)";
                                                }
                                            ?>
                                                <tr>
                                                    <td>
                                                        <div class="perm-title" style="font-size: 0.85rem;">
                                                            <span><?= $perm_data['icon'] ?></span>
                                                            <span><?= htmlspecialchars($perm_data['name']) ?></span>
                                                        </div>
                                                        <div class="perm-desc-text"><?= htmlspecialchars($perm_data['desc']) ?></div>
                                                    </td>
                                                    <td>
                                                        <div class="permission-radio-group">
                                                            <label title="Forcer l'autorisation pour cet utilisateur">
                                                                <input type="radio" name="user_perms[<?= $user['id'] ?>][<?= $perm_key ?>]" value="1" <?= $user_override === 1 ? 'checked' : '' ?>>
                                                                <span>✓ Autoriser</span>
                                                            </label>
                                                            <label title="Hériter des rôles Discord de l'utilisateur">
                                                                <input type="radio" name="user_perms[<?= $user['id'] ?>][<?= $perm_key ?>]" value="inherit" <?= $user_override === null ? 'checked' : '' ?>>
                                                                <span>🔗 Hériter</span>
                                                            </label>
                                                            <label title="Forcer le refus pour cet utilisateur">
                                                                <input type="radio" name="user_perms[<?= $user['id'] ?>][<?= $perm_key ?>]" value="0" <?= ($user_override === 0 && $user_override !== null) ? 'checked' : '' ?>>
                                                                <span>✗ Refuser</span>
                                                            </label>
                                                        </div>
                                                    </td>
                                                    <td>
                                                        <?php if ($has_access): ?>
                                                            <span class="badge-effective-yes" title="<?= htmlspecialchars($reason) ?>">
                                                                ✓ Accordé
                                                            </span>
                                                        <?php else: ?>
                                                            <span class="badge-effective-no" title="<?= htmlspecialchars($reason) ?>">
                                                                ✗ Refusé
                                                            </span>
                                                        <?php endif; ?>
                                                        <div style="font-size: 0.72rem; color: var(--text-muted); margin-top: 3px;">
                                                            <?= htmlspecialchars($reason) ?>
                                                        </div>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php endforeach; ?>

                            <button type="submit" class="btn btn-primary mt-2" style="width:100%; justify-content: center; padding: 12px;">
                                💾 Mettre à jour les accès de <?= htmlspecialchars($user_display) ?>
                            </button>
                        </form>
                    </div>
                </details>
            <?php endforeach; ?>
        </div>
        <div id="user-search-no-result" style="display: none; padding: 30px; text-align: center; color: var(--text-muted);">
            <p>🔍 Aucun utilisateur ne correspond à votre recherche.</p>
        </div>
    </div>
</div>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        const urlParams = new URLSearchParams(window.location.search);
        const tab = urlParams.get('tab') || 'roles';
        openTab(tab);

        const searchInput = document.getElementById('search-user');
        const noResult = document.getElementById('user-search-no-result');

        if (searchInput) {
            searchInput.addEventListener('input', function(e) {
                const query = (e.target.value || '').trim().toLowerCase();
                const items = document.querySelectorAll('#users-accordion-list .user-item');
                let countVisible = 0;

                items.forEach(item => {
                    const searchCorpus = item.getAttribute('data-user-name') || '';
                    if (!query || searchCorpus.includes(query)) {
                        item.style.display = 'block';
                        countVisible++;
                    } else {
                        item.style.display = 'none';
                    }
                });

                if (noResult) {
                    noResult.style.display = (items.length > 0 && countVisible === 0) ? 'block' : 'none';
                }
            });
        }
    });

    function openTab(tabName) {
        document.querySelectorAll('.tab-content').forEach(c => c.classList.remove('active'));
        document.querySelectorAll('.tab-link').forEach(l => l.classList.remove('active'));
        
        const tabElement = document.getElementById(tabName);
        if (tabElement) tabElement.classList.add('active');
        
        const tabLink = document.querySelector(`.tab-link[href*='tab=${tabName}']`);
        if (tabLink) tabLink.classList.add('active');
    }
</script>

</body>
</html>