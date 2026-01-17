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
        $default = [['id' => 1, 'task_name' => 'Selamat datang!', 'list_order' => 1, 'is_completed' => 0, 'due_date' => null, 'pomodoro_count' => 0, 'category_color' => 'transparent']];
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
    if (!$streakJson || $streakJson === '') {
        $default = ['current' => 0, 'longest' => 0, 'last_date' => null];
        saveStreak($default);
        return $default;
    }
    $data = json_decode($streakJson, true);
    return is_array($data) ? $data : ['current' => 0, 'longest' => 0, 'last_date' => null];
}

function saveStreak($streakData) {
    return redisCommand('SET', ['streak_data', json_encode($streakData)]);
}

// --- LOGIKA STREAK YANG DIPERBAIKI ---
function refreshStreak($tasks) {
    $streak = getStreak();
    
    // Set timezone ke WIB (Indonesia)
    date_default_timezone_set('Asia/Jakarta');
    
    $today = date('Y-m-d');
    $yesterday = date('Y-m-d', strtotime('-1 day'));
    
    // Hitung TOTAL tugas yang selesai
    $totalCompleted = 0;
    foreach ($tasks as $t) {
        if (!empty($t['is_completed']) && ($t['is_completed'] == 1 || $t['is_completed'] === true)) {
            $totalCompleted++;
        }
    }
    
    // CASE 1: Ada tugas yang selesai (minimal 1)
    if ($totalCompleted > 0) {
        // Jika last_date adalah hari ini, berarti sudah ada aktivitas hari ini
        if ($streak['last_date'] === $today) {
            // Streak tetap, tidak berubah (sudah dicatat hari ini)
        }
        // Jika last_date adalah kemarin, streak berlanjut
        else if ($streak['last_date'] === $yesterday) {
            $streak['current']++;
            $streak['last_date'] = $today;
            
            // Update rekor terpanjang
            if ($streak['current'] > ($streak['longest'] ?? 0)) {
                $streak['longest'] = $streak['current'];
            }
        }
        // Jika last_date bukan kemarin dan bukan hari ini, mulai streak baru
        else {
            $streak['current'] = 1;
            $streak['last_date'] = $today;
            
            // Update rekor terpanjang jika perlu
            if ($streak['current'] > ($streak['longest'] ?? 0)) {
                $streak['longest'] = $streak['current'];
            }
        }
    } 
    // CASE 2: TIDAK ADA tugas yang selesai (0 completed)
    else {
        // Jika last_date adalah hari ini, berarti baru saja di-uncheck semua
        if ($streak['last_date'] === $today) {
            // Batalkan hari ini, mundur ke kemarin
            if ($streak['current'] > 0) {
                $streak['current']--;
            }
            // Set last_date ke kemarin jika masih ada streak, null jika sudah 0
            $streak['last_date'] = $streak['current'] > 0 ? $yesterday : null;
        }
        // Jika sudah >1 hari tidak ada aktivitas, reset streak
        else if (!empty($streak['last_date']) && $streak['last_date'] < $yesterday) {
            $streak['current'] = 0;
            $streak['last_date'] = null;
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
            echo json_encode(['status' => 'locked', 'isAdmin' => false, 'streak' => ['current' => 0, 'longest' => 0, 'last_date' => null]]);
            exit;
        }
        $tasks = getTasks();
        
        // PENTING: Cek apakah ada task completed, tapi streak masih 0 dan last_date hari ini
        // Ini berarti data inconsistent, perlu di-refresh
        $streak = getStreak();
        $today = date('Y-m-d');
        $hasCompleted = false;
        foreach ($tasks as $t) {
            if (!empty($t['is_completed']) && ($t['is_completed'] == 1 || $t['is_completed'] === true)) {
                $hasCompleted = true;
                break;
            }
        }
        
        // Jika ada task completed tapi streak = 0 dan last_date = today, fix it
        if ($hasCompleted && $streak['current'] == 0 && $streak['last_date'] === $today) {
            $streak['current'] = 1;
            $streak['longest'] = max(1, $streak['longest'] ?? 0);
            saveStreak($streak);
        }
        
        echo json_encode(['status' => 'success', 'data' => $tasks, 'isAdmin' => isAdmin(), 'streak' => $streak]);
        break;

    case 'get_streak':
        if (!isset($_SESSION['logged_in'])) {
            echo json_encode(['status' => 'success', 'streak' => ['current' => 0, 'longest' => 0, 'last_date' => null]]);
            exit;
        }
        echo json_encode(['status' => 'success', 'streak' => refreshStreak(getTasks())]);
        break;

    case 'login':
        if (($input['password'] ?? '') === $ADMIN_PASS) {
            $_SESSION['logged_in'] = true;
            $_SESSION['is_admin'] = true;
            session_regenerate_id(true);
            $tasks = getTasks();
            $streak = refreshStreak($tasks);
            echo json_encode(['status' => 'success', 'data' => $tasks, 'streak' => $streak]);
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
        $message = '';

        if ($action === 'add') {
            $max = empty($tasks) ? 0 : max(array_column($tasks, 'list_order'));
            $newTask = ['id' => time(), 'task_name' => trim($input['task']), 'list_order' => $max + 1, 'is_completed' => 0, 'category_color' => $input['color'] ?? 'transparent', 'due_date' => $input['due_date'] ?? null, 'pomodoro_count' => 0];
            $tasks[] = $newTask;
            $message = 'Tugas berhasil ditambahkan!';
        } elseif ($action === 'delete') {
            $tasks = array_values(array_filter($tasks, function($t) use ($input) { return $t['id'] != $input['id']; }));
            $message = 'Tugas berhasil dihapus!';
        } elseif ($action === 'reset') {
            $tasks = [['id' => 1, 'task_name' => 'List direset', 'list_order' => 1, 'is_completed' => 0, 'due_date' => null, 'pomodoro_count' => 0, 'category_color' => 'transparent']];
            $message = 'List berhasil direset!';
        } elseif ($action === 'clear_completed') {
            $tasks = array_values(array_filter($tasks, function($t) { return empty($t['is_completed']) || $t['is_completed'] == 0; }));
            $message = 'Tugas selesai berhasil dibersihkan!';
        } elseif ($action === 'uncheck_all') {
            foreach ($tasks as &$t) $t['is_completed'] = 0;
            $message = 'Semua tugas di-uncheck!';
        } elseif ($action === 'swap') {
            foreach ($tasks as &$t) {
                if ($t['id'] == $input['id1']) $t['list_order'] = $input['order2'];
                else if ($t['id'] == $input['id2']) $t['list_order'] = $input['order1'];
            }
        } elseif ($action === 'reorder') {
            $newOrder = $input['order'] ?? [];
            foreach ($tasks as &$t) {
                $index = array_search($t['id'], $newOrder);
                if ($index !== false) $t['list_order'] = $index;
            }
        } elseif ($action === 'update_due_date') {
            foreach ($tasks as &$t) {
                if ($t['id'] == $input['id']) $t['due_date'] = $input['due_date'];
            }
            $message = 'Deadline berhasil diupdate!';
        }
        
        saveTasks($tasks);
        $streak = refreshStreak($tasks);
        echo json_encode(['status' => 'success', 'data' => getTasks(), 'message' => $message, 'streak' => $streak]);
        break;

    case 'toggle':
        if (!isset($_SESSION['logged_in'])) exit;
        $tasks = getTasks();
        
        foreach ($tasks as &$t) { 
            if ($t['id'] == $input['id']) {
                $t['is_completed'] = empty($t['is_completed']) ? 1 : 0;
            } 
        }
        saveTasks($tasks);
        $streak = refreshStreak($tasks);
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
        echo json_encode(['status' => 'error', 'message' => 'Invalid action']);
        break;
}
?>