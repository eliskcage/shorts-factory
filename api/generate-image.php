<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { exit(0); }

$input = json_decode(file_get_contents('php://input'), true);
$prompt = trim($input['prompt'] ?? '');
$style = trim($input['style'] ?? 'cinematic');

if (!$prompt) {
    echo json_encode(['error' => 'No prompt provided']);
    exit;
}

$grokUrl = 'https://api.x.ai/v1/images/generations';
$grokKey = getenv('GROK_API_KEY') ?: trim(file_get_contents('/var/www/vhosts/shortfactory.shop/httpdocs/.grok-key'));

$payload = [
    'model' => 'grok-2-image',
    'prompt' => $prompt . ', ' . $style . ', high quality, cinematic lighting, 16:9 aspect ratio',
    'n' => 1,
    'response_format' => 'url'
];

$ch = curl_init($grokUrl);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_HTTPHEADER => [
        'Authorization: Bearer ' . $grokKey,
        'Content-Type: application/json'
    ],
    CURLOPT_POSTFIELDS => json_encode($payload),
    CURLOPT_TIMEOUT => 60
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($httpCode !== 200) {
    echo json_encode(['error' => 'Grok API error: ' . $httpCode, 'detail' => $response]);
    exit;
}

$data = json_decode($response, true);
$imageUrl = $data['data'][0]['url'] ?? null;

if (!$imageUrl) {
    echo json_encode(['error' => 'No image returned from Grok']);
    exit;
}

$outDir = __DIR__ . '/../images/';
if (!is_dir($outDir)) mkdir($outDir, 0755, true);

$filename = 'grok_' . time() . '_' . mt_rand(1000, 9999) . '.png';
$localPath = $outDir . $filename;
file_put_contents($localPath, file_get_contents($imageUrl));

echo json_encode([
    'url' => '/shorts/images/' . $filename,
    'prompt' => $prompt
]);
