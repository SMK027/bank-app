<?php
require_once '../config/config.php';
requireClient();

$userId = $_SESSION['user_id'];
$db = getDB();

// Récupération des comptes actifs de l'utilisateur
$stmt = $db->prepare("
    SELECT c.*, 'proprietaire' as type_acces 
    FROM comptes c 
    WHERE c.user_id = ? AND c.statut = 'actif'
    UNION ALL
    SELECT c.*, 'procuration' as type_acces 
    FROM comptes c 
    INNER JOIN procurations p ON c.id = p.compte_id 
    WHERE p.user_beneficiaire_id = ? 
    AND p.statut = 'active' 
    AND c.statut = 'actif'
    AND (p.date_fin IS NULL OR p.date_fin >= CURDATE())
    ORDER BY date_creation DESC
");
$stmt->execute([$userId, $userId]);
$comptesActifs = $stmt->fetchAll();

// Traitement d'émission de virement
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'emettre_virement') {
    $compteEmetteurId = $_POST['compte_emetteur'] ?? 0;
    $typeVirement = $_POST['type_virement'] ?? '';
    $montant = $_POST['montant'] ?? 0;
    $motif = $_POST['motif'] ?? '';
    $dateExecution = $_POST['date_execution'] ?? date('Y-m-d');

    // Vérifier que le compte émetteur appartient à l'utilisateur
    $compteValide = false;
    $compteEmetteur = null;
    foreach ($comptesActifs as $compte) {
        if ($compte['id'] == $compteEmetteurId) {
            $compteValide = true;
            $compteEmetteur = $compte;
            break;
        }
    }

    if (!$compteValide) {
        setFlashMessage('Compte émetteur invalide.', 'danger');
    } elseif (!validerMontant($montant) || $montant <= 0) {
        setFlashMessage('Montant invalide.', 'danger');
    } elseif (!in_array($typeVirement, ['interne', 'externe'])) {
        setFlashMessage('Type de virement invalide.', 'danger');
    } else {
        try {
            $db->beginTransaction();

            if ($typeVirement === 'interne') {
                // Virement interne - Recherche par ID ou RIB
                $identifiantDestinataire = trim($_POST['identifiant_destinataire'] ?? '');

                if (empty($identifiantDestinataire)) {
                    throw new Exception('Veuillez saisir l\'identifiant ou le RIB du compte destinataire.');
                }

                // Rechercher le compte destinataire par ID ou RIB
                $compteDestinataire = null;

                // Essayer d'abord par ID (si c'est un nombre)
                if (is_numeric($identifiantDestinataire)) {
                    $stmt = $db->prepare("SELECT * FROM comptes WHERE id = ? AND statut = 'actif'");
                    $stmt->execute([$identifiantDestinataire]);
                    $compteDestinataire = $stmt->fetch();
                }

                // Si pas trouvé, essayer par RIB/numéro de compte
                if (!$compteDestinataire) {
                    $stmt = $db->prepare("SELECT * FROM comptes WHERE numero_compte = ? AND statut = 'actif'");
                    $stmt->execute([$identifiantDestinataire]);
                    $compteDestinataire = $stmt->fetch();
                }

                if (!$compteDestinataire) {
                    throw new Exception('Compte destinataire introuvable ou inactif. Vérifiez l\'identifiant ou le RIB saisi.');
                }

                if ($compteEmetteurId == $compteDestinataire['id']) {
                    throw new Exception('Le compte émetteur et destinataire ne peuvent pas être identiques.');
                }

                // Vérifier le solde disponible
                $soldeDisponible = $compteEmetteur['solde'] + $compteEmetteur['negatif_autorise'];
                if ($montant > $soldeDisponible) {
                    throw new Exception('Solde insuffisant pour effectuer ce virement.');
                }

                // Récupérer les informations du propriétaire du compte destinataire
                $stmt = $db->prepare("SELECT u.prenom, u.nom FROM users u INNER JOIN comptes c ON u.id = c.user_id WHERE c.id = ?");
                $stmt->execute([$compteDestinataire['id']]);
                $proprietaireDestinataire = $stmt->fetch();
                $nomDestinataire = $proprietaireDestinataire ? $proprietaireDestinataire['prenom'] . ' ' . $proprietaireDestinataire['nom'] : 'Inconnu';

                // Si date d'exécution = aujourd'hui, exécuter immédiatement
                if ($dateExecution === date('Y-m-d')) {
                    // Débiter le compte émetteur
                    $nouveauSoldeEmetteur = $compteEmetteur['solde'] - $montant;
                    $stmt = $db->prepare("UPDATE comptes SET solde = ? WHERE id = ?");
                    $stmt->execute([$nouveauSoldeEmetteur, $compteEmetteurId]);

                    // Créditer le compte destinataire
                    $nouveauSoldeDestinataire = $compteDestinataire['solde'] + $montant;
                    $stmt = $db->prepare("UPDATE comptes SET solde = ? WHERE id = ?");
                    $stmt->execute([$nouveauSoldeDestinataire, $compteDestinataire['id']]);

                    // Enregistrer l'opération de débit
                    $stmt = $db->prepare("INSERT INTO operations (compte_id, type_operation, montant, destinataire, nature, description, solde_apres) VALUES (?, 'debit', ?, ?, 'Virement', ?, ?)");
                    $stmt->execute([$compteEmetteurId, $montant, $nomDestinataire . ' - ' . $compteDestinataire['numero_compte'], $motif, $nouveauSoldeEmetteur]);

                    // Enregistrer l'opération de crédit
                    $stmt = $db->prepare("INSERT INTO operations (compte_id, type_operation, montant, destinataire, nature, description, solde_apres) VALUES (?, 'credit', ?, ?, 'Virement', ?, ?)");
                    $stmt->execute([$compteDestinataire['id'], $montant, $compteEmetteur['numero_compte'], $motif, $nouveauSoldeDestinataire]);

                    setFlashMessage('Virement exécuté avec succès vers ' . $nomDestinataire . '.', 'success');
                    logActivity($userId, 'Virement interne', "De: {$compteEmetteur['numero_compte']}, Vers: {$compteDestinataire['numero_compte']}, Montant: $montant");
                } else {
                    // Planifier le virement
                    $stmt = $db->prepare("INSERT INTO virements_planifies (compte_emetteur_id, type_virement, compte_destinataire_id, nom_beneficiaire, montant, motif, date_execution) VALUES (?, 'interne', ?, ?, ?, ?, ?)");
                    $stmt->execute([$compteEmetteurId, $compteDestinataire['id'], $nomDestinataire, $montant, $motif, $dateExecution]);

                    setFlashMessage('Virement planifié avec succès pour le ' . formatDate($dateExecution) . ' vers ' . $nomDestinataire . '.', 'success');
                    logActivity($userId, 'Virement planifié', "De: {$compteEmetteur['numero_compte']}, Date: $dateExecution, Montant: $montant");
                }

            } else {
                // Virement externe (vers RIB)
                $ribDestinataire = $_POST['rib_destinataire'] ?? '';
                $nomBeneficiaire = $_POST['nom_beneficiaire'] ?? '';

                // Valider le RIB (format IBAN)
                $ribDestinataire = strtoupper(str_replace(' ', '', $ribDestinataire));
                if (strlen($ribDestinataire) < 15 || strlen($ribDestinataire) > 34) {
                    throw new Exception('RIB/IBAN invalide.');
                }

                if (empty($nomBeneficiaire)) {
                    throw new Exception('Le nom du bénéficiaire est obligatoire.');
                }

                // Vérifier le solde disponible
                $soldeDisponible = $compteEmetteur['solde'] + $compteEmetteur['negatif_autorise'];
                if ($montant > $soldeDisponible) {
                    throw new Exception('Solde insuffisant pour effectuer ce virement.');
                }

                // Si date d'exécution = aujourd'hui, exécuter immédiatement
                if ($dateExecution === date('Y-m-d')) {
                    // Débiter le compte émetteur
                    $nouveauSoldeEmetteur = $compteEmetteur['solde'] - $montant;
                    $stmt = $db->prepare("UPDATE comptes SET solde = ? WHERE id = ?");
                    $stmt->execute([$nouveauSoldeEmetteur, $compteEmetteurId]);

                    // Enregistrer l'opération de débit
                    $stmt = $db->prepare("INSERT INTO operations (compte_id, type_operation, montant, destinataire, nature, description, solde_apres) VALUES (?, 'debit', ?, ?, 'Virement externe', ?, ?)");
                    $stmt->execute([$compteEmetteurId, $montant, $nomBeneficiaire . ' (' . $ribDestinataire . ')', $motif, $nouveauSoldeEmetteur]);

                    setFlashMessage('Virement externe exécuté avec succès.', 'success');
                    logActivity($userId, 'Virement externe', "De: {$compteEmetteur['numero_compte']}, Vers: $ribDestinataire, Montant: $montant");
                } else {
                    // Planifier le virement
                    $stmt = $db->prepare("INSERT INTO virements_planifies (compte_emetteur_id, type_virement, rib_destinataire, nom_beneficiaire, montant, motif, date_execution) VALUES (?, 'externe', ?, ?, ?, ?, ?)");
                    $stmt->execute([$compteEmetteurId, $ribDestinataire, $nomBeneficiaire, $montant, $motif, $dateExecution]);

                    setFlashMessage('Virement externe planifié avec succès pour le ' . formatDate($dateExecution), 'success');
                    logActivity($userId, 'Virement externe planifié', "De: {$compteEmetteur['numero_compte']}, Date: $dateExecution, Montant: $montant");
                }
            }

            $db->commit();

        } catch (Exception $e) {
            $db->rollBack();
            setFlashMessage('Erreur : ' . $e->getMessage(), 'danger');
        }
    }

    redirect(BASE_URL . '/client/virements.php');
}

// Traitement d'annulation de virement planifié
if (isset($_GET['action']) && $_GET['action'] === 'annuler' && isset($_GET['id'])) {
    $virementId = $_GET['id'];

    // Vérifier que le virement appartient à l'utilisateur
    $stmt = $db->prepare("
        SELECT vp.* 
        FROM virements_planifies vp
        INNER JOIN comptes c ON vp.compte_emetteur_id = c.id
        WHERE vp.id = ? AND c.user_id = ? AND vp.statut = 'en_attente'
    ");
    $stmt->execute([$virementId, $userId]);
    $virement = $stmt->fetch();

    if ($virement) {
        $stmt = $db->prepare("UPDATE virements_planifies SET statut = 'annule' WHERE id = ?");
        $stmt->execute([$virementId]);

        setFlashMessage('Virement annulé avec succès.', 'success');
        logActivity($userId, 'Annulation virement planifié', "Virement ID: $virementId");
    }

    redirect(BASE_URL . '/client/virements.php');
}

// Récupération des virements planifiés de l'utilisateur
$comptesIds = array_column($comptesActifs, 'id');
if (!empty($comptesIds)) {
    $placeholders = implode(',', array_fill(0, count($comptesIds), '?'));
    $stmt = $db->prepare("
        SELECT vp.*, 
               ce.numero_compte as compte_emetteur_numero,
               cd.numero_compte as compte_destinataire_numero
        FROM virements_planifies vp
        INNER JOIN comptes ce ON vp.compte_emetteur_id = ce.id
        LEFT JOIN comptes cd ON vp.compte_destinataire_id = cd.id
        WHERE vp.compte_emetteur_id IN ($placeholders)
        ORDER BY vp.date_execution ASC, vp.date_creation DESC
    ");
    $stmt->execute($comptesIds);
    $virementsPlanifies = $stmt->fetchAll();
} else {
    $virementsPlanifies = [];
}

$pageTitle = 'Virements';
include 'includes/header.php';
?>

<style>
    .virement-type-selector {
        display: flex;
        gap: 10px;
        margin-bottom: 20px;
    }

    .virement-type-btn {
        flex: 1;
        padding: 15px;
        border: 2px solid #ddd;
        background: white;
        cursor: pointer;
        border-radius: 5px;
        transition: all 0.3s;
    }

    .virement-type-btn:hover {
        border-color: #3498db;
    }

    .virement-type-btn.active {
        border-color: #3498db;
        background: #e3f2fd;
    }

    .virement-form-section {
        display: none;
    }

    .virement-form-section.active {
        display: block;
    }

    .info-box {
        background: #e8f4f8;
        border-left: 4px solid #3498db;
        padding: 12px;
        margin-bottom: 15px;
        border-radius: 4px;
    }

    .info-box strong {
        color: #2c3e50;
    }
</style>

<div class="page-content">
    <h1>Virements</h1>

    <?php
    $flash = getFlashMessage();
    if ($flash):
        ?>
        <div class="alert alert-<?php echo e($flash['type']); ?>">
            <?php echo e($flash['message']); ?>
        </div>
    <?php endif; ?>

    <?php if (empty($comptesActifs)): ?>
        <div class="alert alert-warning">
            Vous n'avez aucun compte actif. Impossible d'effectuer des virements.
        </div>
    <?php else: ?>

        <div class="page-actions">
            <button class="btn btn-primary" onclick="toggleForm('virement-form')">
                Effectuer un virement
            </button>
        </div>

        <div id="virement-form" class="form-container" style="display: none;">
            <h2>Nouveau virement</h2>

            <div class="virement-type-selector">
                <div class="virement-type-btn active" onclick="selectVirementType('interne')">
                    <h3>Virement interne</h3>
                    <p>Vers un compte de la banque (par ID ou RIB)</p>
                </div>
                <div class="virement-type-btn" onclick="selectVirementType('externe')">
                    <h3>Virement externe</h3>
                    <p>Vers un compte externe (RIB/IBAN)</p>
                </div>
            </div>

            <form method="POST" action="">
                <input type="hidden" name="action" value="emettre_virement">
                <input type="hidden" name="type_virement" id="type_virement" value="interne">

                <div class="form-group">
                    <label for="compte_emetteur">Compte émetteur *</label>
                    <select id="compte_emetteur" name="compte_emetteur" class="form-control" required onchange="updateSoldeInfo()">
                        <option value="">Sélectionner un compte</option>
                        <?php foreach ($comptesActifs as $compte): ?>
                            <option value="<?php echo $compte['id']; ?>"
                                    data-solde="<?php echo $compte['solde']; ?>"
                                    data-negatif="<?php echo $compte['negatif_autorise']; ?>"
                                    data-id="<?php echo $compte['id']; ?>"
                                    data-rib="<?php echo $compte['numero_compte']; ?>">
                                <?php echo e($compte['numero_compte'] . ' - ' . ucfirst($compte['type_compte']) . ' - Solde: ' . formatMontant($compte['solde'])); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <small id="solde-info" class="form-text"></small>
                </div>

                <!-- Virement interne -->
                <div id="section-interne" class="virement-form-section active">
                    <div class="info-box">
                        <strong>💡 Astuce :</strong> Vous pouvez saisir soit l'<strong>identifiant du compte</strong> (numéro), soit le <strong>RIB complet</strong> du destinataire.
                    </div>

                    <div class="form-group">
                        <label for="identifiant_destinataire">Identifiant ou RIB du compte destinataire *</label>
                        <input type="text" id="identifiant_destinataire" name="identifiant_destinataire" class="form-control" placeholder="Ex: 123 ou FR76XXXXXXXXXXXXXXXXXXXX">
                        <small class="form-text">Saisissez l'ID du compte (ex: 5) ou le RIB complet (ex: FR76...)</small>
                    </div>
                </div>

                <!-- Virement externe -->
                <div id="section-externe" class="virement-form-section">
                    <div class="form-group">
                        <label for="nom_beneficiaire">Nom du bénéficiaire *</label>
                        <input type="text" id="nom_beneficiaire" name="nom_beneficiaire" class="form-control">
                    </div>

                    <div class="form-group">
                        <label for="rib_destinataire">RIB/IBAN du bénéficiaire *</label>
                        <input type="text" id="rib_destinataire" name="rib_destinataire" class="form-control" placeholder="FR76 XXXX XXXX XXXX XXXX XXXX XXX">
                    </div>
                </div>

                <div class="form-group">
                    <label for="montant">Montant (€) *</label>
                    <input type="number" id="montant" name="montant" class="form-control" step="0.01" min="0.01" required>
                </div>

                <div class="form-group">
                    <label for="motif">Motif</label>
                    <input type="text" id="motif" name="motif" class="form-control" placeholder="Ex: Remboursement, Loyer...">
                </div>

                <div class="form-group">
                    <label for="date_execution">Date d'exécution *</label>
                    <input type="date" id="date_execution" name="date_execution" class="form-control" value="<?php echo date('Y-m-d'); ?>" min="<?php echo date('Y-m-d'); ?>" required>
                    <small class="form-text">Si la date est aujourd'hui, le virement sera exécuté immédiatement. Sinon, il sera planifié.</small>
                </div>

                <div class="form-actions">
                    <button type="submit" class="btn btn-primary">Valider le virement</button>
                    <button type="button" class="btn btn-secondary" onclick="toggleForm('virement-form')">Annuler</button>
                </div>
            </form>
        </div>

    <?php endif; ?>

    <div class="virements-planifies">
        <h2>Virements planifiés</h2>

        <?php if (empty($virementsPlanifies)): ?>
            <p>Aucun virement planifié.</p>
        <?php else: ?>
            <table class="table">
                <thead>
                <tr>
                    <th>Date d'exécution</th>
                    <th>Type</th>
                    <th>Compte émetteur</th>
                    <th>Destinataire</th>
                    <th>Montant</th>
                    <th>Motif</th>
                    <th>Statut</th>
                    <th>Actions</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($virementsPlanifies as $virement): ?>
                    <tr>
                        <td><?php echo formatDate($virement['date_execution']); ?></td>
                        <td>
                            <span class="badge badge-<?php echo $virement['type_virement'] === 'interne' ? 'info' : 'primary'; ?>">
                                <?php echo ucfirst($virement['type_virement']); ?>
                            </span>
                        </td>
                        <td><?php echo e(substr($virement['compte_emetteur_numero'], -8)); ?></td>
                        <td>
                            <?php if ($virement['type_virement'] === 'interne'): ?>
                                <?php if ($virement['compte_destinataire_numero']): ?>
                                    <?php echo e(substr($virement['compte_destinataire_numero'], -8)); ?>
                                <?php else: ?>
                                    <?php echo e($virement['nom_beneficiaire'] ?? 'Compte interne'); ?>
                                <?php endif; ?>
                            <?php else: ?>
                                <?php echo e($virement['nom_beneficiaire']); ?><br>
                                <small><?php echo e($virement['rib_destinataire']); ?></small>
                            <?php endif; ?>
                        </td>
                        <td><?php echo formatMontant($virement['montant']); ?></td>
                        <td><?php echo e($virement['motif'] ?? '-'); ?></td>
                        <td>
                            <span class="badge badge-<?php
                            echo $virement['statut'] === 'execute' ? 'success' :
                                    ($virement['statut'] === 'annule' ? 'secondary' :
                                            ($virement['statut'] === 'erreur' ? 'danger' : 'warning'));
                            ?>">
                                <?php echo ucfirst($virement['statut']); ?>
                            </span>
                        </td>
                        <td>
                            <?php if ($virement['statut'] === 'en_attente'): ?>
                                <a href="?action=annuler&id=<?php echo $virement['id']; ?>"
                                   class="btn btn-sm btn-danger"
                                   onclick="return confirm('Annuler ce virement planifié ?')">
                                    Annuler
                                </a>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>

<script>
    function selectVirementType(type) {
        // Mettre à jour le champ caché
        document.getElementById('type_virement').value = type;

        // Mettre à jour les boutons
        document.querySelectorAll('.virement-type-btn').forEach(btn => {
            btn.classList.remove('active');
        });
        event.currentTarget.classList.add('active');

        // Afficher/masquer les sections
        document.getElementById('section-interne').classList.remove('active');
        document.getElementById('section-externe').classList.remove('active');

        if (type === 'interne') {
            document.getElementById('section-interne').classList.add('active');
            document.getElementById('identifiant_destinataire').required = true;
            document.getElementById('nom_beneficiaire').required = false;
            document.getElementById('rib_destinataire').required = false;
        } else {
            document.getElementById('section-externe').classList.add('active');
            document.getElementById('identifiant_destinataire').required = false;
            document.getElementById('nom_beneficiaire').required = true;
            document.getElementById('rib_destinataire').required = true;
        }
    }

    function updateSoldeInfo() {
        const select = document.getElementById('compte_emetteur');
        const option = select.options[select.selectedIndex];
        const soldeInfo = document.getElementById('solde-info');

        if (option.value) {
            const solde = parseFloat(option.dataset.solde);
            const negatif = parseFloat(option.dataset.negatif);
            const disponible = solde + negatif;
            const compteId = option.dataset.id;
            const compteRib = option.dataset.rib;

            soldeInfo.innerHTML = `<strong>Solde disponible :</strong> ${disponible.toFixed(2)} € (Solde: ${solde.toFixed(2)} € + Découvert autorisé: ${negatif.toFixed(2)} €)<br>
                               <strong>ID de ce compte :</strong> ${compteId} | <strong>RIB :</strong> ${compteRib}`;
            soldeInfo.style.color = disponible > 0 ? '#27ae60' : '#e74c3c';
        } else {
            soldeInfo.innerHTML = '';
        }
    }
</script>

<?php include 'includes/footer.php'; ?>
