<?php
require_once __DIR__.'/config.php';
require_once __DIR__.'/bdd.php';
require_once __DIR__.'/api_discord.php';

header('Content-Type: application/json');
$input = json_decode(file_get_contents('php://input'), true) ?: $_POST;
$token = $input['token'] ?? '';
$discord_id = $input['discord_id'] ?? '';
$sign = $input['sign'] ?? '';

// HMAC simple (optionnel)
if (!empty($API_SHARED_SECRET)) {
  $payload = $token.'|'.$discord_id;
  if (!hash_equals(hash_hmac('sha256', $payload, $API_SHARED_SECRET), $sign)) {
    http_response_code(403); echo json_encode(['ok'=>false,'err'=>'bad-sign']); exit;
  }
}

$pdo = db();
$st = $pdo->prepare('SELECT a.*, u.discord_id FROM applications a JOIN users u ON u.id=a.user_id WHERE a.integration_token=?');
$st->execute([$token]);
$app = $st->fetch(PDO::FETCH_ASSOC);
if (!$app) { http_response_code(404); echo json_encode(['ok'=>false,'err'=>'bad-token']); exit; }
if (!empty($discord_id) && $discord_id !== $app['discord_id']) { http_response_code(403); echo json_encode(['ok'=>false,'err'=>'mismatch']); exit; }

$pdo->prepare("UPDATE applications SET status='onboarding_done', updated_at=NOW() WHERE id=?")->execute([$app['id']]);

// Rôles Discord
if (!empty($DISCORD_PLAYER_ROLE_ID)) {
  try { discord_add_role($app['discord_id'], $DISCORD_PLAYER_ROLE_ID); } catch (Exception $e) {}
}
if (!empty($DISCORD_INTEGRATION_ROLE_ID)) {
  try { discord_remove_role($app['discord_id'], $DISCORD_INTEGRATION_ROLE_ID); } catch (Exception $e) {}
}

echo json_encode(['ok'=>true]);