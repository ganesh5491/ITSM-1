<?php
/**
 * Categories API Endpoints
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
            handleGetCategories();
            break;
        case 'POST':
            handleCreateCategory($request);
            break;
        case 'PUT':
            $categoryId = $_GET['id'] ?? null;
            handleUpdateCategory($categoryId, $request);
            break;
        case 'DELETE':
            $categoryId = $_GET['id'] ?? null;
            handleDeleteCategory($categoryId);
            break;
        default:
            jsonResponse(['error' => 'Method not allowed'], 405);
    }
} catch (Exception $e) {
    error_log("Categories API Error: " . $e->getMessage());
    jsonResponse(['error' => 'Internal server error'], 500);
}

function handleGetCategories() {
    requireAuth();
    
    $db = getDb();
    $categories = $db->fetchAll("
        SELECT 
            c.*,
            p.name as parent_name,
            (SELECT COUNT(*) FROM tickets WHERE category_id = c.id) as ticket_count
        FROM categories c
        LEFT JOIN categories p ON c.parent_id = p.id
        ORDER BY c.parent_id IS NULL DESC, c.parent_id, c.name
    ");
    
    jsonResponse($categories);
}

function handleCreateCategory($request) {
    requireRole(['admin']);
    
    $name = sanitizeInput($request['name'] ?? '');
    $parentId = !empty($request['parentId']) ? (int)$request['parentId'] : null;
    
    if (empty($name)) {
        jsonResponse(['error' => 'Category name is required'], 400);
    }
    
    $db = getDb();
    
    // Check if category name already exists at the same level
    $existingQuery = "SELECT id FROM categories WHERE name = :name";
    $params = ['name' => $name];
    
    if ($parentId) {
        $existingQuery .= " AND parent_id = :parent_id";
        $params['parent_id'] = $parentId;
    } else {
        $existingQuery .= " AND parent_id IS NULL";
    }
    
    $existing = $db->fetchOne($existingQuery, $params);
    if ($existing) {
        jsonResponse(['error' => 'Category name already exists'], 400);
    }
    
    // If parent_id is provided, verify parent exists
    if ($parentId) {
        $parent = $db->fetchOne("SELECT id FROM categories WHERE id = :id", ['id' => $parentId]);
        if (!$parent) {
            jsonResponse(['error' => 'Parent category not found'], 404);
        }
    }
    
    $categoryId = $db->insert('categories', [
        'name' => $name,
        'parent_id' => $parentId
    ]);
    
    $category = $db->fetchOne("
        SELECT 
            c.*,
            p.name as parent_name
        FROM categories c
        LEFT JOIN categories p ON c.parent_id = p.id
        WHERE c.id = :id
    ", ['id' => $categoryId]);
    
    jsonResponse($category, 201);
}

function handleUpdateCategory($categoryId, $request) {
    requireRole(['admin']);
    
    if (!$categoryId) {
        jsonResponse(['error' => 'Category ID is required'], 400);
    }
    
    $db = getDb();
    
    // Check if category exists
    $category = $db->fetchOne("SELECT * FROM categories WHERE id = :id", ['id' => $categoryId]);
    if (!$category) {
        jsonResponse(['error' => 'Category not found'], 404);
    }
    
    $name = sanitizeInput($request['name'] ?? $category['name']);
    $parentId = isset($request['parentId']) 
        ? (!empty($request['parentId']) ? (int)$request['parentId'] : null)
        : $category['parent_id'];
    
    // Check for circular reference (category can't be its own parent)
    if ($parentId == $categoryId) {
        jsonResponse(['error' => 'Category cannot be its own parent'], 400);
    }
    
    // Check if name conflicts with existing categories at the same level
    $existingQuery = "SELECT id FROM categories WHERE name = :name AND id != :id";
    $params = ['name' => $name, 'id' => $categoryId];
    
    if ($parentId) {
        $existingQuery .= " AND parent_id = :parent_id";
        $params['parent_id'] = $parentId;
    } else {
        $existingQuery .= " AND parent_id IS NULL";
    }
    
    $existing = $db->fetchOne($existingQuery, $params);
    if ($existing) {
        jsonResponse(['error' => 'Category name already exists'], 400);
    }
    
    $db->update('categories', [
        'name' => $name,
        'parent_id' => $parentId
    ], 'id = :id', ['id' => $categoryId]);
    
    $updatedCategory = $db->fetchOne("
        SELECT 
            c.*,
            p.name as parent_name
        FROM categories c
        LEFT JOIN categories p ON c.parent_id = p.id
        WHERE c.id = :id
    ", ['id' => $categoryId]);
    
    jsonResponse($updatedCategory);
}

function handleDeleteCategory($categoryId) {
    requireRole(['admin']);
    
    if (!$categoryId) {
        jsonResponse(['error' => 'Category ID is required'], 400);
    }
    
    $db = getDb();
    
    // Check if category exists
    $category = $db->fetchOne("SELECT id FROM categories WHERE id = :id", ['id' => $categoryId]);
    if (!$category) {
        jsonResponse(['error' => 'Category not found'], 404);
    }
    
    // Check if category has tickets
    $ticketCount = $db->fetchOne(
        "SELECT COUNT(*) as count FROM tickets WHERE category_id = :id OR subcategory_id = :id",
        ['id' => $categoryId]
    );
    
    if ($ticketCount['count'] > 0) {
        jsonResponse(['error' => 'Cannot delete category with existing tickets'], 400);
    }
    
    // Check if category has subcategories
    $subcategoryCount = $db->fetchOne(
        "SELECT COUNT(*) as count FROM categories WHERE parent_id = :id",
        ['id' => $categoryId]
    );
    
    if ($subcategoryCount['count'] > 0) {
        jsonResponse(['error' => 'Cannot delete category with subcategories'], 400);
    }
    
    $db->delete('categories', 'id = :id', ['id' => $categoryId]);
    
    jsonResponse(['message' => 'Category deleted successfully']);
}
?>