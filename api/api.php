<?php
// Konfigurasi session untuk Vercel
ini_set('session.cookie_samesite', 'Lax');
ini_set('session.cookie_httponly', '1');
session_start();

// Headers
header('Content-Type: application/json');
header('Access-Control-Allow-Credentials: true');

// DB CONNECTION untuk Neon Database
// Neon biasanya pakai DATABASE_URL
$dsn = getenv('DATABASE_URL') ?: getenv('POSTGRES_URL');

if (!$dsn) {
    echo json_encode([
        'status' => 'error', 
        'message' => 'Database URL not configured',
        'hint' => 'Set DATABASE_URL di Vercel Environment Variables'
    ]);
    exit;
}

try {
    // Koneksi dengan SSL untuk Neon
    $pdo = new PDO($dsn, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false
    ]);
    
    // Set timezone dan charset
    $pdo->exec("SET NAMES 'utf8'");
    $pdo->exec("SET TIME ZONE 'Asia/Jakarta'");
    
} catch (PDOException $e) {
    echo json_encode([
        'status' => 'error', 
        'message' => 'Database connection failed',
        'error' => $e->getMessage()
    ]);
    exit;
}

// Get action
$action = $_GET['action'] ?? '';

// Get input data
$raw = file_get_contents('php://input');
$input = json_decode($raw, true);

if (!is_array($input)) {
    $input = [];
}

// Admin password
$ADMIN_PASS = 'admin123';

// Helper function
function isAdmin() {
    return isset($_SESSION['is_admin']) && $_SESSION['is_admin'] === true;
}

// Route handler
switch ($action) {

    case 'get_all':
        try {
            $stmt = $pdo->query("SELECT * FROM tasks ORDER BY list_order ASC, id ASC");
            $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            echo json_encode([
                'status' => 'success',
                'data' => $data,
                'isAdmin' => isAdmin()
            ]);
        } catch (PDOException $e) {
            echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        }
        break;

    case 'login':
        $password = trim($input['password'] ?? '');
        
        if ($password === $ADMIN_PASS) {
            $_SESSION['is_admin'] = true;
            echo json_encode([
                'status' => 'success',
                'message' => 'Login successful'
            ]);
        } else {
            echo json_encode([
                'status' => 'error',
                'message' => 'Invalid password',
                'debug' => [
                    'received_length' => strlen($password),
                    'expected_length' => strlen($ADMIN_PASS),
                    'session_id' => session_id()
                ]
            ]);
        }
        break;

    case 'logout':
        $_SESSION = [];
        session_destroy();
        echo json_encode(['status' => 'success', 'message' => 'Logged out']);
        break;

    case 'add':
        if (!isAdmin()) {
            echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
            exit;
        }
        
        try {
            $taskName = trim($input['task'] ?? '');
            if (empty($taskName)) {
                echo json_encode(['status' => 'error', 'message' => 'Task name cannot be empty']);
                exit;
            }
            
            $max = $pdo->query("SELECT COALESCE(MAX(list_order), 0) FROM tasks")->fetchColumn();
            $stmt = $pdo->prepare("INSERT INTO tasks (task_name, list_order, is_completed) VALUES (?, ?, false)");
            $stmt->execute([$taskName, $max + 1]);
            
            echo json_encode(['status' => 'success', 'message' => 'Task added']);
        } catch (PDOException $e) {
            echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        }
        break;

    case 'toggle':
        try {
            $id = $input['id'] ?? 0;
            $stmt = $pdo->prepare("UPDATE tasks SET is_completed = NOT is_completed WHERE id = ?");
            $stmt->execute([$id]);
            
            echo json_encode(['status' => 'success', 'message' => 'Task toggled']);
        } catch (PDOException $e) {
            echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        }
        break;

    case 'delete':
        if (!isAdmin()) {
            echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
            exit;
        }
        
        try {
            $id = $input['id'] ?? 0;
            $stmt = $pdo->prepare("DELETE FROM tasks WHERE id = ?");
            $stmt->execute([$id]);
            
            echo json_encode(['status' => 'success', 'message' => 'Task deleted']);
        } catch (PDOException $e) {
            echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        }
        break;

    case 'reset':
        if (!isAdmin()) {
            echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
            exit;
        }
        
        try {
            $pdo->exec("TRUNCATE TABLE tasks RESTART IDENTITY");
            $pdo->exec("INSERT INTO tasks (task_name, list_order, is_completed) VALUES ('List telah direset oleh Admin', 1, false)");
            
            echo json_encode(['status' => 'success', 'message' => 'List reset']);
        } catch (PDOException $e) {
            echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        }
        break;

    case 'swap':
        if (!isAdmin()) {
            echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
            exit;
        }
        
        try {
            $pdo->prepare("UPDATE tasks SET list_order = ? WHERE id = ?")
                ->execute([$input['order2'], $input['id1']]);
            $pdo->prepare("UPDATE tasks SET list_order = ? WHERE id = ?")
                ->execute([$input['order1'], $input['id2']]);
            
            echo json_encode(['status' => 'success', 'message' => 'Tasks swapped']);
        } catch (PDOException $e) {
            echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        }
        break;

    default:
        echo json_encode(['status' => 'error', 'message' => 'Invalid action']);
        break;
}
?>