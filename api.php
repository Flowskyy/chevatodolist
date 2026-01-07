<?php
session_start();
header('Content-Type: application/json');

// Gunakan POSTGRES_URL yang otomatis disediakan Vercel
$dsn = getenv('POSTGRES_URL'); 

try {
    $pdo = new PDO($dsn);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    echo json_encode(['status' => 'error', 'message' => 'Koneksi gagal']);
    exit;
}

$action = $_GET['action'] ?? '';
$input = json_decode(file_get_contents('php://input'), true);
$ADMIN_PASS = 'admin123';

function isAdmin() {
    return isset($_SESSION['is_admin']) && $_SESSION['is_admin'] === true;
}

switch ($action) {
    case 'get_all':
        // Postgres query
        $stmt = $pdo->query("SELECT * FROM tasks ORDER BY list_order ASC, id ASC");
        echo json_encode(['status' => 'success', 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC), 'isAdmin' => isAdmin()]);
        break;

    case 'login':
        if (($input['password'] ?? '') === $ADMIN_PASS) {
            $_SESSION['is_admin'] = true;
            echo json_encode(['status' => 'success']);
        } else {
            echo json_encode(['status' => 'error']);
        }
        break;

    case 'logout':
        session_destroy();
        echo json_encode(['status' => 'success']);
        break;

    case 'add':
        if (!isAdmin()) exit;
        $max = $pdo->query("SELECT COALESCE(MAX(list_order), 0) FROM tasks")->fetchColumn();
        $stmt = $pdo->prepare("INSERT INTO tasks (task_name, list_order) VALUES (?, ?)");
        $stmt->execute([$input['task'], $max + 1]);
        echo json_encode(['status' => 'success']);
        break;

    case 'toggle':
        // Di Postgres, toggle boolean cukup pakai NOT
        $stmt = $pdo->prepare("UPDATE tasks SET is_completed = NOT is_completed WHERE id = ?");
        $stmt->execute([$input['id']]);
        echo json_encode(['status' => 'success']);
        break;

    case 'delete':
        if (!isAdmin()) exit;
        $stmt = $pdo->prepare("DELETE FROM tasks WHERE id = ?");
        $stmt->execute([$input['id']]);
        echo json_encode(['status' => 'success']);
        break;

    case 'reset':
        if (!isAdmin()) exit;
        // Reset ID kembali ke 1 di Postgres
        $pdo->exec("TRUNCATE TABLE tasks RESTART IDENTITY");
        $pdo->exec("INSERT INTO tasks (task_name, list_order) VALUES ('List telah direset oleh Admin', 1)");
        echo json_encode(['status' => 'success']);
        break;

    case 'swap':
        if (!isAdmin()) exit;
        $pdo->prepare("UPDATE tasks SET list_order = ? WHERE id = ?")->execute([$input['order2'], $input['id1']]);
        $pdo->prepare("UPDATE tasks SET list_order = ? WHERE id = ?")->execute([$input['order1'], $input['id2']]);
        echo json_encode(['status' => 'success']);
        break;
}