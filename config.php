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
$LAST_GEMINI_SOURCES = [];

// Daftar model yang dicoba secara berurutan (fallback)
$MODELS = [
    'gemini-2.5-flash',
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

function extractPPTX($filePath) {
    if (!class_exists('ZipArchive')) {
        return "ERROR: Ekstensi PHP ZipArchive belum aktif. Aktifkan extension=zip di php.ini untuk membaca PPTX.";
    }

    $zip = new ZipArchive();
    if ($zip->open($filePath) !== true) {
        return "ERROR: File PPTX tidak bisa dibuka.";
    }

    $slides = [];
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = $zip->getNameIndex($i);
        if (!preg_match('#^ppt/slides/slide(\d+)\.xml$#', $name, $matches)) {
            continue;
        }

        $xml = $zip->getFromIndex($i);
        if ($xml === false) {
            continue;
        }

        preg_match_all('/<a:t>(.*?)<\/a:t>/s', $xml, $textMatches);
        $parts = array_map(function($value) {
            return html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_XML1, 'UTF-8');
        }, $textMatches[1] ?? []);

        $text = trim(preg_replace('/\s+/', ' ', implode(' ', $parts)));
        if ($text !== '') {
            $slides[(int)$matches[1]] = "Slide " . (int)$matches[1] . ": " . $text;
        }
    }

    $zip->close();
    ksort($slides);

    if (empty($slides)) {
        return "ERROR: Tidak ada teks yang bisa dibaca dari PPTX. Jika slide berupa gambar, upload gambar slide-nya.";
    }

    return implode("\n\n", $slides);
}

function createInlineDataPart($filePath, $mimeType) {
    $bytes = file_get_contents($filePath);
    if ($bytes === false) {
        return null;
    }

    return [
        "inline_data" => [
            "mime_type" => $mimeType,
            "data" => base64_encode($bytes)
        ]
    ];
}

function createImagePart($filePath, $mimeType) {
    return createInlineDataPart($filePath, $mimeType);
}

function extractPPTXImageParts($filePath, $maxImages = 8) {
    if (!class_exists('ZipArchive')) {
        return [];
    }

    $zip = new ZipArchive();
    if ($zip->open($filePath) !== true) {
        return [];
    }

    $mimeMap = [
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'webp' => 'image/webp',
    ];
    $parts = [];

    for ($i = 0; $i < $zip->numFiles && count($parts) < $maxImages; $i++) {
        $name = $zip->getNameIndex($i);
        if (!preg_match('#^ppt/media/.+\.(jpg|jpeg|png|webp)$#i', $name, $matches)) {
            continue;
        }

        $bytes = $zip->getFromIndex($i);
        if ($bytes === false) {
            continue;
        }

        $ext = strtolower($matches[1]);
        $parts[] = [
            "inline_data" => [
                "mime_type" => $mimeMap[$ext],
                "data" => base64_encode($bytes)
            ]
        ];
    }

    $zip->close();
    return $parts;
}

function extractGeminiSources($result) {
    $sources = [];
    $chunks = $result['candidates'][0]['groundingMetadata']['groundingChunks'] ??
        $result['candidates'][0]['grounding_metadata']['grounding_chunks'] ??
        [];

    foreach ($chunks as $chunk) {
        $web = $chunk['web'] ?? null;
        if (!$web || empty($web['uri'])) {
            continue;
        }

        $uri = $web['uri'];
        if (isset($sources[$uri])) {
            continue;
        }

        $sources[$uri] = [
            'title' => $web['title'] ?? $uri,
            'url' => $uri
        ];
    }

    return array_values($sources);
}

function callGemini($prompt, $system = "", $useGoogleSearch = false, $temperature = 0.4, $extraParts = []) {
    global $MODELS, $LAST_GEMINI_SOURCES;

    $LAST_GEMINI_SOURCES = [];

    $contents = [];

    if (!empty($system)) {
        $fullPrompt = $system . "\n\n=== PERTANYAAN / TUGAS ===\n" . $prompt;
    } else {
        $fullPrompt = $prompt;
    }

    $parts = [["text" => $fullPrompt]];
    foreach ($extraParts as $part) {
        $parts[] = $part;
    }

    $contents[] = [
        "role" => "user",
        "parts" => $parts
    ];

    $data = [
        "contents" => $contents,
        "generationConfig" => [
            "maxOutputTokens" => MAX_TOKENS,
            "temperature" => $temperature
        ]
    ];

    if ($useGoogleSearch) {
        $data["tools"] = [
            [
                "google_search" => new stdClass()
            ]
        ];
    }

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
            $LAST_GEMINI_SOURCES = extractGeminiSources($result);
            return $result['candidates'][0]['content']['parts'][0]['text'];
        }

        if (isset($result['candidates'][0]['finishReason']) && $result['candidates'][0]['finishReason'] === 'SAFETY') {
            return "ERROR: Konten diblokir oleh filter keamanan Google.";
        }

        $lastError = "ERROR: Format respons tidak dikenali dari model $model.";
    }

    return $lastError . "\n\nSemua model telah dicoba. Silakan coba lagi nanti atau periksa API key.";
}
