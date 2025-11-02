<?php
require_once '../config/config.php';
requireClient();

$userId = $_SESSION['user_id'];
$db = getDB();

// Récupération des comptes de l'utilisateur
$stmt = $db->prepare("
    SELECT c.*, 'proprietaire' as type_acces 
    FROM comptes c 
    WHERE c.user_id = ?
    UNION ALL
    SELECT c.*, 'procuration' as type_acces 
    FROM comptes c 
    INNER JOIN procurations p ON c.id = p.compte_id 
    WHERE p.user_beneficiaire_id = ? 
    AND p.statut = 'active' 
    AND (p.date_fin IS NULL OR p.date_fin >= CURDATE())
    ORDER BY date_creation DESC
");
$stmt->execute([$userId, $userId]);
$comptesUtilisateur = $stmt->fetchAll();

$comptesIds = array_column($comptesUtilisateur, 'id');

// Récupération des prélèvements à venir sur les comptes de l'utilisateur
$prelevementsAvenir = [];
if (!empty($comptesIds)) {
    $placeholders = implode(',', array_fill(0, count($comptesIds), '?'));
    $stmt = $db->prepare("
        SELECT p.*, 
               cs.numero_compte as compte_source_numero,
               cs.type_compte as compte_source_type,
               cd.numero_compte as compte_destinataire_numero
        FROM prelevements_planifies p
        INNER JOIN comptes cs ON p.compte_source_id = cs.id
        LEFT JOIN comptes cd ON p.compte_destinataire_id = cd.id
        WHERE p.compte_source_id IN ($placeholders)
        AND p.statut = 'en_attente'
        ORDER BY p.date_execution ASC
    ");
    $stmt->execute($comptesIds);
    $prelevementsAvenir = $stmt->fetchAll();
}

// Récupération de l'historique des prélèvements exécutés
$prelevementsHistorique = [];
if (!empty($comptesIds)) {
    $placeholders = implode(',', array_fill(0, count($comptesIds), '?'));
    $stmt = $db->prepare("
        SELECT p.*, 
               cs.numero_compte as compte_source_numero,
               cs.type_compte as compte_source_type,
               cd.numero_compte as compte_destinataire_numero
        FROM prelevements_planifies p
        INNER JOIN comptes cs ON p.compte_source_id = cs.id
        LEFT JOIN comptes cd ON p.compte_destinataire_id = cd.id
        WHERE p.compte_source_id IN ($placeholders)
        AND p.statut IN ('execute', 'annule', 'erreur')
        ORDER BY p.date_execution_reelle DESC
        LIMIT 50
    ");
    $stmt->execute($comptesIds);
    $prelevementsHistorique = $stmt->fetchAll();
}

$pageTitle = 'Prélèvements';
include 'includes/header.php';
?>

<style>
    .prelevements-tabs {
        display: flex;
        gap: 10px;
        margin-bottom: 20px;
        border-bottom: 2px solid #ddd;
    }

    .tab-btn {
        padding: 10px 20px;
        border: none;
        background: none;
        cursor: pointer;
        border-bottom: 3px solid transparent;
        transition: all 0.3s;
    }

    .tab-btn.active {
        border-bottom-color: #3498db;
        color: #3498db;
        font-weight: bold;
    }

    .tab-content {
        display: none;
    }

    .tab-content.active {
        display: block;
    }

    .prelevement-card {
        background: #f8f9fa;
        border-left: 4px solid #f39c12;
        padding: 15px;
        margin-bottom: 15px;
        border-radius: 4px;
    }

    .prelevement-card.execute {
        border-left-color: #27ae60;
        background: #eafaf1;
    }

    .prelevement-card.annule {
        border-left-color: #95a5a6;
        background: #ecf0f1;
    }

    .prelevement-card.erreur {
        border-left-color: #e74c3c;
        background: #fadbd8;
    }

    .prelevement-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 10px;
    }

    .prelevement-montant {
        font-size: 1.5em;
        font-weight: bold;
        color: #e74c3c;
    }

    .prelevement-details {
        font-size: 0.9em;
        color: #555;
    }

    .prelevement-details strong {
        color: #2c3e50;
    }
</style>

<div class="page-content">
    <h1>Prélèvements</h1>

    <?php if (empty($comptesUtilisateur)): ?>
        <div class="alert alert-warning">
            Vous n'avez aucun compte.
        </div>
    <?php else: ?>

        <div class="prelevements-tabs">
            <button class="tab-btn active" onclick="switchTab('avenir')">
                Prélèvements à venir (<?php echo count($prelevementsAvenir); ?>)
            </button>
            <button class="tab-btn" onclick="switchTab('historique')">
                Historique (<?php echo count($prelevementsHistorique); ?>)
            </button>
        </div>

        <!-- Onglet Prélèvements à venir -->
        <div id="tab-avenir" class="tab-content active">
            <h2>Prélèvements à venir</h2>

            <?php if (empty($prelevementsAvenir)): ?>
                <p>Aucun prélèvement prévu sur vos comptes.</p>
            <?php else: ?>
                <?php foreach ($prelevementsAvenir as $prelevement): ?>
                    <div class="prelevement-card">
                        <div class="prelevement-header">
                            <div>
                                <strong>Date d'exécution :</strong> <?php echo formatDate($prelevement['date_execution']); ?>
                            </div>
                            <div class="prelevement-montant">
                                - <?php echo formatMontant($prelevement['montant']); ?>
                            </div>
                        </div>

                        <div class="prelevement-details">
                            <p><strong>Compte débité :</strong> <?php echo e($prelevement['compte_source_numero'] . ' (' . ucfirst($prelevement['compte_source_type']) . ')'); ?></p>

                            <?php if ($prelevement['compte_destinataire_numero']): ?>
                                <p><strong>Compte destinataire :</strong> <?php echo e($prelevement['compte_destinataire_numero']); ?></p>
                            <?php else: ?>
                                <p><strong>Destinataire :</strong> <span class="badge badge-secondary">Banque</span></p>
                            <?php endif; ?>

                            <p><strong>Descriptif :</strong> <?php echo e($prelevement['descriptif']); ?></p>

                            <p><small><strong>Créé le :</strong> <?php echo formatDate(date('Y-m-d', strtotime($prelevement['date_creation']))); ?></small></p>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <!-- Onglet Historique -->
        <div id="tab-historique" class="tab-content">
            <h2>Historique des prélèvements</h2>

            <?php if (empty($prelevementsHistorique)): ?>
                <p>Aucun prélèvement dans l'historique.</p>
            <?php else: ?>
                <?php foreach ($prelevementsHistorique as $prelevement): ?>
                    <div class="prelevement-card <?php echo $prelevement['statut']; ?>">
                        <div class="prelevement-header">
                            <div>
                                <strong>Date d'exécution :</strong> <?php echo formatDate($prelevement['date_execution_reelle'] ? date('Y-m-d', strtotime($prelevement['date_execution_reelle'])) : $prelevement['date_execution']); ?>
                                <span class="badge badge-<?php
                                echo $prelevement['statut'] === 'execute' ? 'success' :
                                    ($prelevement['statut'] === 'annule' ? 'secondary' : 'danger');
                                ?>">
                            <?php echo ucfirst($prelevement['statut']); ?>
                        </span>
                            </div>
                            <div class="prelevement-montant">
                                <?php if ($prelevement['statut'] === 'execute'): ?>
                                    - <?php echo formatMontant($prelevement['montant']); ?>
                                <?php else: ?>
                                    <?php echo formatMontant($prelevement['montant']); ?>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="prelevement-details">
                            <p><strong>Compte débité :</strong> <?php echo e($prelevement['compte_source_numero'] . ' (' . ucfirst($prelevement['compte_source_type']) . ')'); ?></p>

                            <?php if ($prelevement['compte_destinataire_numero']): ?>
                                <p><strong>Compte destinataire :</strong> <?php echo e($prelevement['compte_destinataire_numero']); ?></p>
                            <?php else: ?>
                                <p><strong>Destinataire :</strong> <span class="badge badge-secondary">Banque</span></p>
                            <?php endif; ?>

                            <p><strong>Descriptif :</strong> <?php echo e($prelevement['descriptif']); ?></p>

                            <?php if ($prelevement['statut'] === 'erreur' && $prelevement['message_erreur']): ?>
                                <p class="text-danger"><strong>Erreur :</strong> <?php echo e($prelevement['message_erreur']); ?></p>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

    <?php endif; ?>
</div>

<script>
    function switchTab(tabName) {
        // Masquer tous les contenus
        document.querySelectorAll('.tab-content').forEach(content => {
            content.classList.remove('active');
        });

        // Désactiver tous les boutons
        document.querySelectorAll('.tab-btn').forEach(btn => {
            btn.classList.remove('active');
        });

        // Activer le contenu et le bouton sélectionnés
        document.getElementById('tab-' + tabName).classList.add('active');
        event.currentTarget.classList.add('active');
    }
</script>

<?php include 'includes/footer.php'; ?>
