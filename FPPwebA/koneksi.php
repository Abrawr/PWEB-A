<?php
// --- ERROR REPORTING ---
ini_set('display_errors', 0);

$host = "localhost";
$user = ""; 
$pass = "";         
$db   = "";

$conn = mysqli_connect($host, $user, $pass, $db);

if (!$conn) {
    die("Maaf, terjadi gangguan koneksi ke server.");
}

// --- HSTS ---
header("Strict-Transport-Security: max-age=31536000; includeSubDomains");

// --- CSP & SECURITY HEADERS ---
$nonce = base64_encode(random_bytes(16));
header("Content-Security-Policy: default-src 'self'; script-src 'self' https://cdn.jsdelivr.net https://www.google.com https://www.gstatic.com 'nonce-{$nonce}'; style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net; font-src https://cdn.jsdelivr.net https://fonts.gstatic.com; connect-src 'self' https://dummyjson.com https://opentdb.com https://marcconrad.com; img-src 'self' data: https://marcconrad.com https://wsrv.nl; frame-src https://www.google.com https://www.gstatic.com; object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'none';");
header("X-Frame-Options: DENY");
header("X-XSS-Protection: 1; mode=block");
header("X-Content-Type-Options: nosniff");
header("Referrer-Policy: strict-origin-when-cross-origin");
header("Permissions-Policy: geolocation=(), camera=(), microphone=()");

// --- SECURE SESSION ---
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'domain' => '',
    'secure' => true,
    'httponly' => true,
    'samesite' => 'Strict'
]);

session_start();

// --- CSRF PROTECTION ---
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}


function verify_csrf() {
    $token_input = ($_SERVER['REQUEST_METHOD'] === 'POST') ? ($_POST['csrf_token'] ?? '') : ($_GET['csrf_token'] ?? '');
    if (!hash_equals($_SESSION['csrf_token'], $token_input)) {
        http_response_code(403);
        die("Akses Ditolak: Invalid CSRF Token");
    }
}
?>