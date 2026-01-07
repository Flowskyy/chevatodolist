<?php
session_start();
// Jangan ada spasi atau baris kosong sebelum <?php

// 1. KONEKSI DATABASE
$dsn = getenv('POSTGRES_URL'); 

try {
    $pdo = new PDO($dsn);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    header('Content-Type: application/json');
    echo json_encode(['status' => 'error', 'message' => 'DB Error']);
    exit;
}

$action = $_GET['action'] ?? '';
$input = json_decode(file_get_contents('php://input'), true);
$ADMIN_PASS = 'admin123';

function isAdmin() {
    return isset($_SESSION['is_admin']) && $_SESSION['is_admin'] === true;
}

header('Content-Type: application/json');

switch ($action) {
    case 'get_all':
        $stmt = $pdo->query("SELECT * FROM tasks ORDER BY list_order ASC, id ASC");
        echo json_encode([
            'status' => 'success', 
            'data' => $stmt->fetchAll(PDO::FETCH_ASSOC), 
            'isAdmin' => isAdmin()
        ]);
        break;

    case 'login':
        $submittedPass = $input['password'] ?? '';
        if ($submittedPass === $ADMIN_PASS) {
            $_SESSION['is_admin'] = true;
            session_write_close(); 
            echo json_encode(['status' => 'success']);
        } else {
            http_response_code(401); // Kirim status unauthorized
            echo json_encode(['status' => 'error', 'message' => 'Password salah']);
        }
        break;

    case 'logout':
        session_destroy();
        echo json_encode(['status' => 'success']);
        break;

    case 'add':
        if (!isAdmin()) { http_response_code(403); exit; }
        $max = $pdo->query("SELECT COALESCE(MAX(list_order), 0) FROM tasks")->fetchColumn();
        $stmt = $pdo->prepare("INSERT INTO tasks (task_name, list_order) VALUES (?, ?)");
        $stmt->execute([$input['task'], (int)$max + 1]);
        echo json_encode(['status' => 'success']);
        break;

    case 'toggle':
        $stmt = $pdo->prepare("UPDATE tasks SET is_completed = NOT is_completed WHERE id = ?");
        $stmt->execute([$input['id']]);
        echo json_encode(['status' => 'success']);
        break;

    case 'delete':
        if (!isAdmin()) { http_response_code(403); exit; }
        $stmt = $pdo->prepare("DELETE FROM tasks WHERE id = ?");
        $stmt->execute([$input['id']]);
        echo json_encode(['status' => 'success']);
        break;

    case 'swap':
        if (!isAdmin()) { http_response_code(403); exit; }
        $pdo->prepare("UPDATE tasks SET list_order = ? WHERE id = ?")->execute([$input['order2'], $input['id1']]);
        $pdo->prepare("UPDATE tasks SET list_order = ? WHERE id = ?")->execute([$input['order1'], $input['id2']]);
        echo json_encode(['status' => 'success']);
        break;
}