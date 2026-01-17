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
        $default = [['id' => 1, 'task_name' => 'Selamat datang!', 'list_order' => 1, 'is_completed' => false, 'due_date' => null]];
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

function getStreak() {
    $streakJson = redisCommand('GET', ['streak_data']);
    if (!$streakJson) {
        return ['current' => 0, 'longest' => 0, 'last_date' => null];
    }
    return json_decode($streakJson, true);
}

function saveStreak($streakData) {
    return redisCommand('SET', ['streak_data', json_encode($streakData)]);
}

function updateStreak() {
    $streak = getStreak();
    $today = date('Y-m-d');
    $lastDate = $streak['last_date'];
    
    if ($lastDate === $today) {
        // Already updated today
        return $streak;
    }
    
    if ($lastDate === null) {
        // First time
        $streak = ['current' => 1, 'longest' => 1, 'last_date' => $today];
    } else {
        $yesterday = date('Y-m-d', strtotime('-1 day'));
        if ($lastDate === $yesterday) {
            // Continue streak
            $streak['current']++;
            $streak['longest'] = max($streak['longest'], $streak['current']);
            $streak['last_date'] = $today;
        } else {
            // Streak broken
            $streak['current'] = 1;
            $streak['last_date'] = $today;
        }
    }
    
    saveStreak($streak);
    return $streak;
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
        $streak = getStreak();
        echo json_encode([
            'status' => 'success', 
            'data' => getTasks(), 
            'isAdmin' => isAdmin(),
            'streak' => $streak
        ]);
        break;

    case 'get_streak':
        if (!isset($_SESSION['logged_in'])) exit;
        echo json_encode(['status' => 'success', 'streak' => getStreak()]);
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
    case 'reorder':
    case 'update_due_date':
        if (!isAdmin()) { echo json_encode(['status' => 'error', 'message' => 'Unauthorized']); exit; }
        
        $tasks = getTasks();
        if ($action === 'add') {
            $max = empty($tasks) ? 0 : max(array_column($tasks, 'list_order'));
            $newTask = [
                'id' => time(), 
                'task_name' => trim($input['task']), 
                'list_order' => $max + 1, 
                'is_completed' => false,
                'category_color' => $input['color'] ?? 'transparent',
                'due_date' => $input['due_date'] ?? null,
                'pomodoro_count' => 0
            ];
            $tasks[] = $newTask;
            saveTasks($tasks);
            echo json_encode(['status' => 'success', 'data' => getTasks(), 'message' => 'Tugas berhasil ditambahkan!']);
        } elseif ($action === 'delete') {
            $tasks = array_values(array_filter($tasks, function($t) use ($input) { return $t['id'] != $input['id']; }));
            saveTasks($tasks);
            echo json_encode(['status' => 'success', 'data' => getTasks(), 'message' => 'Tugas berhasil dihapus!']);
        } elseif ($action === 'reset') {
            $tasks = [['id' => 1, 'task_name' => 'List direset', 'list_order' => 1, 'is_completed' => false, 'due_date' => null]];
            saveTasks($tasks);
            echo json_encode(['status' => 'success', 'data' => getTasks(), 'message' => 'List berhasil direset!']);
        } elseif ($action === 'clear_completed') {
            $tasks = array_values(array_filter($tasks, function($t) { return !$t['is_completed']; }));
            saveTasks($tasks);
            echo json_encode(['status' => 'success', 'data' => getTasks(), 'message' => 'Tugas selesai berhasil dibersihkan!']);
        } elseif ($action === 'uncheck_all') {
            foreach ($tasks as &$t) $t['is_completed'] = false;
            saveTasks($tasks);
            echo json_encode(['status' => 'success', 'data' => getTasks(), 'message' => 'Semua tugas di-uncheck!']);
        } elseif ($action === 'swap') {
            foreach ($tasks as &$t) {
                if ($t['id'] == $input['id1']) $t['list_order'] = $input['order2'];
                else if ($t['id'] == $input['id2']) $t['list_order'] = $input['order1'];
            }
            saveTasks($tasks);
            echo json_encode(['status' => 'success', 'data' => getTasks()]);
        } elseif ($action === 'reorder') {
            $newOrder = $input['order'] ?? [];
            foreach ($tasks as &$t) {
                $index = array_search($t['id'], $newOrder);
                if ($index !== false) {
                    $t['list_order'] = $index;
                }
            }
            saveTasks($tasks);
            echo json_encode(['status' => 'success', 'data' => getTasks()]);
        } elseif ($action === 'update_due_date') {
            foreach ($tasks as &$t) {
                if ($t['id'] == $input['id']) {
                    $t['due_date'] = $input['due_date'];
                }
            }
            saveTasks($tasks);
            echo json_encode(['status' => 'success', 'data' => getTasks(), 'message' => 'Deadline berhasil diupdate!']);
        }
        break;

    case 'toggle':
        if (!isset($_SESSION['logged_in'])) exit;
        $tasks = getTasks();
        $wasCompleted = false;
        foreach ($tasks as &$t) { 
            if ($t['id'] == $input['id']) {
                $t['is_completed'] = !$t['is_completed'];
                if ($t['is_completed']) {
                    $wasCompleted = true;
                }
            }
        }
        saveTasks($tasks);
        
        $streak = getStreak();
        if ($wasCompleted) {
            $streak = updateStreak();
        }
        
        echo json_encode(['status' => 'success', 'data' => getTasks(), 'streak' => $streak]);
        break;

    case 'pomodoro_complete':
        if (!isset($_SESSION['logged_in'])) exit;
        $tasks = getTasks();
        foreach ($tasks as &$t) {
            if ($t['id'] == $input['id']) {
                $t['pomodoro_count'] = ($t['pomodoro_count'] ?? 0) + 1;
            }
        }
        saveTasks($tasks);
        echo json_encode(['status' => 'success', 'data' => getTasks()]);
        break;

    default:
        echo json_encode(['status' => 'error']);
        break;
}
?>