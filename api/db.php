<?php
/**
 * Database Connection File
 * File ini menangani koneksi ke PostgreSQL database
 * Menggunakan PDO untuk koneksi yang aman
 */

// Ambil URL koneksi dari environment variable Vercel
$db_url = getenv('POSTGRES_URL');

// Cek apakah environment variable sudah di-set
if (!$db_url) {
    die(json_encode([
        'status' => 'error',
        'message' => 'POSTGRES_URL environment variable tidak ditemukan.',
        'hint' => 'Pastikan POSTGRES_URL sudah di-set di Vercel Environment Variables'
    ]));
}

try {
    // Koneksi menggunakan PDO untuk PostgreSQL
    $pdo = new PDO($db_url);
    
    // Set error mode ke exception agar mudah debug
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Set default fetch mode ke associative array
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    
    // Set charset UTF-8 untuk support karakter Indonesia
    $pdo->exec("SET NAMES 'utf8'");
    
    // Optional: Uncomment untuk test koneksi
    // echo json_encode([
    //     'status' => 'success',
    //     'message' => 'Database connected successfully!',
    //     'pdo_class' => get_class($pdo)
    // ]);
    
} catch (PDOException $e) {
    // Handle error koneksi
    die(json_encode([
        'status' => 'error',
        'message' => 'Koneksi database gagal',
        'error' => $e->getMessage(),
        'hint' => 'Periksa kembali POSTGRES_URL di environment variables'
    ]));
}

// Return $pdo object untuk digunakan di file lain
return $pdo;
?>