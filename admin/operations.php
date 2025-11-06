<?php
require_once '../config/config.php';
requireAdmin();

$db = getDB();

// Traitement de crédit/débit sur un compte
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_operation') {
    $compteId = $_POST['compte_id'] ?? 0;
    $typeOperation = $_POST['type_operation'] ?? '';
    $montant = $_POST['montant'] ?? 0;
    $description = $_POST['description'] ?? '';

    if (!validerMontant($montant)) {
        setFlashMessage('Montant invalide.', 'danger');
    } elseif (!in_array($typeOperation, ['credit', 'debit'])) {
        setFlashMessage('Type d\'opération invalide.', 'danger');
    } else {
        $stmt = $db->prepare("SELECT c.*, u.id as user_id FROM comptes c INNER JOIN users u ON c.user_id = u.id WHERE c.id = ?");
        $stmt->execute([$compteId]);
        $compte = $stmt->fetch();

        if ($compte) {
            if (enregistrerOperation($compteId, $typeOperation, $montant, 'Administration', 'Opération administrative', $description)) {
                // Notification RGPD
                $message = ($typeOperation === 'credit' ? 'Crédit' : 'Débit') . " de " . formatMontant($montant) . " sur le compte " . $compte['numero_compte'];
                envoyerNotificationRGPD($compte['user_id'], 'Opération administrative', $message);

                setFlashMessage('Opération enregistrée avec succès.', 'success');
                logActivity($_SESSION['user_id'], 'Opération administrative', "Type: $typeOperation, Montant: $montant, Compte: {$compte['numero_compte']}");
            } else {
                setFlashMessage('Erreur lors de l\'enregistrement de l\'opération.', 'danger');
            }
        }
    }

    redirect(BASE_URL . '/admin/operations.php');
}

// Récupération de tous les comptes actifs avec informations détaillées
$stmt = $db->query("
    SELECT c.id, c.numero_compte, c.type_compte, c.solde, c.negatif_autorise, u.nom, u.prenom, u.username
    FROM comptes c 
    INNER JOIN users u ON c.user_id = u.id 
    WHERE c.statut = 'actif' 
    ORDER BY u.nom, u.prenom
");
$comptes = $stmt->fetchAll();

// Récupération des dernières opérations avec informations complètes
$stmt = $db->query("
    SELECT o.*, c.numero_compte, c.type_compte, c.id as compte_id, u.nom, u.prenom, u.id as user_id
    FROM operations o 
    INNER JOIN comptes c ON o.compte_id = c.id 
    INNER JOIN users u ON c.user_id = u.id 
    ORDER BY o.date_operation DESC 
    LIMIT 50
");
$operations = $stmt->fetchAll();

$pageTitle = 'Gestion des opérations';
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

    <div class="page-actions">
        <button class="btn btn-primary" onclick="toggleForm('add-operation-form')">
            Créer une opération
        </button>
    </div>

    <div id="add-operation-form" class="form-container" style="display: none;">
        <h2>Nouvelle opération administrative</h2>
        <form method="POST" action="">
            <input type="hidden" name="action" value="add_operation">

            <div class="form-group">
                <label for="compte_id">Compte *</label>
                <select id="compte_id" name="compte_id" class="form-control" required onchange="updateCompteInfo()">
                    <option value="">Sélectionner un compte</option>
                    <?php foreach ($comptes as $compte): ?>
                        <option value="<?php echo $compte['id']; ?>"
                                data-type="<?php echo e($compte['type_compte']); ?>"
                                data-solde="<?php echo $compte['solde']; ?>"
                                data-negatif="<?php echo $compte['negatif_autorise']; ?>"
                                data-proprietaire="<?php echo e($compte['prenom'] . ' ' . $compte['nom']); ?>">
                            [ID: <?php echo $compte['id']; ?>] <?php echo e($compte['numero_compte']); ?> -
                            <?php echo e($compte['prenom'] . ' ' . $compte['nom']); ?> -
                            <?php echo e(ucfirst($compte['type_compte'])); ?> -
                            Solde: <?php echo formatMontant($compte['solde']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Informations du compte sélectionné -->
            <div id="compte-info" class="compte-info-box" style="display: none;">
                <h3>Informations du compte</h3>
                <table class="info-table">
                    <tr>
                        <th>ID du compte</th>
                        <td id="info-compte-id">-</td>
                    </tr>
                    <tr>
                        <th>Propriétaire</th>
                        <td id="info-proprietaire">-</td>
                    </tr>
                    <tr>
                        <th>Type de compte</th>
                        <td id="info-type">-</td>
                    </tr>
                    <tr>
                        <th>Solde actuel</th>
                        <td id="info-solde">-</td>
                    </tr>
                    <tr>
                        <th>Négatif autorisé</th>
                        <td id="info-negatif">-</td>
                    </tr>
                </table>
            </div>

            <div class="form-group">
                <label for="type_operation">Type d'opération *</label>
                <select id="type_operation" name="type_operation" class="form-control" required>
                    <option value="">Sélectionner un type</option>
                    <option value="credit">Crédit (ajouter de l'argent)</option>
                    <option value="debit">Débit (retirer de l'argent)</option>
                </select>
            </div>

            <div class="form-group">
                <label for="montant">Montant (€) *</label>
                <input type="number" id="montant" name="montant" class="form-control" step="0.01" min="0.01" required>
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

    <div class="operations-list">
        <h2>Dernières opérations (50)</h2>

        <?php if (empty($operations)): ?>
            <p>Aucune opération enregistrée.</p>
        <?php else: ?>
            <table class="table">
                <thead>
                <tr>
                    <th>Date</th>
                    <th>ID Compte</th>
                    <th>Numéro de compte</th>
                    <th>Type compte</th>
                    <th>Client</th>
                    <th>Type opération</th>
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
                        <td><strong><?php echo $operation['compte_id']; ?></strong></td>
                        <td><?php echo e(substr($operation['numero_compte'], -8)); ?></td>
                        <td>
                            <span class="badge badge-info">
                                <?php echo e(ucfirst($operation['type_compte'])); ?>
                            </span>
                        </td>
                        <td><?php echo e($operation['prenom'] . ' ' . $operation['nom']); ?></td>
                        <td>
                            <span class="badge badge-<?php echo in_array($operation['type_operation'], ['debit', 'retrait', 'prelevement']) ? 'danger' : 'success'; ?>">
                                <?php echo e(ucfirst($operation['type_operation'])); ?>
                            </span>
                        </td>
                        <td><?php echo e($operation['destinataire'] ?? '-'); ?></td>
                        <td><?php echo e($operation['nature'] ?? '-'); ?></td>
                        <td><?php echo e($operation['description'] ?? '-'); ?></td>
                        <td class="<?php echo in_array($operation['type_operation'], ['debit', 'retrait', 'prelevement']) ? 'negative' : 'positive'; ?>">
                            <?php
                            $signe = in_array($operation['type_operation'], ['debit', 'retrait', 'prelevement']) ? '-' : '+';
                            echo $signe . ' ' . formatMontant($operation['montant']);
                            ?>
                        </td>
                        <td class="<?php echo $operation['solde_apres'] < 0 ? 'negative' : 'positive'; ?>">
                            <?php echo formatMontant($operation['solde_apres']); ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>

<script>
    function updateCompteInfo() {
        const select = document.getElementById('compte_id');
        const selectedOption = select.options[select.selectedIndex];

        if (selectedOption.value) {
            const compteId = selectedOption.value;
            const type = selectedOption.getAttribute('data-type');
            const solde = parseFloat(selectedOption.getAttribute('data-solde'));
            const negatif = parseFloat(selectedOption.getAttribute('data-negatif'));
            const proprietaire = selectedOption.getAttribute('data-proprietaire');

            // Afficher la box d'informations
            document.getElementById('compte-info').style.display = 'block';

            // Remplir les informations
            document.getElementById('info-compte-id').textContent = compteId;
            document.getElementById('info-proprietaire').textContent = proprietaire;
            document.getElementById('info-type').textContent = type.charAt(0).toUpperCase() + type.slice(1);

            // Formater et colorer le solde
            const soldeElement = document.getElementById('info-solde');
            soldeElement.textContent = formatMontant(solde);
            soldeElement.className = solde < 0 ? 'negative' : 'positive';

            // Afficher le négatif autorisé
            document.getElementById('info-negatif').textContent = formatMontant(negatif);
        } else {
            document.getElementById('compte-info').style.display = 'none';
        }
    }

    function formatMontant(montant) {
        return new Intl.NumberFormat('fr-FR', {
            style: 'currency',
            currency: 'EUR'
        }).format(montant);
    }
</script>

<style>
    .compte-info-box {
        background-color: #f8f9fa;
        border: 2px solid #dee2e6;
        border-radius: 8px;
        padding: 20px;
        margin: 20px 0;
    }

    .compte-info-box h3 {
        margin-top: 0;
        margin-bottom: 15px;
        color: #2c3e50;
        font-size: 1.1em;
    }

    .compte-info-box .info-table {
        width: 100%;
        border-collapse: collapse;
    }

    .compte-info-box .info-table tr {
        border-bottom: 1px solid #dee2e6;
    }

    .compte-info-box .info-table tr:last-child {
        border-bottom: none;
    }

    .compte-info-box .info-table th {
        text-align: left;
        padding: 10px;
        font-weight: 600;
        color: #495057;
        width: 40%;
    }

    .compte-info-box .info-table td {
        text-align: left;
        padding: 10px;
        font-weight: 500;
    }

    .compte-info-box .positive {
        color: #27ae60;
        font-weight: bold;
    }

    .compte-info-box .negative {
        color: #e74c3c;
        font-weight: bold;
    }
</style>

<?php include 'includes/footer.php'; ?>
