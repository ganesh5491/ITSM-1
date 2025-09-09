<?php
/**
 * Users API Endpoints  
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
            handleGetUsers();
            break;
        case 'POST':
            handleCreateUser($request);
            break;
        case 'PUT':
            $userId = $_GET['id'] ?? null;
            handleUpdateUser($userId, $request);
            break;
        case 'DELETE':
            $userId = $_GET['id'] ?? null;
            handleDeleteUser($userId);
            break;
        default:
            jsonResponse(['error' => 'Method not allowed'], 405);
    }
} catch (Exception $e) {
    error_log("Users API Error: " . $e->getMessage());
    jsonResponse(['error' => 'Internal server error'], 500);
}

function handleGetUsers() {
    requireAuth();
    
    $db = getDb();
    $currentUserId = $_SESSION['user_id'];
    $userRole = $_SESSION['user_role'];
    
    $sql = "
        SELECT 
            id, username, name, email, role, company_name, department, 
            contact_number, designation, created_at,
            (SELECT COUNT(*) FROM tickets WHERE created_by_id = u.id) as tickets_created,
            (SELECT COUNT(*) FROM tickets WHERE assigned_to_id = u.id) as tickets_assigned
        FROM users u
        WHERE 1=1
    ";
    
    $params = [];
    
    // Regular users can only see their own profile and agents/admins
    if ($userRole === 'user') {
        $sql .= " AND (id = :current_user_id OR role IN ('admin', 'agent'))";
        $params['current_user_id'] = $currentUserId;
    }
    
    $sql .= " ORDER BY role DESC, name";
    
    $users = $db->fetchAll($sql, $params);
    jsonResponse($users);
}

function handleCreateUser($request) {
    requireRole(['admin']);
    
    $username = sanitizeInput($request['username'] ?? '');
    $password = $request['password'] ?? '';
    $name = sanitizeInput($request['name'] ?? '');
    $email = sanitizeInput($request['email'] ?? '');
    $role = sanitizeInput($request['role'] ?? 'user');
    $companyName = sanitizeInput($request['companyName'] ?? '');
    $department = sanitizeInput($request['department'] ?? '');
    $contactNumber = sanitizeInput($request['contactNumber'] ?? '');
    $designation = sanitizeInput($request['designation'] ?? '');
    
    if (empty($username) || empty($password) || empty($name) || empty($email)) {
        jsonResponse(['error' => 'Username, password, name, and email are required'], 400);
    }
    
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        jsonResponse(['error' => 'Invalid email format'], 400);
    }
    
    if (!in_array($role, ['admin', 'agent', 'user'])) {
        jsonResponse(['error' => 'Invalid role'], 400);
    }
    
    $db = getDb();
    
    // Check if username or email already exists
    $existing = $db->fetchOne(
        "SELECT id FROM users WHERE username = :username OR email = :email",
        ['username' => $username, 'email' => $email]
    );
    
    if ($existing) {
        jsonResponse(['error' => 'Username or email already exists'], 400);
    }
    
    $hashedPassword = hashPassword($password);
    $userId = $db->insert('users', [
        'username' => $username,
        'password' => $hashedPassword,
        'name' => $name,
        'email' => $email,
        'role' => $role,
        'company_name' => $companyName,
        'department' => $department,
        'contact_number' => $contactNumber,
        'designation' => $designation
    ]);
    
    $user = $db->fetchOne(
        "SELECT id, username, name, email, role, company_name, department, contact_number, designation, created_at FROM users WHERE id = :id",
        ['id' => $userId]
    );
    
    jsonResponse($user, 201);
}

function handleUpdateUser($userId, $request) {
    requireAuth();
    
    if (!$userId) {
        jsonResponse(['error' => 'User ID is required'], 400);
    }
    
    $currentUserId = $_SESSION['user_id'];
    $currentUserRole = $_SESSION['user_role'];
    
    $db = getDb();
    
    // Check if user exists
    $user = $db->fetchOne("SELECT * FROM users WHERE id = :id", ['id' => $userId]);
    if (!$user) {
        jsonResponse(['error' => 'User not found'], 404);
    }
    
    // Permission check: users can only update their own profile, admins can update anyone
    if ($currentUserRole !== 'admin' && $currentUserId != $userId) {
        jsonResponse(['error' => 'Permission denied'], 403);
    }
    
    $updateData = [];
    
    // Fields that users can update about themselves
    if (isset($request['name'])) {
        $updateData['name'] = sanitizeInput($request['name']);
    }
    if (isset($request['email'])) {
        $email = sanitizeInput($request['email']);
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            jsonResponse(['error' => 'Invalid email format'], 400);
        }
        $updateData['email'] = $email;
    }
    if (isset($request['companyName'])) {
        $updateData['company_name'] = sanitizeInput($request['companyName']);
    }
    if (isset($request['department'])) {
        $updateData['department'] = sanitizeInput($request['department']);
    }
    if (isset($request['contactNumber'])) {
        $updateData['contact_number'] = sanitizeInput($request['contactNumber']);
    }
    if (isset($request['designation'])) {
        $updateData['designation'] = sanitizeInput($request['designation']);
    }
    
    // Fields only admins can update
    if ($currentUserRole === 'admin') {
        if (isset($request['username'])) {
            $updateData['username'] = sanitizeInput($request['username']);
        }
        if (isset($request['role'])) {
            $role = sanitizeInput($request['role']);
            if (!in_array($role, ['admin', 'agent', 'user'])) {
                jsonResponse(['error' => 'Invalid role'], 400);
            }
            $updateData['role'] = $role;
        }
    }
    
    // Password update (user can update their own, admin can update anyone's)
    if (isset($request['password']) && !empty($request['password'])) {
        if ($currentUserRole === 'admin' || $currentUserId == $userId) {
            $updateData['password'] = hashPassword($request['password']);
        } else {
            jsonResponse(['error' => 'Permission denied to update password'], 403);
        }
    }
    
    if (empty($updateData)) {
        jsonResponse(['error' => 'No valid fields to update'], 400);
    }
    
    // Check for duplicate username/email if being updated
    if (isset($updateData['username']) || isset($updateData['email'])) {
        $checkSql = "SELECT id FROM users WHERE id != :user_id AND (";
        $checkParams = ['user_id' => $userId];
        $conditions = [];
        
        if (isset($updateData['username'])) {
            $conditions[] = "username = :username";
            $checkParams['username'] = $updateData['username'];
        }
        if (isset($updateData['email'])) {
            $conditions[] = "email = :email";
            $checkParams['email'] = $updateData['email'];
        }
        
        $checkSql .= implode(' OR ', $conditions) . ")";
        
        $duplicate = $db->fetchOne($checkSql, $checkParams);
        if ($duplicate) {
            jsonResponse(['error' => 'Username or email already exists'], 400);
        }
    }
    
    $db->update('users', $updateData, 'id = :id', ['id' => $userId]);
    
    $updatedUser = $db->fetchOne(
        "SELECT id, username, name, email, role, company_name, department, contact_number, designation, created_at FROM users WHERE id = :id",
        ['id' => $userId]
    );
    
    jsonResponse($updatedUser);
}

function handleDeleteUser($userId) {
    requireRole(['admin']);
    
    if (!$userId) {
        jsonResponse(['error' => 'User ID is required'], 400);
    }
    
    $currentUserId = $_SESSION['user_id'];
    
    if ($currentUserId == $userId) {
        jsonResponse(['error' => 'Cannot delete your own account'], 400);
    }
    
    $db = getDb();
    
    // Check if user exists
    $user = $db->fetchOne("SELECT id FROM users WHERE id = :id", ['id' => $userId]);
    if (!$user) {
        jsonResponse(['error' => 'User not found'], 404);
    }
    
    // Check if user has tickets
    $ticketCount = $db->fetchOne(
        "SELECT COUNT(*) as count FROM tickets WHERE created_by_id = :id OR assigned_to_id = :id",
        ['id' => $userId]
    );
    
    if ($ticketCount['count'] > 0) {
        jsonResponse(['error' => 'Cannot delete user with existing tickets'], 400);
    }
    
    $db->delete('users', 'id = :id', ['id' => $userId]);
    
    jsonResponse(['message' => 'User deleted successfully']);
}
?>