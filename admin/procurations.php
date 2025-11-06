<?php
require_once '../config/config.php';
requireAdmin();

$db = getDB();

// Traitement de création de procuration
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create_procuration') {
    $compteId = $_POST['compte_id'] ?? 0;
    $userBeneficiaireId = $_POST['user_beneficiaire_id'] ?? 0;
    $dateDebut = $_POST['date_debut'] ?? date('Y-m-d');
    $dateFin = $_POST['date_fin'] ?? null;
    
    if ($compteId <= 0 || $userBeneficiaireId <= 0) {
        setFlashMessage('Veuillez remplir tous les champs obligatoires.', 'danger');
    } else {
        // Vérifier que le bénéficiaire n'est pas le propriétaire du compte
        $stmt = $db->prepare("SELECT user_id, numero_compte FROM comptes WHERE id = ?");
        $stmt->execute([$compteId]);
        $compte = $stmt->fetch();
        
        if ($compte && $compte['user_id'] == $userBeneficiaireId) {
            setFlashMessage('Le bénéficiaire ne peut pas être le propriétaire du compte.', 'danger');
        } else {
            $stmt = $db->prepare("INSERT INTO procurations (compte_id, user_beneficiaire_id, date_debut, date_fin) VALUES (?, ?, ?, ?)");
            $stmt->execute([$compteId, $userBeneficiaireId, $dateDebut, $dateFin ?: null]);
            
            // Notification RGPD au propriétaire du compte
            envoyerNotificationRGPD($compte['user_id'], 'Création de procuration', "Une procuration a été créée sur votre compte " . $compte['numero_compte']);
            
            // Notification au bénéficiaire
            envoyerNotificationRGPD($userBeneficiaireId, 'Procuration accordée', "Vous avez reçu une procuration sur le compte " . $compte['numero_compte']);
            
            setFlashMessage('Procuration créée avec succès.', 'success');
            logActivity($_SESSION['user_id'], 'Création procuration', "Compte ID: $compteId, Bénéficiaire ID: $userBeneficiaireId");
        }
    }
    
    redirect(BASE_URL . '/admin/procurations.php');
}

// Traitement de révocation de procuration
if (isset($_GET['action']) && $_GET['action'] === 'revoke' && isset($_GET['id'])) {
    $procurationId = $_GET['id'];
    
    $stmt = $db->prepare("
        SELECT p.*, c.numero_compte, c.user_id as proprietaire_id 
        FROM procurations p 
        INNER JOIN comptes c ON p.compte_id = c.id 
        WHERE p.id = ?
    ");
    $stmt->execute([$procurationId]);
    $procuration = $stmt->fetch();
    
    if ($procuration) {
        $stmt = $db->prepare("UPDATE procurations SET statut = 'revoquee' WHERE id = ?");
        $stmt->execute([$procurationId]);
        
        // Notification RGPD
        envoyerNotificationRGPD($procuration['proprietaire_id'], 'Révocation de procuration', "La procuration sur votre compte " . $procuration['numero_compte'] . " a été révoquée");
        envoyerNotificationRGPD($procuration['user_beneficiaire_id'], 'Procuration révoquée', "Votre procuration sur le compte " . $procuration['numero_compte'] . " a été révoquée");
        
        setFlashMessage('Procuration révoquée avec succès.', 'success');
        logActivity($_SESSION['user_id'], 'Révocation procuration', "Procuration ID: $procurationId");
    }
    
    redirect(BASE_URL . '/admin/procurations.php');
}

// Récupération de toutes les procurations
$stmt = $db->query("
    SELECT p.*, 
           c.numero_compte, 
           u1.nom as proprietaire_nom, u1.prenom as proprietaire_prenom,
           u2.nom as beneficiaire_nom, u2.prenom as beneficiaire_prenom
    FROM procurations p 
    INNER JOIN comptes c ON p.compte_id = c.id 
    INNER JOIN users u1 ON c.user_id = u1.id
    INNER JOIN users u2 ON p.user_beneficiaire_id = u2.id
    ORDER BY p.date_creation DESC
");
$procurations = $stmt->fetchAll();

// Récupération de tous les comptes actifs pour le formulaire
$stmt = $db->query("
    SELECT c.id, c.numero_compte, u.nom, u.prenom 
    FROM comptes c 
    INNER JOIN users u ON c.user_id = u.id 
    WHERE c.statut = 'actif' 
    ORDER BY u.nom, u.prenom
");
$comptes = $stmt->fetchAll();

// Récupération de tous les clients pour le formulaire
$stmt = $db->query("SELECT id, username, nom, prenom FROM users WHERE role = 'client' AND statut = 'actif' ORDER BY nom, prenom");
$clients = $stmt->fetchAll();

$pageTitle = 'Gestion des procurations';
include 'includes/header.php';
?>

<div class="page-content">
    <h1>Gestion des procurations</h1>
    
    <?php
    $flash = getFlashMessage();
    if ($flash):
    ?>
        <div class="alert alert-<?php echo e($flash['type']); ?>">
            <?php echo e($flash['message']); ?>
        </div>
    <?php endif; ?>
    
    <div class="page-actions">
        <button class="btn btn-primary" onclick="toggleForm('create-procuration-form')">
            Créer une procuration
        </button>
    </div>
    
    <div id="create-procuration-form" class="form-container" style="display: none;">
        <h2>Nouvelle procuration</h2>
        <form method="POST" action="">
            <input type="hidden" name="action" value="create_procuration">
            
            <div class="form-group">
                <label for="compte_id">Compte *</label>
                <select id="compte_id" name="compte_id" class="form-control" required>
                    <option value="">Sélectionner un compte</option>
                    <?php foreach ($comptes as $compte): ?>
                    <option value="<?php echo $compte['id']; ?>">
                        <?php echo e($compte['numero_compte'] . ' - ' . $compte['prenom'] . ' ' . $compte['nom']); ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="form-group">
                <label for="user_beneficiaire_id">Bénéficiaire *</label>
                <select id="user_beneficiaire_id" name="user_beneficiaire_id" class="form-control" required>
                    <option value="">Sélectionner un bénéficiaire</option>
                    <?php foreach ($clients as $client): ?>
                    <option value="<?php echo $client['id']; ?>">
                        <?php echo e($client['prenom'] . ' ' . $client['nom'] . ' (' . $client['username'] . ')'); ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="form-group">
                <label for="date_debut">Date de début *</label>
                <input type="date" id="date_debut" name="date_debut" class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
            </div>
            
            <div class="form-group">
                <label for="date_fin">Date de fin (optionnel)</label>
                <input type="date" id="date_fin" name="date_fin" class="form-control">
                <small class="form-text">Laisser vide pour une procuration sans date de fin.</small>
            </div>
            
            <div class="form-actions">
                <button type="submit" class="btn btn-primary">Créer</button>
                <button type="button" class="btn btn-secondary" onclick="toggleForm('create-procuration-form')">Annuler</button>
            </div>
        </form>
    </div>
    
    <div class="procurations-list">
        <h2>Liste des procurations (<?php echo count($procurations); ?>)</h2>
        
        <?php if (empty($procurations)): ?>
            <p>Aucune procuration enregistrée.</p>
        <?php else: ?>
            <table class="table">
                <thead>
                    <tr>
                        <th>Compte</th>
                        <th>Propriétaire</th>
                        <th>Bénéficiaire</th>
                        <th>Date début</th>
                        <th>Date fin</th>
                        <th>Statut</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($procurations as $procuration): ?>
                    <tr>
                        <td><?php echo e($procuration['numero_compte']); ?></td>
                        <td><?php echo e($procuration['proprietaire_prenom'] . ' ' . $procuration['proprietaire_nom']); ?></td>
                        <td><?php echo e($procuration['beneficiaire_prenom'] . ' ' . $procuration['beneficiaire_nom']); ?></td>
                        <td><?php echo formatDate($procuration['date_debut']); ?></td>
                        <td><?php echo $procuration['date_fin'] ? formatDate($procuration['date_fin']) : 'Indéterminée'; ?></td>
                        <td>
                            <span class="badge badge-<?php 
                                echo $procuration['statut'] === 'active' ? 'success' : 
                                    ($procuration['statut'] === 'revoquee' ? 'danger' : 'warning'); 
                            ?>">
                                <?php echo e(ucfirst($procuration['statut'])); ?>
                            </span>
                        </td>
                        <td class="actions">
                            <?php if ($procuration['statut'] === 'active'): ?>
                            <a href="?action=revoke&id=<?php echo $procuration['id']; ?>" 
                               class="btn btn-sm btn-danger"
                               onclick="return confirm('Confirmer la révocation de cette procuration ?')">
                                Révoquer
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

<?php include 'includes/footer.php'; ?>
