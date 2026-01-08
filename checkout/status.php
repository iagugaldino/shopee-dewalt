<?php
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, api-secret');
header('Access-Control-Max-Age: 86400');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

session_start();
header('Content-Type: application/json; charset=utf-8');

$rawBody = file_get_contents('php://input');
$input = json_decode($rawBody, true);
if (!is_array($input)) $input = [];

$transactionId =
    ($input['id'] ?? null) ??
    ($input['transaction_id'] ?? null) ??
    ($input['transactionId'] ?? null) ??
    ($_POST['transaction_id'] ?? null) ??
    ($_POST['id'] ?? null) ??
    ($_GET['transaction_id'] ?? null) ??
    ($_GET['transactionId'] ?? null) ??
    ($_GET['id'] ?? null);

if (!$transactionId) {
    $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    $pathParts = explode('/', trim($path, '/'));
    $lastPart = end($pathParts);
    if ($lastPart && $lastPart !== 'status.php') $transactionId = $lastPart;
}

if (!$transactionId) {
    echo json_encode([
        'success' => false,
        'error' => 'Transaction ID não informado',
        'status' => 'waiting_payment',
        'message' => 'ID da transação não encontrado.'
    ]);
    exit;
}

$API_SECRET = 'sk_61037d319c59fed7064a8a4e763c59af6471a45b117737aa91d1095241a26e517460db9f785926691c43a652df03e2b269d042ba785ed5eaf6517f7972813a76';
$apiUrl = 'https://api.genesys.finance/v1/transactions/' . urlencode($transactionId);

$ch = curl_init($apiUrl);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => [
        'accept: application/json',
        'api-secret: ' . $API_SECRET
    ],
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
curl_close($ch);

if ($response === false) {
    echo json_encode([
        'success' => false,
        'status' => 'error',
        'error' => 'Erro ao executar requisição CURL',
        'message' => $curlError
    ]);
    exit;
}

$decoded = json_decode($response, true);
if (!is_array($decoded)) {
    echo json_encode([
        'success' => false,
        'status' => 'error',
        'error' => 'Resposta inválida da API',
        'httpCode' => $httpCode,
        'raw' => $response
    ]);
    exit;
}

if ($httpCode < 200 || $httpCode >= 300) {
    echo json_encode([
        'success' => false,
        'status' => 'error',
        'error' => 'Erro retornado pela API',
        'httpCode' => $httpCode,
        'response' => $decoded
    ]);
    exit;
}

$statusRaw = strtoupper((string)($decoded['status'] ?? 'PENDING'));
$paid = in_array($statusRaw, ['AUTHORIZED'], true);

echo json_encode([
    'success' => true,
    'paid' => $paid,
    'status' => strtolower($statusRaw),
    'transaction_id' => $transactionId,
    'transaction' => $decoded,
    'data' => $decoded,
    'response' => $decoded
]);
