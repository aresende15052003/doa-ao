<?php
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

require __DIR__ . '/common.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false]);
    exit;
}

$raw = file_get_contents('php://input') ?: '';
$data = json_decode($raw, true);

if (!is_array($data)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'message' => 'JSON inválido']);
    exit;
}

$event = (string)($data['event'] ?? '');
$token = (string)($data['token'] ?? '');

if ($event === '' || $token === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'message' => 'Evento ou token ausente']);
    exit;
}

/*
 * Validação: o token recebido precisa ser exatamente um webhookToken
 * devolvido pela AmploPay quando esta aplicação criou a cobrança.
 */
$index = load_json_file(token_index_file($token));
if (!$index || empty($index['transactionId'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'message' => 'Token de webhook inválido']);
    exit;
}

$transactionId = (string)$index['transactionId'];
$file = transaction_file($transactionId);
$record = load_json_file($file);

if (!$record) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'message' => 'Transação não encontrada']);
    exit;
}

$record['event'] = $event;
$record['updatedAt'] = gmdate('c');

switch ($event) {
    case 'TRANSACTION_PAID':
        $record['paid'] = true;
        $record['status'] = 'OK';
        $record['transactionStatus'] = 'COMPLETED';
        $record['paidAt'] = gmdate('c');
        break;

    case 'TRANSACTION_CANCELED':
        $record['paid'] = false;
        $record['transactionStatus'] = 'FAILED';
        $record['canceledAt'] = gmdate('c');
        break;

    case 'TRANSACTION_REFUNDED':
        $record['paid'] = false;
        $record['transactionStatus'] = 'REFUNDED';
        $record['refundedAt'] = gmdate('c');
        break;

    case 'TRANSACTION_CHARGED_BACK':
        $record['paid'] = false;
        $record['transactionStatus'] = 'CHARGED_BACK';
        $record['chargedBackAt'] = gmdate('c');
        break;

    case 'TRANSACTION_CREATED':
        if (empty($record['transactionStatus'])) {
            $record['transactionStatus'] = 'PENDING';
        }
        break;
}

save_json_file($file, $record);

http_response_code(200);
echo json_encode(['ok' => true]);
