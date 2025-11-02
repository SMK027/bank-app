<?php
require_once '../config/config.php';
requireAdmin();

$db = getDB();

// Traitement d'octroi de crédit
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create_credit') {
    $userId = $_POST['user_id'] ?? 0;
    $montantTotal = $_POST['montant_total'] ?? 0;
    $tauxInteret = $_POST['taux_interet'] ?? 0;
    $dureeMois = $_POST['duree_mois'] ?? 0;
    $dateDebut = $_POST['date_debut'] ?? date('Y-m-d');
    
    if ($userId <= 0 || !validerMontant($montantTotal) || $dureeMois <= 0) {
        setFlashMessage('Veuillez remplir correctement tous les champs.', 'danger');
    } else {
        try {
            // Calcul de la mensualité
            $tauxMensuel = $tauxInteret / 100 / 12;
            if ($tauxMensuel > 0) {
                $mensualite = $montantTotal * ($tauxMensuel * pow(1 + $tauxMensuel, $dureeMois)) / (pow(1 + $tauxMensuel, $dureeMois) - 1);
            } else {
                $mensualite = $montantTotal / $dureeMois;
            }
            
            $dateFin = date('Y-m-d', strtotime($dateDebut . " +$dureeMois months"));
            
            $stmt = $db->prepare("INSERT INTO credits (user_id, montant_total, montant_restant, taux_interet, duree_mois, mensualite, date_debut, date_fin) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$userId, $montantTotal, $montantTotal, $tauxInteret, $dureeMois, $mensualite, $dateDebut, $dateFin]);
            
            $creditId = $db->lastInsertId();
            
            // Création des échéances
            for ($i = 1; $i <= $dureeMois; $i++) {
                $dateEcheance = date('Y-m-d', strtotime($dateDebut . " +$i months"));
                $stmt = $db->prepare("INSERT INTO echeances_credit (credit_id, numero_echeance, montant, date_echeance) VALUES (?, ?, ?, ?)");
                $stmt->execute([$creditId, $i, $mensualite, $dateEcheance]);
            }
            
            // Notification RGPD
            envoyerNotificationRGPD($userId, 'Octroi de crédit', "Un crédit de " . formatMontant($montantTotal) . " vous a été accordé sur $dureeMois mois.");
            
            setFlashMessage('Crédit accordé avec succès.', 'success');
            logActivity($_SESSION['user_id'], 'Octroi crédit', "Montant: $montantTotal, Durée: $dureeMois mois, Client ID: $userId");
        } catch (Exception $e) {
            setFlashMessage('Erreur lors de la création du crédit.', 'danger');
        }
    }
    
    redirect(BASE_URL . '/admin/credits.php');
}

// Traitement de modification du montant total
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_montant') {
    $creditId = $_POST['credit_id'] ?? 0;
    $nouveauMontant = $_POST['nouveau_montant'] ?? 0;
    
    if ($creditId > 0 && validerMontant($nouveauMontant)) {
        $stmt = $db->prepare("SELECT c.*, u.id as user_id FROM credits c INNER JOIN users u ON c.user_id = u.id WHERE c.id = ?");
        $stmt->execute([$creditId]);
        $credit = $stmt->fetch();
        
        if ($credit) {
            $stmt = $db->prepare("UPDATE credits SET montant_total = ?, montant_restant = ? WHERE id = ?");
            $stmt->execute([$nouveauMontant, $nouveauMontant, $creditId]);
            
            envoyerNotificationRGPD($credit['user_id'], 'Modification de crédit', "Le montant de votre crédit a été modifié à " . formatMontant($nouveauMontant));
            
            setFlashMessage('Montant du crédit modifié avec succès.', 'success');
            logActivity($_SESSION['user_id'], 'Modification montant crédit', "Crédit ID: $creditId, Nouveau montant: $nouveauMontant");
        }
    }
    
    redirect(BASE_URL . '/admin/credits.php');
}

// Traitement de modification du taux
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_taux') {
    $creditId = $_POST['credit_id'] ?? 0;
    $nouveauTaux = $_POST['nouveau_taux'] ?? 0;
    
    if ($creditId > 0 && $nouveauTaux >= 0) {
        $stmt = $db->prepare("SELECT c.*, u.id as user_id FROM credits c INNER JOIN users u ON c.user_id = u.id WHERE c.id = ?");
        $stmt->execute([$creditId]);
        $credit = $stmt->fetch();
        
        if ($credit) {
            $stmt = $db->prepare("UPDATE credits SET taux_interet = ? WHERE id = ?");
            $stmt->execute([$nouveauTaux, $creditId]);
            
            envoyerNotificationRGPD($credit['user_id'], 'Modification de crédit', "Le taux d'intérêt de votre crédit a été modifié à " . number_format($nouveauTaux, 2) . "%");
            
            setFlashMessage('Taux d\'intérêt modifié avec succès.', 'success');
            logActivity($_SESSION['user_id'], 'Modification taux crédit', "Crédit ID: $creditId, Nouveau taux: $nouveauTaux%");
        }
    }
    
    redirect(BASE_URL . '/admin/credits.php');
}

// Traitement de modification de la date de fin
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_date_fin') {
    $creditId = $_POST['credit_id'] ?? 0;
    $nouvelleDateFin = $_POST['nouvelle_date_fin'] ?? '';
    
    if ($creditId > 0 && !empty($nouvelleDateFin)) {
        $stmt = $db->prepare("SELECT c.*, u.id as user_id FROM credits c INNER JOIN users u ON c.user_id = u.id WHERE c.id = ?");
        $stmt->execute([$creditId]);
        $credit = $stmt->fetch();
        
        if ($credit) {
            $stmt = $db->prepare("UPDATE credits SET date_fin = ? WHERE id = ?");
            $stmt->execute([$nouvelleDateFin, $creditId]);
            
            envoyerNotificationRGPD($credit['user_id'], 'Modification de crédit', "La date de fin de votre crédit a été modifiée au " . formatDate($nouvelleDateFin));
            
            setFlashMessage('Date de fin modifiée avec succès.', 'success');
            logActivity($_SESSION['user_id'], 'Modification date fin crédit', "Crédit ID: $creditId, Nouvelle date: $nouvelleDateFin");
        }
    }
    
    redirect(BASE_URL . '/admin/credits.php');
}

// Traitement d'ajout d'échéance
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_echeance') {
    $creditId = $_POST['credit_id'] ?? 0;
    $montant = $_POST['montant'] ?? 0;
    $dateEcheance = $_POST['date_echeance'] ?? '';
    
    if ($creditId > 0 && validerMontant($montant) && !empty($dateEcheance)) {
        // Récupérer le dernier numéro d'échéance
        $stmt = $db->prepare("SELECT MAX(numero_echeance) as max_num FROM echeances_credit WHERE credit_id = ?");
        $stmt->execute([$creditId]);
        $result = $stmt->fetch();
        $numeroEcheance = ($result['max_num'] ?? 0) + 1;
        
        $stmt = $db->prepare("INSERT INTO echeances_credit (credit_id, numero_echeance, montant, date_echeance) VALUES (?, ?, ?, ?)");
        $stmt->execute([$creditId, $numeroEcheance, $montant, $dateEcheance]);
        
        setFlashMessage('Échéance ajoutée avec succès.', 'success');
        logActivity($_SESSION['user_id'], 'Ajout échéance crédit', "Crédit ID: $creditId, Montant: $montant");
    }
    
    redirect(BASE_URL . '/admin/credits.php');
}

// Traitement de suppression d'échéance
if (isset($_GET['action']) && $_GET['action'] === 'delete_echeance' && isset($_GET['id'])) {
    $echeanceId = $_GET['id'];
    
    $stmt = $db->prepare("SELECT * FROM echeances_credit WHERE id = ? AND statut = 'en_attente'");
    $stmt->execute([$echeanceId]);
    $echeance = $stmt->fetch();
    
    if ($echeance) {
        $stmt = $db->prepare("DELETE FROM echeances_credit WHERE id = ?");
        $stmt->execute([$echeanceId]);
        
        setFlashMessage('Échéance supprimée avec succès.', 'success');
        logActivity($_SESSION['user_id'], 'Suppression échéance crédit', "Échéance ID: $echeanceId");
    } else {
        setFlashMessage('Impossible de supprimer une échéance déjà payée.', 'danger');
    }
    
    redirect(BASE_URL . '/admin/credits.php');
}

// Traitement de prélèvement forcé des mensualités dues (utilise la procédure SQL)
if (isset($_GET['action']) && $_GET['action'] === 'prelever_dues') {
    try {
        // Appeler la procédure stockée
        $stmt = $db->query("CALL prelever_mensualities()");
        $result = $stmt->fetch();
        
        $nbPrelevements = $result['echeances_traitees'] ?? 0;
        
        if ($nbPrelevements > 0) {
            setFlashMessage("$nbPrelevements échéance(s) prélevée(s) avec succès via la procédure automatique.", 'success');
            logActivity($_SESSION['user_id'], 'Prélèvement forcé échéances', "Nombre: $nbPrelevements");
        } else {
            setFlashMessage('Aucune échéance due à prélever.', 'info');
        }
    } catch (Exception $e) {
        setFlashMessage('Erreur lors du prélèvement : ' . $e->getMessage(), 'danger');
    }
    
    redirect(BASE_URL . '/admin/credits.php');
}

// Récupération de tous les crédits
$stmt = $db->query("
    SELECT c.*, u.username, u.nom, u.prenom 
    FROM credits c 
    INNER JOIN users u ON c.user_id = u.id 
    ORDER BY c.date_creation DESC
");
$credits = $stmt->fetchAll();

// Récupération de tous les clients pour le formulaire
$stmt = $db->query("SELECT id, username, nom, prenom FROM users WHERE role = 'client' AND statut = 'actif' ORDER BY nom, prenom");
$clients = $stmt->fetchAll();

// Compter les échéances dues
$stmt = $db->query("
    SELECT COUNT(*) as nb_dues
    FROM echeances_credit e
    INNER JOIN credits c ON e.credit_id = c.id
    WHERE e.date_echeance <= CURDATE()
    AND e.statut = 'en_attente'
    AND c.statut = 'actif'
");
$nbEcheancesDues = $stmt->fetch()['nb_dues'];

$pageTitle = 'Gestion des crédits';
include 'includes/header.php';
?>

<div class="page-content">
    <h1>Gestion des crédits</h1>
    
    <?php
    $flash = getFlashMessage();
    if ($flash):
    ?>
        <div class="alert alert-<?php echo e($flash['type']); ?>">
            <?php echo e($flash['message']); ?>
        </div>
    <?php endif; ?>
    
    <div class="page-actions">
        <button class="btn btn-primary" onclick="toggleForm('create-credit-form')">
            Octroyer un crédit
        </button>
        <?php if ($nbEcheancesDues > 0): ?>
        <a href="?action=prelever_dues" class="btn btn-warning" onclick="return confirm('Prélever toutes les échéances dues (<?php echo $nbEcheancesDues; ?>) ?')">
            Prélever les échéances dues (<?php echo $nbEcheancesDues; ?>)
        </a>
        <?php endif; ?>
    </div>
    
    <div id="create-credit-form" class="form-container" style="display: none;">
        <h2>Nouveau crédit</h2>
        <form method="POST" action="">
            <input type="hidden" name="action" value="create_credit">
            
            <div class="form-group">
                <label for="user_id">Client *</label>
                <select id="user_id" name="user_id" class="form-control" required>
                    <option value="">Sélectionner un client</option>
                    <?php foreach ($clients as $client): ?>
                    <option value="<?php echo $client['id']; ?>">
                        <?php echo e($client['prenom'] . ' ' . $client['nom'] . ' (' . $client['username'] . ')'); ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="form-row">
                <div class="form-group">
                    <label for="montant_total">Montant total (€) *</label>
                    <input type="number" id="montant_total" name="montant_total" class="form-control" step="0.01" min="0.01" required>
                </div>
                
                <div class="form-group">
                    <label for="taux_interet">Taux d'intérêt (%) *</label>
                    <input type="number" id="taux_interet" name="taux_interet" class="form-control" step="0.01" min="0" value="0" required>
                </div>
            </div>
            
            <div class="form-row">
                <div class="form-group">
                    <label for="duree_mois">Durée (mois) *</label>
                    <input type="number" id="duree_mois" name="duree_mois" class="form-control" min="1" required>
                </div>
                
                <div class="form-group">
                    <label for="date_debut">Date de début *</label>
                    <input type="date" id="date_debut" name="date_debut" class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
                </div>
            </div>
            
            <div class="form-actions">
                <button type="submit" class="btn btn-primary">Créer</button>
                <button type="button" class="btn btn-secondary" onclick="toggleForm('create-credit-form')">Annuler</button>
            </div>
        </form>
    </div>
    
    <div class="credits-list">
        <h2>Liste des crédits (<?php echo count($credits); ?>)</h2>
        
        <?php if (empty($credits)): ?>
            <p>Aucun crédit enregistré.</p>
        <?php else: ?>
            <table class="table">
                <thead>
                    <tr>
                        <th>Client</th>
                        <th>Montant total</th>
                        <th>Montant restant</th>
                        <th>Taux (%)</th>
                        <th>Durée</th>
                        <th>Mensualité</th>
                        <th>Date fin</th>
                        <th>Statut</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($credits as $credit): ?>
                    <tr>
                        <td><?php echo e($credit['prenom'] . ' ' . $credit['nom']); ?></td>
                        <td><?php echo formatMontant($credit['montant_total']); ?></td>
                        <td><?php echo formatMontant($credit['montant_restant']); ?></td>
                        <td><?php echo number_format($credit['taux_interet'], 2); ?>%</td>
                        <td><?php echo $credit['duree_mois']; ?> mois</td>
                        <td><?php echo formatMontant($credit['mensualite']); ?></td>
                        <td><?php echo formatDate($credit['date_fin']); ?></td>
                        <td>
                            <span class="badge badge-<?php echo $credit['statut'] === 'actif' ? 'success' : ($credit['statut'] === 'termine' ? 'secondary' : 'warning'); ?>">
                                <?php echo e(ucfirst($credit['statut'])); ?>
                            </span>
                        </td>
                        <td class="actions">
                            <button class="btn btn-sm btn-info" onclick="showEcheances(<?php echo $credit['id']; ?>)">
                                Échéances
                            </button>
                            <?php if ($credit['statut'] === 'actif'): ?>
                            <button class="btn btn-sm btn-warning" onclick="showModifierMontant(<?php echo $credit['id']; ?>, <?php echo $credit['montant_total']; ?>)">
                                Montant
                            </button>
                            <button class="btn btn-sm btn-warning" onclick="showModifierTaux(<?php echo $credit['id']; ?>, <?php echo $credit['taux_interet']; ?>)">
                                Taux
                            </button>
                            <button class="btn btn-sm btn-warning" onclick="showModifierDateFin(<?php echo $credit['id']; ?>, '<?php echo $credit['date_fin']; ?>')">
                                Date fin
                            </button>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>

<!-- Modal des échéances -->
<div id="echeances-modal" class="modal" style="display: none;">
    <div class="modal-content modal-large">
        <h2>Échéances du crédit</h2>
        <div id="echeances-content"></div>
        <button type="button" class="btn btn-secondary" onclick="hideEcheances()">Fermer</button>
    </div>
</div>

<!-- Modal de modification du montant -->
<div id="montant-modal" class="modal" style="display: none;">
    <div class="modal-content">
        <h2>Modifier le montant du crédit</h2>
        <form method="POST" action="">
            <input type="hidden" name="action" value="update_montant">
            <input type="hidden" name="credit_id" id="montant-credit-id">
            
            <div class="form-group">
                <label for="nouveau_montant">Nouveau montant total (€) *</label>
                <input type="number" id="nouveau_montant" name="nouveau_montant" class="form-control" step="0.01" min="0.01" required>
            </div>
            
            <div class="form-actions">
                <button type="submit" class="btn btn-primary">Modifier</button>
                <button type="button" class="btn btn-secondary" onclick="hideMontantModal()">Annuler</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal de modification du taux -->
<div id="taux-modal" class="modal" style="display: none;">
    <div class="modal-content">
        <h2>Modifier le taux d'intérêt</h2>
        <form method="POST" action="">
            <input type="hidden" name="action" value="update_taux">
            <input type="hidden" name="credit_id" id="taux-credit-id">
            
            <div class="form-group">
                <label for="nouveau_taux">Nouveau taux d'intérêt (%) *</label>
                <input type="number" id="nouveau_taux" name="nouveau_taux" class="form-control" step="0.01" min="0" required>
            </div>
            
            <div class="form-actions">
                <button type="submit" class="btn btn-primary">Modifier</button>
                <button type="button" class="btn btn-secondary" onclick="hideTauxModal()">Annuler</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal de modification de la date de fin -->
<div id="date-fin-modal" class="modal" style="display: none;">
    <div class="modal-content">
        <h2>Modifier la date de fin</h2>
        <form method="POST" action="">
            <input type="hidden" name="action" value="update_date_fin">
            <input type="hidden" name="credit_id" id="date-fin-credit-id">
            
            <div class="form-group">
                <label for="nouvelle_date_fin">Nouvelle date de fin *</label>
                <input type="date" id="nouvelle_date_fin" name="nouvelle_date_fin" class="form-control" required>
            </div>
            
            <div class="form-actions">
                <button type="submit" class="btn btn-primary">Modifier</button>
                <button type="button" class="btn btn-secondary" onclick="hideDateFinModal()">Annuler</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal d'ajout d'échéance -->
<div id="add-echeance-modal" class="modal" style="display: none;">
    <div class="modal-content">
        <h2>Ajouter une échéance</h2>
        <form method="POST" action="">
            <input type="hidden" name="action" value="add_echeance">
            <input type="hidden" name="credit_id" id="add-echeance-credit-id">
            
            <div class="form-group">
                <label for="montant">Montant (€) *</label>
                <input type="number" id="montant" name="montant" class="form-control" step="0.01" min="0.01" required>
            </div>
            
            <div class="form-group">
                <label for="date_echeance">Date d'échéance *</label>
                <input type="date" id="date_echeance" name="date_echeance" class="form-control" required>
            </div>
            
            <div class="form-actions">
                <button type="submit" class="btn btn-primary">Ajouter</button>
                <button type="button" class="btn btn-secondary" onclick="hideAddEcheanceModal()">Annuler</button>
            </div>
        </form>
    </div>
</div>

<script>
function showEcheances(creditId) {
    fetch('ajax/get_echeances.php?credit_id=' + creditId)
        .then(response => response.json())
        .then(data => {
            let html = '<table class="table"><thead><tr><th>N°</th><th>Date</th><th>Montant</th><th>Statut</th><th>Date paiement</th><th>Actions</th></tr></thead><tbody>';
            
            if (data.length === 0) {
                html += '<tr><td colspan="6">Aucune échéance</td></tr>';
            } else {
                data.forEach(echeance => {
                    html += '<tr>';
                    html += '<td>' + echeance.numero_echeance + '</td>';
                    html += '<td>' + echeance.date_echeance + '</td>';
                    html += '<td>' + echeance.montant + ' €</td>';
                    html += '<td>' + echeance.statut + '</td>';
                    html += '<td>' + (echeance.date_paiement || '-') + '</td>';
                    html += '<td>';
                    if (echeance.statut === 'En_attente') {
                        html += '<a href="?action=delete_echeance&id=' + echeance.id + '" class="btn btn-sm btn-danger" onclick="return confirm(\'Supprimer cette échéance ?\')">Supprimer</a>';
                    }
                    html += '</td>';
                    html += '</tr>';
                });
            }
            
            html += '</tbody></table>';
            html += '<button class="btn btn-primary" onclick="showAddEcheanceModal(' + creditId + ')">Ajouter une échéance</button>';
            
            document.getElementById('echeances-content').innerHTML = html;
            document.getElementById('echeances-modal').style.display = 'flex';
        });
}

function hideEcheances() {
    document.getElementById('echeances-modal').style.display = 'none';
}

function showModifierMontant(creditId, montantActuel) {
    document.getElementById('montant-credit-id').value = creditId;
    document.getElementById('nouveau_montant').value = montantActuel;
    document.getElementById('montant-modal').style.display = 'flex';
}

function hideMontantModal() {
    document.getElementById('montant-modal').style.display = 'none';
}

function showAddEcheanceModal(creditId) {
    document.getElementById('add-echeance-credit-id').value = creditId;
    document.getElementById('add-echeance-modal').style.display = 'flex';
    hideEcheances();
}

function hideAddEcheanceModal() {
    document.getElementById('add-echeance-modal').style.display = 'none';
}

function showModifierTaux(creditId, tauxActuel) {
    document.getElementById('taux-credit-id').value = creditId;
    document.getElementById('nouveau_taux').value = tauxActuel;
    document.getElementById('taux-modal').style.display = 'flex';
}

function hideTauxModal() {
    document.getElementById('taux-modal').style.display = 'none';
}

function showModifierDateFin(creditId, dateFin) {
    document.getElementById('date-fin-credit-id').value = creditId;
    document.getElementById('nouvelle_date_fin').value = dateFin;
    document.getElementById('date-fin-modal').style.display = 'flex';
}

function hideDateFinModal() {
    document.getElementById('date-fin-modal').style.display = 'none';
}
</script>

<?php include 'includes/footer.php'; ?>
