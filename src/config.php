<?php
// Load .env manual
$envPath = __DIR__ . '/.env';
if (file_exists($envPath)) {
    $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) continue;
        list($key, $value) = explode('=', $line, 2);
        putenv(trim($key) . '=' . trim($value));
    }
}

// Database Configuration
$db_host = getenv('DB_HOST');
$db_user = getenv('DB_USER');
$db_pass = getenv('DB_PASS');
$db_name = getenv('DB_NAME');
$db_port = getenv('DB_PORT');

$conn = new mysqli($db_host, $db_user, $db_pass, $db_name, $db_port);

if ($conn->connect_error) {
    die("Koneksi Database Gagal: " . $conn->connect_error);
}

// Valselt
define('VALSELT_CLIENT_ID', getenv('VALSELT_CLIENT_ID'));
define('VALSELT_CLIENT_SECRET', getenv('VALSELT_CLIENT_SECRET'));

// MinIO / S3
define('MINIO_ENDPOINT', getenv('MINIO_ENDPOINT'));
define('MINIO_KEY', getenv('MINIO_KEY'));
define('MINIO_SECRET', getenv('MINIO_SECRET'));
define('MINIO_BUCKET', getenv('MINIO_BUCKET'));
define('MINIO_REGION', getenv('MINIO_REGION'));
