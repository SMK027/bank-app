<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo isset($pageTitle) ? e($pageTitle) . ' - ' : ''; ?><?php echo APP_NAME; ?></title>
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/style.css">
</head>
<body>
    <nav class="navbar">
        <div class="navbar-brand">
            <a href="<?php echo BASE_URL; ?>/client/index.php"><?php echo APP_NAME; ?></a>
        </div>
        <ul class="navbar-menu">
            <li><a href="<?php echo BASE_URL; ?>/client/index.php">Tableau de bord</a></li>
            <li><a href="<?php echo BASE_URL; ?>/client/comptes.php">Mes comptes</a></li>
            <li><a href="<?php echo BASE_URL; ?>/client/operations.php">Opérations</a></li>
            <li><a href="<?php echo BASE_URL; ?>/client/virements.php">Virements</a></li>
            <li><a href="<?php echo BASE_URL; ?>/client/prelevements.php">Prélèvements</a></li>
            <li><a href="<?php echo BASE_URL; ?>/client/recherche.php">Recherche</a></li>
            <li><a href="<?php echo BASE_URL; ?>/client/messages.php">
                Messages
                <?php 
                $unread = compterMessagesNonLus($_SESSION['user_id']);
                if ($unread > 0): 
                ?>
                    <span class="badge"><?php echo $unread; ?></span>
                <?php endif; ?>
            </a></li>
            <li><a href="<?php echo BASE_URL; ?>/client/profil.php">Profil</a></li>
            <li><a href="<?php echo BASE_URL; ?>/logout.php">Déconnexion</a></li>
        </ul>
    </nav>
    <div class="container">
