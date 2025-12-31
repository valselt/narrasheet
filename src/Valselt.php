<?php
/**
 * Valselt ID Single File SDK
 * Simpan file ini sebagai 'Valselt.php' di folder aplikasi Anda.
 */
class Valselt {
    private $client_id;
    private $client_secret;
    private $redirect_uri;
    
    // Ganti ini sesuai domain Valselt ID Anda
    private $auth_endpoint  = "https://valseltid.ivanaldorino.web.id/authorize.php";
    private $token_endpoint = "https://valseltid.ivanaldorino.web.id/token";

    public function __construct($client_id, $client_secret, $redirect_uri = null) {
        if (session_status() === PHP_SESSION_NONE) session_start();
        
        $this->client_id = $client_id;
        $this->client_secret = $client_secret;
        
        // Jika redirect_uri tidak diisi, otomatis deteksi URL saat ini (tanpa parameter code/state)
        if ($redirect_uri === null) {
            $protocol = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? "https" : "http";
            $current_url = $protocol . "://$_SERVER[HTTP_HOST]$_SERVER[PHP_SELF]";
            $this->redirect_uri = $current_url;
        } else {
            $this->redirect_uri = $redirect_uri;
        }
    }

    public function getUser() {
        // 1. Cek apakah ini proses callback (ada parameter 'code' dan 'state')
        if (isset($_GET['code']) && isset($_GET['state'])) {
            return $this->handleCallback();
        }

        // 2. Jika bukan callback, berarti user belum login -> Redirect ke Valselt
        $this->redirectToLogin();
    }

    private function redirectToLogin() {
        $_SESSION['valselt_oauth_state'] = bin2hex(random_bytes(16));
        
        $params = [
            'client_id'     => $this->client_id,
            'redirect_uri'  => $this->redirect_uri,
            'state'         => $_SESSION['valselt_oauth_state'],
            'response_type' => 'code'
        ];
        
        header("Location: " . $this->auth_endpoint . "?" . http_build_query($params));
        exit();
    }

    private function handleCallback() {
        // Validasi State (Keamanan Anti-CSRF)
        if (!isset($_SESSION['valselt_oauth_state']) || $_GET['state'] !== $_SESSION['valselt_oauth_state']) {
            die('Error: Invalid State (Potential CSRF Attack). Silakan refresh halaman.');
        }

        // Tukar Code dengan Token (cURL)
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $this->token_endpoint);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
            'grant_type'    => 'authorization_code',
            'client_id'     => $this->client_id,
            'client_secret' => $this->client_secret,
            'redirect_uri'  => $this->redirect_uri,
            'code'          => $_GET['code']
        ]));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        
        // OPSI SSL (Matikan jika di localhost/dev bermasalah, Hidupkan di Production)
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); 
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);

        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $data = json_decode($response, true);

        if ($http_code == 200 && isset($data['user_info'])) {
            // Bersihkan URL dari parameter code & state agar bersih saat refresh
            // Opsional: Redirect ke diri sendiri
            // header("Location: " . $this->redirect_uri); 
            
            return $data['user_info'];
        } else {
            // --- BAGIAN DEBUGGING (UBAH MENJADI INI) ---
            echo "<h1>Login Gagal</h1>";
            echo "<p>HTTP Code: " . htmlspecialchars($http_code) . "</p>";
            echo "<h3>Pesan Error Asli dari Server:</h3>";
            echo "<pre style='background:#f4f4f4; padding:15px; border:1px solid #ccc; color:red;'>" . htmlspecialchars($response) . "</pre>";
            
            // Tampilkan kemungkinan error JSON
            if (json_last_error() !== JSON_ERROR_NONE) {
                echo "<p>JSON Decode Error: " . json_last_error_msg() . "</p>";
            }
            
            die(); 
            // ---------------------------------------------
        }
    }
}
?>