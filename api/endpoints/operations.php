<?php
/**
 * Endpoints pour la gestion des opérations bancaires
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../middleware.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/functions.php';

$method = $_SERVER['REQUEST_METHOD'];
$path = $_SERVER['PATH_INFO'] ?? '/';

// Authentification requise pour tous les endpoints
$currentUser = requireAuth();
checkRateLimit($currentUser['user_id'], 'operations');

$db = getDB();

// Route: POST /api/operations - Créer une nouvelle opération
if ($method === 'POST' && $path === '/') {
    $data = getJsonInput();
    
    validateInput($data, [
        'compte_id' => ['required' => true, 'type' => 'int'],
        'type_operation' => ['required' => true, 'enum' => ['debit', 'credit', 'virement', 'prelevement', 'depot', 'retrait']],
        'montant' => ['required' => true, 'type' => 'float', 'min_value' => 0.01],
        'destinataire' => ['type' => 'string', 'max' => 255],
        'nature' => ['type' => 'string', 'max' => 100],
        'description' => ['type' => 'string', 'max' => 1000]
    ]);
    
    $compteId = (int)$data['compte_id'];
    $typeOperation = $data['type_operation'];
    $montant = (float)$data['montant'];
    $destinataire = $data['destinataire'] ?? null;
    $nature = $data['nature'] ?? null;
    $description = $data['description'] ?? null;
    
    // Vérifier que l'utilisateur a accès à ce compte
    $stmt = $db->prepare("
        SELECT 
            c.id,
            c.numero_compte,
            c.solde,
            c.negatif_autorise,
            c.user_id,
            CASE 
                WHEN c.user_id = ? THEN 'proprietaire'
                ELSE 'procuration'
            END as relation
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
    $stmt->execute([$currentUser['user_id'], $compteId, $currentUser['user_id'], $currentUser['user_id']]);
    $compte = $stmt->fetch();
    
    if (!$compte) {
        sendJsonError('Compte non trouvé ou accès refusé', 404);
    }
    
    // Calculer le nouveau solde
    $nouveauSolde = $compte['solde'];
    
    if (in_array($typeOperation, ['debit', 'retrait', 'prelevement'])) {
        $nouveauSolde -= $montant;
    } else if (in_array($typeOperation, ['credit', 'depot'])) {
        $nouveauSolde += $montant;
    } else if ($typeOperation === 'virement') {
        // Pour un virement, on considère que c'est un débit du compte
        $nouveauSolde -= $montant;
    }
    
    // Vérifier le découvert autorisé
    $decouvertMax = -$compte['negatif_autorise'];
    if ($nouveauSolde < $decouvertMax) {
        sendJsonError(
            'Opération refusée : découvert autorisé dépassé',
            400,
            [
                'solde_actuel' => $compte['solde'],
                'nouveau_solde' => $nouveauSolde,
                'decouvert_autorise' => $compte['negatif_autorise'],
                'solde_minimum' => $decouvertMax
            ]
        );
    }
    
    // Démarrer une transaction
    $db->beginTransaction();
    
    try {
        // Insérer l'opération
        $stmt = $db->prepare("
            INSERT INTO operations (compte_id, type_operation, montant, destinataire, nature, description, solde_apres)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $compteId,
            $typeOperation,
            $montant,
            $destinataire,
            $nature,
            $description,
            $nouveauSolde
        ]);
        
        $operationId = $db->lastInsertId();
        
        // Mettre à jour le solde du compte
        $stmt = $db->prepare("UPDATE comptes SET solde = ?, date_modification = NOW() WHERE id = ?");
        $stmt->execute([$nouveauSolde, $compteId]);
        
        // Logger l'activité
        $stmt = $db->prepare("
            INSERT INTO logs_activite (user_id, action, details, ip_address)
            VALUES (?, 'Opération bancaire', ?, ?)
        ");
        $stmt->execute([
            $currentUser['user_id'],
            "Opération $typeOperation de $montant € sur le compte {$compte['numero_compte']}",
            $_SERVER['REMOTE_ADDR']
        ]);
        
        // Vérifier si le compte est en négatif
        if ($nouveauSolde < 0) {
            // Vérifier s'il y a déjà une alerte active
            $stmt = $db->prepare("
                SELECT id FROM alertes_negatif 
                WHERE compte_id = ? AND resolu = FALSE
            ");
            $stmt->execute([$compteId]);
            
            if (!$stmt->fetch()) {
                // Créer une alerte
                $stmt = $db->prepare("
                    INSERT INTO alertes_negatif (compte_id, date_debut_negatif, montant)
                    VALUES (?, NOW(), ?)
                ");
                $stmt->execute([$compteId, abs($nouveauSolde)]);
            } else {
                // Mettre à jour l'alerte existante
                $stmt = $db->prepare("
                    UPDATE alertes_negatif 
                    SET montant = ?, duree_jours = DATEDIFF(NOW(), date_debut_negatif)
                    WHERE compte_id = ? AND resolu = FALSE
                ");
                $stmt->execute([abs($nouveauSolde), $compteId]);
            }
        } else {
            // Résoudre les alertes si le solde est redevenu positif
            $stmt = $db->prepare("
                UPDATE alertes_negatif 
                SET resolu = TRUE, date_resolution = NOW()
                WHERE compte_id = ? AND resolu = FALSE
            ");
            $stmt->execute([$compteId]);
        }
        
        $db->commit();
        
        // Récupérer l'opération créée
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
            WHERE id = ?
        ");
        $stmt->execute([$operationId]);
        $operation = $stmt->fetch();
        
        sendJsonResponse([
            'success' => true,
            'message' => 'Opération enregistrée avec succès',
            'data' => [
                'operation' => $operation,
                'ancien_solde' => $compte['solde'],
                'nouveau_solde' => $nouveauSolde
            ]
        ], 201);
        
    } catch (Exception $e) {
        $db->rollBack();
        sendJsonError('Erreur lors de l\'enregistrement de l\'opération: ' . $e->getMessage(), 500);
    }
}

// Route: GET /api/operations/{id} - Détails d'une opération
if ($method === 'GET' && preg_match('#^/(\d+)$#', $path, $matches)) {
    $operationId = (int)$matches[1];
    
    // Récupérer l'opération et vérifier l'accès
    $stmt = $db->prepare("
        SELECT 
            o.id,
            o.compte_id,
            o.type_operation,
            o.montant,
            o.destinataire,
            o.nature,
            o.description,
            o.date_operation,
            o.solde_apres,
            c.numero_compte,
            c.type_compte
        FROM operations o
        JOIN comptes c ON o.compte_id = c.id
        WHERE o.id = ?
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
    $stmt->execute([$operationId, $currentUser['user_id'], $currentUser['user_id']]);
    $operation = $stmt->fetch();
    
    if (!$operation) {
        sendJsonError('Opération non trouvée ou accès refusé', 404);
    }
    
    sendJsonResponse([
        'success' => true,
        'data' => $operation
    ]);
}

// Route: GET /api/operations/search - Rechercher des opérations
if ($method === 'GET' && $path === '/search') {
    $limit = isset($_GET['limit']) ? min((int)$_GET['limit'], 100) : 20;
    $offset = isset($_GET['offset']) ? (int)$_GET['offset'] : 0;
    $compteId = $_GET['compte_id'] ?? null;
    $type = $_GET['type'] ?? null;
    $nature = $_GET['nature'] ?? null;
    $destinataire = $_GET['destinataire'] ?? null;
    $montantMin = $_GET['montant_min'] ?? null;
    $montantMax = $_GET['montant_max'] ?? null;
    $dateDebut = $_GET['date_debut'] ?? null;
    $dateFin = $_GET['date_fin'] ?? null;
    
    // Construire la requête
    $sql = "SELECT 
                o.id,
                o.compte_id,
                o.type_operation,
                o.montant,
                o.destinataire,
                o.nature,
                o.description,
                o.date_operation,
                o.solde_apres,
                c.numero_compte
            FROM operations o
            JOIN comptes c ON o.compte_id = c.id
            WHERE (
                c.user_id = ?
                OR EXISTS (
                    SELECT 1 FROM procurations p 
                    WHERE p.compte_id = c.id 
                    AND p.user_beneficiaire_id = ? 
                    AND p.statut = 'active'
                    AND (p.date_fin IS NULL OR p.date_fin >= CURDATE())
                )
            )";
    
    $params = [$currentUser['user_id'], $currentUser['user_id']];
    
    if ($compteId) {
        $sql .= " AND o.compte_id = ?";
        $params[] = $compteId;
    }
    
    if ($type) {
        $sql .= " AND o.type_operation = ?";
        $params[] = $type;
    }
    
    if ($nature) {
        $sql .= " AND o.nature LIKE ?";
        $params[] = "%$nature%";
    }
    
    if ($destinataire) {
        $sql .= " AND o.destinataire LIKE ?";
        $params[] = "%$destinataire%";
    }
    
    if ($montantMin) {
        $sql .= " AND o.montant >= ?";
        $params[] = $montantMin;
    }
    
    if ($montantMax) {
        $sql .= " AND o.montant <= ?";
        $params[] = $montantMax;
    }
    
    if ($dateDebut) {
        $sql .= " AND o.date_operation >= ?";
        $params[] = $dateDebut;
    }
    
    if ($dateFin) {
        $sql .= " AND o.date_operation <= ?";
        $params[] = $dateFin;
    }
    
    $sql .= " ORDER BY o.date_operation DESC LIMIT ? OFFSET ?";
    $params[] = $limit;
    $params[] = $offset;
    
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $operations = $stmt->fetchAll();
    
    sendJsonResponse([
        'success' => true,
        'data' => [
            'operations' => $operations,
            'count' => count($operations)
        ]
    ]);
}

// Route non trouvée
sendJsonError('Route non trouvée', 404);
