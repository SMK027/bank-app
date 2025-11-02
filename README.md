# Application de Gestion Bancaire

Application web complète de gestion bancaire développée en PHP, MySQL, HTML, CSS et JavaScript.

## Fonctionnalités

### Espace Client
- **Dashboard** : Vue synthétique des comptes, solde total, budget
- **Gestion des comptes** : Consultation des comptes (propriétaire + procurations)
- **Opérations** : Enregistrement manuel d'opérations bancaires
- **Recherche** : Filtrage des opérations par nature, destinataire et montant
- **Profil** : Modification des informations personnelles
- **Messagerie** : Consultation et envoi de messages au personnel
- **Budget** : Affichage et gestion du budget
- **Crédits** : Consultation des crédits avec échéances

### Espace Administrateur
- **Dashboard** : Statistiques globales et alertes
- **Gestion des clients** : Création, modification, suspension de comptes clients
- **Gestion des comptes** : Création, suspension, gestion du négatif autorisé
- **Opérations** : Crédit/débit sur les comptes
- **Crédits** : Octroi et gestion des crédits avec échéances
- **Procurations** : Création et révocation de procurations
- **Messagerie** : Consultation des messages des clients
- **Statistiques** : Bilans et rapports détaillés
- **Réinitialisation** : Réinitialisation des mots de passe clients

### Fonctionnalités de sécurité et conformité
- **Sécurité** : Mots de passe hashés, sessions sécurisées, protection CSRF
- **RGPD** : Notifications automatiques pour toute modification de compte
- **Alertes** : Avertissements automatiques en cas de négatif prolongé
- **Traçabilité** : Logs d'activité pour toutes les opérations importantes

## Prérequis

- **Serveur web** : Apache 2.4+ ou Nginx
- **PHP** : Version 7.4 ou supérieure
- **Base de données** : MySQL 5.7+ ou MariaDB 10.3+
- **Extensions PHP** : PDO, pdo_mysql, mbstring

## Installation

### 1. Préparation de l'environnement

Assurez-vous que votre serveur web (Apache/Nginx) et PHP sont installés et configurés.

### 2. Configuration de la base de données

1. Créez une base de données MySQL/MariaDB :
```sql
CREATE DATABASE bank_app CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

2. Créez un utilisateur et accordez-lui les privilèges :
```sql
CREATE USER 'bank_user'@'localhost' IDENTIFIED BY 'votre_mot_de_passe';
GRANT ALL PRIVILEGES ON bank_app.* TO 'bank_user'@'localhost';
FLUSH PRIVILEGES;
```

3. Importez le schéma de la base de données :
```bash
mysql -u bank_user -p bank_app < sql/schema.sql
```

### 3. Configuration de l'application

1. Éditez le fichier `config/database.php` et modifiez les paramètres de connexion :
```php
define('DB_HOST', 'localhost');
define('DB_NAME', 'bank_app');
define('DB_USER', 'bank_user');
define('DB_PASS', 'votre_mot_de_passe');
```

2. Éditez le fichier `config/config.php` et modifiez l'URL de base :
```php
define('BASE_URL', 'http://votre-domaine.com/bank_app');
```

### 4. Déploiement

1. Copiez tous les fichiers dans le répertoire de votre serveur web :
```bash
cp -r bank_app /var/www/html/
```

2. Configurez les permissions :
```bash
chmod -R 755 /var/www/html/bank_app
chown -R www-data:www-data /var/www/html/bank_app
```

### 5. Configuration Apache (optionnel)

Créez un fichier `.htaccess` à la racine si nécessaire :
```apache
<IfModule mod_rewrite.c>
    RewriteEngine On
    RewriteBase /bank_app/
</IfModule>

# Protection des fichiers sensibles
<FilesMatch "^(config|includes)">
    Order allow,deny
    Deny from all
</FilesMatch>
```

## Comptes par défaut

Après l'installation, vous pouvez vous connecter avec les comptes suivants :

### Administrateur
- **Nom d'utilisateur** : `admin`
- **Mot de passe** : `admin123`

### Client de test
- **Nom d'utilisateur** : `client_test`
- **Mot de passe** : `client123`

⚠️ **Important** : Changez immédiatement ces mots de passe après la première connexion !

## Structure des fichiers

```
bank_app/
├── config/
│   ├── database.php          # Configuration BDD
│   └── config.php            # Configuration générale
├── includes/
│   ├── functions.php         # Fonctions utilitaires
│   ├── auth.php              # Gestion authentification
│   └── db.php                # Connexion BDD
├── assets/
│   ├── css/
│   │   └── style.css         # Styles globaux
│   └── js/
│       └── main.js           # Scripts JavaScript
├── client/
│   ├── index.php             # Dashboard client
│   ├── comptes.php           # Liste des comptes
│   ├── operations.php        # Gestion opérations
│   ├── recherche.php         # Recherche d'opérations
│   ├── profil.php            # Profil utilisateur
│   └── messages.php          # Messagerie
├── admin/
│   ├── index.php             # Dashboard admin
│   ├── clients.php           # Gestion clients
│   ├── comptes.php           # Gestion comptes
│   ├── credits.php           # Gestion crédits
│   ├── procurations.php      # Gestion procurations
│   ├── messages.php          # Consultation messages
│   ├── statistiques.php      # Statistiques globales
│   └── operations.php        # Opérations admin
├── sql/
│   └── schema.sql            # Schéma de la base de données
├── login.php                 # Page de connexion
├── logout.php                # Déconnexion
└── README.md                 # Documentation
```

## Utilisation

### Pour les clients

1. Connectez-vous avec vos identifiants
2. Consultez vos comptes et votre solde sur le dashboard
3. Enregistrez des opérations manuellement
4. Recherchez des opérations selon vos critères
5. Modifiez vos informations personnelles
6. Envoyez des messages à l'administration

### Pour les administrateurs

1. Connectez-vous avec les identifiants administrateur
2. Créez et gérez les comptes clients
3. Créez et gérez les comptes bancaires
4. Effectuez des opérations de crédit/débit
5. Octroyez des crédits avec échéances
6. Créez et révoquez des procurations
7. Consultez les statistiques et les rapports
8. Répondez aux messages des clients

## Sécurité

- Les mots de passe sont hashés avec `password_hash()` (bcrypt)
- Les sessions sont sécurisées avec timeout automatique (30 minutes)
- Protection CSRF sur tous les formulaires
- Validation des entrées utilisateur
- Requêtes préparées pour éviter les injections SQL
- Séparation stricte des rôles (client/admin)

## Maintenance

### Sauvegarde de la base de données

```bash
mysqldump -u bank_user -p bank_app > backup_$(date +%Y%m%d).sql
```

### Restauration de la base de données

```bash
mysql -u bank_user -p bank_app < backup_20240101.sql
```

### Nettoyage des logs

Les logs d'activité peuvent devenir volumineux. Pour nettoyer les logs de plus de 6 mois :

```sql
DELETE FROM logs_activite WHERE date_action < DATE_SUB(NOW(), INTERVAL 6 MONTH);
```

## Dépannage

### Erreur de connexion à la base de données

Vérifiez les paramètres dans `config/database.php` et assurez-vous que :
- Le serveur MySQL est démarré
- Les identifiants sont corrects
- L'utilisateur a les privilèges nécessaires

### Page blanche

Activez l'affichage des erreurs dans PHP :
```php
error_reporting(E_ALL);
ini_set('display_errors', 1);
```

### Session expirée trop rapidement

Modifiez la constante `SESSION_TIMEOUT` dans `config/config.php` :
```php
define('SESSION_TIMEOUT', 3600); // 1 heure
```

## Support

Pour toute question ou problème, consultez la documentation ou contactez l'équipe de développement.

## Licence

Cette application est fournie à des fins éducatives et de démonstration.

## Version

Version 1.0.0 - Janvier 2024
