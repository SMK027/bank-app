<?php
require_once '../config/config.php';
requireClient();

$userId = $_SESSION['user_id'];
$db = getDB();

// Traitement de l'envoi de message
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'send_message') {
    $sujet = $_POST['sujet'] ?? '';
    $contenu = $_POST['contenu'] ?? '';
    
    if (empty($sujet) || empty($contenu)) {
        setFlashMessage('Veuillez remplir tous les champs.', 'danger');
    } else {
        // Envoyer à l'admin (ID 1)
        if (envoyerMessage($userId, 1, $sujet, $contenu)) {
            setFlashMessage('Message envoyé avec succès.', 'success');
            logActivity($userId, 'Envoi message', "Sujet: $sujet");
        } else {
            setFlashMessage('Erreur lors de l\'envoi du message.', 'danger');
        }
    }
    
    redirect(BASE_URL . '/client/messages.php');
}

// Marquer un message comme lu
if (isset($_GET['action']) && $_GET['action'] === 'mark_read' && isset($_GET['id'])) {
    $messageId = $_GET['id'];
    
    $stmt = $db->prepare("UPDATE messages SET lu = 1, date_lecture = NOW() WHERE id = ? AND destinataire_id = ?");
    $stmt->execute([$messageId, $userId]);
    
    redirect(BASE_URL . '/client/messages.php');
}

// Récupération des messages reçus
$stmt = $db->prepare("
    SELECT m.*, u.nom as expediteur_nom, u.prenom as expediteur_prenom 
    FROM messages m 
    INNER JOIN users u ON m.expediteur_id = u.id 
    WHERE m.destinataire_id = ? 
    ORDER BY m.date_envoi DESC
");
$stmt->execute([$userId]);
$messagesRecus = $stmt->fetchAll();

// Récupération des messages envoyés
$stmt = $db->prepare("
    SELECT m.*, u.nom as destinataire_nom, u.prenom as destinataire_prenom 
    FROM messages m 
    INNER JOIN users u ON m.destinataire_id = u.id 
    WHERE m.expediteur_id = ? 
    ORDER BY m.date_envoi DESC
");
$stmt->execute([$userId]);
$messagesEnvoyes = $stmt->fetchAll();

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
        <h2>Nouveau message à l'administration</h2>
        <form method="POST" action="">
            <input type="hidden" name="action" value="send_message">
            
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
        <h2>Messages reçus</h2>
        
        <?php if (empty($messagesRecus)): ?>
            <p>Aucun message reçu.</p>
        <?php else: ?>
            <div class="messages-list">
                <?php foreach ($messagesRecus as $message): ?>
                <div class="message-item <?php echo !$message['lu'] ? 'unread' : ''; ?>">
                    <div class="message-header">
                        <div class="message-from">
                            <strong><?php echo e($message['expediteur_prenom'] . ' ' . $message['expediteur_nom']); ?></strong>
                            <?php if (!$message['lu']): ?>
                                <span class="badge badge-primary">Non lu</span>
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
                    <?php if (!$message['lu']): ?>
                    <div class="message-actions">
                        <a href="?action=mark_read&id=<?php echo $message['id']; ?>" class="btn btn-sm btn-secondary">
                            Marquer comme lu
                        </a>
                    </div>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
    
    <div id="sent-tab" class="tab-content">
        <h2>Messages envoyés</h2>
        
        <?php if (empty($messagesEnvoyes)): ?>
            <p>Aucun message envoyé.</p>
        <?php else: ?>
            <div class="messages-list">
                <?php foreach ($messagesEnvoyes as $message): ?>
                <div class="message-item">
                    <div class="message-header">
                        <div class="message-from">
                            <strong>À : <?php echo e($message['destinataire_prenom'] . ' ' . $message['destinataire_nom']); ?></strong>
                            <?php if ($message['lu']): ?>
                                <span class="badge badge-success">Lu</span>
                            <?php else: ?>
                                <span class="badge badge-secondary">Non lu</span>
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

<?php include 'includes/footer.php'; ?>
