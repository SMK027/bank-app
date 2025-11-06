<?php
/**
 * Fonctions utilitaires de l'application
 */

/**
 * Formate un montant en euros
 */
function formatMontant($montant) {
    return number_format($montant, 2, ',', ' ') . ' €';
}

/**
 * Formate une date
 */
function formatDate($date, $format = 'd/m/Y') {
    if (empty($date)) return '-';
    $timestamp = is_numeric($date) ? $date : strtotime($date);
    return date($format, $timestamp);
}

/**
 * Formate une date avec heure
 */
function formatDateTime($date) {
    return formatDate($date, 'd/m/Y H:i');
}

/**
 * Échappe les caractères HTML
 */
function e($string) {
    return htmlspecialchars($string, ENT_QUOTES, 'UTF-8');
}

/**
 * Redirige vers une URL
 */
function redirect($url) {
    header('Location: ' . $url);
    exit();
}

/**
 * Affiche un message flash
 */
function setFlashMessage($message, $type = 'info') {
    $_SESSION['flash_message'] = $message;
    $_SESSION['flash_type'] = $type;
}

/**
 * Récupère et supprime le message flash
 */
function getFlashMessage() {
    if (isset($_SESSION['flash_message'])) {
        $message = $_SESSION['flash_message'];
        $type = $_SESSION['flash_type'] ?? 'info';
        unset($_SESSION['flash_message'], $_SESSION['flash_type']);
        return ['message' => $message, 'type' => $type];
    }
    return null;
}

/**
 * Génère un numéro de compte unique
 */
function generateNumeroCompte() {
    $prefix = 'FR76';
    $number = '';
    for ($i = 0; $i < 23; $i++) {
        $number .= rand(0, 9);
    }
    return $prefix . $number;
}

/**
 * Enregistre une activité dans les logs
 */
function logActivity($userId, $action, $details = null) {
    try {
        $db = getDB();
        $ipAddress = $_SERVER['REMOTE_ADDR'] ?? null;
        
        $stmt = $db->prepare("INSERT INTO logs_activite (user_id, action, details, ip_address) VALUES (?, ?, ?, ?)");
        $stmt->execute([$userId, $action, $details, $ipAddress]);
    } catch (Exception $e) {
        // Échec silencieux pour ne pas bloquer l'application
        error_log("Erreur lors de l'enregistrement du log : " . $e->getMessage());
    }
}

/**
 * Envoie un message interne
 */
function envoyerMessage($expediteurId, $destinataireId, $sujet, $contenu, $type = 'normal') {
    $db = getDB();
    
    $stmt = $db->prepare("INSERT INTO messages (expediteur_id, destinataire_id, sujet, contenu, type_message) VALUES (?, ?, ?, ?, ?)");
    return $stmt->execute([$expediteurId, $destinataireId, $sujet, $contenu, $type]);
}

/**
 * Compte les messages non lus
 */
function compterMessagesNonLus($userId) {
    $db = getDB();
    
    $stmt = $db->prepare("SELECT COUNT(*) FROM messages WHERE destinataire_id = ? AND lu = 0");
    $stmt->execute([$userId]);
    return $stmt->fetchColumn();
}

/**
 * Récupère les comptes d'un utilisateur (propriétaire + procurations)
 */
function getComptesUtilisateur($userId) {
    $db = getDB();
    
    // Comptes dont l'utilisateur est propriétaire
    $stmt = $db->prepare("
        SELECT c.*, 'proprietaire' as type_acces 
        FROM comptes c 
        WHERE c.user_id = ? AND c.statut = 'actif'
    ");
    $stmt->execute([$userId]);
    $comptesProprietaire = $stmt->fetchAll();
    
    // Comptes avec procuration
    $stmt = $db->prepare("
        SELECT c.*, 'procuration' as type_acces, u.nom, u.prenom 
        FROM comptes c 
        INNER JOIN procurations p ON c.id = p.compte_id 
        INNER JOIN users u ON c.user_id = u.id
        WHERE p.user_beneficiaire_id = ? 
        AND p.statut = 'active' 
        AND c.statut = 'actif'
        AND (p.date_fin IS NULL OR p.date_fin >= CURDATE())
    ");
    $stmt->execute([$userId]);
    $comptesProcuration = $stmt->fetchAll();
    
    return array_merge($comptesProprietaire, $comptesProcuration);
}

/**
 * Enregistre une opération bancaire
 */
function enregistrerOperation($compteId, $typeOperation, $montant, $destinataire = null, $nature = null, $description = null) {
    $db = getDB();
    
    try {
        $db->beginTransaction();
        
        // Récupération du solde actuel
        $stmt = $db->prepare("SELECT solde FROM comptes WHERE id = ? FOR UPDATE");
        $stmt->execute([$compteId]);
        $compte = $stmt->fetch();
        
        if (!$compte) {
            throw new Exception("Compte introuvable");
        }
        
        // Calcul du nouveau solde
        $nouveauSolde = $compte['solde'];
        if (in_array($typeOperation, ['debit', 'retrait', 'prelevement'])) {
            $nouveauSolde -= $montant;
        } else {
            $nouveauSolde += $montant;
        }
        
        // Mise à jour du solde
        $stmt = $db->prepare("UPDATE comptes SET solde = ? WHERE id = ?");
        $stmt->execute([$nouveauSolde, $compteId]);
        
        // Enregistrement de l'opération
        $stmt = $db->prepare("
            INSERT INTO operations (compte_id, type_operation, montant, destinataire, nature, description, solde_apres) 
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([$compteId, $typeOperation, $montant, $destinataire, $nature, $description, $nouveauSolde]);
        
        // Vérification du négatif
        verifierNegatif($compteId);
        
        $db->commit();
        return true;
    } catch (Exception $e) {
        $db->rollBack();
        error_log("Erreur lors de l'enregistrement de l'opération : " . $e->getMessage());
        return false;
    }
}

/**
 * Vérifie si un compte est en négatif et crée une alerte si nécessaire
 */
function verifierNegatif($compteId) {
    $db = getDB();
    
    $stmt = $db->prepare("SELECT c.*, u.id as user_id FROM comptes c INNER JOIN users u ON c.user_id = u.id WHERE c.id = ?");
    $stmt->execute([$compteId]);
    $compte = $stmt->fetch();
    
    if (!$compte) return;
    
    $solde = $compte['solde'];
    $negatifAutorise = -abs($compte['negatif_autorise']);
    
    // Si le solde est inférieur au négatif autorisé
    if ($solde < $negatifAutorise) {
        // Vérifier s'il existe déjà une alerte non résolue
        $stmt = $db->prepare("SELECT * FROM alertes_negatif WHERE compte_id = ? AND resolu = 0 ORDER BY date_debut_negatif DESC LIMIT 1");
        $stmt->execute([$compteId]);
        $alerte = $stmt->fetch();
        
        if (!$alerte) {
            // Créer une nouvelle alerte
            $stmt = $db->prepare("INSERT INTO alertes_negatif (compte_id, date_debut_negatif, montant) VALUES (?, NOW(), ?)");
            $stmt->execute([$compteId, $solde]);
        } else {
            // Mettre à jour la durée
            $dureeJours = floor((time() - strtotime($alerte['date_debut_negatif'])) / 86400);
            $stmt = $db->prepare("UPDATE alertes_negatif SET duree_jours = ?, montant = ? WHERE id = ?");
            $stmt->execute([$dureeJours, $solde, $alerte['id']]);
            
            // Si la durée dépasse le seuil et qu'aucune alerte n'a été envoyée
            if ($dureeJours >= ALERTE_NEGATIF_JOURS && !$alerte['alerte_envoyee']) {
                // Envoyer un message d'alerte
                $sujet = "Alerte : Solde négatif prolongé";
                $contenu = "Votre compte " . $compte['numero_compte'] . " est en négatif depuis " . $dureeJours . " jours.\n";
                $contenu .= "Solde actuel : " . formatMontant($solde) . "\n";
                $contenu .= "Négatif autorisé : " . formatMontant($negatifAutorise) . "\n";
                $contenu .= "Veuillez régulariser votre situation dans les plus brefs délais.";
                
                envoyerMessage(1, $compte['user_id'], $sujet, $contenu, 'alerte');
                
                // Marquer l'alerte comme envoyée
                $stmt = $db->prepare("UPDATE alertes_negatif SET alerte_envoyee = 1, date_alerte = NOW() WHERE id = ?");
                $stmt->execute([$alerte['id']]);
            }
        }
    } else {
        // Résoudre les alertes si le solde est redevenu positif ou dans le négatif autorisé
        $stmt = $db->prepare("UPDATE alertes_negatif SET resolu = 1, date_resolution = NOW() WHERE compte_id = ? AND resolu = 0");
        $stmt->execute([$compteId]);
    }
}

/**
 * Envoie une notification RGPD pour modification de compte
 */
function envoyerNotificationRGPD($userId, $typeModification, $details) {
    $sujet = "Notification : Modification de votre compte";
    $contenu = "Conformément au RGPD, nous vous informons qu'une modification a été effectuée sur votre compte.\n\n";
    $contenu .= "Type de modification : " . $typeModification . "\n";
    $contenu .= "Détails : " . $details . "\n";
    $contenu .= "Date : " . date('d/m/Y H:i') . "\n\n";
    $contenu .= "Si vous n'êtes pas à l'origine de cette modification, veuillez contacter immédiatement l'administration.";
    
    envoyerMessage(1, $userId, $sujet, $contenu, 'notification');
}

/**
 * Valide un montant
 */
function validerMontant($montant) {
    return is_numeric($montant) && $montant > 0;
}

/**
 * Calcule le solde total de tous les comptes d'un utilisateur
 */
function calculerSoldeTotal($userId) {
    $comptes = getComptesUtilisateur($userId);
    $total = 0;
    foreach ($comptes as $compte) {
        if ($compte['type_acces'] === 'proprietaire') {
            $total += $compte['solde'];
        }
    }
    return $total;
}

/**
 * Récupère les statistiques globales (pour admin)
 */
function getStatistiquesGlobales() {
    $db = getDB();
    
    $stats = [];
    
    // Nombre total de clients
    $stmt = $db->query("SELECT COUNT(*) FROM users WHERE role = 'client'");
    $stats['total_clients'] = $stmt->fetchColumn();
    
    // Nombre total de comptes
    $stmt = $db->query("SELECT COUNT(*) FROM comptes WHERE statut = 'actif'");
    $stats['total_comptes'] = $stmt->fetchColumn();
    
    // Solde total de tous les comptes
    $stmt = $db->query("SELECT SUM(solde) FROM comptes WHERE statut = 'actif'");
    $stats['solde_total'] = $stmt->fetchColumn() ?? 0;
    
    // Nombre d'opérations ce mois
    $stmt = $db->query("SELECT COUNT(*) FROM operations WHERE MONTH(date_operation) = MONTH(CURDATE()) AND YEAR(date_operation) = YEAR(CURDATE())");
    $stats['operations_mois'] = $stmt->fetchColumn();
    
    // Nombre de crédits actifs
    $stmt = $db->query("SELECT COUNT(*) FROM credits WHERE statut = 'actif'");
    $stats['credits_actifs'] = $stmt->fetchColumn();
    
    // Montant total des crédits en cours
    $stmt = $db->query("SELECT SUM(montant_restant) FROM credits WHERE statut = 'actif'");
    $stats['montant_credits'] = $stmt->fetchColumn() ?? 0;
    
    // Nombre de comptes en négatif
    $stmt = $db->query("SELECT COUNT(*) FROM comptes WHERE solde < 0 AND statut = 'actif'");
    $stats['comptes_negatifs'] = $stmt->fetchColumn();
    
    // Messages non lus par l'admin
    $stmt = $db->query("SELECT COUNT(*) FROM messages WHERE destinataire_id = 1 AND lu = 0");
    $stats['messages_non_lus'] = $stmt->fetchColumn();
    
    return $stats;
}
