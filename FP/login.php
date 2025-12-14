<?php
require 'koneksi.php';


$nonce = isset($nonce) ? $nonce : '';

if (isset($_SESSION['user_id'])) {

    header("Location: dashboard.php");
    exit();
}

$message = "";

// --- ANTI-BRUTE FORCE ---
if (isset($_SESSION['lockout_time']) && time() < $_SESSION['lockout_time']) {
    $sisa_waktu = ceil(($_SESSION['lockout_time'] - time()) / 60);
    $message = "<div class='alert alert-danger'>Terlalu banyak percobaan gagal. Silakan tunggu $sisa_waktu menit.</div>";
    $locked = true; 
} else {
    if (isset($_SESSION['lockout_time']) && time() > $_SESSION['lockout_time']) {
        unset($_SESSION['failed_attempts']);
        unset($_SESSION['lockout_time']);
    }
    $locked = false;
}

if ($_SERVER["REQUEST_METHOD"] == "POST" && !$locked) {
    $username = trim($_POST['username']);
    $password = trim($_POST['password']);


    usleep(500000); 

    $stmt = $conn->prepare("SELECT id, username, password FROM users WHERE username = ?");
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 1) {
        $user = $result->fetch_assoc();
        
        if (password_verify($password, $user['password'])) {
            unset($_SESSION['failed_attempts']);
            unset($_SESSION['lockout_time']);
            
            session_regenerate_id(true); 
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['username'] = $user['username'];

            header("Location: dashboard.php");
            exit();
        } else {
            handle_failed_login();
            $message = "<div class='alert alert-danger'>Password salah!</div>";
        }
    } else {
        handle_failed_login();
        $message = "<div class='alert alert-danger'>Login gagal!</div>";
    }
    $stmt->close();
}

function handle_failed_login() {
    if (!isset($_SESSION['failed_attempts'])) {
        $_SESSION['failed_attempts'] = 0;
    }
    $_SESSION['failed_attempts']++;

    if ($_SESSION['failed_attempts'] >= 3) sleep(3);
    if ($_SESSION['failed_attempts'] >= 5) $_SESSION['lockout_time'] = time() + (15 * 60);
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login Task Manager</title>
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
            <div class="card-body p-5">
                <div class="text-center mb-4">
                    <i class="bi bi-shield-lock-fill text-primary display-1"></i>
                    <h2 class="fw-bold mt-3">Login Aman</h2>
                </div>
                <?= $message ?>
                
                <?php if(!$locked): ?>
                <form method="POST" action="">
                    <div class="mb-3">
                        <label class="form-label">Username</label>
                        <input type="text" name="username" class="form-control" required>
                    </div>
                    <div class="mb-4">
                        <label class="form-label">Password</label>
                        <div class="input-group">
                            <input type="password" name="password" id="password" class="form-control border-end-0" required>
                            <button class="btn btn-outline-secondary border-start-0" type="button" id="togglePassword" style="z-index: 100;">
                                <i class="bi bi-eye" id="iconEye"></i>
                            </button>
                        </div>
                    </div>
                    <button type="submit" class="btn btn-primary w-100 py-2 fw-bold rounded-pill">Masuk</button>
                </form>
                <?php else: ?>
                    <div class="alert alert-warning text-center">Form terkunci demi keamanan.</div>
                <?php endif; ?>

                <div class="text-center mt-4">
                    <small class="text-secondary">Belum punya akun? <a href="register.php" class="text-decoration-none fw-bold">Daftar</a></small>
                </div>
            </div>
        </div>
    </div>

    <script nonce="<?= $nonce ?>">
        document.addEventListener('DOMContentLoaded', function() {
            const togglePassword = document.getElementById('togglePassword');
            const passwordInput = document.getElementById('password');
            const icon = document.getElementById('iconEye');

            if (togglePassword && passwordInput && icon) {
                togglePassword.addEventListener('click', function () {
                    const type = passwordInput.getAttribute('type') === 'password' ? 'text' : 'password';
                    passwordInput.setAttribute('type', type);
                    icon.classList.toggle('bi-eye');
                    icon.classList.toggle('bi-eye-slash');
                });
            }
        });
    </script>
</body>
</html>