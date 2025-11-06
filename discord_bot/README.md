# Bot Discord Bank App

Bot Discord pour interagir avec l'application bancaire Bank App.

## Fonctionnalités

### Authentification
- `/link` - Lier votre compte bancaire à Discord
- `/unlink` - Délier votre compte Discord
- `/status` - Vérifier le statut de votre liaison

### Consultation
- `/accounts` - Afficher tous vos comptes bancaires
- `/balance [compte_id]` - Afficher le solde d'un compte
- `/operations <compte_id> [limite]` - Afficher les dernières opérations
- `/stats` - Afficher vos statistiques bancaires

### Opérations
- `/operation` - Enregistrer une nouvelle opération bancaire
- `/search` - Rechercher des opérations selon des critères

## Installation

### Prérequis
- Python 3.8 ou supérieur
- Un compte Discord Developer avec un bot créé
- L'application Bank App installée et configurée

### Configuration

1. **Créer une application Discord**
   - Allez sur https://discord.com/developers/applications
   - Créez une nouvelle application
   - Dans l'onglet "Bot", créez un bot et copiez le token
   - Dans l'onglet "OAuth2", notez le Client ID et Client Secret
   - Activez les intents "Server Members Intent" et "Message Content Intent"

2. **Configurer les variables d'environnement**
   ```bash
   cp .env .env
   nano .env
   ```
   
   Remplissez les valeurs:
   ```
   DISCORD_BOT_TOKEN=votre_token_bot
   DISCORD_CLIENT_ID=votre_client_id
   DISCORD_CLIENT_SECRET=votre_client_secret
   API_BASE_URL=https://votre-domaine.com/api
   API_BOT_TOKEN=votre_token_secret_bot
   ```

3. **Installer les dépendances**
   ```bash
   pip install -r requirements.txt
   ```

4. **Configurer l'API Backend**
   
   Dans le fichier `api/config.php` du backend, assurez-vous que:
   - `DISCORD_CLIENT_ID` correspond à votre Client ID
   - `DISCORD_CLIENT_SECRET` correspond à votre Client Secret
   - `DISCORD_BOT_TOKEN` correspond à `API_BOT_TOKEN` du bot

## Lancement

```bash
python bot.py
```

Le bot se connectera à Discord et synchronisera les commandes slash.

## Inviter le bot sur votre serveur

1. Allez sur https://discord.com/developers/applications
2. Sélectionnez votre application
3. Dans l'onglet "OAuth2" > "URL Generator"
4. Cochez les scopes:
   - `bot`
   - `applications.commands`
5. Cochez les permissions:
   - `Send Messages`
   - `Use Slash Commands`
   - `Read Message History`
6. Copiez l'URL générée et ouvrez-la dans votre navigateur
7. Sélectionnez le serveur où ajouter le bot

## Utilisation

### Première utilisation

1. Tapez `/link` dans Discord
2. Suivez le lien pour vous connecter à votre compte bancaire
3. Dans votre profil sur le site web, cliquez sur "Lier mon compte Discord"
4. Autorisez l'application Discord
5. Retournez sur Discord et utilisez `/status` pour vérifier la liaison

### Consulter vos comptes

```
/accounts
```

Affiche tous vos comptes avec leurs soldes.

### Afficher le solde

```
/balance
/balance compte_id:123
```

Sans argument, affiche tous les comptes. Avec un ID, affiche le solde détaillé d'un compte.

### Voir les opérations

```
/operations compte_id:123 limite:10
```

Affiche les 10 dernières opérations du compte.

### Enregistrer une opération

```
/operation compte_id:123 type_operation:credit montant:100.50 description:Salaire
```

Enregistre une nouvelle opération. Une confirmation sera demandée.

### Rechercher des opérations

```
/search type_operation:virement nature:loyer limite:20
```

Recherche des opérations selon des critères.

### Voir les statistiques

```
/stats
```

Affiche vos statistiques bancaires du mois en cours.

## Sécurité

- Toutes les commandes sont en mode "ephemeral" (visibles uniquement par vous)
- Les tokens JWT expirent après 1 heure
- Les opérations sensibles nécessitent une confirmation
- Le bot ne stocke aucune donnée bancaire
- La liaison peut être révoquée à tout moment avec `/unlink`

## Logs

Les logs sont enregistrés dans le fichier `bot.log`.

## Dépannage

### Le bot ne répond pas
- Vérifiez que le bot est en ligne
- Vérifiez les logs dans `bot.log`
- Assurez-vous que les commandes sont synchronisées

### Erreur "Compte non lié"
- Utilisez `/link` pour lier votre compte
- Vérifiez que vous êtes connecté sur le site web
- Vérifiez que l'OAuth2 Discord est correctement configuré

### Erreur API
- Vérifiez que l'API backend est accessible
- Vérifiez l'URL dans `API_BASE_URL`
- Vérifiez que `API_BOT_TOKEN` correspond au backend

## Support

Pour toute question ou problème, consultez la documentation du projet principal.
