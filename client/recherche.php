<?php
require_once '../config/config.php';
requireClient();

$userId = $_SESSION['user_id'];
$comptes = getComptesUtilisateur($userId);
$db = getDB();

$operations = [];
$recherche = false;

// Traitement de la recherche
if ($_SERVER['REQUEST_METHOD'] === 'GET' && (isset($_GET['nature']) || isset($_GET['destinataire']) || isset($_GET['montant_min']) || isset($_GET['montant_max']))) {
    $recherche = true;
    
    $nature = $_GET['nature'] ?? '';
    $destinataire = $_GET['destinataire'] ?? '';
    $montantMin = $_GET['montant_min'] ?? '';
    $montantMax = $_GET['montant_max'] ?? '';
    
    $comptesIds = array_column($comptes, 'id');
    
    if (!empty($comptesIds)) {
        $placeholders = implode(',', array_fill(0, count($comptesIds), '?'));
        
        $sql = "
            SELECT o.*, c.numero_compte 
            FROM operations o 
            INNER JOIN comptes c ON o.compte_id = c.id 
            WHERE o.compte_id IN ($placeholders)
        ";
        
        $params = $comptesIds;
        
        if (!empty($nature)) {
            $sql .= " AND o.nature LIKE ?";
            $params[] = '%' . $nature . '%';
        }
        
        if (!empty($destinataire)) {
            $sql .= " AND o.destinataire LIKE ?";
            $params[] = '%' . $destinataire . '%';
        }
        
        if (!empty($montantMin) && is_numeric($montantMin)) {
            $sql .= " AND o.montant >= ?";
            $params[] = $montantMin;
        }
        
        if (!empty($montantMax) && is_numeric($montantMax)) {
            $sql .= " AND o.montant <= ?";
            $params[] = $montantMax;
        }
        
        $sql .= " ORDER BY o.date_operation DESC";
        
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $operations = $stmt->fetchAll();
    }
}

$pageTitle = 'Recherche d\'opérations';
include 'includes/header.php';
?>

<div class="page-content">
    <h1>Recherche d'opérations</h1>
    
    <div class="search-form">
        <form method="GET" action="">
            <div class="form-row">
                <div class="form-group">
                    <label for="nature">Nature</label>
                    <input type="text" id="nature" name="nature" class="form-control" 
                           value="<?php echo e($_GET['nature'] ?? ''); ?>" 
                           placeholder="Ex: Salaire, Loyer...">
                </div>
                
                <div class="form-group">
                    <label for="destinataire">Destinataire</label>
                    <input type="text" id="destinataire" name="destinataire" class="form-control" 
                           value="<?php echo e($_GET['destinataire'] ?? ''); ?>" 
                           placeholder="Nom du destinataire">
                </div>
            </div>
            
            <div class="form-row">
                <div class="form-group">
                    <label for="montant_min">Montant minimum (€)</label>
                    <input type="number" id="montant_min" name="montant_min" class="form-control" 
                           step="0.01" min="0" 
                           value="<?php echo e($_GET['montant_min'] ?? ''); ?>">
                </div>
                
                <div class="form-group">
                    <label for="montant_max">Montant maximum (€)</label>
                    <input type="number" id="montant_max" name="montant_max" class="form-control" 
                           step="0.01" min="0" 
                           value="<?php echo e($_GET['montant_max'] ?? ''); ?>">
                </div>
            </div>
            
            <div class="form-actions">
                <button type="submit" class="btn btn-primary">Rechercher</button>
                <a href="recherche.php" class="btn btn-secondary">Réinitialiser</a>
            </div>
        </form>
    </div>
    
    <?php if ($recherche): ?>
    <div class="search-results">
        <h2>Résultats de la recherche (<?php echo count($operations); ?> opération(s) trouvée(s))</h2>
        
        <?php if (empty($operations)): ?>
            <div class="alert alert-info">
                Aucune opération ne correspond à vos critères de recherche.
            </div>
        <?php else: ?>
            <table class="table">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Compte</th>
                        <th>Type</th>
                        <th>Destinataire</th>
                        <th>Nature</th>
                        <th>Description</th>
                        <th>Montant</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($operations as $operation): ?>
                    <tr>
                        <td><?php echo formatDateTime($operation['date_operation']); ?></td>
                        <td><?php echo e(substr($operation['numero_compte'], -8)); ?></td>
                        <td><?php echo e(ucfirst($operation['type_operation'])); ?></td>
                        <td><?php echo e($operation['destinataire'] ?? '-'); ?></td>
                        <td><?php echo e($operation['nature'] ?? '-'); ?></td>
                        <td><?php echo e($operation['description'] ?? '-'); ?></td>
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
            
            <div class="search-summary">
                <h3>Résumé</h3>
                <?php
                $totalCredit = 0;
                $totalDebit = 0;
                foreach ($operations as $operation) {
                    if (in_array($operation['type_operation'], ['debit', 'retrait', 'prelevement'])) {
                        $totalDebit += $operation['montant'];
                    } else {
                        $totalCredit += $operation['montant'];
                    }
                }
                ?>
                <p>Total des crédits : <span class="positive"><?php echo formatMontant($totalCredit); ?></span></p>
                <p>Total des débits : <span class="negative"><?php echo formatMontant($totalDebit); ?></span></p>
                <p>Solde net : <span class="<?php echo ($totalCredit - $totalDebit) >= 0 ? 'positive' : 'negative'; ?>">
                    <?php echo formatMontant($totalCredit - $totalDebit); ?>
                </span></p>
            </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</div>

<?php include 'includes/footer.php'; ?>
