<?php

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

require __DIR__ . '/config.php';
require __DIR__ . '/common.php';

function responder($httpCode, $data) {
    http_response_code($httpCode);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    responder(405, [
        'ok' => false,
        'message' => 'Método não permitido.'
    ]);
}

$input = json_decode(file_get_contents('php://input'), true);

if (!is_array($input)) {
    responder(400, [
        'ok' => false,
        'message' => 'Dados inválidos.'
    ]);
}

$amount = isset($input['amount']) ? (float)$input['amount'] : 0;
$name = trim((string)($input['name'] ?? ''));
$email = trim((string)($input['email'] ?? ''));
$phone = trim((string)($input['phone'] ?? ''));
$document = trim((string)($input['document'] ?? ''));

/* O valor é validado no servidor; não confie apenas no data-value do HTML. */
$valorPermitido = false;
foreach (VALORES_PERMITIDOS as $permitido) {
    if (abs($amount - $permitido) < 0.001) {
        $valorPermitido = true;
        break;
    }
}

if (!$valorPermitido) {
    responder(400, [
        'ok' => false,
        'message' => 'Valor de contribuição inválido.'
    ]);
}

if ($name === '' || strlen($name) < 2) {
    responder(400, [
        'ok' => false,
        'message' => 'Informe seu nome.'
    ]);
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    responder(400, [
        'ok' => false,
        'message' => 'Informe um e-mail válido.'
    ]);
}

/*
 * identifier precisa ser único em cada transação.
 */
try {
    $random = bin2hex(random_bytes(6));
} catch (Exception $e) {
    $random = uniqid('', true);
}

$identifier = 'doacao_' . gmdate('YmdHis') . '_' . $random;

$client = [
    'name' => $name,
    'email' => $email
];

if ($phone !== '') {
    $client['phone'] = $phone;
}

if ($document !== '') {
    $client['document'] = $document;
}

$timezone = new DateTimeZone('America/Sao_Paulo');
$dueDate = new DateTime('now', $timezone);
$dueDate->modify('+1 day');

/*
 * A documentação fornecida não marca products como obrigatório,
 * então não inventamos um "produto" para uma contribuição.
 */
$baseUrl = current_base_url();
if ($baseUrl === '') {
    responder(500, [
        'ok' => false,
        'message' => 'Não foi possível identificar o domínio do site para configurar o webhook.'
    ]);
}

$payload = [
    'identifier' => $identifier,
    'amount' => $amount,
    'client' => $client,
    'dueDate' => $dueDate->format('Y-m-d'),
    'metadata' => [
        'source' => 'pagina-doacao',
        'identifier' => $identifier
    ],
    'callbackUrl' => $baseUrl . '/api/amplopay/webhook.php'
];

$ch = curl_init(AMPLOPAY_PIX_ENDPOINT);

curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_CONNECTTIMEOUT => 10,
    CURLOPT_TIMEOUT => 30,
    CURLOPT_HTTPHEADER => [
        'Accept: application/json',
        'Content-Type: application/json',
        'x-public-key: ' . AMPLOPAY_PUBLIC_KEY,
        'x-secret-key: ' . AMPLOPAY_SECRET_KEY
    ],
    CURLOPT_POSTFIELDS => json_encode(
        $payload,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    )
]);

$responseBody = curl_exec($ch);
$curlError = curl_error($ch);
$httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);

curl_close($ch);

if ($responseBody === false) {
    responder(502, [
        'ok' => false,
        'message' => 'Não foi possível conectar à AmploPay.',
        'details' => $curlError
    ]);
}

$data = json_decode($responseBody, true);

if (!is_array($data)) {
    responder(502, [
        'ok' => false,
        'message' => 'A AmploPay retornou uma resposta inválida.'
    ]);
}

if ($httpCode < 200 || $httpCode >= 300) {
    $message =
        $data['message']
        ?? $data['errorDescription']
        ?? 'A AmploPay recusou a criação do PIX.';

    responder(
        ($httpCode >= 400 && $httpCode < 500) ? 400 : 502,
        [
            'ok' => false,
            'message' => $message,
            'errorCode' => $data['errorCode'] ?? null,
            'details' => $data['details'] ?? null
        ]
    );
}

$pix = isset($data['pix']) && is_array($data['pix'])
    ? $data['pix']
    : [];

$code = (string)($pix['code'] ?? '');
$image = (string)($pix['image'] ?? '');
$expiresAt = (string)($pix['expiresAt'] ?? '');

if ($code === '') {
    responder(502, [
        'ok' => false,
        'message' => 'O PIX foi solicitado, mas a AmploPay não retornou o código Copia e Cola.',
        'transactionId' => $data['transactionId'] ?? null
    ]);
}

/*
 * Salva o transactionId e o webhookToken retornado pela AmploPay.
 * O token não é enviado ao navegador; ele serve para validar o webhook.
 */
$transactionId = (string)($data['transactionId'] ?? '');
$webhookToken = (string)($data['webhookToken'] ?? '');

if ($transactionId !== '') {
    $record = [
        'transactionId' => $transactionId,
        'identifier' => $identifier,
        'amount' => $amount,
        'status' => (string)($data['status'] ?? ''),
        'transactionStatus' => (string)($data['transactionStatus'] ?? ''),
        'paid' => (($data['transactionStatus'] ?? '') === 'COMPLETED'),
        'event' => null,
        'createdAt' => gmdate('c'),
        'updatedAt' => gmdate('c'),
        'webhookTokenHash' => $webhookToken !== '' ? hash('sha256', $webhookToken) : null
    ];
    save_json_file(transaction_file($transactionId), $record);

    if ($webhookToken !== '') {
        save_json_file(token_index_file($webhookToken), [
            'transactionId' => $transactionId,
            'createdAt' => gmdate('c')
        ]);
    }
}

/* O navegador recebe apenas os dados necessários para exibir o PIX. */
responder(200, [
    'ok' => true,
    'transactionId' => $transactionId !== '' ? $transactionId : null,
    'status' => $data['status'] ?? null,
    'transactionStatus' => $data['transactionStatus'] ?? null,
    'code' => $code,
    'image' => $image,
    'expiresAt' => $expiresAt
]);
