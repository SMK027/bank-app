<?php
/**
 * Endpoints pour la gestion des comptes bancaires
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../middleware.php';
require_once __DIR__ . '/../../includes/db.php';

$method = $_SERVER['REQUEST_METHOD'];
$path = $_SERVER['PATH_INFO'] ?? '/';

// Authentification requise pour tous les endpoints
$currentUser = requireAuth();
checkRateLimit($currentUser['user_id'], 'accounts');

$db = getDB();

// Route: GET /api/accounts - Liste des comptes de l'utilisateur
if ($method === 'GET' && $path === '/') {
    // Récupérer les comptes dont l'utilisateur est propriétaire
    $stmt = $db->prepare("
        SELECT 
            c.id,
            c.numero_compte,
            c.type_compte,
            c.solde,
            c.negatif_autorise,
            c.statut,
            c.date_creation,
            'proprietaire' as relation
        FROM comptes c
        WHERE c.user_id = ? AND c.statut = 'actif'
        
        UNION
        
        SELECT 
            c.id,
            c.numero_compte,
            c.type_compte,
            c.solde,
            c.negatif_autorise,
            c.statut,
            c.date_creation,
            'procuration' as relation
        FROM comptes c
        JOIN procurations p ON c.id = p.compte_id
        WHERE p.user_beneficiaire_id = ? 
        AND p.statut = 'active' 
        AND c.statut = 'actif'
        AND (p.date_fin IS NULL OR p.date_fin >= CURDATE())
        
        ORDER BY date_creation DESC
    ");
    $stmt->execute([$currentUser['user_id'], $currentUser['user_id']]);
    $comptes = $stmt->fetchAll();
    
    // Calculer le solde total
    $soldeTotal = array_sum(array_column($comptes, 'solde'));
    
    sendJsonResponse([
        'success' => true,
        'data' => [
            'comptes' => $comptes,
            'solde_total' => $soldeTotal,
            'nombre_comptes' => count($comptes)
        ]
    ]);
}

// Route: GET /api/accounts/{id} - Détails d'un compte spécifique
if ($method === 'GET' && preg_match('#^/(\d+)$#', $path, $matches)) {
    $compteId = (int)$matches[1];
    
    // Vérifier que l'utilisateur a accès à ce compte
    $stmt = $db->prepare("
        SELECT 
            c.id,
            c.numero_compte,
            c.type_compte,
            c.solde,
            c.negatif_autorise,
            c.statut,
            c.date_creation,
            c.date_modification,
            u.nom,
            u.prenom,
            CASE 
                WHEN c.user_id = ? THEN 'proprietaire'
                ELSE 'procuration'
            END as relation
        FROM comptes c
        JOIN users u ON c.user_id = u.id
        WHERE c.id = ? AND c.statut = 'actif'
        AND (
            c.user_id = ?
            OR EXISTS (
                SELECT 1 FROM procurations p 
                WHERE p.compte_id = c.id 
                AND p.user_beneficiaire_id = ? 
                AND p.statut = 'active'
                AND (p.date_fin IS NULL OR p.date_fin >= CURDATE())
            )
        )
    ");
    $stmt->execute([$currentUser['user_id'], $compteId, $currentUser['user_id'], $currentUser['user_id']]);
    $compte = $stmt->fetch();
    
    if (!$compte) {
        sendJsonError('Compte non trouvé ou accès refusé', 404);
    }
    
    // Récupérer les dernières opérations
    $stmt = $db->prepare("
        SELECT 
            id,
            type_operation,
            montant,
            destinataire,
            nature,
            description,
            date_operation,
            solde_apres
        FROM operations
        WHERE compte_id = ?
        ORDER BY date_operation DESC
        LIMIT 10
    ");
    $stmt->execute([$compteId]);
    $dernieresOperations = $stmt->fetchAll();
    
    $compte['dernieres_operations'] = $dernieresOperations;
    
    sendJsonResponse([
        'success' => true,
        'data' => $compte
    ]);
}

// Route: GET /api/accounts/{id}/balance - Solde d'un compte
if ($method === 'GET' && preg_match('#^/(\d+)/balance$#', $path, $matches)) {
    $compteId = (int)$matches[1];
    
    // Vérifier que l'utilisateur a accès à ce compte
    $stmt = $db->prepare("
        SELECT 
            c.id,
            c.numero_compte,
            c.solde,
            c.negatif_autorise,
            c.type_compte
        FROM comptes c
        WHERE c.id = ? AND c.statut = 'actif'
        AND (
            c.user_id = ?
            OR EXISTS (
                SELECT 1 FROM procurations p 
                WHERE p.compte_id = c.id 
                AND p.user_beneficiaire_id = ? 
                AND p.statut = 'active'
                AND (p.date_fin IS NULL OR p.date_fin >= CURDATE())
            )
        )
    ");
    $stmt->execute([$compteId, $currentUser['user_id'], $currentUser['user_id']]);
    $compte = $stmt->fetch();
    
    if (!$compte) {
        sendJsonError('Compte non trouvé ou accès refusé', 404);
    }
    
    $disponible = $compte['solde'] + $compte['negatif_autorise'];
    $enNegatif = $compte['solde'] < 0;
    
    sendJsonResponse([
        'success' => true,
        'data' => [
            'compte_id' => $compte['id'],
            'numero_compte' => $compte['numero_compte'],
            'type_compte' => $compte['type_compte'],
            'solde' => $compte['solde'],
            'negatif_autorise' => $compte['negatif_autorise'],
            'disponible' => $disponible,
            'en_negatif' => $enNegatif
        ]
    ]);
}

// Route: GET /api/accounts/{id}/operations - Opérations d'un compte
if ($method === 'GET' && preg_match('#^/(\d+)/operations$#', $path, $matches)) {
    $compteId = (int)$matches[1];
    
    // Paramètres de pagination et filtrage
    $limit = isset($_GET['limit']) ? min((int)$_GET['limit'], 100) : 20;
    $offset = isset($_GET['offset']) ? (int)$_GET['offset'] : 0;
    $type = $_GET['type'] ?? null;
    $dateDebut = $_GET['date_debut'] ?? null;
    $dateFin = $_GET['date_fin'] ?? null;
    
    // Vérifier que l'utilisateur a accès à ce compte
    $stmt = $db->prepare("
        SELECT id FROM comptes c
        WHERE c.id = ? AND c.statut = 'actif'
        AND (
            c.user_id = ?
            OR EXISTS (
                SELECT 1 FROM procurations p 
                WHERE p.compte_id = c.id 
                AND p.user_beneficiaire_id = ? 
                AND p.statut = 'active'
                AND (p.date_fin IS NULL OR p.date_fin >= CURDATE())
            )
        )
    ");
    $stmt->execute([$compteId, $currentUser['user_id'], $currentUser['user_id']]);
    
    if (!$stmt->fetch()) {
        sendJsonError('Compte non trouvé ou accès refusé', 404);
    }
    
    // Construire la requête avec filtres
    $sql = "SELECT 
                id,
                type_operation,
                montant,
                destinataire,
                nature,
                description,
                date_operation,
                solde_apres
            FROM operations
            WHERE compte_id = ?";
    
    $params = [$compteId];
    
    if ($type) {
        $sql .= " AND type_operation = ?";
        $params[] = $type;
    }
    
    if ($dateDebut) {
        $sql .= " AND date_operation >= ?";
        $params[] = $dateDebut;
    }
    
    if ($dateFin) {
        $sql .= " AND date_operation <= ?";
        $params[] = $dateFin;
    }
    
    $sql .= " ORDER BY date_operation DESC LIMIT ? OFFSET ?";
    $params[] = $limit;
    $params[] = $offset;
    
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $operations = $stmt->fetchAll();
    
    // Compter le total
    $sqlCount = "SELECT COUNT(*) as total FROM operations WHERE compte_id = ?";
    $paramsCount = [$compteId];
    
    if ($type) {
        $sqlCount .= " AND type_operation = ?";
        $paramsCount[] = $type;
    }
    if ($dateDebut) {
        $sqlCount .= " AND date_operation >= ?";
        $paramsCount[] = $dateDebut;
    }
    if ($dateFin) {
        $sqlCount .= " AND date_operation <= ?";
        $paramsCount[] = $dateFin;
    }
    
    $stmt = $db->prepare($sqlCount);
    $stmt->execute($paramsCount);
    $total = $stmt->fetch()['total'];
    
    sendJsonResponse([
        'success' => true,
        'data' => [
            'operations' => $operations,
            'pagination' => [
                'total' => $total,
                'limit' => $limit,
                'offset' => $offset,
                'has_more' => ($offset + $limit) < $total
            ]
        ]
    ]);
}

// Route non trouvée
sendJsonError('Route non trouvée', 404);
