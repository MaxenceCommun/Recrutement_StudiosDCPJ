<?php
require_once __DIR__.'/config.php';

function http_post($url, $fields, $headers=[]) {
  $ch = curl_init($url);
  curl_setopt($ch, CURLOPT_POST, true);
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
  curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
  curl_setopt($ch, CURLOPT_POSTFIELDS, $fields);
  $resp = curl_exec($ch);
  if ($resp === false) throw new Exception(curl_error($ch));
  $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);
  return [$code, $resp];
}

function http_get($url, $headers=[]) {
  $ch = curl_init($url);
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
  curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
  $resp = curl_exec($ch);
  if ($resp === false) throw new Exception(curl_error($ch));
  $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);
  return [$code, $resp];
}

function http_delete($url, $headers=[]) {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    $resp = curl_exec($ch);
    if ($resp === false) throw new Exception(curl_error($ch));
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$code, $resp];
}

function discord_exchange_code($code) {
  global $DISCORD_CLIENT_ID, $DISCORD_CLIENT_SECRET, $DISCORD_REDIRECT_URI;
  $fields = http_build_query([
    'client_id' => $DISCORD_CLIENT_ID,
    'client_secret' => $DISCORD_CLIENT_SECRET,
    'grant_type' => 'authorization_code',
    'code' => $code,
    'redirect_uri' => $DISCORD_REDIRECT_URI,
  ]);
  [$status, $resp] = http_post('https://discord.com/api/oauth2/token', $fields, ['Content-Type: application/x-www-form-urlencoded']);
  if ($status !== 200) throw new Exception('Discord token error: '.$resp);
  return json_decode($resp, true);
}

function discord_me($access_token) {
  [$status, $resp] = http_get('https://discord.com/api/users/@me', ['Authorization: Bearer '.$access_token]);
  if ($status !== 200) throw new Exception('Discord me error: '.$resp);
  return json_decode($resp, true);
}

function discord_get_member($user_id, $guild_id = null) {
  global $DISCORD_BOT_TOKEN, $DISCORD_GUILD_ID;
  $target_guild = $guild_id ?? $DISCORD_GUILD_ID;
  if(empty($target_guild)) return null;
  [$status, $resp] = http_get("https://discord.com/api/guilds/$target_guild/members/$user_id", ['Authorization: Bot '.$DISCORD_BOT_TOKEN]);
  if ($status === 404) return null;
  if ($status !== 200) throw new Exception('Discord member error: '.$resp);
  return json_decode($resp, true);
}

// NOUVELLE FONCTION : Récupère tous les membres du serveur Discord
function discord_get_all_guild_members($guild_id = null) {
    global $DISCORD_BOT_TOKEN, $DISCORD_GUILD_ID;
    $target_guild = $guild_id ?? $DISCORD_GUILD_ID;
    if(empty($target_guild) || empty($DISCORD_BOT_TOKEN)) {
        return [];
    }

    $all_members = [];
    $after = '0';

    while (true) {
        $url = "https://discord.com/api/guilds/$target_guild/members?limit=1000&after=" . $after;
        [$status, $resp] = http_get($url, ['Authorization: Bot ' . $DISCORD_BOT_TOKEN]);

        if ($status !== 200) {
            throw new Exception("Erreur lors de la récupération des membres de la guilde : " . $resp);
        }

        $members_chunk = json_decode($resp, true);
        if (empty($members_chunk)) {
            break; // Plus de membres à récupérer
        }

        $all_members = array_merge($all_members, $members_chunk);
        $last_member = end($members_chunk);
        $after = $last_member['user']['id'];
    }
    
    // Trier les membres par leur pseudo
    usort($all_members, function($a, $b) {
        return strcasecmp($a['user']['global_name'] ?? $a['user']['username'], $b['user']['global_name'] ?? $b['user']['username']);
    });

    return $all_members;
}


function discord_add_role($user_id, $role_id) {
  global $DISCORD_BOT_TOKEN, $DISCORD_GUILD_ID;
  if(empty($DISCORD_GUILD_ID) || empty($role_id)) return;
  $ch = curl_init("https://discord.com/api/guilds/$DISCORD_GUILD_ID/members/$user_id/roles/$role_id");
  curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
  curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: Bot '.$DISCORD_BOT_TOKEN, 'Content-Length: 0']);
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
  $resp = curl_exec($ch);
  $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);
  if ($code !== 204) throw new Exception('Discord add role error: '.$resp);
}

function discord_remove_role($user_id, $role_id) {
    global $DISCORD_BOT_TOKEN, $DISCORD_GUILD_ID;
    if(empty($DISCORD_GUILD_ID) || empty($role_id)) return;
    [$code, $resp] = http_delete("https://discord.com/api/guilds/$DISCORD_GUILD_ID/members/$user_id/roles/$role_id", ['Authorization: Bot '.$DISCORD_BOT_TOKEN]);
    if ($code !== 204) {
        throw new Exception('Discord remove role error: ' . $resp);
    }
}

function discord_create_invite($channel_id) {
    global $DISCORD_BOT_TOKEN, $DISCORD_GUILD_ID;
    if(empty($DISCORD_GUILD_ID) || empty($channel_id)) throw new Exception('Guild ID ou Channel ID manquant pour créer une invitation.');
    $payload = json_encode(['max_age' => 86400, 'max_uses' => 1, 'unique' => true]); // Valide 1 jour, 1 utilisation
    [$code, $resp] = http_post("https://discord.com/api/v10/channels/{$channel_id}/invites", $payload, [
        'Authorization: Bot ' . $DISCORD_BOT_TOKEN,
        'Content-Type: application/json'
    ]);
    if ($code !== 200) {
        throw new Exception('Discord create invite error: ' . $resp);
    }
    $invite = json_decode($resp, true);
    return 'https://discord.gg/' . $invite['code'];
}

function discord_send_dm($user_id, $content, $image_url = null) {
  global $DISCORD_BOT_TOKEN;
  if (empty($DISCORD_BOT_TOKEN)) return;
  try {
    [$status, $resp] = http_post('https://discord.com/api/users/@me/channels', json_encode(['recipient_id' => $user_id]), ['Authorization: Bot '.$DISCORD_BOT_TOKEN, 'Content-Type: application/json']);
    if ($status !== 200) throw new Exception('Discord DM channel error: '.$resp);
    $channel = json_decode($resp, true);
    
    $payload = ['content' => $content];
    if ($image_url) {
        $payload['embeds'] = [
            [
                'image' => ['url' => $image_url],
                'color' => hexdec('3498db')
            ]
        ];
    }

    [$status2, $resp2] = http_post('https://discord.com/api/channels/'.$channel['id'].'/messages', json_encode($payload), ['Authorization: Bot '.$DISCORD_BOT_TOKEN, 'Content-Type: application/json']);
    if ($status2 !== 200) throw new Exception('Discord DM send error: '.$resp2);
  } catch (Exception $e) {
    error_log("Failed to send DM to {$user_id}: " . $e->getMessage());
  }
}

function discord_send_webhook_message($webhook_url, $content, $embeds = []) {
    if (empty($webhook_url) || strpos($webhook_url, 'discord.com') === false) return;
    $payload = json_encode(['content' => $content, 'embeds' => $embeds]);
    try {
        http_post($webhook_url, $payload, ['Content-Type: application/json']);
    } catch (Exception $e) {
        error_log("Failed to send webhook message: " . $e->getMessage());
    }
}

function discord_is_user_in_guild($user_id, $guild_id) {
    global $DISCORD_BOT_TOKEN;
    if (empty($DISCORD_BOT_TOKEN) || empty($guild_id) || empty($user_id)) return false;
    
    [$status, $resp] = http_get("https://discord.com/api/v10/guilds/$guild_id/members/$user_id", [
        'Authorization: Bot ' . $DISCORD_BOT_TOKEN
    ]);
    
    return ($status === 200);
}

// NOUVELLE FONCTION : Envoi de candidature avec boutons (Interactions)
function discord_send_application_message($channel_id, $app_id, $app_type, $username, $url, $with_votes = true, $mention = null, $status_text = '⏳ En attente de traitement', $details = []) {
    global $DISCORD_BOT_TOKEN;
    if (empty($DISCORD_BOT_TOKEN) || empty($channel_id)) return;
    
    $type_label = ($app_type === 'developer' || $app_type === 'developpement') ? 'Développeur' : ($app_type === 'player' ? 'Joueur' : 'Opérateur');
    
    $content = "Nouvelle candidature **$type_label** de `$username`.";
    if ($mention) {
        $content = "<@&$mention> " . $content;
    }

    $components = [];
    if ($with_votes) {
        $components = [
            [
                'type' => 1, // Action Row
                'components' => [
                    [
                        'type' => 2, // Button
                        'style' => 3, // Success (Green)
                        'label' => 'Pour',
                        'custom_id' => 'vote_pour_' . $app_id,
                        'emoji' => ['name' => '✅']
                    ],
                    [
                        'type' => 2, 
                        'style' => 2, // Secondary (Gray)
                        'label' => 'Neutre',
                        'custom_id' => 'vote_neutre_' . $app_id,
                        'emoji' => ['name' => '➖']
                    ],
                    [
                        'type' => 2, 
                        'style' => 4, // Danger (Red)
                        'label' => 'Contre',
                        'custom_id' => 'vote_contre_' . $app_id,
                        'emoji' => ['name' => '❌']
                    ],
                    [
                        'type' => 2,
                        'style' => 5, // Link (Gray with popup icon)
                        'label' => 'Ouvrir le dossier',
                        'url' => $url
                    ]
                ]
            ]
        ];
    } else {
        $components = [
            [
                'type' => 1,
                'components' => [
                    [
                        'type' => 2,
                        'style' => 5,
                        'label' => 'Ouvrir le dossier',
                        'url' => $url
                    ]
                ]
            ]
        ];
    }

    $fields = [];
    if ($status_text !== null) {
        $fields[] = ['name' => 'Statut Actuel', 'value' => $status_text, 'inline' => true];
    }
    
    foreach ($details as $name => $value) {
        if (!empty($value)) {
            $fields[] = ['name' => $name, 'value' => $value, 'inline' => true];
        }
    }

    $payload = [
        'content' => $content,
        'embeds' => [
            [
                'title' => "Candidature #$app_id - $username",
                'description' => $with_votes ? "Une nouvelle candidature vient d'arriver sur le site.\nVous pouvez voter directement via les boutons ci-dessous ou consulter le dossier complet." : "Une nouvelle candidature pour le pôle développement vient d'arriver.",
                'color' => hexdec('3498db'),
                'url' => $url,
                'fields' => $fields
            ]
        ],
        'components' => $components
    ];
    
    try {
        http_post("https://discord.com/api/v10/channels/$channel_id/messages", json_encode($payload), [
            'Authorization: Bot ' . $DISCORD_BOT_TOKEN,
            'Content-Type: application/json'
        ]);
    } catch (Exception $e) {
        error_log("Failed to send application message to channel $channel_id: " . $e->getMessage());
    }
}