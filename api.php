<?php
session_start();
header('Content-Type: application/json');
require 'db.php';

$action = $_GET['action'] ?? '';
$input = json_decode(file_get_contents('php://input'), true);

// Password Admin Hardcoded (Sesuai request)
$ADMIN_PASS = 'admin123';

// Helper: Cek apakah user adalah admin
function isAdmin() {
    return isset($_SESSION['is_admin']) && $_SESSION['is_admin'] === true;
}

switch ($action) {
    // 1. GET ALL TASKS
    case 'get_all':
        $stmt = $pdo->query("SELECT * FROM tasks ORDER BY list_order ASC, id ASC");
        $tasks = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['status' => 'success', 'data' => $tasks, 'isAdmin' => isAdmin()]);
        break;

    // 2. LOGIN ADMIN
    case 'login':
        if (isset($input['password']) && $input['password'] === $ADMIN_PASS) {
            $_SESSION['is_admin'] = true;
            echo json_encode(['status' => 'success']);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Password salah']);
        }
        break;

    // 3. LOGOUT
    case 'logout':
        session_destroy();
        echo json_encode(['status' => 'success']);
        break;

    // 4. ADD TASK (Admin Only)
    case 'add':
        if (!isAdmin()) { echo json_encode(['status' => 'error']); exit; }
        
        $task = $input['task'];
        // Set list_order paling bawah
        $stmt = $pdo->prepare("INSERT INTO tasks (task_name, list_order) VALUES (?, ?)");
        // Ambil max order untuk taruh di paling bawah
        $max = $pdo->query("SELECT MAX(list_order) FROM tasks")->fetchColumn();
        $stmt->execute([$task, $max + 1]);
        
        echo json_encode(['status' => 'success']);
        break;

    // 5. TOGGLE CHECKLIST (User & Admin bisa)
    case 'toggle':
        $id = $input['id'];
        // Ambil status sekarang
        $stmt = $pdo->prepare("SELECT is_completed FROM tasks WHERE id = ?");
        $stmt->execute([$id]);
        $current = $stmt->fetchColumn();
        
        // Flip status (0 jadi 1, 1 jadi 0)
        $newState = $current ? 0 : 1;
        $update = $pdo->prepare("UPDATE tasks SET is_completed = ? WHERE id = ?");
        $update->execute([$newState, $id]);
        
        echo json_encode(['status' => 'success']);
        break;

    // 6. DELETE (Admin Only)
    case 'delete':
        if (!isAdmin()) { echo json_encode(['status' => 'error']); exit; }
        $stmt = $pdo->prepare("DELETE FROM tasks WHERE id = ?");
        $stmt->execute([$input['id']]);
        echo json_encode(['status' => 'success']);
        break;

    // 7. RESET ALL (Admin Only)
    case 'reset':
        if (!isAdmin()) { echo json_encode(['status' => 'error']); exit; }
        $pdo->exec("TRUNCATE TABLE tasks"); 
        // Opsional: Tambah default task
        $pdo->exec("INSERT INTO tasks (task_name, list_order) VALUES ('Mulai hari dengan senyum', 1)");
        echo json_encode(['status' => 'success']);
        break;

    // 8. SWAP ORDER (Admin Only - Naik Turun)
    case 'swap':
        if (!isAdmin()) { echo json_encode(['status' => 'error']); exit; }
        // Ini logika sederhana menukar nilai list_order antar 2 item
        $id1 = $input['id1'];
        $order1 = $input['order1'];
        $id2 = $input['id2'];
        $order2 = $input['order2'];

        $pdo->beginTransaction();
        $s1 = $pdo->prepare("UPDATE tasks SET list_order = ? WHERE id = ?");
        $s1->execute([$order2, $id1]); // ID1 dapet urutan ID2
        $s2 = $pdo->prepare("UPDATE tasks SET list_order = ? WHERE id = ?");
        $s2->execute([$order1, $id2]); // ID2 dapet urutan ID1
        $pdo->commit();
        echo json_encode(['status' => 'success']);
        break;

    default:
        echo json_encode(['status' => 'error', 'message' => 'Invalid action']);
        break;
}
?>