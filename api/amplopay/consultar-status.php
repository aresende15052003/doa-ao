<?php
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

require __DIR__ . '/common.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['ok' => false]);
    exit;
}

$id = trim((string)($_GET['id'] ?? ''));
if ($id === '' || strlen($id) > 200) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'message' => 'ID inválido']);
    exit;
}

$record = load_json_file(transaction_file($id));
if (!$record) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'message' => 'Transação não encontrada']);
    exit;
}

echo json_encode([
    'ok' => true,
    'paid' => !empty($record['paid']),
    'status' => $record['status'] ?? null,
    'transactionStatus' => $record['transactionStatus'] ?? null,
    'event' => $record['event'] ?? null
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
