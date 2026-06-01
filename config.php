<?php
require_once __DIR__ . '/vendor/autoload.php';
use Smalot\PdfParser\Parser;

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->safeLoad();

// GEMINI CONFIG - multiple models dengan fallback
$apiKey = $_ENV['GEMINI_API_KEY'] ?? '';
if ($apiKey === '' || $apiKey === 'YOUR_API_KEY_HERE') {
    http_response_code(500);
    exit('ERROR: GEMINI_API_KEY belum diatur. Buat file .env dari .env.example lalu isi API key di sana.');
}

define('API_KEY', $apiKey);
define('MAX_TOKENS', (int)($_ENV['MAX_TOKENS'] ?? 8000));

// Daftar model yang dicoba secara berurutan (fallback)
$MODELS = [
    'gemini-2.5-flash-lite',      // Model ringan, paling stabil untuk free tier
    'gemini-2.5-flash',           // Model standar (kadang overload)
    'gemini-3.1-flash-lite-preview', // Model preview 3.1
    'gemini-3-flash-preview',     // Model 3 series preview
];

function extractPDF($filePath) {
    try {
        $parser = new Parser();
        $pdf = $parser->parseFile($filePath);
        $text = $pdf->getText();
        $text = preg_replace('/\s+/', ' ', $text);
        return trim($text);
    } catch (Exception $e) {
        return "ERROR: " . $e->getMessage();
    }
}

function extractText($filePath) {
    try {
        $text = file_get_contents($filePath);
        $text = preg_replace('/\s+/', ' ', $text);
        return trim($text);
    } catch (Exception $e) {
        return "ERROR: " . $e->getMessage();
    }
}

function callGemini($prompt, $system = "") {
    global $MODELS;

    $contents = [];

    if (!empty($system)) {
        $fullPrompt = $system . "\n\n=== PERTANYAAN / TUGAS ===\n" . $prompt;
    } else {
        $fullPrompt = $prompt;
    }

    $contents[] = [
        "role" => "user",
        "parts" => [["text" => $fullPrompt]]
    ];

    $data = [
        "contents" => $contents,
        "generationConfig" => [
            "maxOutputTokens" => MAX_TOKENS,
            "temperature" => 0.7
        ]
    ];

    $lastError = "";

    foreach ($MODELS as $model) {
        $url = "https://generativelanguage.googleapis.com/v1beta/models/" . $model . ":generateContent";

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "Content-Type: application/json",
            "x-goog-api-key: " . API_KEY
        ]);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 60);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if (!empty($curlError)) {
            $lastError = "ERROR_CURL ($model): " . $curlError;
            continue;
        }

        if ($httpCode !== 200) {
            $err = json_decode($response, true);
            $errorMsg = $err['error']['message'] ?? $err['error']['status'] ?? "HTTP $httpCode";

            // Kalau error karena model unavailable atau high demand, coba model berikutnya
            if (strpos($errorMsg, 'no longer available') !== false || 
                strpos($errorMsg, 'high demand') !== false ||
                strpos($errorMsg, 'not found') !== false) {
                $lastError = "ERROR_API ($model): " . $errorMsg;
                continue; // Coba model berikutnya
            }

            return "ERROR_API ($model): " . $errorMsg;
        }

        $result = json_decode($response, true);

        if (isset($result['candidates'][0]['content']['parts'][0]['text'])) {
            return $result['candidates'][0]['content']['parts'][0]['text'];
        }

        if (isset($result['candidates'][0]['finishReason']) && $result['candidates'][0]['finishReason'] === 'SAFETY') {
            return "ERROR: Konten diblokir oleh filter keamanan Google.";
        }

        $lastError = "ERROR: Format respons tidak dikenali dari model $model.";
    }

    return $lastError . "\n\nSemua model telah dicoba. Silakan coba lagi nanti atau periksa API key.";
}
