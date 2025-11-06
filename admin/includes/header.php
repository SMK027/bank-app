<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo isset($pageTitle) ? e($pageTitle) . ' - ' : ''; ?><?php echo APP_NAME; ?> - Administration</title>
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/style.css">
</head>
<body>
    <nav class="navbar navbar-admin">
        <div class="navbar-brand">
            <a href="<?php echo BASE_URL; ?>/admin/index.php"><?php echo APP_NAME; ?> - Admin</a>
        </div>
        <ul class="navbar-menu">
            <li><a href="<?php echo BASE_URL; ?>/admin/index.php">Tableau de bord</a></li>
            <li><a href="<?php echo BASE_URL; ?>/admin/clients.php">Clients</a></li>
            <li><a href="<?php echo BASE_URL; ?>/admin/comptes.php">Comptes</a></li>
            <li><a href="<?php echo BASE_URL; ?>/admin/operations.php">Opérations</a></li>
            <li><a href="<?php echo BASE_URL; ?>/admin/credits.php">Crédits</a></li>
            <li><a href="<?php echo BASE_URL; ?>/admin/prelevements.php">Prélèvements</a></li>
            <li><a href="<?php echo BASE_URL; ?>/admin/procurations.php">Procurations</a></li>
            <li><a href="<?php echo BASE_URL; ?>/admin/messages.php">
                Messages
                <?php 
                $unread = compterMessagesNonLus($_SESSION['user_id']);
                if ($unread > 0): 
                ?>
                    <span class="badge"><?php echo $unread; ?></span>
                <?php endif; ?>
            </a></li>
            <li><a href="<?php echo BASE_URL; ?>/admin/statistiques.php">Statistiques</a></li>
            <li><a href="<?php echo BASE_URL; ?>/logout.php">Déconnexion</a></li>
        </ul>
    </nav>
    <div class="container">
