<?php
require 'koneksi.php';

$nonce = isset($nonce) ? $nonce : '';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$user_id = $_SESSION['user_id'];
$username = $_SESSION['username'];
$csrf_token = $_SESSION['csrf_token'];
$msg = "";

// --- FETCH USER DATA ---
$stmt = $conn->prepare("SELECT photo FROM users WHERE id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$res = $stmt->get_result();
$userData = $res->fetch_assoc();
$current_photo = $userData['photo'] ?? 'default.png';
$stmt->close();

$isAdmin = ($username === 'admin');

// --- HANDLE PHOTO UPLOAD ---
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_FILES['photo_file'])) {
    verify_csrf();
    if (!$isAdmin) {
        $msg = "<div class='alert alert-danger'>Hanya Admin yang boleh mengubah foto!</div>";
    } else {
        $file = $_FILES['photo_file'];
        if ($file['error'] !== UPLOAD_ERR_OK) {
            $msg = "<div class='alert alert-danger'>Terjadi error saat upload.</div>";
        } elseif ($file['size'] > 2 * 1024 * 1024) {
            $msg = "<div class='alert alert-danger'>Ukuran file maksimal 2MB.</div>";
        } else {
            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $mimeType = $finfo->file($file['tmp_name']);
            $allowedMimes = ['image/jpeg' => 'jpg', 'image/png'  => 'png', 'image/gif'  => 'gif'];

            if (!array_key_exists($mimeType, $allowedMimes)) {
                $msg = "<div class='alert alert-danger'>File harus berupa gambar (JPG, PNG, GIF).</div>";
            } else {
                $ext = $allowedMimes[$mimeType];
                $randomName = bin2hex(random_bytes(16)) . '.' . $ext;
                $uploadDir = 'uploads/';
                if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);

                if (move_uploaded_file($file['tmp_name'], $uploadDir . $randomName)) {
                    if ($current_photo != 'default.png' && file_exists($uploadDir . $current_photo)) {
                        unlink($uploadDir . $current_photo);
                    }
                    $stmt = $conn->prepare("UPDATE users SET photo = ? WHERE id = ?");
                    $stmt->bind_param("si", $randomName, $user_id);
                    if ($stmt->execute()) {
                        $msg = "<div class='alert alert-success'>Foto profil berhasil diperbarui!</div>";
                        $current_photo = $randomName;
                    } else {
                        $msg = "<div class='alert alert-danger'>Gagal update database.</div>";
                    }
                    $stmt->close();
                } else {
                    $msg = "<div class='alert alert-danger'>Gagal memindahkan file.</div>";
                }
            }
        }
    }
}

// --- HANDLE PASSWORD UPDATE ---
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_password'])) {
    verify_csrf();
    $pass_baru = trim($_POST['new_password']);
    $pass_konfirm = trim($_POST['confirm_password']);
    
    if ($pass_baru !== $pass_konfirm) {
        $msg = "<div class='alert alert-danger'>Konfirmasi password tidak cocok!</div>";
    } elseif (strlen($pass_baru) >= 6) {
        $hash = password_hash($pass_baru, PASSWORD_DEFAULT);
        $stmt = $conn->prepare("UPDATE users SET password = ? WHERE id = ?");
        $stmt->bind_param("si", $hash, $user_id);
        if ($stmt->execute()) {
            $msg = "<div class='alert alert-success'>Password berhasil diubah!</div>";
        } else {
            $msg = "<div class='alert alert-danger'>Gagal mengubah password.</div>";
        }
        $stmt->close();
    } else {
        $msg = "<div class='alert alert-warning'>Password minimal 6 karakter.</div>";
    }
}

// --- HANDLE ACCOUNT DELETION ---
if (isset($_POST['delete_account'])) {
    verify_csrf();
    
    // Server-side reCAPTCHA Verification
    $recaptcha_secret = "";
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

    if ($json['success'] === true) {
        $stmt = $conn->prepare("DELETE FROM users WHERE id = ?");
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        session_destroy();
        header("Location: login.php");
        exit();
    } else {
        $msg = "<div class='alert alert-danger'>Verifikasi reCAPTCHA Gagal! Silakan coba lagi.</div>";
    }
}
?>

<!DOCTYPE html>
<html lang="id" data-bs-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pengaturan Akun</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
    
    <script nonce="<?= $nonce ?>">
        const savedTheme = localStorage.getItem('theme') || 'light';
        document.documentElement.setAttribute('data-bs-theme', savedTheme);
    </script>
</head>
<body class="bg-body-tertiary">

    <nav class="navbar bg-body shadow-sm mb-4">
        <div class="container">
            <a class="navbar-brand fw-bold" href="dashboard.php"><i class="bi bi-arrow-left"></i> Kembali</a>
            <span class="navbar-text">Pengaturan</span>
        </div>
    </nav>

    <div class="container" style="max-width: 700px;">
        <h3 class="mb-4 fw-bold">Profil & Preferensi</h3>
        <?= $msg ?>

        <div class="card border-0 shadow-sm mb-4">
            <div class="card-body p-4 text-center">
                <div class="mx-auto mb-3" style="width: 100px; height: 100px;">
                    <?php if ($current_photo == 'default.png'): ?>
                        <div class="rounded-circle bg-primary text-white d-flex align-items-center justify-content-center fs-1 w-100 h-100">
                            <?= strtoupper(substr($username, 0, 2)) ?>
                        </div>
                    <?php else: ?>
                        <img src="uploads/<?= htmlspecialchars($current_photo) ?>" class="rounded-circle w-100 h-100 object-fit-cover shadow-sm" alt="Profile">
                    <?php endif; ?>
                </div>
                
                <h5 class="fw-bold"><?= htmlspecialchars($username) ?></h5>
                <p class="text-secondary small mb-3"><?= $isAdmin ? 'Administrator' : 'Pengguna Biasa' ?></p>

                <?php if ($isAdmin): ?>
                    <button class="btn btn-outline-primary btn-sm rounded-pill" id="btnTriggerUpload">
                        <i class="bi bi-camera"></i> Ubah Foto
                    </button>
                    <form method="POST" enctype="multipart/form-data" id="formUpload" class="d-none">
                        <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                        <input type="file" name="photo_file" id="fileInput" accept="image/png, image/jpeg, image/gif">
                    </form>
                <?php else: ?>
                    <button class="btn btn-secondary btn-sm rounded-pill" disabled>
                        <i class="bi bi-lock"></i> Ubah Foto (Locked)
                    </button>
                <?php endif; ?>
            </div>
        </div>

        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-body fw-bold py-3">Tampilan Aplikasi</div>
            <div class="card-body p-4">
                <div class="form-check mb-3">
                    <input class="form-check-input" type="radio" name="theme" id="themeLight" value="light">
                    <label class="form-check-label d-flex align-items-center gap-2" for="themeLight">
                        <i class="bi bi-sun-fill text-warning"></i> Mode Terang (Light)
                    </label>
                </div>
                <div class="form-check">
                    <input class="form-check-input" type="radio" name="theme" id="themeDark" value="dark">
                    <label class="form-check-label d-flex align-items-center gap-2" for="themeDark">
                        <i class="bi bi-moon-stars-fill text-primary"></i> Mode Gelap (Dark)
                    </label>
                </div>
            </div>
        </div>

        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-body fw-bold py-3">Keamanan</div>
            <div class="card-body p-4">
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                    <div class="mb-3">
                        <label class="form-label">Password Baru</label>
                        <div class="input-group">
                            <input type="password" name="new_password" id="newPass" class="form-control" required>
                            <button class="btn btn-outline-secondary btn-toggle-pass" type="button" data-target="newPass">
                                <i class="bi bi-eye"></i>
                            </button>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Konfirmasi Password</label>
                        <div class="input-group">
                            <input type="password" name="confirm_password" id="confirmPass" class="form-control" required>
                            <button class="btn btn-outline-secondary btn-toggle-pass" type="button" data-target="confirmPass">
                                <i class="bi bi-eye"></i>
                            </button>
                        </div>
                    </div>
                    <button type="submit" name="update_password" class="btn btn-primary">Simpan Password</button>
                </form>
            </div>
        </div>

        <div class="card border-danger shadow-sm mb-5">
            <div class="card-body p-4 d-flex justify-content-between align-items-center">
                <div>
                    <h6 class="text-danger fw-bold mb-1">Hapus Akun</h6>
                    <small class="text-secondary">Permanen dan tidak bisa dibatalkan.</small>
                </div>
                <form method="POST" id="formDeleteAccount">
                    <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                    <!-- Hidden input to hold recaptcha token if needed manually, though standard post works -->
                    <input type="hidden" name="delete_account" value="1">
                    <button type="submit" class="btn btn-danger btn-sm">Hapus</button>
                </form>
            </div>
        </div>
    </div>

    </div>

    <!-- CONFIRMATION MODAL -->
    <div class="modal fade" id="confirmModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content border-danger shadow-lg">
                <div class="modal-header bg-danger-subtle border-danger text-danger">
                    <h5 class="modal-title fw-bold"><i class="bi bi-exclamation-triangle-fill me-2"></i>Konfirmasi Penghapusan</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4 text-center">
                    <h4 class="fw-bold text-danger mb-3">Apakah Anda Yakin?</h4>
                    <p class="mb-4">Akun Anda akan dihapus secara <strong>PERMANEN</strong>. Semua data tugas dan kategori akan hilang dan tidak dapat dikembalikan.</p>
                    <div class="d-flex justify-content-center gap-2">
                        <button type="button" class="btn btn-secondary px-4" data-bs-dismiss="modal">Batal</button>
                        <button type="button" class="btn btn-danger px-4" id="btnProccedToPuzzle">Ya, Lanjut Hapus</button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- PUZZLE MODAL -->
    <div class="modal fade" id="puzzleModal" data-bs-backdrop="static" data-bs-keyboard="false" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content border-0 shadow-lg">
                <div class="modal-header border-0 bg-danger text-white">
                    <h5 class="modal-title fw-bold"><i class="bi bi-person-fill-lock me-2"></i>Verifikasi Penghapusan</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4 text-center">
                    <p class="text-secondary mb-4">Untuk melanjutkan penghapusan akun, silakan verifikasi keamanan:</p>
                    
                    <div class="d-flex justify-content-center mb-4">
                        <div class="g-recaptcha" data-sitekey="6LebMicsAAAAAH4UUPnWrBEYXH-lpEsGmKxdMzXn" data-callback="onRecaptchaSuccess"></div>
                    </div>

                    <div id="captchaError" class="alert alert-danger d-none">Silakan centang verifikasi di atas.</div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://www.google.com/recaptcha/api.js" async defer></script>
    <script nonce="<?= $nonce ?>">
        document.addEventListener('DOMContentLoaded', () => {
            const currentTheme = localStorage.getItem('theme') || 'light';
            if (currentTheme === 'dark') {
                document.getElementById('themeDark').checked = true;
            } else {
                document.getElementById('themeLight').checked = true;
            }

            document.querySelectorAll('input[name="theme"]').forEach(radio => {
                radio.addEventListener('change', (e) => {
                    const selectedTheme = e.target.value;
                    document.documentElement.setAttribute('data-bs-theme', selectedTheme);
                    localStorage.setItem('theme', selectedTheme);
                });
            });

            const formDelete = document.getElementById('formDeleteAccount');
            const confirmModal = new bootstrap.Modal(document.getElementById('confirmModal'));
            const btnProcced = document.getElementById('btnProccedToPuzzle');
            const puzzleModal = new bootstrap.Modal(document.getElementById('puzzleModal'));
            const puzzleLoading = document.getElementById('puzzleLoading');
            const puzzleContent = document.getElementById('puzzleContent');
            const questionEl = document.getElementById('puzzleQuestion');
            const optionsEl = document.getElementById('puzzleOptions');
            let isVerified = false;

            formDelete.addEventListener('submit', function(e) {


                e.preventDefault();
                // Show Custom Confirmation Modal
                confirmModal.show();
            });

            btnProcced.addEventListener('click', () => {
                confirmModal.hide();
                puzzleModal.show();
            });

            // Callback for reCAPTCHA
            window.onRecaptchaSuccess = function(token) {
                setTimeout(() => {
                    window.isVerified = true; // Flag to allow submit
                    
                    
                    let hiddenInput = document.createElement('input');
                    hiddenInput.type = 'hidden';
                    hiddenInput.name = 'g-recaptcha-response';
                    hiddenInput.value = token;
                    formDelete.appendChild(hiddenInput);
                    
                    puzzleModal.hide();
                    formDelete.submit();
                }, 500);
            };

            const btnUpload = document.getElementById('btnTriggerUpload');
            const fileInput = document.getElementById('fileInput');
            const formUpload = document.getElementById('formUpload');

            if (btnUpload && fileInput) {
                btnUpload.addEventListener('click', () => fileInput.click());
                fileInput.addEventListener('change', () => {
                    if (fileInput.files.length > 0) formUpload.submit();
                });
            }

            // Toggle Password Visibility
            document.querySelectorAll('.btn-toggle-pass').forEach(btn => {
                btn.addEventListener('click', function() {
                    const targetId = this.getAttribute('data-target');
                    const input = document.getElementById(targetId);
                    const icon = this.querySelector('i');
                    
                    if (input.type === 'password') {
                        input.type = 'text';
                        icon.classList.remove('bi-eye');
                        icon.classList.add('bi-eye-slash');
                    } else {
                        input.type = 'password';
                        icon.classList.remove('bi-eye-slash');
                        icon.classList.add('bi-eye');
                    }
                });
            });
        });
    </script>
</body>

</html>
