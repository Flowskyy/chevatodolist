<?php
// Konfigurasi session untuk Vercel - PENTING!
ini_set('session.use_cookies', '1');
ini_set('session.use_only_cookies', '1');
ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_samesite', 'Lax');
ini_set('session.cookie_secure', '0'); // Set '1' jika pakai HTTPS

// Start session SEBELUM output apapun
session_start();

// Headers - Set setelah session_start
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Credentials: true');

// Import koneksi database
$pdo = require_once __DIR__ . '/db.php';

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

// Debug helper (hapus setelah selesai debugging)
function debugLog($message, $data = null) {
    error_log("[TODO-DEBUG] $message: " . json_encode($data));
}

// Route handler
switch ($action) {

    case 'get_all':
        try {
            $stmt = $pdo->query("SELECT * FROM tasks ORDER BY list_order ASC, id ASC");
            $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            debugLog("get_all", [
                'count' => count($data),
                'isAdmin' => isAdmin(),
                'session_id' => session_id()
            ]);
            
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
        $password = $input['password'] ?? '';
        
        debugLog("login_attempt", [
            'password_length' => strlen($password),
            'expected_length' => strlen($ADMIN_PASS),
            'match' => ($password === $ADMIN_PASS),
            'session_id_before' => session_id()
        ]);
        
        if ($password === $ADMIN_PASS) {
            $_SESSION['is_admin'] = true;
            
            // Regenerate session ID untuk keamanan
            session_regenerate_id(true);
            
            debugLog("login_success", [
                'session_id_after' => session_id(),
                'session_data' => $_SESSION
            ]);
            
            echo json_encode([
                'status' => 'success',
                'message' => 'Login successful',
                'isAdmin' => true,
                'session_id' => session_id()
            ]);
        } else {
            debugLog("login_failed", [
                'received' => $password,
                'expected' => $ADMIN_PASS
            ]);
            
            echo json_encode([
                'status' => 'error',
                'message' => 'Password salah',
                'debug' => [
                    'received_pass' => $password,
                    'received_length' => strlen($password),
                    'expected_length' => strlen($ADMIN_PASS)
                ]
            ]);
        }
        break;

    case 'logout':
        debugLog("logout", ['session_before' => $_SESSION]);
        
        $_SESSION = [];
        
        // Hapus cookie session
        if (ini_get("session.use_cookies")) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000,
                $params["path"], $params["domain"],
                $params["secure"], $params["httponly"]
            );
        }
        
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