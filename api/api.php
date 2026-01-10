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
        $defaultTasks = [
            ['id' => 1, 'task_name' => 'Selamat datang di Cheva\'s To Do List!', 'list_order' => 1, 'is_completed' => false],
            ['id' => 2, 'task_name' => 'Gunakan panel admin untuk menambah list', 'list_order' => 2, 'is_completed' => false]
        ];
        saveTasks($defaultTasks);
        return $defaultTasks;
    }
    
    $tasks = json_decode($tasksJson, true);
    if (!is_array($tasks)) $tasks = [];
    
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
$input = json_decode($raw, true) ?? [];

$ADMIN_PASS = 'cepaimut';

function isAdmin() {
    return isset($_SESSION['is_admin']) && $_SESSION['is_admin'] === true;
}

function debugLog($action, $data = []) {
    error_log("ACTION: $action | DATA: " . json_encode($data) . " | SESSION: " . json_encode($_SESSION));
}

switch ($action) {
    case 'get_all':
        // Proteksi: Jika belum login, jangan kirim data task
        if (!isset($_SESSION['logged_in'])) {
            echo json_encode([
                'status' => 'locked',
                'message' => 'Login required',
                'isAdmin' => false
            ]);
            exit;
        }

        $tasks = getTasks();
        echo json_encode([
            'status' => 'success',
            'data' => $tasks,
            'isAdmin' => isAdmin()
        ]);
        break;

    case 'login':
        $password = $input['password'] ?? '';
        if ($password === $ADMIN_PASS) {
            $_SESSION['logged_in'] = true;
            $_SESSION['is_admin'] = true;
            session_regenerate_id(true);
            echo json_encode(['status' => 'success', 'isAdmin' => true]);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Password salah']);
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
        if (!isAdmin()) { echo json_encode(['status' => 'error', 'message' => 'Unauthorized']); exit; }
        $taskName = trim($input['task'] ?? '');
        if (empty($taskName)) { echo json_encode(['status' => 'error', 'message' => 'Empty task']); exit; }
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
        echo json_encode(['status' => 'success', 'task' => $newTask]);
        break;

    case 'toggle':
        // Toggle bisa dilakukan siapa saja yang sudah login (sesuai permintaan alur login di depan)
        if (!isset($_SESSION['logged_in'])) { echo json_encode(['status' => 'error']); exit; }
        $id = (int)($input['id'] ?? 0);
        $tasks = getTasks();
        for ($i = 0; $i < count($tasks); $i++) {
            if ((int)$tasks[$i]['id'] === $id) {
                $tasks[$i]['is_completed'] = !($tasks[$i]['is_completed'] ?? false);
                saveTasks($tasks);
                echo json_encode(['status' => 'success']);
                exit;
            }
        }
        echo json_encode(['status' => 'error', 'message' => 'Not found']);
        break;

    case 'delete':
        if (!isAdmin()) { echo json_encode(['status' => 'error']); exit; }
        $id = (int)($input['id'] ?? 0);
        $tasks = getTasks();
        $tasks = array_values(array_filter($tasks, function($t) use ($id) { return (int)$t['id'] !== $id; }));
        saveTasks($tasks);
        echo json_encode(['status' => 'success']);
        break;

    case 'reset':
        if (!isAdmin()) { echo json_encode(['status' => 'error']); exit; }
        $tasks = [['id' => 1, 'task_name' => 'List telah direset oleh Admin', 'list_order' => 1, 'is_completed' => false]];
        saveTasks($tasks);
        echo json_encode(['status' => 'success']);
        break;

    case 'clear_completed':
        if (!isAdmin()) { echo json_encode(['status' => 'error']); exit; }
        $tasks = array_values(array_filter(getTasks(), function($t) { return !($t['is_completed'] ?? false); }));
        saveTasks($tasks);
        echo json_encode(['status' => 'success']);
        break;

    case 'uncheck_all':
        if (!isAdmin()) { echo json_encode(['status' => 'error']); exit; }
        $tasks = getTasks();
        for ($i = 0; $i < count($tasks); $i++) { $tasks[$i]['is_completed'] = false; }
        saveTasks($tasks);
        echo json_encode(['status' => 'success']);
        break;

    case 'swap':
        if (!isAdmin()) { echo json_encode(['status' => 'error']); exit; }
        $id1 = (int)$input['id1']; $id2 = (int)$input['id2'];
        $order1 = (int)$input['order1']; $order2 = (int)$input['order2'];
        $tasks = getTasks();
        foreach ($tasks as &$t) {
            if ((int)$t['id'] === $id1) $t['list_order'] = $order2;
            else if ((int)$t['id'] === $id2) $t['list_order'] = $order1;
        }
        saveTasks($tasks);
        echo json_encode(['status' => 'success']);
        break;

    default:
        echo json_encode(['status' => 'error', 'message' => 'Invalid action']);
        break;
}
?>