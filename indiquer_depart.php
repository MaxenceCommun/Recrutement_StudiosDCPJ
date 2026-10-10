<?php
require_once __DIR__.'/config.php';
require_once __DIR__.'/bdd.php';

$pdo = db();
$token = $_GET['token'] ?? '';

if (empty($token)) {
    die("Lien invalide.");
}

$st = $pdo->prepare("SELECT * FROM users WHERE departure_token = ?");
$st->execute([$token]);
$user = $st->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    die("Lien invalide ou expiré.");
}

$feedback = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['submit_reason'])) {
        $reason = trim($_POST['reason']);
        $st = $pdo->prepare("UPDATE users SET departure_reason = ?, departure_token = NULL WHERE id = ?");
        $st->execute([$reason, $user['id']]);
        $feedback = "Merci d'avoir pris le temps de nous répondre. Bonne continuation !";
        $user['departure_token'] = null; // Hide form
        
        // Alerte Staff
        global $STAFF_ALERT_WEBHOOK_URL;
        $embed = [
            'title' => "🚪 Motif de départ : " . htmlspecialchars($user['username']),
            'description' => htmlspecialchars($reason),
            'color' => hexdec('e74c3c')
        ];
        try { discord_send_webhook_message($STAFF_ALERT_WEBHOOK_URL, '', [$embed]); } catch(Exception $e) {}
    }
}

?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Indiquer votre départ — Studios DCPJ</title>
    <link rel="icon" type="image/png" href="assets/logo.png">
    <link rel="stylesheet" href="assets/styles.css">
    <style>
        body { display: flex; align-items: center; justify-content: center; min-height: 100vh; background: var(--bg-primary); margin: 0; }
        .box { background: var(--bg-secondary); padding: 30px; border-radius: 12px; max-width: 500px; width: 100%; box-shadow: 0 4px 15px rgba(0,0,0,0.2); }
        h2 { margin-top: 0; color: var(--accent-primary); }
    </style>
</head>
<body>
    <div class="box">
        <h2>Départ du Discord</h2>
        <p>Bonjour <strong><?= htmlspecialchars($user['display_name']) ?></strong>,</p>
        <p>Nous avons remarqué que vous avez quitté notre serveur Discord. Pourriez-vous nous indiquer la raison de votre départ ? Cela nous aide à améliorer le serveur.</p>
        
        <?php if ($feedback): ?>
            <div class="alert alert-success"><?= $feedback ?></div>
        <?php elseif ($user['departure_token']): ?>
            <form method="POST">
                <div class="form-group">
                    <label>Motif / Remarque :</label>
                    <textarea name="reason" rows="4" class="card" required placeholder="Expliquez brièvement pourquoi vous nous quittez..."></textarea>
                </div>
                <button type="submit" name="submit_reason" class="btn btn-primary" style="width: 100%; justify-content: center;">Envoyer</button>
            </form>
            <hr style="margin: 20px 0;">
            <p style="text-align: center; font-size: 0.9rem; color: var(--text-muted);">
                Vous avez quitté par erreur ?<br><br>
                <a href="<?= htmlspecialchars($DISCORD_SERVER_INVITE_LINK) ?>" class="btn" style="background-color: #5865F2; display: inline-block;">Rejoindre le Discord</a>
            </p>
        <?php endif; ?>
    </div>
</body>
</html>
