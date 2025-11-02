<?php
require_once '../config/config.php';
requireAdmin();

$db = getDB();
$stats = getStatistiquesGlobales();

// Statistiques par type de compte
$stmt = $db->query("
    SELECT type_compte, COUNT(*) as nombre, SUM(solde) as solde_total 
    FROM comptes 
    WHERE statut = 'actif' 
    GROUP BY type_compte
");
$statsParType = $stmt->fetchAll();

// Statistiques des opérations par mois (12 derniers mois)
$stmt = $db->query("
    SELECT 
        DATE_FORMAT(date_operation, '%Y-%m') as mois,
        COUNT(*) as nombre_operations,
        SUM(CASE WHEN type_operation IN ('credit', 'depot', 'virement') THEN montant ELSE 0 END) as total_credits,
        SUM(CASE WHEN type_operation IN ('debit', 'retrait', 'prelevement') THEN montant ELSE 0 END) as total_debits
    FROM operations 
    WHERE date_operation >= DATE_SUB(NOW(), INTERVAL 12 MONTH)
    GROUP BY DATE_FORMAT(date_operation, '%Y-%m')
    ORDER BY mois DESC
");
$statsOperations = $stmt->fetchAll();

// Top 10 clients par solde total
$stmt = $db->query("
    SELECT u.nom, u.prenom, u.username, SUM(c.solde) as solde_total, COUNT(c.id) as nombre_comptes
    FROM users u
    INNER JOIN comptes c ON u.id = c.user_id
    WHERE u.role = 'client' AND c.statut = 'actif'
    GROUP BY u.id
    ORDER BY solde_total DESC
    LIMIT 10
");
$topClients = $stmt->fetchAll();

// Statistiques des crédits
$stmt = $db->query("
    SELECT 
        COUNT(*) as nombre_credits,
        SUM(montant_total) as montant_total,
        SUM(montant_restant) as montant_restant,
        AVG(taux_interet) as taux_moyen
    FROM credits 
    WHERE statut = 'actif'
");
$statsCredits = $stmt->fetch();

$pageTitle = 'Statistiques';
include 'includes/header.php';
?>

<div class="page-content">
    <h1>Statistiques globales</h1>
    
    <div class="stats-overview">
        <h2>Vue d'ensemble</h2>
        <div class="dashboard-summary">
            <div class="summary-card">
                <h3>Total clients</h3>
                <p class="count"><?php echo $stats['total_clients']; ?></p>
            </div>
            
            <div class="summary-card">
                <h3>Total comptes</h3>
                <p class="count"><?php echo $stats['total_comptes']; ?></p>
            </div>
            
            <div class="summary-card">
                <h3>Solde total</h3>
                <p class="amount <?php echo $stats['solde_total'] < 0 ? 'negative' : 'positive'; ?>">
                    <?php echo formatMontant($stats['solde_total']); ?>
                </p>
            </div>
            
            <div class="summary-card">
                <h3>Comptes négatifs</h3>
                <p class="count <?php echo $stats['comptes_negatifs'] > 0 ? 'negative' : ''; ?>">
                    <?php echo $stats['comptes_negatifs']; ?>
                </p>
            </div>
        </div>
    </div>
    
    <div class="stats-section">
        <h2>Répartition par type de compte</h2>
        <table class="table">
            <thead>
                <tr>
                    <th>Type de compte</th>
                    <th>Nombre</th>
                    <th>Solde total</th>
                    <th>Solde moyen</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($statsParType as $stat): ?>
                <tr>
                    <td><?php echo e(ucfirst($stat['type_compte'])); ?></td>
                    <td><?php echo $stat['nombre']; ?></td>
                    <td><?php echo formatMontant($stat['solde_total']); ?></td>
                    <td><?php echo formatMontant($stat['solde_total'] / $stat['nombre']); ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    
    <div class="stats-section">
        <h2>Opérations des 12 derniers mois</h2>
        <table class="table">
            <thead>
                <tr>
                    <th>Mois</th>
                    <th>Nombre d'opérations</th>
                    <th>Total crédits</th>
                    <th>Total débits</th>
                    <th>Solde net</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($statsOperations as $stat): ?>
                <tr>
                    <td><?php echo e($stat['mois']); ?></td>
                    <td><?php echo $stat['nombre_operations']; ?></td>
                    <td class="positive"><?php echo formatMontant($stat['total_credits']); ?></td>
                    <td class="negative"><?php echo formatMontant($stat['total_debits']); ?></td>
                    <td class="<?php echo ($stat['total_credits'] - $stat['total_debits']) >= 0 ? 'positive' : 'negative'; ?>">
                        <?php echo formatMontant($stat['total_credits'] - $stat['total_debits']); ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    
    <div class="stats-section">
        <h2>Top 10 clients par solde</h2>
        <table class="table">
            <thead>
                <tr>
                    <th>Rang</th>
                    <th>Client</th>
                    <th>Nom d'utilisateur</th>
                    <th>Nombre de comptes</th>
                    <th>Solde total</th>
                </tr>
            </thead>
            <tbody>
                <?php $rang = 1; foreach ($topClients as $client): ?>
                <tr>
                    <td><?php echo $rang++; ?></td>
                    <td><?php echo e($client['prenom'] . ' ' . $client['nom']); ?></td>
                    <td><?php echo e($client['username']); ?></td>
                    <td><?php echo $client['nombre_comptes']; ?></td>
                    <td class="<?php echo $client['solde_total'] < 0 ? 'negative' : 'positive'; ?>">
                        <?php echo formatMontant($client['solde_total']); ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    
    <div class="stats-section">
        <h2>Statistiques des crédits</h2>
        <div class="dashboard-summary">
            <div class="summary-card">
                <h3>Crédits actifs</h3>
                <p class="count"><?php echo $statsCredits['nombre_credits'] ?? 0; ?></p>
            </div>
            
            <div class="summary-card">
                <h3>Montant total prêté</h3>
                <p class="amount"><?php echo formatMontant($statsCredits['montant_total'] ?? 0); ?></p>
            </div>
            
            <div class="summary-card">
                <h3>Montant restant dû</h3>
                <p class="amount"><?php echo formatMontant($statsCredits['montant_restant'] ?? 0); ?></p>
            </div>
            
            <div class="summary-card">
                <h3>Taux moyen</h3>
                <p class="count"><?php echo number_format($statsCredits['taux_moyen'] ?? 0, 2); ?>%</p>
            </div>
        </div>
    </div>
</div>

<?php include 'includes/footer.php'; ?>
