<?php
require 'koneksi.php';


if (isset($_SESSION['user_id'])) {
    header("Location: dashboard.php");
    exit();
}


$nonce = isset($nonce) ? $nonce : '';
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Task Manager - Kelola Hidup Anda</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
    <style nonce="<?= $nonce ?>">
        .hero-section {
            background: linear-gradient(135deg, #0d6efd 0%, #0a58ca 100%);
            color: white;
            padding: 100px 0;
            border-bottom-left-radius: 50px;
            border-bottom-right-radius: 50px;
        }
        .feature-icon {
            width: 60px;
            height: 60px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
            background-color: rgba(13, 110, 253, 0.1);
            color: #0d6efd;
            font-size: 1.5rem;
            margin-bottom: 1rem;
        }
    </style>
</head>
<body class="bg-light">

    <!-- Navbar Sederhana -->
    <nav class="navbar navbar-expand-lg navbar-light bg-white shadow-sm fixed-top">
        <div class="container">
            <a class="navbar-brand fw-bold text-primary" href="#">
                <i class="bi bi-check2-square me-2"></i>Task Manager
            </a>
            <div class="d-flex gap-2">
                <a href="login.php" class="btn btn-outline-primary rounded-pill px-4">Masuk</a>
                <a href="register.php" class="btn btn-primary rounded-pill px-4">Daftar</a>
            </div>
        </div>
    </nav>

    <!-- Hero Section -->
    <section class="hero-section text-center position-relative mt-5">
        <div class="container">
            <div class="row justify-content-center">
                <div class="col-lg-8">
                    <h1 class="display-4 fw-bold mb-4">Atur Tugas, Tingkatkan Produktivitas</h1>
                    <p class="lead mb-5 opacity-75">
                        Aplikasi manajemen tugas sederhana namun powerful untuk membantu Anda menyelesaikan pekerjaan tepat waktu, di mana saja, kapan saja.
                    </p>
                    <div class="d-flex justify-content-center gap-3">
                        <a href="register.php" class="btn btn-light btn-lg text-primary fw-bold rounded-pill px-5 shadow">Mulai Sekarang</a>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Features Section -->
    <section class="py-5">
        <div class="container py-5">
            <div class="row g-4">
                <div class="col-md-4">
                    <div class="card border-0 shadow-sm h-100 p-4">
                        <div class="card-body">
                            <div class="feature-icon">
                                <i class="bi bi-list-check"></i>
                            </div>
                            <h4 class="fw-bold mb-3">Manajemen Tugas</h4>
                            <p class="text-secondary">Catat, edit, dan tandai tugas Anda. Kelompokkan berdasarkan kategori prioritas seperti Pekerjaan atau Pribadi.</p>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="card border-0 shadow-sm h-100 p-4">
                        <div class="card-body">
                            <div class="feature-icon">
                                <i class="bi bi-shield-check"></i>
                            </div>
                            <h4 class="fw-bold mb-3">Keamanan Data</h4>
                            <p class="text-secondary">Data Anda aman bersama kami. Dilengkapi dengan proteksi CSRF, XSS, dan enkripsi password modern.</p>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="card border-0 shadow-sm h-100 p-4">
                        <div class="card-body">
                            <div class="feature-icon">
                                <i class="bi bi-phone"></i>
                            </div>
                            <h4 class="fw-bold mb-3">Responsif</h4>
                            <p class="text-secondary">Akses tugas Anda dari perangkat apa saja. Tampilan menyesuaikan layar Desktop maupun Smartphone.</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Footer -->
    <footer class="bg-white py-4 border-top text-center">
        <div class="container">
            <p class="text-secondary mb-0 small">&copy; <?= date('Y') ?> Task Manager App. All rights reserved.</p>
        </div>
    </footer>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>