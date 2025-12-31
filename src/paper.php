<?php
session_start();
require_once 'config.php';
if (file_exists('upload_helper.php')) {
    require_once 'upload_helper.php';
}

if (!isset($_SESSION['user'])) {
    header("Location: login.php");
    exit();
}

$user = $_SESSION['user'];
$email = $user['email'] ?? 'unknown';
$username = $user['username'] ?? 'user';

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

// --- 2. HELPER: HANDLE TAGS ---
function handleTags($conn, $itemId, $tagsJson, $userEmail, $type = 'paper') {
    $tableRel = ($type === 'paper') ? 'paper_tags' : 'dataset_tags';
    $colID = ($type === 'paper') ? 'paper_id' : 'dataset_id';

    $stmt_del = $conn->prepare("DELETE FROM $tableRel WHERE $colID = ?");
    $stmt_del->bind_param("i", $itemId);
    $stmt_del->execute();

    if (empty($tagsJson)) return;

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

        $stmt_check = $conn->prepare("SELECT id FROM tags WHERE tag_name = ? AND user_email = ?");
        $stmt_check->bind_param("ss", $tagName, $userEmail);
        $stmt_check->execute();
        $res = $stmt_check->get_result();

        if ($res->num_rows > 0) {
            $tagId = $res->fetch_assoc()['id'];
        } else {
            $stmt_ins = $conn->prepare("INSERT INTO tags (user_email, tag_name) VALUES (?, ?)");
            $stmt_ins->bind_param("ss", $userEmail, $tagName);
            $stmt_ins->execute();
            $tagId = $stmt_ins->insert_id;
        }

        $stmt_link = $conn->prepare("INSERT INTO $tableRel ($colID, tag_id) VALUES (?, ?)");
        $stmt_link->bind_param("ii", $itemId, $tagId);
        $stmt_link->execute();
    }
}

// --- 3. HANDLE AJAX UPLOAD ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_type']) && $_POST['action_type'] === 'ajax_upload') {
    header('Content-Type: application/json');
    
    if (isset($_FILES['pdf_file']) && $_FILES['pdf_file']['error'] === UPLOAD_ERR_OK) {
        if (function_exists('uploadToMinio')) {
            $upload = uploadToMinio($_FILES['pdf_file'], $username);
            
            if ($upload['status']) {
                $meta = function_exists('extractPdfData') ? extractPdfData($upload['tmp_path'] ?? $_FILES['pdf_file']['tmp_name']) : [];
                
                echo json_encode([
                    'status'    => true,
                    'url'       => $upload['url'],
                    'real_name' => $_FILES['pdf_file']['name'],
                    'title'     => $meta['title'] ?? '',
                    'author'    => $meta['author'] ?? '',
                    'doi'       => $meta['doi'] ?? '',
                    'journal'   => $meta['journal'] ?? '',
                    'year'      => $meta['year'] ?? '',
                    'publisher' => $meta['publisher'] ?? ''
                ]);
            } else {
                echo json_encode(['status' => false, 'message' => $upload['message']]);
            }
        } else {
            echo json_encode(['status' => false, 'message' => 'Helper upload error.']);
        }
    } else {
        echo json_encode(['status' => false, 'message' => 'Gagal menerima file.']);
    }
    exit();
}

// --- 4. LOGIC CRUD ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action_type'] ?? 'create';
    
    if ($action === 'delete') {
        $id = $_POST['paper_id'];
        $stmt = $conn->prepare("DELETE FROM papers WHERE id=? AND user_email=?");
        $stmt->bind_param("is", $id, $email);
        if ($stmt->execute()) header("Location: paper.php?success=delete");
        exit();
    }
    elseif ($action === 'archive') {
        $id = $_POST['paper_id'];
        $stmt = $conn->prepare("UPDATE papers SET status='archived' WHERE id=? AND user_email=?");
        $stmt->bind_param("is", $id, $email);
        if ($stmt->execute()) header("Location: paper.php?success=archive");
        exit();
    }
    elseif ($action === 'restore') {
        $id = $_POST['paper_id'];
        $stmt = $conn->prepare("UPDATE papers SET status='active' WHERE id=? AND user_email=?");
        $stmt->bind_param("is", $id, $email);
        if ($stmt->execute()) header("Location: paper.php?success=restore");
        exit();
    }
    elseif ($action === 'update') {
        $id = $_POST['paper_id'];
        $doi = $_POST['doi'];
        $title = $_POST['title'];
        $author = $_POST['author'];
        $link_paper = $_POST['link_paper'];
        $link_upload = $_POST['link_upload_final'];
        $real_name_file = $_POST['real_name_file'];
        $journal = $_POST['journal_name'];
        $year = $_POST['publish_year'];
        $publisher = $_POST['publisher'];
        $tags = $_POST['tags'] ?? ''; 
        
        $stmt_old = $conn->prepare("SELECT link_upload, real_name_file FROM papers WHERE id=?");
        $stmt_old->bind_param("i", $id);
        $stmt_old->execute();
        $res_old = $stmt_old->get_result()->fetch_assoc();
        
        if (empty($link_upload)) {
            $link_upload = $res_old['link_upload'];
            $real_name_file = $res_old['real_name_file'];
        }

        $stmt = $conn->prepare("UPDATE papers SET title=?, author=?, doi=?, link_paper=?, link_upload=?, real_name_file=?, journal_name=?, publish_year=?, publisher=? WHERE id=? AND user_email=?");
        $stmt->bind_param("sssssssssis", $title, $author, $doi, $link_paper, $link_upload, $real_name_file, $journal, $year, $publisher, $id, $email);
        
        if ($stmt->execute()) {
            handleTags($conn, $id, $tags, $email, 'paper'); 
            header("Location: paper.php?success=update");
        }
        exit();
    }
    else {
        $doi = $_POST['doi'];
        $title = $_POST['title'];
        $author = $_POST['author'];
        $link_paper = $_POST['link_paper'];
        $link_upload = $_POST['link_upload_final'];
        $real_name_file = $_POST['real_name_file'];
        $journal = $_POST['journal_name'];
        $year = $_POST['publish_year'];
        $publisher = $_POST['publisher'];
        $tags = $_POST['tags'] ?? ''; 
        
        if (empty($doi) && empty($link_paper) && empty($link_upload)) {
             header("Location: paper.php?error=" . urlencode("Harap isi minimal satu: DOI, Upload PDF, atau Link Jurnal."));
             exit();
        }

        if (empty($title)) $title = "Untitled Paper";
        if (empty($author)) $author = "Unknown Author";

        $stmt = $conn->prepare("INSERT INTO papers (user_email, title, author, doi, link_paper, link_upload, real_name_file, journal_name, publish_year, publisher, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'active')");
        $stmt->bind_param("ssssssssss", $email, $title, $author, $doi, $link_paper, $link_upload, $real_name_file, $journal, $year, $publisher);
        
        if ($stmt->execute()) {
            $new_id = $stmt->insert_id;
            handleTags($conn, $new_id, $tags, $email, 'paper'); 
            header("Location: paper.php?success=create");
        }
        exit();
    }
}

// --- 5. AMBIL DATA ---
$sql_base = "SELECT p.*, GROUP_CONCAT(t.tag_name) as tag_list 
             FROM papers p 
             LEFT JOIN paper_tags pt ON p.id = pt.paper_id 
             LEFT JOIN tags t ON pt.tag_id = t.id 
             WHERE p.user_email = ? AND p.status = ? 
             GROUP BY p.id ORDER BY p.created_at DESC";

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
    <title>Paper - Narrasheet</title>
    
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
                <a href="dataset" class="text-sm font-medium text-slate-600 hover:text-blue-600 transition font-heading">Dataset</a>
                <a href="paper" class="text-sm font-bold text-blue-600 font-heading">Paper</a>
                <a href="tags" class="text-sm font-medium text-slate-600 hover:text-blue-600 transition font-heading">Tags</a>
                <div class="h-8 w-[1px] bg-gray-200 mx-2 block"></div>
                <div class="flex items-center gap-3">
                    <img src="<?php echo !empty($user['profile_pic']) ? $user['profile_pic'] : 'https://ui-avatars.com/api/?name='.urlencode($user['username']); ?>" class="w-9 h-9 rounded-full border border-gray-200 object-cover shadow-sm">
                    <a href="logout.php" class="text-sm font-medium text-red-500 hover:text-red-600 font-heading">Logout</a>
                </div>
            </div>
        </div>

        <div id="mobile-menu" class="hidden md:hidden absolute top-full left-0 w-full bg-white border-b border-gray-100 shadow-xl py-4 px-6 flex flex-col gap-4">
            <a href="dataset" class="text-base font-medium text-slate-600 py-2 border-b border-gray-50">Dataset</a>
            <a href="paper" class="text-base font-bold text-blue-600 py-2 border-b border-gray-50">Paper</a>
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
                <h1 class="text-3xl font-bold text-slate-900 font-heading">Paper & Jurnal</h1>
                <p class="text-slate-500 text-sm mt-1 font-body">Koleksi referensi penelitian dan literatur.</p>
            </div>
            
            <div class="flex gap-3 w-full md:w-auto">
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

        <?php if(isset($_GET['error'])): ?>
            <div id="errorAlert" class="bg-red-100 border border-red-200 text-red-700 px-4 py-3 rounded-xl text-sm font-medium mb-6 flex items-center gap-2 transition-opacity duration-500 ease-out opacity-100 font-body"><svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg><?php echo htmlspecialchars($_GET['error']); ?></div>
        <?php endif; ?>

        <?php if(isset($_GET['success'])): ?>
            <?php 
                $msg = match($_GET['success']) { 'update' => 'Paper berhasil diperbarui!', 'delete' => 'Paper telah dihapus permanen.', 'archive' => 'Paper berhasil dipindahkan ke arsip.', 'restore' => 'Paper berhasil dipulihkan ke daftar aktif.', default => 'Paper berhasil disimpan!' };
                $color = ($_GET['success'] == 'delete') ? 'text-red-700 bg-red-100 border-red-200' : 'text-green-700 bg-green-100 border-green-200';
            ?>
            <div id="successAlert" class="<?php echo $color; ?> border px-4 py-3 rounded-xl text-sm font-medium mb-6 flex items-center gap-2 transition-opacity duration-500 ease-out opacity-100 font-body"><svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path></svg><?php echo $msg; ?></div>
        <?php endif; ?>

        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-6">
            <?php if($result_active->num_rows > 0): ?>
                <?php while($row = $result_active->fetch_assoc()): ?>
                    <?php renderCard($row, 'active'); ?>
                <?php endwhile; ?>
            <?php else: ?>
                <div class="col-span-full py-12 text-center border-2 border-dashed border-gray-200 rounded-2xl"><p class="text-slate-400 mb-2 font-mono text-sm">Belum ada paper.</p><button onclick="openInputModal()" class="text-blue-600 font-bold hover:underline font-mono text-sm">Tambah Sekarang</button></div>
            <?php endif; ?>
        </div>
    </div>

    <?php
    function renderCard($row, $status) {
        $js_id = $row['id']; 
        $js_title = htmlspecialchars($row['title'], ENT_QUOTES); 
        $js_author = htmlspecialchars($row['author'], ENT_QUOTES); 
        $js_doi = htmlspecialchars($row['doi'], ENT_QUOTES); 
        $js_link_paper = htmlspecialchars($row['link_paper'], ENT_QUOTES); 
        $js_link_upload = htmlspecialchars($row['link_upload'] ?? '', ENT_QUOTES); 
        $js_real_name = htmlspecialchars($row['real_name_file'] ?? basename($row['link_upload'] ?? ''), ENT_QUOTES);
        
        $js_journal = htmlspecialchars($row['journal_name'] ?? '', ENT_QUOTES);
        $js_year = htmlspecialchars($row['publish_year'] ?? '', ENT_QUOTES);
        $js_publisher = htmlspecialchars($row['publisher'] ?? '', ENT_QUOTES);
        $js_tags = htmlspecialchars($row['tag_list'] ?? '', ENT_QUOTES);

        $opacityClass = ($status == 'archived') ? 'opacity-75 grayscale hover:grayscale-0 hover:opacity-100' : '';
        
        $has_doi = !empty($row['doi']);
        $has_pdf = !empty($row['link_upload']);
        $has_link = !empty($row['link_paper']);

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
        <div onclick="showDetail('<?php echo $js_id; ?>', '<?php echo $js_title; ?>', '<?php echo $js_author; ?>', '<?php echo $js_doi; ?>', '<?php echo $js_link_paper; ?>', '<?php echo $js_link_upload; ?>', '<?php echo $js_real_name; ?>', '<?php echo $js_journal; ?>', '<?php echo $js_year; ?>', '<?php echo $js_publisher; ?>', '<?php echo $status; ?>', '<?php echo $js_tags; ?>')" 
             class="bg-white rounded-2xl border border-gray-100 shadow-sm hover:shadow-xl hover:shadow-blue-900/5 transition duration-300 group flex flex-col justify-between h-full p-4 font-mono cursor-pointer hover:-translate-y-1 <?php echo $opacityClass; ?>">
            <div class="flex justify-between items-start gap-3 mb-2">
                <h3 class="font-bold text-slate-800 text-lg leading-tight break-words tracking-tight group-hover:text-blue-600 transition flex-1 pr-2"><?php echo htmlspecialchars($row['title']); ?></h3>
                
                <div class="flex gap-1.5 shrink-0">
                    <?php if($has_doi): ?><div class="w-7 h-7 rounded-lg flex items-center justify-center bg-yellow-50 border border-yellow-100 text-yellow-600" title="DOI Available"><svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A1.994 1.994 0 013 12V7a4 4 0 014-4z"></path></svg></div><?php endif; ?>
                    <?php if($has_pdf): ?><div class="w-7 h-7 rounded-lg flex items-center justify-center bg-red-50 border border-red-100 text-red-600" title="PDF Uploaded"><svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path></svg></div><?php endif; ?>
                    <?php if($has_link): ?><div class="w-7 h-7 rounded-lg flex items-center justify-center bg-blue-50 border border-blue-100 text-blue-600" title="Link Available"><svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1"></path></svg></div><?php endif; ?>
                    <?php if(!$has_doi && !$has_pdf && !$has_link): ?><div class="w-7 h-7 rounded-lg flex items-center justify-center bg-gray-50 border border-gray-100 text-gray-400"><svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 21h10a2 2 0 002-2V9.414a1 1 0 00-.293-.707l-5.414-5.414A1 1 0 0012.586 3H7a2 2 0 00-2 2v14a2 2 0 002 2z"></path></svg></div><?php endif; ?>
                </div>
            </div>
            
            <div class="mb-3">
                <span class="text-xs text-slate-500 font-bold block mb-1"><?php echo htmlspecialchars($row['author']); ?></span>
                <?php if(!empty($row['journal_name'])): ?><span class="text-[10px] text-slate-400 block italic truncate"><?php echo htmlspecialchars($row['journal_name']); ?></span><?php endif; ?>
            </div>
            
            <div class="flex flex-wrap gap-1 mb-2">
                <?php echo $tags_html; ?>
            </div>

            <div class="pt-3 border-t border-gray-50 mt-auto flex justify-between items-center"><span class="text-xs text-slate-400 block"><?php echo date('d M Y', strtotime($row['created_at'])); ?></span><span class="text-[10px] bg-gray-100 text-gray-500 px-2 py-0.5 rounded">Detail</span></div>
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
                <div class="col-span-full py-12 text-center"><div class="inline-block p-4 rounded-full bg-slate-50 mb-3 text-slate-300"><svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 8h14M5 8a2 2 0 110-4h14a2 2 0 110 4M5 8v10a2 2 0 002 2h10a2 2 0 002-2V8m-9 4h4"></path></svg></div><p class="text-slate-400 font-mono text-sm">Tidak ada paper yang diarsipkan.</p></div>
            <?php endif; ?>
        </div>
    </div>

    <div id="formSource" class="hidden">
        <form method="POST" action="" enctype="multipart/form-data" class="space-y-4 text-left" id="paperForm">
            <input type="hidden" name="paper_id" id="inputId" value="">
            <input type="hidden" name="action_type" id="inputAction" value="create">
            <input type="hidden" name="link_upload_final" id="linkUploadFinal" value="">
            <input type="hidden" name="real_name_file" id="realNameFile" value="">

            <div>
                <label class="block text-slate-500 text-xs font-bold uppercase tracking-wider mb-2 font-heading">DOI</label>
                <div class="flex gap-2">
                    <input type="text" id="inputDoi" name="doi" placeholder="10.xxxx/xxxxx" class="w-full px-4 py-3 bg-gray-50 border border-gray-200 rounded-xl focus:outline-none focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500 transition text-sm font-body">
                    <button type="button" id="btnFetchDoi" class="bg-slate-200 text-slate-600 px-4 py-2 rounded-xl text-xs font-bold hover:bg-slate-300 transition">Cari</button>
                </div>
            </div>

            <div>
                <label class="block text-slate-500 text-xs font-bold uppercase tracking-wider mb-2 font-heading">Upload PDF</label>
                <div class="relative">
                    
                    <div id="uploadArea" class="border-2 border-dashed border-gray-300 rounded-2xl p-8 text-center flex flex-col items-center justify-center cursor-pointer hover:bg-gray-50 transition-colors group">
                        <input type="file" id="inputFile" name="pdf_file" accept=".pdf" class="hidden">
                        
                        <div class="w-12 h-12 bg-gray-100 text-gray-400 rounded-xl flex items-center justify-center mb-3 group-hover:scale-110 transition-transform duration-300">
                            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12"></path></svg>
                        </div>
                        <h4 class="text-sm font-bold text-slate-700">Choose a file or drag & drop it here</h4>
                        <p class="text-[10px] text-slate-400 mt-1">PDF format, up to 95MB</p>
                        <button type="button" class="mt-4 px-4 py-2 border border-gray-200 rounded-lg text-xs font-bold text-slate-600 bg-white hover:bg-gray-50 shadow-sm transition">Browse File</button>
                    </div>
                    
                    <div id="fileDisplayArea" class="hidden bg-white border border-gray-200 rounded-xl p-3 flex items-center justify-between shadow-sm">
                        <div class="flex items-center gap-3 overflow-hidden">
                            <div class="w-8 h-8 rounded-lg flex items-center justify-center bg-red-50 text-red-600 border border-red-100 shrink-0">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path></svg>
                            </div>
                            <span id="displayFileName" class="text-sm text-slate-700 font-medium truncate max-w-[200px]">filename.pdf</span>
                        </div>
                        <button type="button" id="btnChangeFile" class="text-xs font-bold text-blue-600 hover:text-blue-800 bg-blue-50 px-3 py-1.5 rounded-lg transition shrink-0 border border-blue-100">
                            Ganti
                        </button>
                    </div>

                    <div id="progressWrapper" class="hidden mt-3">
                        <div class="flex justify-between mb-1">
                            <span class="text-xs font-bold text-blue-600" id="progressText">Uploading...</span>
                            <span class="text-xs font-bold text-blue-600" id="progressPercent">0%</span>
                        </div>
                        <div class="w-full bg-gray-200 rounded-full h-2.5">
                            <div id="progressBar" class="bg-blue-600 h-2.5 rounded-full transition-all duration-100 ease-out" style="width: 0%"></div>
                        </div>
                    </div>

                    <div id="uploadSuccess" class="hidden mt-2 text-xs font-bold flex items-center gap-2 text-green-600">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path></svg> 
                        Upload Selesai!
                    </div>
                </div>
            </div>

            <div class="border-b border-gray-100 my-2"></div>

            <div><label class="block text-slate-500 text-xs font-bold uppercase tracking-wider mb-2 font-heading">Judul Paper</label><input type="text" id="inputTitle" name="title" required class="w-full px-4 py-3 bg-gray-50 border border-gray-200 rounded-xl focus:outline-none focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500 transition text-sm font-body"></div>
            <div><label class="block text-slate-500 text-xs font-bold uppercase tracking-wider mb-2 font-heading">Penulis (Author)</label><input type="text" id="inputAuthor" name="author" required class="w-full px-4 py-3 bg-gray-50 border border-gray-200 rounded-xl focus:outline-none focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500 transition text-sm font-body"></div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div class="md:col-span-2">
                    <label class="block text-slate-500 text-xs font-bold uppercase tracking-wider mb-2 font-heading">Nama Jurnal / Konferensi</label>
                    <input type="text" id="inputJournal" name="journal_name" class="w-full px-4 py-3 bg-gray-50 border border-gray-200 rounded-xl focus:outline-none focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500 transition text-sm font-body" placeholder="Auto-fill dari DOI/PDF">
                </div>
                <div>
                    <label class="block text-slate-500 text-xs font-bold uppercase tracking-wider mb-2 font-heading">Penerbit (Publisher)</label>
                    <input type="text" id="inputPublisher" name="publisher" class="w-full px-4 py-3 bg-gray-50 border border-gray-200 rounded-xl focus:outline-none focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500 transition text-sm font-body">
                </div>
                <div>
                    <label class="block text-slate-500 text-xs font-bold uppercase tracking-wider mb-2 font-heading">Tahun Terbit</label>
                    <input type="text" id="inputYear" name="publish_year" class="w-full px-4 py-3 bg-gray-50 border border-gray-200 rounded-xl focus:outline-none focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500 transition text-sm font-body" placeholder="YYYY">
                </div>
            </div>

            <div>
                <label class="block text-slate-500 text-xs font-bold uppercase tracking-wider mb-2 font-heading">Tags / Kategori</label>
                <input type="text" id="inputTags" name="tags" class="w-full px-4 py-3 bg-gray-50 border border-gray-200 rounded-xl text-sm font-body" placeholder="Ketik tag dan Enter...">
                <p class="text-[10px] text-gray-400 mt-1 font-body">Gunakan Enter untuk membuat tag baru.</p>
            </div>

            <div>
                <label class="block text-slate-500 text-xs font-bold uppercase tracking-wider mb-2 font-heading">Link Jurnal / URL Sumber</label>
                <input type="url" id="inputLink" name="link_paper" placeholder="https://..." class="w-full px-4 py-3 bg-gray-50 border border-gray-200 rounded-xl focus:outline-none focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500 transition text-sm font-body">
                <p class="text-[10px] text-gray-400 mt-1 font-body">Diisi tidak mengupload file (atau sebagai link referensi).</p>
            </div>

            <div id="btnContainer" class="pt-2"><button type="submit" id="btnSubmit" class="w-full bg-blue-600 text-white font-bold py-3 rounded-xl hover:bg-blue-700 transition shadow-lg shadow-blue-600/20 text-sm font-heading">Simpan Data</button></div>
        </form>
    </div>

    <script>
        // Navbar Toggle
        const btn = document.getElementById('mobile-menu-btn');
        const menu = document.getElementById('mobile-menu');
        btn.addEventListener('click', () => { menu.classList.toggle('hidden'); });

        document.addEventListener('DOMContentLoaded', function() {
            const successAlert = document.getElementById('successAlert');
            if (successAlert) { setTimeout(() => { successAlert.classList.remove('opacity-100'); successAlert.classList.add('opacity-0'); setTimeout(() => { successAlert.remove(); window.history.replaceState(null, null, window.location.pathname); }, 500); }, 3000); }
        });

        // --- TAGIFY LOGIC START ---
        let tagifyInstance = null;

        function initTagify() {
            const input = document.querySelector('#inputTags');
            if (!input) return;

            // Fetch list tags dari server (API)
            fetch('paper.php?action_type=get_tags')
                .then(response => response.json())
                .then(whitelist => {
                    if (tagifyInstance) tagifyInstance.destroy(); // Hancurkan instance lama biar ga double
                    
                    tagifyInstance = new Tagify(input, {
                        whitelist: whitelist, // Autocomplete list
                        enforceWhitelist: false, // False = Boleh bikin tag baru yg gak ada di list
                        dropdown: {
                            maxItems: 20,           
                            classname: "tags-look", 
                            enabled: 0,             // 0 = langsung muncul dropdown pas diklik
                            closeOnSelect: false    
                        }
                    });
                })
                .catch(err => console.error("Gagal load tags:", err));
        }
        // --- TAGIFY LOGIC END ---

        // --- FETCH DOI FUNCTION ---
        function setupDoiListener(modalTarget) {
            const btnFetch = modalTarget.querySelector('#btnFetchDoi'); const inputDoi = modalTarget.querySelector('#inputDoi');
            const inputTitle = modalTarget.querySelector('#inputTitle'); const inputAuthor = modalTarget.querySelector('#inputAuthor'); const inputLink = modalTarget.querySelector('#inputLink');
            const inputJournal = modalTarget.querySelector('#inputJournal'); const inputYear = modalTarget.querySelector('#inputYear'); const inputPublisher = modalTarget.querySelector('#inputPublisher');

            if (btnFetch) {
                btnFetch.onclick = async function() {
                    let doi = inputDoi.value.trim(); if (!doi) return alert("Masukkan DOI terlebih dahulu!");
                    doi = doi.replace('https://doi.org/', '').replace('http://doi.org/', '');
                    const originalText = btnFetch.innerText; btnFetch.innerText = "Mencari..."; btnFetch.disabled = true;
                    try {
                        const response = await fetch(`https://api.crossref.org/works/${doi}`);
                        if (!response.ok) throw new Error("DOI tidak ditemukan");
                        const data = await response.json(); const item = data.message;
                        
                        if (item.title && item.title.length > 0) inputTitle.value = item.title[0];
                        if (item.author) { const authors = item.author.map(a => (a.given ? a.given + " " : "") + a.family).join(", "); inputAuthor.value = authors; }
                        if (item['container-title'] && item['container-title'].length > 0) inputJournal.value = item['container-title'][0];
                        if (item.publisher) inputPublisher.value = item.publisher;
                        
                        let pubYear = '';
                        if (item['published-print'] && item['published-print']['date-parts']) pubYear = item['published-print']['date-parts'][0][0];
                        else if (item['published-online'] && item['published-online']['date-parts']) pubYear = item['published-online']['date-parts'][0][0];
                        else if (item['created'] && item['created']['date-parts']) pubYear = item['created']['date-parts'][0][0];
                        if(pubYear) inputYear.value = pubYear;

                    } catch (error) { alert("Gagal mengambil data DOI: " + error.message); } finally { btnFetch.innerText = originalText; btnFetch.disabled = false; }
                };
            }
        }

        // --- AJAX UPLOAD LOGIC ---
        function setupAjaxUpload(modalTarget) {
            const inputFile = modalTarget.querySelector('#inputFile');
            const uploadArea = modalTarget.querySelector('#uploadArea');
            const progressWrapper = modalTarget.querySelector('#progressWrapper');
            const progressBar = modalTarget.querySelector('#progressBar');
            const progressText = modalTarget.querySelector('#progressText');
            const progressPercent = modalTarget.querySelector('#progressPercent');
            const uploadSuccess = modalTarget.querySelector('#uploadSuccess');
            const inputLinkUpload = modalTarget.querySelector('#linkUploadFinal');
            const inputRealName = modalTarget.querySelector('#realNameFile');
            const btnSubmit = modalTarget.querySelector('#btnSubmit');
            const fileDisplayArea = modalTarget.querySelector('#fileDisplayArea');
            const displayFileName = modalTarget.querySelector('#displayFileName');
            const btnChangeFile = modalTarget.querySelector('#btnChangeFile');
            
            // Auto fill targets
            const inputTitle = modalTarget.querySelector('#inputTitle');
            const inputAuthor = modalTarget.querySelector('#inputAuthor');
            const inputDoi = modalTarget.querySelector('#inputDoi');
            const inputJournal = modalTarget.querySelector('#inputJournal');
            const inputYear = modalTarget.querySelector('#inputYear');
            const inputPublisher = modalTarget.querySelector('#inputPublisher');

            if (!inputFile) return;

            uploadArea.onclick = () => inputFile.click();
            uploadArea.ondragover = (e) => { e.preventDefault(); uploadArea.classList.add('bg-blue-50', 'border-blue-300'); };
            uploadArea.ondragleave = (e) => { e.preventDefault(); uploadArea.classList.remove('bg-blue-50', 'border-blue-300'); };
            uploadArea.ondrop = (e) => { e.preventDefault(); uploadArea.classList.remove('bg-blue-50', 'border-blue-300'); if(e.dataTransfer.files.length > 0) { inputFile.files = e.dataTransfer.files; inputFile.dispatchEvent(new Event('change')); }};
            if(btnChangeFile) btnChangeFile.onclick = () => inputFile.click();

            inputFile.addEventListener('change', function() {
                if (this.files.length === 0) return;
                if (this.files[0].size > 95 * 1024 * 1024) { alert("File > 95MB."); this.value = ''; return; }

                progressWrapper.classList.remove('hidden');
                uploadSuccess.classList.add('hidden');
                uploadArea.classList.add('hidden');
                fileDisplayArea.classList.add('hidden'); 
                progressBar.style.width = '0%'; progressPercent.innerText = '0%';
                
                inputFile.disabled = true; if(btnSubmit) btnSubmit.disabled = true;

                const formData = new FormData();
                formData.append('pdf_file', this.files[0]);
                formData.append('action_type', 'ajax_upload');

                const xhr = new XMLHttpRequest();
                xhr.open('POST', 'paper.php', true);
                xhr.upload.addEventListener('progress', function(e) {
                    if (e.lengthComputable) {
                        const pct = Math.round((e.loaded / e.total) * 100);
                        progressBar.style.width = pct + '%'; progressPercent.innerText = pct + '%';
                    }
                });
                xhr.onload = function() {
                    if (xhr.status === 200) {
                        try {
                            const data = JSON.parse(xhr.responseText);
                            if (data.status) {
                                setTimeout(() => {
                                    progressWrapper.classList.add('hidden');
                                    fileDisplayArea.classList.remove('hidden');
                                    displayFileName.innerText = data.real_name;
                                    uploadSuccess.classList.remove('hidden');
                                }, 500);
                                inputLinkUpload.value = data.url; 
                                inputRealName.value = data.real_name;
                                
                                if(data.title) inputTitle.value = data.title;
                                if(data.author) inputAuthor.value = data.author;
                                if(data.doi) inputDoi.value = data.doi;
                                if(data.journal) inputJournal.value = data.journal;
                                if(data.year) inputYear.value = data.year;
                                if(data.publisher) inputPublisher.value = data.publisher;

                            } else { alert("Upload gagal: " + data.message); progressWrapper.classList.add('hidden'); uploadArea.classList.remove('hidden'); }
                        } catch (e) { alert("Error parsing response."); }
                    } else { alert("Server error."); }
                    inputFile.disabled = false; if(btnSubmit) btnSubmit.disabled = false;
                };
                xhr.send(formData);
            });
        }

        function openArchiveModal() {
            document.getElementById('modalContent').innerHTML = document.getElementById('sourceArchived').innerHTML;
            document.getElementById('modalHeaderActions').innerHTML = '';
            document.getElementById('modalPanel').classList.remove('sm:max-w-lg'); 
            document.getElementById('modalPanel').classList.add('sm:max-w-6xl');
            openModal('Arsip Paper');
        }

        function resetModalSize() { const p = document.getElementById('modalPanel'); p.classList.remove('sm:max-w-6xl'); p.classList.add('sm:max-w-lg'); }

        function openInputModal() {
            resetModalSize(); 
            const modalTarget = document.getElementById('modalContent');
            modalTarget.innerHTML = document.getElementById('formSource').innerHTML; 
            document.getElementById('modalHeaderActions').innerHTML = ''; 
            
            setupDoiListener(modalTarget); 
            setupAjaxUpload(modalTarget); 
            
            const fileDisplayArea = modalTarget.querySelector('#fileDisplayArea');
            const uploadArea = modalTarget.querySelector('#uploadArea');
            if(fileDisplayArea) fileDisplayArea.classList.add('hidden');
            if(uploadArea) uploadArea.classList.remove('hidden');

            const form = modalTarget.querySelector('form');
            form.addEventListener('submit', function(e) {
                const link = modalTarget.querySelector('#inputLink').value.trim();
                const upload = modalTarget.querySelector('#linkUploadFinal').value.trim();
                const doi = modalTarget.querySelector('#inputDoi').value.trim();
                const file = modalTarget.querySelector('#inputFile'); 
                if (!link && !doi && !upload && file.files.length === 0) { 
                    e.preventDefault(); alert('Harap isi minimal satu sumber!'); 
                }
            });
            modalTarget.querySelector('#btnContainer').innerHTML = `<button type="submit" id="btnSubmit" class="w-full bg-blue-600 text-white font-bold py-3 rounded-xl hover:bg-blue-700 transition shadow-lg shadow-blue-600/20 text-sm font-heading">Simpan Data</button>`; 
            
            // INIT TAGIFY (Delay sedikit agar elemen ter-render)
            setTimeout(initTagify, 100);

            openModal('Tambah Paper Baru');
        }

        function submitArchive(id) { if(confirm('Arsipkan?')) { const form = document.createElement('form'); form.method='POST'; const i1=document.createElement('input');i1.type='hidden';i1.name='paper_id';i1.value=id; const i2=document.createElement('input');i2.type='hidden';i2.name='action_type';i2.value='archive'; form.appendChild(i1);form.appendChild(i2);document.body.appendChild(form);form.submit(); } }
        function submitRestore(id) { if(confirm('Pulihkan?')) { const form = document.createElement('form'); form.method='POST'; const i1=document.createElement('input');i1.type='hidden';i1.name='paper_id';i1.value=id; const i2=document.createElement('input');i2.type='hidden';i2.name='action_type';i2.value='restore'; form.appendChild(i1);form.appendChild(i2);document.body.appendChild(form);form.submit(); } }
        function confirmDelete(id, title) { document.getElementById('modalHeaderActions').innerHTML=''; const sTitle = title.replace(/'/g, "\\'"); document.getElementById('modalContent').innerHTML = `<div class="text-center p-4"><div class="w-16 h-16 bg-red-100 rounded-full flex items-center justify-center mx-auto mb-4"><svg class="w-8 h-8 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path></svg></div><h3 class="text-xl font-bold text-slate-800 mb-2 font-heading">Hapus Permanen?</h3><p class="text-sm text-slate-500 mb-6 font-body">Yakin hapus <strong>${title}</strong>?</p><div class="flex gap-3 justify-center"><button onclick="closeModal()" class="px-5 py-2.5 bg-gray-100 text-slate-600 rounded-xl font-bold text-sm">Batal</button><button onclick="submitDelete('${id}')" class="px-5 py-2.5 bg-red-600 text-white rounded-xl font-bold text-sm">Ya, Hapus</button></div></div>`; openModal('Konfirmasi Hapus'); }
        function submitDelete(id) { const form = document.createElement('form'); form.method='POST'; const i1=document.createElement('input');i1.type='hidden';i1.name='paper_id';i1.value=id; const i2=document.createElement('input');i2.type='hidden';i2.name='action_type';i2.value='delete'; form.appendChild(i1);form.appendChild(i2);document.body.appendChild(form);form.submit(); }

        function editPaper(id, title, author, doi, link_paper, link_upload, real_name, journal, year, publisher, tags) { 
            resetModalSize(); const modalTarget = document.getElementById('modalContent'); 
            modalTarget.innerHTML = document.getElementById('formSource').innerHTML; 
            document.getElementById('modalHeaderActions').innerHTML = ''; 
            
            modalTarget.querySelector('#inputId').value = id; 
            modalTarget.querySelector('#inputAction').value = 'update'; 
            modalTarget.querySelector('#inputDoi').value = doi; 
            modalTarget.querySelector('#inputTitle').value = title; 
            modalTarget.querySelector('#inputAuthor').value = author; 
            modalTarget.querySelector('#inputLink').value = link_paper; 
            modalTarget.querySelector('#linkUploadFinal').value = link_upload; 
            modalTarget.querySelector('#realNameFile').value = real_name;
            modalTarget.querySelector('#inputJournal').value = journal;
            modalTarget.querySelector('#inputYear').value = year;
            modalTarget.querySelector('#inputPublisher').value = publisher;
            
            // Set value input tags agar dibaca Tagify
            modalTarget.querySelector('#inputTags').value = tags;
            
            const fileDisplayArea = modalTarget.querySelector('#fileDisplayArea');
            const displayFileName = modalTarget.querySelector('#displayFileName');
            const uploadArea = modalTarget.querySelector('#uploadArea');
            
            if(link_upload) {
                fileDisplayArea.classList.remove('hidden');
                uploadArea.classList.add('hidden');
                displayFileName.innerText = real_name ? real_name : decodeURIComponent(link_upload.split('/').pop().replace(/^\d{8}-\d{6}-/, ''));
            } else {
                fileDisplayArea.classList.add('hidden');
                uploadArea.classList.remove('hidden');
            }

            const sTitle = title.replace(/'/g, "\\'"); 
            modalTarget.querySelector('#btnContainer').className = "pt-2 flex gap-3"; 
            modalTarget.querySelector('#btnContainer').innerHTML = `<button type="button" onclick="confirmDelete('${id}', '${sTitle}')" class="w-1/3 bg-red-100 text-red-600 font-bold py-3 rounded-xl text-sm font-heading">Hapus</button><button type="submit" id="btnSubmit" class="w-2/3 bg-blue-600 text-white font-bold py-3 rounded-xl text-sm font-heading">Update Data</button>`; 
            
            setupDoiListener(modalTarget); 
            setupAjaxUpload(modalTarget); 
            
            // INIT TAGIFY (Delay sedikit agar elemen ter-render)
            setTimeout(initTagify, 100);

            openModal('Edit Paper'); 
        }
        
        function copyCode(btn) { navigator.clipboard.writeText(btn.nextElementSibling.innerText).then(() => { const original = btn.innerHTML; btn.innerHTML = `<svg class="w-4 h-4 text-green-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path></svg>`; setTimeout(() => { btn.innerHTML = original; }, 2000); }); }
        
        function showDetail(id, title, author, doi, link_paper, link_upload, real_name, journal, year, publisher, status, tags) {
            resetModalSize();
            const headerActions = document.getElementById('modalHeaderActions');
            const sTitle = title.replace(/'/g, "\\'"); const sAuthor = author.replace(/'/g, "\\'"); const sDoi = doi.replace(/'/g, "\\'"); const sLink = link_paper.replace(/'/g, "\\'"); const sUpload = link_upload.replace(/'/g, "\\'"); const sReal = real_name.replace(/'/g, "\\'"); const sJournal = journal.replace(/'/g, "\\'"); const sYear = year.replace(/'/g, "\\'"); const sPub = publisher.replace(/'/g, "\\'"); const sTags = tags.replace(/'/g, "\\'");

            if(headerActions) {
                let btns = `<button onclick="editPaper('${id}', '${sTitle}', '${sAuthor}', '${sDoi}', '${sLink}', '${sUpload}', '${sReal}', '${sJournal}', '${sYear}', '${sPub}', '${sTags}')" class="p-2 text-slate-400 hover:text-blue-600 transition rounded-full hover:bg-blue-50" title="Edit"><svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z"></path></svg></button>`;
                if (status === 'active') btns = `<button onclick="submitArchive('${id}')" class="p-2 text-slate-400 hover:text-amber-600 transition rounded-full hover:bg-amber-50" title="Arsipkan"><svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 8h14M5 8a2 2 0 110-4h14a2 2 0 110 4M5 8v10a2 2 0 002 2h10a2 2 0 002-2V8m-9 4h4"></path></svg></button>` + btns;
                else btns = `<button onclick="submitRestore('${id}')" class="p-2 text-slate-400 hover:text-green-600 transition rounded-full hover:bg-green-50" title="Pulihkan"><svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h10a8 8 0 018 8v2M3 10l6 6m-6-6l6-6"></path></svg></button>` + btns;
                headerActions.innerHTML = btns;
            }

            const bibtex = `@article{${author.split(' ')[0].toLowerCase().replace(/[^a-z]/g, '')}_paper,\n  title={${title}},\n  author={${author}},\n  journal={${journal}},\n  year={${year}},\n  publisher={${publisher}},\n  doi={${doi}},\n  url={${link_paper || link_upload}}\n}`;
            
            // APA Style Generator
            const apaYear = year ? `(${year})` : '(n.d.)';
            const apaJournal = journal ? `<span class="italic">${journal}</span>` : '';
            const apaDoi = doi ? ` https://doi.org/${doi}` : (link_paper ? ` ${link_paper}` : '');
            const apaText = `${author}. ${apaYear}. ${title}. ${apaJournal}${apaJournal ? '.' : ''}${apaDoi}`;

            let actionButtons = '';
            if (doi) actionButtons += `<a href="https://doi.org/${doi}" target="_blank" class="flex-1 flex items-center justify-center py-3 rounded-xl bg-white text-slate-700 font-bold hover:bg-gray-50 transition shadow-sm border border-gray-300">DOI</a>`;
            if (link_paper) actionButtons += `<a href="${link_paper}" target="_blank" class="flex-1 flex items-center justify-center py-3 rounded-xl bg-white text-slate-700 font-bold hover:bg-gray-50 transition shadow-sm border border-gray-300">Link Jurnal</a>`;
            if (link_upload) actionButtons += `<a href="${link_upload}" target="_blank" class="flex-1 flex items-center justify-center py-3 rounded-xl bg-white text-slate-700 font-bold hover:bg-gray-50 transition shadow-sm border border-gray-300">Download PDF</a>`;
            const buttonContainer = actionButtons ? `<div class="flex flex-col lg:flex-row gap-3 mb-6 w-full">${actionButtons}</div>` : '';

            // Generate HTML for tags in detail view
            let tagsDetailHtml = '';
            if(tags) {
                const tagsArr = tags.split(',');
                tagsDetailHtml = '<div class="flex flex-wrap gap-2 mt-2">';
                tagsArr.forEach(t => {
                     tagsDetailHtml += `<span class="bg-blue-50 text-blue-600 text-xs px-2.5 py-1 rounded-md font-bold uppercase tracking-wide border border-blue-100">${t.trim()}</span>`;
                });
                tagsDetailHtml += '</div>';
            }

            let metaHtml = `<div><label class="block text-slate-500 text-[10px] font-bold uppercase tracking-wider mb-1 font-heading">Judul</label><p class="text-slate-800 font-medium text-lg leading-tight">${title}</p></div>`;
            metaHtml += `<div><label class="block text-slate-500 text-[10px] font-bold uppercase tracking-wider mb-1 font-heading">Penulis</label><p class="text-slate-800 font-medium text-sm">${author}</p></div>`;
            if(journal) metaHtml += `<div><label class="block text-slate-500 text-[10px] font-bold uppercase tracking-wider mb-1 font-heading">Jurnal / Konferensi</label><p class="text-slate-800 font-medium text-sm">${journal} ${year ? `(${year})` : ''}</p></div>`;
            if(tags) metaHtml += `<div><label class="block text-slate-500 text-[10px] font-bold uppercase tracking-wider mb-1 font-heading">Tags</label>${tagsDetailHtml}</div>`;

            // APA STYLE SECTION (Fixed for mobile visibility)
            const citationHtml = `
            <div class="pt-4 border-t border-gray-100">
                <div class="mb-4">
                    <h4 class="text-xs font-bold uppercase tracking-wider text-slate-500 mb-2 font-heading">APA Style</h4>
                    <div class="relative group bg-slate-50 border border-gray-200 rounded-xl overflow-hidden">
                        <button onclick="copyCode(this)" class="absolute top-2 right-2 bg-white hover:bg-gray-100 text-slate-400 p-1.5 rounded-lg opacity-100 lg:opacity-0 lg:group-hover:opacity-100 transition-opacity border border-gray-200 shadow-sm" title="Copy"><svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 5H6a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2v-1M8 5a2 2 0 002 2h2a2 2 0 002-2M8 5a2 2 0 012-2h2a2 2 0 012 2m0 0h2a2 2 0 012 2v3m2 4H10m0 0l3-3m-3 3l3 3"></path></svg></button>
                        <div class="p-4 text-slate-700 text-sm font-body leading-relaxed select-all pl-8 -indent-8">${apaText}</div>
                    </div>
                </div>
                <div>
                    <h4 class="text-xs font-bold uppercase tracking-wider text-slate-500 mb-2 font-heading">BibTeX Citation</h4>
                    <div class="relative group bg-slate-900 rounded-xl overflow-hidden">
                        <button onclick="copyCode(this)" class="absolute top-2 right-2 bg-white/10 hover:bg-white/20 text-slate-50 p-1.5 rounded-lg opacity-100 lg:opacity-0 lg:group-hover:opacity-100 transition-opacity" title="Copy"><svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 5H6a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2v-1M8 5a2 2 0 002 2h2a2 2 0 002-2M8 5a2 2 0 012-2h2a2 2 0 012 2m0 0h2a2 2 0 012 2v3m2 4H10m0 0l3-3m-3 3l3 3"></path></svg></button>
                        <pre class="p-4 text-slate-50 text-xs font-mono overflow-x-auto leading-relaxed select-all"><code>${bibtex}</code></pre>
                    </div>
                </div>
            </div>`;

            document.getElementById('modalContent').innerHTML = `<div class="text-left space-y-5">${metaHtml}${buttonContainer}${citationHtml}</div>`;
            openModal('Informasi Paper');
        }
    </script>

</body>
</html>