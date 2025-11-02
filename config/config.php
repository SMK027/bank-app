<?php
/**
 * Configuration générale de l'application
 */

// Démarrage de la session
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Fuseau horaire
date_default_timezone_set('Europe/Paris');

// Chemins de l'application
define('BASE_PATH', dirname(__DIR__));
define('INCLUDES_PATH', BASE_PATH . '/includes');
define('CONFIG_PATH', BASE_PATH . '/config');

// URL de base (à adapter selon votre configuration)
define('BASE_URL', 'http://localhost:8000');

// Paramètres de sécurité
define('SESSION_TIMEOUT', 1800); // 30 minutes en secondes

// Paramètres de l'application
define('APP_NAME', 'Gestion Bancaire');
define('APP_VERSION', '1.0.0');

// Durée avant alerte de négatif (en jours)
define('ALERTE_NEGATIF_JOURS', 0);

// Inclusion des fichiers nécessaires
require_once INCLUDES_PATH . '/db.php';
require_once INCLUDES_PATH . '/functions.php';
require_once INCLUDES_PATH . '/auth.php';

// Vérification du timeout de session
if (isset($_SESSION['user_id']) && isset($_SESSION['last_activity'])) {
    if (time() - $_SESSION['last_activity'] > SESSION_TIMEOUT) {
        session_unset();
        session_destroy();
        header('Location: ' . BASE_URL . '/login.php?timeout=1');
        exit();
    }
}
$_SESSION['last_activity'] = time();
