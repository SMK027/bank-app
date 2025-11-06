<?php
/**
 * Middleware pour l'API REST
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/../includes/db.php';

/**
 * Classe pour gérer les JWT
 */
class JWT {
    /**
     * Encode un payload en JWT
     */
    public static function encode($payload) {
        $header = [
            'typ' => 'JWT',
            'alg' => JWT_ALGORITHM
        ];
        
        $header = base64_encode(json_encode($header));
        $payload = base64_encode(json_encode($payload));
        
        $signature = hash_hmac('sha256', "$header.$payload", JWT_SECRET, true);
        $signature = base64_encode($signature);
        
        return "$header.$payload.$signature";
    }
    
    /**
     * Décode et vérifie un JWT
     */
    public static function decode($token) {
        $parts = explode('.', $token);
        
        if (count($parts) !== 3) {
            return null;
        }
        
        list($header, $payload, $signature) = $parts;
        
        // Vérification de la signature
        $validSignature = base64_encode(
            hash_hmac('sha256', "$header.$payload", JWT_SECRET, true)
        );
        
        if ($signature !== $validSignature) {
            return null;
        }
        
        $payload = json_decode(base64_decode($payload), true);
        
        // Vérification de l'expiration
        if (isset($payload['exp']) && $payload['exp'] < time()) {
            return null;
        }
        
        return $payload;
    }
    
    /**
     * Génère un token d'accès
     */
    public static function generateAccessToken($userId, $discordId = null, $role = 'client') {
        $payload = [
            'user_id' => $userId,
            'discord_id' => $discordId,
            'role' => $role,
            'type' => 'access',
            'iat' => time(),
            'exp' => time() + JWT_ACCESS_TOKEN_EXPIRY
        ];
        
        return self::encode($payload);
    }
    
    /**
     * Génère un token de rafraîchissement
     */
    public static function generateRefreshToken($userId, $discordId = null) {
        $payload = [
            'user_id' => $userId,
            'discord_id' => $discordId,
            'type' => 'refresh',
            'iat' => time(),
            'exp' => time() + JWT_REFRESH_TOKEN_EXPIRY
        ];
        
        return self::encode($payload);
    }
}

/**
 * Vérifie l'authentification via JWT
 */
function requireAuth() {
    $headers = getallheaders();
    $authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? null;
    
    if (!$authHeader || !preg_match('/Bearer\s+(.*)$/i', $authHeader, $matches)) {
        sendJsonError('Token d\'authentification manquant', 401);
    }
    
    $token = $matches[1];
    $payload = JWT::decode($token);
    
    if (!$payload) {
        sendJsonError('Token invalide ou expiré', 401);
    }
    
    if ($payload['type'] !== 'access') {
        sendJsonError('Type de token invalide', 401);
    }
    
    // Vérifier que l'utilisateur existe et est actif
    $db = getDB();
    $stmt = $db->prepare("SELECT id, username, role, statut FROM users WHERE id = ? AND statut = 'actif'");
    $stmt->execute([$payload['user_id']]);
    $user = $stmt->fetch();
    
    if (!$user) {
        sendJsonError('Utilisateur non trouvé ou inactif', 401);
    }
    
    // Mettre à jour la dernière utilisation du token
    try {
        $stmt = $db->prepare("UPDATE api_tokens SET last_used = NOW() WHERE token = ? AND revoked = FALSE");
        $stmt->execute([$token]);
    } catch (Exception $e) {
        // Token pas dans la base, mais JWT valide (pour les tokens générés à la volée)
    }
    
    return [
        'user_id' => $user['id'],
        'username' => $user['username'],
        'role' => $user['role'],
        'discord_id' => $payload['discord_id'] ?? null
    ];
}

/**
 * Vérifie que l'utilisateur est un admin
 */
function requireAdminAuth() {
    $user = requireAuth();
    
    if ($user['role'] !== 'admin') {
        sendJsonError('Accès refusé : privilèges administrateur requis', 403);
    }
    
    return $user;
}

/**
 * Vérifie le rate limiting
 */
function checkRateLimit($userId, $endpoint) {
    $db = getDB();
    
    // Nettoyer les anciennes entrées
    $stmt = $db->prepare("
        DELETE FROM api_rate_limits 
        WHERE window_start < DATE_SUB(NOW(), INTERVAL ? SECOND)
    ");
    $stmt->execute([RATE_LIMIT_WINDOW]);
    
    // Vérifier le nombre de requêtes
    $stmt = $db->prepare("
        SELECT request_count, window_start 
        FROM api_rate_limits 
        WHERE user_id = ? AND endpoint = ? 
        AND window_start >= DATE_SUB(NOW(), INTERVAL ? SECOND)
    ");
    $stmt->execute([$userId, $endpoint, RATE_LIMIT_WINDOW]);
    $limit = $stmt->fetch();
    
    if ($limit) {
        if ($limit['request_count'] >= RATE_LIMIT_REQUESTS) {
            sendJsonError('Trop de requêtes. Veuillez réessayer plus tard.', 429);
        }
        
        // Incrémenter le compteur
        $stmt = $db->prepare("
            UPDATE api_rate_limits 
            SET request_count = request_count + 1, last_request = NOW() 
            WHERE user_id = ? AND endpoint = ?
        ");
        $stmt->execute([$userId, $endpoint]);
    } else {
        // Créer une nouvelle entrée
        $stmt = $db->prepare("
            INSERT INTO api_rate_limits (user_id, endpoint, request_count, window_start) 
            VALUES (?, ?, 1, NOW())
        ");
        $stmt->execute([$userId, $endpoint]);
    }
}

/**
 * Valide les données d'entrée
 */
function validateInput($data, $rules) {
    $errors = [];
    
    foreach ($rules as $field => $rule) {
        $value = $data[$field] ?? null;
        
        // Required
        if (isset($rule['required']) && $rule['required'] && empty($value)) {
            $errors[$field] = "Le champ $field est requis";
            continue;
        }
        
        if (empty($value)) {
            continue;
        }
        
        // Type
        if (isset($rule['type'])) {
            switch ($rule['type']) {
                case 'int':
                    if (!is_numeric($value) || (int)$value != $value) {
                        $errors[$field] = "Le champ $field doit être un entier";
                    }
                    break;
                case 'float':
                    if (!is_numeric($value)) {
                        $errors[$field] = "Le champ $field doit être un nombre";
                    }
                    break;
                case 'string':
                    if (!is_string($value)) {
                        $errors[$field] = "Le champ $field doit être une chaîne de caractères";
                    }
                    break;
                case 'email':
                    if (!filter_var($value, FILTER_VALIDATE_EMAIL)) {
                        $errors[$field] = "Le champ $field doit être un email valide";
                    }
                    break;
            }
        }
        
        // Min/Max length
        if (isset($rule['min']) && strlen($value) < $rule['min']) {
            $errors[$field] = "Le champ $field doit contenir au moins {$rule['min']} caractères";
        }
        if (isset($rule['max']) && strlen($value) > $rule['max']) {
            $errors[$field] = "Le champ $field ne doit pas dépasser {$rule['max']} caractères";
        }
        
        // Min/Max value
        if (isset($rule['min_value']) && $value < $rule['min_value']) {
            $errors[$field] = "Le champ $field doit être supérieur ou égal à {$rule['min_value']}";
        }
        if (isset($rule['max_value']) && $value > $rule['max_value']) {
            $errors[$field] = "Le champ $field doit être inférieur ou égal à {$rule['max_value']}";
        }
        
        // Enum
        if (isset($rule['enum']) && !in_array($value, $rule['enum'])) {
            $errors[$field] = "Le champ $field doit être l'une des valeurs suivantes: " . implode(', ', $rule['enum']);
        }
    }
    
    if (!empty($errors)) {
        sendJsonError('Erreur de validation', 400, $errors);
    }
    
    return true;
}

/**
 * Récupère le corps de la requête en JSON
 */
function getJsonInput() {
    $input = file_get_contents('php://input');
    $data = json_decode($input, true);
    
    if (json_last_error() !== JSON_ERROR_NONE) {
        sendJsonError('JSON invalide', 400);
    }
    
    return $data ?? [];
}
