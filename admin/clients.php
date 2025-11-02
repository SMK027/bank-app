<?php
require_once '../config/config.php';
requireAdmin();

$db = getDB();

// Traitement de création de client ou admin
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create_user') {
    $role = $_POST['role'] ?? 'client';
    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';
    $email = $_POST['email'] ?? '';
    $nom = $_POST['nom'] ?? '';
    $prenom = $_POST['prenom'] ?? '';
    $telephone = $_POST['telephone'] ?? '';
    $adresse = $_POST['adresse'] ?? '';
    
    if (empty($username) || empty($password) || empty($email) || empty($nom) || empty($prenom)) {
        setFlashMessage('Veuillez remplir tous les champs obligatoires.', 'danger');
    } else {
        try {
            $passwordHash = password_hash($password, PASSWORD_DEFAULT);
            
            $stmt = $db->prepare("INSERT INTO users (username, password_hash, email, nom, prenom, telephone, adresse, role) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$username, $passwordHash, $email, $nom, $prenom, $telephone, $adresse, $role]);
            
            $typeUser = $role === 'admin' ? 'Administrateur' : 'Client';
            setFlashMessage("$typeUser créé avec succès.", 'success');
            logActivity($_SESSION['user_id'], "Création $typeUser", "Username: $username");
        } catch (PDOException $e) {
            if ($e->getCode() == 23000) {
                setFlashMessage('Ce nom d\'utilisateur ou email existe déjà.', 'danger');
            } else {
                setFlashMessage('Erreur lors de la création du client.', 'danger');
            }
        }
    }
    
    redirect(BASE_URL . '/admin/clients.php');
}

// Traitement de suspension/activation
if (isset($_GET['action']) && $_GET['action'] === 'toggle_status' && isset($_GET['id'])) {
    $clientId = $_GET['id'];
    
    $stmt = $db->prepare("SELECT statut, username FROM users WHERE id = ? AND role = 'client'");
    $stmt->execute([$clientId]);
    $client = $stmt->fetch();
    
    if ($client) {
        $nouveauStatut = $client['statut'] === 'actif' ? 'suspendu' : 'actif';
        
        $stmt = $db->prepare("UPDATE users SET statut = ? WHERE id = ?");
        $stmt->execute([$nouveauStatut, $clientId]);
        
        // Notification RGPD
        envoyerNotificationRGPD($clientId, 'Modification du statut', "Votre compte a été " . ($nouveauStatut === 'actif' ? 'activé' : 'suspendu'));
        
        setFlashMessage('Statut du client modifié avec succès.', 'success');
        logActivity($_SESSION['user_id'], 'Modification statut client', "Client: {$client['username']}, Nouveau statut: $nouveauStatut");
    }
    
    redirect(BASE_URL . '/admin/clients.php');
}

// Traitement de réinitialisation de mot de passe
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'reset_password') {
    $clientId = $_POST['client_id'] ?? 0;
    $newPassword = $_POST['new_password'] ?? '';
    
    if (empty($newPassword) || strlen($newPassword) < 6) {
        setFlashMessage('Le mot de passe doit contenir au moins 6 caractères.', 'danger');
    } else {
        $stmt = $db->prepare("SELECT username, role FROM users WHERE id = ?");
        $stmt->execute([$clientId]);
        $user = $stmt->fetch();
        
        if ($user) {
            $passwordHash = password_hash($newPassword, PASSWORD_DEFAULT);
            
            $stmt = $db->prepare("UPDATE users SET password_hash = ? WHERE id = ?");
            $stmt->execute([$passwordHash, $clientId]);
            
            // Notification RGPD uniquement pour les clients
            if ($user['role'] === 'client') {
                envoyerNotificationRGPD($clientId, 'Réinitialisation du mot de passe', 'Votre mot de passe a été réinitialisé par un administrateur.');
            }
            
            setFlashMessage('Mot de passe réinitialisé avec succès.', 'success');
            logActivity($_SESSION['user_id'], 'Réinitialisation mot de passe', "Utilisateur: {$user['username']}");
        }
    }
    
    redirect(BASE_URL . '/admin/clients.php');
}

// Récupération de tous les clients
$stmt = $db->query("SELECT * FROM users WHERE role = 'client' ORDER BY date_creation DESC");
$clients = $stmt->fetchAll();

// Récupération de tous les administrateurs
$stmt = $db->query("SELECT * FROM users WHERE role = 'admin' ORDER BY date_creation DESC");
$admins = $stmt->fetchAll();

$pageTitle = 'Gestion des clients';
include 'includes/header.php';
?>

<div class="page-content">
    <h1>Gestion des clients</h1>
    
    <?php
    $flash = getFlashMessage();
    if ($flash):
    ?>
        <div class="alert alert-<?php echo e($flash['type']); ?>">
            <?php echo e($flash['message']); ?>
        </div>
    <?php endif; ?>
    
    <div class="page-actions">
        <button class="btn btn-primary" onclick="showCreateForm('client')">
            Créer un nouveau client
        </button>
        <button class="btn btn-success" onclick="showCreateForm('admin')">
            Créer un administrateur
        </button>
    </div>
    
    <div id="create-user-form" class="form-container" style="display: none;">
        <h2 id="form-title">Nouvel utilisateur</h2>
        <form method="POST" action="">
            <input type="hidden" name="action" value="create_user">
            <input type="hidden" name="role" id="user-role" value="client">
            
            <div class="form-row">
                <div class="form-group">
                    <label for="username">Nom d'utilisateur *</label>
                    <input type="text" id="username" name="username" class="form-control" required>
                </div>
                
                <div class="form-group">
                    <label for="password">Mot de passe *</label>
                    <input type="password" id="password" name="password" class="form-control" required>
                </div>
            </div>
            
            <div class="form-row">
                <div class="form-group">
                    <label for="nom">Nom *</label>
                    <input type="text" id="nom" name="nom" class="form-control" required>
                </div>
                
                <div class="form-group">
                    <label for="prenom">Prénom *</label>
                    <input type="text" id="prenom" name="prenom" class="form-control" required>
                </div>
            </div>
            
            <div class="form-group">
                <label for="email">Email *</label>
                <input type="email" id="email" name="email" class="form-control" required>
            </div>
            
            <div class="form-group">
                <label for="telephone">Téléphone</label>
                <input type="tel" id="telephone" name="telephone" class="form-control">
            </div>
            
            <div class="form-group">
                <label for="adresse">Adresse</label>
                <textarea id="adresse" name="adresse" class="form-control" rows="3"></textarea>
            </div>
            
            <div class="form-actions">
                <button type="submit" class="btn btn-primary">Créer</button>
                <button type="button" class="btn btn-secondary" onclick="hideCreateForm()">Annuler</button>
            </div>
        </form>
    </div>
    
    <div class="clients-list">
        <h2>Liste des clients (<?php echo count($clients); ?>)</h2>
        
        <?php if (empty($clients)): ?>
            <p>Aucun client enregistré.</p>
        <?php else: ?>
            <table class="table">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Nom d'utilisateur</th>
                        <th>Nom complet</th>
                        <th>Email</th>
                        <th>Téléphone</th>
                        <th>Statut</th>
                        <th>Date création</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($clients as $client): ?>
                    <tr>
                        <td><?php echo $client['id']; ?></td>
                        <td><?php echo e($client['username']); ?></td>
                        <td><?php echo e($client['prenom'] . ' ' . $client['nom']); ?></td>
                        <td><?php echo e($client['email']); ?></td>
                        <td><?php echo e($client['telephone'] ?? '-'); ?></td>
                        <td>
                            <span class="badge badge-<?php echo $client['statut'] === 'actif' ? 'success' : 'warning'; ?>">
                                <?php echo e(ucfirst($client['statut'])); ?>
                            </span>
                        </td>
                        <td><?php echo formatDate($client['date_creation']); ?></td>
                        <td class="actions">
                            <a href="?action=toggle_status&id=<?php echo $client['id']; ?>" 
                               class="btn btn-sm btn-<?php echo $client['statut'] === 'actif' ? 'warning' : 'success'; ?>"
                               onclick="return confirm('Confirmer le changement de statut ?')">
                                <?php echo $client['statut'] === 'actif' ? 'Suspendre' : 'Activer'; ?>
                            </a>
                            <button class="btn btn-sm btn-info" onclick="showResetPasswordForm(<?php echo $client['id']; ?>, '<?php echo e($client['username']); ?>')">
                                Réinitialiser MDP
                            </button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
    
    <div class="admins-list" style="margin-top: 40px;">
        <h2>Liste des administrateurs (<?php echo count($admins); ?>)</h2>
        
        <?php if (empty($admins)): ?>
            <p>Aucun administrateur enregistré.</p>
        <?php else: ?>
            <table class="table">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Nom d'utilisateur</th>
                        <th>Nom complet</th>
                        <th>Email</th>
                        <th>Téléphone</th>
                        <th>Statut</th>
                        <th>Date création</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($admins as $admin): ?>
                    <tr>
                        <td><?php echo $admin['id']; ?></td>
                        <td><?php echo e($admin['username']); ?></td>
                        <td><?php echo e($admin['prenom'] . ' ' . $admin['nom']); ?></td>
                        <td><?php echo e($admin['email']); ?></td>
                        <td><?php echo e($admin['telephone'] ?? '-'); ?></td>
                        <td>
                            <span class="badge badge-<?php echo $admin['statut'] === 'actif' ? 'success' : 'warning'; ?>">
                                <?php echo e(ucfirst($admin['statut'])); ?>
                            </span>
                        </td>
                        <td><?php echo formatDate($admin['date_creation']); ?></td>
                        <td class="actions">
                            <?php if ($admin['id'] != $_SESSION['user_id']): ?>
                            <button class="btn btn-sm btn-info" onclick="showResetPasswordForm(<?php echo $admin['id']; ?>, '<?php echo e($admin['username']); ?>')">
                                Réinitialiser MDP
                            </button>
                            <?php else: ?>
                            <span class="badge badge-info">Vous</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>

<!-- Modal de réinitialisation de mot de passe -->
<div id="reset-password-modal" class="modal" style="display: none;">
    <div class="modal-content">
        <h2>Réinitialiser le mot de passe</h2>
        <p>Client : <strong id="reset-client-name"></strong></p>
        <form method="POST" action="">
            <input type="hidden" name="action" value="reset_password">
            <input type="hidden" name="client_id" id="reset-client-id">
            
            <div class="form-group">
                <label for="new_password">Nouveau mot de passe *</label>
                <input type="password" id="new_password" name="new_password" class="form-control" required>
            </div>
            
            <div class="form-actions">
                <button type="submit" class="btn btn-primary">Réinitialiser</button>
                <button type="button" class="btn btn-secondary" onclick="hideResetPasswordForm()">Annuler</button>
            </div>
        </form>
    </div>
</div>

<script>
function showResetPasswordForm(clientId, clientName) {
    document.getElementById('reset-client-id').value = clientId;
    document.getElementById('reset-client-name').textContent = clientName;
    document.getElementById('reset-password-modal').style.display = 'flex';
}

function hideResetPasswordForm() {
    document.getElementById('reset-password-modal').style.display = 'none';
}

function showCreateForm(role) {
    document.getElementById('user-role').value = role;
    if (role === 'admin') {
        document.getElementById('form-title').textContent = 'Nouvel administrateur';
    } else {
        document.getElementById('form-title').textContent = 'Nouveau client';
    }
    document.getElementById('create-user-form').style.display = 'block';
}

function hideCreateForm() {
    document.getElementById('create-user-form').style.display = 'none';
}
</script>

<?php include 'includes/footer.php'; ?>
