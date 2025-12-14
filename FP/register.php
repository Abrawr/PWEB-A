<?php
require 'koneksi.php';

$nonce = isset($nonce) ? $nonce : '';

define('MAX_USERS', 50); 
$message = "";

if (isset($_SESSION['user_id'])) {

    header("Location: dashboard.php");
    exit();
}

// --- QUOTA CHECK ---

$stmt_count = $conn->prepare("SELECT COUNT(*) as total FROM users");
$stmt_count->execute();
$res_count = $stmt_count->get_result();
$row_count = $res_count->fetch_assoc();
$total_users = $row_count['total'];
$stmt_count->close();

$is_registration_open = ($total_users < MAX_USERS);

// --- REQUEST HANDLING ---
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    if (!$is_registration_open) {
        $message = "<div class='alert alert-danger'>Maaf, kuota pendaftaran sudah penuh!</div>";
    } else {
        $username = trim($_POST['username']);
        $password = trim($_POST['password']);
        $confirm_password = trim($_POST['confirm_password']);

        // --- INPUT VALIDATION ---
        if (!preg_match('/^[a-zA-Z0-9]{4,20}$/', $username)) {
            $message = "<div class='alert alert-warning'>Username harus alfanumerik (4-20 karakter).</div>";
        } 
        elseif ($password !== $confirm_password) {
             $message = "<div class='alert alert-danger'>Konfirmasi password tidak cocok!</div>";
        }
        elseif (strlen($password) < 8 || !preg_match('/[A-Za-z]/', $password) || !preg_match('/[0-9]/', $password)) {
            $message = "<div class='alert alert-warning'>Password minimal 8 karakter (Huruf + Angka).</div>";
        } else {
            // --- RECAPTCHA VALIDATION ---
            $recaptcha_secret = "YOUR_RECAPTCHA_SECRET_HERE";
            $recaptcha_response = $_POST['g-recaptcha-response'] ?? '';
            $verify_url = "https://www.google.com/recaptcha/api/siteverify";
            
            $data = [
                'secret' => $recaptcha_secret,
                'response' => $recaptcha_response,
                'remoteip' => $_SERVER['REMOTE_ADDR']
            ];
            $options = [
                'http' => [
                    'header'  => "Content-type: application/x-www-form-urlencoded\r\n",
                    'method'  => 'POST',
                    'content' => http_build_query($data)
                ]
            ];
            $context  = stream_context_create($options);
            $result = file_get_contents($verify_url, false, $context);
            $json = json_decode($result, true);

            if ($json['success'] !== true) {
                $message = "<div class='alert alert-danger'>Verifikasi reCAPTCHA Gagal! Harap centang saya bukan robot.</div>";
            } else {
                $stmt_check = $conn->prepare("SELECT id FROM users WHERE username = ?");
                $stmt_check->bind_param("s", $username);
                $stmt_check->execute();
                $stmt_check->store_result();
    
                if ($stmt_check->num_rows > 0) {
                    $message = "<div class='alert alert-danger'>Username sudah terdaftar!</div>";
                } else {
                    $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                    $stmt_insert = $conn->prepare("INSERT INTO users (username, password) VALUES (?, ?)");
                    $stmt_insert->bind_param("ss", $username, $hashed_password);
                    
                    if ($stmt_insert->execute()) {
                        $message = "<div class='alert alert-success'>Pendaftaran sukses! <a href='login.php' class='fw-bold'>Login</a>.</div>";
                        $total_users++; 
                        if($total_users >= MAX_USERS) $is_registration_open = false;
                    } else {
                        $message = "<div class='alert alert-danger'>Gagal mendaftar.</div>";
                    }
                    $stmt_insert->close();
                }
                $stmt_check->close();
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Daftar Akun</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
</head>
<body class="bg-body-tertiary">
    <!-- Navbar untuk kembali ke Home/Landing -->
    <nav class="fixed-top p-3">
        <a href="index.php" class="text-decoration-none text-secondary fw-bold">
            <i class="bi bi-arrow-left"></i> Home
        </a>
    </nav>

    <div class="container d-flex justify-content-center align-items-center vh-100">
        <div class="card border-0 shadow-lg" style="width: 100%; max-width: 400px;">
            <div class="card-body p-4">
                
                <div class="text-center mb-3">
                    <i class="bi bi-person-plus-fill text-primary display-4"></i>
                    <h3 class="fw-bold mt-2">Buat Akun</h3>
                    
                    <?php if ($is_registration_open): ?>
                        <span class="badge bg-success-subtle text-success rounded-pill">Pendaftaran Dibuka</span>
                    <?php else: ?>
                        <span class="badge bg-danger-subtle text-danger rounded-pill">Pendaftaran Ditutup</span>
                    <?php endif; ?>
                </div>

                <?= $message ?>

                <?php if ($is_registration_open): ?>
                    <form method="POST" action="">
                        <div class="mb-2">
                            <label class="form-label small fw-bold">Username</label>
                            <input type="text" name="username" class="form-control form-control-sm" placeholder="Huruf & Angka saja" required>
                        </div>
                        
                        <!-- Password Utama -->
                        <div class="mb-2">
                            <label class="form-label small fw-bold">Password</label>
                            <div class="input-group input-group-sm">
                                <input type="password" name="password" id="regPassword" class="form-control border-end-0" placeholder="Min 8 char" required>
                                <button class="btn btn-outline-secondary border-start-0" type="button" id="btnRegPass" style="z-index: 100;">
                                    <i class="bi bi-eye" id="iconRegPass"></i>
                                </button>
                            </div>
                        </div>

                        <!-- Konfirmasi Password -->
                        <div class="mb-3">
                            <label class="form-label small fw-bold">Ulangi Password</label>
                            <div class="input-group input-group-sm">
                                <input type="password" name="confirm_password" id="confPassword" class="form-control border-end-0" placeholder="Ketik ulang password" required>
                                <button class="btn btn-outline-secondary border-start-0" type="button" id="btnConfPass" style="z-index: 100;">
                                    <i class="bi bi-eye" id="iconConfPass"></i>
                                </button>
                            </div>
                        </div>

                        <!-- reCAPTCHA Widget (Scaled down) -->
                        <div class="mb-3 d-flex justify-content-center" style="transform:scale(0.85); transform-origin:0 0;">
                            <div class="g-recaptcha" data-sitekey="6LebMicsAAAAAH4UUPnWrBEYXH-lpEsGmKxdMzXn"></div>
                        </div>

                        <button type="submit" class="btn btn-primary w-100 py-2 fw-bold rounded-pill shadow-sm">Daftar Sekarang</button>
                    </form>
                <?php else: ?>
                    <div class="alert alert-secondary text-center py-4" role="alert">
                        <i class="bi bi-lock-fill fs-1 d-block mb-3"></i>
                        Maaf, sistem tidak menerima pendaftaran baru saat ini.
                    </div>
                    <a href="login.php" class="btn btn-outline-primary w-100 rounded-pill">Kembali ke Login</a>
                <?php endif; ?>

                <?php if ($is_registration_open): ?>
                <div class="text-center mt-3">
                    <small class="text-secondary">Sudah punya akun? <a href="login.php" class="text-decoration-none fw-bold">Login</a></small>
                </div>
                <?php endif; ?>

            </div>
        </div>
    </div>

    <script src="https://www.google.com/recaptcha/api.js" async defer></script>
    <script nonce="<?= $nonce ?>">
        document.addEventListener('DOMContentLoaded', function() {
            function setupToggle(btnId, inputId, iconId) {
                const btn = document.getElementById(btnId);
                const input = document.getElementById(inputId);
                const icon = document.getElementById(iconId);

                if (btn && input && icon) {
                    btn.addEventListener('click', function () {
                        const type = input.getAttribute('type') === 'password' ? 'text' : 'password';
                        input.setAttribute('type', type);
                        icon.classList.toggle('bi-eye');
                        icon.classList.toggle('bi-eye-slash');
                    });
                }
            }
            setupToggle('btnRegPass', 'regPassword', 'iconRegPass');
            setupToggle('btnConfPass', 'confPassword', 'iconConfPass');
        });
    </script>
</body>
</html>