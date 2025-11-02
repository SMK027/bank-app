<?php
require_once '../../config/config.php';
requireAdmin();

header('Content-Type: application/json');

$creditId = $_GET['credit_id'] ?? 0;

if ($creditId <= 0) {
    echo json_encode([]);
    exit;
}

$db = getDB();

$stmt = $db->prepare("SELECT * FROM echeances_credit WHERE credit_id = ? ORDER BY numero_echeance ASC");
$stmt->execute([$creditId]);
$echeances = $stmt->fetchAll();

// Formater les données
$result = [];
foreach ($echeances as $echeance) {
    $result[] = [
        'id' => $echeance['id'],
        'numero_echeance' => $echeance['numero_echeance'],
        'date_echeance' => formatDate($echeance['date_echeance']),
        'montant' => number_format($echeance['montant'], 2, ',', ' '),
        'statut' => ucfirst($echeance['statut']),
        'date_paiement' => $echeance['date_paiement'] ? formatDateTime($echeance['date_paiement']) : null
    ];
}

echo json_encode($result);
