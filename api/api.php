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

$UPSTASH_URL = getenv('UPSTASH_REDIS_REST_URL');
$UPSTASH_TOKEN = getenv('UPSTASH_REDIS_REST_TOKEN');

if (!$UPSTASH_URL || !$UPSTASH_TOKEN) {
    die(json_encode(['status' => 'error', 'message' => 'Redis configuration not found']));
}

function redisCommand($command, $args = []) {
    global $UPSTASH_URL, $UPSTASH_TOKEN;
    $data = array_merge([$command], $args);
    $ch = curl_init($UPSTASH_URL);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . $UPSTASH_TOKEN, 'Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($httpCode !== 200) return null;
    $result = json_decode($response, true);
    return $result['result'] ?? null;
}

function getTasks() {
    $tasksJson = redisCommand('GET', ['tasks']);
    if (!$tasksJson || $tasksJson === '') {
        $default = [['id' => 1, 'task_name' => 'Selamat datang!', 'list_order' => 1, 'is_completed' => false]];
        saveTasks($default);
        return $default;
    }
    $tasks = json_decode($tasksJson, true);
    if (!is_array($tasks)) $tasks = [];
    usort($tasks, function($a, $b) { return ($a['list_order'] ?? 0) - ($b['list_order'] ?? 0); });
    return $tasks;
}

function saveTasks($tasks) {
    return redisCommand('SET', ['tasks', json_encode($tasks)]);
}

$action = $_GET['action'] ?? '';
$input = json_decode(file_get_contents('php://input'), true) ?? [];
$ADMIN_PASS = 'cepayakinn'; 

function isAdmin() { return isset($_SESSION['is_admin']) && $_SESSION['is_admin'] === true; }

switch ($action) {
    case 'get_all':
        if (!isset($_SESSION['logged_in'])) {
            echo json_encode(['status' => 'locked', 'isAdmin' => false]);
            exit;
        }
        echo json_encode(['status' => 'success', 'data' => getTasks(), 'isAdmin' => isAdmin()]);
        break;

    case 'login':
        if (($input['password'] ?? '') === $ADMIN_PASS) {
            $_SESSION['logged_in'] = true;
            $_SESSION['is_admin'] = true;
            session_regenerate_id(true);
            echo json_encode(['status' => 'success']);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Password salah']);
        }
        break;

    case 'logout':
        session_destroy();
        echo json_encode(['status' => 'success']);
        break;

    case 'add':
    case 'delete':
    case 'reset':
    case 'clear_completed':
    case 'uncheck_all':
    case 'swap':
        if (!isAdmin()) { echo json_encode(['status' => 'error', 'message' => 'Unauthorized']); exit; }
        
        $tasks = getTasks();
        if ($action === 'add') {
            $max = empty($tasks) ? 0 : max(array_column($tasks, 'list_order'));
            $tasks[] = ['id' => time(), 'task_name' => trim($input['task']), 'list_order' => $max + 1, 'is_completed' => false];
        } elseif ($action === 'delete') {
            $tasks = array_values(array_filter($tasks, function($t) use ($input) { return $t['id'] != $input['id']; }));
        } elseif ($action === 'reset') {
            $tasks = [['id' => 1, 'task_name' => 'List direset', 'list_order' => 1, 'is_completed' => false]];
        } elseif ($action === 'clear_completed') {
            $tasks = array_values(array_filter($tasks, function($t) { return !$t['is_completed']; }));
        } elseif ($action === 'uncheck_all') {
            foreach ($tasks as &$t) $t['is_completed'] = false;
        } elseif ($action === 'swap') {
            foreach ($tasks as &$t) {
                if ($t['id'] == $input['id1']) $t['list_order'] = $input['order2'];
                else if ($t['id'] == $input['id2']) $t['list_order'] = $input['order1'];
            }
        }
        saveTasks($tasks);
        echo json_encode(['status' => 'success']);
        break;

    case 'toggle':
        if (!isset($_SESSION['logged_in'])) exit;
        $tasks = getTasks();
        foreach ($tasks as &$t) { if ($t['id'] == $input['id']) $t['is_completed'] = !$t['is_completed']; }
        saveTasks($tasks);
        echo json_encode(['status' => 'success']);
        break;

    default:
        echo json_encode(['status' => 'error']);
        break;
}
?>