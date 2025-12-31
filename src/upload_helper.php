<?php
require 'vendor/autoload.php'; 
require_once 'config.php';

use Aws\S3\S3Client;
use Aws\Exception\AwsException;
use Smalot\PdfParser\Parser;

function uploadToMinio($file, $username) {
    // ... (Kode upload sama seperti sebelumnya, tidak berubah) ...
    $allowed = ['pdf'];
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    
    if (!in_array($ext, $allowed)) {
        return ['status' => false, 'message' => 'Hanya file PDF yang diperbolehkan.'];
    }

    $originalName = pathinfo($file['name'], PATHINFO_FILENAME);
    $cleanName = preg_replace('/[^a-zA-Z0-9_-]/', '_', $originalName);
    
    $timestampDate = date('Ymd');
    $timestampTime = date('His');
    $objectName = "papers/{$username}-{$cleanName}-{$timestampDate}-{$timestampTime}.{$ext}";

    $s3 = new S3Client([
        'version' => 'latest',
        'region'  => MINIO_REGION,
        'endpoint' => MINIO_ENDPOINT,
        'use_path_style_endpoint' => true,
        'credentials' => ['key' => MINIO_KEY, 'secret' => MINIO_SECRET],
    ]);

    try {
        $result = $s3->putObject([
            'Bucket' => MINIO_BUCKET,
            'Key'    => $objectName,
            'SourceFile' => $file['tmp_name'],
            'ACL'    => 'public-read',
            'ContentType' => 'application/pdf'
        ]);
        
        $url = MINIO_ENDPOINT . '/' . MINIO_BUCKET . '/' . $objectName;
        
        return [
            'status' => true, 
            'url' => $url, 
            'real_name' => $file['name']
        ];

    } catch (AwsException $e) {
        return ['status' => false, 'message' => 'MinIO Error: ' . $e->getMessage()];
    }
}

// --- FUNGSI EKSTRAKSI METADATA LENGKAP ---
function extractPdfData($filePath) {
    // Siapkan array data lengkap
    $data = [
        'title' => null, 
        'author' => null, 
        'doi' => null,
        'journal' => null,
        'year' => null,
        'publisher' => null
    ];
    
    try {
        $parser = new Parser();
        $pdf = $parser->parseFile($filePath);
        $pages = $pdf->getPages();
        $text = isset($pages[0]) ? $pages[0]->getText() : '';

        // 1. CARI DOI
        if (preg_match('/(10\.\d{4,9}\/[-._;()\/:\w]+)/', $text, $matches)) {
            $doi = rtrim(trim($matches[1]), ".");
            $data['doi'] = $doi;
            
            // Tembak Crossref
            $crossref = @file_get_contents("https://api.crossref.org/works/" . urlencode($doi), false, stream_context_create(["http" => ["timeout" => 4]]));
            
            if ($crossref) {
                $json = json_decode($crossref, true);
                if (isset($json['message'])) {
                    $msg = $json['message'];
                    
                    // Ambil Judul
                    $data['title'] = $msg['title'][0] ?? null;
                    
                    // Ambil Penulis (Gabung semua)
                    if (isset($msg['author'])) {
                        $authors = array_map(function($a) {
                            return ($a['given'] ?? '') . ' ' . ($a['family'] ?? '');
                        }, $msg['author']); // Ambil semua penulis, jangan dilimit
                        $data['author'] = implode(', ', $authors);
                    }

                    // Ambil Nama Jurnal (Container Title)
                    $data['journal'] = $msg['container-title'][0] ?? null;

                    // Ambil Penerbit
                    $data['publisher'] = $msg['publisher'] ?? null;

                    // Ambil Tahun Terbit (Cek published-print, lalu published-online, lalu created)
                    if (isset($msg['published-print']['date-parts'][0][0])) {
                        $data['year'] = $msg['published-print']['date-parts'][0][0];
                    } elseif (isset($msg['published-online']['date-parts'][0][0])) {
                        $data['year'] = $msg['published-online']['date-parts'][0][0];
                    } elseif (isset($msg['created']['date-parts'][0][0])) {
                        $data['year'] = $msg['created']['date-parts'][0][0];
                    }

                    return $data; // Return data lengkap dari Crossref
                }
            }
        }

        // 2. FALLBACK METADATA INTERNAL PDF (Jika Crossref Gagal)
        $details = $pdf->getDetails();
        // ... (Logika fallback sederhana sama seperti sebelumnya) ...
        if (!empty($details['Title'])) $data['title'] = $details['Title'];
        if (!empty($details['Author'])) $data['author'] = $details['Author'];

    } catch (Exception $e) {
        // Fail silent
    }
    
    return $data;
}
?>