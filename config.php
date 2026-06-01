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

// Database
$DB_PATH = __DIR__ . '/data/conversations.db';

// Buat folder data jika belum ada
if (!is_dir(__DIR__ . '/data')) {
    mkdir(__DIR__ . '/data', 0755, true);
}

// Daftar model yang tersedia untuk API key ini
$MODELS = [
    'gemini-2.5-flash',      // Model satu-satunya yang tersedia
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

// Chunk large text to avoid prompt size limits
function chunkLargeText($text, $maxSize = 30000) {
    $lines = explode("\n\n", $text); // Split by double newline (paragraphs)
    
    if (count($lines) <= 1) {
        // If single paragraph, just truncate with note
        if (strlen($text) > $maxSize) {
            return substr($text, 0, $maxSize) . "\n\n[... materi dipotong karena terlalu panjang ...]";
        }
        return $text;
    }

    // Try to fit as many paragraphs as possible
    $result = $lines[0]; // Start with first line
    
    for ($i = 1; $i < count($lines); $i++) {
        $nextSection = $lines[$i];
        if (strlen($result) + strlen($nextSection) + 4 < $maxSize) { // +4 for \n\n
            $result .= "\n\n" . $nextSection;
        } else {
            break;
        }
    }

    $result .= "\n\n[... materi panjang dipotong untuk efisiensi pemrosesan ...]";
    return $result;
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

    // Check if prompt is too large and needs chunking
    $promptSize = strlen($fullPrompt);
    $maxPromptSize = 30000; // 30KB limit
    
    if ($promptSize > $maxPromptSize && strpos($fullPrompt, "\n\n") !== false) {
        // Chunk the material if it's too large
        $fullPrompt = chunkLargeText($fullPrompt, $maxPromptSize);
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
    $maxRetries = 3; // Increased from 2

    foreach ($MODELS as $model) {
        $url = "https://generativelanguage.googleapis.com/v1beta/models/" . $model . ":generateContent";

        // Retry logic dengan exponential backoff untuk rate limiting
        $retryDelay = 1; // detik
        
        for ($retry = 0; $retry <= $maxRetries; $retry++) {
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                "Content-Type: application/json",
                "x-goog-api-key: " . API_KEY
            ]);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 120); // Increased from 90
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 15); // Increased from 10

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);

            if (!empty($curlError)) {
                $lastError = "ERROR_CURL ($model): " . $curlError;
                if ($retry < $maxRetries) {
                    sleep($retryDelay);
                    $retryDelay = min($retryDelay * 2, 8); // Max 8 second delay
                    continue;
                }
                break;
            }

            if ($httpCode !== 200) {
                $err = json_decode($response, true);
                $errorMsg = $err['error']['message'] ?? $err['error']['status'] ?? "HTTP $httpCode";

                // Rate limited - retry dengan delay lebih panjang
                if (strpos($errorMsg, 'high demand') !== false || 
                    strpos($errorMsg, 'RESOURCE_EXHAUSTED') !== false ||
                    strpos($errorMsg, 'too many requests') !== false ||
                    strpos($errorMsg, 'RATE_LIMIT_EXCEEDED') !== false) {
                    
                    if ($retry < $maxRetries) {
                        // Don't return error yet, retry dengan delay
                        sleep($retryDelay);
                        $retryDelay = min($retryDelay * 2, 10);
                        continue;
                    }
                    
                    $lastError = "⏳ Server AI sedang sibuk setelah $maxRetries percobaan.\nSilakan coba lagi dalam beberapa saat.";
                    break;
                }

                // Model tidak tersedia - skip ke model berikutnya
                if (strpos($errorMsg, 'not found') !== false || 
                    strpos($errorMsg, 'no longer available') !== false) {
                    $lastError = "ERROR_API ($model): " . $errorMsg;
                    break; // Skip ke model berikutnya
                }

                // Other errors - return immediately
                return "❌ ERROR_API ($model): " . $errorMsg;
            }

            $result = json_decode($response, true);

            if (isset($result['candidates'][0]['content']['parts'][0]['text'])) {
                $LAST_GEMINI_SOURCES = extractGeminiSources($result);
                return $result['candidates'][0]['content']['parts'][0]['text'];
            }

            if (isset($result['candidates'][0]['finishReason']) && $result['candidates'][0]['finishReason'] === 'SAFETY') {
                return "❌ ERROR: Konten diblokir oleh filter keamanan Google.";
            }

            $lastError = "ERROR: Format respons tidak dikenali dari model $model.";
            break; // Exit retry loop
        }
    }

    return $lastError . "\n\n💡 Tips: Jika error berlanjut, coba dengan pertanyaan yang lebih singkat atau file yang lebih kecil.";
}


// Database Functions untuk menyimpan percakapan
function getDB() {
    global $DB_PATH;
    try {
        $db = new PDO('sqlite:' . $DB_PATH);
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        return $db;
    } catch (Exception $e) {
        return null;
    }
}

function initDB() {
    $db = getDB();
    if (!$db) return false;
    
    try {
        $db->exec("CREATE TABLE IF NOT EXISTS conversations (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            title TEXT NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            summary TEXT
        )");
        
        $db->exec("CREATE TABLE IF NOT EXISTS messages (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            conversation_id INTEGER NOT NULL,
            role TEXT NOT NULL CHECK(role IN ('user', 'assistant')),
            content TEXT NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (conversation_id) REFERENCES conversations(id) ON DELETE CASCADE
        )");
        
        $db->exec("CREATE INDEX IF NOT EXISTS idx_messages_conversation ON messages(conversation_id)");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_conversations_updated ON conversations(updated_at DESC)");
        
        return true;
    } catch (Exception $e) {
        return false;
    }
}

function saveConversation($title, $messages) {
    $db = getDB();
    if (!$db) return null;
    
    try {
        $db->beginTransaction();
        
        $stmt = $db->prepare("INSERT INTO conversations (title, summary) VALUES (?, ?)");
        $summary = substr($title, 0, 100);
        $stmt->execute([$title, $summary]);
        $convId = $db->lastInsertId();
        
        $msgStmt = $db->prepare("INSERT INTO messages (conversation_id, role, content) VALUES (?, ?, ?)");
        foreach ($messages as $msg) {
            $msgStmt->execute([$convId, $msg['role'], $msg['content']]);
        }
        
        $db->commit();
        return $convId;
    } catch (Exception $e) {
        $db->rollBack();
        return null;
    }
}

function getConversations($limit = 20) {
    $db = getDB();
    if (!$db) return [];
    
    try {
        $stmt = $db->prepare("SELECT id, title, created_at, updated_at FROM conversations ORDER BY updated_at DESC LIMIT ?");
        $stmt->execute([$limit]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        return [];
    }
}

function getConversationMessages($convId) {
    $db = getDB();
    if (!$db) return [];
    
    try {
        $stmt = $db->prepare("SELECT role, content, created_at FROM messages WHERE conversation_id = ? ORDER BY created_at ASC");
        $stmt->execute([$convId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        return [];
    }
}

function updateConversationTitle($convId, $title) {
    $db = getDB();
    if (!$db) return false;
    
    try {
        $stmt = $db->prepare("UPDATE conversations SET title = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
        return $stmt->execute([$title, $convId]);
    } catch (Exception $e) {
        return false;
    }
}

function deleteConversation($convId) {
    $db = getDB();
    if (!$db) return false;
    
    try {
        $stmt = $db->prepare("DELETE FROM conversations WHERE id = ?");
        return $stmt->execute([$convId]);
    } catch (Exception $e) {
        return false;
    }
}

// Initialize DB on first load
initDB();
