<?php
/**
 * Authentication API Endpoints
 * IT Helpdesk Portal - PHP Backend
 */

require_once '../config/database.php';

// Enable CORS for frontend
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
        case 'POST':
            $action = $_GET['action'] ?? '';
            
            switch ($action) {
                case 'login':
                    handleLogin($request);
                    break;
                case 'register':
                    handleRegister($request);
                    break;
                case 'logout':
                    handleLogout();
                    break;
                default:
                    jsonResponse(['error' => 'Invalid action'], 400);
            }
            break;
            
        case 'GET':
            handleGetUser();
            break;
            
        default:
            jsonResponse(['error' => 'Method not allowed'], 405);
    }
} catch (Exception $e) {
    error_log("Auth API Error: " . $e->getMessage());
    jsonResponse(['error' => 'Internal server error'], 500);
}

function handleLogin($request) {
    $username = sanitizeInput($request['username'] ?? '');
    $password = $request['password'] ?? '';
    
    if (empty($username) || empty($password)) {
        jsonResponse(['error' => 'Username and password are required'], 400);
    }
    
    $db = getDb();
    $user = $db->fetchOne(
        "SELECT * FROM users WHERE username = :username",
        ['username' => $username]
    );
    
    if (!$user || !verifyPassword($password, $user['password'])) {
        jsonResponse(['error' => 'Invalid credentials'], 401);
    }
    
    // Set session
    $_SESSION['user_id'] = $user['id'];
    $_SESSION['user_username'] = $user['username'];
    $_SESSION['user_role'] = $user['role'];
    $_SESSION['user_name'] = $user['name'];
    
    // Remove password from response
    unset($user['password']);
    
    jsonResponse($user);
}

function handleRegister($request) {
    $username = sanitizeInput($request['username'] ?? '');
    $password = $request['password'] ?? '';
    $name = sanitizeInput($request['name'] ?? '');
    $email = sanitizeInput($request['email'] ?? '');
    
    if (empty($username) || empty($password) || empty($name) || empty($email)) {
        jsonResponse(['error' => 'All fields are required'], 400);
    }
    
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        jsonResponse(['error' => 'Invalid email format'], 400);
    }
    
    $db = getDb();
    
    // Check if username already exists
    $existingUser = $db->fetchOne(
        "SELECT id FROM users WHERE username = :username OR email = :email",
        ['username' => $username, 'email' => $email]
    );
    
    if ($existingUser) {
        jsonResponse(['error' => 'Username or email already exists'], 400);
    }
    
    // Create new user
    $hashedPassword = hashPassword($password);
    $userId = $db->insert('users', [
        'username' => $username,
        'password' => $hashedPassword,
        'name' => $name,
        'email' => $email,
        'role' => 'user'
    ]);
    
    // Get created user
    $user = $db->fetchOne("SELECT * FROM users WHERE id = :id", ['id' => $userId]);
    unset($user['password']);
    
    // Set session
    $_SESSION['user_id'] = $user['id'];
    $_SESSION['user_username'] = $user['username'];
    $_SESSION['user_role'] = $user['role'];
    $_SESSION['user_name'] = $user['name'];
    
    jsonResponse($user, 201);
}

function handleLogout() {
    session_destroy();
    jsonResponse(['message' => 'Logged out successfully']);
}

function handleGetUser() {
    if (!isset($_SESSION['user_id'])) {
        jsonResponse(['error' => 'Not authenticated'], 401);
    }
    
    $db = getDb();
    $user = $db->fetchOne(
        "SELECT id, username, name, email, role, company_name, department, contact_number, designation, created_at FROM users WHERE id = :id",
        ['id' => $_SESSION['user_id']]
    );
    
    if (!$user) {
        session_destroy();
        jsonResponse(['error' => 'User not found'], 404);
    }
    
    jsonResponse($user);
}
?>