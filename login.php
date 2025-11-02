<?php
require_once 'config/config.php';

// Si déjà connecté, rediriger vers le dashboard approprié
if (isLoggedIn()) {
    if (isAdmin()) {
        redirect(BASE_URL . '/admin/index.php');
    } else {
        redirect(BASE_URL . '/client/index.php');
    }
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';
    
    if (empty($username) || empty($password)) {
        $error = 'Veuillez remplir tous les champs.';
    } else {
        if (loginUser($username, $password)) {
            // Redirection selon le rôle
            if (isAdmin()) {
                redirect(BASE_URL . '/admin/index.php');
            } else {
                redirect(BASE_URL . '/client/index.php');
            }
        } else {
            $error = 'Identifiants incorrects ou compte inactif.';
        }
    }
}

$timeout = isset($_GET['timeout']) ? true : false;
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Connexion - <?php echo APP_NAME; ?></title>
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/style.css">
</head>
<body class="login-page">
    <div class="login-container">
        <div class="login-box">
            <h1><?php echo APP_NAME; ?></h1>
            <h2>Connexion</h2>
            
            <?php if ($timeout): ?>
                <div class="alert alert-warning">
                    Votre session a expiré. Veuillez vous reconnecter.
                </div>
            <?php endif; ?>
            
            <?php if ($error): ?>
                <div class="alert alert-danger">
                    <?php echo e($error); ?>
                </div>
            <?php endif; ?>
            
            <form method="POST" action="">
                <div class="form-group">
                    <label for="username">Nom d'utilisateur</label>
                    <input type="text" id="username" name="username" class="form-control" required autofocus>
                </div>
                
                <div class="form-group">
                    <label for="password">Mot de passe</label>
                    <input type="password" id="password" name="password" class="form-control" required>
                </div>
                
                <button type="submit" class="btn btn-primary btn-block">Se connecter</button>
            </form>
        </div>
    </div>
</body>
</html>
