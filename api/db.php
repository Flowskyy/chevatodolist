<?php
// Ambil URL koneksi dari environment variable Vercel
$db_url = getenv('POSTGRES_URL');

if (!$db_url) {
    die("Koneksi gagal: POSTGRES_URL tidak ditemukan.");
}

try {
    // Koneksi menggunakan PDO untuk PostgreSQL
    $pdo = new PDO($db_url);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    die("Koneksi gagal: " . $e->getMessage());
}
?>