<?php
require_once __DIR__.'/config.php';
require_once __DIR__.'/bdd.php';

if (empty($_SESSION['user'])) {
    header('Location: /connexion_discord');
    exit;
}

$pdo = db();
$user_id = (int)$_SESSION['user']['id'];

// Récupération des informations fraîches de l'utilisateur
$stmt = $pdo->prepare("SELECT id, username, display_name, avatar, discord_id, status, trial_start_date, total_playtime, session_presence_count, membership_expires_at, created_at FROM users WHERE id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    header('Location: /connexion_discord');
    exit;
}

// Redirection si l'utilisateur est toujours en période d'essai
$trial_statuses = ['essai', 'essai_adhesion', 'essai_termine'];
if (in_array($user['status'], $trial_statuses)) {
    header('Location: /mon_suivi');
    exit;
}

// Temps de jeu initial
$playtime_seconds = (int)($user['total_playtime'] ?? 0);
$hours = floor($playtime_seconds / 3600);
$minutes = floor(($playtime_seconds % 3600) / 60);
$formatted_playtime = sprintf('%dh %02dm', $hours, $minutes);

// Nombre de sessions de jeu
$stmt_sessions_count = $pdo->prepare("SELECT COUNT(*) FROM playtime_logs WHERE user_id = ?");
$stmt_sessions_count->execute([$user_id]);
$sessions_recorded = (int)$stmt_sessions_count->fetchColumn();
$total_sessions = max($sessions_recorded, (int)($user['session_presence_count'] ?? 0));

// Dernières sessions de jeu (5 dernières)
$stmt_recent_sessions = $pdo->prepare("SELECT session_start, session_end, duration FROM playtime_logs WHERE user_id = ? ORDER BY session_start DESC LIMIT 5");
$stmt_recent_sessions->execute([$user_id]);
$recent_sessions = $stmt_recent_sessions->fetchAll(PDO::FETCH_ASSOC);

// Calcul ancienneté
$member_since_date = !empty($user['trial_start_date']) ? $user['trial_start_date'] : ($user['created_at'] ?? 'now');
$dt_start = new DateTime($member_since_date);
$dt_now = new DateTime();
$days_member = $dt_start->diff($dt_now)->days;

// Expiration adhésion
$membership_exp = null;
if (!empty($user['membership_expires_at'])) {
    $membership_exp = date('d/m/Y', strtotime($user['membership_expires_at']));
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Mon Espace Joueur — Studios DCPJ</title>
    <link rel="icon" type="image/png" href="assets/logo.png">
    <link rel="stylesheet" href="assets/styles.css">
    <style>
        .player-hero-card {
            background: linear-gradient(135deg, rgba(20, 25, 40, 0.95) 0%, rgba(30, 35, 55, 0.9) 100%);
            border: 1px solid var(--border-color);
            border-radius: 20px;
            padding: 30px;
            margin-bottom: 30px;
            position: relative;
            overflow: hidden;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.3);
        }
        .player-hero-card::after {
            content: '';
            position: absolute;
            top: -50px;
            right: -50px;
            width: 200px;
            height: 200px;
            background: var(--gradient-primary);
            filter: blur(80px);
            opacity: 0.15;
            pointer-events: none;
        }
        .player-profile-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 20px;
        }
        .player-avatar-meta {
            display: flex;
            align-items: center;
            gap: 20px;
        }
        .player-avatar {
            width: 70px;
            height: 70px;
            border-radius: 18px;
            background: var(--gradient-primary);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 2rem;
            font-weight: 800;
            color: white;
            box-shadow: 0 8px 20px rgba(0,0,0,0.3);
            border: 2px solid rgba(255,255,255,0.1);
        }
        .player-status-badge {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 6px 14px;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 700;
            background: rgba(39, 174, 96, 0.15);
            color: #2ecc71;
            border: 1px solid rgba(39, 174, 96, 0.4);
        }
        .stats-kpi-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        .kpi-card {
            background: var(--bg-card);
            border-radius: 16px;
            padding: 24px;
            border: 1px solid var(--border-color);
            position: relative;
            overflow: hidden;
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }
        .kpi-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.2);
        }
        .kpi-card .val {
            font-size: 2.2rem;
            font-weight: 800;
            color: var(--text-primary);
            margin-bottom: 4px;
        }
        .kpi-card .lbl {
            font-size: 0.85rem;
            color: var(--text-secondary);
            text-transform: uppercase;
            letter-spacing: 1px;
            font-weight: 600;
        }
        
        .status-indicators {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        .status-box {
            display: flex;
            align-items: center;
            gap: 15px;
            background: var(--bg-secondary);
            padding: 18px 20px;
            border-radius: 14px;
            border: 1px solid var(--border-color);
        }
        .status-dot {
            width: 14px;
            height: 14px;
            border-radius: 50%;
            transition: background-color 0.5s ease;
        }
        .status-dot.online { background-color: var(--accent-success); box-shadow: 0 0 10px var(--accent-success); animation: pulse 2s infinite; }
        .status-dot.offline { background-color: var(--accent-red); }
        .status-dot.active-time { background-color: #3498db; box-shadow: 0 0 10px #3498db; animation: pulse 2s infinite; }
        .status-dot.inactive-time { background-color: #7f8c8d; }
        @keyframes pulse { 0% { opacity: 1; } 50% { opacity: 0.4; } 100% { opacity: 1; } }

        /* Accordéon Informations Pratiques */
        .info-accordion details {
            background: var(--bg-secondary);
            border: 1px solid var(--border-color);
            border-radius: 12px;
            margin-bottom: 15px;
            overflow: hidden;
            transition: border-color 0.2s;
        }
        .info-accordion details[open] {
            border-color: rgba(255, 255, 255, 0.15);
        }
        .info-accordion summary {
            padding: 18px 22px;
            font-weight: 700;
            font-size: 1.15rem;
            cursor: pointer;
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: transparent;
            user-select: none;
        }
        .info-accordion summary:hover {
            background: var(--bg-tertiary);
        }
        .info-accordion summary::after {
            content: '▼';
            font-size: 0.8em;
            color: var(--text-muted);
            transition: transform 0.2s;
        }
        .info-accordion details[open] summary::after {
            transform: rotate(180deg);
        }
        .info-accordion .info-content {
            padding: 0 22px 22px 22px;
            line-height: 1.8;
            color: var(--text-secondary);
        }
        .info-content h4 {
            color: var(--accent-primary);
            margin-top: 18px;
            margin-bottom: 8px;
            font-size: 1.05rem;
        }
        .info-content ul {
            list-style: none;
            padding: 0;
            margin-top: 10px;
        }
        .info-content ul li {
            background: rgba(255, 255, 255, 0.02);
            border: 1px solid var(--border-color);
            padding: 10px 16px;
            border-radius: 10px;
            margin-bottom: 8px;
            color: var(--text-secondary);
            border-left: 3px solid var(--accent-secondary);
        }
        .info-content code {
            background: var(--bg-primary);
            padding: 3px 8px;
            border-radius: 6px;
            font-family: monospace;
            color: #3498db;
            font-size: 0.95rem;
        }
        .info-content kbd {
            background: var(--bg-tertiary);
            border: 1px solid var(--border-color);
            padding: 3px 8px;
            border-radius: 6px;
            font-family: monospace;
            font-weight: bold;
            color: var(--text-primary);
            box-shadow: 0 2px 0 rgba(0,0,0,0.4);
        }

        .discord-channel-link {
            display: flex;
            align-items: center;
            padding: 12px 16px;
            background: rgba(255, 255, 255, 0.03);
            border: 1px solid var(--border-color);
            border-radius: 10px;
            color: #e2e8f0;
            text-decoration: none !important;
            transition: all 0.2s ease;
            font-weight: 500;
            margin-bottom: 10px;
        }
        .discord-channel-link:hover {
            background: rgba(52, 152, 219, 0.12);
            border-color: var(--accent-secondary);
            color: white;
            transform: translateX(4px);
        }
        .discord-channel-link .icon {
            color: var(--accent-secondary);
            font-weight: bold;
            margin-right: 10px;
            font-size: 1.1rem;
        }

        .recent-sessions-table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0 8px;
        }
        .recent-sessions-table th {
            padding: 12px 16px;
            color: var(--text-muted);
            font-size: 0.8rem;
            text-transform: uppercase;
            letter-spacing: 1px;
            text-align: left;
        }
        .recent-sessions-table td {
            padding: 14px 16px;
            background: var(--bg-secondary);
        }
        .recent-sessions-table tr td:first-child { border-radius: 10px 0 0 10px; }
        .recent-sessions-table tr td:last-child { border-radius: 0 10px 10px 0; }

        .page-container {
            padding-bottom: 80px;
        }

        .player-footer {
            margin-top: 70px;
            padding: 35px 0 45px;
            background: rgba(10, 14, 23, 0.75);
            border-top: 1px solid var(--border-color);
            width: 100%;
        }
    </style>
</head>
<body>
<?php include __DIR__.'/partiels_entete.php'; ?>

<div class="wrap page-container">
    
    <!-- Hero / Carte Profil Joueur -->
    <div class="player-hero-card">
        <div class="player-profile-header">
            <div class="player-avatar-meta">
                <div class="player-avatar">
                    <?= strtoupper(substr($user['display_name'] ?? $user['username'], 0, 1)) ?>
                </div>
                <div>
                    <h1 style="margin: 0 0 6px 0; font-size: 1.8rem;"><?= htmlspecialchars($user['display_name'] ?? $user['username']) ?></h1>
                    <div style="color: var(--text-muted); font-size: 0.9rem;">
                        @<?= htmlspecialchars($user['username']) ?> 
                        <?php if ($membership_exp): ?>
                            • <span style="color: var(--text-secondary);">Adhésion valide jusqu'au <?= $membership_exp ?></span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <div>
                <span class="player-status-badge">
                    <span>⭐</span> Membre Joueur Officiel
                </span>
            </div>
        </div>
    </div>

    <!-- Statut de connexion en direct -->
    <div class="status-indicators">
        <div class="status-box">
            <div id="player-status-dot" class="status-dot offline"></div>
            <div>
                <h4 style="margin: 0; font-size: 1rem;">Statut en jeu</h4>
                <span id="player-status-text" style="color: var(--text-muted); font-size: 0.85rem;">Vérification...</span>
            </div>
        </div>
        <div class="status-box">
            <div id="session-status-dot" class="status-dot inactive-time"></div>
            <div>
                <h4 style="margin: 0; font-size: 1rem;">Comptabilisation</h4>
                <span id="session-status-text" style="color: var(--text-muted); font-size: 0.85rem;">Vérification...</span>
            </div>
        </div>
    </div>

    <!-- KPI Statistiques Joueur -->
    <div class="stats-kpi-grid">
        <div class="kpi-card" style="border-left: 4px solid var(--accent-primary);">
            <div class="val" id="playtime-val"><?= $formatted_playtime ?></div>
            <div class="lbl">Temps de jeu total</div>
        </div>
        <div class="kpi-card" style="border-left: 4px solid #3498db;">
            <div class="val"><?= $total_sessions ?></div>
            <div class="lbl">Sessions enregistrées</div>
        </div>
        <div class="kpi-card" style="border-left: 4px solid var(--accent-success);">
            <div class="val"><?= $days_member ?> j</div>
            <div class="lbl">Ancienneté membre</div>
        </div>
    </div>

    <!-- Historique des dernières sessions -->
    <?php if (!empty($recent_sessions)): ?>
    <div class="card mb-4" style="padding: 24px;">
        <h3 style="margin-top: 0; margin-bottom: 16px; font-size: 1.2rem; display: flex; align-items: center; gap: 10px;">
            <span>⏱️</span> Dernières sessions de jeu
        </h3>
        <div style="overflow-x: auto;">
            <table class="recent-sessions-table">
                <thead>
                    <tr>
                        <th>Date & Heure début</th>
                        <th>Fin de session</th>
                        <th>Durée comptabilisée</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($recent_sessions as $s): 
                        $s_dur = (int)($s['duration'] ?? 0);
                        $s_h = floor($s_dur / 3600);
                        $s_m = floor(($s_dur % 3600) / 60);
                        $s_duration_str = ($s_h > 0 ? "{$s_h}h " : "") . sprintf('%02dm', $s_m);
                    ?>
                        <tr>
                            <td style="color: var(--text-primary); font-weight: 500;">
                                <?= date('d/m/Y à H:i', strtotime($s['session_start'])) ?>
                            </td>
                            <td style="color: var(--text-secondary);">
                                <?= !empty($s['session_end']) ? date('d/m/Y à H:i', strtotime($s['session_end'])) : '<span style="color: var(--accent-success); font-weight: bold;">En cours...</span>' ?>
                            </td>
                            <td style="font-weight: 600; color: #3498db;">
                                <?= !empty($s['session_end']) ? $s_duration_str : 'Calcul en direct' ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>

    <!-- Guide & Informations Utiles -->
    <h2 class="mt-4 mb-3" style="font-size: 1.4rem; display: flex; align-items: center; gap: 10px;">
        <span>📖</span> Informations utiles & Guides
    </h2>

    <div class="info-accordion">
        <details open>
            <summary>Rappels sur le fonctionnement & Rôle Civil</summary>
            <div class="info-content">
                <p>En tant que civil, votre rôle est primordial pour créer des interventions immersives et réalistes pour les services de secours et de sécurité.</p>
                <ul>
                    <li><b>Analysez les effectifs :</b> Avant 21h, consultez les plannings et présences sur Discord pour connaître les services disponibles.</li>
                    <li><b>Dimensionnez vos scènes :</b> Adaptez la gravité de vos appels au nombre d'effectifs connectés pour éviter de surcharger les équipes.</li>
                    <li><b>Diversifiez :</b> Variez les scénarios (accidents, malaises, différends, situations inédites) pour enrichir l'expérience collective.</li>
                </ul>
            </div>
        </details>
        
        <details>
            <summary>Touches & Raccourcis en jeu (FiveM)</summary>
            <div class="info-content">
                <h4>⚙️ Menus Principaux</h4>
                <ul>
                    <li><kbd>F1</kbd> : vMenu (Gestion globale et options du joueur)</li>
                    <li><kbd>F2</kbd> : Noclip (Réservé aux secours / staffs habilités)</li>
                </ul>
                <h4>🕹️ Menus interactifs</h4>
                <p>Touches de raccourcis <kbd>1</kbd> à <kbd>8</kbd> :</p>
                <ul>
                    <li><kbd>1</kbd> : Menu Interactif (Gestion des Props et objets)</li>
                    <li><kbd>2</kbd> : Menu Personnel (Apparence et tenues)</li>
                    <li><kbd>3</kbd> : Téléphone</li>
                    <li><kbd>4</kbd> : Animations & attitudes</li>
                    <li><kbd>8</kbd> : Menu Plastron (Gestion des blessures et états)</li>
                </ul>
                <h4>🚶 Postures & Commandes rapides</h4>
                <ul>
                    <li><kbd>VERR. MAJ</kbd> : Se coucher sur le ventre</li>
                    <li><kbd>CTRL</kbd> / <kbd>ALT</kbd> : Se baisser / S'accroupir</li>
                    <li><kbd>B</kbd> : Feux / Phares de jour</li>
                </ul>
            </div>
        </details>

        <details>
            <summary>Commandes utiles en jeu (/)</summary>
            <div class="info-content">
                <ul>
                    <li><code>/victime</code> : Pour être facilement repéré par les secours au sol.</li>
                    <li><code>/constantes</code> : Affiche vos constantes vitales dans le chat pour les secours.</li>
                    <li><code>/startfire</code> : Déclenche un début d'incendie paramétrable.</li>
                    <li><code>/info fb [TEXTE]</code> : Publier un post informatif sur le réseau RP.</li>
                    <li><code>/appel [SERVICE] [TEXTE]</code> : Envoyer un appel d'urgence automatique (ex: samu, fdo, sp).</li>
                    <li><code>/dv</code> : Supprime le véhicule dans lequel vous vous trouvez (nettoyage après scène).</li>
                </ul>
            </div>
        </details>

        <details>
            <summary>Salons Discord officiels à suivre</summary>
            <div class="info-content">
                <p>Consultez régulièrement ces salons essentiels sur le serveur Discord pour préparer vos sessions et suivre les annonces :</p>
                
                <a href="discord://discord.com/channels/293135138517286922/293140024751292419" class="discord-channel-link">
                    <span class="icon">#</span><span>🔷informations-générales</span>
                </a>
                <a href="discord://discord.com/channels/293135138517286922/766337485374488626" class="discord-channel-link">
                    <span class="icon">#</span><span>📆-présence-ce-soir</span>
                </a>
                <a href="discord://discord.com/channels/293135138517286922/877317216725516368" class="discord-channel-link">
                    <span class="icon">#</span><span>🤕・aide-interventions</span>
                </a>
                <a href="discord://discord.com/channels/293135138517286922/319232458899980288" class="discord-channel-link">
                    <span class="icon">#</span><span>🛑absences</span>
                </a>
                <a href="discord://discord.com/channels/293135138517286922/318782684727869451" class="discord-channel-link">
                    <span class="icon">#</span><span>📜journal-officiel</span>
                </a>
            </div>
        </details>
    </div>

</div>

<!-- Footer -->
<footer class="player-footer">
    <div class="wrap" style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 20px;">
        <div style="display: flex; align-items: center; gap: 12px;">
            <img src="assets/logo.png" alt="Studios DCPJ" style="width: 32px; height: 32px; border-radius: 8px;">
            <div>
                <div style="font-weight: 700; color: var(--text-primary); font-size: 0.95rem;">Studios DCPJ</div>
                <div style="font-size: 0.8rem; color: var(--text-muted);">Simulation GTA V RP • Espace Joueur</div>
            </div>
        </div>
        <div style="font-size: 0.85rem; color: var(--text-muted);">
            © <?= date('Y') ?> Studios DCPJ • Tous droits réservés
        </div>
        <div style="display: flex; gap: 20px; font-size: 0.85rem;">
            <a href="/espace_joueur" style="color: var(--text-secondary); text-decoration: none;">Mon Espace</a>
            <a href="https://discord.gg/g8Td5auzev" target="_blank" style="color: var(--text-secondary); text-decoration: none;">Discord Public</a>
        </div>
    </div>
</footer>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const playerStatusDot = document.getElementById('player-status-dot');
    const playerStatusText = document.getElementById('player-status-text');
    const sessionStatusDot = document.getElementById('session-status-dot');
    const sessionStatusText = document.getElementById('session-status-text');
    const playtimeVal = document.getElementById('playtime-val');

    async function updateStatus() {
        try {
            const response = await fetch('/api_get_player_status');
            if (!response.ok) return;

            const data = await response.json();

            if (playerStatusDot && playerStatusText) {
                playerStatusDot.className = `status-dot ${data.is_player_online ? 'online' : 'offline'}`;
                playerStatusText.textContent = data.is_player_online ? 'Connecté sur le serveur' : 'Déconnecté';
            }

            if (sessionStatusDot && sessionStatusText) {
                sessionStatusDot.className = `status-dot ${data.is_session_time ? 'active-time' : 'inactive-time'}`;
                sessionStatusText.textContent = data.is_session_time ? 'Temps de jeu en cours d\'enregistrement' : 'Hors plage horaire comptabilisée';
            }

            if (playtimeVal && data.formatted_playtime) {
                playtimeVal.textContent = data.formatted_playtime;
            }

        } catch (error) {
            console.error("Erreur mise à jour statut joueur:", error);
        }
    }

    updateStatus();
    setInterval(updateStatus, 10000);
});
</script>

</body>
</html>
