<?php
// Pastikan path vendor benar
$vendorPath = __DIR__ . '/vendor/autoload.php';
if (file_exists($vendorPath)) {
    require $vendorPath;
} else {
    header('Content-Type: application/json');
    echo json_encode(['status' => false, 'message' => 'Vendor/Autoload tidak ditemukan. Jalankan composer install.']);
    exit;
}

require_once 'config.php';

use Aws\S3\S3Client;
use Aws\Exception\AwsException;
use Smalot\PdfParser\Parser;

function uploadToMinio($file, $username) {
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

// --- FUNGSI UTAMA: SMART EXTRACT (MENDELEY STYLE) ---
function extractPdfData($filePath) {
    // Struktur default kosong
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
        // Ambil teks dari halaman 1 (biasanya judul/doi ada di sini)
        $text = isset($pages[0]) ? $pages[0]->getText() : '';
        
        // --- STRATEGI 1: CARI DOI DI TEXT (AKURASI TINGGI) ---
        // Regex DOI yang lebih robust (menangkap format standard & url)
        if (preg_match('/10\.\d{4,9}\/[-._;()\/:\w]+/', $text, $matches)) {
            $doiCandidates = $matches[0];
            // Bersihkan trailing punctuation (kadang titik di akhir kalimat ikut)
            $doi = rtrim($doiCandidates, ".,;");
            
            $apiData = fetchCrossrefMetadata($doi, 'doi');
            if ($apiData) {
                return array_merge($data, $apiData);
            }
        }

        // --- STRATEGI 2: TITLE SEARCH (SEARCH QUERY) ---
        // Jika DOI tidak ketemu, kita ambil "potongan teks awal" yang bersih
        // lalu kita "Tanya" ke Crossref: "Apakah kamu punya paper dengan judul mirip teks ini?"
        $cleanText = cleanTextForSearch($text);
        
        if (strlen($cleanText) > 10) {
            // Cari di Crossref menggunakan query bibliographic
            $apiData = fetchCrossrefMetadata($cleanText, 'query');
            if ($apiData) {
                return array_merge($data, $apiData);
            }
        }

        // --- STRATEGI 3: FALLBACK INTERNAL PDF (AKURASI RENDAH) ---
        // Dipakai hanya jika Strategi 1 & 2 gagal total
        $details = $pdf->getDetails();
        if (!empty($details['Title'])) $data['title'] = $details['Title'];
        if (!empty($details['Author'])) $data['author'] = $details['Author'];

    } catch (Exception $e) {
        // Fail silent, return data kosong
    }
    
    return $data;
}

// --- HELPER: BERSIHKAN TEKS UNTUK PENCARIAN ---
function cleanTextForSearch($rawText) {
    // Ambil 300 karakter pertama saja (biasanya judul ada di awal)
    $headText = substr($rawText, 0, 300);
    
    // Hapus baris baru dan tab
    $headText = str_replace(["\n", "\r", "\t"], " ", $headText);
    
    // Hapus karakter aneh / non-alphanumeric dasar
    $headText = preg_replace('/[^a-zA-Z0-9\s\-\.,:]/', '', $headText);
    
    // Hapus kata-kata "sampah" yang sering muncul di header jurnal
    $removeWords = ['Available online', 'ScienceDirect', 'Procedia', 'Journal of', 'ISSN', 'Vol.', 'No.', 'pp.', 'www.', 'http'];
    $headText = str_ireplace($removeWords, '', $headText);
    
    // Hapus spasi ganda
    return trim(preg_replace('/\s+/', ' ', $headText));
}

// --- HELPER: REQUEST KE CROSSREF API ---
function fetchCrossrefMetadata($query, $type = 'doi') {
    $url = "";
    
    if ($type === 'doi') {
        $url = "https://api.crossref.org/works/" . urlencode($query);
    } else {
        // Search query mode (seperti search bar)
        // Kita limit 1 hasil paling relevan
        $url = "https://api.crossref.org/works?query.bibliographic=" . urlencode($query) . "&rows=1";
    }

    // Gunakan context stream untuk timeout handling
    $context = stream_context_create([
        "http" => [
            "timeout" => 5, // Timeout 5 detik agar tidak loading lama
            "header" => "User-Agent: Narrasheet/1.0 (mailto:admin@ivanaldorino.web.id)" // Etika API: Beritahu siapa kita
        ]
    ]);

    $response = @file_get_contents($url, false, $context);
    
    if ($response) {
        $json = json_decode($response, true);
        
        // Handle response beda antara DOI (langsung item) dan Query (list items)
        $item = null;
        if ($type === 'doi' && isset($json['message'])) {
            $item = $json['message'];
        } elseif ($type === 'query' && isset($json['message']['items'][0])) {
            $item = $json['message']['items'][0];
        }

        if ($item) {
            $extracted = [];
            $extracted['title'] = $item['title'][0] ?? null;
            $extracted['doi']   = $item['DOI'] ?? null;
            $extracted['journal'] = $item['container-title'][0] ?? null;
            $extracted['publisher'] = $item['publisher'] ?? null;
            
            // Ambil Tahun
            if (isset($item['published-print']['date-parts'][0][0])) {
                $extracted['year'] = $item['published-print']['date-parts'][0][0];
            } elseif (isset($item['published-online']['date-parts'][0][0])) {
                $extracted['year'] = $item['published-online']['date-parts'][0][0];
            } elseif (isset($item['created']['date-parts'][0][0])) {
                $extracted['year'] = $item['created']['date-parts'][0][0];
            }
            
            // Ambil Penulis
            if (isset($item['author'])) {
                $authors = array_map(function($a) {
                    return ($a['given'] ?? '') . ' ' . ($a['family'] ?? '');
                }, $item['author']);
                $extracted['author'] = implode(', ', $authors);
            }

            return $extracted;
        }
    }
    
    return null;
}