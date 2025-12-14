<?php
require 'koneksi.php';


date_default_timezone_set('Asia/Jakarta');


$nonce = isset($nonce) ? $nonce : '';

// --- CHECK LOGIN ---
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$user_id = $_SESSION['user_id'];
$username_display = $_SESSION['username'];
$csrf_token = $_SESSION['csrf_token'];

// --- VIEW CONTROLLER ---
$current_view = isset($_GET['view']) ? $_GET['view'] : 'active';

// --- FETCH USER PHOTO ---
$stmt_photo = $conn->prepare("SELECT photo FROM users WHERE id = ?");
$stmt_photo->bind_param("i", $user_id);
$stmt_photo->execute();
$res_photo = $stmt_photo->get_result();
$data_photo = $res_photo->fetch_assoc();
$user_photo = $data_photo['photo'] ?? 'default.png';
$stmt_photo->close();

// --- FETCH CALENDAR DATA ---
$stmt_cal = $conn->prepare("SELECT title, deadline, status FROM tasks WHERE user_id = ? AND status != 'done' AND deadline IS NOT NULL");
$stmt_cal->bind_param("i", $user_id);
$stmt_cal->execute();
$res_cal = $stmt_cal->get_result();
$calendar_tasks = [];
while($row_cal = $res_cal->fetch_assoc()) {
    $calendar_tasks[] = [
        'date' => date('Y-m-d', strtotime($row_cal['deadline'])),
        'title' => $row_cal['title'],
        'time' => date('H:i', strtotime($row_cal['deadline']))
    ];
}
$json_tasks = json_encode($calendar_tasks);
$stmt_cal->close();

// --- AUTO MIGRATION ---
$check_table = $conn->query("SHOW TABLES LIKE 'categories'");
if ($check_table->num_rows == 0) {
    // 1. Create Table
    $conn->query("CREATE TABLE categories (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        name VARCHAR(50) NOT NULL,
        color VARCHAR(20) DEFAULT '#0d6efd',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY unique_cat (user_id, name),
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // 2. Migrate Existing Categories from Tasks
    $mig_stmt = $conn->prepare("SELECT DISTINCT category FROM tasks WHERE user_id = ?");
    $mig_stmt->bind_param("i", $user_id);
    $mig_stmt->execute();
    $mig_res = $mig_stmt->get_result();
    
    $presets = [
        'pribadi' => '#0d6efd',   // Blue
        'pekerjaan' => '#6610f2', // Indigo
        'keluarga' => '#198754',  // Green
        'keuangan' => '#ffc107',  // Yellow
        'rencana' => '#0dcaf0'    // Cyan
    ];
    
    while($row = $mig_res->fetch_assoc()) {
        $cat_name = strtolower($row['category']);

        $cat_color = isset($presets[$cat_name]) ? $presets[$cat_name] : '#' . substr(md5($cat_name), 0, 6);
        
        $ins = $conn->prepare("INSERT IGNORE INTO categories (user_id, name, color) VALUES (?, ?, ?)");
        $ins->bind_param("iss", $user_id, $cat_name, $cat_color);
        $ins->execute();
    }
}

// --- CRUD LOGIC ---
if (isset($_POST['add_category'])) {
    verify_csrf();
    $cat_name = strtolower(htmlspecialchars($_POST['cat_name']));
    $cat_color = htmlspecialchars($_POST['cat_color']);
    $cat_desc = htmlspecialchars($_POST['cat_desc']);
    
    $stmt = $conn->prepare("INSERT INTO categories (user_id, name, color, description) VALUES (?, ?, ?, ?)");
    $stmt->bind_param("isss", $user_id, $cat_name, $cat_color, $cat_desc);
    if($stmt->execute()){

         header("Location: dashboard.php?view=" . $current_view);
         exit();
    }
    $stmt->close();
}

// --- HANDLE DELETE CATEGORY ---
if (isset($_POST['delete_category'])) {
    verify_csrf();
    $cat_id = $_POST['cat_id'];
    $cat_name = $_POST['cat_name'];
    

    $upd = $conn->prepare("UPDATE tasks SET category = 'uncategorized' WHERE user_id = ? AND category = ?");
    $upd->bind_param("is", $user_id, $cat_name);
    $upd->execute();
    $upd->close();


    $del = $conn->prepare("DELETE FROM categories WHERE id = ? AND user_id = ?");
    $del->bind_param("ii", $cat_id, $user_id);
    $del->execute();
    $del->close();
    
    header("Location: dashboard.php?view=categories");
    exit();
}

// --- HANDLE EDIT CATEGORY ---
if (isset($_POST['edit_category'])) {
    verify_csrf();
    $cat_id = $_POST['cat_id'];
    $old_name = strtolower($_POST['old_name']);
    $new_name = strtolower(htmlspecialchars($_POST['cat_name']));
    $cat_color = htmlspecialchars($_POST['cat_color']);
    $cat_desc = htmlspecialchars($_POST['cat_desc']);


    $stmt = $conn->prepare("UPDATE categories SET name = ?, color = ?, description = ? WHERE id = ? AND user_id = ?");
    $stmt->bind_param("sssii", $new_name, $cat_color, $cat_desc, $cat_id, $user_id);
    
    if ($stmt->execute()) {
        if ($old_name !== $new_name && !empty($old_name)) {
            $upd_tasks = $conn->prepare("UPDATE tasks SET category = ? WHERE category = ? AND user_id = ?");
            $upd_tasks->bind_param("ssi", $new_name, $old_name, $user_id);
            $upd_tasks->execute();
            $upd_tasks->close();
        }
        header("Location: dashboard.php?view=categories");
        exit();
    }
    $stmt->close();
}

if (isset($_POST['add_task'])) {
    verify_csrf(); 
    $title = htmlspecialchars($_POST['title']);
    $category = strtolower(htmlspecialchars($_POST['category']));
    $deadline = !empty($_POST['deadline']) ? $_POST['deadline'] : NULL;


    $priority = htmlspecialchars($_POST['priority']);


    if (empty($category)) {
        $category = 'uncategorized';
    }



    if ($category !== 'uncategorized') {
        $check_cat = $conn->prepare("SELECT id FROM categories WHERE user_id = ? AND name = ?");
        $check_cat->bind_param("is", $user_id, $category);
        $check_cat->execute();
        if($check_cat->get_result()->num_rows == 0) {

            $new_color = '#' . substr(md5($category), 0, 6);
            $ins_cat = $conn->prepare("INSERT INTO categories (user_id, name, color) VALUES (?, ?, ?)");
            $ins_cat->bind_param("iss", $user_id, $category, $new_color);
            $ins_cat->execute();
        }
    }

    $stmt = $conn->prepare("INSERT INTO tasks (user_id, title, description, category, priority, status, deadline) VALUES (?, ?, ?, ?, ?, 'draft', ?)");

    $stmt->bind_param("isssss", $user_id, $title, $description, $category, $priority, $deadline);
    if($stmt->execute()){
         $new_task_id = $conn->insert_id;
         
         if (!empty($_POST['subtasks']) && is_array($_POST['subtasks'])) {
             $stmt_sub = $conn->prepare("INSERT INTO subtasks (task_id, title) VALUES (?, ?)");
             foreach ($_POST['subtasks'] as $sub_line) {
                 $sub_line = trim($sub_line);
                 if (!empty($sub_line)) {
                     $stmt_sub->bind_param("is", $new_task_id, $sub_line);
                     $stmt_sub->execute();
                 }
             }
             $stmt_sub->close();
         }

         header("Location: dashboard.php?view=" . $current_view);
         exit();
    }
    $stmt->close();
}

if (isset($_POST['edit_task'])) {
    verify_csrf();
    $id = $_POST['task_id']; 
    $title = htmlspecialchars($_POST['title']);
    $description = htmlspecialchars($_POST['description']);
    $category = strtolower(htmlspecialchars($_POST['category']));
    $priority = htmlspecialchars($_POST['priority']);
    $deadline = !empty($_POST['deadline']) ? $_POST['deadline'] : NULL;


    if (empty($category)) {
        $category = 'uncategorized';
    }


    if ($category !== 'uncategorized') {
        $check_cat = $conn->prepare("SELECT id FROM categories WHERE user_id = ? AND name = ?");
        $check_cat->bind_param("is", $user_id, $category);
        $check_cat->execute();
        if($check_cat->get_result()->num_rows == 0) {
            $new_color = '#' . substr(md5($category), 0, 6);
            $ins_cat = $conn->prepare("INSERT INTO categories (user_id, name, color) VALUES (?, ?, ?)");
            $ins_cat->bind_param("iss", $user_id, $category, $new_color);
            $ins_cat->execute();
        }
    }

    $stmt = $conn->prepare("UPDATE tasks SET title = ?, description = ?, category = ?, priority = ?, deadline = ? WHERE id = ? AND user_id = ?");
    $stmt->bind_param("sssssii", $title, $description, $category, $priority, $deadline, $id, $user_id);
    $stmt->execute();




    $stmt_del_sub = $conn->prepare("DELETE FROM subtasks WHERE task_id = ?");
    $stmt_del_sub->bind_param("i", $id);
    $stmt_del_sub->execute();
    $stmt_del_sub->close();

    if (!empty($_POST['subtasks']) && is_array($_POST['subtasks'])) {
        $stmt_sub = $conn->prepare("INSERT INTO subtasks (task_id, title) VALUES (?, ?)");
        foreach ($_POST['subtasks'] as $sub_line) {
            $sub_line = trim($sub_line);
            if (!empty($sub_line)) {
                $stmt_sub->bind_param("is", $id, $sub_line);
                $stmt_sub->execute();
            }
        }
        $stmt_sub->close();
    }

    $stmt->close();
    header("Location: dashboard.php?view=active");
    exit();
}

if (isset($_GET['complete_id'])) {
    verify_csrf(); 
    $id = $_GET['complete_id'];
    $stmt = $conn->prepare("UPDATE tasks SET status = 'done', completed_at = NOW() WHERE id = ? AND user_id = ?");
    $stmt->bind_param("ii", $id, $user_id);
    $stmt->execute();
    $stmt->close();
    header("Location: dashboard.php?view=active");
    exit();
}

if (isset($_GET['undo_id'])) {
    verify_csrf(); 
    $id = $_GET['undo_id'];
    $stmt = $conn->prepare("UPDATE tasks SET status = 'draft', completed_at = NULL WHERE id = ? AND user_id = ?");
    $stmt->bind_param("ii", $id, $user_id);
    $stmt->execute();
    $stmt->close();
    header("Location: dashboard.php?view=completed");
    exit();
}

if (isset($_GET['delete_id'])) {
    verify_csrf();
    $id = $_GET['delete_id'];
    $stmt = $conn->prepare("DELETE FROM tasks WHERE id = ? AND user_id = ?");
    $stmt->bind_param("ii", $id, $user_id);
    $stmt->execute();
    $stmt->close();
    $redirect_view = (isset($_GET['from_view'])) ? $_GET['from_view'] : 'active';
    header("Location: dashboard.php?view=" . $redirect_view);
    exit();
}

if (isset($_GET['toggle_subtask'])) {
    verify_csrf();
    $subtask_id = $_GET['toggle_subtask'];

    $stmt = $conn->prepare("UPDATE subtasks st JOIN tasks t ON st.task_id = t.id SET st.is_done = NOT st.is_done WHERE st.id = ? AND t.user_id = ?");
    $stmt->bind_param("ii", $subtask_id, $user_id);
    $stmt->execute();
    $stmt->close();
    header("Location: dashboard.php?view=" . $current_view);
    exit();
}

// --- FETCH MAIN DATA ---
// --- FETCH CATEGORIES ---

$cat_filter_sql = "";
$cat_types = "i";
$cat_params = [$user_id];

if ($current_view == 'categories' && !empty($_GET['q'])) {
    $cat_filter_sql = " AND c.name LIKE ?";
    $cat_types .= "s";
    $cat_search = "%" . $_GET['q'] . "%";
    $cat_params[] = $cat_search;
}

$sql_cats = "SELECT c.*, 
            COUNT(CASE WHEN t.status != 'done' THEN 1 END) as active_count,
            COUNT(CASE WHEN t.status = 'done' THEN 1 END) as done_count,
            COUNT(CASE WHEN t.status != 'done' AND t.deadline IS NOT NULL AND t.deadline < NOW() THEN 1 END) as overdue_count
             FROM categories c 
             LEFT JOIN tasks t ON c.name = t.category AND c.user_id = t.user_id
             WHERE c.user_id = ? $cat_filter_sql
             GROUP BY c.id";
$stmt_cats = $conn->prepare($sql_cats);
$stmt_cats->bind_param($cat_types, ...$cat_params);
$stmt_cats->execute();
$categories_res = $stmt_cats->get_result();
$categories_data = [];
while($c = $categories_res->fetch_assoc()) {
    $categories_data[] = $c;
}
$stmt_cats->close();

// --- FETCH TASKS ---
$sort_order = "ORDER BY (t.deadline IS NULL), t.deadline ASC, t.created_at DESC";
if (isset($_GET['sort'])) {
    if ($_GET['sort'] == 'deadline_asc') $sort_order = "ORDER BY (t.deadline IS NULL), t.deadline ASC";
    if ($_GET['sort'] == 'deadline_desc') $sort_order = "ORDER BY (t.deadline IS NULL), t.deadline DESC";
    if ($_GET['sort'] == 'created_asc') $sort_order = "ORDER BY t.created_at ASC";
    if ($_GET['sort'] == 'created_desc') $sort_order = "ORDER BY t.created_at DESC";
}

$filter_sql = "";
$types = "i";
$params = [$user_id];

// Search
if (!empty($_GET['q'])) {
    $filter_sql .= " AND (t.title LIKE ? OR t.description LIKE ?)";
    $search_term = "%" . $_GET['q'] . "%";
    $types .= "ss";
    $params[] = $search_term;
    $params[] = $search_term;
}

// Filter Category
if (!empty($_GET['filter_cat'])) {
    if ($_GET['filter_cat'] == 'uncategorized') {
         // Handle both explicit 'uncategorized' string AND NULL/Empty
        $filter_sql .= " AND (t.category = 'uncategorized' OR t.category IS NULL OR t.category = '')";
    } else {
        $filter_sql .= " AND t.category = ?";
        $types .= "s";
        $params[] = $_GET['filter_cat'];
    }
}

// Filter Priority
if (!empty($_GET['filter_prio'])) {
    $filter_sql .= " AND t.priority = ?";
    $types .= "s";
    $params[] = $_GET['filter_prio'];
}

if ($current_view == 'completed') {
    $sql_tasks = "SELECT t.*, c.color as cat_color 
                  FROM tasks t 
                  LEFT JOIN categories c ON t.category = c.name AND t.user_id = c.user_id
                  WHERE t.user_id = ? AND t.status = 'done' 
                  $filter_sql
                  ORDER BY t.completed_at DESC";
} else {
    $sql_tasks = "SELECT t.*, c.color as cat_color 
                  FROM tasks t 
                  LEFT JOIN categories c ON t.category = c.name AND t.user_id = c.user_id
                  WHERE t.user_id = ? AND t.status != 'done' 
                  $filter_sql
                  $sort_order";
}

$stmt = $conn->prepare($sql_tasks);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$tasks = $stmt->get_result();

function formatTime($datetime) {
    if (!$datetime) return '-';
    return date('d M, H:i', strtotime($datetime));
}
?>

<!DOCTYPE html>
<html lang="id" data-bs-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Task Dashboard</title>
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Bootstrap Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">

    <script nonce="<?= $nonce ?>">
        const savedTheme = localStorage.getItem('theme') || 'light';
        document.documentElement.setAttribute('data-bs-theme', savedTheme);
    </script>
    
    <style>

        .card { z-index: 1; }
    </style>
</head>
<body class="bg-body-tertiary">
    <div class="container-fluid overflow-hidden">
        <div class="row vh-100">
            
            <!-- 1. MAIN CONTENT (TENGAH) -->
            <div class="col-12 col-xl-9 d-flex flex-column h-100 overflow-auto p-4 bg-body position-relative">
                
                <!-- HEADER: Judul & Jam Digital (Trigger Kalender) -->
                <div class="d-flex align-items-center justify-content-between pb-3 mb-4 border-bottom position-relative" style="min-height: 80px;">
                    
                    <!-- Kiri: Judul Halaman -->
                    <div class="z-1"> 
                        <h1 class="h3 fw-bold mb-0">
                            <?= ($current_view == 'completed') ? 'Riwayat Selesai' : (($current_view == 'categories') ? 'Kelola Kategori' : 'Tugas Saya') ?>
                        </h1>
                        <p class="text-secondary small mb-0 d-none d-md-block">
                            <?= ($current_view == 'completed') ? 'Daftar pekerjaan selesai.' : (($current_view == 'categories') ? 'Kumpulan Kategori Anda.' : 'Tugas yang sedang berlangsung.') ?>
                        </p>
                    </div>

                    <!-- Tengah: Jam Digital & Tombol Kalender -->
                    <div class="position-absolute start-50 translate-middle-x z-2 text-center" style="top: 0;">
                        <button type="button" 
                                class="btn btn-light bg-body-tertiary border shadow-sm rounded-4 px-4 py-2 d-flex flex-column align-items-center"
                                data-bs-toggle="modal" 
                                data-bs-target="#calendarModal">
                            <span class="h4 fw-bold mb-0 font-monospace text-primary" id="digital-clock">00:00:00</span>
                            <!-- Tanggal -->
                            <span class="small text-secondary text-uppercase fw-bold" style="font-size: 0.7rem;" id="digital-date">MEMUAT...</span>
                        </button>
                    </div>
                    
                    <!-- Kanan: Tombol Tambah & Menu Mobile -->
                    <div class="d-flex align-items-center gap-2 z-1">
                        <?php if($current_view == 'active'): ?>
                        <button class="btn btn-primary rounded-pill px-3 shadow-sm fw-bold" data-bs-toggle="modal" data-bs-target="#addTaskModal">
                            <i class="bi bi-plus-lg"></i> <span class="d-none d-md-inline">Baru</span>
                        </button>
                        <?php elseif($current_view == 'categories'): ?>
                        <button class="btn btn-primary rounded-pill px-3 shadow-sm fw-bold" data-bs-toggle="modal" data-bs-target="#addCategoryModal">
                            <i class="bi bi-plus-lg"></i> <span class="d-none d-md-inline">Kategori</span>
                        </button>
                        <?php endif; ?>
                        
                        <!-- Mobile Menu Trigger -->
                        <a href="#offcanvasRight" data-bs-toggle="offcanvas" class="btn btn-outline-secondary d-xl-none border-0">
                            <i class="bi bi-list fs-3"></i>
                        </a>
                    </div>
                </div>

                <!-- LIST TUGAS -->
                <div class="row g-3">
                    <?php if ($current_view != 'categories'): ?>
                        <!-- --- TASK SEARCH & FILTER FORM --- -->
                        <div class="col-12 mb-3">
                            <form method="GET" id="taskSearchForm">
                                <input type="hidden" name="view" value="<?= $current_view == 'completed' ? 'completed' : 'active' ?>">
                                <div class="row g-2">
                                    <div class="col-md-5">
                                        <div class="input-group">
                                            <span class="input-group-text bg-body-tertiary border-end-0"><i class="bi bi-search"></i></span>
                                            <input type="text" name="q" class="form-control bg-body-tertiary border-start-0 ps-0" placeholder="Cari tugas..." value="<?= htmlspecialchars($_GET['q'] ?? '') ?>">
                                        </div>
                                    </div>
                                    <div class="col-6 col-md-2">
                                        <select name="sort" class="form-select bg-body-tertiary text-secondary small">
                                            <option value="deadline_asc" <?= ($_GET['sort'] ?? '') == 'deadline_asc' ? 'selected' : '' ?>>Tenggat Terdekat</option>
                                            <option value="deadline_desc" <?= ($_GET['sort'] ?? '') == 'deadline_desc' ? 'selected' : '' ?>>Tenggat Terjauh</option>
                                            <option value="created_asc" <?= ($_GET['sort'] ?? '') == 'created_asc' ? 'selected' : '' ?>>Paling Lama</option>
                                            <option value="created_desc" <?= ($_GET['sort'] ?? '') == 'created_desc' ? 'selected' : '' ?>>Paling Baru</option>
                                        </select>
                                    </div>
                                    <div class="col-6 col-md-2">
                                        <select name="filter_cat" class="form-select bg-body-tertiary text-secondary small">
                                            <option value="">Semua Kategori</option>
                                            <option value="uncategorized" <?= ($_GET['filter_cat'] ?? '') == 'uncategorized' ? 'selected' : '' ?>>Uncategorized</option>
                                            <?php 
                                            $filter_cats_res = $conn->query("SELECT * FROM categories WHERE user_id = $user_id ORDER BY name ASC");
                                            while($fc = $filter_cats_res->fetch_assoc()): 
                                            ?>
                                                <option value="<?= htmlspecialchars($fc['name']) ?>" <?= ($_GET['filter_cat'] ?? '') == $fc['name'] ? 'selected' : '' ?>>
                                                    <?= ucfirst(htmlspecialchars($fc['name'])) ?>
                                                </option>
                                            <?php endwhile; ?>
                                        </select>
                                    </div>
                                    <div class="col-6 col-md-2">
                                        <select name="filter_prio" class="form-select bg-body-tertiary text-secondary small">
                                            <option value="">Semua Prioritas</option>
                                            <option value="penting" <?= ($_GET['filter_prio'] ?? '') == 'penting' ? 'selected' : '' ?>>Penting (Red)</option>
                                            <option value="wajib" <?= ($_GET['filter_prio'] ?? '') == 'wajib' ? 'selected' : '' ?>>Wajib (Yellow)</option>
                                            <option value="opsional" <?= ($_GET['filter_prio'] ?? '') == 'opsional' ? 'selected' : '' ?>>Opsional (Green)</option>
                                        </select>
                                    </div>
                                    <?php if(!empty($_GET['q']) || !empty($_GET['filter_cat']) || !empty($_GET['filter_prio']) || !empty($_GET['sort'])): ?>
                                    <div class="col-6 col-md-1 d-grid">
                                        <a href="dashboard.php?view=<?= $current_view ?>" class="btn btn-outline-secondary btn-sm d-flex align-items-center justify-content-center" data-bs-toggle="tooltip" title="Reset Filter"><i class="bi bi-x-lg"></i></a>
                                    </div>
                                    <?php endif; ?>
                                </div>
                            </form>
                        </div>
                    <?php endif; ?>

                    <?php if ($current_view == 'categories'): ?>
                        <!-- Category Search Bar -->

                        <div class="col-12 mb-3">
                            <form method="GET">
                                <input type="hidden" name="view" value="categories">
                                <div class="row g-2">
                                    <div class="col-md-5">
                                        <div class="input-group">
                                            <span class="input-group-text bg-body-tertiary border-end-0"><i class="bi bi-search"></i></span>
                                            <input type="text" name="q" class="form-control bg-body-tertiary border-start-0 ps-0" placeholder="Cari kategori..." value="<?= htmlspecialchars($_GET['q'] ?? '') ?>">
                                            <?php if(!empty($_GET['q'])): ?>
                                                <a href="dashboard.php?view=categories" class="btn bg-body-tertiary border border-start-0 text-secondary" style="z-index: 5;" data-bs-toggle="tooltip" title="Hapus Pencarian">
                                                    <i class="bi bi-x-lg"></i>
                                                </a>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            </form>
                        </div>

                        <div class="row g-3">
                            <?php 
                            foreach($categories_data as $cat): 

                                $colClass = 'col-md-6';
                            ?>
                            <div class="<?= $colClass ?>">
                                <div class="card border-0 shadow-sm h-100 position-relative overflow-hidden">
                                    
                                    <div class="position-absolute top-0 start-0 w-100 h-100" 
                                         style="background-color: <?= $cat['color'] ?>; opacity: 0.1; z-index: 0;"></div>
                                    
                                    <div class="card-body position-relative z-1 d-flex flex-column justify-content-between p-4">
                                        <div class="d-flex justify-content-between align-items-start mb-3">
                                            <div>
                                                <h5 class="fw-bold mb-0 header-cat" style="color: <?= $cat['color'] ?>"><?= ucfirst(htmlspecialchars($cat['name'])) ?></h5>
                                                <?php if(!empty($cat['description'])): ?>
                                                    <small class="text-body-secondary d-block mt-1" style="font-size: 0.8rem; line-height: 1.2; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; word-break: break-word;"><?= htmlspecialchars($cat['description']) ?></small>
                                                <?php else: ?>
                                                    <small class="text-secondary opacity-50 d-block mt-1 fst-italic" style="font-size: 0.75rem;">Tidak ada deskripsi</small>
                                                <?php endif; ?>
                                            </div>

                                            <div class="d-flex align-items-center gap-2">
                                                <div class="rounded-circle shadow-sm" style="width: 24px; height: 24px; background-color: <?= $cat['color'] ?>;"></div>
                                                
                                                
                                                <button type="button" class="btn btn-icon btn-sm text-primary opacity-50 hover-opacity-100 p-0 ms-1" 
                                                        title="Ubah Kategori"
                                                        data-bs-toggle="modal" data-bs-target="#editCategoryModal"
                                                        data-id="<?= $cat['id'] ?>"
                                                        data-name="<?= htmlspecialchars($cat['name']) ?>"
                                                        data-color="<?= $cat['color'] ?>"
                                                        data-desc="<?= htmlspecialchars($cat['description']) ?>">
                                                    <i class="bi bi-pencil"></i>
                                                </button>


                                                <form method="POST" class="m-0 d-flex" onsubmit="return confirm('Hapus kategori <?= htmlspecialchars($cat['name']) ?>? \n<?= $cat['active_count'] ?> tugas aktif dan <?= $cat['done_count'] ?> tugas selesai akan menjadi Uncategorized.')">
                                                    <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                                                    <input type="hidden" name="cat_id" value="<?= $cat['id'] ?>">
                                                    <input type="hidden" name="cat_name" value="<?= htmlspecialchars($cat['name']) ?>">
                                                    <button type="submit" name="delete_category" class="btn btn-icon btn-sm text-danger opacity-50 hover-opacity-100 p-0 ms-1" style="width: 24px; height: 24px; display: flex; align-items: center; justify-content: center;" title="Hapus Kategori">
                                                        <i class="bi bi-trash"></i>
                                                    </button>
                                                </form>
                                            </div>
                                        </div>
                                        
                                        <div class="d-flex align-items-center gap-4">
                                            <div class="d-flex align-items-center gap-2">
                                                <i class="bi bi-list-task text-body-secondary fs-5 opacity-75"></i>
                                                <div>
                                                    <div class="fw-bold fs-5 lh-1 text-body"><?= $cat['active_count'] ?></div>
                                                    <small class="text-body-secondary small">Aktif</small>
                                                </div>
                                            </div>
                                            <div class="vr opacity-25"></div>
                                            <div class="d-flex align-items-center gap-2">
                                                <i class="bi bi-alarm text-danger fs-5 opacity-75"></i>
                                                <div>
                                                    <div class="fw-bold fs-5 lh-1 text-body"><?= $cat['overdue_count'] ?></div>
                                                    <small class="text-body-secondary small">Telat</small>
                                                </div>
                                            </div>
                                            <div class="vr opacity-25"></div>
                                            <div class="d-flex align-items-center gap-2">
                                                <i class="bi bi-check2-circle text-success fs-5 opacity-75"></i>
                                                <div>
                                                    <div class="fw-bold fs-5 lh-1 text-body"><?= $cat['done_count'] ?></div>
                                                    <small class="text-body-secondary small">Selesai</small>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <?php endforeach; ?>

                        </div> <!-- End Row Categories -->

                        <?php if (empty($categories_data)): ?>
                            <div class="col-12 text-center py-5">
                                <div class="text-secondary opacity-25 mb-3"><i class="bi bi-tags display-1"></i></div>
                                <h5 class="text-secondary fw-bold">Belum ada data</h5>
                                <p class="text-secondary small">Tidak ada data yang ditampilkan saat ini.</p>
                            </div>
                        <?php endif; ?>
                    <?php elseif ($tasks->num_rows > 0): ?>

                <div class="row row-cols-1 row-cols-md-2 row-cols-lg-3 g-3 pb-5" id="taskGridContainer"> 
                        <?php while($row = $tasks->fetch_assoc()): ?>
                            <?php
                                // Cek Telat
                                $is_overdue = false;
                                if ($current_view == 'active' && !empty($row['deadline'])) {
                                    $deadline_dt = new DateTime($row['deadline']);
                                    $badge_color = $row['cat_color'] ?? '#6c757d'; 
                                    list($r, $g, $b) = sscanf($badge_color, "#%02x%02x%02x");
                                    $now_dt = new DateTime();
                                    if ($now_dt > $deadline_dt) { $is_overdue = true; }
                                }
                            ?>


                             <div class="col">
                                <div class="card shadow-sm h-100 position-relative <?= $is_overdue ? 'border-danger border-2' : 'border-0' ?>">
                                    <?php if ($is_overdue): ?>
                                        <div class="position-absolute top-0 end-0 bg-danger text-white px-2 py-1 small fw-bold" 
                                                style="border-bottom-left-radius: 8px; border-top-right-radius: var(--bs-border-radius); font-size: 0.65rem;">
                                            TELAT
                                        </div>
                                    <?php endif; ?>

                                    <div class="card-body p-3">
                                        <div class="d-flex align-items-center mb-2">

                                            <div class="me-3">
                                                <?php if ($current_view == 'active'): ?>
                                                    <a href="?complete_id=<?= $row['id'] ?>&csrf_token=<?= $csrf_token ?>" class="btn btn-outline-success rounded-circle p-0 d-flex align-items-center justify-content-center" style="width: 40px; height: 40px;" title="Selesai">
                                                        <i class="bi bi-check-lg fs-4"></i>
                                                    </a>
                                                <?php else: ?>
                                                    <div class="btn btn-success disabled rounded-circle p-0 d-flex align-items-center justify-content-center" style="width: 40px; height: 40px;">
                                                        <i class="bi bi-check-lg fs-4"></i>
                                                    </div>
                                                <?php endif; ?>
                                            </div>


                                            <div class="flex-grow-1">
                                                <h6 class="mb-1 fw-bold <?= ($current_view == 'completed') ? 'text-decoration-line-through text-secondary' : '' ?>">
                                                    <?= htmlspecialchars($row['title']) ?>
                                                </h6>
                                            </div>


                                            <div class="dropdown ms-2">
                                                <button class="btn btn-link text-secondary p-0" data-bs-toggle="dropdown"><i class="bi bi-three-dots-vertical fs-5"></i></button>
                                                <ul class="dropdown-menu dropdown-menu-end border-0 shadow" style="z-index: 9999;">
                                                    <?php if ($current_view == 'active'): ?>
                                                        <li>
                                                            <button class="dropdown-item" data-bs-toggle="modal" data-bs-target="#editTaskModal"
                                                                    data-id="<?= $row['id'] ?>" data-title="<?= htmlspecialchars($row['title']) ?>"
                                                                    data-description="<?= htmlspecialchars($row['description']) ?>"
                                                                    data-category="<?= htmlspecialchars($row['category']) ?>"
                                                                    data-priority="<?= htmlspecialchars($row['priority']) ?>"
                                                                    data-deadline="<?= !empty($row['deadline']) ? date('Y-m-d\TH:i', strtotime($row['deadline'])) : '' ?>"
                                                                    data-subtasks="<?= htmlspecialchars(implode("\n", array_column($conn->query("SELECT title FROM subtasks WHERE task_id = " . $row['id'])->fetch_all(MYSQLI_ASSOC), 'title'))) ?>">
                                                                <i class="bi bi-pencil me-2"></i> Ubah Detail
                                                            </button>
                                                        </li>
                                                    <?php endif; ?>
                                                    <?php if ($current_view == 'completed'): ?>
                                                        <li><a class="dropdown-item" href="?undo_id=<?= $row['id'] ?>&csrf_token=<?= $csrf_token ?>"><i class="bi bi-arrow-counterclockwise me-2"></i> Kembalikan</a></li>
                                                    <?php endif; ?>
                                                    <li><a class="dropdown-item text-danger btn-delete" href="?delete_id=<?= $row['id'] ?>&from_view=<?= $current_view ?>&csrf_token=<?= $csrf_token ?>"><i class="bi bi-trash me-2"></i> Hapus Permanen</a></li>
                                                </ul>
                                            </div>
                                        </div>

                                        <?php if (!empty($row['description'])): ?>
                                            <p class="card-text text-secondary mb-3 small"><?= nl2br(htmlspecialchars($row['description'])) ?></p>
                                        <?php endif; ?>
                                        
                                        <!-- SUBTASKS SECTION -->
                                        <?php
                                        $t_id = $row['id'];
                                        $subtasks = $conn->query("SELECT * FROM subtasks WHERE task_id = $t_id ORDER BY id ASC");
                                        if ($subtasks->num_rows > 0):
                                        ?>
                                        <div class="mb-3 ps-1 border-start border-3 border-light">
                                            <small class="text-uppercase fw-bold text-secondary d-block mb-2" style="font-size: 0.7rem;">Subtasks</small>
                                            <ul class="list-unstyled mb-0">
                                                <?php while($sub = $subtasks->fetch_assoc()): ?>
                                                <li class="d-flex align-items-center gap-2 mb-1">
                                                    <a href="dashboard.php?toggle_subtask=<?= $sub['id'] ?>&csrf_token=<?= $csrf_token ?>" class="text-decoration-none d-flex align-items-center">
                                                        <?php if($sub['is_done']): ?>
                                                            <i class="bi bi-check-square-fill text-success fs-5"></i>
                                                        <?php else: ?>
                                                            <i class="bi bi-square text-secondary fs-5"></i>
                                                        <?php endif; ?>
                                                    </a>
                                                        <?= htmlspecialchars($sub['title']) ?>
                                                    </span>
                                                </li>
                                                <?php endwhile; ?>
                                            </ul>
                                        </div>
                                        <?php endif; ?>

                                        <div class="d-flex flex-wrap align-items-center gap-3 mt-1">
                                            
                                            <?php 
                                                $badge_color = $row['cat_color'] ?? '#6c757d'; 
                                                
                                                list($r, $g, $b) = sscanf($badge_color, "#%02x%02x%02x");
                                            ?>
                                            <span class="badge rounded-pill fw-normal px-2 py-1" 
                                                  style="background-color: rgba(<?= "$r,$g,$b" ?>, 0.1); color: <?= $badge_color ?>; font-size: 0.7rem;">
                                                <?= ucfirst(htmlspecialchars($row['category'])) ?>
                                            </span>
                                            <?php if (!empty($row['priority'])): ?>
                                                <?php
                                                    $prio_color = 'secondary';
                                                    if ($row['priority'] == 'penting') $prio_color = 'danger';
                                                    if ($row['priority'] == 'wajib') $prio_color = 'warning';
                                                    if ($row['priority'] == 'opsional') $prio_color = 'success';
                                                ?>
                                                <span class="badge rounded-pill bg-<?= $prio_color ?>-subtle text-<?= $prio_color ?> fw-normal px-2 py-1" style="font-size: 0.7rem;">
                                                    <i class="bi bi-flag-fill me-1"></i> <?= ucfirst(htmlspecialchars($row['priority'])) ?>
                                                </span>
                                            <?php endif; ?>
                                            <div class="d-flex gap-3 text-secondary small" style="font-size: 0.75rem;">
                                                <span title="Dibuat"><i class="bi bi-plus-circle"></i> <?= formatTime($row['created_at']) ?></span>
                                                <?php if ($current_view == 'active' && !empty($row['deadline'])): ?>
                                                    <span class="<?= $is_overdue ? 'text-danger fw-bold' : '' ?>"><i class="bi bi-alarm"></i> <?= formatTime($row['deadline']) ?></span>
                                                <?php endif; ?>
                                                <?php if ($current_view == 'completed'): ?>
                                                    <span class="text-success"><i class="bi bi-check-all"></i> <?= formatTime($row['completed_at']) ?></span>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div> <!-- End Col -->
                        <?php endwhile; ?>
                    </div> <!-- End Row -->
                    <?php else: ?>
                        <div class="col-12 text-center py-5">
                            <div class="text-secondary opacity-25 mb-3"><i class="bi bi-clipboard-check display-1"></i></div>
                            <h5 class="text-secondary fw-bold">Belum ada data</h5>
                            <p class="text-secondary small">Tidak ada data yang ditampilkan saat ini.</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- 2. RIGHT SIDEBAR (DESKTOP) -->
            <div class="col-xl-3 d-none d-xl-flex flex-column p-4 border-start bg-body-tertiary">
                <div class="d-flex align-items-center justify-content-between mb-5">
                    <div class="d-flex align-items-center">
                        <div class="me-3" style="width: 48px; height: 48px;">
                            <?php if ($user_photo == 'default.png'): ?>
                                <div class="rounded-circle bg-primary d-flex align-items-center justify-content-center text-white fw-bold w-100 h-100 fs-5">
                                    <?= strtoupper(substr($username_display, 0, 2)) ?>
                                </div>
                            <?php else: ?>
                                <img src="uploads/<?= htmlspecialchars($user_photo) ?>" class="rounded-circle w-100 h-100 object-fit-cover" alt="User">
                            <?php endif; ?>
                        </div>
                        <div>
                            <h6 class="mb-0 fw-bold text-truncate" style="max-width: 150px;"><?= htmlspecialchars($username_display) ?></h6>
                            <small class="text-secondary"><?= ($username_display === 'admin') ? 'Administrator' : 'Personal Account' ?></small>
                        </div>
                    </div>
                    <a href="settings.php" class="btn btn-icon btn-sm btn-outline-secondary border-0 rounded-circle"><i class="bi bi-gear-fill fs-5"></i></a>
                </div>

                <div class="d-flex flex-column gap-2">
                    <h6 class="text-uppercase text-secondary small fw-bold mb-2 ps-2">Menu Utama</h6>
                    <a href="?view=active" class="btn text-start p-3 rounded-3 d-flex align-items-center gap-3 <?= ($current_view == 'active') ? 'btn-primary shadow' : 'btn-light bg-white border-0 text-secondary' ?>">
                        <i class="bi bi-list-task fs-5"></i> <span class="fw-bold">Tugas Saya</span>
                        <?php if($current_view == 'active'): ?><i class="bi bi-chevron-right ms-auto"></i><?php endif; ?>
                    </a>
                    <a href="?view=categories" class="btn text-start p-3 rounded-3 d-flex align-items-center gap-3 <?= ($current_view == 'categories') ? 'btn-primary shadow' : 'btn-light bg-white border-0 text-secondary' ?>">
                        <i class="bi bi-tags fs-5" style="transform: translateY(-1px);"></i> <span class="fw-bold">Kelola Kategori</span>
                        <?php if($current_view == 'categories'): ?><i class="bi bi-chevron-right ms-auto"></i><?php endif; ?>
                    </a>
                    <a href="?view=completed" class="btn text-start p-3 rounded-3 d-flex align-items-center gap-3 <?= ($current_view == 'completed') ? 'btn-primary shadow' : 'btn-light bg-white border-0 text-secondary' ?>">
                        <i class="bi bi-check2-circle fs-5"></i> <span class="fw-bold">Riwayat Selesai</span>
                        <?php if($current_view == 'completed'): ?><i class="bi bi-chevron-right ms-auto"></i><?php endif; ?>
                    </a>
                </div>



                <div class="mt-auto">
                    <a href="logout.php" class="btn btn-outline-danger w-100 py-2 border-0 bg-danger-subtle text-danger"><i class="bi bi-box-arrow-right me-2"></i> Keluar</a>
                </div>
            </div>

        </div>
    </div>

    <!-- OFFCANVAS MOBILE -->
    <div class="offcanvas offcanvas-end" tabindex="-1" id="offcanvasRight">
        <div class="offcanvas-header border-bottom">
            <h5 class="offcanvas-title fw-bold">Menu</h5>
            <button type="button" class="btn-close" data-bs-dismiss="offcanvas"></button>
        </div>
        <div class="offcanvas-body d-flex flex-column bg-body-tertiary">
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-body d-flex align-items-center p-3">
                     <div class="me-3" style="width: 48px; height: 48px;">
                        <?php if ($user_photo == 'default.png'): ?>
                            <div class="rounded-circle bg-primary d-flex align-items-center justify-content-center text-white fw-bold w-100 h-100 fs-5">
                                <?= strtoupper(substr($username_display, 0, 2)) ?>
                            </div>
                        <?php else: ?>
                            <img src="uploads/<?= htmlspecialchars($user_photo) ?>" class="rounded-circle w-100 h-100 object-fit-cover" alt="User">
                        <?php endif; ?>
                    </div>
                    <div>
                        <h6 class="mb-0 fw-bold"><?= htmlspecialchars($username_display) ?></h6>
                        <a href="settings.php" class="small text-decoration-none">Pengaturan</a>
                    </div>
                </div>
            </div>
            <div class="d-grid gap-2 mb-4">
                <a href="?view=active" class="btn p-3 text-start <?= ($current_view == 'active') ? 'btn-primary' : 'btn-light bg-white' ?>"><i class="bi bi-list-task me-2"></i> Tugas Saya</a>
                <a href="?view=categories" class="btn p-3 text-start <?= ($current_view == 'categories') ? 'btn-primary' : 'btn-light bg-white' ?>"><i class="bi bi-tags me-2"></i> Kelola Kategori</a>
                <a href="?view=completed" class="btn p-3 text-start <?= ($current_view == 'completed') ? 'btn-primary' : 'btn-light bg-white' ?>"><i class="bi bi-check2-circle me-2"></i> Riwayat Selesai</a>
            </div>
            <div class="mt-auto">
                <a href="logout.php" class="btn btn-danger w-100"><i class="bi bi-box-arrow-right me-2"></i> Keluar</a>
            </div>
        </div>
    </div>

    <!-- MODAL ADD TASK -->
    <div class="modal fade" id="addTaskModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content border-0 shadow-lg">
                <div class="modal-header border-0 pb-0">
                    <h5 class="modal-title fw-bold">Tambah Tugas Baru</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body pt-4">
                    <form method="POST" action="">
                        <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                        <div class="mb-3">
                            <label class="form-label text-secondary small fw-bold text-uppercase">Judul Tugas</label>

                            <input type="text" name="title" class="form-control bg-body-tertiary text-body border-0" placeholder="Apa yang ingin dikerjakan?" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label text-secondary small fw-bold text-uppercase">Deskripsi (Opsional)</label>
                            <textarea name="description" class="form-control bg-body-tertiary text-body border-0" rows="3" placeholder="Detail tugas..."></textarea>
                        </div>
                        <div class="mb-3">
                            <label class="form-label text-secondary small fw-bold text-uppercase">Kategori</label>
                            <!-- Category Selection Chips -->
                            <div class="d-flex flex-wrap gap-2 mb-2">
                                <?php foreach($categories_data as $cat): ?>
                                    <input type="radio" class="btn-check" name="category" id="add_cat_<?= $cat['id'] ?>" value="<?= htmlspecialchars($cat['name']) ?>">
                                    <label class="btn btn-sm btn-outline-secondary text-body border d-flex align-items-center gap-2" for="add_cat_<?= $cat['id'] ?>">
                                        <span class="rounded-circle" style="width: 10px; height: 10px; background-color: <?= $cat['color'] ?>;"></span>
                                        <?= ucfirst(htmlspecialchars($cat['name'])) ?>
                                    </label>
                                <?php endforeach; ?>
                                <!-- Button to open Add Category Modal -->
                                <button type="button" class="btn btn-sm btn-link text-decoration-none" data-bs-toggle="modal" data-bs-target="#addCategoryModal">
                                    <i class="bi bi-plus-circle"></i> Kategori Baru
                                </button>
                            </div>
                        </div>
                        <div class="row mb-3">
                            <div class="col-6">
                                <label class="form-label text-secondary small fw-bold text-uppercase">Prioritas</label>
                                <select name="priority" class="form-select bg-body-tertiary text-body border-0">
                                    <option value="">Tidak Ada</option>
                                    <option value="penting">Penting</option>
                                    <option value="wajib">Wajib</option>
                                    <option value="opsional">Opsional</option>
                                </select>
                            </div>
                            <div class="col-6">
                                <label class="form-label text-secondary small fw-bold text-uppercase">Tenggat Waktu</label>
                                <input type="datetime-local" name="deadline" class="form-control bg-body-tertiary text-body border-0">
                            </div>
                        </div>
                        <div class="mb-4">
                            <label class="form-label text-secondary small fw-bold text-uppercase">Subtasks</label>
                            <div id="addSubtaskContainer" class="d-flex flex-column gap-2 mb-2">
                                <!-- Dynamic Inputs -->
                            </div>
                            <button type="button" id="btnAddSubtaskAdd" class="btn btn-sm btn-outline-secondary border-dashed w-100">
                                <i class="bi bi-plus"></i> Tambah Subtask
                            </button>
                        </div>
                        <button type="submit" name="add_task" class="btn btn-primary w-100 py-3 rounded-3 fw-bold">Simpan Tugas</button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- MODAL ADD CATEGORY -->
    <div class="modal fade" id="addCategoryModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered modal-sm">
            <div class="modal-content border-0 shadow-sm">
                <div class="modal-header border-0 pb-0">
                    <h6 class="modal-title fw-bold">Buat Kategori</h6>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <form method="POST">
                        <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                        <div class="mb-3">
                            <label class="form-label small text-secondary">Nama Kategori</label>
                            <input type="text" name="cat_name" class="form-control" placeholder="Misal: design, kuliner..." required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label small text-secondary">Deskripsi (Opsional)</label>
                            <textarea name="cat_desc" class="form-control" rows="2" placeholder="Keterangan singkat..."></textarea>
                        </div>
                        <div class="mb-3">
                            <label class="form-label small text-secondary">Warna</label>
                            <input type="color" name="cat_color" class="form-control form-control-color w-100" value="#0d6efd" title="Pilih warna">
                        </div>
                        <button type="submit" name="add_category" class="btn btn-primary w-100 btn-sm">Simpan</button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- MODAL EDIT CATEGORY -->
    <div class="modal fade" id="editCategoryModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered modal-sm">
            <div class="modal-content border-0 shadow-sm">
                <div class="modal-header border-0 pb-0">
                    <h6 class="modal-title fw-bold">Ubah Kategori</h6>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body pt-4">
                    <form method="POST">
                        <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                        <input type="hidden" name="cat_id" id="editCatId">
                        <input type="hidden" name="old_name" id="editCatOldName">
                        
                        <div class="mb-3">
                            <label class="form-label small text-secondary">Nama Kategori</label>
                            <input type="text" name="cat_name" id="editCatName" class="form-control" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label small text-secondary">Deskripsi</label>
                            <textarea name="cat_desc" id="editCatDesc" class="form-control" rows="2"></textarea>
                        </div>
                        <div class="mb-3">
                            <label class="form-label small text-secondary">Warna</label>
                            <input type="color" name="cat_color" id="editCatColor" class="form-control form-control-color w-100" title="Pilih warna">
                        </div>
                        <button type="submit" name="edit_category" class="btn btn-primary w-100 btn-sm">Simpan Perubahan</button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- MODAL EDIT TASK -->
    <div class="modal fade" id="editTaskModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content border-0 shadow-lg">
                <div class="modal-header border-0 pb-0">
                    <h5 class="modal-title fw-bold">Ubah Detail Tugas</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body pt-4">
                    <form method="POST" action="">
                        <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                        <input type="hidden" name="task_id" id="editTaskId">
                        <div class="mb-3">
                            <label class="form-label text-secondary small fw-bold text-uppercase">Judul Tugas</label>
                            <input type="text" name="title" id="editTaskTitle" class="form-control bg-body-tertiary text-body border-0" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label text-secondary small fw-bold text-uppercase">Deskripsi</label>
                            <textarea name="description" id="editTaskDesc" class="form-control bg-body-tertiary text-body border-0" rows="3"></textarea>
                        </div>
                        <div class="mb-3">
                            <label class="form-label text-secondary small fw-bold text-uppercase">Kategori</label>
                            <div class="d-flex flex-wrap gap-2 mb-2">
                                <?php foreach($categories_data as $cat): ?>
                                    <input type="radio" class="btn-check" name="category" id="edit_cat_task_<?= $cat['id'] ?>" value="<?= htmlspecialchars($cat['name']) ?>">
                                    <label class="btn btn-sm btn-outline-secondary text-body border d-flex align-items-center gap-2" for="edit_cat_task_<?= $cat['id'] ?>">
                                        <span class="rounded-circle" style="width: 10px; height: 10px; background-color: <?= $cat['color'] ?>;"></span>
                                        <?= ucfirst(htmlspecialchars($cat['name'])) ?>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <div class="row mb-3">
                            <div class="col-6">
                                <label class="fw-bold small mb-1 text-secondary">Prioritas</label>
                                <select name="priority" id="editTaskPriority" class="form-select bg-body-tertiary text-body border-0 fw-bold">
                                    <option value="">Tidak Ada</option>
                                    <option value="penting">Penting (Red)</option>
                                    <option value="wajib">Wajib (Yellow)</option>
                                    <option value="opsional">Opsional (Green)</option>
                                </select>
                            </div>
                            <div class="col-6">
                                <label class="form-label text-secondary small fw-bold text-uppercase">Tenggat Waktu</label>
                                <input type="datetime-local" name="deadline" id="editTaskDeadline" class="form-control bg-body-tertiary text-body border-0">
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label text-secondary small fw-bold text-uppercase">Subtasks</label>
                            <div id="editSubtaskContainer" class="d-flex flex-column gap-2 mb-2">
                                <!-- Dynamic Inputs -->
                            </div>
                            <button type="button" id="btnAddSubtaskEdit" class="btn btn-sm btn-outline-secondary border-dashed w-100">
                                <i class="bi bi-plus"></i> Tambah Subtask
                            </button>
                        </div>
                        <button type="submit" name="edit_task" class="btn btn-primary w-100 py-3 rounded-3 fw-bold">Simpan Perubahan</button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- MODAL KALENDER (FULL BOOTSTRAP + SHAPE) -->
    <div class="modal fade" id="calendarModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered modal-xl">
            <div class="modal-content border-0 shadow rounded-4 overflow-hidden">
                <div class="modal-body p-0">
                    <div class="row g-0">
                        
                        <!-- KIRI: KALENDER -->
                        <div class="col-lg-7 p-4 border-end">
                            <div class="d-flex justify-content-between align-items-center mb-4">

                                <h5 class="fw-bold mb-0 text-body" id="calMonthYear">Bulan Tahun</h5>
                                <div class="btn-group">
                                    <button class="btn btn-outline-secondary btn-sm" id="prevMonth"><i class="bi bi-chevron-left"></i></button>
                                    <button class="btn btn-outline-secondary btn-sm" id="nextMonth"><i class="bi bi-chevron-right"></i></button>
                                </div>
                            </div>

                            <div class="d-flex text-center mb-2">
                                <div class="flex-fill fw-bold text-secondary small" style="width: 14.28%">Ming</div>
                                <div class="flex-fill fw-bold text-secondary small" style="width: 14.28%">Sen</div>
                                <div class="flex-fill fw-bold text-secondary small" style="width: 14.28%">Sel</div>
                                <div class="flex-fill fw-bold text-secondary small" style="width: 14.28%">Rab</div>
                                <div class="flex-fill fw-bold text-secondary small" style="width: 14.28%">Kam</div>
                                <div class="flex-fill fw-bold text-secondary small" style="width: 14.28%">Jum</div>
                                <div class="flex-fill fw-bold text-secondary small" style="width: 14.28%">Sab</div>
                            </div>


                            <div class="d-flex flex-wrap row-gap-2" id="calendarGrid"></div>
                        </div>

                        <!-- KANAN: DETAIL TUGAS -->

                        <div class="col-lg-5 bg-body-tertiary p-4 d-flex flex-column">
                            <div class="mb-4 text-center">
                                <span class="badge bg-primary-subtle text-primary rounded-pill mb-2">Jadwal Terpilih</span>

                                <h2 class="display-6 fw-bold mb-0 text-body" id="selectedDateDisplay">--</h2>
                                <p class="text-secondary mb-0" id="selectedFullDateDisplay">Pilih tanggal di samping</p>
                            </div>

                            <div class="card border-0 shadow-sm flex-grow-1 overflow-hidden">

                                <div class="card-header bg-body border-bottom fw-bold py-3 text-body">Daftar Tugas</div>
                                <div class="card-body overflow-auto p-0" id="taskListContainer" style="max-height: 300px;">
                                    <div class="h-100 d-flex flex-column align-items-center justify-content-center text-secondary opacity-50 p-4">
                                        <i class="bi bi-calendar-check fs-1"></i>
                                        <p class="small mt-2">Tidak ada tugas pada tanggal ini.</p>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="mt-3">
                                <button class="btn btn-outline-secondary w-100 rounded-pill" data-bs-dismiss="modal">Tutup</button>
                            </div>
                        </div>

                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- SCRIPTS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script nonce="<?= $nonce ?>">
        // 1. DATA PHP KE JS
        const USER_TASKS_CALENDAR = <?= $json_tasks ?>;

        document.addEventListener('DOMContentLoaded', () => {
            
            // --- A. JAM DIGITAL ---
            function updateClock() {
                const now = new Date();
                const clockEl = document.getElementById('digital-clock');
                const dateEl = document.getElementById('digital-date');
                if(clockEl && dateEl) {
                    clockEl.innerText = now.toLocaleTimeString('id-ID', { hour12: false });
                    dateEl.innerText = now.toLocaleDateString('id-ID', { weekday: 'long', day: 'numeric', month: 'short' });
                }
            }
            setInterval(updateClock, 1000);
            updateClock();

            // --- B. KALENDER LOGIC ---
            let currYear = new Date().getFullYear();
            let currMonth = new Date().getMonth();
            const months = ["Januari", "Februari", "Maret", "April", "Mei", "Juni", "Juli", "Agustus", "September", "Oktober", "November", "Desember"];
            
            const grid = document.getElementById('calendarGrid');
            const monthTitle = document.getElementById('calMonthYear');
            const taskContainer = document.getElementById('taskListContainer');

            function renderCalendar(year, month) {
                if(!grid) return;
                
                grid.innerHTML = "";
                monthTitle.innerText = `${months[month]} ${year}`;
                
                const firstDay = new Date(year, month, 1).getDay();
                const lastDate = new Date(year, month + 1, 0).getDate();
                const today = new Date();

                // Spacer Awal Bulan
                for(let i=0; i<firstDay; i++) {
                    const empty = document.createElement('div');
                    empty.style.width = "14.28%"; 
                    empty.style.height = "50px";
                    grid.appendChild(empty);
                }

                // Render Tanggal
                for(let i=1; i<=lastDate; i++) {
                    const dateBtn = document.createElement('button');
                    const dateStr = `${year}-${String(month+1).padStart(2,'0')}-${String(i).padStart(2,'0')}`;
                    
                    dateBtn.style.width = "14.28%";
                    dateBtn.style.height = "50px";
                    

                    dateBtn.className = "btn btn-sm text-body border-0 rounded-2 position-relative d-flex justify-content-center align-items-center fw-bold";
                    
                    // Highlight Hari Ini
                    if(i === today.getDate() && month === today.getMonth() && year === today.getFullYear()) {
                        dateBtn.classList.remove('text-body'); 
                        dateBtn.classList.add('btn-primary', 'text-white', 'shadow');
                    }

                    // Dot Merah jika ada tugas
                    if(USER_TASKS_CALENDAR.some(t => t.date === dateStr)) {
                        const dot = document.createElement('span');
                        dot.className = "position-absolute bottom-0 start-50 translate-middle-x bg-danger rounded-circle";
                        dot.style.width = "5px"; dot.style.height = "5px";
                        dot.style.marginBottom = "5px";
                        dateBtn.appendChild(dot);
                    }

                    dateBtn.innerHTML += i;

                    // Event Klik
                    dateBtn.onclick = () => {
                        document.querySelectorAll('#calendarGrid button').forEach(b => {
                            b.classList.remove('btn-primary', 'text-white', 'shadow');
                            b.classList.add('text-body'); // Reset warna teks
                        });
                        dateBtn.classList.remove('text-body');
                        dateBtn.classList.add('btn-primary', 'text-white', 'shadow');
                        showTasks(dateStr, i, month, year);
                    };

                    grid.appendChild(dateBtn);
                }
            }

            function showTasks(dateStr, day, month, year) {
                document.getElementById('selectedDateDisplay').innerText = day;
                document.getElementById('selectedFullDateDisplay').innerText = `${day} ${months[month]} ${year}`;
                taskContainer.innerHTML = "";
                
                const tasks = USER_TASKS_CALENDAR.filter(t => t.date === dateStr);
                
                if(tasks.length > 0) {
                    const ul = document.createElement('div');
                    ul.className = "list-group list-group-flush";
                    tasks.forEach(t => {
                        const li = document.createElement('div');

                        li.className = "list-group-item list-group-item-action bg-transparent d-flex justify-content-between align-items-center px-3 py-3 border-bottom";
                        li.innerHTML = `
                            <div class="text-body"><i class="bi bi-check-circle me-2 text-primary"></i> ${t.title}</div>
                            <span class="badge bg-secondary-subtle text-body border">${t.time}</span>
                        `;
                        ul.appendChild(li);
                    });
                    taskContainer.appendChild(ul);
                } else {
                    taskContainer.innerHTML = `
                        <div class="h-100 d-flex flex-column align-items-center justify-content-center text-secondary opacity-50 p-4">
                            <i class="bi bi-calendar-x fs-1"></i>
                            <p class="small mt-2">Tidak ada tugas.</p>
                        </div>
                    `;
                }
            }

            document.getElementById('prevMonth').onclick = () => { currMonth--; if(currMonth < 0) { currMonth=11; currYear--; } renderCalendar(currYear, currMonth); };
            document.getElementById('nextMonth').onclick = () => { currMonth++; if(currMonth > 11) { currMonth=0; currYear++; } renderCalendar(currYear, currMonth); };
            
            const calModal = document.getElementById('calendarModal');
            if(calModal) {
                calModal.addEventListener('shown.bs.modal', () => renderCalendar(currYear, currMonth));
            }

            // --- C. EDIT & DELETE LOGIC ---
            document.querySelectorAll('.btn-delete').forEach(btn => {
                btn.addEventListener('click', (e) => {
                    if (!confirm('Yakin ingin menghapus tugas ini secara permanen?')) e.preventDefault();
                });
            });

            const editModal = document.getElementById('editTaskModal');
            if (editModal) {
                editModal.addEventListener('show.bs.modal', event => {
                    const button = event.relatedTarget;
                    const id = button.getAttribute('data-id');
                    const title = button.getAttribute('data-title');
                    const deadline = button.getAttribute('data-deadline');
                    const category = button.getAttribute('data-category');
            
                    const description = button.getAttribute('data-description');
                    const priority = button.getAttribute('data-priority');
                    const subtasks = button.getAttribute('data-subtasks');
            
                    const modalTitle = editModal.querySelector('#editTaskTitle');
                    const modalId = editModal.querySelector('#editTaskId');
                    const modalDesc = editModal.querySelector('#editTaskDesc');
                    const modalPriority = editModal.querySelector('#editTaskPriority');
                    const modalDeadline = editModal.querySelector('#editTaskDeadline');
                    const modalSubtasks = button.getAttribute('data-subtasks') || ''; // Raw string
                    
                    modalTitle.value = title;
                    modalId.value = id;
                    modalDesc.value = description;
                    modalPriority.value = priority;
                    modalDeadline.value = deadline;
                    
                    // Clear & Populate Dynamic Subtasks
                    const container = document.getElementById('editSubtaskContainer');
                    container.innerHTML = '';
                    if (modalSubtasks.trim() !== "") {
                        const subsArray = modalSubtasks.split("\n");
                        subsArray.forEach(sub => addField('editSubtaskContainer', sub));
                    } else {
                         // Optional: Add one empty field by default? or left empty
                    }

                    // Select Radio Button
                    const radios = editModal.querySelectorAll('input[name="category"]');
                    radios.forEach(r => r.checked = false); // Reset first
                    radios.forEach(r => {
                        if(r.value.toLowerCase() === category.toLowerCase()){
                            r.checked = true;
                        }
                    });
                });
            }

            // Script for Edit Category Modal
            const editCategoryModal = document.getElementById('editCategoryModal');
            if (editCategoryModal) {
                editCategoryModal.addEventListener('show.bs.modal', function (event) {
                    const button = event.relatedTarget;
                    const id = button.getAttribute('data-id');
                    const name = button.getAttribute('data-name');
                    const color = button.getAttribute('data-color');
                    const desc = button.getAttribute('data-desc');

                    const modalId = editCategoryModal.querySelector('#editCatId');
                    const modalOldName = editCategoryModal.querySelector('#editCatOldName');
                    const modalName = editCategoryModal.querySelector('#editCatName');
                    const modalColor = editCategoryModal.querySelector('#editCatColor');
                    const modalDesc = editCategoryModal.querySelector('#editCatDesc');

                    modalId.value = id;
                    modalOldName.value = name;
                    modalName.value = name; // Only display name, no ucfirst here to match raw value
                    modalColor.value = color;
                    modalDesc.value = desc;
                });
            }

            // --- D. DROPDOWN Z-INDEX ---
            // Saat dropdown dibuka, card pembungkusnya diberi z-index tinggi
            // agar menu dropdown tampil di ATAS card di bawahnya.
            const dropdowns = document.querySelectorAll('.dropdown');
            dropdowns.forEach(dd => {
                dd.addEventListener('show.bs.dropdown', function () {
                    const card = this.closest('.card');
                    if(card) card.style.zIndex = "999";
                });
                dd.addEventListener('hide.bs.dropdown', function () {
                    const card = this.closest('.card');
                    if(card) card.style.zIndex = "";
                });
            });
        });

        // --- F. DYNAMIC SUBTASKS FUNCTION (Moved Global & Event Delegation) ---
        // CSP prohibits inline onclick, so we use event delegation.
        
        document.addEventListener('click', function(e) {
            // 1. Handle "Add Subtask" Buttons
            if (e.target.closest('#btnAddSubtaskAdd')) {
                addField('addSubtaskContainer');
            }
            if (e.target.closest('#btnAddSubtaskEdit')) {
                addField('editSubtaskContainer');
            }

            // 2. Handle "Remove Subtask" Buttons (Dynamic)
            if (e.target.closest('.btn-remove-subtask')) {
                e.target.closest('.d-flex').remove();
            }
        });

        function addField(containerId, value = '') {
            const container = document.getElementById(containerId);
            if (!container) return; 
            const div = document.createElement('div');
            div.className = 'd-flex gap-2';
            div.innerHTML = `
                <input type="text" name="subtasks[]" class="form-control bg-body-tertiary text-body border-0 btn-sm" value="${value}" placeholder="Nama subtask...">
                <button type="button" class="btn btn-icon text-danger btn-sm btn-remove-subtask"><i class="bi bi-x"></i></button>
            `;
            container.appendChild(div);
        }

        // --- LIVE SEARCH & FILTER LOGIC ---
        document.addEventListener('DOMContentLoaded', () => {
            const form = document.getElementById('taskSearchForm');
            const gridContainer = document.getElementById('taskGridContainer');
            
            if (form && gridContainer) {
                const inputs = form.querySelectorAll('input, select');
                
                // Debounce Function
                function debounce(func, timeout = 300) {
                    let timer;
                    return (...args) => {
                        clearTimeout(timer);
                        timer = setTimeout(() => { func.apply(this, args); }, timeout);
                    };
                }

                // Fetch & Update
                const updateResults = () => {
                    const formData = new FormData(form);
                    const params = new URLSearchParams(formData);
                    // Retain current view
                    // params.append('view', '<?= $current_view ?>'); // Already in form as hidden input

                    const url = `dashboard.php?${params.toString()}`;
                    
                    // Update URL browser without reload
                    history.pushState(null, '', url);

                    // Add loading opacity
                    gridContainer.style.opacity = '0.5';
                    gridContainer.style.transition = 'opacity 0.2s';

                    fetch(url)
                        .then(response => response.text())
                        .then(html => {
                            const parser = new DOMParser();
                            const doc = parser.parseFromString(html, 'text/html');
                            const newGrid = doc.getElementById('taskGridContainer');
                            
                            if (newGrid) {
                                gridContainer.innerHTML = newGrid.innerHTML;
                                // Re-initialize tooltips or events if needed
                            } else {
                                // Fallback empty
                                gridContainer.innerHTML = '<div class="col-12 text-center py-5"><p class="text-secondary">Tidak ada data.</p></div>';
                            }
                            gridContainer.style.opacity = '1';
                        })
                        .catch(err => {
                            console.error('Search error:', err);
                            gridContainer.style.opacity = '1';
                        });
                };

                // Attach Events
                inputs.forEach(input => {
                    if (input.tagName === 'SELECT') {
                        input.addEventListener('change', updateResults);
                    } else if (input.type === 'text') {
                        input.addEventListener('input', debounce(updateResults, 300));
                    }
                });
            }
        });
    </script>
</body>
</html>