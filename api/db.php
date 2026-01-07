<?php
/**
 * Neon Database Connection File
 * File ini menangani koneksi ke Neon PostgreSQL database
 * Menggunakan PDO untuk koneksi yang aman
 */

// Ambil URL koneksi dari environment variable
// Neon biasanya memberikan DATABASE_URL atau bisa custom
$db_url = getenv('DATABASE_URL') ?: getenv('POSTGRES_URL');

// Cek apakah environment variable sudah di-set
if (!$db_url) {
    die(json_encode([
        'status' => 'error',
        'message' => 'Database URL tidak ditemukan.',
        'hint' => 'Set DATABASE_URL atau POSTGRES_URL di Vercel Environment Variables'
    ]));
}

try {
    // Koneksi menggunakan PDO untuk PostgreSQL
    // Neon requires SSL connection
    $pdo = new PDO($db_url, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
        // Neon specific: Enable SSL
        PDO::PGSQL_ATTR_DISABLE_PREPARES => false,
    ]);
    
    // Set charset UTF-8 untuk support karakter Indonesia
    $pdo->exec("SET NAMES 'utf8'");
    
    // Set timezone (opsional)
    $pdo->exec("SET TIME ZONE 'Asia/Jakarta'");
    
    // Optional: Uncomment untuk test koneksi
    // echo json_encode([
    //     'status' => 'success',
    //     'message' => 'Neon Database connected successfully!',
    //     'server_info' => $pdo->getAttribute(PDO::ATTR_SERVER_VERSION)
    // ]);
    
} catch (PDOException $e) {
    // Handle error koneksi
    die(json_encode([
        'status' => 'error',
        'message' => 'Koneksi database gagal',
        'error' => $e->getMessage(),
        'hint' => 'Periksa kembali DATABASE_URL dari Neon di environment variables'
    ]));
}

// Return $pdo object untuk digunakan di file lain
return $pdo;
?>