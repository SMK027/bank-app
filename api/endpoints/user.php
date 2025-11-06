<?php
/**
 * Endpoints pour la gestion du profil utilisateur
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../middleware.php';
require_once __DIR__ . '/../../includes/db.php';

$method = $_SERVER['REQUEST_METHOD'];
$path = $_SERVER['PATH_INFO'] ?? '/';

// Authentification requise pour tous les endpoints
$currentUser = requireAuth();
checkRateLimit($currentUser['user_id'], 'user');

$db = getDB();

// Route: GET /api/user/profile - Profil de l'utilisateur
if ($method === 'GET' && $path === '/profile') {
    $stmt = $db->prepare("
        SELECT 
            id,
            username,
            email,
            nom,
            prenom,
            telephone,
            adresse,
            role,
            statut,
            date_creation,
            derniere_connexion
        FROM users
        WHERE id = ?
    ");
    $stmt->execute([$currentUser['user_id']]);
    $user = $stmt->fetch();
    
    if (!$user) {
        sendJsonError('Utilisateur non trouvé', 404);
    }
    
    // Récupérer les statistiques
    $stmt = $db->prepare("SELECT COUNT(*) as total FROM comptes WHERE user_id = ? AND statut = 'actif'");
    $stmt->execute([$currentUser['user_id']]);
    $nbComptes = $stmt->fetch()['total'];
    
    $stmt = $db->prepare("
        SELECT COUNT(*) as total 
        FROM operations o
        JOIN comptes c ON o.compte_id = c.id
        WHERE c.user_id = ?
    ");
    $stmt->execute([$currentUser['user_id']]);
    $nbOperations = $stmt->fetch()['total'];
    
    $user['statistiques'] = [
        'nombre_comptes' => $nbComptes,
        'nombre_operations' => $nbOperations
    ];
    
    sendJsonResponse([
        'success' => true,
        'data' => $user
    ]);
}

// Route: GET /api/user/discord - Informations de liaison Discord
if ($method === 'GET' && $path === '/discord') {
    $stmt = $db->prepare("
        SELECT 
            discord_id,
            discord_username,
            discord_discriminator,
            discord_avatar,
            linked_at,
            last_used,
            active
        FROM discord_links
        WHERE user_id = ? AND active = TRUE
    ");
    $stmt->execute([$currentUser['user_id']]);
    $discordLink = $stmt->fetch();
    
    if (!$discordLink) {
        sendJsonResponse([
            'success' => true,
            'data' => [
                'linked' => false,
                'message' => 'Aucun compte Discord lié'
            ]
        ]);
    }
    
    sendJsonResponse([
        'success' => true,
        'data' => [
            'linked' => true,
            'discord' => $discordLink
        ]
    ]);
}

// Route: DELETE /api/user/discord - Délier le compte Discord
if ($method === 'DELETE' && $path === '/discord') {
    // Vérifier qu'il y a un compte Discord lié
    $stmt = $db->prepare("SELECT id FROM discord_links WHERE user_id = ? AND active = TRUE");
    $stmt->execute([$currentUser['user_id']]);
    
    if (!$stmt->fetch()) {
        sendJsonError('Aucun compte Discord lié', 404);
    }
    
    // Désactiver la liaison
    $stmt = $db->prepare("UPDATE discord_links SET active = FALSE WHERE user_id = ?");
    $stmt->execute([$currentUser['user_id']]);
    
    // Logger l'activité
    $stmt = $db->prepare("
        INSERT INTO logs_activite (user_id, action, details, ip_address)
        VALUES (?, 'Déliaison Discord', 'Compte Discord délié', ?)
    ");
    $stmt->execute([$currentUser['user_id'], $_SERVER['REMOTE_ADDR']]);
    
    sendJsonResponse([
        'success' => true,
        'message' => 'Compte Discord délié avec succès'
    ]);
}

// Route: GET /api/user/stats - Statistiques de l'utilisateur
if ($method === 'GET' && $path === '/stats') {
    // Nombre de comptes
    $stmt = $db->prepare("SELECT COUNT(*) as total FROM comptes WHERE user_id = ? AND statut = 'actif'");
    $stmt->execute([$currentUser['user_id']]);
    $nbComptes = $stmt->fetch()['total'];
    
    // Solde total
    $stmt = $db->prepare("SELECT COALESCE(SUM(solde), 0) as total FROM comptes WHERE user_id = ? AND statut = 'actif'");
    $stmt->execute([$currentUser['user_id']]);
    $soldeTotal = $stmt->fetch()['total'];
    
    // Nombre d'opérations ce mois
    $stmt = $db->prepare("
        SELECT COUNT(*) as total 
        FROM operations o
        JOIN comptes c ON o.compte_id = c.id
        WHERE c.user_id = ? 
        AND MONTH(o.date_operation) = MONTH(CURRENT_DATE())
        AND YEAR(o.date_operation) = YEAR(CURRENT_DATE())
    ");
    $stmt->execute([$currentUser['user_id']]);
    $nbOperationsMois = $stmt->fetch()['total'];
    
    // Dépenses ce mois
    $stmt = $db->prepare("
        SELECT COALESCE(SUM(o.montant), 0) as total 
        FROM operations o
        JOIN comptes c ON o.compte_id = c.id
        WHERE c.user_id = ? 
        AND o.type_operation IN ('debit', 'retrait', 'prelevement', 'virement')
        AND MONTH(o.date_operation) = MONTH(CURRENT_DATE())
        AND YEAR(o.date_operation) = YEAR(CURRENT_DATE())
    ");
    $stmt->execute([$currentUser['user_id']]);
    $depensesMois = $stmt->fetch()['total'];
    
    // Revenus ce mois
    $stmt = $db->prepare("
        SELECT COALESCE(SUM(o.montant), 0) as total 
        FROM operations o
        JOIN comptes c ON o.compte_id = c.id
        WHERE c.user_id = ? 
        AND o.type_operation IN ('credit', 'depot')
        AND MONTH(o.date_operation) = MONTH(CURRENT_DATE())
        AND YEAR(o.date_operation) = YEAR(CURRENT_DATE())
    ");
    $stmt->execute([$currentUser['user_id']]);
    $revenusMois = $stmt->fetch()['total'];
    
    // Crédits actifs
    $stmt = $db->prepare("
        SELECT 
            COUNT(*) as nombre,
            COALESCE(SUM(montant_restant), 0) as total_restant
        FROM credits 
        WHERE user_id = ? AND statut = 'actif'
    ");
    $stmt->execute([$currentUser['user_id']]);
    $credits = $stmt->fetch();
    
    sendJsonResponse([
        'success' => true,
        'data' => [
            'comptes' => [
                'nombre' => $nbComptes,
                'solde_total' => $soldeTotal
            ],
            'operations_mois' => [
                'nombre' => $nbOperationsMois,
                'depenses' => $depensesMois,
                'revenus' => $revenusMois,
                'solde' => $revenusMois - $depensesMois
            ],
            'credits' => [
                'nombre' => $credits['nombre'],
                'montant_restant' => $credits['total_restant']
            ]
        ]
    ]);
}

// Route: GET /api/user/notifications - Notifications de l'utilisateur
if ($method === 'GET' && $path === '/notifications') {
    $limit = isset($_GET['limit']) ? min((int)$_GET['limit'], 50) : 10;
    
    $stmt = $db->prepare("
        SELECT 
            id,
            sujet,
            contenu,
            lu,
            date_envoi,
            type_message
        FROM messages
        WHERE destinataire_id = ?
        ORDER BY date_envoi DESC
        LIMIT ?
    ");
    $stmt->execute([$currentUser['user_id'], $limit]);
    $notifications = $stmt->fetchAll();
    
    // Compter les non lus
    $stmt = $db->prepare("SELECT COUNT(*) as total FROM messages WHERE destinataire_id = ? AND lu = FALSE");
    $stmt->execute([$currentUser['user_id']]);
    $nbNonLus = $stmt->fetch()['total'];
    
    sendJsonResponse([
        'success' => true,
        'data' => [
            'notifications' => $notifications,
            'non_lus' => $nbNonLus
        ]
    ]);
}

// Route non trouvée
sendJsonError('Route non trouvée', 404);
