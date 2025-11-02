<?php
require_once '../config/config.php';
requireClient();

$userId = $_SESSION['user_id'];
$db = getDB();

// Récupération de TOUS les comptes (y compris suspendus/clôturés)
$stmt = $db->prepare("
    SELECT c.*, 'proprietaire' as type_acces 
    FROM comptes c 
    WHERE c.user_id = ? 
    UNION ALL
    SELECT c.*, 'procuration' as type_acces 
    FROM comptes c 
    INNER JOIN procurations p ON c.id = p.compte_id 
    WHERE p.user_beneficiaire_id = ? 
    AND p.statut = 'active' 
    AND c.statut = 'actif'
    AND (p.date_fin IS NULL OR p.date_fin >= CURDATE())
    ORDER BY date_creation DESC
");
$stmt->execute([$userId, $userId]);
$comptes = $stmt->fetchAll();

$soldeTotal = calculerSoldeTotal($userId);
$messagesNonLus = compterMessagesNonLus($userId);

// Récupération des crédits actifs
$stmt = $db->prepare("SELECT * FROM credits WHERE user_id = ? AND statut = 'actif' ORDER BY date_creation DESC");
$stmt->execute([$userId]);
$credits = $stmt->fetchAll();

// Récupération du budget
$stmt = $db->prepare("SELECT * FROM budgets WHERE user_id = ? AND actif = 1 ORDER BY date_debut DESC LIMIT 1");
$stmt->execute([$userId]);
$budget = $stmt->fetch();

// Récupération des dernières opérations
$comptesIds = array_column($comptes, 'id');
if (!empty($comptesIds)) {
    $placeholders = implode(',', array_fill(0, count($comptesIds), '?'));
    $stmt = $db->prepare("
        SELECT o.*, c.numero_compte 
        FROM operations o 
        INNER JOIN comptes c ON o.compte_id = c.id 
        WHERE o.compte_id IN ($placeholders) 
        ORDER BY o.date_operation DESC 
        LIMIT 10
    ");
    $stmt->execute($comptesIds);
    $dernieresOperations = $stmt->fetchAll();
} else {
    $dernieresOperations = [];
}

$pageTitle = 'Tableau de bord';
include 'includes/header.php';
?>

    <div class="dashboard">
        <h1>Bienvenue, <?php echo e($_SESSION['prenom'] . ' ' . $_SESSION['nom']); ?></h1>

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
                <h3>Solde total</h3>
                <p class="amount <?php echo $soldeTotal < 0 ? 'negative' : 'positive'; ?>">
                    <?php echo formatMontant($soldeTotal); ?>
                </p>
            </div>

            <div class="summary-card">
                <h3>Nombre de comptes</h3>
                <p class="count"><?php echo count($comptes); ?></p>
            </div>

            <div class="summary-card">
                <h3>Crédits actifs</h3>
                <p class="count"><?php echo count($credits); ?></p>
            </div>

            <div class="summary-card">
                <h3>Messages non lus</h3>
                <p class="count"><?php echo $messagesNonLus; ?></p>
            </div>
        </div>

        <?php if ($budget): ?>
            <div class="budget-section">
                <h2>Budget <?php echo e($budget['periode']); ?></h2>
                <div class="budget-bar">
                    <div class="budget-progress" style="width: <?php echo min(100, ($budget['montant_utilise'] / $budget['montant_max']) * 100); ?>%"></div>
                </div>
                <p>
                    <?php echo formatMontant($budget['montant_utilise']); ?> / <?php echo formatMontant($budget['montant_max']); ?>
                    (<?php echo e($budget['categorie']); ?>)
                </p>
            </div>
        <?php endif; ?>

        <div class="accounts-section">
            <h2>Mes comptes</h2>
            <?php if (empty($comptes)): ?>
                <p>Aucun compte disponible.</p>
            <?php else: ?>
                <table class="table">
                    <thead>
                    <tr>
                        <th>Numéro de compte</th>
                        <th>Type</th>
                        <th>Solde</th>
                        <th>Accès</th>
                        <th>Statut</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($comptes as $compte): ?>
                        <tr>
                            <td><?php echo e($compte['numero_compte']); ?></td>
                            <td><?php echo e(ucfirst($compte['type_compte'])); ?></td>
                            <td class="<?php echo $compte['solde'] < 0 ? 'negative' : 'positive'; ?>">
                                <?php echo formatMontant($compte['solde']); ?>
                            </td>
                            <td><?php echo e(ucfirst($compte['type_acces'])); ?></td>
                            <td>
                            <span class="badge badge-<?php echo getStatutBadgeClass($compte['statut']); ?>">
                                <?php echo e(ucfirst($compte['statut'])); ?>
                            </span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>

        <?php if (!empty($credits)): ?>
            <div class="credits-section">
                <h2>Mes crédits</h2>
                <table class="table">
                    <thead>
                    <tr>
                        <th>Montant total</th>
                        <th>Montant restant</th>
                        <th>Taux</th>
                        <th>Mensualité</th>
                        <th>Échéance</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($credits as $credit): ?>
                        <tr>
                            <td><?php echo formatMontant($credit['montant_total']); ?></td>
                            <td><?php echo formatMontant($credit['montant_restant']); ?></td>
                            <td><?php echo e($credit['taux_interet']); ?>%</td>
                            <td><?php echo formatMontant($credit['mensualite']); ?></td>
                            <td><?php echo formatDate($credit['date_fin']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>

        <div class="operations-section">
            <h2>Dernières opérations</h2>
            <?php if (empty($dernieresOperations)): ?>
                <p>Aucune opération récente.</p>
            <?php else: ?>
                <table class="table">
                    <thead>
                    <tr>
                        <th>Date</th>
                        <th>Compte</th>
                        <th>Type</th>
                        <th>Description</th>
                        <th>Montant</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($dernieresOperations as $operation): ?>
                        <tr>
                            <td><?php echo formatDateTime($operation['date_operation']); ?></td>
                            <td><?php echo e(substr($operation['numero_compte'], -8)); ?></td>
                            <td><?php echo e(ucfirst($operation['type_operation'])); ?></td>
                            <td><?php echo e($operation['description'] ?? $operation['destinataire'] ?? '-'); ?></td>
                            <td class="<?php echo in_array($operation['type_operation'], ['debit', 'retrait', 'prelevement']) ? 'negative' : 'positive'; ?>">
                                <?php
                                $signe = in_array($operation['type_operation'], ['debit', 'retrait', 'prelevement']) ? '-' : '+';
                                echo $signe . ' ' . formatMontant($operation['montant']);
                                ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>

<?php
function getStatutBadgeClass($statut) {
    switch ($statut) {
        case 'actif':
            return 'success';
        case 'suspendu':
            return 'warning';
        case 'cloture':
            return 'secondary';
        default:
            return 'secondary';
    }
}

include 'includes/footer.php';
?>