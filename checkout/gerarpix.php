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

// ============================================
// CONFIGURAÇÃO DO WEBHOOK URL
// ============================================
// 
// ⚠️ IMPORTANTE: A API Genesys precisa de uma URL pública acessível
//
// 📍 PARA PRODUÇÃO (Hostinger):
//   1. Após hospedar, defina abaixo a URL completa do seu domínio
//   2. Exemplo: 'https://seudominio.com/webhook-genesys.php'
//   3. Ou: 'https://seudominio.hostingar.com.br/webhook-genesys.php'
//
// 🛠️ PARA DESENVOLVIMENTO LOCAL:
//   Opção 1: Use ngrok
//     - Instale: https://ngrok.com/
//     - Execute: ngrok http 80
//     - Defina: 'https://seu-ngrok.ngrok.io/webhook-genesys.php'
//
//   Opção 2: Use webhook.site (temporário)
//     - Acesse: https://webhook.site/
//     - Copie sua URL e defina abaixo
//
// Deixe vazio para tentar URL automática (não funciona com localhost)
define('WEBHOOK_URL_CUSTOM', ''); // <-- DEFINA SUA URL AQUI QUANDO HOSPEDAR
// ============================================

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false]);
    exit;
}

$inputRaw = file_get_contents('php://input');
$input = json_decode($inputRaw, true);
if (!is_array($input)) $input = [];

$amount = floatval($input['amount'] ?? 0);
if ($amount < 1) {
    echo json_encode(['success' => false, 'error' => "Valor inválido: $amount"]);
    exit;
}

$comprador = $input['comprador'] ?? [];
$nome = trim($comprador['nome'] ?? '');
$email = trim($comprador['email'] ?? '');
$telefone = trim($comprador['telefone'] ?? '');
$cpf = trim($comprador['cpf'] ?? '');

$cpf = preg_replace('/\D/', '', $cpf);
$telefone = preg_replace('/\D/', '', $telefone);

if ($nome === '') {
    echo json_encode(['success' => false, 'error' => 'Nome do comprador é obrigatório']);
    exit;
}
if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    echo json_encode(['success' => false, 'error' => 'E-mail inválido']);
    exit;
}
if ($telefone === '' || strlen($telefone) < 10) {
    echo json_encode(['success' => false, 'error' => 'Telefone inválido']);
    exit;
}
if ($cpf === '' || strlen($cpf) < 11) {
    echo json_encode(['success' => false, 'error' => 'CPF inválido']);
    exit;
}

$utm = [];
if (!empty($input['utm'])) {
    if (is_array($input['utm'])) {
        $utm = $input['utm'];
    } elseif (is_string($input['utm'])) {
        parse_str(ltrim($input['utm'], '?'), $utm);
    }
}
if (empty($utm) && !empty($_SERVER['QUERY_STRING'])) {
    parse_str($_SERVER['QUERY_STRING'], $utm);
}

$utm_source = (string)($utm['utm_source'] ?? '');
$utm_medium = (string)($utm['utm_medium'] ?? '');
$utm_campaign = (string)($utm['utm_campaign'] ?? '');
$utm_content = (string)($utm['utm_content'] ?? '');
$utm_term = (string)($utm['utm_term'] ?? '');

$carrinho = $input['carrinho'] ?? [];
$items = [];

if (empty($carrinho)) {
    $items[] = [
        'id' => 'item_1',
        'title' => 'Pedido',
        'description' => '',
        'price' => round($amount, 2),
        'quantity' => 1,
        'is_physical' => true
    ];
} else {
    foreach ($carrinho as $i => $item) {
        $titulo = trim($item['titulo'] ?? $item['nome'] ?? 'Produto');
        $preco = floatval($item['preco'] ?? 0);
        $quantidade = intval($item['quantidade'] ?? 1);
        if ($preco > 0 && $quantidade > 0) {
            $items[] = [
                'id' => (string)($item['id'] ?? ('item_' . ($i + 1))),
                'title' => $titulo,
                'description' => (string)($item['descricao'] ?? $item['description'] ?? ''),
                'price' => round($preco, 2),
                'quantity' => $quantidade,
                'is_physical' => (bool)($item['is_physical'] ?? true)
            ];
        }
    }

    if (empty($items)) {
        $items[] = [
            'id' => 'item_1',
            'title' => 'Pedido',
            'description' => '',
            'price' => round($amount, 2),
            'quantity' => 1,
            'is_physical' => true
        ];
    }
}

$ip = (string)($input['ip'] ?? $_SERVER['REMOTE_ADDR'] ?? '');

// Constrói URL base completa
$isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['SERVER_PORT'] ?? '') == 443);
$scheme = $isHttps ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'] ?? '';

// Constrói URL completa do webhook na raiz do domínio
$defaultWebhook = '';
if ($host) {
    // Remove porta se presente (para evitar duplicação)
    $host = preg_replace('/:\d+$/', '', $host);
    $defaultWebhook = $scheme . '://' . $host . '/webhook-genesys.php';
}

$externalId = (string)($input['external_id'] ?? $input['externalId'] ?? ('ext_' . time() . '_' . bin2hex(random_bytes(4))));

// Prioridade: 1) URL customizada definida no código, 2) URL do input, 3) URL automática
$webhookUrl = '';
if (!empty(WEBHOOK_URL_CUSTOM)) {
    $webhookUrl = WEBHOOK_URL_CUSTOM;
} elseif (!empty($input['webhook_url'])) {
    $webhookUrl = (string)$input['webhook_url'];
} elseif (!empty($input['webhookUrl'])) {
    $webhookUrl = (string)$input['webhookUrl'];
} else {
    $webhookUrl = $defaultWebhook;
}

// Valida se é uma URL válida
if ($webhookUrl === '') {
    echo json_encode(['success' => false, 'error' => 'webhook_url é obrigatório (defina um webhook_url ou configure /webhook-genesys.php no seu domínio).']);
    exit;
}

// Garante que seja uma URL válida (começa com http:// ou https://)
if (!preg_match('/^https?:\/\//i', $webhookUrl)) {
    // Se não começa com http:// ou https://, adiciona o scheme e host
    if ($host) {
        $host = preg_replace('/:\d+$/', '', $host);
        $webhookUrl = $scheme . '://' . $host . '/' . ltrim($webhookUrl, '/');
    } else {
        echo json_encode(['success' => false, 'error' => 'webhook_url inválido: deve ser uma URL completa (ex: https://seudominio.com/webhook-genesys.php)']);
        exit;
    }
}

// Valida formato de URL usando filter_var
$validatedUrl = filter_var($webhookUrl, FILTER_VALIDATE_URL);
if ($validatedUrl === false) {
    echo json_encode([
        'success' => false, 
        'error' => 'webhook_url inválido: formato de URL incorreto',
        'webhook_url_tentado' => $webhookUrl,
        'host' => $host,
        'scheme' => $scheme,
        'dica' => 'O webhook_url deve ser uma URL pública acessível (não localhost). Use um serviço como ngrok para desenvolvimento local.'
    ]);
    exit;
}

// Usa a URL validada
$webhookUrl = $validatedUrl;

// Verifica se está em localhost
$isLocalhost = preg_match('/localhost|127\.0\.0\.1|::1/i', $webhookUrl);

if ($isLocalhost) {
    error_log("AVISO: webhook_url está usando localhost. A API Genesys não aceita localhost.");
    
    // Se estiver em localhost e não houver webhook_url customizado, retorna erro com instruções
    if (empty(WEBHOOK_URL_CUSTOM) && empty($input['webhook_url']) && empty($input['webhookUrl'])) {
        echo json_encode([
            'success' => false, 
            'error' => 'webhook_url não pode ser localhost. A API Genesys precisa de uma URL pública acessível.',
            'webhook_url_gerado' => $webhookUrl,
            'modo_desenvolvimento' => true,
            'solucoes' => [
                'opcao_1' => [
                    'titulo' => 'Usar ngrok (Recomendado)',
                    'passos' => [
                        '1. Instale ngrok: https://ngrok.com/',
                        '2. Execute: ngrok http 80 (ou a porta do seu servidor)',
                        '3. Copie a URL do ngrok (ex: https://abc123.ngrok.io)',
                        '4. Edite gerarpix.php linha ~17 e defina: define(\'WEBHOOK_URL_CUSTOM\', \'https://abc123.ngrok.io/webhook-genesys.php\');'
                    ]
                ],
                'opcao_2' => [
                    'titulo' => 'Usar webhook.site (Temporário para testes)',
                    'passos' => [
                        '1. Acesse: https://webhook.site/',
                        '2. Copie sua URL única (ex: https://webhook.site/abc123-def456)',
                        '3. Edite gerarpix.php linha ~17 e defina: define(\'WEBHOOK_URL_CUSTOM\', \'https://webhook.site/abc123-def456\');'
                    ]
                ],
                'opcao_3' => [
                    'titulo' => 'Enviar webhook_url no JavaScript',
                    'passos' => [
                        '1. No checkout/index.html, ao chamar gerarpix.php, adicione:',
                        '2. webhook_url: \'https://seu-ngrok.ngrok.io/webhook-genesys.php\' no body da requisição'
                    ]
                ]
            ]
        ]);
        exit;
    }
}

// Log para debug (remover em produção)
error_log("Webhook URL sendo enviado: $webhookUrl");

$API_SECRET = 'sk_61037d319c59fed7064a8a4e763c59af6471a45b117737aa91d1095241a26e517460db9f785926691c43a652df03e2b269d042ba785ed5eaf6517f7972813a76';
$apiUrl = 'https://api.genesys.finance/v1/transactions';

$payload = [
    'external_id' => $externalId,
    'total_amount' => round($amount, 2),
    'payment_method' => 'PIX',
    'webhook_url' => $webhookUrl,
    'items' => $items,
    'ip' => $ip,
    'customer' => [
        'name' => $nome,
        'email' => $email,
        'phone' => $telefone,
        'document_type' => 'CPF',
        'document' => $cpf,
        'utm_source' => $utm_source,
        'utm_medium' => $utm_medium,
        'utm_campaign' => $utm_campaign,
        'utm_content' => $utm_content,
        'utm_term' => $utm_term
    ]
];

if (!empty($input['splits']) && is_array($input['splits'])) {
    $splits = [];
    foreach ($input['splits'] as $s) {
        $rid = trim((string)($s['recipient_id'] ?? ''));
        $pct = isset($s['percentage']) ? floatval($s['percentage']) : null;
        if ($rid !== '' && $pct !== null) {
            $splits[] = ['recipient_id' => $rid, 'percentage' => $pct];
        }
    }
    if (!empty($splits)) $payload['splits'] = $splits;
}

$payloadJson = json_encode($payload, JSON_UNESCAPED_UNICODE);

// Log para debug (remover em produção)
error_log("Payload sendo enviado para API: " . $payloadJson);
error_log("Webhook URL no payload: " . $webhookUrl);

$ch = curl_init($apiUrl);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, $payloadJson);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'accept: application/json',
    'content-type: application/json',
    'api-secret: ' . $API_SECRET
]);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
curl_setopt($ch, CURLOPT_MAXREDIRS, 5);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
curl_close($ch);

if ($response === false) {
    echo json_encode([
        'success' => false,
        'error' => 'Erro ao comunicar com a API de pagamento',
        'detail' => $curlError,
        'httpCode' => $httpCode
    ]);
    exit;
}

$decoded = json_decode($response, true);
if ($decoded === null) {
    echo json_encode([
        'success' => false,
        'error' => 'Resposta inválida da API',
        'raw' => $response,
        'httpCode' => $httpCode
    ]);
    exit;
}

if ($httpCode < 200 || $httpCode >= 300) {
    // Se o erro for relacionado ao webhook_url, adiciona informações de debug
    $errorMsg = 'Erro retornado pela API de pagamento';
    $debugInfo = [];
    
    if (isset($decoded['errorFields']) && is_array($decoded['errorFields'])) {
        foreach ($decoded['errorFields'] as $field) {
            if (strpos($field, 'webhook_url') !== false) {
                $debugInfo['webhook_url_enviado'] = $webhookUrl;
                $debugInfo['webhook_url_validado'] = filter_var($webhookUrl, FILTER_VALIDATE_URL) !== false;
                $debugInfo['is_localhost'] = preg_match('/localhost|127\.0\.0\.1|::1/i', $webhookUrl);
                $debugInfo['solucao'] = 'A API Genesys não aceita localhost. Use uma URL pública (ex: https://seudominio.com/webhook-genesys.php) ou ngrok para desenvolvimento.';
                break;
            }
        }
    }
    
    echo json_encode([
        'success' => false,
        'error' => $errorMsg,
        'response' => $decoded,
        'httpCode' => $httpCode,
        'debug' => $debugInfo
    ]);
    exit;
}

$pixCode = $decoded['pix']['payload'] ?? null;
$transactionId = $decoded['id'] ?? null;

if (!$pixCode) {
    echo json_encode([
        'success' => false,
        'error' => 'Resposta da API não contém código PIX',
        'response' => $decoded,
        'httpCode' => $httpCode
    ]);
    exit;
}

echo json_encode([
    'success' => true,
    'pix_code' => $pixCode,
    'transaction_id' => $transactionId,
    'amount' => $amount,
    'status' => $decoded['status'] ?? null
]);
