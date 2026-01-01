<?php
session_start();
require_once 'config.php';

if (!isset($_SESSION['user'])) {
    header("Location: login.php");
    exit();
}

$user = $_SESSION['user'];
$email = $user['email'] ?? 'unknown';

// --- LOGIC: TENTUKAN MODE (LIST vs DETAIL) ---
$tag_id = $_GET['id'] ?? null;
$view_mode = $tag_id ? 'detail' : 'list';

$current_tag_name = '';
$papers = [];
$datasets = [];

if ($view_mode === 'detail') {
    // --- MODE DETAIL: AMBIL ISI FOLDER ---
    $stmt = $conn->prepare("SELECT tag_name FROM tags WHERE id = ? AND user_email = ?");
    $stmt->bind_param("is", $tag_id, $email);
    $stmt->execute();
    $res_tag = $stmt->get_result();
    
    if ($res_tag->num_rows === 0) {
        header("Location: tags.php"); 
        exit();
    }
    $current_tag_name = $res_tag->fetch_assoc()['tag_name'];

    // Ambil Papers
    $stmt_p = $conn->prepare("
        SELECT p.* FROM papers p 
        JOIN paper_tags pt ON p.id = pt.paper_id 
        WHERE pt.tag_id = ? AND p.status = 'active'
        ORDER BY p.created_at DESC
    ");
    $stmt_p->bind_param("i", $tag_id);
    $stmt_p->execute();
    $papers = $stmt_p->get_result();

    // Ambil Datasets
    $stmt_d = $conn->prepare("
        SELECT d.* FROM datasets d 
        JOIN dataset_tags dt ON d.id = dt.dataset_id 
        WHERE dt.tag_id = ? AND d.status = 'active'
        ORDER BY d.created_at DESC
    ");
    $stmt_d->bind_param("i", $tag_id);
    $stmt_d->execute();
    $datasets = $stmt_d->get_result();

} else {
    // --- MODE LIST: AMBIL SEMUA FOLDER ---
    $stmt = $conn->prepare("
        SELECT t.*, 
               (SELECT COUNT(*) FROM paper_tags pt 
                JOIN papers p ON pt.paper_id = p.id 
                WHERE pt.tag_id = t.id AND p.status = 'active') as paper_count,
               (SELECT COUNT(*) FROM dataset_tags dt 
                JOIN datasets d ON dt.dataset_id = d.id 
                WHERE dt.tag_id = t.id AND d.status = 'active') as dataset_count
        FROM tags t 
        WHERE t.user_email = ? 
        ORDER BY t.tag_name ASC
    ");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $all_tags = $stmt->get_result();
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tags Folder - Narrasheet</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter+Tight:wght@300;400;500;600&family=Outfit:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500;600;700&display=swap" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
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
        .no-scrollbar::-webkit-scrollbar { display: none; }
        .no-scrollbar { -ms-overflow-style: none; scrollbar-width: none; }
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
                <a href="paper" class="text-sm font-medium text-slate-600 hover:text-blue-600 transition font-heading">Paper</a>
                <a href="tags" class="text-sm font-bold text-blue-600 font-heading">Tags</a>
                <div class="h-8 w-[1px] bg-gray-200 mx-2 block"></div>
                <div class="flex items-center gap-3">
                    <img src="<?php echo !empty($user['profile_pic']) ? $user['profile_pic'] : 'https://ui-avatars.com/api/?name='.urlencode($user['username']); ?>" class="w-9 h-9 rounded-full border border-gray-200 object-cover shadow-sm">
                    <a href="logout.php" class="text-sm font-medium text-red-500 hover:text-red-600 font-heading">Logout</a>
                </div>
            </div>
        </div>

        <div id="mobile-menu" class="hidden md:hidden absolute top-full left-0 w-full bg-white border-b border-gray-100 shadow-xl py-4 px-6 flex flex-col gap-4">
            <a href="dataset" class="text-base font-medium text-slate-600 py-2 border-b border-gray-50">Dataset</a>
            <a href="paper" class="text-base font-medium text-slate-600 py-2 border-b border-gray-50">Paper</a>
            <a href="tags" class="text-base font-bold text-blue-600 py-2 border-b border-gray-50">Tags</a>
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

        <?php if ($view_mode === 'list'): ?>
            <div class="flex flex-col md:flex-row justify-between items-start md:items-center mb-8 gap-4">
                <div>
                    <h1 class="text-3xl font-bold text-slate-900 font-heading">Folder Tags</h1>
                    <p class="text-slate-500 text-sm mt-1 font-body">Semua kategori riset Anda tersusun rapi di sini.</p>
                </div>
                <div class="relative w-full md:w-64">
                    <span class="absolute inset-y-0 left-0 flex items-center pl-3 text-gray-400">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path></svg>
                    </span>
                    <input type="text" id="searchInput" onkeyup="filterTags()" placeholder="Cari folder..." class="w-full pl-10 pr-4 py-2 bg-white border border-gray-200 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500 transition shadow-sm">
                </div>
            </div>

            <div id="tagsGrid" class="grid grid-cols-2 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-4 md:gap-6">
                <?php if ($all_tags->num_rows > 0): ?>
                    <?php while($tag = $all_tags->fetch_assoc()): ?>
                        <?php 
                            $p_count = $tag['paper_count']; $d_count = $tag['dataset_count']; $total_items = $p_count + $d_count;
                        ?>
                        <a href="tags?id=<?php echo $tag['id']; ?>" class="tag-card group relative bg-white p-5 md:p-6 rounded-2xl border border-gray-100 shadow-sm hover:shadow-xl hover:shadow-blue-900/5 hover:-translate-y-1 transition-all duration-300 cursor-pointer overflow-hidden">
                            <div class="absolute top-0 right-0 w-24 h-24 bg-blue-50 rounded-bl-full -mr-4 -mt-4 opacity-50 group-hover:scale-110 transition-transform duration-500"></div>
                            <div class="relative flex items-start justify-between mb-4">
                                <div class="w-10 h-10 md:w-12 md:h-12 bg-blue-50 text-blue-600 rounded-xl flex items-center justify-center shadow-sm group-hover:bg-blue-600 group-hover:text-white transition-colors duration-300">
                                    <svg class="w-5 h-5 md:w-6 md:h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 7v10a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-6l-2-2H5a2 2 0 00-2 2z"></path></svg>
                                </div>
                                <span class="bg-gray-50 text-gray-500 text-[10px] font-bold px-2 py-1 rounded-full group-hover:bg-blue-50 group-hover:text-blue-600 transition-colors"><?php echo $total_items; ?> Item</span>
                            </div>
                            <h3 class="tag-name font-bold text-slate-800 text-base md:text-lg mb-1 group-hover:text-blue-600 transition-colors capitalize font-heading truncate"><?php echo htmlspecialchars($tag['tag_name']); ?></h3>
                            <div class="flex items-center gap-3 text-xs text-slate-400 font-mono mt-3 pt-3 border-t border-gray-50">
                                <span class="flex items-center gap-1"><svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path></svg><?php echo $p_count; ?></span>
                                <span class="w-1 h-1 bg-gray-300 rounded-full"></span>
                                <span class="flex items-center gap-1"><svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 7v10c0 2.21 3.582 4 8 4s8-1.79 8-4V7M4 7c0 2.21 3.582 4 8 4s8-1.79 8-4M4 7c0-2.21 3.582-4 8-4s8 1.79 8 4m0 5c0 2.21-3.582 4-8 4s-8-1.79-8-4"></path></svg><?php echo $d_count; ?></span>
                            </div>
                        </a>
                    <?php endwhile; ?>
                <?php else: ?>
                    <div class="col-span-full py-16 text-center border-2 border-dashed border-gray-200 rounded-2xl bg-white/50"><div class="inline-block p-4 rounded-full bg-blue-50 mb-3 text-blue-300"><svg class="w-10 h-10" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A1.994 1.994 0 013 12V7a4 4 0 014-4z"></path></svg></div><h3 class="text-slate-800 font-bold text-lg font-heading">Belum ada Tags</h3><p class="text-slate-400 mb-2 font-body text-sm mt-1">Tambahkan tags saat mengupload paper atau dataset.</p></div>
                <?php endif; ?>
            </div>
            <script> function filterTags() { const input = document.getElementById('searchInput'); const filter = input.value.toLowerCase(); const grid = document.getElementById('tagsGrid'); const cards = grid.getElementsByClassName('tag-card'); for (let i = 0; i < cards.length; i++) { const title = cards[i].querySelector('.tag-name').innerText; if (title.toLowerCase().indexOf(filter) > -1) { cards[i].style.display = ""; } else { cards[i].style.display = "none"; } } } </script>

        <?php else: ?>
            
            <div class="flex items-center gap-4 mb-8 animate-fade-in-up">
                <a href="tags" class="w-10 h-10 rounded-xl bg-white border border-gray-200 flex items-center justify-center text-slate-500 hover:text-blue-600 hover:border-blue-200 transition shadow-sm group">
                    <svg class="w-5 h-5 group-hover:-translate-x-0.5 transition-transform" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"></path></svg>
                </a>
                <div>
                    <div class="flex items-center gap-2 text-xs text-slate-400 font-bold uppercase tracking-wider mb-1">
                        <svg class="w-4 h-4 text-blue-500" fill="currentColor" viewBox="0 0 24 24"><path d="M4 4c-1.1 0-2 .9-2 2v12c0 1.1.9 2 2 2h16c1.1 0 2-.9 2-2V8c0-1.1-.9-2-2-2h-8l-2-2H4z"></path></svg>
                        Folder Tag
                    </div>
                    <h1 class="text-2xl md:text-3xl font-bold text-slate-900 font-heading capitalize"><?php echo htmlspecialchars($current_tag_name); ?></h1>
                </div>
            </div>

            <div class="flex gap-2 mb-6 border-b border-gray-200 pb-4 overflow-x-auto no-scrollbar">
                <button onclick="switchTab('paper')" id="btn-paper" class="flex-shrink-0 flex items-center gap-2 px-5 py-2.5 rounded-xl font-bold text-sm transition-all duration-300 bg-slate-900 text-white shadow-lg shadow-slate-900/20">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path></svg>
                    Paper (<?php echo $papers->num_rows; ?>)
                </button>
                <button onclick="switchTab('dataset')" id="btn-dataset" class="flex-shrink-0 flex items-center gap-2 px-5 py-2.5 rounded-xl font-bold text-sm transition-all duration-300 bg-white text-slate-500 hover:bg-gray-50 border border-gray-200">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 7v10c0 2.21 3.582 4 8 4s8-1.79 8-4V7M4 7c0 2.21 3.582 4 8 4s8-1.79 8-4M4 7c0-2.21 3.582-4 8-4s8 1.79 8 4m0 5c0 2.21-3.582 4-8 4s-8-1.79-8-4"></path></svg>
                    Dataset (<?php echo $datasets->num_rows; ?>)
                </button>
            </div>

            <div id="content-paper" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-6 animate-fade-in">
                <?php if($papers->num_rows > 0): ?>
                    <?php while($row = $papers->fetch_assoc()): ?>
                        <?php 
                            // Prepare Variables for Popup (updated for new fields & json author)
                            $js_title = htmlspecialchars($row['title'], ENT_QUOTES); 
                            $js_author_raw = htmlspecialchars($row['author'], ENT_QUOTES); // Pass raw JSON to JS
                            $js_doi = htmlspecialchars($row['doi'] ?? '', ENT_QUOTES); 
                            $js_link_paper = htmlspecialchars($row['link_paper'] ?? '', ENT_QUOTES); 
                            $js_link_upload = htmlspecialchars($row['link_upload'] ?? '', ENT_QUOTES); 
                            $js_journal = htmlspecialchars($row['journal_name'] ?? '', ENT_QUOTES);
                            $js_year = htmlspecialchars($row['publish_year'] ?? '', ENT_QUOTES);
                            $js_volume = htmlspecialchars($row['volume'] ?? '', ENT_QUOTES);
                            $js_p_start = htmlspecialchars($row['page_start'] ?? '', ENT_QUOTES);
                            $js_p_end = htmlspecialchars($row['page_end'] ?? '', ENT_QUOTES);
                            $js_publisher = htmlspecialchars($row['publisher'] ?? '', ENT_QUOTES);
                            $js_abstract = htmlspecialchars($row['abstract'] ?? '', ENT_QUOTES);
                            
                            $has_pdf = !empty($row['link_upload']);

                            // Handle Author Display (Card View)
                            $rawAuthor = $row['author'];
                            $authorsArray = json_decode($rawAuthor, true);
                            $authorDisplay = "";
                            if (json_last_error() === JSON_ERROR_NONE && is_array($authorsArray)) {
                                $tempArr = [];
                                foreach($authorsArray as $au) {
                                    $f = isset($au['first']) ? trim($au['first']) : '';
                                    $l = isset($au['last']) ? trim($au['last']) : '';
                                    if($l) {
                                        $initial = $f ? substr($f, 0, 1) . '.' : '';
                                        $tempArr[] = "$l, $initial"; 
                                    } else {
                                         $tempArr[] = $f;
                                    }
                                }
                                $authorDisplay = htmlspecialchars(implode(", ", $tempArr));
                            } else {
                                $authorDisplay = htmlspecialchars($rawAuthor);
                            }
                        ?>
                        <div onclick="showPaperPopup('<?php echo $js_title; ?>', '<?php echo $js_author_raw; ?>', '<?php echo $js_doi; ?>', '<?php echo $js_link_paper; ?>', '<?php echo $js_link_upload; ?>', '<?php echo $js_journal; ?>', '<?php echo $js_year; ?>', '<?php echo $js_volume; ?>', '<?php echo $js_p_start; ?>', '<?php echo $js_p_end; ?>', '<?php echo $js_publisher; ?>', '<?php echo $js_abstract; ?>')" 
                             class="bg-white rounded-2xl border border-gray-100 shadow-sm hover:shadow-xl hover:-translate-y-1 transition duration-300 p-4 flex flex-col h-full group cursor-pointer">
                            <div class="flex justify-between items-start mb-2">
                                <h3 class="font-bold text-slate-800 text-md leading-tight line-clamp-2 group-hover:text-blue-600 transition"><?php echo htmlspecialchars($row['title']); ?></h3>
                                <?php if($has_pdf): ?>
                                    <div class="w-6 h-6 rounded bg-red-50 text-red-600 flex items-center justify-center shrink-0"><svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path></svg></div>
                                <?php endif; ?>
                            </div>
                            <p class="text-xs text-slate-500 mb-4 line-clamp-1"><?php echo $authorDisplay; ?></p>
                            <div class="mt-auto pt-3 border-t border-gray-50 flex justify-between items-center">
                                <span class="text-[10px] text-gray-400 font-mono"><?php echo $row['publish_year']; ?></span>
                                <span class="text-[10px] bg-gray-100 text-gray-500 px-2 py-0.5 rounded">Detail</span>
                            </div>
                        </div>
                    <?php endwhile; ?>
                <?php else: ?>
                    <div class="col-span-full py-12 text-center"><p class="text-slate-400 text-sm">Tidak ada paper di tag ini.</p></div>
                <?php endif; ?>
            </div>

            <div id="content-dataset" class="hidden grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-6 animate-fade-in">
                <?php if($datasets->num_rows > 0): ?>
                    <?php while($row = $datasets->fetch_assoc()): ?>
                        <?php 
                            $js_nama = htmlspecialchars($row['nama_dataset'], ENT_QUOTES);
                            $js_link = htmlspecialchars($row['link_dataset'], ENT_QUOTES);
                            $js_jenis = htmlspecialchars($row['jenis_dataset'], ENT_QUOTES);

                            $icon_url = match($row['jenis_dataset']) {
                                'kaggle' => 'https://cdn.simpleicons.org/kaggle/20BEFF',
                                'huggingface' => 'https://cdn.simpleicons.org/huggingface/FFD21E',
                                'openml' => 'https://cdn.ivanaldorino.web.id/narrasheet/openml.svg',
                                default => null
                            };
                        ?>
                        <div onclick="showDatasetPopup('<?php echo $js_nama; ?>', '<?php echo $js_link; ?>', '<?php echo $js_jenis; ?>')" 
                             class="bg-white rounded-2xl border border-gray-100 shadow-sm hover:shadow-xl hover:-translate-y-1 transition duration-300 p-4 flex flex-col h-full group cursor-pointer">
                            <div class="flex justify-between items-start mb-2">
                                <h3 class="font-bold text-slate-800 text-md leading-tight line-clamp-2 group-hover:text-blue-600 transition"><?php echo htmlspecialchars($row['nama_dataset']); ?></h3>
                                <div class="w-6 h-6 rounded bg-gray-50 p-1 shrink-0 flex items-center justify-center">
                                    <?php if($icon_url): ?><img src="<?php echo $icon_url; ?>" class="w-full h-full object-contain"><?php else: ?><svg class="w-3 h-3 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 7v10c0 2.21 3.582 4 8 4s8-1.79 8-4V7M4 7c0 2.21 3.582 4 8 4s8-1.79 8-4M4 7c0-2.21 3.582-4 8-4s8 1.79 8 4m0 5c0 2.21-3.582 4-8 4s-8-1.79-8-4"></path></svg><?php endif; ?>
                                </div>
                            </div>
                            <p class="text-xs text-slate-400 mb-4 truncate"><?php echo htmlspecialchars($row['link_dataset']); ?></p>
                            <div class="mt-auto pt-3 border-t border-gray-50 flex justify-between items-center">
                                <span class="text-[10px] text-gray-400 font-mono capitalize"><?php echo $row['jenis_dataset']; ?></span>
                                <span class="text-[10px] bg-gray-100 text-gray-500 px-2 py-0.5 rounded">Detail</span>
                            </div>
                        </div>
                    <?php endwhile; ?>
                <?php else: ?>
                    <div class="col-span-full py-12 text-center"><p class="text-slate-400 text-sm">Tidak ada dataset di tag ini.</p></div>
                <?php endif; ?>
            </div>

            <script>
                function switchTab(type) {
                    const btnPaper = document.getElementById('btn-paper'); const btnDataset = document.getElementById('btn-dataset');
                    const contentPaper = document.getElementById('content-paper'); const contentDataset = document.getElementById('content-dataset');
                    const activeClass = ['bg-slate-900', 'text-white', 'shadow-lg', 'shadow-slate-900/20'];
                    const inactiveClass = ['bg-white', 'text-slate-500', 'hover:bg-gray-50', 'border', 'border-gray-200'];

                    if (type === 'paper') {
                        btnPaper.classList.add(...activeClass); btnPaper.classList.remove(...inactiveClass);
                        btnDataset.classList.add(...inactiveClass); btnDataset.classList.remove(...activeClass);
                        contentPaper.classList.remove('hidden'); contentDataset.classList.add('hidden');
                    } else {
                        btnDataset.classList.add(...activeClass); btnDataset.classList.remove(...inactiveClass);
                        btnPaper.classList.add(...inactiveClass); btnPaper.classList.remove(...activeClass);
                        contentDataset.classList.remove('hidden'); contentPaper.classList.add('hidden');
                    }
                }
            </script>

        <?php endif; ?>

    </div>

    <?php include 'custompopup.php'; ?>

    <script>
        // Navbar Toggle
        const btn = document.getElementById('mobile-menu-btn');
        const menu = document.getElementById('mobile-menu');
        btn.addEventListener('click', () => { menu.classList.toggle('hidden'); });

        function resetModalSize() {
            const modalPanel = document.getElementById('modalPanel');
            if(modalPanel) { modalPanel.classList.remove('sm:max-w-6xl'); modalPanel.classList.add('sm:max-w-lg'); }
        }

        // --- COPY LOGIC ---
        function copyCode(btn) {
            navigator.clipboard.writeText(btn.nextElementSibling.innerText).then(() => { 
                const original = btn.innerHTML; 
                btn.innerHTML = `<svg class="w-4 h-4 text-green-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path></svg>`; 
                setTimeout(() => { btn.innerHTML = original; }, 2000); 
            });
        }

        // --- POPUP PAPER ---
        function showPaperPopup(title, authorRaw, doi, link_paper, link_upload, journal, year, volume, p_start, p_end, publisher, abstract) {
            resetModalSize();
            const headerActions = document.getElementById('modalHeaderActions');
            if(headerActions) headerActions.innerHTML = ''; 

            // Format Pages
            let pagesDisplay = "";
            if(p_start && p_end) pagesDisplay = `${p_start}-${p_end}`;
            else if(p_start) pagesDisplay = p_start;
            
            // Format BibTeX Pages
            let pagesBib = "";
            if(p_start && p_end) pagesBib = `${p_start}--${p_end}`;
            else if(p_start) pagesBib = p_start;

            // Format Authors untuk Bibtex & APA
            let authBib = "Unknown";
            let authAPA = "Unknown";
            try {
                if (authorRaw.startsWith('[') || authorRaw.startsWith('{')) {
                    const parsed = JSON.parse(authorRaw);
                    authBib = parsed.map(a => `${a.last}, ${a.first}`).join(' and ');
                    authAPA = parsed.map(a => {
                        const init = a.first ? a.first[0] + '.' : '';
                        return `${a.last}, ${init}`;
                    }).join(' & ');
                } else {
                    authBib = authorRaw;
                    authAPA = authorRaw;
                }
            } catch(e) { authBib = authorRaw; authAPA = authorRaw; }

            const bibtex = `@article{${authBib.split(',')[0].toLowerCase().replace(/[^a-z]/g, '')}_${year},\n  title={${title}},\n  author={${authBib}},\n  journal={${journal}},\n  volume={${volume}},\n  pages={${pagesBib}},\n  year={${year}},\n  publisher={${publisher}},\n  doi={${doi}},\n  url={${link_paper || link_upload}}\n}`;
            
            const apaYear = year ? `(${year})` : '(n.d.)';
            const apaJournal = journal ? `<span class="italic">${journal}</span>` : '';
            const apaVol = volume ? `, <span class="italic">${volume}</span>` : '';
            const apaPage = pagesDisplay ? `, ${pagesDisplay}` : '';
            const apaDoi = doi ? ` https://doi.org/${doi}` : (link_paper ? ` ${link_paper}` : '');
            const apaText = `${authAPA} ${apaYear}. ${title}. ${apaJournal}${apaVol}${apaPage}.${apaDoi}`;

            let actionButtons = '';
            if (doi) actionButtons += `<a href="https://doi.org/${doi}" target="_blank" class="flex-1 flex items-center justify-center py-3 rounded-xl bg-white text-slate-700 font-bold hover:bg-gray-50 transition shadow-sm border border-gray-300">DOI</a>`;
            if (link_paper) actionButtons += `<a href="${link_paper}" target="_blank" class="flex-1 flex items-center justify-center py-3 rounded-xl bg-white text-slate-700 font-bold hover:bg-gray-50 transition shadow-sm border border-gray-300">Link Jurnal</a>`;
            if (link_upload) actionButtons += `<a href="${link_upload}" target="_blank" class="flex-1 flex items-center justify-center py-3 rounded-xl bg-white text-slate-700 font-bold hover:bg-gray-50 transition shadow-sm border border-gray-300">Download PDF</a>`;
            const buttonContainer = actionButtons ? `<div class="flex flex-col lg:flex-row gap-3 mb-6 w-full">${actionButtons}</div>` : '';

            let metaHtml = `<div><label class="block text-slate-500 text-[10px] font-bold uppercase tracking-wider mb-1 font-heading">Judul</label><p class="text-slate-800 font-medium text-lg leading-tight">${title}</p></div>`;
            metaHtml += `<div><label class="block text-slate-500 text-[10px] font-bold uppercase tracking-wider mb-1 font-heading">Penulis</label><p class="text-slate-800 font-medium text-sm">${authAPA}</p></div>`;
            
            if(journal) metaHtml += `<div><label class="block text-slate-500 text-[10px] font-bold uppercase tracking-wider mb-1 font-heading">Jurnal</label><p class="text-slate-800 font-medium text-sm">${journal} ${volume ? `Vol. ${volume}` : ''} ${pagesDisplay ? `pp. ${pagesDisplay}` : ''}</p></div>`;
            
            if(abstract) metaHtml += `<div><label class="block text-slate-500 text-[10px] font-bold uppercase tracking-wider mb-1 font-heading">Abstract</label><p class="text-slate-600 font-body text-sm leading-relaxed text-justify bg-slate-50 p-3 rounded-lg border border-gray-100 max-h-40 overflow-y-auto">${abstract}</p></div>`;

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

        // --- POPUP DATASET ---
        function showDatasetPopup(nama, link, jenis) {
            resetModalSize(); 
            const headerActions = document.getElementById('modalHeaderActions');
            if(headerActions) headerActions.innerHTML = ''; 

            let caraPakaiHtml = '';
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

            const detailHtml = `<div class="text-left space-y-5"><div><label class="block text-slate-500 text-[10px] font-bold uppercase tracking-wider mb-1 font-heading">Nama Dataset</label><p class="text-slate-800 font-medium text-lg leading-tight break-words font-body">${nama}</p></div><div><label class="block text-slate-500 text-[10px] font-bold uppercase tracking-wider mb-1 font-heading">Link Dataset</label><a href="${link}" target="_blank" class="text-blue-600 hover:underline text-sm break-all flex items-center gap-1 font-body">${link}<svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"></path></svg></a></div>${caraPakaiHtml}</div>`;
            document.getElementById('modalContent').innerHTML = detailHtml;
            openModal('Informasi Dataset');
        }
    </script>

</body>
</html>