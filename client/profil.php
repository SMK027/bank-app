<?php
require_once '../config/config.php';
requireClient();

$userId = $_SESSION['user_id'];
$db = getDB();

// Traitement de la modification du profil
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_profile') {
    $email = $_POST['email'] ?? '';
    $telephone = $_POST['telephone'] ?? '';
    $adresse = $_POST['adresse'] ?? '';
    
    if (empty($email)) {
        setFlashMessage('L\'email est obligatoire.', 'danger');
    } else {
        try {
            $stmt = $db->prepare("UPDATE users SET email = ?, telephone = ?, adresse = ? WHERE id = ?");
            $stmt->execute([$email, $telephone, $adresse, $userId]);
            
            $_SESSION['email'] = $email;
            
            setFlashMessage('Profil mis à jour avec succès.', 'success');
            logActivity($userId, 'Modification profil', 'Mise à jour des informations personnelles');
        } catch (PDOException $e) {
            setFlashMessage('Erreur lors de la mise à jour du profil.', 'danger');
        }
    }
    
    redirect(BASE_URL . '/client/profil.php');
}

// Traitement du changement de mot de passe
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'change_password') {
    $currentPassword = $_POST['current_password'] ?? '';
    $newPassword = $_POST['new_password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';
    
    if (empty($currentPassword) || empty($newPassword) || empty($confirmPassword)) {
        setFlashMessage('Veuillez remplir tous les champs.', 'danger');
    } elseif ($newPassword !== $confirmPassword) {
        setFlashMessage('Les mots de passe ne correspondent pas.', 'danger');
    } elseif (strlen($newPassword) < 6) {
        setFlashMessage('Le mot de passe doit contenir au moins 6 caractères.', 'danger');
    } else {
        // Vérifier le mot de passe actuel
        $stmt = $db->prepare("SELECT password_hash FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        $user = $stmt->fetch();
        
        if (password_verify($currentPassword, $user['password_hash'])) {
            $newPasswordHash = password_hash($newPassword, PASSWORD_DEFAULT);
            
            $stmt = $db->prepare("UPDATE users SET password_hash = ? WHERE id = ?");
            $stmt->execute([$newPasswordHash, $userId]);
            
            setFlashMessage('Mot de passe modifié avec succès.', 'success');
            logActivity($userId, 'Changement mot de passe', 'Mot de passe modifié');
        } else {
            setFlashMessage('Mot de passe actuel incorrect.', 'danger');
        }
    }
    
    redirect(BASE_URL . '/client/profil.php');
}

// Récupération des informations de l'utilisateur
$stmt = $db->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$userId]);
$user = $stmt->fetch();

$pageTitle = 'Mon profil';
include 'includes/header.php';
?>

<div class="page-content">
    <h1>Mon profil</h1>
    
    <?php
    $flash = getFlashMessage();
    if ($flash):
    ?>
        <div class="alert alert-<?php echo e($flash['type']); ?>">
            <?php echo e($flash['message']); ?>
        </div>
    <?php endif; ?>
    
    <div class="profile-sections">
        <div class="profile-section">
            <h2>Informations personnelles</h2>
            <form method="POST" action="">
                <input type="hidden" name="action" value="update_profile">
                
                <div class="form-group">
                    <label>Nom d'utilisateur</label>
                    <input type="text" class="form-control" value="<?php echo e($user['username']); ?>" disabled>
                    <small class="form-text">Le nom d'utilisateur ne peut pas être modifié.</small>
                </div>
                
                <div class="form-group">
                    <label>Nom</label>
                    <input type="text" class="form-control" value="<?php echo e($user['nom']); ?>" disabled>
                </div>
                
                <div class="form-group">
                    <label>Prénom</label>
                    <input type="text" class="form-control" value="<?php echo e($user['prenom']); ?>" disabled>
                </div>
                
                <div class="form-group">
                    <label for="email">Email *</label>
                    <input type="email" id="email" name="email" class="form-control" 
                           value="<?php echo e($user['email']); ?>" required>
                </div>
                
                <div class="form-group">
                    <label for="telephone">Téléphone</label>
                    <input type="tel" id="telephone" name="telephone" class="form-control" 
                           value="<?php echo e($user['telephone'] ?? ''); ?>">
                </div>
                
                <div class="form-group">
                    <label for="adresse">Adresse</label>
                    <textarea id="adresse" name="adresse" class="form-control" rows="3"><?php echo e($user['adresse'] ?? ''); ?></textarea>
                </div>
                
                <button type="submit" class="btn btn-primary">Mettre à jour</button>
            </form>
        </div>
        
        <div class="profile-section">
            <h2>Changer le mot de passe</h2>
            <form method="POST" action="">
                <input type="hidden" name="action" value="change_password">
                
                <div class="form-group">
                    <label for="current_password">Mot de passe actuel *</label>
                    <input type="password" id="current_password" name="current_password" class="form-control" required>
                </div>
                
                <div class="form-group">
                    <label for="new_password">Nouveau mot de passe *</label>
                    <input type="password" id="new_password" name="new_password" class="form-control" required>
                    <small class="form-text">Minimum 6 caractères.</small>
                </div>
                
                <div class="form-group">
                    <label for="confirm_password">Confirmer le mot de passe *</label>
                    <input type="password" id="confirm_password" name="confirm_password" class="form-control" required>
                </div>
                
                <button type="submit" class="btn btn-primary">Changer le mot de passe</button>
            </form>
        </div>
        
        <div class="profile-section">
            <h2>Informations du compte</h2>
            <table class="info-table">
                <tr>
                    <th>Date de création</th>
                    <td><?php echo formatDateTime($user['date_creation']); ?></td>
                </tr>
                <tr>
                    <th>Dernière connexion</th>
                    <td><?php echo $user['derniere_connexion'] ? formatDateTime($user['derniere_connexion']) : 'Jamais'; ?></td>
                </tr>
                <tr>
                    <th>Statut</th>
                    <td>
                        <span class="badge badge-<?php echo $user['statut'] === 'actif' ? 'success' : 'warning'; ?>">
                            <?php echo e(ucfirst($user['statut'])); ?>
                        </span>
                    </td>
                </tr>
            </table>
        </div>
    </div>
</div>

<?php include 'includes/footer.php'; ?>
