<?php
/**
 * Configuration de l'API REST
 */

// Chargement de la configuration de base
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/config.php';

// Configuration JWT
define('JWT_SECRET', 'VOTRE_CLE_SECRETE_JWT_A_CHANGER'); // À CHANGER en production
define('JWT_ALGORITHM', 'HS256');
define('JWT_ACCESS_TOKEN_EXPIRY', 3600); // 1 heure
define('JWT_REFRESH_TOKEN_EXPIRY', 604800); // 7 jours

// Configuration Discord OAuth2
define('DISCORD_CLIENT_ID', 'VOTRE_CLIENT_ID_DISCORD');
define('DISCORD_CLIENT_SECRET', 'VOTRE_CLIENT_SECRET_DISCORD');
define('DISCORD_REDIRECT_URI', BASE_URL . '/api/auth/discord/callback');
define('DISCORD_BOT_TOKEN', 'VOTRE_BOT_TOKEN_DISCORD');

// Configuration API
define('API_VERSION', 'v1');
define('API_BASE_URL', BASE_URL . '/api');

// Rate limiting
define('RATE_LIMIT_REQUESTS', 60); // Nombre de requêtes
define('RATE_LIMIT_WINDOW', 60); // Fenêtre en secondes (1 minute)

// CORS
define('CORS_ALLOWED_ORIGINS', [
    'http://localhost',
    'https://discord.com',
    BASE_URL
]);

// Headers de sécurité
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('X-XSS-Protection: 1; mode=block');
header('Content-Type: application/json; charset=utf-8');

// Gestion CORS
if (isset($_SERVER['HTTP_ORIGIN'])) {
    $origin = $_SERVER['HTTP_ORIGIN'];
    if (in_array($origin, CORS_ALLOWED_ORIGINS)) {
        header("Access-Control-Allow-Origin: $origin");
    }
}

header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
header('Access-Control-Max-Age: 86400');

// Réponse aux requêtes OPTIONS (preflight)
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

/**
 * Fonction pour envoyer une réponse JSON
 */
function sendJsonResponse($data, $statusCode = 200) {
    http_response_code($statusCode);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit();
}

/**
 * Fonction pour envoyer une erreur JSON
 */
function sendJsonError($message, $statusCode = 400, $details = null) {
    $response = [
        'success' => false,
        'error' => $message,
        'code' => $statusCode
    ];
    
    if ($details !== null) {
        $response['details'] = $details;
    }
    
    sendJsonResponse($response, $statusCode);
}

/**
 * Fonction pour logger les requêtes API
 */
function logApiRequest($userId, $endpoint, $method, $statusCode, $requestData = null, $responseData = null, $executionTime = 0) {
    try {
        $db = getDB();
        $stmt = $db->prepare("
            INSERT INTO api_logs (user_id, endpoint, method, status_code, request_data, response_data, ip_address, user_agent, execution_time)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        
        $stmt->execute([
            $userId,
            $endpoint,
            $method,
            $statusCode,
            $requestData ? json_encode($requestData) : null,
            $responseData ? json_encode($responseData) : null,
            $_SERVER['REMOTE_ADDR'] ?? null,
            $_SERVER['HTTP_USER_AGENT'] ?? null,
            $executionTime
        ]);
    } catch (Exception $e) {
        error_log("Erreur lors du logging API: " . $e->getMessage());
    }
}
