<?php 
require_once __DIR__.'/config.php';
require_once __DIR__.'/bdd.php'; // On inclut la BDD

$is_banned = false;
$ban_reason = '';
$is_on_cooldown = false;
$cooldown_ends_at = null;
$rejection_reason_cooldown = '';

if (!empty($_SESSION['user']['id'])) {
    $pdo = db();
    $user_id = $_SESSION['user']['id'];
    $today = date('Y-m-d H:i:s');

    // 1. On cherche d'abord une interdiction de postuler ACTIVE depuis le FTA
    $stmt = $pdo->prepare(
        "SELECT reason, system_tags_json FROM fta_sanctions 
         WHERE user_id = ? 
         AND sanction_type LIKE 'Exclusion%' 
         ORDER BY start_date DESC"
    );
    $stmt->execute([$user_id]);
    $sanctions = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($sanctions as $sanction) {
        $tags = json_decode($sanction['system_tags_json'] ?? '[]', true);
        foreach ($tags as $tag) {
            if ($tag['tag'] === 'Interdiction de postuler') {
                if (empty($tag['end_date']) || $tag['end_date'] > $today) {
                    $is_banned = true;
                    $ban_reason = 'Sanctionné via le FTA : ' . $sanction['reason'];
                    break 2;
                }
            }
        }
    }
    
    // 2. Si non banni, on vérifie le cooldown d'une candidature refusée ou can_reapply_after
    if (!$is_banned) {
        $stmt = $pdo->prepare(
            "SELECT updated_at, rejection_reason FROM applications 
             WHERE user_id = ? AND status = 'refuse' 
             ORDER BY updated_at DESC LIMIT 1"
        );
        $stmt->execute([$user_id]);
        $last_rejection = $stmt->fetch(PDO::FETCH_ASSOC);

        $stmt_u = $pdo->prepare("SELECT can_reapply_after, departure_reason, status FROM users WHERE id = ?");
        $stmt_u->execute([$user_id]);
        $u_row = $stmt_u->fetch(PDO::FETCH_ASSOC);
        $now = new DateTime();

        if ($u_row && !empty($u_row['can_reapply_after'])) {
            $user_reapply_date = new DateTime($u_row['can_reapply_after']);
            if ($user_reapply_date > $now) {
                if ($user_reapply_date->diff($now)->y >= 50) {
                    $is_banned = true;
                    $ban_reason = !empty($last_rejection['rejection_reason']) 
                        ? $last_rejection['rejection_reason'] 
                        : (!empty($u_row['departure_reason']) ? "Départ / Incident : " . $u_row['departure_reason'] : "Interdiction définitive de repostuler sur les Studios DCPJ.");
                } else {
                    $is_on_cooldown = true;
                    $cooldown_ends_at = $user_reapply_date;
                    if (!empty($last_rejection['rejection_reason'])) {
                        $rejection_reason_cooldown = $last_rejection['rejection_reason'];
                    } elseif (!empty($u_row['departure_reason'])) {
                        $rejection_reason_cooldown = "Départ / Incident : " . $u_row['departure_reason'];
                    } else {
                        $rejection_reason_cooldown = "Délai de réinscription suite à un départ ou une décision du staff";
                    }
                }
            }
        } elseif ($last_rejection) {
            $rejection_date = new DateTime($last_rejection['updated_at']);
            $reapply_date = $rejection_date->modify('+' . ($GLOBALS['APPLICATION_REJECTION_COOLDOWN'] ?? '1 month'));

            if ($reapply_date > $now) {
                $is_on_cooldown = true;
                $cooldown_ends_at = $reapply_date;
                $rejection_reason_cooldown = $last_rejection['rejection_reason'];
            }
        }
    }
}

?>
<!DOCTYPE html><html lang="fr"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Choisir une candidature — Studios DCPJ</title>
<link rel="icon" type="image/png" href="assets/logo.png">
<link rel="stylesheet" href="assets/styles.css">
<style>
    body, html { height: 100%; }
    .main-container { display: flex; flex-direction: column; justify-content: center; min-height: calc(100vh - 72px); padding: 40px 24px; box-sizing: border-box; }
    .page-header { text-align: center; margin-bottom: 50px; }
    .page-header h2 { font-size: 2.8rem; font-weight: 800; margin-bottom: 12px; }
    .page-header p { font-size: 1.2rem; color: var(--text-secondary); max-width: 700px; margin: auto; }
    .choice-layout { display: grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap: 24px; max-width: 1300px; width: 100%; margin: 0 auto; }
    .choice-card { position: relative; display: flex; flex-direction: column; justify-content: center; align-items: center; text-align: center; min-height: 400px; padding: 36px 24px; border-radius: 20px; overflow: hidden; text-decoration: none; color: var(--text-primary); border: 1px solid var(--border-color); transition: transform 0.3s ease, box-shadow 0.3s ease; }
    .choice-card::before { content: ''; position: absolute; top: 0; left: 0; width: 100%; height: 100%; background-size: cover; background-position: center; filter: brightness(0.35); transition: filter 0.3s ease, transform 0.3s ease; z-index: -1; }
    .choice-card:hover { transform: translateY(-5px); box-shadow: 0 15px 50px rgba(0,0,0,0.6); border-color: var(--accent-primary); }
    .choice-card:hover::before { filter: brightness(0.55); transform: scale(1.04); }
    #choice-player::before { background-image: url('assets/backgrounds/bmpm.png'); }
    #choice-operator::before { background-image: url('assets/backgrounds/cic.jpg'); }
    #choice-developer::before { background-image: url('assets/backgrounds/audience.jpg'); }
    .choice-badge-icon { font-size: 2.2rem; margin-bottom: 12px; }
    .choice-card h3 { font-size: 2rem; font-weight: 700; margin-bottom: 14px; text-shadow: 0 2px 10px rgba(0,0,0,0.8); }
    .choice-card p { font-size: 1.05rem; color: #e2e8f0; max-width: 420px; margin-bottom: 24px; text-shadow: 0 1px 5px rgba(0,0,0,0.8); line-height: 1.5; flex: 1; }
    .choice-card .btn { background: rgba(255, 255, 255, 0.95); color: #0a0a0a; font-weight: 700; border: none; padding: 12px 24px; backdrop-filter: blur(5px); }
    .cooldown-box { background: var(--bg-secondary); border-radius: 16px; padding: 40px; max-width: 800px; margin: 0 auto; text-align: center; border: 1px solid var(--border-color); }
    #countdown-timer { font-size: 2rem; font-weight: bold; color: var(--accent-primary); margin: 20px 0; display: block; }
    @media (max-width: 1100px) { .choice-layout { grid-template-columns: 1fr; max-width: 600px; } }
    @media (max-width: 600px) {
        .main-container { padding: 20px 15px; }
        .page-header { margin-bottom: 25px; }
        .page-header h2 { font-size: 1.8rem; }
        .page-header p { font-size: 1rem; }
        .choice-card { min-height: 250px; padding: 25px 15px; border-radius: 14px; }
        .choice-card h3 { font-size: 1.6rem; }
        .choice-card p { font-size: 0.95rem; }
        .cooldown-box { padding: 25px 15px; }
        #countdown-timer { font-size: 1.5rem; }
    }
</style>
</head><body>
<?php include __DIR__.'/partiels_entete.php'; ?>

<div class="main-container">
    <div class="page-header">
        <h2>Postuler chez Studios DCPJ</h2>
        <p>Veuillez choisir le type de poste pour lequel vous souhaitez nous rejoindre. Chaque rôle est essentiel au fonctionnement et à l'excellence de notre communauté.</p>
    </div>

    <?php if ($is_banned): ?>
        <div class="cooldown-box">
            <h3 style="color: var(--accent-red);">Accès au recrutement impossible</h3>
            <p class="text-secondary">Vous ne pouvez pas postuler car une sanction active a été trouvée sur votre dossier.</p>
            <p><strong>Motif :</strong> <?= htmlspecialchars($ban_reason) ?></p>
            <p class="text-muted mt-2">Si vous pensez qu'il s'agit d'une erreur, veuillez contacter un membre du bureau.</p>
        </div>
    <?php elseif ($is_on_cooldown): ?>
        <div class="cooldown-box">
            <h3 style="color: var(--accent-primary);">Candidature en attente</h3>
            <p class="text-secondary">Votre précédente candidature a été refusée. Pour garantir un processus de recrutement équitable, un délai d'attente est appliqué avant de pouvoir postuler à nouveau.</p>
            <p><strong>Motif du refus :</strong> <?= htmlspecialchars($rejection_reason_cooldown) ?></p>
            <p class="mt-2">Vous pourrez déposer une nouvelle candidature dans :</p>
            <div id="countdown-timer" data-date="<?= $cooldown_ends_at->format('Y-m-d H:i:s') ?>">--:--:--:--</div>
        </div>
    <?php else: ?>
        <div class="choice-layout">
            <a href="candidater.php" class="choice-card" id="choice-player">
                <div class="choice-badge-icon">🎮</div>
                <h3>Candidature Joueur</h3>
                <p>Pour rejoindre nos serveurs de simulation sur GTA V / FiveM en tant que policier, pompier, ou civil. Nécessite le jeu GTA V.</p>
                <span class="btn">Postuler comme Joueur</span>
            </a>
            <a href="candidater_operateur.php" class="choice-card" id="choice-operator">
                <div class="choice-badge-icon">🎧</div>
                <h3>Candidature Opérateur</h3>
                <p>Pour assurer la gestion des appels d'urgence (Police / Secours) via nos outils de régulation externes. Ne nécessite pas le jeu GTA V.</p>
                <span class="btn">Postuler comme Opérateur</span>
            </a>
            <a href="candidater_developpeur.php" class="choice-card" id="choice-developer">
                <div class="choice-badge-icon">💻</div>
                <h3>Candidature Développeur</h3>
                <p>Pour participer à la création de scripts LUA FiveM, outils web, bots Discord ou mapping 3D. Dépôt de portfolio requis.</p>
                <span class="btn">Postuler comme Développeur</span>
            </a>
        </div>
    <?php endif; ?>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const timerElement = document.getElementById('countdown-timer');
    if (timerElement) {
        const endDate = new Date(timerElement.getAttribute('data-date')).getTime();

        const countdown = setInterval(function() {
            const now = new Date().getTime();
            const distance = endDate - now;

            if (distance < 0) {
                clearInterval(countdown);
                timerElement.innerHTML = "Vous pouvez maintenant postuler !";
                window.location.reload(); // Recharger la page pour débloquer
                return;
            }

            const days = Math.floor(distance / (1000 * 60 * 60 * 24));
            const hours = Math.floor((distance % (1000 * 60 * 60 * 24)) / (1000 * 60 * 60));
            const minutes = Math.floor((distance % (1000 * 60 * 60)) / (1000 * 60));
            const seconds = Math.floor((distance % (1000 * 60)) / 1000);

            timerElement.innerHTML = `${days}j ${hours}h ${minutes}m ${seconds}s`;
        }, 1000);
    }
});
</script>

</body></html>