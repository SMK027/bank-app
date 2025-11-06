<?php
/**
 * Routeur principal de l'API REST
 */

// Démarrer la mesure du temps d'exécution
$startTime = microtime(true);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/middleware.php';

// Récupérer la route demandée
$requestUri = $_SERVER['REQUEST_URI'];
$scriptName = dirname($_SERVER['SCRIPT_NAME']);
$path = str_replace($scriptName, '', $requestUri);
$path = parse_url($path, PHP_URL_PATH);
$path = str_replace('/api', '', $path);

// Supprimer le slash final s'il existe
$path = rtrim($path, '/');

// Router les requêtes vers les bons endpoints
if (preg_match('#^/auth(/.*)?$#', $path)) {
    // Routes d'authentification
    $_SERVER['PATH_INFO'] = preg_replace('#^/auth#', '', $path);
    require __DIR__ . '/auth.php';
    
} elseif (preg_match('#^/accounts(/.*)?$#', $path)) {
    // Routes des comptes
    $_SERVER['PATH_INFO'] = preg_replace('#^/accounts#', '', $path) ?: '/';
    require __DIR__ . '/endpoints/accounts.php';
    
} elseif (preg_match('#^/operations(/.*)?$#', $path)) {
    // Routes des opérations
    $_SERVER['PATH_INFO'] = preg_replace('#^/operations#', '', $path) ?: '/';
    require __DIR__ . '/endpoints/operations.php';
    
} elseif (preg_match('#^/user(/.*)?$#', $path)) {
    // Routes utilisateur
    $_SERVER['PATH_INFO'] = preg_replace('#^/user#', '', $path) ?: '/';
    require __DIR__ . '/endpoints/user.php';
    
} elseif ($path === '' || $path === '/') {
    // Route racine - Documentation de l'API
    sendJsonResponse([
        'success' => true,
        'api' => 'Bank App API',
        'version' => API_VERSION,
        'endpoints' => [
            'auth' => [
                'POST /api/auth/login' => 'Connexion et obtention d\'un token JWT',
                'POST /api/auth/refresh' => 'Rafraîchir le token d\'accès',
                'GET /api/auth/discord/authorize' => 'Initier la liaison Discord OAuth2',
                'GET /api/auth/discord/callback' => 'Callback OAuth2 Discord',
                'POST /api/auth/discord/token' => 'Obtenir un token JWT via Discord ID (bot uniquement)'
            ],
            'accounts' => [
                'GET /api/accounts' => 'Liste des comptes de l\'utilisateur',
                'GET /api/accounts/{id}' => 'Détails d\'un compte',
                'GET /api/accounts/{id}/balance' => 'Solde d\'un compte',
                'GET /api/accounts/{id}/operations' => 'Opérations d\'un compte'
            ],
            'operations' => [
                'POST /api/operations' => 'Créer une opération',
                'GET /api/operations/{id}' => 'Détails d\'une opération',
                'GET /api/operations/search' => 'Rechercher des opérations'
            ],
            'user' => [
                'GET /api/user/profile' => 'Profil de l\'utilisateur',
                'GET /api/user/discord' => 'Informations de liaison Discord',
                'DELETE /api/user/discord' => 'Délier le compte Discord',
                'GET /api/user/stats' => 'Statistiques de l\'utilisateur',
                'GET /api/user/notifications' => 'Notifications de l\'utilisateur'
            ]
        ],
        'authentication' => [
            'type' => 'Bearer Token (JWT)',
            'header' => 'Authorization: Bearer {token}'
        ],
        'rate_limiting' => [
            'requests' => RATE_LIMIT_REQUESTS,
            'window' => RATE_LIMIT_WINDOW . ' secondes'
        ]
    ]);
    
} else {
    // Route non trouvée
    sendJsonError('Endpoint non trouvé', 404);
}

// Calculer le temps d'exécution
$executionTime = microtime(true) - $startTime;

// Logger la requête si un utilisateur est authentifié
if (isset($currentUser)) {
    logApiRequest(
        $currentUser['user_id'],
        $path,
        $_SERVER['REQUEST_METHOD'],
        http_response_code(),
        null,
        null,
        $executionTime
    );
}
