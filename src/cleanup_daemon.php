<?php
// cleanup_daemon.php

// Matikan batas waktu eksekusi agar bisa jalan selamanya
set_time_limit(0);

// Load logic
require_once __DIR__ . '/cleanup_logic.php';

echo "Service Pembersih Narrasheet Berjalan...\n";
echo "Interval: 15 detik.\n";

while (true) {
    // Jalankan pembersihan
    runCleanupRoutine();

    // Cek koneksi DB agar tidak timeout (MySQL gone away)
    if (!$conn->ping()) {
        echo "Koneksi DB terputus, reconnecting...\n";
        $conn->close();
        require __DIR__ . '/config.php';
    }

    // Istirahat 15 detik
    sleep(15);
}