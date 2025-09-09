<?php
/**
 * FAQs API Endpoints
 * IT Helpdesk Portal - PHP Backend
 */

require_once '../config/database.php';

// Enable CORS
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

$method = $_SERVER['REQUEST_METHOD'];
$request = json_decode(file_get_contents('php://input'), true);

try {
    switch ($method) {
        case 'GET':
            handleGetFaqs();
            break;
        case 'POST':
            handleCreateFaq($request);
            break;
        case 'PUT':
            $faqId = $_GET['id'] ?? null;
            handleUpdateFaq($faqId, $request);
            break;
        case 'DELETE':
            $faqId = $_GET['id'] ?? null;
            handleDeleteFaq($faqId);
            break;
        default:
            jsonResponse(['error' => 'Method not allowed'], 405);
    }
} catch (Exception $e) {
    error_log("FAQs API Error: " . $e->getMessage());
    jsonResponse(['error' => 'Internal server error'], 500);
}

function handleGetFaqs() {
    requireAuth();
    
    $db = getDb();
    $categoryId = $_GET['categoryId'] ?? '';
    $search = $_GET['search'] ?? '';
    
    $sql = "
        SELECT 
            f.*,
            c.name as category_name
        FROM faqs f
        LEFT JOIN categories c ON f.category_id = c.id
        WHERE 1=1
    ";
    
    $params = [];
    
    if (!empty($categoryId)) {
        $sql .= " AND f.category_id = :category_id";
        $params['category_id'] = $categoryId;
    }
    
    if (!empty($search)) {
        $sql .= " AND (f.question LIKE :search OR f.answer LIKE :search)";
        $params['search'] = '%' . $search . '%';
    }
    
    $sql .= " ORDER BY f.view_count DESC, f.created_at DESC";
    
    $faqs = $db->fetchAll($sql, $params);
    jsonResponse($faqs);
}

function handleCreateFaq($request) {
    requireRole(['admin', 'agent']);
    
    $question = sanitizeInput($request['question'] ?? '');
    $answer = sanitizeInput($request['answer'] ?? '');
    $categoryId = !empty($request['categoryId']) ? (int)$request['categoryId'] : null;
    
    if (empty($question) || empty($answer)) {
        jsonResponse(['error' => 'Question and answer are required'], 400);
    }
    
    $db = getDb();
    
    // Verify category exists if provided
    if ($categoryId) {
        $category = $db->fetchOne("SELECT id FROM categories WHERE id = :id", ['id' => $categoryId]);
        if (!$category) {
            jsonResponse(['error' => 'Invalid category'], 400);
        }
    }
    
    $faqId = $db->insert('faqs', [
        'question' => $question,
        'answer' => $answer,
        'category_id' => $categoryId
    ]);
    
    $faq = $db->fetchOne("
        SELECT 
            f.*,
            c.name as category_name
        FROM faqs f
        LEFT JOIN categories c ON f.category_id = c.id
        WHERE f.id = :id
    ", ['id' => $faqId]);
    
    jsonResponse($faq, 201);
}

function handleUpdateFaq($faqId, $request) {
    requireRole(['admin', 'agent']);
    
    if (!$faqId) {
        jsonResponse(['error' => 'FAQ ID is required'], 400);
    }
    
    $db = getDb();
    
    // Check if FAQ exists
    $faq = $db->fetchOne("SELECT * FROM faqs WHERE id = :id", ['id' => $faqId]);
    if (!$faq) {
        jsonResponse(['error' => 'FAQ not found'], 404);
    }
    
    $updateData = [];
    
    if (isset($request['question'])) {
        $updateData['question'] = sanitizeInput($request['question']);
    }
    if (isset($request['answer'])) {
        $updateData['answer'] = sanitizeInput($request['answer']);
    }
    if (isset($request['categoryId'])) {
        $categoryId = !empty($request['categoryId']) ? (int)$request['categoryId'] : null;
        if ($categoryId) {
            $category = $db->fetchOne("SELECT id FROM categories WHERE id = :id", ['id' => $categoryId]);
            if (!$category) {
                jsonResponse(['error' => 'Invalid category'], 400);
            }
        }
        $updateData['category_id'] = $categoryId;
    }
    
    if (empty($updateData)) {
        jsonResponse(['error' => 'No valid fields to update'], 400);
    }
    
    $updateData['updated_at'] = date('Y-m-d H:i:s');
    
    $db->update('faqs', $updateData, 'id = :id', ['id' => $faqId]);
    
    $updatedFaq = $db->fetchOne("
        SELECT 
            f.*,
            c.name as category_name
        FROM faqs f
        LEFT JOIN categories c ON f.category_id = c.id
        WHERE f.id = :id
    ", ['id' => $faqId]);
    
    jsonResponse($updatedFaq);
}

function handleDeleteFaq($faqId) {
    requireRole(['admin']);
    
    if (!$faqId) {
        jsonResponse(['error' => 'FAQ ID is required'], 400);
    }
    
    $db = getDb();
    
    // Check if FAQ exists
    $faq = $db->fetchOne("SELECT id FROM faqs WHERE id = :id", ['id' => $faqId]);
    if (!$faq) {
        jsonResponse(['error' => 'FAQ not found'], 404);
    }
    
    $db->delete('faqs', 'id = :id', ['id' => $faqId]);
    
    jsonResponse(['message' => 'FAQ deleted successfully']);
}
?>