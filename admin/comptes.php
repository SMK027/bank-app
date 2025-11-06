<?php
require_once '../config/config.php';
requireAdmin();

$db = getDB();

// Traitement de création de compte
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create_compte') {
    $userId = $_POST['user_id'] ?? 0;
    $typeCompte = $_POST['type_compte'] ?? 'courant';
    $soldeInitial = $_POST['solde_initial'] ?? 0;
    $negatifAutorise = $_POST['negatif_autorise'] ?? 0;

    if ($userId <= 0) {
        setFlashMessage('Veuillez sélectionner un client.', 'danger');
    } else {
        $numeroCompte = generateNumeroCompte();

        $stmt = $db->prepare("INSERT INTO comptes (user_id, numero_compte, type_compte, solde, negatif_autorise) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$userId, $numeroCompte, $typeCompte, $soldeInitial, $negatifAutorise]);

        // Notification RGPD
        envoyerNotificationRGPD($userId, 'Création de compte', "Un nouveau compte $typeCompte a été créé : $numeroCompte");

        setFlashMessage('Compte créé avec succès.', 'success');
        logActivity($_SESSION['user_id'], 'Création compte', "Compte: $numeroCompte, Client ID: $userId");
    }

    redirect(BASE_URL . '/admin/comptes.php');
}

// Traitement de modification du négatif autorisé
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_negatif') {
    $compteId = $_POST['compte_id'] ?? 0;
    $negatifAutorise = $_POST['negatif_autorise'] ?? 0;

    $stmt = $db->prepare("SELECT c.*, u.id as user_id FROM comptes c INNER JOIN users u ON c.user_id = u.id WHERE c.id = ?");
    $stmt->execute([$compteId]);
    $compte = $stmt->fetch();

    if ($compte) {
        $stmt = $db->prepare("UPDATE comptes SET negatif_autorise = ? WHERE id = ?");
        $stmt->execute([$negatifAutorise, $compteId]);

        // Notification RGPD
        $message = $negatifAutorise > 0 ? "Négatif autorisé de " . formatMontant($negatifAutorise) . " accordé" : "Négatif autorisé révoqué";
        envoyerNotificationRGPD($compte['user_id'], 'Modification négatif autorisé', $message . " sur le compte " . $compte['numero_compte']);

        setFlashMessage('Négatif autorisé modifié avec succès.', 'success');
        logActivity($_SESSION['user_id'], 'Modification négatif autorisé', "Compte: {$compte['numero_compte']}, Montant: $negatifAutorise");
    }

    redirect(BASE_URL . '/admin/comptes.php');
}

// Traitement de clôture de compte
if (isset($_GET['action']) && $_GET['action'] === 'cloture' && isset($_GET['id'])) {
    $compteId = $_GET['id'];

    $stmt = $db->prepare("SELECT c.*, u.id as user_id FROM comptes c INNER JOIN users u ON c.user_id = u.id WHERE c.id = ?");
    $stmt->execute([$compteId]);
    $compte = $stmt->fetch();

    if ($compte) {
        // Vérifier que le solde est à zéro ou proche de zéro
        if (abs($compte['solde']) > 0.01 && $compte['solde'] >= 0) {
            setFlashMessage('Impossible de clôturer un compte avec un solde non nul. Solde actuel : ' . formatMontant($compte['solde']), 'danger');
        } else {
            $stmt = $db->prepare("UPDATE comptes SET statut = 'cloture' WHERE id = ?");
            $stmt->execute([$compteId]);

            // Notification RGPD
            envoyerNotificationRGPD($compte['user_id'], 'Clôture de compte', "Le compte " . $compte['numero_compte'] . " a été clôturé.");

            setFlashMessage('Compte clôturé avec succès.', 'success');
            logActivity($_SESSION['user_id'], 'Clôture compte', "Compte: {$compte['numero_compte']}");
        }
    }

    redirect(BASE_URL . '/admin/comptes.php');
}

// Traitement de suspension/activation
if (isset($_GET['action']) && $_GET['action'] === 'toggle_status' && isset($_GET['id'])) {
    $compteId = $_GET['id'];

    $stmt = $db->prepare("SELECT c.*, u.id as user_id FROM comptes c INNER JOIN users u ON c.user_id = u.id WHERE c.id = ?");
    $stmt->execute([$compteId]);
    $compte = $stmt->fetch();

    if ($compte) {
        $nouveauStatut = $compte['statut'] === 'actif' ? 'suspendu' : 'actif';

        $stmt = $db->prepare("UPDATE comptes SET statut = ? WHERE id = ?");
        $stmt->execute([$nouveauStatut, $compteId]);

        // Notification RGPD
        envoyerNotificationRGPD($compte['user_id'], 'Modification statut compte', "Le compte " . $compte['numero_compte'] . " a été " . ($nouveauStatut === 'actif' ? 'activé' : 'suspendu'));

        setFlashMessage('Statut du compte modifié avec succès.', 'success');
        logActivity($_SESSION['user_id'], 'Modification statut compte', "Compte: {$compte['numero_compte']}, Nouveau statut: $nouveauStatut");
    }

    redirect(BASE_URL . '/admin/comptes.php');
}

// Récupération de tous les comptes
$stmt = $db->query("
    SELECT c.*, u.username, u.nom, u.prenom 
    FROM comptes c 
    INNER JOIN users u ON c.user_id = u.id 
    ORDER BY c.date_creation DESC
");
$comptes = $stmt->fetchAll();

// Récupération de tous les clients pour le formulaire
$stmt = $db->query("SELECT id, username, nom, prenom FROM users WHERE role = 'client' AND statut = 'actif' ORDER BY nom, prenom");
$clients = $stmt->fetchAll();

$pageTitle = 'Gestion des comptes';
include 'includes/header.php';
?>

    <div class="page-content">
        <h1>Gestion des comptes bancaires</h1>

        <?php
        $flash = getFlashMessage();
        if ($flash):
            ?>
            <div class="alert alert-<?php echo e($flash['type']); ?>">
                <?php echo e($flash['message']); ?>
            </div>
        <?php endif; ?>

        <div class="page-actions">
            <button class="btn btn-primary" onclick="toggleForm('create-compte-form')">
                Créer un nouveau compte
            </button>
        </div>

        <div id="create-compte-form" class="form-container" style="display: none;">
            <h2>Nouveau compte</h2>
            <form method="POST" action="">
                <input type="hidden" name="action" value="create_compte">

                <div class="form-group">
                    <label for="user_id">Client *</label>
                    <select id="user_id" name="user_id" class="form-control" required>
                        <option value="">Sélectionner un client</option>
                        <?php foreach ($clients as $client): ?>
                            <option value="<?php echo $client['id']; ?>">
                                <?php echo e($client['prenom'] . ' ' . $client['nom'] . ' (' . $client['username'] . ')'); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label for="type_compte">Type de compte *</label>
                    <select id="type_compte" name="type_compte" class="form-control" required>
                        <option value="courant">Courant</option>
                        <option value="epargne">Épargne</option>
                        <option value="joint">Joint</option>
                    </select>
                </div>

                <div class="form-group">
                    <label for="solde_initial">Solde initial (€)</label>
                    <input type="number" id="solde_initial" name="solde_initial" class="form-control" step="0.01" value="0">
                </div>

                <div class="form-group">
                    <label for="negatif_autorise">Négatif autorisé (€)</label>
                    <input type="number" id="negatif_autorise" name="negatif_autorise" class="form-control" step="0.01" value="0">
                </div>

                <div class="form-actions">
                    <button type="submit" class="btn btn-primary">Créer</button>
                    <button type="button" class="btn btn-secondary" onclick="toggleForm('create-compte-form')">Annuler</button>
                </div>
            </form>
        </div>

        <div class="comptes-list">
            <h2>Liste des comptes (<?php echo count($comptes); ?>)</h2>

            <?php if (empty($comptes)): ?>
                <p>Aucun compte enregistré.</p>
            <?php else: ?>
                <table class="table">
                    <thead>
                    <tr>
                        <th>Numéro de compte</th>
                        <th>Client</th>
                        <th>Type</th>
                        <th>Solde</th>
                        <th>Négatif autorisé</th>
                        <th>Statut</th>
                        <th>Date création</th>
                        <th>Actions</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($comptes as $compte): ?>
                        <tr>
                            <td><?php echo e($compte['numero_compte']); ?></td>
                            <td><?php echo e($compte['prenom'] . ' ' . $compte['nom']); ?></td>
                            <td><?php echo e(ucfirst($compte['type_compte'])); ?></td>
                            <td class="<?php echo $compte['solde'] < 0 ? 'negative' : 'positive'; ?>">
                                <?php echo formatMontant($compte['solde']); ?>
                            </td>
                            <td><?php echo formatMontant($compte['negatif_autorise']); ?></td>
                            <td>
                            <span class="badge badge-<?php echo getStatutBadgeClassAdmin($compte['statut']); ?>">
                                <?php echo e(ucfirst($compte['statut'])); ?>
                            </span>
                            </td>
                            <td><?php echo formatDate($compte['date_creation']); ?></td>
                            <td class="actions">
                                <a href="?action=toggle_status&id=<?php echo $compte['id']; ?>"
                                   class="btn btn-sm btn-<?php echo $compte['statut'] === 'actif' ? 'warning' : 'success'; ?>"
                                   onclick="return confirm('Confirmer le changement de statut ?')">
                                    <?php echo $compte['statut'] === 'actif' ? 'Suspendre' : 'Activer'; ?>
                                </a>
                                <button class="btn btn-sm btn-info" onclick="showNegatifForm(<?php echo $compte['id']; ?>, '<?php echo e($compte['numero_compte']); ?>', <?php echo $compte['negatif_autorise']; ?>)">
                                    Négatif autorisé
                                </button>
                                <?php if ($compte['statut'] !== 'cloture'): ?>
                                    <a href="?action=cloture&id=<?php echo $compte['id']; ?>"
                                       class="btn btn-sm btn-danger"
                                       onclick="return confirm('Attention : La clôture d\'un compte est irréversible. Le solde doit être à zéro ou en négatif. Confirmer la clôture ?')">
                                        Clôturer
                                    </a>
                                <?php else: ?>
                                    <span class="badge badge-secondary">Clôturé</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>

    <!-- Modal de modification du négatif autorisé -->
    <div id="negatif-modal" class="modal" style="display: none;">
        <div class="modal-content">
            <h2>Modifier le négatif autorisé</h2>
            <p>Compte : <strong id="negatif-compte-numero"></strong></p>
            <form method="POST" action="">
                <input type="hidden" name="action" value="update_negatif">
                <input type="hidden" name="compte_id" id="negatif-compte-id">

                <div class="form-group">
                    <label for="negatif_autorise_modal">Négatif autorisé (€) *</label>
                    <input type="number" id="negatif_autorise_modal" name="negatif_autorise" class="form-control" step="0.01" min="0" required>
                    <small class="form-text">Mettre 0 pour révoquer le négatif autorisé.</small>
                </div>

                <div class="form-actions">
                    <button type="submit" class="btn btn-primary">Modifier</button>
                    <button type="button" class="btn btn-secondary" onclick="hideNegatifForm()">Annuler</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function showNegatifForm(compteId, numeroCompte, negatifActuel) {
            document.getElementById('negatif-compte-id').value = compteId;
            document.getElementById('negatif-compte-numero').textContent = numeroCompte;
            document.getElementById('negatif_autorise_modal').value = negatifActuel;
            document.getElementById('negatif-modal').style.display = 'flex';
        }

        function hideNegatifForm() {
            document.getElementById('negatif-modal').style.display = 'none';
        }
    </script>

<?php
function getStatutBadgeClassAdmin($statut) {
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