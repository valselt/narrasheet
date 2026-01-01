<?php
// cleanup_logic.php

// Pastikan path vendor benar
require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/config.php';

use Aws\S3\S3Client;
use Aws\Exception\AwsException;

function runCleanupRoutine() {
    global $conn; // Menggunakan koneksi DB dari config.php

    // Logging ke stdout agar muncul di docker logs
    $log = "[" . date('Y-m-d H:i:s') . "] ";

    // 1. Ambil daftar semua link file yang VALID dari Database
    $validLinks = [];
    $sql = "SELECT link_upload FROM papers WHERE link_upload IS NOT NULL AND link_upload != ''";
    $result = $conn->query($sql);

    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $validLinks[$row['link_upload']] = true;
        }
    }

    // 2. Setup S3 Client
    $s3 = new S3Client([
        'version' => 'latest',
        'region'  => MINIO_REGION,
        'endpoint' => MINIO_ENDPOINT,
        'use_path_style_endpoint' => true,
        'credentials' => ['key' => MINIO_KEY, 'secret' => MINIO_SECRET],
    ]);

    try {
        // 3. List semua object di bucket
        $results = $s3->getPaginator('ListObjectsV2', [
            'Bucket' => MINIO_BUCKET,
            'Prefix' => 'papers/' 
        ]);

        $deletedCount = 0;

        foreach ($results as $result) {
            if (empty($result['Contents'])) continue;

            foreach ($result['Contents'] as $object) {
                $key = $object['Key'];
                $lastModified = $object['LastModified']; 
                
                // URL file di MinIO
                $fileUrl = MINIO_ENDPOINT . '/' . MINIO_BUCKET . '/' . $key;

                // 4. Safety Check: JANGAN HAPUS FILE BARU (< 30 Menit)
                // Ini penting agar file yang baru diupload user (tapi belum klik save) tidak terhapus.
                $fileAge = time() - strtotime($lastModified);
                
                if ($fileAge < 1800) { // 1800 detik = 30 menit
                    continue; 
                }

                // 5. Cek apakah file ada di Database
                if (!isset($validLinks[$fileUrl])) {
                    // FILE YATIM PIATU (Tidak ada di DB & Sudah lama) -> HAPUS
                    echo $log . "MENGHAPUS: " . $key . "\n";
                    
                    try {
                        $s3->deleteObject([
                            'Bucket' => MINIO_BUCKET,
                            'Key'    => $key
                        ]);
                        $deletedCount++;
                    } catch (AwsException $e) {
                        echo $log . "ERROR Gagal hapus $key: " . $e->getMessage() . "\n";
                    }
                }
            }
        }

        if ($deletedCount > 0) {
            echo $log . "Selesai. Total dihapus: $deletedCount file.\n";
        }

    } catch (AwsException $e) {
        echo $log . "FATAL ERROR MinIO: " . $e->getMessage() . "\n";
    }
}