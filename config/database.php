<?php
/**
 * Configuration de la base de données
 * Modifiez ces paramètres selon votre environnement
 */

define('DB_HOST', 'smk027.fr');
define('DB_NAME', 'bank_app');
define('DB_USER', 'bank_user');
define('DB_PASS', 'G*2QEsvUHmm$$5k%9W$2');
define('DB_CHARSET', 'utf8mb4');

// Options PDO
define('PDO_OPTIONS', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
]);
