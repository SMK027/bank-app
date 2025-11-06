<?php
require_once '../config/config.php';
requireClient();

$userId = $_SESSION['user_id'];
$db = getDB();

// Récupération de TOUS les comptes (y compris suspendus/clôturés) pour affichage
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
    AND (p.date_fin IS NULL OR p.date_fin >= CURDATE())
    ORDER BY date_creation DESC
");
$stmt->execute([$userId, $userId]);
$comptes = $stmt->fetchAll();

// Récupération des comptes ACTIFS uniquement pour les opérations
$stmt = $db->prepare("
    SELECT c.*, 'proprietaire' as type_acces 
    FROM comptes c 
    WHERE c.user_id = ? AND c.statut = 'actif'
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
$comptesActifs = $stmt->fetchAll();

// Traitement du formulaire d'ajout d'opération
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_operation') {
    $compteId = $_POST['compte_id'] ?? 0;
    $typeOperation = $_POST['type_operation'] ?? '';
    $montant = $_POST['montant'] ?? 0;
    $destinataire = $_POST['destinataire'] ?? null;
    $nature = $_POST['nature'] ?? null;
    $description = $_POST['description'] ?? null;

    // Vérifier que le compte appartient à l'utilisateur ET qu'il est actif
    $compteValide = false;
    $compteActif = false;
    foreach ($comptesActifs as $compte) {
        if ($compte['id'] == $compteId) {
            $compteValide = true;
            $compteActif = true;
            break;
        }
    }

    if (!$compteValide) {
        setFlashMessage('Compte invalide ou inactif. Les opérations sont bloquées sur ce compte.', 'danger');
    } elseif (!validerMontant($montant)) {
        setFlashMessage('Montant invalide.', 'danger');
    } elseif (!in_array($typeOperation, ['debit', 'credit', 'virement', 'prelevement', 'depot', 'retrait'])) {
        setFlashMessage('Type d\'opération invalide.', 'danger');
    } else {
        if (enregistrerOperation($compteId, $typeOperation, $montant, $destinataire, $nature, $description)) {
            setFlashMessage('Opération enregistrée avec succès.', 'success');
            logActivity($userId, 'Ajout opération', "Type: $typeOperation, Montant: $montant");
        } else {
            setFlashMessage('Erreur lors de l\'enregistrement de l\'opération.', 'danger');
        }
    }

    redirect(BASE_URL . '/client/operations.php');
}

// Récupération des opérations
$compteSelectionne = $_GET['compte_id'] ?? null;
$operations = [];

if ($compteSelectionne) {
    // Vérifier que le compte appartient à l'utilisateur
    $compteValide = false;
    foreach ($comptes as $compte) {
        if ($compte['id'] == $compteSelectionne) {
            $compteValide = true;
            break;
        }
    }

    if ($compteValide) {
        $stmt = $db->prepare("
            SELECT o.*, c.numero_compte 
            FROM operations o 
            INNER JOIN comptes c ON o.compte_id = c.id 
            WHERE o.compte_id = ? 
            ORDER BY o.date_operation DESC
        ");
        $stmt->execute([$compteSelectionne]);
        $operations = $stmt->fetchAll();
    }
} else {
    // Toutes les opérations de tous les comptes
    $comptesIds = array_column($comptes, 'id');
    if (!empty($comptesIds)) {
        $placeholders = implode(',', array_fill(0, count($comptesIds), '?'));
        $stmt = $db->prepare("
            SELECT o.*, c.numero_compte 
            FROM operations o 
            INNER JOIN comptes c ON o.compte_id = c.id 
            WHERE o.compte_id IN ($placeholders) 
            ORDER BY o.date_operation DESC
        ");
        $stmt->execute($comptesIds);
        $operations = $stmt->fetchAll();
    }
}

$pageTitle = 'Opérations';
include 'includes/header.php';
?>

    <div class="page-content">
        <h1>Gestion des opérations</h1>

        <?php
        $flash = getFlashMessage();
        if ($flash):
            ?>
            <div class="alert alert-<?php echo e($flash['type']); ?>">
                <?php echo e($flash['message']); ?>
            </div>
        <?php endif; ?>

        <div class="operations-actions">
            <button class="btn btn-primary" onclick="toggleForm('add-operation-form')">
                Enregistrer une opération
            </button>
        </div>

        <div id="add-operation-form" class="form-container" style="display: none;">
            <h2>Nouvelle opération</h2>
            <form method="POST" action="">
                <input type="hidden" name="action" value="add_operation">

                <div class="form-group">
                    <label for="compte_id">Compte *</label>
                    <select id="compte_id" name="compte_id" class="form-control" required>
                        <option value="">Sélectionner un compte</option>
                        <?php foreach ($comptesActifs as $compte): ?>
                            <option value="<?php echo $compte['id']; ?>">
                                <?php echo e($compte['numero_compte'] . ' - ' . ucfirst($compte['type_compte'])); ?>
                            </option>
                        <?php endforeach; ?>
                        <?php if (count($comptesActifs) === 0): ?>
                            <option value="" disabled>Aucun compte actif disponible</option>
                        <?php endif; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label for="type_operation">Type d'opération *</label>
                    <select id="type_operation" name="type_operation" class="form-control" required>
                        <option value="">Sélectionner un type</option>
                        <option value="credit">Crédit</option>
                        <option value="debit">Débit</option>
                        <option value="virement">Virement</option>
                        <option value="prelevement">Prélèvement</option>
                        <option value="depot">Dépôt</option>
                        <option value="retrait">Retrait</option>
                    </select>
                </div>

                <div class="form-group">
                    <label for="montant">Montant (€) *</label>
                    <input type="number" id="montant" name="montant" class="form-control" step="0.01" min="0.01" required>
                </div>

                <div class="form-group">
                    <label for="destinataire">Destinataire</label>
                    <input type="text" id="destinataire" name="destinataire" class="form-control">
                </div>

                <div class="form-group">
                    <label for="nature">Nature</label>
                    <input type="text" id="nature" name="nature" class="form-control" placeholder="Ex: Salaire, Loyer, Courses...">
                </div>

                <div class="form-group">
                    <label for="description">Description</label>
                    <textarea id="description" name="description" class="form-control" rows="3"></textarea>
                </div>

                <div class="form-actions">
                    <button type="submit" class="btn btn-primary">Enregistrer</button>
                    <button type="button" class="btn btn-secondary" onclick="toggleForm('add-operation-form')">Annuler</button>
                </div>
            </form>
        </div>

        <div class="operations-filter">
            <form method="GET" action="">
                <div class="form-inline">
                    <label for="compte_filter">Filtrer par compte :</label>
                    <select id="compte_filter" name="compte_id" class="form-control" onchange="this.form.submit()">
                        <option value="">Tous les comptes</option>
                        <?php foreach ($comptes as $compte): ?>
                            <option value="<?php echo $compte['id']; ?>" <?php echo $compteSelectionne == $compte['id'] ? 'selected' : ''; ?>>
                                <?php echo e($compte['numero_compte']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </form>
        </div>

        <div class="operations-list">
            <h2>Historique des opérations</h2>

            <?php if (empty($operations)): ?>
                <p>Aucune opération trouvée.</p>
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
                        <th>Solde après</th>
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
                            <td><?php echo formatMontant($operation['solde_apres']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>

<?php include 'includes/footer.php'; ?>