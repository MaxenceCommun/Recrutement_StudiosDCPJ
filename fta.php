<?php
require_once __DIR__.'/config.php';
require_once __DIR__.'/bdd.php';
require_once __DIR__.'/api_discord.php';

$csrf_token = $_SESSION['csrf_token'] ?? bin2hex(random_bytes(32));
$_SESSION['csrf_token'] = $csrf_token;

// Sécurité : Vérifie si l'utilisateur a le droit de voir, d'ajouter ou de modifier le FTA
if (empty($_SESSION['user']) || (empty($_SESSION['user']['can_view_fta']) && empty($_SESSION['user']['can_add_fta']) && empty($_SESSION['user']['can_edit_fta']) && empty($_SESSION['user']['is_bureau']) && empty($_SESSION['user']['is_secretariat']))) {
    http_response_code(403);
    exit('Accès refusé');
}

$pdo = db();
$current_user_id = $_SESSION['user']['id'];
$is_admin_fta = !empty($_SESSION['user']['is_bureau']) || !empty($_SESSION['user']['is_secretariat']);
$can_edit = $is_admin_fta || !empty($_SESSION['user']['can_edit_fta']);
$can_add = $can_edit || !empty($_SESSION['user']['can_add_fta']);
$error_message = '';
$all_discord_users = [];

// Données pour les formulaires
$infraction_articles = $pdo->query("SELECT * FROM fta_infraction_articles WHERE is_active = 1 ORDER BY article_code ASC")->fetchAll(PDO::FETCH_ASSOC);
$sanction_types = ["Avertissement administratif", "Exclusion temporaire", "Exclusion définitive (Radiation)", "Exclusion avec impossibilité de repostuler (Bannissement)"];

// NOUVEAU : Définition plus claire des étiquettes système
$system_tags_available = [
    "Interdiction de postuler" => [
        "desc" => "Empêche l'utilisateur de soumettre une nouvelle candidature pendant la durée spécifiée. Si aucune durée n'est fournie, l'interdiction est permanente.",
        "has_duration" => true
    ],
    "Retrait automatique des rôles Discord" => [
        "desc" => "Le système tentera de retirer les rôles de jeu principaux (ex: 'Joueur') sur Discord.",
        "has_duration" => false
    ],
    "Surveillance renforcée" => [
        "desc" => "Marqueur informatif pour le staff, n'a pas d'effet système automatique.",
        "has_duration" => false
    ]
];

// Traitement du formulaire
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        http_response_code(403);
        exit('Erreur de validation (CSRF).');
    }
    if (isset($_POST['add_sanction'])) {
        if (!$can_add) {
            http_response_code(403);
            exit('Permission refusée : vous ne pouvez pas ajouter de sanction.');
        }
        $discord_user_id = $_POST['discord_user_id'];
        $discord_user_info = json_decode($_POST['discord_user_info'], true);
        $st = $pdo->prepare("SELECT id FROM users WHERE discord_id = ?");
        $st->execute([$discord_user_id]);
        $user = $st->fetch();
        $local_user_id = $user ? $user['id'] : null;
        if (!$user) {
            $pdo->prepare("INSERT INTO users (discord_id, username, display_name) VALUES (?, ?, ?)")->execute([$discord_user_id, $discord_user_info['username'], $discord_user_info['display_name']]);
            $local_user_id = $pdo->lastInsertId();
        }

        $system_tags_array = [];
        $is_banned = false;
        $ban_end_date = null;

        if (!empty($_POST['system_tags']) && is_array($_POST['system_tags'])) {
            foreach ($_POST['system_tags'] as $tag) {
                $tag_data = ["tag" => $tag];
                $duration_value = $_POST['tag_duration_value'][$tag] ?? null;
                $duration_unit = $_POST['tag_duration_unit'][$tag] ?? null;

                if (!empty($duration_value) && !empty($duration_unit) && in_array($duration_unit, ['days', 'weeks', 'months', 'years'])) {
                    $start_date_str = !empty($_POST['start_date']) ? $_POST['start_date'] : date('Y-m-d');
                    $end_date_time = strtotime("+$duration_value $duration_unit", strtotime($start_date_str));
                    $tag_data['end_date'] = date('Y-m-d H:i:s', $end_date_time);
                } else {
                    $tag_data['end_date'] = null; // Permanent
                }
                $system_tags_array[] = $tag_data;

                // Logique pour le bannissement / radiation
                if ($tag === 'Interdiction de postuler') {
                    $is_banned = true;
                    $ban_end_date = $tag_data['end_date'];
                }
            }
        }
        
        $system_tags_json = !empty($system_tags_array) ? json_encode($system_tags_array) : null;
        $start_date = !empty($_POST['start_date']) ? $_POST['start_date'] : date('Y-m-d');
        $end_date = !empty($_POST['end_date']) ? $_POST['end_date'] : null;

        $stmt = $pdo->prepare("INSERT INTO fta_sanctions (user_id, author_id, sanction_type, infraction_article, reason, infraction_details, sanction_notes, system_tags_json, start_date, end_date) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([
            $local_user_id, $current_user_id, $_POST['sanction_type'], $_POST['infraction_article'],
            $_POST['reason'], $_POST['infraction_details'], $_POST['sanction_notes'],
            $system_tags_json, $start_date, $end_date
        ]);

        // AUTOMATISATION SANCTION -> SITE
        if ($is_banned) {
            $effective_ban_date = $ban_end_date !== null ? $ban_end_date : date('Y-m-d H:i:s', strtotime("+99 years"));
            $pdo->prepare("UPDATE users SET status = 'ancien_joueur', can_reapply_after = ? WHERE id = ?")
                ->execute([$effective_ban_date, $local_user_id]);

            // Log de l'action
            log_user_action($local_user_id, 'sanction', "Radiation Automatique via FTA : " . $_POST['sanction_type'], $current_user_id);
        }
    }
    elseif (isset($_POST['delete_sanction'])) {
        if (!$can_edit) {
            http_response_code(403);
            exit('Permission refusée : vous ne pouvez pas supprimer de sanction.');
        }
        $pdo->prepare("DELETE FROM fta_sanctions WHERE id = ?")->execute([$_POST['sanction_id']]);
    }
    header("Location: /fta");
    exit;
}

// Récupération des données pour l'affichage
try {
    $all_discord_users = discord_get_all_guild_members();
} catch (Exception $e) {
    $error_message = "Erreur critique : Impossible de récupérer la liste des membres du serveur Discord. <br>";
    $error_message .= "Cause probable : L'autorisation 'SERVER MEMBERS INTENT' n'est pas activée pour votre bot sur le portail développeur de Discord. Veuillez l'activer.";
    error_log("Erreur FTA - get_all_guild_members: " . $e->getMessage());
}

$sanctions = $pdo->query("SELECT s.*, u.display_name as user_name, u.username as user_username, a.display_name as author_name, a.username as author_username FROM fta_sanctions s JOIN users u ON s.user_id = u.id JOIN users a ON s.author_id = a.id ORDER BY s.start_date DESC")->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Fichier des Sanctions (FTA) — Studios DCPJ</title>
    <link rel="icon" type="image/png" href="assets/logo.png"><link rel="stylesheet" href="assets/styles.css">
    <style>
        .fta-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(min(100%, 340px), 1fr)); gap: 20px; }
        .sanction-card { background: var(--bg-secondary); border: 1px solid var(--border-color); border-radius: 12px; padding: 20px; display: flex; flex-direction: column; }
        .sanction-header { display: flex; justify-content: space-between; align-items: flex-start; border-bottom: 1px solid var(--border-color); padding-bottom: 15px; margin-bottom: 15px; }
        .sanction-header h4 { margin: 0; font-size: 1.2rem; }
        .sanction-badge { padding: 5px 10px; border-radius: 6px; font-size: 0.8rem; font-weight: bold; white-space: nowrap; text-align: center; }
        .sanction-badge.ban { background-color: rgba(231, 76, 60, 0.2); color: #e74c3c; }
        .sanction-badge.temp { background-color: rgba(243, 156, 18, 0.2); color: #f39c12; }
        .sanction-badge.warn { background-color: rgba(52, 152, 219, 0.2); color: #3498db; }
        .sanction-body p { margin: 0 0 12px 0; color: var(--text-secondary); line-height: 1.6; }
        .sanction-body strong { color: var(--text-primary); font-weight: 600; }
        .sanction-details { background: var(--bg-primary); padding: 12px; border-radius: 6px; margin-top: 5px; font-style: italic; font-size: 0.9em; }
        .sanction-footer { display: flex; justify-content: space-between; align-items: center; border-top: 1px solid var(--border-color); padding-top: 15px; margin-top: auto; font-size: 0.8rem; color: var(--text-muted); }
        #search-fta { width: 100%; }
        .user-search-container { position: relative; }
        #user-search-input { width: 100%; }
        #user-search-results { display: none; position: absolute; width: 100%; background: var(--bg-tertiary); border: 1px solid var(--border-color); border-radius: 8px; max-height: 200px; overflow-y: auto; z-index: 10; }
        .search-result-item { padding: 10px 15px; cursor: pointer; }
        .search-result-item:hover { background: var(--accent-secondary); }
        .system-tag { display: inline-block; background-color: var(--accent-secondary); color: white; padding: 4px 10px; border-radius: 12px; font-size: 0.8em; font-weight: bold; margin-right: 5px; margin-bottom: 5px; }
        .tag-duration-container { display: none; margin-left: 25px; margin-top: 10px; }
        .duration-group { display: flex; gap: 10px; align-items: center; }
        @media (max-width: 600px) {
            .fta-grid { grid-template-columns: 1fr; }
            .sanction-card { padding: 15px; }
            .sanction-header { flex-direction: column; gap: 8px; }
        }
    </style>
</head>
<body>
<?php include __DIR__.'/partiels_entete.php'; ?>
<div class="wrap page-container">
    <div style="display:flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 15px;" class="mb-3">
        <h2>Fichier des Sanctions (FTA)</h2>
        <?php if ($can_add): ?>
        <a href="#add-form" class="btn btn-primary" onclick="document.getElementById('add-form').open = true;">Ajouter une sanction</a>
        <?php endif; ?>
    </div>

    <?php if (!empty($error_message)): ?>
        <div class="alert alert-error mb-3"><?= $error_message ?></div>
    <?php endif; ?>

    <?php if ($can_add): ?>
    <details id="add-form" class="card mb-3">
        <summary style="font-weight: bold; cursor: pointer;">Formulaire d'ajout de sanction</summary>
        <form method="POST" class="mt-2">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
            <input type="hidden" name="add_sanction" value="1">
            <input type="hidden" name="discord_user_id" id="selected-user-id" required>
            <input type="hidden" name="discord_user_info" id="selected-user-info">
            
            <div class="form-group"><label for="user-search-input">Utilisateur concerné</label><div class="search-user-container"><input type="text" id="user-search-input" class="card" placeholder="Commencez à taper un nom..." autocomplete="off" required <?= empty($all_discord_users) ? 'disabled' : '' ?>><div id="user-search-results"></div></div></div>
            <div class="form-group"><label for="infraction_article">Infraction constatée</label><select id="infraction_article" name="infraction_article" class="card" required><option value="" disabled selected>-- Choisir un article --</option><?php foreach ($infraction_articles as $article): ?><option value="<?= htmlspecialchars($article['article_code'] . ' - ' . $article['description']) ?>"><?= htmlspecialchars($article['article_code'] . ' - ' . $article['description']) ?></option><?php endforeach; ?><option value="Autre">Autre (préciser dans le motif)</option></select></div>
            <div class="form-group"><label for="reason">Motif (détails)</label><textarea id="reason" name="reason" class="card" required placeholder="Décrivez précisément les faits..."></textarea></div>
            <div class="form-group"><label for="infraction_details">Précisions sur l'infraction</label><input type="text" id="infraction_details" name="infraction_details" class="card" placeholder="Ex: date des faits, personnes présentes..."></div>
            <div class="form-group"><label for="sanction_type">Type de sanction principal</label><select id="sanction_type" name="sanction_type" class="card" required><?php foreach ($sanction_types as $type): ?><option value="<?= htmlspecialchars($type) ?>"><?= htmlspecialchars($type) ?></option><?php endforeach; ?></select></div>
            
            <fieldset class="card" style="border-width: 1px; padding: 20px; border-color: var(--border-color);">
                <legend style="padding: 0 10px; font-weight: bold; color: var(--text-secondary);">Sanctions Complémentaires & Restrictions</legend>

                <div class="form-group">
                    <label for="sanction_notes">Description des sanctions complémentaires (texte libre)</label>
                    <textarea id="sanction_notes" name="sanction_notes" class="card" placeholder="Décrivez ici toute sanction ou note qui n'est pas une restriction système (ex: TIG, excuses publiques, avertissement oral...)" style="background-color: var(--bg-primary);"></textarea>
                </div>

                <div class="form-group">
                    <label>Restrictions Système Automatisées</label>
                    <div class="checkbox-group">
                        <?php foreach ($system_tags_available as $tag => $details): ?>
                        <div id="tag-group-<?= str_replace(' ', '-', $tag) ?>" style="margin-bottom: 10px;">
                            <label style="flex-direction: row; align-items: center; font-weight: normal;" title="<?= htmlspecialchars($details['desc']) ?>">
                                <input type="checkbox" name="system_tags[]" value="<?= htmlspecialchars($tag) ?>" onchange="toggleDuration(this, <?= $details['has_duration'] ? 'true' : 'false' ?>)">
                                <?= htmlspecialchars($tag) ?>
                            </label>
                            <?php if ($details['has_duration']): ?>
                            <div class="tag-duration-container">
                                <div class="duration-group">
                                    <input type="number" name="tag_duration_value[<?= htmlspecialchars($tag) ?>]" class="card" placeholder="Durée" min="1" style="background-color: var(--bg-primary);">
                                    <select name="tag_duration_unit[<?= htmlspecialchars($tag) ?>]" class="card" style="background-color: var(--bg-primary);">
                                        <option value="days">jours</option>
                                        <option value="weeks">semaines</option>
                                        <option value="months">mois</option>
                                        <option value="years">années</option>
                                    </select>
                                    <small style="color: var(--text-muted);">(Laisser vide pour une durée permanente)</small>
                                </div>
                            </div>
                            <?php endif; ?>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </fieldset>
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
                <div class="form-group"><label for="start_date">Date de début</label><input type="date" id="start_date" name="start_date" class="card" required></div>
                <div class="form-group"><label for="end_date">Date de fin</label><input type="date" id="end_date" name="end_date" class="card"></div>
            </div>

            <div class="form-group checkbox-group" style="margin-top: 10px;"><label style="flex-direction: row; align-items: center; font-weight: normal;"><input type="checkbox" name="certify" required>Je certifie que cette inscription fait suite à une décision par l'autorité décisionnelle en la matière conformément à l'art. 123 du CJ-SDCPJ</label></div>
            <button type="submit" class="btn btn-primary" <?= empty($all_discord_users) ? 'disabled' : '' ?>>Enregistrer la sanction</button>
        </form>
    </details>
    <?php endif; ?>

    <input type="text" id="search-fta" placeholder="Rechercher par nom, motif, auteur, article..." class="card">

    <div class="fta-grid mt-3" id="fta-grid-container">
        <?php if(empty($sanctions)): ?>
            <div class="card"><p class="text-muted">Aucune sanction n'a été enregistrée pour le moment.</p></div>
        <?php endif; ?>
        <?php foreach ($sanctions as $sanction): 
            $badge_class = 'warn';
            if (str_contains(strtolower($sanction['sanction_type']), 'temporaire')) $badge_class = 'temp';
            if (str_contains(strtolower($sanction['sanction_type']), 'bannissement') || str_contains(strtolower($sanction['sanction_type']), 'radiation') || str_contains(strtolower($sanction['sanction_type']), 'définitive')) $badge_class = 'ban';
            
            $search_text = strtolower(htmlspecialchars(implode(' ', [
                $sanction['user_name'], $sanction['reason'], $sanction['author_name'], 
                $sanction['infraction_article'], $sanction['infraction_details'], $sanction['sanction_notes']
            ])));
            $system_tags_decoded = json_decode($sanction['system_tags_json'] ?? '[]', true);
        ?>
            <div class="sanction-card" data-search-text="<?= $search_text ?>">
                <div class="sanction-header">
                    <h4><?= htmlspecialchars($sanction['user_name'] ?? $sanction['user_username']) ?></h4>
                    <span class="sanction-badge <?= $badge_class ?>"><?= htmlspecialchars($sanction['sanction_type']) ?></span>
                </div>
                <div class="sanction-body">
                    <p><strong>Infraction :</strong> <?= htmlspecialchars($sanction['infraction_article']) ?></p>
                    <p><strong>Motif :</strong></p><div class="sanction-details"><?= nl2br(htmlspecialchars($sanction['reason'])) ?></div>
                    <?php if(!empty($sanction['infraction_details'])): ?><p class="mt-2"><strong>Précisions :</strong></p><div class="sanction-details"><?= nl2br(htmlspecialchars($sanction['infraction_details'])) ?></div><?php endif; ?>
                    <?php if(!empty($sanction['sanction_notes'])): ?><p class="mt-2"><strong>Notes complémentaires :</strong></p><div class="sanction-details"><?= nl2br(htmlspecialchars($sanction['sanction_notes'])) ?></div><?php endif; ?>
                    <?php if(!empty($system_tags_decoded)): ?>
                        <p class="mt-2"><strong>Restrictions Système :</strong></p>
                        <div>
                        <?php foreach($system_tags_decoded as $tag_data): ?>
                            <span class="system-tag">
                                <?= htmlspecialchars($tag_data['tag']) ?>
                                <?php if(!empty($tag_data['end_date'])): ?>
                                    (jusqu'au <?= date('d/m/Y', strtotime($tag_data['end_date'])) ?>)
                                <?php else: ?>
                                    (Permanent)
                                <?php endif; ?>
                            </span>
                        <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
                <div class="sanction-footer">
                    <span>Par: <strong><?= htmlspecialchars($sanction['author_name'] ?? $sanction['author_username']) ?></strong></span>
                    <span>
                        <?php if ($sanction['end_date']): ?>
                            Du <?= date('d/m/y', strtotime($sanction['start_date'])) ?> au <?= date('d/m/y', strtotime($sanction['end_date'])) ?>
                        <?php else: ?>
                            Le <?= date('d/m/y', strtotime($sanction['start_date'])) ?>
                        <?php endif; ?>
                    </span>
                    <?php if ($can_edit): ?>
                    <form method="POST" onsubmit="return confirm('Êtes-vous sûr de vouloir supprimer cette sanction ?');" style="margin:0;">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                        <input type="hidden" name="delete_sanction" value="1">
                        <input type="hidden" name="sanction_id" value="<?= $sanction['id'] ?>">
                        <button type="submit" class="btn" style="padding: 5px 10px; font-size: 0.8rem; background-color: var(--accent-red);">Suppr.</button>
                    </form>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</div>

<script>
    const discordUsers = <?= json_encode(array_values($all_discord_users)) ?>;

    // SCRIPT MODIFIÉ
    function toggleDuration(checkbox, hasDuration) {
        if (!hasDuration) return; // Si l'étiquette n'est pas censée avoir de durée, on ne fait rien.

        const containerId = `tag-group-${checkbox.value.replace(/ /g, '-')}`;
        const durationContainer = document.getElementById(containerId).querySelector('.tag-duration-container');
        
        if (durationContainer) {
            durationContainer.style.display = checkbox.checked ? 'block' : 'none';
        }
    }

    document.addEventListener('DOMContentLoaded', function() {
        const searchInput = document.getElementById('search-fta');
        if (searchInput) {
            searchInput.addEventListener('input', function(e) {
                const searchTerm = e.target.value.toLowerCase();
                document.querySelectorAll('.sanction-card').forEach(card => {
                    const cardText = card.getAttribute('data-search-text');
                    if (cardText.includes(searchTerm)) {
                        card.style.display = 'flex';
                    } else {
                        card.style.display = 'none';
                    }
                });
            });
        }

        const userSearchInput = document.getElementById('user-search-input');
        const userSearchResults = document.getElementById('user-search-results');
        const selectedUserId = document.getElementById('selected-user-id');
        const selectedUserInfo = document.getElementById('selected-user-info');

        if (userSearchInput) {
            userSearchInput.addEventListener('input', function() {
                const query = userSearchInput.value.toLowerCase();
                userSearchResults.innerHTML = '';
                selectedUserId.value = '';
                
                if (query.length < 2) {
                    userSearchResults.style.display = 'none';
                    return;
                }

                const filteredUsers = discordUsers.filter(member => {
                    if(!member.user || member.user.bot) return false;
                    const displayName = (member.user.global_name || member.user.username).toLowerCase();
                    const username = member.user.username.toLowerCase();
                    return displayName.includes(query) || username.includes(query);
                });

                if (filteredUsers.length > 0) {
                    filteredUsers.slice(0, 10).forEach(member => {
                        const displayName = member.user.global_name || member.user.username;
                        const item = document.createElement('div');
                        item.className = 'search-result-item';
                        item.textContent = `${displayName} (${member.user.username})`;
                        item.addEventListener('click', function() {
                            userSearchInput.value = `${displayName} (${member.user.username})`;
                            selectedUserId.value = member.user.id;
                            selectedUserInfo.value = JSON.stringify({
                                display_name: displayName,
                                username: member.user.username
                            });
                            userSearchResults.style.display = 'none';
                        });
                        userSearchResults.appendChild(item);
                    });
                    userSearchResults.style.display = 'block';
                } else {
                    userSearchResults.style.display = 'none';
                }
            });
        }

        document.addEventListener('click', function(e) {
            if (userSearchResults && e.target !== userSearchInput) {
                userSearchResults.style.display = 'none';
            }
        });
    });
</script>

</body>
</html>