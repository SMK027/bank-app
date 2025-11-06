<?php
require_once '../config/config.php';
requireAdmin();

$userId = $_SESSION['user_id'];
$db = getDB();

// Traitement de l'envoi de message
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'send_message') {
    $destinataireId = $_POST['destinataire_id'] ?? 0;
    $sujet = $_POST['sujet'] ?? '';
    $contenu = $_POST['contenu'] ?? '';
    $typeMessage = $_POST['type_message'] ?? 'normal';

    if ($destinataireId <= 0 || empty($sujet) || empty($contenu)) {
        setFlashMessage('Veuillez remplir tous les champs obligatoires.', 'danger');
    } else {
        if (envoyerMessage($userId, $destinataireId, $sujet, $contenu, $typeMessage)) {
            setFlashMessage('Message envoyé avec succès.', 'success');
            logActivity($userId, 'Envoi message client', "Destinataire ID: $destinataireId, Sujet: $sujet");
        } else {
            setFlashMessage('Erreur lors de l\'envoi du message.', 'danger');
        }
    }

    redirect(BASE_URL . '/admin/messages.php');
}

// Traitement de la réponse à un message
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'reply_message') {
    $messageOriginalId = $_POST['message_original_id'] ?? 0;
    $destinataireId = $_POST['destinataire_id'] ?? 0;
    $sujet = $_POST['sujet'] ?? '';
    $contenu = $_POST['contenu'] ?? '';

    if ($destinataireId <= 0 || empty($sujet) || empty($contenu)) {
        setFlashMessage('Veuillez remplir tous les champs obligatoires.', 'danger');
    } else {
        if (envoyerMessage($userId, $destinataireId, $sujet, $contenu, 'normal')) {
            // Marquer le message original comme lu
            if ($messageOriginalId > 0) {
                $stmt = $db->prepare("UPDATE messages SET lu = 1, date_lecture = NOW() WHERE id = ?");
                $stmt->execute([$messageOriginalId]);
            }

            setFlashMessage('Réponse envoyée avec succès.', 'success');
            logActivity($userId, 'Réponse message client', "Destinataire ID: $destinataireId");
        } else {
            setFlashMessage('Erreur lors de l\'envoi de la réponse.', 'danger');
        }
    }

    redirect(BASE_URL . '/admin/messages.php');
}

// Marquer un message comme lu
if (isset($_GET['action']) && $_GET['action'] === 'mark_read' && isset($_GET['id'])) {
    $messageId = $_GET['id'];

    $stmt = $db->prepare("UPDATE messages SET lu = 1, date_lecture = NOW() WHERE id = ? AND destinataire_id = ?");
    $stmt->execute([$messageId, $userId]);

    redirect(BASE_URL . '/admin/messages.php');
}

// Récupération des messages reçus (des clients)
$stmt = $db->prepare("
    SELECT m.*, u.nom as expediteur_nom, u.prenom as expediteur_prenom, u.username, u.id as expediteur_user_id
    FROM messages m 
    INNER JOIN users u ON m.expediteur_id = u.id 
    WHERE m.destinataire_id = ? 
    ORDER BY m.date_envoi DESC
");
$stmt->execute([$userId]);
$messagesRecus = $stmt->fetchAll();

// Récupération des messages envoyés
$stmt = $db->prepare("
    SELECT m.*, u.nom as destinataire_nom, u.prenom as destinataire_prenom, u.username 
    FROM messages m 
    INNER JOIN users u ON m.destinataire_id = u.id 
    WHERE m.expediteur_id = ? 
    ORDER BY m.date_envoi DESC
");
$stmt->execute([$userId]);
$messagesEnvoyes = $stmt->fetchAll();

// Récupération de tous les clients pour le formulaire
$stmt = $db->query("SELECT id, username, nom, prenom FROM users WHERE role = 'client' AND statut = 'actif' ORDER BY nom, prenom");
$clients = $stmt->fetchAll();

$pageTitle = 'Messagerie';
include 'includes/header.php';
?>

<div class="page-content">
    <h1>Messagerie</h1>

    <?php
    $flash = getFlashMessage();
    if ($flash):
        ?>
        <div class="alert alert-<?php echo e($flash['type']); ?>">
            <?php echo e($flash['message']); ?>
        </div>
    <?php endif; ?>

    <div class="messages-actions">
        <button class="btn btn-primary" onclick="toggleForm('new-message-form')">
            Nouveau message
        </button>
    </div>

    <div id="new-message-form" class="form-container" style="display: none;">
        <h2>Nouveau message à un client</h2>
        <form method="POST" action="">
            <input type="hidden" name="action" value="send_message">

            <div class="form-group">
                <label for="destinataire_id">Destinataire *</label>
                <select id="destinataire_id" name="destinataire_id" class="form-control" required>
                    <option value="">Sélectionner un client</option>
                    <?php foreach ($clients as $client): ?>
                        <option value="<?php echo $client['id']; ?>">
                            <?php echo e($client['prenom'] . ' ' . $client['nom'] . ' (' . $client['username'] . ')'); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group">
                <label for="type_message">Type de message *</label>
                <select id="type_message" name="type_message" class="form-control" required>
                    <option value="normal">Normal</option>
                    <option value="notification">Notification</option>
                    <option value="alerte">Alerte</option>
                </select>
            </div>

            <div class="form-group">
                <label for="sujet">Sujet *</label>
                <input type="text" id="sujet" name="sujet" class="form-control" required>
            </div>

            <div class="form-group">
                <label for="contenu">Message *</label>
                <textarea id="contenu" name="contenu" class="form-control" rows="5" required></textarea>
            </div>

            <div class="form-actions">
                <button type="submit" class="btn btn-primary">Envoyer</button>
                <button type="button" class="btn btn-secondary" onclick="toggleForm('new-message-form')">Annuler</button>
            </div>
        </form>
    </div>

    <div class="messages-tabs">
        <button class="tab-button active" onclick="switchTab('received')">
            Messages reçus (<?php echo count($messagesRecus); ?>)
        </button>
        <button class="tab-button" onclick="switchTab('sent')">
            Messages envoyés (<?php echo count($messagesEnvoyes); ?>)
        </button>
    </div>

    <div id="received-tab" class="tab-content active">
        <h2>Messages reçus des clients</h2>

        <?php if (empty($messagesRecus)): ?>
            <p>Aucun message reçu.</p>
        <?php else: ?>
            <div class="messages-list">
                <?php foreach ($messagesRecus as $message): ?>
                    <div class="message-item <?php echo !$message['lu'] ? 'unread' : ''; ?>">
                        <div class="message-header">
                            <div class="message-from">
                                <strong><?php echo e($message['expediteur_prenom'] . ' ' . $message['expediteur_nom']); ?></strong>
                                <span class="text-muted">(<?php echo e($message['username']); ?>)</span>
                                <?php if (!$message['lu']): ?>
                                    <span class="badge badge-primary">Non lu</span>
                                <?php endif; ?>
                            </div>
                            <div class="message-date"><?php echo formatDateTime($message['date_envoi']); ?></div>
                        </div>
                        <div class="message-subject">
                            <strong><?php echo e($message['sujet']); ?></strong>
                        </div>
                        <div class="message-content">
                            <?php echo nl2br(e($message['contenu'])); ?>
                        </div>
                        <div class="message-actions">
                            <?php if (!$message['lu']): ?>
                                <a href="?action=mark_read&id=<?php echo $message['id']; ?>" class="btn btn-sm btn-secondary">
                                    Marquer comme lu
                                </a>
                            <?php endif; ?>
                            <button class="btn btn-sm btn-primary" onclick="showReplyForm(<?php echo $message['id']; ?>, <?php echo $message['expediteur_user_id']; ?>, '<?php echo e($message['expediteur_prenom'] . ' ' . $message['expediteur_nom']); ?>', '<?php echo e($message['sujet']); ?>')">
                                Répondre
                            </button>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <div id="sent-tab" class="tab-content">
        <h2>Messages envoyés aux clients</h2>

        <?php if (empty($messagesEnvoyes)): ?>
            <p>Aucun message envoyé.</p>
        <?php else: ?>
            <div class="messages-list">
                <?php foreach ($messagesEnvoyes as $message): ?>
                    <div class="message-item">
                        <div class="message-header">
                            <div class="message-from">
                                <strong>À : <?php echo e($message['destinataire_prenom'] . ' ' . $message['destinataire_nom']); ?></strong>
                                <span class="text-muted">(<?php echo e($message['username']); ?>)</span>
                                <?php if ($message['lu']): ?>
                                    <span class="badge badge-success">Lu le <?php echo formatDateTime($message['date_lecture']); ?></span>
                                <?php else: ?>
                                    <span class="badge badge-secondary">Non lu</span>
                                <?php endif; ?>
                                <?php if ($message['type_message'] === 'alerte'): ?>
                                    <span class="badge badge-danger">Alerte</span>
                                <?php elseif ($message['type_message'] === 'notification'): ?>
                                    <span class="badge badge-info">Notification</span>
                                <?php endif; ?>
                            </div>
                            <div class="message-date"><?php echo formatDateTime($message['date_envoi']); ?></div>
                        </div>
                        <div class="message-subject">
                            <strong><?php echo e($message['sujet']); ?></strong>
                        </div>
                        <div class="message-content">
                            <?php echo nl2br(e($message['contenu'])); ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Modal de réponse -->
<div id="reply-modal" class="modal" style="display: none;">
    <div class="modal-content">
        <h2>Répondre au message</h2>
        <p>Destinataire : <strong id="reply-client-name"></strong></p>
        <form method="POST" action="">
            <input type="hidden" name="action" value="reply_message">
            <input type="hidden" name="message_original_id" id="reply-message-id">
            <input type="hidden" name="destinataire_id" id="reply-destinataire-id">

            <div class="form-group">
                <label for="reply_sujet">Sujet *</label>
                <input type="text" id="reply_sujet" name="sujet" class="form-control" required>
            </div>

            <div class="form-group">
                <label for="reply_contenu">Message *</label>
                <textarea id="reply_contenu" name="contenu" class="form-control" rows="5" required></textarea>
            </div>

            <div class="form-actions">
                <button type="submit" class="btn btn-primary">Envoyer la réponse</button>
                <button type="button" class="btn btn-secondary" onclick="hideReplyForm()">Annuler</button>
            </div>
        </form>
    </div>
</div>

<script>
    function showReplyForm(messageId, destinataireId, clientName, sujetOriginal) {
        document.getElementById('reply-message-id').value = messageId;
        document.getElementById('reply-destinataire-id').value = destinataireId;
        document.getElementById('reply-client-name').textContent = clientName;
        document.getElementById('reply_sujet').value = 'RE: ' + sujetOriginal;
        document.getElementById('reply_contenu').value = '';
        document.getElementById('reply-modal').style.display = 'flex';
    }

    function hideReplyForm() {
        document.getElementById('reply-modal').style.display = 'none';
    }
</script>

<?php include 'includes/footer.php'; ?>
