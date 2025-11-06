<?php
require_once '../config/config.php';
requireClient();

$userId = $_SESSION['user_id'];
$db = getDB();

// Récupération de TOUS les comptes dont l'utilisateur est propriétaire (tous les statuts)
$stmt = $db->prepare("SELECT *, 'proprietaire' as type_acces FROM comptes WHERE user_id = ? ORDER BY date_creation DESC");
$stmt->execute([$userId]);
$comptesProprietaire = $stmt->fetchAll();

// Récupération des comptes avec procuration (uniquement actifs)
$stmt = $db->prepare("
    SELECT c.*, p.date_debut, p.date_fin, u.nom, u.prenom, 'procuration' as type_acces
    FROM comptes c 
    INNER JOIN procurations p ON c.id = p.compte_id 
    INNER JOIN users u ON c.user_id = u.id 
    WHERE p.user_beneficiaire_id = ? 
    AND p.statut = 'active' 
    AND c.statut = 'actif'
    AND (p.date_fin IS NULL OR p.date_fin >= CURDATE())
    ORDER BY c.date_creation DESC
");
$stmt->execute([$userId]);
$comptesProcuration = $stmt->fetchAll();

// Fusionner tous les comptes
$comptes = array_merge($comptesProprietaire, $comptesProcuration);

$pageTitle = 'Mes comptes';
include 'includes/header.php';
?>

    <style>
        .account-card.account-inactive {
            opacity: 0.85;
            border: 2px solid #f39c12;
        }

        .account-card.account-cloture {
            opacity: 0.7;
            border: 2px solid #95a5a6;
        }

        .account-warning {
            background-color: #fff3cd;
            border: 1px solid #ffeeba;
            color: #856404;
            padding: 10px;
            border-radius: 5px;
            margin: 10px 0;
            text-align: center;
        }

        .account-warning strong {
            display: block;
            margin-bottom: 5px;
        }

        .account-warning p {
            margin: 0;
            font-size: 0.9em;
        }

        .account-id {
            font-size: 0.9em;
            opacity: 0.9;
        }
    </style>

    <div class="page-content">
        <h1>Mes comptes bancaires</h1>

        <?php
        $flash = getFlashMessage();
        if ($flash):
            ?>
            <div class="alert alert-<?php echo e($flash['type']); ?>">
                <?php echo e($flash['message']); ?>
            </div>
        <?php endif; ?>

        <?php if (empty($comptes)): ?>
            <div class="alert alert-info">
                Vous n'avez aucun compte bancaire pour le moment.
            </div>
        <?php else: ?>
            <div class="accounts-grid">
                <?php foreach ($comptes as $compte): ?>
                    <div class="account-card <?php echo $compte['statut'] === 'suspendu' ? 'account-inactive' : ($compte['statut'] === 'cloture' ? 'account-cloture' : ''); ?>">
                        <div class="account-header" style="background-color: <?php echo getCompteHeaderColor($compte['statut']); ?>">
                            <div>
                                <h3><?php echo e(ucfirst($compte['type_compte'])); ?></h3>
                                <span class="badge badge-<?php echo getStatutBadgeClass($compte['statut']); ?>">
                            <?php echo e(ucfirst($compte['statut'])); ?>
                        </span>
                            </div>
                            <div class="account-id">
                                ID: <?php echo $compte['id']; ?>
                            </div>
                        </div>

                        <div class="account-body">
                            <p class="account-number"><?php echo e($compte['numero_compte']); ?></p>

                            <?php if ($compte['statut'] !== 'actif'): ?>
                                <div class="account-warning">
                                    <strong>⚠️ Compte <?php echo e($compte['statut']); ?></strong>
                                    <p>Les opérations sont bloquées sur ce compte.</p>
                                </div>
                            <?php endif; ?>

                            <div class="account-balance">
                                <span class="label">Solde actuel</span>
                                <span class="amount <?php echo $compte['solde'] < 0 ? 'negative' : 'positive'; ?>">
                            <?php echo formatMontant($compte['solde']); ?>
                        </span>
                            </div>

                            <?php if ($compte['negatif_autorise'] > 0): ?>
                                <div class="account-info">
                                    <span class="label">Négatif autorisé</span>
                                    <span class="value"><?php echo formatMontant($compte['negatif_autorise']); ?></span>
                                </div>
                            <?php endif; ?>

                            <div class="account-info">
                                <span class="label">Type d'accès</span>
                                <span class="value"><?php echo e(ucfirst($compte['type_acces'])); ?></span>
                            </div>

                            <?php if ($compte['type_acces'] === 'procuration'): ?>
                                <div class="account-info">
                                    <span class="label">Propriétaire</span>
                                    <span class="value"><?php echo e($compte['prenom'] . ' ' . $compte['nom']); ?></span>
                                </div>
                                <div class="account-info">
                                    <span class="label">Procuration valide jusqu'au</span>
                                    <span class="value"><?php echo $compte['date_fin'] ? formatDate($compte['date_fin']) : 'Indéterminée'; ?></span>
                                </div>
                            <?php endif; ?>

                            <div class="account-info">
                                <span class="label">Date de création</span>
                                <span class="value"><?php echo formatDate($compte['date_creation']); ?></span>
                            </div>
                        </div>

                        <div class="account-footer">
                            <?php if ($compte['statut'] === 'actif'): ?>
                                <a href="operations.php?compte_id=<?php echo $compte['id']; ?>" class="btn btn-sm btn-primary">
                                    Voir les opérations
                                </a>
                            <?php else: ?>
                                <button class="btn btn-sm btn-secondary" disabled title="Compte <?php echo e($compte['statut']); ?> - Opérations bloquées">
                                    Opérations bloquées
                                </button>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

<?php
function getCompteHeaderColor($statut) {
    switch ($statut) {
        case 'actif':
            return '#3498db';
        case 'suspendu':
            return '#f39c12';
        case 'cloture':
            return '#95a5a6';
        default:
            return '#7f8c8d';
    }
}

function getStatutBadgeClass($statut) {
    switch ($statut) {
        case 'actif':
            return 'success';
        case 'suspendu':
            return 'warning';
        case 'cloture':
            return 'secondary';
        default:
            return 'secondary';
    }
}

include 'includes/footer.php';
?>