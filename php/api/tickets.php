<?php
/**
 * Tickets API Endpoints
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
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$pathParts = explode('/', trim($path, '/'));

try {
    switch ($method) {
        case 'GET':
            handleGetTickets();
            break;
        case 'POST':
            handleCreateTicket($request);
            break;
        case 'PUT':
            $ticketId = $_GET['id'] ?? null;
            handleUpdateTicket($ticketId, $request);
            break;
        case 'DELETE':
            $ticketId = $_GET['id'] ?? null;
            handleDeleteTicket($ticketId);
            break;
        default:
            jsonResponse(['error' => 'Method not allowed'], 405);
    }
} catch (Exception $e) {
    error_log("Tickets API Error: " . $e->getMessage());
    jsonResponse(['error' => 'Internal server error'], 500);
}

function handleGetTickets() {
    requireAuth();
    
    $db = getDb();
    $filter = $_GET['filter'] ?? 'all';
    $status = $_GET['status'] ?? '';
    $priority = $_GET['priority'] ?? '';
    $categoryId = $_GET['categoryId'] ?? '';
    $userId = $_SESSION['user_id'];
    $userRole = $_SESSION['user_role'];
    
    $sql = "
        SELECT 
            t.*,
            c.name as category_name,
            sc.name as subcategory_name,
            cb.name as created_by_name,
            cb.email as created_by_email,
            at.name as assigned_to_name,
            at.email as assigned_to_email,
            (SELECT COUNT(*) FROM comments WHERE ticket_id = t.id) as comment_count
        FROM tickets t
        LEFT JOIN categories c ON t.category_id = c.id
        LEFT JOIN categories sc ON t.subcategory_id = sc.id
        LEFT JOIN users cb ON t.created_by_id = cb.id
        LEFT JOIN users at ON t.assigned_to_id = at.id
        WHERE 1=1
    ";
    
    $params = [];
    
    // Apply filters based on user role and filter type
    if ($filter === 'my' && $userRole === 'user') {
        $sql .= " AND t.created_by_id = :user_id";
        $params['user_id'] = $userId;
    } elseif ($filter === 'assigned' && in_array($userRole, ['admin', 'agent'])) {
        $sql .= " AND t.assigned_to_id = :user_id";
        $params['user_id'] = $userId;
    }
    
    // Additional filters
    if (!empty($status)) {
        $sql .= " AND t.status = :status";
        $params['status'] = $status;
    }
    
    if (!empty($priority)) {
        $sql .= " AND t.priority = :priority";
        $params['priority'] = $priority;
    }
    
    if (!empty($categoryId)) {
        $sql .= " AND t.category_id = :category_id";
        $params['category_id'] = $categoryId;
    }
    
    $sql .= " ORDER BY t.created_at DESC";
    
    $tickets = $db->fetchAll($sql, $params);
    jsonResponse($tickets);
}

function handleCreateTicket($request) {
    requireAuth();
    
    $title = sanitizeInput($request['title'] ?? '');
    $description = sanitizeInput($request['description'] ?? '');
    $priority = sanitizeInput($request['priority'] ?? 'medium');
    $categoryId = (int)($request['categoryId'] ?? 0);
    $subcategoryId = !empty($request['subcategoryId']) ? (int)$request['subcategoryId'] : null;
    $supportType = sanitizeInput($request['supportType'] ?? 'remote');
    $contactEmail = sanitizeInput($request['contactEmail'] ?? '');
    $contactName = sanitizeInput($request['contactName'] ?? '');
    $contactPhone = sanitizeInput($request['contactPhone'] ?? '');
    $contactDepartment = sanitizeInput($request['contactDepartment'] ?? '');
    $dueDate = !empty($request['dueDate']) ? $request['dueDate'] : null;
    
    if (empty($title) || empty($description) || $categoryId === 0) {
        jsonResponse(['error' => 'Title, description, and category are required'], 400);
    }
    
    $db = getDb();
    
    // Verify category exists
    $category = $db->fetchOne("SELECT id FROM categories WHERE id = :id", ['id' => $categoryId]);
    if (!$category) {
        jsonResponse(['error' => 'Invalid category'], 400);
    }
    
    $ticketData = [
        'title' => $title,
        'description' => $description,
        'priority' => $priority,
        'category_id' => $categoryId,
        'subcategory_id' => $subcategoryId,
        'created_by_id' => $_SESSION['user_id'],
        'support_type' => $supportType,
        'contact_email' => $contactEmail,
        'contact_name' => $contactName,
        'contact_phone' => $contactPhone,
        'contact_department' => $contactDepartment,
        'due_date' => $dueDate
    ];
    
    $ticketId = $db->insert('tickets', $ticketData);
    
    // Get the created ticket with relations
    $ticket = $db->fetchOne("
        SELECT 
            t.*,
            c.name as category_name,
            sc.name as subcategory_name,
            cb.name as created_by_name
        FROM tickets t
        LEFT JOIN categories c ON t.category_id = c.id
        LEFT JOIN categories sc ON t.subcategory_id = sc.id
        LEFT JOIN users cb ON t.created_by_id = cb.id
        WHERE t.id = :id
    ", ['id' => $ticketId]);
    
    jsonResponse($ticket, 201);
}

function handleUpdateTicket($ticketId, $request) {
    requireAuth();
    
    if (!$ticketId) {
        jsonResponse(['error' => 'Ticket ID is required'], 400);
    }
    
    $db = getDb();
    $userId = $_SESSION['user_id'];
    $userRole = $_SESSION['user_role'];
    
    // Get existing ticket
    $ticket = $db->fetchOne("SELECT * FROM tickets WHERE id = :id", ['id' => $ticketId]);
    if (!$ticket) {
        jsonResponse(['error' => 'Ticket not found'], 404);
    }
    
    // Check permissions
    if ($userRole === 'user' && $ticket['created_by_id'] != $userId) {
        jsonResponse(['error' => 'Permission denied'], 403);
    }
    
    $updateData = [];
    
    // Update allowed fields based on role
    if (isset($request['title'])) {
        $updateData['title'] = sanitizeInput($request['title']);
    }
    if (isset($request['description'])) {
        $updateData['description'] = sanitizeInput($request['description']);
    }
    if (isset($request['priority'])) {
        $updateData['priority'] = sanitizeInput($request['priority']);
    }
    if (isset($request['status']) && in_array($userRole, ['admin', 'agent'])) {
        $updateData['status'] = sanitizeInput($request['status']);
    }
    if (isset($request['assignedToId']) && in_array($userRole, ['admin', 'agent'])) {
        $updateData['assigned_to_id'] = $request['assignedToId'] ? (int)$request['assignedToId'] : null;
    }
    if (isset($request['categoryId'])) {
        $updateData['category_id'] = (int)$request['categoryId'];
    }
    if (isset($request['subcategoryId'])) {
        $updateData['subcategory_id'] = $request['subcategoryId'] ? (int)$request['subcategoryId'] : null;
    }
    
    if (empty($updateData)) {
        jsonResponse(['error' => 'No valid fields to update'], 400);
    }
    
    $updateData['updated_at'] = date('Y-m-d H:i:s');
    
    $db->update('tickets', $updateData, 'id = :id', ['id' => $ticketId]);
    
    // Get updated ticket
    $updatedTicket = $db->fetchOne("
        SELECT 
            t.*,
            c.name as category_name,
            sc.name as subcategory_name,
            cb.name as created_by_name,
            at.name as assigned_to_name
        FROM tickets t
        LEFT JOIN categories c ON t.category_id = c.id
        LEFT JOIN categories sc ON t.subcategory_id = sc.id
        LEFT JOIN users cb ON t.created_by_id = cb.id
        LEFT JOIN users at ON t.assigned_to_id = at.id
        WHERE t.id = :id
    ", ['id' => $ticketId]);
    
    jsonResponse($updatedTicket);
}

function handleDeleteTicket($ticketId) {
    requireRole(['admin']);
    
    if (!$ticketId) {
        jsonResponse(['error' => 'Ticket ID is required'], 400);
    }
    
    $db = getDb();
    
    // Check if ticket exists
    $ticket = $db->fetchOne("SELECT id FROM tickets WHERE id = :id", ['id' => $ticketId]);
    if (!$ticket) {
        jsonResponse(['error' => 'Ticket not found'], 404);
    }
    
    // Delete ticket (comments will be deleted automatically due to foreign key cascade)
    $db->delete('tickets', 'id = :id', ['id' => $ticketId]);
    
    jsonResponse(['message' => 'Ticket deleted successfully']);
}
?>