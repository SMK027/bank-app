# Guide d'installation - Application de Gestion Bancaire

Ce guide vous accompagne pas à pas dans l'installation de l'application de gestion bancaire.

## Table des matières

1. [Prérequis](#prérequis)
2. [Installation sur serveur local (XAMPP/WAMP)](#installation-sur-serveur-local)
3. [Installation sur serveur Linux](#installation-sur-serveur-linux)
4. [Configuration](#configuration)
5. [Premiers pas](#premiers-pas)
6. [Dépannage](#dépannage)

## Prérequis

### Logiciels requis

- **Serveur web** : Apache 2.4+ ou Nginx 1.18+
- **PHP** : Version 7.4 ou supérieure (PHP 8.0+ recommandé)
- **Base de données** : MySQL 5.7+ ou MariaDB 10.3+

### Extensions PHP requises

- `pdo`
- `pdo_mysql`
- `mbstring`
- `session`

Pour vérifier les extensions installées, créez un fichier `phpinfo.php` avec le contenu suivant :
```php
<?php phpinfo(); ?>
```

## Installation sur serveur local

### Avec XAMPP (Windows/Mac/Linux)

1. **Téléchargez et installez XAMPP** depuis https://www.apachefriends.org/

2. **Démarrez Apache et MySQL** depuis le panneau de contrôle XAMPP

3. **Extrayez l'application** dans le dossier `htdocs` de XAMPP :
   ```
   C:\xampp\htdocs\bank_app\  (Windows)
   /Applications/XAMPP/htdocs/bank_app/  (Mac)
   /opt/lampp/htdocs/bank_app/  (Linux)
   ```

4. **Créez la base de données** :
   - Ouvrez phpMyAdmin : http://localhost/phpmyadmin
   - Cliquez sur "Nouvelle base de données"
   - Nom : `bank_app`
   - Interclassement : `utf8mb4_unicode_ci`
   - Cliquez sur "Créer"

5. **Importez le schéma** :
   - Sélectionnez la base de données `bank_app`
   - Cliquez sur l'onglet "Importer"
   - Choisissez le fichier `sql/schema.sql`
   - Cliquez sur "Exécuter"

6. **Configurez l'application** :
   - Ouvrez `config/database.php`
   - Vérifiez les paramètres (par défaut pour XAMPP) :
     ```php
     define('DB_HOST', 'localhost');
     define('DB_NAME', 'bank_app');
     define('DB_USER', 'root');
     define('DB_PASS', '');
     ```
   - Ouvrez `config/config.php`
   - Modifiez l'URL de base :
     ```php
     define('BASE_URL', 'http://localhost/bank_app');
     ```

7. **Accédez à l'application** : http://localhost/bank_app

### Avec WAMP (Windows)

Les étapes sont similaires à XAMPP, avec les chemins suivants :
- Dossier web : `C:\wamp64\www\bank_app\`
- phpMyAdmin : http://localhost/phpmyadmin

## Installation sur serveur Linux

### Ubuntu/Debian

1. **Installez les prérequis** :
   ```bash
   sudo apt update
   sudo apt install apache2 mysql-server php php-mysql php-mbstring
   sudo systemctl start apache2
   sudo systemctl start mysql
   ```

2. **Configurez MySQL** :
   ```bash
   sudo mysql_secure_installation
   ```

3. **Créez la base de données** :
   ```bash
   sudo mysql -u root -p
   ```
   
   Dans le prompt MySQL :
   ```sql
   CREATE DATABASE bank_app CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
   CREATE USER 'bank_user'@'localhost' IDENTIFIED BY 'mot_de_passe_securise';
   GRANT ALL PRIVILEGES ON bank_app.* TO 'bank_user'@'localhost';
   FLUSH PRIVILEGES;
   EXIT;
   ```

4. **Importez le schéma** :
   ```bash
   mysql -u bank_user -p bank_app < sql/schema.sql
   ```

5. **Copiez les fichiers** :
   ```bash
   sudo cp -r bank_app /var/www/html/
   sudo chown -R www-data:www-data /var/www/html/bank_app
   sudo chmod -R 755 /var/www/html/bank_app
   ```

6. **Configurez l'application** :
   ```bash
   sudo nano /var/www/html/bank_app/config/database.php
   ```
   
   Modifiez les paramètres :
   ```php
   define('DB_HOST', 'localhost');
   define('DB_NAME', 'bank_app');
   define('DB_USER', 'bank_user');
   define('DB_PASS', 'mot_de_passe_securise');
   ```
   
   ```bash
   sudo nano /var/www/html/bank_app/config/config.php
   ```
   
   Modifiez l'URL :
   ```php
   define('BASE_URL', 'http://votre-domaine.com/bank_app');
   ```

7. **Configurez Apache (optionnel)** :
   
   Créez un Virtual Host :
   ```bash
   sudo nano /etc/apache2/sites-available/bank_app.conf
   ```
   
   Contenu :
   ```apache
   <VirtualHost *:80>
       ServerName bank.votre-domaine.com
       DocumentRoot /var/www/html/bank_app
       
       <Directory /var/www/html/bank_app>
           Options -Indexes +FollowSymLinks
           AllowOverride All
           Require all granted
       </Directory>
       
       ErrorLog ${APACHE_LOG_DIR}/bank_app_error.log
       CustomLog ${APACHE_LOG_DIR}/bank_app_access.log combined
   </VirtualHost>
   ```
   
   Activez le site :
   ```bash
   sudo a2ensite bank_app.conf
   sudo a2enmod rewrite
   sudo systemctl restart apache2
   ```

8. **Accédez à l'application** : http://votre-domaine.com/bank_app

### CentOS/RHEL

Les commandes sont similaires, mais utilisez `yum` ou `dnf` au lieu de `apt` :
```bash
sudo yum install httpd mariadb-server php php-mysqlnd php-mbstring
sudo systemctl start httpd
sudo systemctl start mariadb
```

## Configuration

### Configuration de la base de données

Éditez `config/database.php` :
```php
define('DB_HOST', 'localhost');        // Hôte de la base de données
define('DB_NAME', 'bank_app');         // Nom de la base de données
define('DB_USER', 'votre_utilisateur'); // Utilisateur MySQL
define('DB_PASS', 'votre_mot_de_passe'); // Mot de passe MySQL
define('DB_CHARSET', 'utf8mb4');       // Encodage (ne pas modifier)
```

### Configuration générale

Éditez `config/config.php` :
```php
// URL de base de l'application
define('BASE_URL', 'http://localhost/bank_app');

// Fuseau horaire
date_default_timezone_set('Europe/Paris');

// Timeout de session (en secondes)
define('SESSION_TIMEOUT', 1800); // 30 minutes

// Durée avant alerte de négatif (en jours)
define('ALERTE_NEGATIF_JOURS', 7);
```

### Configuration SSL (recommandé pour production)

1. Obtenez un certificat SSL (Let's Encrypt recommandé)
2. Décommentez les lignes HTTPS dans `.htaccess`
3. Modifiez `BASE_URL` pour utiliser `https://`

## Premiers pas

### Connexion initiale

Après l'installation, connectez-vous avec les comptes par défaut :

**Administrateur** :
- Nom d'utilisateur : `admin`
- Mot de passe : `admin123`

**Client de test** :
- Nom d'utilisateur : `client_test`
- Mot de passe : `client123`

### Sécurisation

⚠️ **IMPORTANT** : Changez immédiatement les mots de passe par défaut !

1. Connectez-vous en tant qu'administrateur
2. Allez dans "Clients"
3. Réinitialisez le mot de passe de l'administrateur
4. Réinitialisez le mot de passe du client de test

### Création de nouveaux utilisateurs

1. Connectez-vous en tant qu'administrateur
2. Allez dans "Clients"
3. Cliquez sur "Créer un nouveau client"
4. Remplissez le formulaire
5. Le client peut maintenant se connecter

### Création de comptes bancaires

1. Dans l'espace admin, allez dans "Comptes"
2. Cliquez sur "Créer un nouveau compte"
3. Sélectionnez le client
4. Choisissez le type de compte
5. Définissez le solde initial et le négatif autorisé

## Dépannage

### Erreur "Cannot connect to database"

**Cause** : Paramètres de connexion incorrects ou MySQL non démarré

**Solution** :
1. Vérifiez que MySQL est démarré
2. Vérifiez les paramètres dans `config/database.php`
3. Testez la connexion MySQL :
   ```bash
   mysql -u votre_utilisateur -p
   ```

### Page blanche (erreur 500)

**Cause** : Erreur PHP non affichée

**Solution** :
1. Activez l'affichage des erreurs temporairement :
   ```php
   // Au début de config/config.php
   error_reporting(E_ALL);
   ini_set('display_errors', 1);
   ```
2. Consultez les logs Apache/PHP
3. Vérifiez les permissions des fichiers

### "Session expired" trop fréquent

**Cause** : Timeout de session trop court

**Solution** :
Augmentez `SESSION_TIMEOUT` dans `config/config.php` :
```php
define('SESSION_TIMEOUT', 3600); // 1 heure
```

### Styles CSS ne s'affichent pas

**Cause** : Chemin BASE_URL incorrect

**Solution** :
Vérifiez et corrigez `BASE_URL` dans `config/config.php` :
```php
define('BASE_URL', 'http://localhost/bank_app');
// Sans slash à la fin !
```

### Erreur "Access denied" pour les fichiers config/

**Cause** : Protection .htaccess active

**Solution** :
C'est normal ! Les fichiers de configuration sont protégés. Si vous devez les modifier, utilisez FTP/SSH ou l'accès serveur direct.

### Problèmes de permissions (Linux)

**Solution** :
```bash
sudo chown -R www-data:www-data /var/www/html/bank_app
sudo chmod -R 755 /var/www/html/bank_app
```

## Support

Pour toute question supplémentaire :
1. Consultez le fichier README.md
2. Vérifiez les logs d'erreur
3. Contactez l'équipe de support

## Checklist de déploiement en production

- [ ] Changez tous les mots de passe par défaut
- [ ] Configurez SSL/HTTPS
- [ ] Désactivez l'affichage des erreurs PHP
- [ ] Configurez des sauvegardes automatiques de la base de données
- [ ] Restreignez l'accès aux fichiers sensibles
- [ ] Configurez un pare-feu
- [ ] Testez toutes les fonctionnalités
- [ ] Configurez la surveillance des logs
- [ ] Documentez les procédures de maintenance

Bonne installation !
