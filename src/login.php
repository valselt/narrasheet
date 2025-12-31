<?php
session_start();
require_once 'Valselt.php';
require_once 'config.php';

// Inisialisasi Valselt
// Pastikan redirect URI mengarah ke file ini agar callback ditangkap di sini
$valselt = new Valselt(VALSELT_CLIENT_ID, VALSELT_CLIENT_SECRET);

// getUser() otomatis akan:
// 1. Redirect ke Valselt jika belum ada 'code'
// 2. Menukar 'code' menjadi data user jika ini adalah callback
$user_info = $valselt->getUser();

if ($user_info) {
    // Simpan data user ke session
    $_SESSION['user'] = $user_info;
    
    // Login sukses, lempar ke dashboard (index.php)
    header("Location: ./");
    exit();
}
?>