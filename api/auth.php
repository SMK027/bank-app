<?php
/**
 * Endpoints d'authentification API
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/middleware.php';
require_once __DIR__ . '/../includes/db.php';

$method = $_SERVER['REQUEST_METHOD'];
$path = $_SERVER['PATH_INFO'] ?? '/';

// Route: POST /api/auth/login - Connexion classique pour obtenir un token JWT
if ($method === 'POST' && $path === '/login') {
    $data = getJsonInput();
    
    validateInput($data, [
        'username' => ['required' => true, 'type' => 'string'],
        'password' => ['required' => true, 'type' => 'string']
    ]);
    
    $db = getDB();
    $stmt = $db->prepare("SELECT id, username, password_hash, role, statut FROM users WHERE username = ?");
    $stmt->execute([$data['username']]);
    $user = $stmt->fetch();
    
    if (!$user || !password_verify($data['password'], $user['password_hash'])) {
        sendJsonError('Identifiants incorrects', 401);
    }
    
    if ($user['statut'] !== 'actif') {
        sendJsonError('Compte inactif ou suspendu', 403);
    }
    
    // Vérifier si l'utilisateur a un Discord lié
    $stmt = $db->prepare("SELECT discord_id FROM discord_links WHERE user_id = ? AND active = TRUE");
    $stmt->execute([$user['id']]);
    $discordLink = $stmt->fetch();
    
    $discordId = $discordLink ? $discordLink['discord_id'] : null;
    
    // Générer les tokens
    $accessToken = JWT::generateAccessToken($user['id'], $discordId, $user['role']);
    $refreshToken = JWT::generateRefreshToken($user['id'], $discordId);
    
    // Sauvegarder les tokens
    $stmt = $db->prepare("
        INSERT INTO api_tokens (user_id, token, type, expires_at, ip_address, user_agent)
        VALUES (?, ?, 'access', FROM_UNIXTIME(?), ?, ?),
               (?, ?, 'refresh', FROM_UNIXTIME(?), ?, ?)
    ");
    $stmt->execute([
        $user['id'], $accessToken, time() + JWT_ACCESS_TOKEN_EXPIRY, $_SERVER['REMOTE_ADDR'], $_SERVER['HTTP_USER_AGENT'],
        $user['id'], $refreshToken, time() + JWT_REFRESH_TOKEN_EXPIRY, $_SERVER['REMOTE_ADDR'], $_SERVER['HTTP_USER_AGENT']
    ]);
    
    // Mettre à jour la dernière connexion
    $stmt = $db->prepare("UPDATE users SET derniere_connexion = NOW() WHERE id = ?");
    $stmt->execute([$user['id']]);
    
    sendJsonResponse([
        'success' => true,
        'access_token' => $accessToken,
        'refresh_token' => $refreshToken,
        'expires_in' => JWT_ACCESS_TOKEN_EXPIRY,
        'user' => [
            'id' => $user['id'],
            'username' => $user['username'],
            'role' => $user['role'],
            'discord_linked' => $discordId !== null
        ]
    ]);
}

// Route: POST /api/auth/refresh - Rafraîchir le token d'accès
if ($method === 'POST' && $path === '/refresh') {
    $data = getJsonInput();
    
    validateInput($data, [
        'refresh_token' => ['required' => true, 'type' => 'string']
    ]);
    
    $payload = JWT::decode($data['refresh_token']);
    
    if (!$payload || $payload['type'] !== 'refresh') {
        sendJsonError('Token de rafraîchissement invalide', 401);
    }
    
    $db = getDB();
    
    // Vérifier que le token n'est pas révoqué
    $stmt = $db->prepare("SELECT id FROM api_tokens WHERE token = ? AND revoked = FALSE");
    $stmt->execute([$data['refresh_token']]);
    if (!$stmt->fetch()) {
        sendJsonError('Token révoqué', 401);
    }
    
    // Vérifier que l'utilisateur existe
    $stmt = $db->prepare("SELECT id, username, role FROM users WHERE id = ? AND statut = 'actif'");
    $stmt->execute([$payload['user_id']]);
    $user = $stmt->fetch();
    
    if (!$user) {
        sendJsonError('Utilisateur non trouvé', 401);
    }
    
    // Générer un nouveau token d'accès
    $accessToken = JWT::generateAccessToken($user['id'], $payload['discord_id'] ?? null, $user['role']);
    
    // Sauvegarder le nouveau token
    $stmt = $db->prepare("
        INSERT INTO api_tokens (user_id, token, type, expires_at, ip_address, user_agent)
        VALUES (?, ?, 'access', FROM_UNIXTIME(?), ?, ?)
    ");
    $stmt->execute([
        $user['id'], $accessToken, time() + JWT_ACCESS_TOKEN_EXPIRY, 
        $_SERVER['REMOTE_ADDR'], $_SERVER['HTTP_USER_AGENT']
    ]);
    
    sendJsonResponse([
        'success' => true,
        'access_token' => $accessToken,
        'expires_in' => JWT_ACCESS_TOKEN_EXPIRY
    ]);
}

// Route: GET /api/auth/discord/authorize - Initier l'OAuth2 Discord
if ($method === 'GET' && $path === '/discord/authorize') {
    // Cette route doit être appelée depuis l'interface web après connexion
    session_start();
    
    if (!isset($_SESSION['user_id'])) {
        sendJsonError('Vous devez être connecté pour lier votre compte Discord', 401);
    }
    
    // Générer un state pour la sécurité OAuth2
    $state = bin2hex(random_bytes(16));
    $_SESSION['discord_oauth_state'] = $state;
    $_SESSION['discord_oauth_user_id'] = $_SESSION['user_id'];
    
    $params = http_build_query([
        'client_id' => DISCORD_CLIENT_ID,
        'redirect_uri' => DISCORD_REDIRECT_URI,
        'response_type' => 'code',
        'scope' => 'identify email',
        'state' => $state
    ]);
    
    $authorizeUrl = "https://discord.com/api/oauth2/authorize?$params";
    
    // Rediriger vers Discord
    header("Location: $authorizeUrl");
    exit();
}

// Route: GET /api/auth/discord/callback - Callback OAuth2 Discord
if ($method === 'GET' && $path === '/discord/callback') {
    session_start();
    
    $code = $_GET['code'] ?? null;
    $state = $_GET['state'] ?? null;
    
    if (!$code || !$state) {
        sendJsonError('Paramètres OAuth2 manquants', 400);
    }
    
    // Vérifier le state
    if (!isset($_SESSION['discord_oauth_state']) || $state !== $_SESSION['discord_oauth_state']) {
        sendJsonError('State OAuth2 invalide', 400);
    }
    
    $userId = $_SESSION['discord_oauth_user_id'] ?? null;
    if (!$userId) {
        sendJsonError('Session expirée', 401);
    }
    
    // Échanger le code contre un access token
    $tokenUrl = 'https://discord.com/api/oauth2/token';
    $tokenData = [
        'client_id' => DISCORD_CLIENT_ID,
        'client_secret' => DISCORD_CLIENT_SECRET,
        'grant_type' => 'authorization_code',
        'code' => $code,
        'redirect_uri' => DISCORD_REDIRECT_URI
    ];
    
    $ch = curl_init($tokenUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($tokenData));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/x-www-form-urlencoded']);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode !== 200) {
        sendJsonError('Erreur lors de l\'échange du code OAuth2', 500);
    }
    
    $tokenResponse = json_decode($response, true);
    $accessToken = $tokenResponse['access_token'];
    $refreshToken = $tokenResponse['refresh_token'];
    $expiresIn = $tokenResponse['expires_in'];
    
    // Récupérer les informations de l'utilisateur Discord
    $userUrl = 'https://discord.com/api/users/@me';
    $ch = curl_init($userUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ["Authorization: Bearer $accessToken"]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode !== 200) {
        sendJsonError('Erreur lors de la récupération des informations Discord', 500);
    }
    
    $discordUser = json_decode($response, true);
    
    // Sauvegarder la liaison Discord
    $db = getDB();
    
    // Vérifier si ce Discord est déjà lié à un autre compte
    $stmt = $db->prepare("SELECT user_id FROM discord_links WHERE discord_id = ? AND active = TRUE");
    $stmt->execute([$discordUser['id']]);
    $existingLink = $stmt->fetch();
    
    if ($existingLink && $existingLink['user_id'] != $userId) {
        sendJsonError('Ce compte Discord est déjà lié à un autre compte bancaire', 409);
    }
    
    // Désactiver les anciennes liaisons pour cet utilisateur
    $stmt = $db->prepare("UPDATE discord_links SET active = FALSE WHERE user_id = ?");
    $stmt->execute([$userId]);
    
    // Créer ou mettre à jour la liaison
    $stmt = $db->prepare("
        INSERT INTO discord_links 
        (user_id, discord_id, discord_username, discord_discriminator, discord_avatar, access_token, refresh_token, token_expiry, active)
        VALUES (?, ?, ?, ?, ?, ?, ?, FROM_UNIXTIME(?), TRUE)
        ON DUPLICATE KEY UPDATE
        discord_username = VALUES(discord_username),
        discord_discriminator = VALUES(discord_discriminator),
        discord_avatar = VALUES(discord_avatar),
        access_token = VALUES(access_token),
        refresh_token = VALUES(refresh_token),
        token_expiry = VALUES(token_expiry),
        active = TRUE,
        linked_at = NOW()
    ");
    
    $stmt->execute([
        $userId,
        $discordUser['id'],
        $discordUser['username'],
        $discordUser['discriminator'] ?? '0',
        $discordUser['avatar'] ?? null,
        $accessToken,
        $refreshToken,
        time() + $expiresIn
    ]);
    
    // Logger l'activité
    $stmt = $db->prepare("
        INSERT INTO logs_activite (user_id, action, details, ip_address)
        VALUES (?, 'Liaison Discord', ?, ?)
    ");
    $stmt->execute([
        $userId,
        "Compte Discord {$discordUser['username']} lié avec succès",
        $_SERVER['REMOTE_ADDR']
    ]);
    
    // Nettoyer la session
    unset($_SESSION['discord_oauth_state']);
    unset($_SESSION['discord_oauth_user_id']);
    
    // Rediriger vers le profil avec un message de succès
    header("Location: " . BASE_URL . "/client/profil.php?discord_linked=success");
    exit();
}

// Route: POST /api/auth/discord/token - Obtenir un token JWT via Discord ID (pour le bot)
if ($method === 'POST' && $path === '/discord/token') {
    $data = getJsonInput();
    
    validateInput($data, [
        'discord_id' => ['required' => true, 'type' => 'string'],
        'bot_token' => ['required' => true, 'type' => 'string']
    ]);
    
    // Vérifier le token du bot
    if ($data['bot_token'] !== DISCORD_BOT_TOKEN) {
        sendJsonError('Token du bot invalide', 401);
    }
    
    $db = getDB();
    
    // Récupérer l'utilisateur lié à ce Discord ID
    $stmt = $db->prepare("
        SELECT dl.user_id, u.username, u.role, dl.discord_id
        FROM discord_links dl
        JOIN users u ON dl.user_id = u.id
        WHERE dl.discord_id = ? AND dl.active = TRUE AND u.statut = 'actif'
    ");
    $stmt->execute([$data['discord_id']]);
    $link = $stmt->fetch();
    
    if (!$link) {
        sendJsonError('Aucun compte bancaire lié à ce Discord', 404);
    }
    
    // Mettre à jour la dernière utilisation
    $stmt = $db->prepare("UPDATE discord_links SET last_used = NOW() WHERE discord_id = ?");
    $stmt->execute([$data['discord_id']]);
    
    // Générer un token JWT
    $accessToken = JWT::generateAccessToken($link['user_id'], $link['discord_id'], $link['role']);
    
    sendJsonResponse([
        'success' => true,
        'access_token' => $accessToken,
        'expires_in' => JWT_ACCESS_TOKEN_EXPIRY,
        'user' => [
            'id' => $link['user_id'],
            'username' => $link['username'],
            'role' => $link['role']
        ]
    ]);
}

// Route non trouvée
sendJsonError('Route non trouvée', 404);
