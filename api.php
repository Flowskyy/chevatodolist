<?php
// Mengaktifkan error reporting untuk debugging (bisa dihapus jika sudah lancar)
error_reporting(E_ALL);
ini_set('display_errors', 1);

session_start();
header('Content-Type: application/json');

// Mengambil URL koneksi dari environment variable Vercel
$dsn = getenv('POSTGRES_URL'); 

try {
    // Tambahkan options agar koneksi lebih stabil
    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ];
    $pdo = new PDO($dsn, null, null, $options);
} catch (PDOException $e) {
    echo json_encode(['status' => 'error', 'message' => 'Koneksi database gagal: ' . $e->getMessage()]);
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
        try {
            $stmt = $pdo->query("SELECT * FROM tasks ORDER BY list_order ASC, id ASC");
            echo json_encode([
                'status' => 'success', 
                'data' => $stmt->fetchAll(), 
                'isAdmin' => isAdmin()
            ]);
        } catch (Exception $e) {
            echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        }
        break;

    case 'login':
        if (isset($input['password']) && $input['password'] === $ADMIN_PASS) {
            $_SESSION['is_admin'] = true;
            // Penting: panggil session_write_close agar session tersimpan di Vercel
            session_write_close();
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
        if (!isAdmin()) { echo json_encode(['status' => 'error', 'message' => 'Unauthorized']); exit; }
        $max = $pdo->query("SELECT COALESCE(MAX(list_order), 0) FROM tasks")->fetchColumn();
        $stmt = $pdo->prepare("INSERT INTO tasks (task_name, list_order) VALUES (?, ?)");
        $stmt->execute([$input['task'], (int)$max + 1]);
        echo json_encode(['status' => 'success']);
        break;

    case 'toggle':
        // Toggle boolean di Postgres
        $stmt = $pdo->prepare("UPDATE tasks SET is_completed = NOT is_completed WHERE id = ?");
        $stmt->execute([$input['id']]);
        echo json_encode(['status' => 'success']);
        break;

    case 'delete':
        if (!isAdmin()) { echo json_encode(['status' => 'error', 'message' => 'Unauthorized']); exit; }
        $stmt = $pdo->prepare("DELETE FROM tasks WHERE id = ?");
        $stmt->execute([$input['id']]);
        echo json_encode(['status' => 'success']);
        break;

    case 'reset':
        if (!isAdmin()) { echo json_encode(['status' => 'error', 'message' => 'Unauthorized']); exit; }
        $pdo->exec("TRUNCATE TABLE tasks RESTART IDENTITY");
        $pdo->exec("INSERT INTO tasks (task_name, list_order) VALUES ('List telah direset oleh Admin', 1)");
        echo json_encode(['status' => 'success']);
        break;

    case 'swap':
        if (!isAdmin()) { echo json_encode(['status' => 'error', 'message' => 'Unauthorized']); exit; }
        $pdo->prepare("UPDATE tasks SET list_order = ? WHERE id = ?")->execute([$input['order2'], $input['id1']]);
        $pdo->prepare("UPDATE tasks SET list_order = ? WHERE id = ?")->execute([$input['order1'], $input['id2']]);
        echo json_encode(['status' => 'success']);
        break;
    
    default:
        echo json_encode(['status' => 'error', 'message' => 'Invalid action']);
        break;
}