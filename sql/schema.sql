-- Script de création de la base de données pour l'application bancaire
-- Version: 1.0

CREATE DATABASE IF NOT EXISTS bank_app CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE bank_app;

-- Table des utilisateurs (clients et administrateurs)
CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) UNIQUE NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    email VARCHAR(100) UNIQUE NOT NULL,
    nom VARCHAR(100) NOT NULL,
    prenom VARCHAR(100) NOT NULL,
    telephone VARCHAR(20),
    adresse TEXT,
    role ENUM('client', 'admin') DEFAULT 'client',
    statut ENUM('actif', 'suspendu', 'inactif') DEFAULT 'actif',
    date_creation TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    derniere_connexion TIMESTAMP NULL,
    INDEX idx_username (username),
    INDEX idx_role (role),
    INDEX idx_statut (statut)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table des comptes bancaires
CREATE TABLE IF NOT EXISTS comptes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    numero_compte VARCHAR(20) UNIQUE NOT NULL,
    type_compte ENUM('courant', 'epargne', 'joint') DEFAULT 'courant',
    solde DECIMAL(15, 2) DEFAULT 0.00,
    negatif_autorise DECIMAL(10, 2) DEFAULT 0.00,
    statut ENUM('actif', 'suspendu', 'cloture') DEFAULT 'actif',
    date_creation TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    date_modification TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user_id (user_id),
    INDEX idx_numero_compte (numero_compte),
    INDEX idx_statut (statut)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table des opérations bancaires
CREATE TABLE IF NOT EXISTS operations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    compte_id INT NOT NULL,
    type_operation ENUM('debit', 'credit', 'virement', 'prelevement', 'depot', 'retrait') NOT NULL,
    montant DECIMAL(15, 2) NOT NULL,
    destinataire VARCHAR(255),
    nature VARCHAR(100),
    description TEXT,
    date_operation TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    solde_apres DECIMAL(15, 2),
    FOREIGN KEY (compte_id) REFERENCES comptes(id) ON DELETE CASCADE,
    INDEX idx_compte_id (compte_id),
    INDEX idx_date_operation (date_operation),
    INDEX idx_type_operation (type_operation),
    INDEX idx_nature (nature)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table des crédits
CREATE TABLE IF NOT EXISTS credits (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    montant_total DECIMAL(15, 2) NOT NULL,
    montant_restant DECIMAL(15, 2) NOT NULL,
    taux_interet DECIMAL(5, 2) DEFAULT 0.00,
    duree_mois INT NOT NULL,
    mensualite DECIMAL(10, 2) NOT NULL,
    date_debut DATE NOT NULL,
    date_fin DATE NOT NULL,
    statut ENUM('actif', 'termine', 'suspendu') DEFAULT 'actif',
    date_creation TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user_id (user_id),
    INDEX idx_statut (statut)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table des échéances de crédit
CREATE TABLE IF NOT EXISTS echeances_credit (
    id INT AUTO_INCREMENT PRIMARY KEY,
    credit_id INT NOT NULL,
    numero_echeance INT NOT NULL,
    montant DECIMAL(10, 2) NOT NULL,
    date_echeance DATE NOT NULL,
    statut ENUM('en_attente', 'payee', 'retard') DEFAULT 'en_attente',
    date_paiement TIMESTAMP NULL,
    FOREIGN KEY (credit_id) REFERENCES credits(id) ON DELETE CASCADE,
    INDEX idx_credit_id (credit_id),
    INDEX idx_date_echeance (date_echeance),
    INDEX idx_statut (statut)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table des procurations
CREATE TABLE IF NOT EXISTS procurations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    compte_id INT NOT NULL,
    user_beneficiaire_id INT NOT NULL,
    date_debut DATE NOT NULL,
    date_fin DATE,
    statut ENUM('active', 'revoquee', 'expiree') DEFAULT 'active',
    date_creation TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (compte_id) REFERENCES comptes(id) ON DELETE CASCADE,
    FOREIGN KEY (user_beneficiaire_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_compte_id (compte_id),
    INDEX idx_user_beneficiaire_id (user_beneficiaire_id),
    INDEX idx_statut (statut)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table des messages
CREATE TABLE IF NOT EXISTS messages (
    id INT AUTO_INCREMENT PRIMARY KEY,
    expediteur_id INT NOT NULL,
    destinataire_id INT NOT NULL,
    sujet VARCHAR(255) NOT NULL,
    contenu TEXT NOT NULL,
    lu BOOLEAN DEFAULT FALSE,
    date_envoi TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    date_lecture TIMESTAMP NULL,
    type_message ENUM('normal', 'alerte', 'notification') DEFAULT 'normal',
    FOREIGN KEY (expediteur_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (destinataire_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_expediteur_id (expediteur_id),
    INDEX idx_destinataire_id (destinataire_id),
    INDEX idx_lu (lu),
    INDEX idx_date_envoi (date_envoi)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table des budgets
CREATE TABLE IF NOT EXISTS budgets (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    categorie VARCHAR(100) NOT NULL,
    montant_max DECIMAL(10, 2) NOT NULL,
    montant_utilise DECIMAL(10, 2) DEFAULT 0.00,
    periode ENUM('mensuel', 'trimestriel', 'annuel') DEFAULT 'mensuel',
    date_debut DATE NOT NULL,
    date_fin DATE NOT NULL,
    actif BOOLEAN DEFAULT TRUE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user_id (user_id),
    INDEX idx_categorie (categorie)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table des alertes de négatif
CREATE TABLE IF NOT EXISTS alertes_negatif (
    id INT AUTO_INCREMENT PRIMARY KEY,
    compte_id INT NOT NULL,
    date_debut_negatif TIMESTAMP NOT NULL,
    montant DECIMAL(15, 2) NOT NULL,
    duree_jours INT DEFAULT 0,
    alerte_envoyee BOOLEAN DEFAULT FALSE,
    date_alerte TIMESTAMP NULL,
    resolu BOOLEAN DEFAULT FALSE,
    date_resolution TIMESTAMP NULL,
    FOREIGN KEY (compte_id) REFERENCES comptes(id) ON DELETE CASCADE,
    INDEX idx_compte_id (compte_id),
    INDEX idx_alerte_envoyee (alerte_envoyee),
    INDEX idx_resolu (resolu)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table des logs d'activité (traçabilité RGPD)
CREATE TABLE IF NOT EXISTS logs_activite (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    action VARCHAR(255) NOT NULL,
    details TEXT,
    ip_address VARCHAR(45),
    date_action TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user_id (user_id),
    INDEX idx_date_action (date_action)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Insertion d'un administrateur par défaut
-- Mot de passe: admin123 (à changer après installation)
INSERT INTO users (username, password_hash, email, nom, prenom, role, statut) 
VALUES ('admin', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'admin@bank.local', 'Administrateur', 'Système', 'admin', 'actif');

-- Insertion d'un client de test
-- Mot de passe: client123
INSERT INTO users (username, password_hash, email, nom, prenom, telephone, adresse, role, statut) 
VALUES ('client_test', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'client@test.local', 'Dupont', 'Jean', '0612345678', '123 Rue de la Banque, 75001 Paris', 'client', 'actif');
