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

// Handle file upload (PDF or TXT)
if (isset($_FILES['file']) && $_FILES['file']['error'] === UPLOAD_ERR_OK) {
    $tmpPath = $_FILES['file']['tmp_name'];
    $originalName = $_FILES['file']['name'] ?? '';
    $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

    if ($ext === 'pdf') {
        $material = extractPDF($tmpPath);
        $sourceType = 'pdf';
    } elseif ($ext === 'txt') {
        $material = extractText($tmpPath);
        $sourceType = 'txt';
    } else {
        echo json_encode(['error' => 'Format file tidak didukung. Gunakan PDF atau TXT.']);
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
        echo json_encode(['error' => 'Silakan upload file PDF/TXT atau paste teks terlebih dahulu']);
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

switch ($mode) {
    case 'qa':
        if ($hasMaterial && !empty($material)) {
            $system = str_replace('{{ISI_PDF}}', $material, $SYSTEM_QA);
        } else {
            $system = 'Kamu adalah AI Study Helper, asisten belajar cerdas untuk mahasiswa Indonesia. Jawab pertanyaan dengan bahasa yang ramah, jelas, dan mudah dipahami. Berikan contoh konkret jika membantu.';
        }
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

$result = callGemini($prompt, $system);

if (strpos($result, 'ERROR') === 0) {
    echo json_encode(['error' => $result]);
    exit;
}

$response = ['success' => true, 'result' => $result, 'mode' => $mode, 'source' => $sourceType];
if (!empty($warning)) {
    $response['warning'] = $warning;
}

echo json_encode($response);
