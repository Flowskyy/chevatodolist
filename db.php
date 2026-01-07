<?php
$host = 'localhost';
$db   = 'todo_db';
$user = 'root';      // Default user XAMPP
$pass = '';          // Default password XAMPP (kosong)

try {
    $pdo = new PDO("mysql:host=$host;dbname=$db", $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Koneksi gagal: " . $e->getMessage());
}
?>