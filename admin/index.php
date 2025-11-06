<?php
require_once '../config/config.php';
requireAdmin();

$stats = getStatistiquesGlobales();
$db = getDB();

// Récupération des alertes de négatif non résolues
$stmt = $db->query("
    SELECT a.*, c.numero_compte, u.nom, u.prenom 
    FROM alertes_negatif a 
    INNER JOIN comptes c ON a.compte_id = c.id 
    INNER JOIN users u ON c.user_id = u.id 
    WHERE a.resolu = 0 AND a.duree_jours >= " . ALERTE_NEGATIF_JOURS . "
    ORDER BY a.duree_jours DESC
");
$alertes = $stmt->fetchAll();

// Récupération des dernières activités
$stmt = $db->query("
    SELECT l.*, u.username, u.nom, u.prenom 
    FROM logs_activite l 
    INNER JOIN users u ON l.user_id = u.id 
    ORDER BY l.date_action DESC 
    LIMIT 20
");
$activites = $stmt->fetchAll();

$pageTitle = 'Tableau de bord administrateur';
include 'includes/header.php';
?>

<div class="dashboard admin-dashboard">
    <h1>Tableau de bord administrateur</h1>
    
    <?php
    $flash = getFlashMessage();
    if ($flash):
    ?>
        <div class="alert alert-<?php echo e($flash['type']); ?>">
            <?php echo e($flash['message']); ?>
        </div>
    <?php endif; ?>
    
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
            <h3>Opérations ce mois</h3>
            <p class="count"><?php echo $stats['operations_mois']; ?></p>
        </div>
        
        <div class="summary-card">
            <h3>Crédits actifs</h3>
            <p class="count"><?php echo $stats['credits_actifs']; ?></p>
        </div>
        
        <div class="summary-card">
            <h3>Montant crédits</h3>
            <p class="amount"><?php echo formatMontant($stats['montant_credits']); ?></p>
        </div>
        
        <div class="summary-card">
            <h3>Comptes négatifs</h3>
            <p class="count <?php echo $stats['comptes_negatifs'] > 0 ? 'negative' : ''; ?>">
                <?php echo $stats['comptes_negatifs']; ?>
            </p>
        </div>
        
        <div class="summary-card">
            <h3>Messages non lus</h3>
            <p class="count"><?php echo $stats['messages_non_lus']; ?></p>
        </div>
    </div>
    
    <?php if (!empty($alertes)): ?>
    <div class="alerts-section">
        <h2>Alertes de négatif prolongé</h2>
        <table class="table">
            <thead>
                <tr>
                    <th>Client</th>
                    <th>Compte</th>
                    <th>Montant</th>
                    <th>Durée (jours)</th>
                    <th>Date début</th>
                    <th>Alerte envoyée</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($alertes as $alerte): ?>
                <tr>
                    <td><?php echo e($alerte['prenom'] . ' ' . $alerte['nom']); ?></td>
                    <td><?php echo e($alerte['numero_compte']); ?></td>
                    <td class="negative"><?php echo formatMontant($alerte['montant']); ?></td>
                    <td><?php echo $alerte['duree_jours']; ?></td>
                    <td><?php echo formatDateTime($alerte['date_debut_negatif']); ?></td>
                    <td>
                        <?php if ($alerte['alerte_envoyee']): ?>
                            <span class="badge badge-success">Oui</span>
                        <?php else: ?>
                            <span class="badge badge-danger">Non</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
    
    <div class="activity-section">
        <h2>Activité récente</h2>
        <table class="table">
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Utilisateur</th>
                    <th>Action</th>
                    <th>Détails</th>
                    <th>IP</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($activites as $activite): ?>
                <tr>
                    <td><?php echo formatDateTime($activite['date_action']); ?></td>
                    <td><?php echo e($activite['username']); ?></td>
                    <td><?php echo e($activite['action']); ?></td>
                    <td><?php echo e($activite['details'] ?? '-'); ?></td>
                    <td><?php echo e($activite['ip_address'] ?? '-'); ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php include 'includes/footer.php'; ?>
