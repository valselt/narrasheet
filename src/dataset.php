<?php
session_start();
require_once 'config.php';

if (!isset($_SESSION['user'])) {
    header("Location: login.php");
    exit();
}

$user = $_SESSION['user'];
$email = $user['email'] ?? 'unknown';

// --- 1. API: GET TAGS (Untuk Autocomplete) ---
if (isset($_GET['action_type']) && $_GET['action_type'] === 'get_tags') {
    header('Content-Type: application/json');
    $stmt = $conn->prepare("SELECT tag_name as value FROM tags WHERE user_email = ? ORDER BY tag_name ASC");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $result = $stmt->get_result();
    $tags = [];
    while($row = $result->fetch_assoc()) {
        $tags[] = $row['value']; 
    }
    echo json_encode($tags);
    exit();
}

// --- 2. HELPER: HANDLE TAGS (Dengan Auto-Cleanup) ---
function handleTags($conn, $itemId, $tagsJson, $userEmail, $type = 'paper') {
    $tableRel = ($type === 'paper') ? 'paper_tags' : 'dataset_tags';
    $colID = ($type === 'paper') ? 'paper_id' : 'dataset_id';

    // 1. Hapus semua relasi lama item ini
    $stmt_del = $conn->prepare("DELETE FROM $tableRel WHERE $colID = ?");
    $stmt_del->bind_param("i", $itemId);
    $stmt_del->execute();

    // 2. Jika ada tags baru, masukkan kembali
    if (!empty($tagsJson)) {
        $tagsArray = json_decode($tagsJson, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $raw = explode(',', $tagsJson);
            $tagsArray = [];
            foreach($raw as $r) $tagsArray[] = ['value' => trim($r)];
        }

        foreach ($tagsArray as $tagItem) {
            if (!isset($tagItem['value'])) continue;
            $tagName = trim(strtolower($tagItem['value']));
            if (empty($tagName)) continue;

            // Cek apakah tag sudah ada
            $stmt_check = $conn->prepare("SELECT id FROM tags WHERE tag_name = ? AND user_email = ?");
            $stmt_check->bind_param("ss", $tagName, $userEmail);
            $stmt_check->execute();
            $res = $stmt_check->get_result();

            if ($res->num_rows > 0) {
                $tagId = $res->fetch_assoc()['id'];
            } else {
                // Buat tag baru jika belum ada
                $stmt_ins = $conn->prepare("INSERT INTO tags (user_email, tag_name) VALUES (?, ?)");
                $stmt_ins->bind_param("ss", $userEmail, $tagName);
                $stmt_ins->execute();
                $tagId = $stmt_ins->insert_id;
            }

            // Sambungkan relasi
            $stmt_link = $conn->prepare("INSERT INTO $tableRel ($colID, tag_id) VALUES (?, ?)");
            $stmt_link->bind_param("ii", $itemId, $tagId);
            $stmt_link->execute();
        }
    }

    // 3. CLEANUP: Hapus tags yang sudah tidak dipakai di manapun
    // Logic: Hapus dari tabel 'tags' jika ID-nya TIDAK ADA di 'paper_tags' DAN TIDAK ADA di 'dataset_tags'
    $cleanupSql = "DELETE t FROM tags t 
                   LEFT JOIN paper_tags pt ON t.id = pt.tag_id 
                   LEFT JOIN dataset_tags dt ON t.id = dt.tag_id 
                   WHERE pt.tag_id IS NULL AND dt.tag_id IS NULL";
    $conn->query($cleanupSql);
}

// --- 3. LOGIC CRUD ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action_type'] ?? 'create';
    
    if ($action === 'delete') {
        $id = $_POST['dataset_id'];
        $stmt = $conn->prepare("DELETE FROM datasets WHERE id=? AND user_email=?");
        $stmt->bind_param("is", $id, $email);
        if ($stmt->execute()) {
            // --- TAMBAHAN CLEANUP ---
            $conn->query("DELETE t FROM tags t LEFT JOIN paper_tags pt ON t.id = pt.tag_id LEFT JOIN dataset_tags dt ON t.id = dt.tag_id WHERE pt.tag_id IS NULL AND dt.tag_id IS NULL");
            // ------------------------
            header("Location: dataset?success=delete");
        }
        exit();
    }
    elseif ($action === 'archive') {
        $id = $_POST['dataset_id'];
        $stmt = $conn->prepare("UPDATE datasets SET status='archived' WHERE id=? AND user_email=?");
        $stmt->bind_param("is", $id, $email);
        if ($stmt->execute()) header("Location: dataset?success=archive");
        exit();
    }
    elseif ($action === 'restore') {
        $id = $_POST['dataset_id'];
        $stmt = $conn->prepare("UPDATE datasets SET status='active' WHERE id=? AND user_email=?");
        $stmt->bind_param("is", $id, $email);
        if ($stmt->execute()) header("Location: dataset?success=restore");
        exit();
    }
    elseif ($action === 'update') {
        $id = $_POST['dataset_id'];
        $nama = $_POST['nama_dataset'];
        $jenis = $_POST['jenis_dataset'];
        $link = $_POST['link_dataset'];
        $tags = $_POST['tags'] ?? ''; 
        
        $stmt = $conn->prepare("UPDATE datasets SET nama_dataset=?, jenis_dataset=?, link_dataset=? WHERE id=? AND user_email=?");
        $stmt->bind_param("sssis", $nama, $jenis, $link, $id, $email);
        if ($stmt->execute()) {
            handleTags($conn, $id, $tags, $email, 'dataset'); 
            header("Location: dataset?success=update");
        }
        exit();
    }
    else {
        $nama = $_POST['nama_dataset'];
        $jenis = $_POST['jenis_dataset'];
        $link = $_POST['link_dataset'];
        $tags = $_POST['tags'] ?? ''; 
        
        $stmt = $conn->prepare("INSERT INTO datasets (user_email, nama_dataset, jenis_dataset, link_dataset, status) VALUES (?, ?, ?, ?, 'active')");
        $stmt->bind_param("ssss", $email, $nama, $jenis, $link);
        if ($stmt->execute()) {
            $new_id = $stmt->insert_id;
            handleTags($conn, $new_id, $tags, $email, 'dataset'); 
            header("Location: dataset?success=create");
        }
        exit();
    }
}

// --- 4. AMBIL DATA & SORTING ---
$sortOption = $_GET['sort'] ?? 'newest'; // Default

$orderBy = match ($sortOption) {
    'oldest'   => 'd.created_at ASC',
    'title_az' => 'd.nama_dataset ASC',
    'platform' => 'd.jenis_dataset ASC',
    default    => 'd.created_at DESC' // 'newest'
};

$sql_base = "SELECT d.*, GROUP_CONCAT(t.tag_name) as tag_list 
             FROM datasets d 
             LEFT JOIN dataset_tags dt ON d.id = dt.dataset_id 
             LEFT JOIN tags t ON dt.tag_id = t.id 
             WHERE d.user_email = ? AND d.status = ? 
             GROUP BY d.id ORDER BY $orderBy";

$stmt_active = $conn->prepare($sql_base);
$status_active = 'active';
$stmt_active->bind_param("ss", $email, $status_active);
$stmt_active->execute();
$result_active = $stmt_active->get_result();

$stmt_archive = $conn->prepare($sql_base);
$status_archived = 'archived';
$stmt_archive->bind_param("ss", $email, $status_archived);
$stmt_archive->execute();
$result_archive = $stmt_archive->get_result();
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dataset - Narrasheet</title>
    
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter+Tight:wght@300;400;500;600&family=Outfit:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/@yaireo/tagify/dist/tagify.css" rel="stylesheet" type="text/css" />
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/@yaireo/tagify"></script>

    <script>
        tailwind.config = {
            theme: {
                extend: {
                    fontFamily: {
                        'heading': ['Outfit', 'sans-serif'],
                        'body': ['Inter Tight', 'sans-serif'],
                        'mono': ['JetBrains Mono', 'monospace'],
                    }
                }
            }
        }
    </script>
    <style> 
        body { font-family: 'Inter Tight', sans-serif; } 
        h1, h2, h3, h4, h5, h6 { font-family: 'Outfit', sans-serif; } 
        .tagify { --tags-border-color: #E5E7EB; --tags-hover-border-color: #3B82F6; --tag-bg: #EFF6FF; --tag-text-color: #2563EB; border-radius: 0.75rem; padding: 0.5rem; }
    </style>
</head>
<body class="bg-gray-50 text-slate-800">

    <nav class="bg-white/80 backdrop-blur-md sticky top-0 z-50 border-b border-gray-100">
        <div class="container mx-auto px-6 h-20 flex justify-between items-center">
            <a href="./" class="z-50">
                <img src="https://cdn.ivanaldorino.web.id/narrasheet/narrasheet_long.png" alt="Narrasheet" class="h-8 md:h-10 w-auto">
            </a>

            <button id="mobile-menu-btn" class="md:hidden z-50 text-slate-600 focus:outline-none p-2 rounded-lg hover:bg-gray-100">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"></path></svg>
            </button>

            <div class="hidden md:flex items-center gap-6">
                <a href="dataset" class="text-sm font-bold text-blue-600 font-heading">Dataset</a>
                <a href="paper" class="text-sm font-medium text-slate-600 hover:text-blue-600 transition font-heading">Paper</a>
                <a href="tags" class="text-sm font-medium text-slate-600 hover:text-blue-600 transition font-heading">Tags</a>
                <div class="h-8 w-[1px] bg-gray-200 mx-2 block"></div>
                <div class="flex items-center gap-3">
                    <img src="<?php echo !empty($user['profile_pic']) ? $user['profile_pic'] : 'https://ui-avatars.com/api/?name='.urlencode($user['username']); ?>" class="w-9 h-9 rounded-full border border-gray-200 object-cover shadow-sm">
                    <a href="logout.php" class="text-sm font-medium text-red-500 hover:text-red-600 font-heading">Logout</a>
                </div>
            </div>
        </div>

        <div id="mobile-menu" class="hidden md:hidden absolute top-full left-0 w-full bg-white border-b border-gray-100 shadow-xl py-4 px-6 flex flex-col gap-4">
            <a href="dataset" class="text-base font-bold text-blue-600 py-2 border-b border-gray-50">Dataset</a>
            <a href="paper" class="text-base font-medium text-slate-600 py-2 border-b border-gray-50">Paper</a>
            <a href="tags" class="text-base font-medium text-slate-600 py-2 border-b border-gray-50">Tags</a>
            <div class="flex items-center gap-3 pt-2">
                <img src="<?php echo !empty($user['profile_pic']) ? $user['profile_pic'] : 'https://ui-avatars.com/api/?name='.urlencode($user['username']); ?>" class="w-10 h-10 rounded-full border border-gray-200 object-cover">
                <div class="flex flex-col">
                    <span class="text-sm font-bold text-slate-800"><?php echo htmlspecialchars($user['username']); ?></span>
                    <a href="logout.php" class="text-xs font-bold text-red-500">Logout</a>
                </div>
            </div>
        </div>
    </nav>

    <div class="container mx-auto px-6 py-10">
        
        <div class="flex flex-col md:flex-row justify-between items-start md:items-center mb-8 gap-4">
            <div>
                <h1 class="text-3xl font-bold text-slate-900 font-heading">Dataset</h1>
                <p class="text-slate-500 text-sm mt-1 font-body">Kelola koleksi dataset penelitian Anda.</p>
            </div>
            
            <div class="flex flex-wrap gap-3 w-full md:w-auto items-center">
                <div class="relative group">
                    <button class="flex items-center gap-2 bg-white border border-gray-200 text-slate-600 px-4 py-2.5 rounded-xl text-sm font-bold hover:bg-gray-50 transition shadow-sm">
                        <svg class="w-5 h-5 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 4h13M3 8h9m-9 4h6m4 0l4-4m0 0l4 4m-4-4v12"></path></svg>
                        <span>Urutkan</span>
                        <svg class="w-4 h-4 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path></svg>
                    </button>
                    <div class="absolute right-0 top-full mt-2 w-48 bg-white border border-gray-100 rounded-xl shadow-xl opacity-0 invisible group-hover:opacity-100 group-hover:visible transition-all duration-200 z-50 overflow-hidden transform origin-top-right">
                        <a href="?sort=newest" class="block px-4 py-3 text-sm text-slate-600 hover:bg-blue-50 hover:text-blue-600 font-medium <?php echo ($sortOption == 'newest') ? 'bg-blue-50 text-blue-600' : ''; ?>">Terbaru Ditambahkan</a>
                        <a href="?sort=oldest" class="block px-4 py-3 text-sm text-slate-600 hover:bg-blue-50 hover:text-blue-600 font-medium <?php echo ($sortOption == 'oldest') ? 'bg-blue-50 text-blue-600' : ''; ?>">Terlama Ditambahkan</a>
                        <a href="?sort=title_az" class="block px-4 py-3 text-sm text-slate-600 hover:bg-blue-50 hover:text-blue-600 font-medium <?php echo ($sortOption == 'title_az') ? 'bg-blue-50 text-blue-600' : ''; ?>">Nama (A-Z)</a>
                        <a href="?sort=platform" class="block px-4 py-3 text-sm text-slate-600 hover:bg-blue-50 hover:text-blue-600 font-medium <?php echo ($sortOption == 'platform') ? 'bg-blue-50 text-blue-600' : ''; ?>">Platform (A-Z)</a>
                    </div>
                </div>

                <button onclick="openArchiveModal()" class="flex-1 md:flex-none justify-center group flex items-center gap-2 bg-amber-50 hover:bg-amber-100 text-amber-700 border border-amber-200 px-5 py-2.5 rounded-xl transition shadow-sm hover:shadow-md hover:-translate-y-0.5">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 8h14M5 8a2 2 0 110-4h14a2 2 0 110 4M5 8v10a2 2 0 002 2h10a2 2 0 002-2V8m-9 4h4"></path></svg>
                    <span class="font-bold text-sm font-heading">Arsip (<?php echo $result_archive->num_rows; ?>)</span>
                </button>

                <button onclick="openInputModal()" class="flex-1 md:flex-none justify-center group flex items-center gap-2 bg-slate-900 hover:bg-blue-600 text-white px-5 py-2.5 rounded-xl transition shadow-lg shadow-slate-900/20 hover:shadow-blue-600/30 hover:-translate-y-0.5">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path></svg>
                    <span class="font-bold text-sm font-heading">Tambah</span>
                </button>
            </div>
        </div>

        <?php if(isset($_GET['success'])): ?>
            <?php 
                $msg = match($_GET['success']) { 'update' => 'Dataset berhasil diperbarui!', 'delete' => 'Dataset telah dihapus permanen.', 'archive' => 'Dataset berhasil dipindahkan ke arsip.', 'restore' => 'Dataset berhasil dipulihkan ke daftar aktif.', default => 'Dataset berhasil disimpan!' };
                $color = ($_GET['success'] == 'delete') ? 'text-red-700 bg-red-100 border-red-200' : 'text-green-700 bg-green-100 border-green-200';
            ?>
            <div id="successAlert" class="<?php echo $color; ?> border px-4 py-3 rounded-xl text-sm font-medium mb-6 flex items-center gap-2 transition-opacity duration-500 ease-out opacity-100 font-body">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path></svg><?php echo $msg; ?>
            </div>
        <?php endif; ?>

        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-6">
            <?php if($result_active->num_rows > 0): ?>
                <?php while($row = $result_active->fetch_assoc()): ?>
                    <?php renderCard($row, 'active'); ?>
                <?php endwhile; ?>
            <?php else: ?>
                <div class="col-span-full py-12 text-center border-2 border-dashed border-gray-200 rounded-2xl">
                    <p class="text-slate-400 mb-2 font-mono text-sm">Belum ada dataset aktif.</p>
                    <button onclick="openInputModal()" class="text-blue-600 font-bold hover:underline font-mono text-sm">Tambah Sekarang</button>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <?php
    function renderCard($row, $status) {
        $icon_url = match($row['jenis_dataset']) {
            'kaggle' => 'https://cdn.simpleicons.org/kaggle/20BEFF',
            'huggingface' => 'https://cdn.simpleicons.org/huggingface/FFD21E',
            'openml' => 'https://cdn.ivanaldorino.web.id/narrasheet/openml.svg',
            default => null
        };
        $js_id = $row['id'];
        $js_nama = htmlspecialchars($row['nama_dataset'], ENT_QUOTES);
        $js_link = htmlspecialchars($row['link_dataset'], ENT_QUOTES);
        $js_jenis = htmlspecialchars($row['jenis_dataset'], ENT_QUOTES);
        $js_tags = htmlspecialchars($row['tag_list'] ?? '', ENT_QUOTES);

        $opacityClass = ($status == 'archived') ? 'opacity-75 grayscale hover:grayscale-0 hover:opacity-100' : '';
        
        $tags_html = '';
        if (!empty($row['tag_list'])) {
            $tags_arr = explode(',', $row['tag_list']);
            $count = 0;
            foreach($tags_arr as $t) {
                if($count < 3) $tags_html .= '<span class="inline-block bg-blue-50 text-blue-600 text-[10px] px-2 py-1 rounded-md font-bold uppercase tracking-wide mr-1 mb-1 border border-blue-100">'.htmlspecialchars(trim($t)).'</span>';
                $count++;
            }
            if(count($tags_arr) > 3) $tags_html .= '<span class="inline-block text-[10px] text-gray-400 font-bold px-1">+ '.(count($tags_arr)-3).'</span>';
        }
        ?>
        <div onclick="showDetail('<?php echo $js_id; ?>', '<?php echo $js_nama; ?>', '<?php echo $js_link; ?>', '<?php echo $js_jenis; ?>', '<?php echo $status; ?>', '<?php echo $js_tags; ?>')" 
             class="bg-white rounded-2xl border border-gray-100 shadow-sm hover:shadow-xl hover:shadow-blue-900/5 transition duration-300 group flex flex-col justify-between h-full p-4 font-mono cursor-pointer hover:-translate-y-1 <?php echo $opacityClass; ?>">
            <div class="flex justify-between items-start gap-3 mb-1">
                <h3 class="font-bold text-slate-800 text-lg leading-tight break-words tracking-tight group-hover:text-blue-600 transition">
                    <?php echo htmlspecialchars($row['nama_dataset']); ?>
                </h3>
                <div class="w-8 h-8 rounded-lg flex items-center justify-center bg-gray-50 border border-gray-100 p-1.5 shrink-0">
                    <?php if($icon_url): ?>
                        <img src="<?php echo $icon_url; ?>" alt="Icon" class="w-full h-full object-contain">
                    <?php else: ?>
                        <svg class="w-5 h-5 text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 21h10a2 2 0 002-2V9.414a1 1 0 00-.293-.707l-5.414-5.414A1 1 0 0012.586 3H7a2 2 0 00-2 2v14a2 2 0 002 2z"></path></svg>
                    <?php endif; ?>
                </div>
            </div>
            <div class="mb-3">
                <span class="text-xs text-slate-400 truncate block break-all"><?php echo htmlspecialchars($row['link_dataset']); ?></span>
            </div>
            
            <div class="flex flex-wrap gap-1 mb-2">
               <?php echo $tags_html; ?>
            </div>

            <div class="pt-3 border-t border-gray-50 mt-auto flex justify-between items-center">
                <span class="text-xs text-slate-400 block"><?php echo date('d M Y', strtotime($row['created_at'])); ?></span>
                <span class="text-[10px] bg-gray-100 text-gray-500 px-2 py-0.5 rounded">Detail</span>
            </div>
        </div>
        <?php
    }
    ?>

    <?php include 'custompopup.php'; ?>

    <div id="sourceArchived" class="hidden">
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-6">
            <?php if($result_archive->num_rows > 0): ?>
                <?php while($row = $result_archive->fetch_assoc()): ?>
                    <?php renderCard($row, 'archived'); ?>
                <?php endwhile; ?>
            <?php else: ?>
                <div class="col-span-full py-12 text-center">
                    <div class="inline-block p-4 rounded-full bg-slate-50 mb-3 text-slate-300">
                        <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 8h14M5 8a2 2 0 110-4h14a2 2 0 110 4M5 8v10a2 2 0 002 2h10a2 2 0 002-2V8m-9 4h4"></path></svg>
                    </div>
                    <p class="text-slate-400 font-mono text-sm">Tidak ada dataset yang diarsipkan.</p>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <div id="formSource" class="hidden">
        <form method="POST" action="" class="space-y-4 text-left" id="datasetForm">
            <input type="hidden" name="dataset_id" id="inputId" value="">
            <input type="hidden" name="action_type" id="inputAction" value="create">

            <div>
                <label class="block text-slate-500 text-xs font-bold uppercase tracking-wider mb-2 font-heading">URL Link</label>
                <input type="url" id="inputUrl" name="link_dataset" placeholder="https://..." required class="w-full px-4 py-3 bg-gray-50 border border-gray-200 rounded-xl focus:outline-none focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500 transition text-sm font-body">
                <p class="text-[10px] text-gray-400 mt-1 font-body">Paste link Kaggle/HuggingFace untuk isi otomatis.</p>
            </div>
            <div class="border-b border-gray-100 my-2"></div>
            <div>
                <label class="block text-slate-500 text-xs font-bold uppercase tracking-wider mb-2 font-heading">Nama Dataset</label>
                <input type="text" id="inputNama" name="nama_dataset" required class="w-full px-4 py-3 bg-gray-50 border border-gray-200 rounded-xl focus:outline-none focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500 transition text-sm font-body">
            </div>
            
            <div>
                <label class="block text-slate-500 text-xs font-bold uppercase tracking-wider mb-2 font-heading">Tags / Kategori</label>
                <input type="text" id="inputTags" name="tags" class="w-full px-4 py-3 bg-gray-50 border border-gray-200 rounded-xl text-sm font-body" placeholder="Ketik tag dan Enter...">
            </div>

            <div>
                <label class="block text-slate-500 text-xs font-bold uppercase tracking-wider mb-2 font-heading">Platform</label>
                <div class="relative">
                    <select id="inputJenis" name="jenis_dataset" required class="w-full px-4 py-3 bg-gray-50 border border-gray-200 rounded-xl focus:outline-none focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500 transition appearance-none cursor-pointer text-sm font-body">
                        <option value="lainnya">Lainnya</option>
                        <option value="kaggle">Kaggle</option>
                        <option value="huggingface">Hugging Face</option>
                        <option value="openml">OpenML</option>
                    </select>
                    <div class="absolute inset-y-0 right-0 flex items-center px-4 pointer-events-none text-gray-500">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path></svg>
                    </div>
                </div>
            </div>
            <div id="btnContainer" class="pt-2">
                <button type="submit" id="btnSubmit" class="w-full bg-blue-600 text-white font-bold py-3 rounded-xl hover:bg-blue-700 transition shadow-lg shadow-blue-600/20 text-sm font-heading">Simpan Data</button>
            </div>
        </form>
    </div>

    <script>
        // --- NAVBAR TOGGLE ---
        const btn = document.getElementById('mobile-menu-btn');
        const menu = document.getElementById('mobile-menu');
        btn.addEventListener('click', () => { menu.classList.toggle('hidden'); });

        document.addEventListener('DOMContentLoaded', function() {
            const successAlert = document.getElementById('successAlert');
            if (successAlert) {
                setTimeout(() => { successAlert.classList.remove('opacity-100'); successAlert.classList.add('opacity-0'); setTimeout(() => { successAlert.remove(); window.history.replaceState(null, null, window.location.pathname); }, 500); }, 3000);
            }
        });

        // --- TAGIFY LOGIC START ---
        let tagifyInstance = null;

        function initTagify() {
            const input = document.querySelector('#inputTags');
            if (!input) return;

            fetch('dataset.php?action_type=get_tags')
                .then(response => response.json())
                .then(whitelist => {
                    if (tagifyInstance) tagifyInstance.destroy(); 
                    
                    tagifyInstance = new Tagify(input, {
                        whitelist: whitelist, 
                        enforceWhitelist: false, 
                        dropdown: {
                            maxItems: 20,           
                            classname: "tags-look", 
                            enabled: 0,             
                            closeOnSelect: false    
                        }
                    });
                })
                .catch(err => console.error("Gagal load tags:", err));
        }
        // --- TAGIFY LOGIC END ---

        function openArchiveModal() {
            const archiveContent = document.getElementById('sourceArchived').innerHTML;
            const modalTarget = document.getElementById('modalContent');
            const headerActions = document.getElementById('modalHeaderActions');
            const modalPanel = document.getElementById('modalPanel');
            if(headerActions) headerActions.innerHTML = '';
            modalPanel.classList.remove('sm:max-w-lg'); modalPanel.classList.add('sm:max-w-6xl');
            modalTarget.innerHTML = archiveContent;
            openModal('Arsip Dataset');
        }

        function resetModalSize() {
            const modalPanel = document.getElementById('modalPanel');
            modalPanel.classList.remove('sm:max-w-6xl'); modalPanel.classList.add('sm:max-w-lg');
        }

        function toTitleCase(str) { return str.replace(/\w\S*/g, function(txt) { return txt.charAt(0).toUpperCase() + txt.substr(1).toLowerCase(); }); }

        function setupAutoFillListener(modalTarget) {
            const inputUrl = modalTarget.querySelector('#inputUrl');
            const inputNama = modalTarget.querySelector('#inputNama');
            const inputJenis = modalTarget.querySelector('#inputJenis');
            if (inputUrl) {
                inputUrl.oninput = function() {
                    const val = this.value.trim();
                    let slug = ''; let platform = '';
                    if (val.includes('kaggle.com/datasets/')) { const parts = val.replace(/\/$/, '').split('/'); slug = parts[parts.length - 1]; platform = 'kaggle'; }
                    else if (val.includes('huggingface.co/datasets/')) { const parts = val.replace(/\/$/, '').split('/'); slug = parts[parts.length - 1]; platform = 'huggingface'; }
                    if (slug && platform) { inputNama.value = toTitleCase(slug.replace(/[-_]/g, ' ')); inputJenis.value = platform; }
                };
            }
        }

        function submitArchive(id) {
            if(confirm('Arsipkan dataset ini?')) {
                const form = document.createElement('form'); form.method = 'POST'; form.action = '';
                const inputId = document.createElement('input'); inputId.type = 'hidden'; inputId.name = 'dataset_id'; inputId.value = id;
                const inputAction = document.createElement('input'); inputAction.type = 'hidden'; inputAction.name = 'action_type'; inputAction.value = 'archive';
                form.appendChild(inputId); form.appendChild(inputAction); document.body.appendChild(form); form.submit();
            }
        }

        function submitRestore(id) {
            if(confirm('Pulihkan dataset ini ke daftar aktif?')) {
                const form = document.createElement('form'); form.method = 'POST'; form.action = '';
                const inputId = document.createElement('input'); inputId.type = 'hidden'; inputId.name = 'dataset_id'; inputId.value = id;
                const inputAction = document.createElement('input'); inputAction.type = 'hidden'; inputAction.name = 'action_type'; inputAction.value = 'restore';
                form.appendChild(inputId); form.appendChild(inputAction); document.body.appendChild(form); form.submit();
            }
        }

        function confirmDelete(id, nama, link, jenis) {
            const headerActions = document.getElementById('modalHeaderActions');
            if(headerActions) headerActions.innerHTML = ''; 
            const safeNama = nama.replace(/'/g, "\\'"); const safeLink = link.replace(/'/g, "\\'");
            const confirmHtml = `<div class="text-center p-4"><div class="w-16 h-16 bg-red-100 rounded-full flex items-center justify-center mx-auto mb-4"><svg class="w-8 h-8 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path></svg></div><h3 class="text-xl font-bold text-slate-800 mb-2 font-heading">Hapus Permanen?</h3><p class="text-sm text-slate-500 mb-6 font-body">Yakin hapus <strong>${nama}</strong>?</p><div class="flex gap-3 justify-center"><button onclick="editDataset('${id}', '${safeNama}', '${safeLink}', '${jenis}', '')" class="px-5 py-2.5 bg-gray-100 text-slate-600 rounded-xl font-bold text-sm">Batal</button><button onclick="submitDelete('${id}')" class="px-5 py-2.5 bg-red-600 text-white rounded-xl font-bold text-sm">Ya, Hapus</button></div></div>`;
            document.getElementById('modalContent').innerHTML = confirmHtml;
            openModal('Konfirmasi Hapus');
        }

        function submitDelete(id) {
            const form = document.createElement('form'); form.method = 'POST'; form.action = '';
            const inputId = document.createElement('input'); inputId.type = 'hidden'; inputId.name = 'dataset_id'; inputId.value = id;
            const inputAction = document.createElement('input'); inputAction.type = 'hidden'; inputAction.name = 'action_type'; inputAction.value = 'delete';
            form.appendChild(inputId); form.appendChild(inputAction); document.body.appendChild(form); form.submit();
        }

        function openInputModal() {
            resetModalSize(); 
            const formContent = document.getElementById('formSource').innerHTML;
            const modalTarget = document.getElementById('modalContent');
            const headerActions = document.getElementById('modalHeaderActions');
            if(headerActions) headerActions.innerHTML = ''; 
            if(modalTarget) {
                modalTarget.innerHTML = formContent;
                setupAutoFillListener(modalTarget);
                modalTarget.querySelector('#btnContainer').innerHTML = `<button type="submit" id="btnSubmit" class="w-full bg-blue-600 text-white font-bold py-3 rounded-xl hover:bg-blue-700 transition shadow-lg shadow-blue-600/20 text-sm font-heading">Simpan Data</button>`;
            }
            // Init Tagify
            setTimeout(initTagify, 100);

            openModal('Tambah Dataset Baru');
        }

        function editDataset(id, nama, link, jenis, tags) {
            resetModalSize();
            const formContent = document.getElementById('formSource').innerHTML;
            const modalTarget = document.getElementById('modalContent');
            const headerActions = document.getElementById('modalHeaderActions');
            if(headerActions) headerActions.innerHTML = '';
            if(modalTarget) {
                modalTarget.innerHTML = formContent;
                modalTarget.querySelector('#inputId').value = id;
                modalTarget.querySelector('#inputAction').value = 'update';
                modalTarget.querySelector('#inputUrl').value = link;
                modalTarget.querySelector('#inputNama').value = nama;
                modalTarget.querySelector('#inputJenis').value = jenis;
                
                // Set value tags
                modalTarget.querySelector('#inputTags').value = tags;
                
                const safeNama = nama.replace(/'/g, "\\'"); const safeLink = link.replace(/'/g, "\\'");
                modalTarget.querySelector('#btnContainer').className = "pt-2 flex gap-3";
                modalTarget.querySelector('#btnContainer').innerHTML = `<button type="button" onclick="confirmDelete('${id}', '${safeNama}', '${safeLink}', '${jenis}')" class="w-1/3 bg-red-100 text-red-600 font-bold py-3 rounded-xl text-sm font-heading">Hapus</button><button type="submit" class="w-2/3 bg-blue-600 text-white font-bold py-3 rounded-xl text-sm font-heading">Update Data</button>`;
                setupAutoFillListener(modalTarget);
            }
            
            // Init Tagify
            setTimeout(initTagify, 100);
            
            openModal('Edit Dataset');
        }

        function copyCode(btn) {
            const codeContainer = btn.nextElementSibling;
            if (codeContainer) navigator.clipboard.writeText(codeContainer.innerText).then(() => { const originalIcon = btn.innerHTML; btn.innerHTML = `<svg class="w-4 h-4 text-green-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path></svg>`; setTimeout(() => { btn.innerHTML = originalIcon; }, 2000); });
        }

        function showDetail(id, nama, link, jenis, status, tags) {
            resetModalSize(); 
            let caraPakaiHtml = '';
            const headerActions = document.getElementById('modalHeaderActions');
            const safeNama = nama.replace(/'/g, "\\'"); const safeLink = link.replace(/'/g, "\\'"); const safeTags = tags.replace(/'/g, "\\'");
            
            if(headerActions) {
                if (status === 'active') {
                    headerActions.innerHTML = `<button onclick="submitArchive('${id}')" class="p-2 text-slate-400 hover:text-amber-600 transition rounded-full hover:bg-amber-50" title="Arsipkan"><svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 8h14M5 8a2 2 0 110-4h14a2 2 0 110 4M5 8v10a2 2 0 002 2h10a2 2 0 002-2V8m-9 4h4"></path></svg></button><button onclick="editDataset('${id}', '${safeNama}', '${safeLink}', '${jenis}', '${safeTags}')" class="p-2 text-slate-400 hover:text-blue-600 transition rounded-full hover:bg-blue-50" title="Edit Dataset"><svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z"></path></svg></button>`;
                } else {
                    headerActions.innerHTML = `<button onclick="submitRestore('${id}')" class="p-2 text-slate-400 hover:text-green-600 transition rounded-full hover:bg-green-50" title="Pulihkan"><svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h10a8 8 0 018 8v2M3 10l6 6m-6-6l6-6"></path></svg></button><button onclick="editDataset('${id}', '${safeNama}', '${safeLink}', '${jenis}', '${safeTags}')" class="p-2 text-slate-400 hover:text-blue-600 transition rounded-full hover:bg-blue-50" title="Edit Dataset"><svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z"></path></svg></button>`;
                }
            }

            if (jenis === 'kaggle') {
                const match = link.match(/kaggle\.com\/datasets\/([^\/]+\/[^\/?]+)/);
                if (match && match[1]) {
                    const datasetId = match[1];
                    caraPakaiHtml = `<div class="mt-6 pt-4 border-t border-gray-100"><h4 class="text-xs font-bold uppercase tracking-wider text-slate-500 mb-2 font-heading">Cara Menggunakan (KaggleHub)</h4><div class="relative group bg-slate-900 rounded-xl overflow-hidden"><button onclick="copyCode(this)" class="absolute top-2 right-2 bg-white/10 hover:bg-white/20 text-slate-300 p-1.5 rounded-lg opacity-0 group-hover:opacity-100 transition-opacity" title="Copy Code"><svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 5H6a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2v-1M8 5a2 2 0 002 2h2a2 2 0 002-2M8 5a2 2 0 012-2h2a2 2 0 012 2m0 0h2a2 2 0 012 2v3m2 4H10m0 0l3-3m-3 3l3 3"></path></svg></button><pre class="p-4 text-slate-50 text-xs font-mono overflow-x-auto leading-relaxed"><code>import kagglehub\n# Download latest version\npath = kagglehub.dataset_download("${datasetId}")\nprint("Path to dataset files:", path)</code></pre></div></div>`;
                }
            } else if (jenis === 'huggingface') {
                const match = link.match(/huggingface\.co\/datasets\/([^\/]+\/[^\/?]+)/);
                if (match && match[1]) {
                    const datasetId = match[1];
                    caraPakaiHtml = `<div class="mt-6 pt-4 border-t border-gray-100"><h4 class="text-xs font-bold uppercase tracking-wider text-slate-500 mb-2 font-heading">Cara Menggunakan (Hugging Face)</h4><div class="relative group bg-slate-900 rounded-xl overflow-hidden"><button onclick="copyCode(this)" class="absolute top-2 right-2 bg-white/10 hover:bg-white/20 text-slate-300 p-1.5 rounded-lg opacity-0 group-hover:opacity-100 transition-opacity" title="Copy Code"><svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 5H6a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2v-1M8 5a2 2 0 002 2h2a2 2 0 002-2M8 5a2 2 0 012-2h2a2 2 0 012 2m0 0h2a2 2 0 012 2v3m2 4H10m0 0l3-3m-3 3l3 3"></path></svg></button><pre class="p-4 text-slate-5 text-xs font-mono overflow-x-auto leading-relaxed"><code>from datasets import load_dataset\ndataset = load_dataset("${datasetId}")\nprint(dataset)</code></pre></div></div>`;
                }
            }
            
            // Generate HTML for tags in detail view
            let tagsDetailHtml = '';
            if(tags) {
                // PERBAIKAN DI SINI: Hapus spasi setelah koma
                const tagsArr = tags.split(','); 
                
                tagsDetailHtml = '<div class="flex flex-wrap gap-2 mt-2">';
                tagsArr.forEach(t => {
                     // Tambahkan .trim() untuk membersihkan spasi yang mungkin tersisa
                     tagsDetailHtml += `<span class="bg-blue-50 text-blue-600 text-xs px-2.5 py-1 rounded-md font-bold uppercase tracking-wide border border-blue-100">${t.trim()}</span>`;
                });
                tagsDetailHtml += '</div>';
            }

            const detailHtml = `<div class="text-left space-y-5"><div><label class="block text-slate-500 text-[10px] font-bold uppercase tracking-wider mb-1 font-heading">Nama Dataset</label><p class="text-slate-800 font-medium text-lg leading-tight break-words font-body">${nama}</p></div><div><label class="block text-slate-500 text-[10px] font-bold uppercase tracking-wider mb-1 font-heading">Link Dataset</label><a href="${link}" target="_blank" class="text-blue-600 hover:underline text-sm break-all flex items-center gap-1 font-body">${link}<svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"></path></svg></a></div><div><label class="block text-slate-500 text-[10px] font-bold uppercase tracking-wider mb-1 font-heading">Tags</label>${tagsDetailHtml}</div>${caraPakaiHtml}</div>`;
            document.getElementById('modalContent').innerHTML = detailHtml;
            openModal('Informasi Dataset');
        }
    </script>

</body>
</html>