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
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode !== 200) {
        error_log("Redis command failed: $command");
        return null;
    }
    
    $result = json_decode($response, true);
    return $result['result'] ?? null;
}

// Get tasks from Redis
function getTasks() {
    $tasksJson = redisCommand('GET', ['tasks']);
    if (!$tasksJson) {
        // Initialize dengan data default
        $defaultTasks = [
            ['id' => 1, 'task_name' => 'Selamat datang di Cheva\'s To Do List!', 'list_order' => 1, 'is_completed' => false],
            ['id' => 2, 'task_name' => 'Login sebagai admin untuk mengedit list', 'list_order' => 2, 'is_completed' => false],
            ['id' => 3, 'task_name' => 'Password: admin123', 'list_order' => 3, 'is_completed' => false]
        ];
        saveTasks($defaultTasks);
        return $defaultTasks;
    }
    return json_decode($tasksJson, true);
}

// Save tasks to Redis
function saveTasks($tasks) {
    redisCommand('SET', ['tasks', json_encode($tasks)]);
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

switch ($action) {

    case 'get_all':
        try {
            $tasks = getTasks();
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
        
        if ($password === $ADMIN_PASS) {
            $_SESSION['is_admin'] = true;
            session_regenerate_id(true);
            
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
            echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
            exit;
        }
        
        try {
            $taskName = trim($input['task'] ?? '');
            if (empty($taskName)) {
                echo json_encode(['status' => 'error', 'message' => 'Task name cannot be empty']);
                exit;
            }
            
            $tasks = getTasks();
            $maxOrder = empty($tasks) ? 0 : max(array_column($tasks, 'list_order'));
            
            $newTask = [
                'id' => getNextId($tasks),
                'task_name' => $taskName,
                'list_order' => $maxOrder + 1,
                'is_completed' => false
            ];
            
            $tasks[] = $newTask;
            saveTasks($tasks);
            
            echo json_encode(['status' => 'success', 'message' => 'Task added']);
        } catch (Exception $e) {
            echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        }
        break;

    case 'toggle':
        try {
            $id = $input['id'] ?? 0;
            $tasks = getTasks();
            
            foreach ($tasks as &$task) {
                if ($task['id'] == $id) {
                    $task['is_completed'] = !$task['is_completed'];
                    break;
                }
            }
            
            saveTasks($tasks);
            echo json_encode(['status' => 'success', 'message' => 'Task toggled']);
        } catch (Exception $e) {
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
            $tasks = getTasks();
            $tasks = array_values(array_filter($tasks, function($task) use ($id) {
                return $task['id'] != $id;
            }));
            
            saveTasks($tasks);
            echo json_encode(['status' => 'success', 'message' => 'Task deleted']);
        } catch (Exception $e) {
            echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        }
        break;

    case 'reset':
        if (!isAdmin()) {
            echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
            exit;
        }
        
        try {
            $tasks = [
                ['id' => 1, 'task_name' => 'List telah direset oleh Admin', 'list_order' => 1, 'is_completed' => false]
            ];
            saveTasks($tasks);
            
            echo json_encode(['status' => 'success', 'message' => 'List reset']);
        } catch (Exception $e) {
            echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        }
        break;

    case 'swap':
        if (!isAdmin()) {
            echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
            exit;
        }
        
        try {
            $tasks = getTasks();
            $id1 = $input['id1'];
            $id2 = $input['id2'];
            $order1 = $input['order1'];
            $order2 = $input['order2'];
            
            foreach ($tasks as &$task) {
                if ($task['id'] == $id1) {
                    $task['list_order'] = $order2;
                } else if ($task['id'] == $id2) {
                    $task['list_order'] = $order1;
                }
            }
            
            // Sort by order
            usort($tasks, function($a, $b) {
                return $a['list_order'] - $b['list_order'];
            });
            
            saveTasks($tasks);
            echo json_encode(['status' => 'success', 'message' => 'Tasks swapped']);
        } catch (Exception $e) {
            echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        }
        break;

    default:
        echo json_encode(['status' => 'error', 'message' => 'Invalid action']);
        break;
}
?>