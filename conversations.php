<?php
require_once __DIR__ . '/config.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, DELETE');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

$action = $_GET['action'] ?? $_POST['action'] ?? '';

try {
    switch ($action) {
        case 'list':
            // Get list of conversations
            $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 20;
            $conversations = getConversations($limit);
            echo json_encode([
                'success' => true,
                'data' => $conversations
            ]);
            break;
            
        case 'get':
            // Get single conversation with all messages
            $convId = isset($_GET['id']) ? (int)$_GET['id'] : null;
            if (!$convId) {
                http_response_code(400);
                echo json_encode(['error' => 'Conversation ID tidak disediakan']);
                exit;
            }
            
            $messages = getConversationMessages($convId);
            echo json_encode([
                'success' => true,
                'data' => $messages
            ]);
            break;
            
        case 'save':
            // Save new conversation
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                http_response_code(405);
                echo json_encode(['error' => 'Method not allowed']);
                exit;
            }
            
            $title = $_POST['title'] ?? 'Percakapan tanpa judul';
            $messagesJson = $_POST['messages'] ?? '[]';
            $messages = json_decode($messagesJson, true);
            
            if (!is_array($messages)) {
                http_response_code(400);
                echo json_encode(['error' => 'Format messages tidak valid']);
                exit;
            }
            
            $convId = saveConversation($title, $messages);
            if ($convId) {
                echo json_encode([
                    'success' => true,
                    'conversation_id' => $convId
                ]);
            } else {
                http_response_code(500);
                echo json_encode(['error' => 'Gagal menyimpan percakapan']);
            }
            break;
            
        case 'update-title':
            // Update conversation title
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                http_response_code(405);
                echo json_encode(['error' => 'Method not allowed']);
                exit;
            }
            
            $convId = isset($_POST['id']) ? (int)$_POST['id'] : null;
            $title = $_POST['title'] ?? '';
            
            if (!$convId || !$title) {
                http_response_code(400);
                echo json_encode(['error' => 'Parameter tidak lengkap']);
                exit;
            }
            
            if (updateConversationTitle($convId, $title)) {
                echo json_encode(['success' => true]);
            } else {
                http_response_code(500);
                echo json_encode(['error' => 'Gagal update title']);
            }
            break;
            
        case 'delete':
            // Delete conversation
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                http_response_code(405);
                echo json_encode(['error' => 'Method not allowed']);
                exit;
            }
            
            $convId = isset($_POST['id']) ? (int)$_POST['id'] : null;
            if (!$convId) {
                http_response_code(400);
                echo json_encode(['error' => 'Conversation ID tidak disediakan']);
                exit;
            }
            
            if (deleteConversation($convId)) {
                echo json_encode(['success' => true]);
            } else {
                http_response_code(500);
                echo json_encode(['error' => 'Gagal hapus percakapan']);
            }
            break;
            
        default:
            http_response_code(400);
            echo json_encode(['error' => 'Action tidak dikenali']);
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Server error: ' . $e->getMessage()]);
}
