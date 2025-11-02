<?php
require_once '../config/config.php';
requireAdmin();

$db = getDB();
$adminId = $_SESSION['user_id'];

// Traitement de création de prélèvement
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'creer_prelevement') {
    $compteSourceId = $_POST['compte_source'] ?? 0;
    $compteDestinataireId = $_POST['compte_destinataire'] ?? null;
    $montant = $_POST['montant'] ?? 0;
    $descriptif = $_POST['descriptif'] ?? '';
    $dateExecution = $_POST['date_execution'] ?? date('Y-m-d');

    if (empty($compteSourceId) || !validerMontant($montant) || $montant <= 0) {
        setFlashMessage('Veuillez remplir tous les champs obligatoires avec des valeurs valides.', 'danger');
    } elseif (empty($descriptif)) {
        setFlashMessage('Le descriptif est obligatoire.', 'danger');
    } else {
        try {
            // Vérifier que le compte source existe et est actif
            $stmt = $db->prepare("SELECT * FROM comptes WHERE id = ? AND statut = 'actif'");
            $stmt->execute([$compteSourceId]);
            $compteSource = $stmt->fetch();

            if (!$compteSource) {
                throw new Exception('Compte source invalide ou inactif.');
            }

            // Si un compte destinataire est fourni, vérifier qu'il existe et est actif
            if (!empty($compteDestinataireId)) {
                $stmt = $db->prepare("SELECT * FROM comptes WHERE id = ? AND statut = 'actif'");
                $stmt->execute([$compteDestinataireId]);
                $compteDestinataire = $stmt->fetch();

                if (!$compteDestinataire) {
                    throw new Exception('Compte destinataire invalide ou inactif.');
                }

                if ($compteSourceId == $compteDestinataireId) {
                    throw new Exception('Le compte source et destinataire ne peuvent pas être identiques.');
                }
            } else {
                $compteDestinataireId = null;
            }

            // Créer le prélèvement
            $stmt = $db->prepare("INSERT INTO prelevements_planifies (compte_source_id, compte_destinataire_id, montant, descriptif, date_execution, admin_createur_id) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->execute([$compteSourceId, $compteDestinataireId, $montant, $descriptif, $dateExecution, $adminId]);

            setFlashMessage('Prélèvement créé avec succès.', 'success');
            logActivity($adminId, 'Création prélèvement', "Compte: {$compteSource['numero_compte']}, Montant: $montant, Date: $dateExecution");

        } catch (Exception $e) {
            setFlashMessage('Erreur : ' . $e->getMessage(), 'danger');
        }
    }

    redirect(BASE_URL . '/admin/prelevements.php');
}

// Traitement de modification de prélèvement
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'modifier_prelevement') {
    $prelevementId = $_POST['prelevement_id'] ?? 0;
    $montant = $_POST['montant'] ?? 0;
    $descriptif = $_POST['descriptif'] ?? '';
    $dateExecution = $_POST['date_execution'] ?? date('Y-m-d');

    if (!validerMontant($montant) || $montant <= 0 || empty($descriptif)) {
        setFlashMessage('Veuillez remplir tous les champs obligatoires avec des valeurs valides.', 'danger');
    } else {
        try {
            // Vérifier que le prélèvement existe et est en attente
            $stmt = $db->prepare("SELECT * FROM prelevements_planifies WHERE id = ? AND statut = 'en_attente'");
            $stmt->execute([$prelevementId]);
            $prelevement = $stmt->fetch();

            if (!$prelevement) {
                throw new Exception('Prélèvement introuvable ou déjà exécuté.');
            }

            // Modifier le prélèvement
            $stmt = $db->prepare("UPDATE prelevements_planifies SET montant = ?, descriptif = ?, date_execution = ? WHERE id = ?");
            $stmt->execute([$montant, $descriptif, $dateExecution, $prelevementId]);

            setFlashMessage('Prélèvement modifié avec succès.', 'success');
            logActivity($adminId, 'Modification prélèvement', "ID: $prelevementId, Nouveau montant: $montant");

        } catch (Exception $e) {
            setFlashMessage('Erreur : ' . $e->getMessage(), 'danger');
        }
    }

    redirect(BASE_URL . '/admin/prelevements.php');
}

// Traitement d'annulation de prélèvement
if (isset($_GET['action']) && $_GET['action'] === 'annuler' && isset($_GET['id'])) {
    $prelevementId = $_GET['id'];

    $stmt = $db->prepare("SELECT * FROM prelevements_planifies WHERE id = ? AND statut = 'en_attente'");
    $stmt->execute([$prelevementId]);
    $prelevement = $stmt->fetch();

    if ($prelevement) {
        $stmt = $db->prepare("UPDATE prelevements_planifies SET statut = 'annule' WHERE id = ?");
        $stmt->execute([$prelevementId]);

        setFlashMessage('Prélèvement annulé avec succès.', 'success');
        logActivity($adminId, 'Annulation prélèvement', "ID: $prelevementId");
    }

    redirect(BASE_URL . '/admin/prelevements.php');
}

// Récupération de tous les comptes actifs
$stmt = $db->query("SELECT c.*, u.prenom, u.nom FROM comptes c INNER JOIN users u ON c.user_id = u.id WHERE c.statut = 'actif' ORDER BY u.nom, u.prenom");
$comptesActifs = $stmt->fetchAll();

// Récupération de tous les prélèvements
$stmt = $db->query("
    SELECT p.*, 
           cs.numero_compte as compte_source_numero,
           cd.numero_compte as compte_destinataire_numero,
           us.prenom as client_prenom, us.nom as client_nom,
           ua.username as admin_username
    FROM prelevements_planifies p
    INNER JOIN comptes cs ON p.compte_source_id = cs.id
    LEFT JOIN comptes cd ON p.compte_destinataire_id = cd.id
    INNER JOIN users us ON cs.user_id = us.id
    INNER JOIN users ua ON p.admin_createur_id = ua.id
    ORDER BY p.date_execution ASC, p.date_creation DESC
");
$prelevements = $stmt->fetchAll();

$pageTitle = 'Gestion des prélèvements';
include 'includes/header.php';
?>

<div class="page-content">
    <h1>Gestion des prélèvements</h1>

    <?php
    $flash = getFlashMessage();
    if ($flash):
        ?>
        <div class="alert alert-<?php echo e($flash['type']); ?>">
            <?php echo e($flash['message']); ?>
        </div>
    <?php endif; ?>

    <div class="page-actions">
        <button class="btn btn-primary" onclick="toggleForm('create-prelevement-form')">
            Créer un prélèvement
        </button>
    </div>

    <div id="create-prelevement-form" class="form-container" style="display: none;">
        <h2>Nouveau prélèvement</h2>
        <form method="POST" action="">
            <input type="hidden" name="action" value="creer_prelevement">

            <div class="form-group">
                <label for="compte_source">Compte à débiter (source) *</label>
                <select id="compte_source" name="compte_source" class="form-control" required>
                    <option value="">Sélectionner un compte</option>
                    <?php foreach ($comptesActifs as $compte): ?>
                        <option value="<?php echo $compte['id']; ?>">
                            <?php echo e($compte['prenom'] . ' ' . $compte['nom'] . ' - ' . $compte['numero_compte'] . ' (' . ucfirst($compte['type_compte']) . ') - Solde: ' . formatMontant($compte['solde'])); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group">
                <label for="compte_destinataire">Compte destinataire (optionnel)</label>
                <select id="compte_destinataire" name="compte_destinataire" class="form-control">
                    <option value="">Aucun (fonds pour la banque)</option>
                    <?php foreach ($comptesActifs as $compte): ?>
                        <option value="<?php echo $compte['id']; ?>">
                            <?php echo e($compte['prenom'] . ' ' . $compte['nom'] . ' - ' . $compte['numero_compte'] . ' (' . ucfirst($compte['type_compte']) . ')'); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <small class="form-text">Si aucun compte n'est sélectionné, les fonds seront destinés à la banque.</small>
            </div>

            <div class="form-group">
                <label for="montant">Montant (€) *</label>
                <input type="number" id="montant" name="montant" class="form-control" step="0.01" min="0.01" required>
            </div>

            <div class="form-group">
                <label for="descriptif">Descriptif *</label>
                <textarea id="descriptif" name="descriptif" class="form-control" rows="3" required placeholder="Ex: Frais de gestion mensuel, Pénalité de retard..."></textarea>
            </div>

            <div class="form-group">
                <label for="date_execution">Date d'exécution *</label>
                <input type="date" id="date_execution" name="date_execution" class="form-control" value="<?php echo date('Y-m-d'); ?>" min="<?php echo date('Y-m-d'); ?>" required>
                <small class="form-text">Le prélèvement sera exécuté automatiquement à cette date.</small>
            </div>

            <div class="form-actions">
                <button type="submit" class="btn btn-primary">Créer le prélèvement</button>
                <button type="button" class="btn btn-secondary" onclick="toggleForm('create-prelevement-form')">Annuler</button>
            </div>
        </form>
    </div>

    <div class="prelevements-list">
        <h2>Liste des prélèvements (<?php echo count($prelevements); ?>)</h2>

        <?php if (empty($prelevements)): ?>
            <p>Aucun prélèvement enregistré.</p>
        <?php else: ?>
            <table class="table">
                <thead>
                <tr>
                    <th>ID</th>
                    <th>Date exécution</th>
                    <th>Client</th>
                    <th>Compte source</th>
                    <th>Compte dest.</th>
                    <th>Montant</th>
                    <th>Descriptif</th>
                    <th>Statut</th>
                    <th>Admin</th>
                    <th>Actions</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($prelevements as $prelevement): ?>
                    <tr>
                        <td><?php echo $prelevement['id']; ?></td>
                        <td><?php echo formatDate($prelevement['date_execution']); ?></td>
                        <td><?php echo e($prelevement['client_prenom'] . ' ' . $prelevement['client_nom']); ?></td>
                        <td><?php echo e(substr($prelevement['compte_source_numero'], -8)); ?></td>
                        <td>
                            <?php if ($prelevement['compte_destinataire_numero']): ?>
                                <?php echo e(substr($prelevement['compte_destinataire_numero'], -8)); ?>
                            <?php else: ?>
                                <span class="badge badge-secondary">Banque</span>
                            <?php endif; ?>
                        </td>
                        <td><?php echo formatMontant($prelevement['montant']); ?></td>
                        <td><?php echo e(substr($prelevement['descriptif'], 0, 50)) . (strlen($prelevement['descriptif']) > 50 ? '...' : ''); ?></td>
                        <td>
                            <span class="badge badge-<?php
                            echo $prelevement['statut'] === 'execute' ? 'success' :
                                ($prelevement['statut'] === 'annule' ? 'secondary' :
                                    ($prelevement['statut'] === 'erreur' ? 'danger' : 'warning'));
                            ?>">
                                <?php echo ucfirst($prelevement['statut']); ?>
                            </span>
                        </td>
                        <td><?php echo e($prelevement['admin_username']); ?></td>
                        <td class="actions">
                            <?php if ($prelevement['statut'] === 'en_attente'): ?>
                                <button class="btn btn-sm btn-warning" onclick="showModifierPrelevement(<?php echo $prelevement['id']; ?>, <?php echo $prelevement['montant']; ?>, '<?php echo e(addslashes($prelevement['descriptif'])); ?>', '<?php echo $prelevement['date_execution']; ?>')">
                                    Modifier
                                </button>
                                <a href="?action=annuler&id=<?php echo $prelevement['id']; ?>"
                                   class="btn btn-sm btn-danger"
                                   onclick="return confirm('Annuler ce prélèvement ?')">
                                    Annuler
                                </a>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>

<!-- Modal de modification de prélèvement -->
<div id="modifier-prelevement-modal" class="modal" style="display: none;">
    <div class="modal-content">
        <h2>Modifier le prélèvement</h2>
        <form method="POST" action="">
            <input type="hidden" name="action" value="modifier_prelevement">
            <input type="hidden" name="prelevement_id" id="modifier-prelevement-id">

            <div class="form-group">
                <label for="modifier_montant">Montant (€) *</label>
                <input type="number" id="modifier_montant" name="montant" class="form-control" step="0.01" min="0.01" required>
            </div>

            <div class="form-group">
                <label for="modifier_descriptif">Descriptif *</label>
                <textarea id="modifier_descriptif" name="descriptif" class="form-control" rows="3" required></textarea>
            </div>

            <div class="form-group">
                <label for="modifier_date_execution">Date d'exécution *</label>
                <input type="date" id="modifier_date_execution" name="date_execution" class="form-control" min="<?php echo date('Y-m-d'); ?>" required>
            </div>

            <div class="form-actions">
                <button type="submit" class="btn btn-primary">Modifier</button>
                <button type="button" class="btn btn-secondary" onclick="hideModifierPrelevement()">Annuler</button>
            </div>
        </form>
    </div>
</div>

<script>
    function showModifierPrelevement(id, montant, descriptif, dateExecution) {
        document.getElementById('modifier-prelevement-id').value = id;
        document.getElementById('modifier_montant').value = montant;
        document.getElementById('modifier_descriptif').value = descriptif;
        document.getElementById('modifier_date_execution').value = dateExecution;
        document.getElementById('modifier-prelevement-modal').style.display = 'flex';
    }

    function hideModifierPrelevement() {
        document.getElementById('modifier-prelevement-modal').style.display = 'none';
    }
</script>

<?php include 'includes/footer.php'; ?>
