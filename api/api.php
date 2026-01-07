<?php
// Konfigurasi session untuk Vercel
ini_set('session.use_cookies', '1');
ini_set('session.use_only_cookies', '1');
ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_samesite', 'Lax');
ini_set('session.cookie_secure', '0');

session_start();

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Credentials: true');

// Upstash Redis REST API Configuration
$UPSTASH_URL = getenv('UPSTASH_REDIS_REST_URL');
$UPSTASH_TOKEN = getenv('UPSTASH_REDIS_REST_TOKEN');

if (!$UPSTASH_URL || !$UPSTASH_TOKEN) {
    die(json_encode([
        'status' => 'error',
        'message' => 'Redis configuration not found',
        'hint' => 'Set UPSTASH_REDIS_REST_URL and UPSTASH_REDIS_REST_TOKEN in Vercel'
    ]));
}

// Helper function untuk Redis REST API
function redisCommand($command, $args = []) {
    global $UPSTASH_URL, $UPSTASH_TOKEN;
    
    $data = array_merge([$command], $args);
    
    $ch = curl_init($UPSTASH_URL);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $UPSTASH_TOKEN,
        'Content-Type: application/json'
    ]);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    
    if ($httpCode !== 200) {
        error_log("Redis command failed: $command, HTTP: $httpCode, Error: $error");
        return null;
    }
    
    $result = json_decode($response, true);
    return $result['result'] ?? null;
}

// Get tasks from Redis
function getTasks() {
    $tasksJson = redisCommand('GET', ['tasks']);
    
    if (!$tasksJson || $tasksJson === '') {
        // Initialize dengan data default
        $defaultTasks = [
            ['id' => 1, 'task_name' => 'Selamat datang di Cheva\'s To Do List!', 'list_order' => 1, 'is_completed' => false],
            ['id' => 2, 'task_name' => 'Login sebagai admin untuk mengedit list', 'list_order' => 2, 'is_completed' => false],
            ['id' => 3, 'task_name' => 'Password: admin123', 'list_order' => 3, 'is_completed' => false]
        ];
        saveTasks($defaultTasks);
        return $defaultTasks;
    }
    
    $tasks = json_decode($tasksJson, true);
    
    // Validasi tasks adalah array
    if (!is_array($tasks)) {
        $tasks = [];
    }
    
    // Sort by list_order untuk consistency
    usort($tasks, function($a, $b) {
        $orderA = isset($a['list_order']) ? (int)$a['list_order'] : 0;
        $orderB = isset($b['list_order']) ? (int)$b['list_order'] : 0;
        return $orderA - $orderB;
    });
    
    return $tasks;
}

// Save tasks to Redis
function saveTasks($tasks) {
    $json = json_encode($tasks);
    $result = redisCommand('SET', ['tasks', $json]);
    error_log("Saving tasks: " . $json);
    return $result;
}

// Get next ID
function getNextId($tasks) {
    if (empty($tasks)) return 1;
    $ids = array_column($tasks, 'id');
    return max($ids) + 1;
}

$action = $_GET['action'] ?? '';
$raw = file_get_contents('php://input');
$input = json_decode($raw, true);

if (!is_array($input)) {
    $input = [];
}

$ADMIN_PASS = 'admin123';

function isAdmin() {
    return isset($_SESSION['is_admin']) && $_SESSION['is_admin'] === true;
}

function debugLog($action, $data = []) {
    error_log("ACTION: $action | DATA: " . json_encode($data) . " | SESSION: " . json_encode($_SESSION));
}

switch ($action) {

    case 'get_all':
        try {
            $tasks = getTasks();
            debugLog('get_all', ['count' => count($tasks), 'isAdmin' => isAdmin()]);
            
            echo json_encode([
                'status' => 'success',
                'data' => $tasks,
                'isAdmin' => isAdmin()
            ]);
        } catch (Exception $e) {
            echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        }
        break;

    case 'login':
        $password = $input['password'] ?? '';
        
        debugLog('login_attempt', ['password_length' => strlen($password)]);
        
        if ($password === $ADMIN_PASS) {
            $_SESSION['is_admin'] = true;
            session_regenerate_id(true);
            
            debugLog('login_success', ['session_id' => session_id()]);
            
            echo json_encode([
                'status' => 'success',
                'message' => 'Login successful',
                'isAdmin' => true
            ]);
        } else {
            echo json_encode([
                'status' => 'error',
                'message' => 'Password salah'
            ]);
        }
        break;

    case 'logout':
        debugLog('logout', ['before' => $_SESSION]);
        $_SESSION = [];
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
            echo json_encode(['status' => 'error', 'message' => 'Unauthorized - Please login as admin']);
            exit;
        }
        
        try {
            $taskName = trim($input['task'] ?? '');
            debugLog('add', ['task_name' => $taskName]);
            
            if (empty($taskName)) {
                echo json_encode(['status' => 'error', 'message' => 'Task name cannot be empty']);
                exit;
            }
            
            $tasks = getTasks();
            $maxOrder = 0;
            
            foreach ($tasks as $task) {
                if (isset($task['list_order']) && $task['list_order'] > $maxOrder) {
                    $maxOrder = $task['list_order'];
                }
            }
            
            $newTask = [
                'id' => getNextId($tasks),
                'task_name' => $taskName,
                'list_order' => $maxOrder + 1,
                'is_completed' => false
            ];
            
            $tasks[] = $newTask;
            $saveResult = saveTasks($tasks);
            
            debugLog('add_result', ['new_task' => $newTask, 'save_result' => $saveResult]);
            
            echo json_encode(['status' => 'success', 'message' => 'Task added', 'task' => $newTask]);
        } catch (Exception $e) {
            debugLog('add_error', ['error' => $e->getMessage()]);
            echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        }
        break;

    case 'toggle':
        try {
            $id = isset($input['id']) ? (int)$input['id'] : 0;
            debugLog('toggle', ['id' => $id]);
            
            $tasks = getTasks();
            $found = false;
            
            for ($i = 0; $i < count($tasks); $i++) {
                if (isset($tasks[$i]['id']) && (int)$tasks[$i]['id'] === $id) {
                    $tasks[$i]['is_completed'] = !($tasks[$i]['is_completed'] ?? false);
                    $found = true;
                    debugLog('toggle_found', ['task' => $tasks[$i]]);
                    break;
                }
            }
            
            if ($found) {
                $saveResult = saveTasks($tasks);
                debugLog('toggle_saved', ['save_result' => $saveResult]);
                echo json_encode(['status' => 'success', 'message' => 'Task toggled']);
            } else {
                debugLog('toggle_not_found', ['id' => $id, 'tasks_count' => count($tasks)]);
                echo json_encode(['status' => 'error', 'message' => 'Task not found']);
            }
        } catch (Exception $e) {
            debugLog('toggle_error', ['error' => $e->getMessage()]);
            echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        }
        break;

    case 'delete':
        if (!isAdmin()) {
            echo json_encode(['status' => 'error', 'message' => 'Unauthorized - Please login as admin']);
            exit;
        }
        
        try {
            $id = isset($input['id']) ? (int)$input['id'] : 0;
            debugLog('delete', ['id' => $id]);
            
            $tasks = getTasks();
            $newTasks = [];
            
            foreach ($tasks as $task) {
                if (isset($task['id']) && (int)$task['id'] !== $id) {
                    $newTasks[] = $task;
                }
            }
            
            $saveResult = saveTasks($newTasks);
            debugLog('delete_result', ['before' => count($tasks), 'after' => count($newTasks), 'save_result' => $saveResult]);
            
            echo json_encode(['status' => 'success', 'message' => 'Task deleted']);
        } catch (Exception $e) {
            debugLog('delete_error', ['error' => $e->getMessage()]);
            echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        }
        break;

    case 'reset':
        if (!isAdmin()) {
            echo json_encode(['status' => 'error', 'message' => 'Unauthorized - Please login as admin']);
            exit;
        }
        
        try {
            debugLog('reset', []);
            $tasks = [
                ['id' => 1, 'task_name' => 'List telah direset oleh Admin', 'list_order' => 1, 'is_completed' => false]
            ];
            $saveResult = saveTasks($tasks);
            debugLog('reset_result', ['save_result' => $saveResult]);
            
            echo json_encode(['status' => 'success', 'message' => 'List reset']);
        } catch (Exception $e) {
            debugLog('reset_error', ['error' => $e->getMessage()]);
            echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        }
        break;

    case 'clear_completed':
        if (!isAdmin()) {
            echo json_encode(['status' => 'error', 'message' => 'Unauthorized - Please login as admin']);
            exit;
        }
        
        try {
            debugLog('clear_completed', []);
            $tasks = getTasks();
            $newTasks = [];
            
            foreach ($tasks as $task) {
                if (!($task['is_completed'] ?? false)) {
                    $newTasks[] = $task;
                }
            }
            
            $saveResult = saveTasks($newTasks);
            debugLog('clear_completed_result', ['before' => count($tasks), 'after' => count($newTasks), 'save_result' => $saveResult]);
            
            echo json_encode(['status' => 'success', 'message' => 'Completed tasks cleared']);
        } catch (Exception $e) {
            debugLog('clear_completed_error', ['error' => $e->getMessage()]);
            echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        }
        break;

    case 'uncheck_all':
        if (!isAdmin()) {
            echo json_encode(['status' => 'error', 'message' => 'Unauthorized - Please login as admin']);
            exit;
        }
        
        try {
            debugLog('uncheck_all', []);
            $tasks = getTasks();
            
            for ($i = 0; $i < count($tasks); $i++) {
                $tasks[$i]['is_completed'] = false;
            }
            
            $saveResult = saveTasks($tasks);
            debugLog('uncheck_all_result', ['save_result' => $saveResult]);
            
            echo json_encode(['status' => 'success', 'message' => 'All tasks unchecked']);
        } catch (Exception $e) {
            debugLog('uncheck_all_error', ['error' => $e->getMessage()]);
            echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        }
        break;

    case 'swap':
        if (!isAdmin()) {
            echo json_encode(['status' => 'error', 'message' => 'Unauthorized - Please login as admin']);
            exit;
        }
        
        try {
            $id1 = isset($input['id1']) ? (int)$input['id1'] : 0;
            $id2 = isset($input['id2']) ? (int)$input['id2'] : 0;
            $order1 = isset($input['order1']) ? (int)$input['order1'] : 0;
            $order2 = isset($input['order2']) ? (int)$input['order2'] : 0;
            
            debugLog('swap', ['id1' => $id1, 'id2' => $id2, 'order1' => $order1, 'order2' => $order2]);
            
            $tasks = getTasks();
            
            for ($i = 0; $i < count($tasks); $i++) {
                if (isset($tasks[$i]['id'])) {
                    if ((int)$tasks[$i]['id'] === $id1) {
                        $tasks[$i]['list_order'] = $order2;
                    } else if ((int)$tasks[$i]['id'] === $id2) {
                        $tasks[$i]['list_order'] = $order1;
                    }
                }
            }
            
            $saveResult = saveTasks($tasks);
            debugLog('swap_result', ['save_result' => $saveResult]);
            
            echo json_encode(['status' => 'success', 'message' => 'Tasks swapped']);
        } catch (Exception $e) {
            debugLog('swap_error', ['error' => $e->getMessage()]);
            echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        }
        break;

    default:
        echo json_encode(['status' => 'error', 'message' => 'Invalid action: ' . $action]);
        break;
}
?>