<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/prompts.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$mode = $_POST['mode'] ?? 'qa';
$question = $_POST['question'] ?? '';

$material = '';
$sourceType = 'none';
$hasMaterial = false;
$extraParts = [];

// Handle file upload (PDF, TXT, PPTX, or image)
if (isset($_FILES['file']) && $_FILES['file']['error'] === UPLOAD_ERR_OK) {
    $tmpPath = $_FILES['file']['tmp_name'];
    $originalName = $_FILES['file']['name'] ?? '';
    $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    $mimeType = $_FILES['file']['type'] ?? '';

    if ($ext === 'pdf') {
        $material = extractPDF($tmpPath);
        $sourceType = 'pdf';
    } elseif ($ext === 'txt') {
        $material = extractText($tmpPath);
        $sourceType = 'txt';
    } elseif ($ext === 'pptx') {
        $material = extractPPTX($tmpPath);
        $sourceType = 'pptx';
    } elseif ($ext === 'ppt') {
        echo json_encode(['error' => 'File .ppt lama belum didukung. Simpan ulang presentasi sebagai .pptx lalu upload lagi.']);
        exit;
    } elseif (in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true)) {
        $mimeMap = [
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'webp' => 'image/webp',
        ];
        $imagePart = createImagePart($tmpPath, $mimeMap[$ext] ?? $mimeType);
        if ($imagePart === null) {
            echo json_encode(['error' => 'Gagal membaca gambar.']);
            exit;
        }
        $extraParts[] = $imagePart;
        $material = 'User mengunggah gambar. Analisis isi gambar tersebut sebagai materi atau konteks pertanyaan.';
        $sourceType = 'image';
    } else {
        echo json_encode(['error' => 'Format file tidak didukung. Gunakan PDF, TXT, PPTX, JPG, PNG, atau WEBP.']);
        exit;
    }
    $hasMaterial = true;
} 
// Handle raw text paste
elseif (isset($_POST['rawtext']) && !empty(trim($_POST['rawtext']))) {
    $material = trim($_POST['rawtext']);
    $sourceType = 'rawtext';
    $hasMaterial = true;
}

// For Q&A mode, material is optional
if ($mode === 'qa') {
    if (empty($question)) {
        echo json_encode(['error' => 'Pertanyaan tidak boleh kosong']);
        exit;
    }
} else {
    // For other modes, material is required
    if (!$hasMaterial) {
        echo json_encode(['error' => 'Silakan upload file PDF/TXT/PPTX/gambar atau paste teks terlebih dahulu']);
        exit;
    }
}

if ($hasMaterial && strpos($material, 'ERROR') === 0) {
    echo json_encode(['error' => 'Gagal membaca file: ' . $material]);
    exit;
}

// Check material length for non-QA modes
$warning = '';
if ($mode !== 'qa' && $hasMaterial && strlen($material) < 100) {
    $warning = $WARNING_MATERIAL;
}

// Trim very long material
if ($hasMaterial && strlen($material) > 15000) {
    $material = substr($material, 0, 15000) . "\n\n[... materi dipotong karena terlalu panjang ...]";
}

$allowed = ['ringkasan', 'soal', 'flashcard', 'qa'];
if (!in_array($mode, $allowed)) {
    echo json_encode(['error' => 'Mode tidak valid']);
    exit;
}

$prompt = '';
$system = '';
$useGoogleSearch = false;
$monthNames = [
    1 => 'Januari',
    2 => 'Februari',
    3 => 'Maret',
    4 => 'April',
    5 => 'Mei',
    6 => 'Juni',
    7 => 'Juli',
    8 => 'Agustus',
    9 => 'September',
    10 => 'Oktober',
    11 => 'November',
    12 => 'Desember',
];
$now = new DateTime('now', new DateTimeZone('Asia/Makassar'));
$currentDateContext = sprintf(
    "Tanggal hari ini adalah %s %s %s. Zona waktu server: Asia/Makassar.",
    $now->format('j'),
    $monthNames[(int)$now->format('n')],
    $now->format('Y')
);

switch ($mode) {
    case 'qa':
        if ($hasMaterial && !empty($material)) {
            $system = str_replace('{{ISI_PDF}}', $material, $SYSTEM_QA);
        } else {
            $system = 'Kamu adalah AI Study Helper, asisten belajar cerdas untuk mahasiswa Indonesia. Jawab pertanyaan dengan bahasa yang ramah, jelas, dan mudah dipahami. Berikan contoh konkret jika membantu.';
        }
        $system .= "\n\nKonteks waktu: " . $currentDateContext . " Jika user bertanya tanggal, hari, atau waktu sekarang, gunakan konteks waktu ini dan jangan menebak.";
        $system .= "\n\nUntuk pertanyaan tentang data terbaru seperti jadwal rilis, berita, versi software, harga, atau event terkini, gunakan Google Search grounding yang tersedia.";
        $system .= "\nAturan jawaban data terbaru:";
        $system .= "\n1. Jangan menjawab dengan kalimat proses seperti 'kita perlu mencari informasi terbaru'.";
        $system .= "\n2. Jika hasil pencarian memuat jawabannya, mulai jawaban dengan informasi inti secara langsung, misalnya tanggal, nama, versi, harga, atau status.";
        $system .= "\n3. Jika ada beberapa sumber yang berbeda, sebutkan jawaban yang paling konsisten dan tambahkan catatan singkat bahwa jadwal bisa berubah.";
        $system .= "\n4. Jika hasil pencarian tidak memuat jawaban yang jelas, baru katakan bahwa datanya belum bisa dipastikan.";
        $system .= "\n5. Jangan mencantumkan daftar sumber di teks jawaban karena aplikasi akan menampilkan sumber secara terpisah.";
        $useGoogleSearch = true;
        $prompt = $question;
        break;
    case 'ringkasan':
        $prompt = str_replace('{{ISI_PDF}}', $material, $PROMPT_RINGKASAN);
        break;
    case 'soal':
        $prompt = str_replace('{{ISI_PDF}}', $material, $PROMPT_SOAL);
        break;
    case 'flashcard':
        $prompt = str_replace('{{ISI_PDF}}', $material, $PROMPT_FLASHCARD);
        break;
}

$result = callGemini($prompt, $system, $useGoogleSearch, $mode === 'qa' ? 0.25 : 0.7, $extraParts);

$unansweredPatterns = [
    'perlu mencari informasi terbaru',
    'perlu mencari informasi',
    'untuk mengetahui kapan',
    'saya perlu mencari',
];
$needsDirectRetry = false;
if ($mode === 'qa' && !empty($LAST_GEMINI_SOURCES)) {
    $lowerResult = strtolower($result);
    foreach ($unansweredPatterns as $pattern) {
        if (strpos($lowerResult, $pattern) !== false) {
            $needsDirectRetry = true;
            break;
        }
    }
}

if ($needsDirectRetry) {
    $retrySystem = $system . "\n\nJawab ulang secara langsung berdasarkan sumber yang ditemukan. Jangan jelaskan bahwa kamu perlu mencari. Jangan hanya memberi daftar sumber. Mulai dengan jawaban inti dalam kalimat pertama.";
    $result = callGemini($prompt, $retrySystem, $useGoogleSearch, 0.15, $extraParts);
}

if (strpos($result, 'ERROR') === 0) {
    echo json_encode(['error' => $result]);
    exit;
}

$response = [
    'success' => true,
    'result' => $result,
    'mode' => $mode,
    'source' => $sourceType,
    'sources' => $LAST_GEMINI_SOURCES ?? []
];
if (!empty($warning)) {
    $response['warning'] = $warning;
}

echo json_encode($response);
