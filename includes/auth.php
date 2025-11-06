<?php
/**
 * Gestion de l'authentification et des autorisations
 */

/**
 * Vérifie si l'utilisateur est connecté
 */
function isLoggedIn() {
    return isset($_SESSION['user_id']) && isset($_SESSION['role']);
}

/**
 * Vérifie si l'utilisateur est un administrateur
 */
function isAdmin() {
    return isLoggedIn() && $_SESSION['role'] === 'admin';
}

/**
 * Vérifie si l'utilisateur est un client
 */
function isClient() {
    return isLoggedIn() && $_SESSION['role'] === 'client';
}

/**
 * Redirige vers la page de connexion si non connecté
 */
function requireLogin() {
    if (!isLoggedIn()) {
        header('Location: ' . BASE_URL . '/login.php');
        exit();
    }
}

/**
 * Redirige vers la page d'accueil si non admin
 */
function requireAdmin() {
    requireLogin();
    if (!isAdmin()) {
        header('Location: ' . BASE_URL . '/client/index.php');
        exit();
    }
}

/**
 * Redirige vers la page d'accueil si non client
 */
function requireClient() {
    requireLogin();
    if (!isClient()) {
        header('Location: ' . BASE_URL . '/admin/index.php');
        exit();
    }
}

/**
 * Connecte un utilisateur
 */
function loginUser($username, $password) {
    $db = getDB();
    
    $stmt = $db->prepare("SELECT id, username, password_hash, email, nom, prenom, role, statut FROM users WHERE username = ? AND statut = 'actif'");
    $stmt->execute([$username]);
    $user = $stmt->fetch();
    
    if ($user && password_verify($password, $user['password_hash'])) {
        // Mise à jour de la dernière connexion
        $updateStmt = $db->prepare("UPDATE users SET derniere_connexion = NOW() WHERE id = ?");
        $updateStmt->execute([$user['id']]);
        
        // Création de la session
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['username'] = $user['username'];
        $_SESSION['email'] = $user['email'];
        $_SESSION['nom'] = $user['nom'];
        $_SESSION['prenom'] = $user['prenom'];
        $_SESSION['role'] = $user['role'];
        $_SESSION['last_activity'] = time();
        
        // Log de l'activité
        logActivity($user['id'], 'Connexion', 'Connexion réussie');
        
        return true;
    }
    
    return false;
}

/**
 * Déconnecte l'utilisateur
 */
function logoutUser() {
    if (isset($_SESSION['user_id'])) {
        logActivity($_SESSION['user_id'], 'Déconnexion', 'Déconnexion');
    }
    
    session_unset();
    session_destroy();
}

/**
 * Génère un token CSRF
 */
function generateCSRFToken() {
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Vérifie le token CSRF
 */
function verifyCSRFToken($token) {
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

/**
 * Obtient les informations de l'utilisateur connecté
 */
function getCurrentUser() {
    if (!isLoggedIn()) {
        return null;
    }
    
    $db = getDB();
    $stmt = $db->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    return $stmt->fetch();
}
