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
$qaStyle = $_POST['qa_style'] ?? 'study';
if (!in_array($qaStyle, ['study', 'bff'], true)) {
    $qaStyle = 'study';
}
$historyJson = $_POST['history'] ?? '[]';
$conversationHistory = [];

if ($mode === 'qa' && is_string($historyJson) && $historyJson !== '') {
    $decodedHistory = json_decode($historyJson, true);
    if (is_array($decodedHistory)) {
        $conversationHistory = array_slice($decodedHistory, -8);
    }
}

$material = '';
$sourceType = 'none';
$hasMaterial = false;
$extraParts = [];

function uploadErrorMessage($code) {
    $uploadMax = ini_get('upload_max_filesize');
    $postMax = ini_get('post_max_size');
    $messages = [
        UPLOAD_ERR_INI_SIZE => "File terlalu besar. Batas PHP saat ini: upload_max_filesize=$uploadMax, post_max_size=$postMax.",
        UPLOAD_ERR_FORM_SIZE => 'File terlalu besar untuk form upload.',
        UPLOAD_ERR_PARTIAL => 'Upload file tidak lengkap. Coba upload ulang.',
        UPLOAD_ERR_NO_FILE => 'Tidak ada file yang diterima server.',
        UPLOAD_ERR_NO_TMP_DIR => 'Folder temporary upload PHP tidak tersedia.',
        UPLOAD_ERR_CANT_WRITE => 'Server gagal menyimpan file upload sementara.',
        UPLOAD_ERR_EXTENSION => 'Upload dihentikan oleh ekstensi PHP.',
    ];

    return $messages[$code] ?? 'Upload file gagal dengan kode error: ' . $code;
}

// Handle file upload (PDF, TXT, PPTX, or image)
if (isset($_FILES['file']) && $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
    echo json_encode(['error' => uploadErrorMessage($_FILES['file']['error'])]);
    exit;
}

if (isset($_FILES['file']) && $_FILES['file']['error'] === UPLOAD_ERR_OK) {
    $tmpPath = $_FILES['file']['tmp_name'];
    $originalName = $_FILES['file']['name'] ?? '';
    $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    $mimeType = $_FILES['file']['type'] ?? '';

    if ($ext === 'pdf') {
        $pdfPart = createInlineDataPart($tmpPath, 'application/pdf');
        if ($pdfPart === null) {
            echo json_encode(['error' => 'Gagal membaca PDF yang diupload.']);
            exit;
        }
        $extraParts[] = $pdfPart;
        $extractedText = extractPDF($tmpPath);
        if (strpos($extractedText, 'ERROR') === 0 || trim($extractedText) === '') {
            $material = 'User mengunggah PDF. Baca dan analisis isi PDF yang dilampirkan langsung.';
        } else {
            $material = $extractedText;
        }
        $sourceType = 'pdf';
    } elseif ($ext === 'txt') {
        $material = extractText($tmpPath);
        $sourceType = 'txt';
    } elseif ($ext === 'pptx') {
        $material = extractPPTX($tmpPath);
        $pptxImageParts = extractPPTXImageParts($tmpPath);
        foreach ($pptxImageParts as $part) {
            $extraParts[] = $part;
        }
        if (strpos($material, 'ERROR') === 0 && !empty($pptxImageParts)) {
            $material = 'User mengunggah PPTX. Teks slide tidak bisa diekstrak, tetapi gambar dari slide dilampirkan. Analisis gambar slide tersebut.';
        }
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

$historyContext = '';
if (!empty($conversationHistory)) {
    $historyLines = [];
    foreach ($conversationHistory as $item) {
        if (!is_array($item)) {
            continue;
        }

        $role = ($item['role'] ?? '') === 'assistant' ? 'AI' : 'User';
        $content = trim((string)($item['content'] ?? ''));
        if ($content === '') {
            continue;
        }

        $content = preg_replace('/\s+/', ' ', $content);
        $historyLines[] = $role . ': ' . substr($content, 0, 1200);
    }

    if (!empty($historyLines)) {
        $historyContext = "Riwayat percakapan sebelumnya:\n" . implode("\n", $historyLines);
    }
}

switch ($mode) {
    case 'qa':
        if ($hasMaterial && !empty($material)) {
            $system = str_replace('{{ISI_PDF}}', $material, $SYSTEM_QA);
        } else {
            $system = 'Kamu adalah AI Study Helper, asisten belajar cerdas untuk mahasiswa Indonesia. Jawab pertanyaan dengan bahasa yang ramah, jelas, dan mudah dipahami. Berikan contoh konkret jika membantu.';
        }
        if ($qaStyle === 'bff') {
            $system .= "\n\nGaya jawaban: BFF Chat. Jawab seperti teman dekat yang santai, hangat, suportif, dan natural. Boleh pakai bahasa sehari-hari, tetapi tetap akurat, tidak mengarang, dan tetap jelaskan poin penting dengan jelas. Jika user sedang belajar atau bertanya tentang materi, bantu seperti teman belajar yang sabar.";
        } else {
            $system .= "\n\nGaya jawaban: Study Mode. Jawab dengan struktur belajar yang rapi, fokus, mudah dipahami, dan cocok untuk mahasiswa. Prioritaskan definisi, langkah, contoh, dan kesimpulan singkat jika membantu.";
        }
        if ($historyContext !== '') {
            $system .= "\n\n" . $historyContext . "\nGunakan riwayat ini untuk memahami pertanyaan lanjutan, tetapi tetap prioritaskan materi/lampiran terbaru jika ada.";
        }
        $system .= "\n\nKonteks waktu: " . $currentDateContext . " Jika user bertanya tanggal, hari, atau waktu sekarang, gunakan konteks waktu ini dan jangan menebak.";
        $system .= "\n\nUntuk pertanyaan tentang data terbaru seperti jadwal rilis, berita, versi software, harga, atau event terkini, gunakan Google Search grounding yang tersedia.";
        $system .= "\nAturan jawaban data terbaru:";
        $system .= "\n1. Jangan menjawab dengan kalimat proses seperti 'kita perlu mencari informasi terbaru'.";
        $system .= "\n2. Jika hasil pencarian memuat jawabannya, mulai jawaban dengan informasi inti secara langsung, misalnya tanggal, nama, versi, harga, atau status.";
        $system .= "\n3. Jika ada beberapa sumber yang berbeda, sebutkan jawaban yang paling konsisten dan tambahkan catatan singkat bahwa jadwal bisa berubah.";
        $system .= "\n4. Jika hasil pencarian tidak memuat jawaban yang jelas, baru katakan bahwa datanya belum bisa dipastikan.";
        $system .= "\n5. Jangan mencantumkan daftar sumber di teks jawaban karena aplikasi akan menampilkan sumber secara terpisah.";
        $useGoogleSearch = !$hasMaterial;
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

// Save conversation for QA mode
$convId = null;
if ($mode === 'qa') {
    $conversationHistory[] = ['role' => 'user', 'content' => $question];
    $conversationHistory[] = ['role' => 'assistant', 'content' => $result];
    
    // Generate a title from the first question
    $title = substr($question, 0, 100);
    if (strlen($question) > 100) {
        $title .= '...';
    }
    
    $convId = saveConversation($title, array_slice($conversationHistory, -20)); // Save last 20 messages
}

$response = [
    'success' => true,
    'result' => $result,
    'mode' => $mode,
    'source' => $sourceType,
    'sources' => $LAST_GEMINI_SOURCES ?? []
];

// Add conversation ID if available
if ($convId) {
    $response['conversation_id'] = $convId;
}

if (!empty($warning)) {
    $response['warning'] = $warning;
}

echo json_encode($response);
