<?php
session_start();
require_once 'config.php';

$is_logged_in = isset($_SESSION['user']);
$user = $is_logged_in ? $_SESSION['user'] : null;

if ($is_logged_in && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action_type'] ?? 'create';
    $email = $user['email'];
    if ($action === 'create') {
        $nama = $_POST['nama_dataset'];
        $jenis = $_POST['jenis_dataset'];
        $link = $_POST['link_dataset'];
        $stmt = $conn->prepare("INSERT INTO datasets (user_email, nama_dataset, jenis_dataset, link_dataset, status) VALUES (?, ?, ?, ?, 'active')");
        $stmt->bind_param("ssss", $email, $nama, $jenis, $link);
        if ($stmt->execute()) { header("Location: index?success=create"); exit(); }
    }
}

// --- LOGIC: GABUNGKAN DATASET & PAPER ---
$recent_items = [];
if ($is_logged_in) {
    $email = $user['email'];
    
    // 1. Ambil 5 Dataset Terbaru
    $stmt_d = $conn->prepare("SELECT *, 'dataset' as type FROM datasets WHERE user_email = ? AND status = 'active' ORDER BY created_at DESC LIMIT 5");
    $stmt_d->bind_param("s", $email);
    $stmt_d->execute();
    $res_d = $stmt_d->get_result();
    while($row = $res_d->fetch_assoc()) { $recent_items[] = $row; }

    // 2. Ambil 5 Paper Terbaru
    $stmt_p = $conn->prepare("SELECT *, 'paper' as type FROM papers WHERE user_email = ? AND status = 'active' ORDER BY created_at DESC LIMIT 5");
    $stmt_p->bind_param("s", $email);
    $stmt_p->execute();
    $res_p = $stmt_p->get_result();
    while($row = $res_p->fetch_assoc()) { $recent_items[] = $row; }

    // 3. Gabung dan Urutkan Ulang berdasarkan created_at (Terbaru di atas)
    usort($recent_items, function($a, $b) {
        return strtotime($b['created_at']) - strtotime($a['created_at']);
    });

    // 4. Ambil 10 teratas saja
    $recent_items = array_slice($recent_items, 0, 10); 
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Narrasheet - Kelola Aset Penelitian</title>
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
        
        /* SEMBUNYIKAN SCROLLBAR DI SEMUA BROWSER */
        .no-scrollbar::-webkit-scrollbar {
            display: none;
        }
        .no-scrollbar {
            -ms-overflow-style: none;  /* IE and Edge */
            scrollbar-width: none;  /* Firefox */
        }
    </style>
</head>
<body class="bg-gray-50 text-slate-800">

    <nav class="bg-white/80 backdrop-blur-md sticky top-0 z-50 border-b border-gray-100">
        <div class="container mx-auto px-6 h-20 flex justify-between items-center">
            <a href="index" class="z-50">
                <img src="https://cdn.ivanaldorino.web.id/narrasheet/narrasheet_long.png" alt="Narrasheet" class="h-8 md:h-10 w-auto">
            </a>

            <button id="mobile-menu-btn" class="md:hidden z-50 text-slate-600 focus:outline-none p-2 rounded-lg hover:bg-gray-100">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"></path>
                </svg>
            </button>

            <div class="hidden md:flex items-center gap-6">
                <?php if ($is_logged_in): ?>
                    <a href="dataset" class="text-sm font-medium text-slate-600 hover:text-blue-600 transition font-heading">Dataset</a>
                    <a href="paper" class="text-sm font-medium text-slate-600 hover:text-blue-600 transition font-heading">Paper</a>
                    <a href="tags" class="text-sm font-medium text-slate-600 hover:text-blue-600 transition font-heading">Tags</a>
                    <div class="h-8 w-[1px] bg-gray-200 mx-2 block"></div>
                    <div class="flex items-center gap-3">
                        <img src="<?php echo !empty($user['profile_pic']) ? $user['profile_pic'] : 'https://ui-avatars.com/api/?name='.urlencode($user['username']); ?>" 
                             class="w-9 h-9 rounded-full border border-gray-200 object-cover shadow-sm">
                        <a href="logout.php" class="text-sm font-medium text-red-500 hover:text-red-600 font-heading">Logout</a>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <div id="mobile-menu" class="hidden md:hidden absolute top-full left-0 w-full bg-white border-b border-gray-100 shadow-xl py-4 px-6 flex flex-col gap-4 animate-fade-in-down">
            <?php if ($is_logged_in): ?>
                <a href="dataset" class="text-base font-medium text-slate-700 py-2 border-b border-gray-50">Dataset</a>
                <a href="paper" class="text-base font-medium text-slate-600 py-2 border-b border-gray-50">Paper</a>
                <a href="tags" class="text-base font-medium text-slate-600 py-2 border-b border-gray-50">Tags</a>
                
                <div class="flex items-center gap-3 pt-2">
                    <img src="<?php echo !empty($user['profile_pic']) ? $user['profile_pic'] : 'https://ui-avatars.com/api/?name='.urlencode($user['username']); ?>" class="w-10 h-10 rounded-full border border-gray-200 object-cover">
                    <div class="flex flex-col">
                        <span class="text-sm font-bold text-slate-800"><?php echo htmlspecialchars($user['username']); ?></span>
                        <a href="logout.php" class="text-xs font-bold text-red-500">Logout</a>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </nav>

    <?php if (!$is_logged_in): ?>
        <div class="container mx-auto px-6 min-h-[80vh] flex flex-col justify-center items-center text-center">
            <span class="px-4 py-1.5 rounded-full bg-blue-50 text-blue-600 text-xs font-bold tracking-wide uppercase mb-6 border border-blue-100 font-heading">Early Access</span>
            <h1 class="text-4xl md:text-7xl font-bold text-slate-900 mb-6 leading-tight">Simpan Dataset & Paper<br><span class="text-transparent bg-clip-text bg-gradient-to-r from-blue-600 to-cyan-600">Dalam Satu Tempat.</span></h1>
            <p class="text-base md:text-lg text-slate-500 max-w-2xl mb-10 leading-relaxed font-body">Narrasheet membantu peneliti dan developer mengelola aset data dan referensi jurnal dengan mudah, terstruktur, dan aman.</p>
            <div class="flex gap-4"><a href="login.php" class="px-8 py-4 bg-blue-600 text-white font-semibold rounded-xl hover:bg-blue-700 transition shadow-xl shadow-blue-600/20 hover:-translate-y-1 font-heading">Mulai Sekarang</a></div>
        </div>
    <?php else: ?>
        <div class="container mx-auto px-6 py-8 md:py-12">
            <div class="mb-8 md:mb-12">
                <h2 class="text-2xl md:text-3xl font-bold text-slate-800 mb-2">Selamat Datang, <span class="text-blue-600"><?php echo htmlspecialchars($user['username'] ?? 'User'); ?></span>! 👋</h2>
                <p class="text-sm md:text-base text-slate-500 font-body">Lanjutkan penelitian Anda hari ini.</p>
            </div>

            <?php if(isset($_GET['success'])): ?>
                <div id="successAlert" class="text-green-700 bg-green-100 border border-green-200 px-4 py-3 rounded-xl text-sm font-medium mb-6 flex items-center gap-2 transition-opacity duration-500 ease-out opacity-100 font-body"><svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path></svg>Dataset berhasil disimpan!</div>
            <?php endif; ?>

            <div class="mb-8">
                <div class="flex justify-between items-end mb-6"><h3 class="text-lg md:text-xl font-bold text-slate-800">Terakhir Ditambahkan</h3></div>
                <?php if (empty($recent_items)): ?>
                    <div class="p-8 border-2 border-dashed border-gray-200 rounded-2xl text-center"><p class="text-gray-400 mb-4 font-body">Belum ada item aktif.</p><button onclick="openInputModal()" class="text-blue-600 font-bold hover:underline font-heading">Tambah Dataset Baru +</button></div>
                <?php else: ?>
                    <div id="horizontalScroll" class="flex gap-4 md:gap-6 overflow-x-auto pb-6 no-scrollbar snap-x items-stretch">
                        <?php foreach($recent_items as $data): ?>
                        <?php
                            $is_dataset = ($data['type'] == 'dataset');
                            $type_label = strtoupper($data['type']); 
                            $badge_class = $is_dataset ? 'bg-blue-50 text-blue-700 border-blue-100' : 'bg-purple-50 text-purple-700 border-purple-100';
                            
                            if ($is_dataset) {
                                // --- DATASET LOGIC ---
                                $title_display = htmlspecialchars($data['nama_dataset']);
                                $sub_display = htmlspecialchars($data['link_dataset']);
                                $icon_url = match($data['jenis_dataset']) { 'kaggle' => 'https://cdn.simpleicons.org/kaggle/20BEFF', 'huggingface' => 'https://cdn.simpleicons.org/huggingface/FFD21E', 'openml' => 'https://cdn.ivanaldorino.web.id/narrasheet/openml.svg', default => null };
                                $js_nama = htmlspecialchars($data['nama_dataset'], ENT_QUOTES); 
                                $js_link = htmlspecialchars($data['link_dataset'], ENT_QUOTES); 
                                $js_jenis = htmlspecialchars($data['jenis_dataset'], ENT_QUOTES);
                                $onclick = "showDetail('$js_nama', '$js_link', '$js_jenis')";
                            } else {
                                // --- PAPER LOGIC (UPDATED FOR JSON AUTHORS) ---
                                $title_display = htmlspecialchars($data['title']);
                                
                                // Parse JSON Author untuk Tampilan Card (misal: Doe, J.)
                                $rawAuthor = $data['author'];
                                $authorsArray = json_decode($rawAuthor, true);
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
                                    $sub_display = htmlspecialchars(implode(", ", $tempArr));
                                } else {
                                    $sub_display = htmlspecialchars($rawAuthor);
                                }

                                $icon_url = null;
                                
                                // Variables for Popup (Pass raw JSON author)
                                $js_title = htmlspecialchars($data['title'], ENT_QUOTES);
                                $js_author = htmlspecialchars($data['author'], ENT_QUOTES); // Raw JSON for script
                                $js_doi = htmlspecialchars($data['doi'] ?? '', ENT_QUOTES);
                                $js_link_paper = htmlspecialchars($data['link_paper'] ?? '', ENT_QUOTES);
                                $js_link_upload = htmlspecialchars($data['link_upload'] ?? '', ENT_QUOTES);
                                $js_journal = htmlspecialchars($data['journal_name'] ?? '', ENT_QUOTES);
                                $js_year = htmlspecialchars($data['publish_year'] ?? '', ENT_QUOTES);
                                $js_publisher = htmlspecialchars($data['publisher'] ?? '', ENT_QUOTES);
                                $onclick = "showPaperPopup('$js_title', '$js_author', '$js_doi', '$js_link_paper', '$js_link_upload', '$js_journal', '$js_year', '$js_publisher')";
                            }
                        ?>
                        <div onclick="<?php echo $onclick; ?>" class="snap-start shrink-0 w-64 md:w-72 group bg-white p-5 rounded-2xl border border-gray-100 shadow-sm hover:shadow-xl hover:shadow-blue-900/5 transition duration-300 flex flex-col hover:-translate-y-1 cursor-pointer">
                            <div class="flex items-center justify-between mb-3">
                                <span class="px-2.5 py-1 rounded-md text-[10px] font-bold tracking-wider border <?php echo $badge_class; ?> font-heading"><?php echo $type_label; ?></span>
                                <div class="w-6 h-6 shrink-0 opacity-70 group-hover:opacity-100 transition flex items-center justify-center">
                                    <?php if($is_dataset && $icon_url): ?>
                                        <img src="<?php echo $icon_url; ?>" alt="Icon" class="w-full h-full object-contain">
                                    <?php elseif(!$is_dataset): ?>
                                        <svg class="w-5 h-5 text-purple-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path></svg>
                                    <?php else: ?>
                                        <svg class="w-full h-full text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 7v10c0 2.21 3.582 4 8 4s8-1.79 8-4V7M4 7c0 2.21 3.582 4 8 4s8-1.79 8-4M4 7c0-2.21 3.582-4 8-4s8 1.79 8 4m0 5c0 2.21-3.582 4-8 4s-8-1.79-8-4"></path></svg>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="flex-1">
                                <h4 class="text-base md:text-lg font-bold text-slate-800 leading-tight mb-2 font-heading group-hover:text-blue-600 transition line-clamp-2"><?php echo $title_display; ?></h4>
                                <p class="text-xs text-slate-400 font-body truncate"><?php echo $sub_display; ?></p>
                            </div>
                            <div class="pt-4 border-t border-gray-50 mt-4 font-body flex justify-between items-center text-xs text-slate-400">
                                <span><?php echo date('d M Y', strtotime($data['created_at'])); ?></span>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 md:gap-6 mt-4 md:mt-8">
                <div class="group bg-gradient-to-br from-blue-600 to-cyan-600 rounded-2xl p-6 md:p-8 text-white flex justify-between items-center shadow-lg shadow-blue-600/20 hover:shadow-blue-600/40 transition-all duration-300">
                    <div>
                        <h3 class="text-lg md:text-xl font-bold mb-2 font-heading">Upload Dataset</h3>
                        <p class="text-blue-100 text-sm mb-4 font-body">Simpan link dataset baru.</p>
                        <a href="dataset" class="inline-block bg-white text-blue-600 px-5 py-2 rounded-lg text-sm font-bold hover:bg-blue-50 transition font-heading shadow-md group-hover:shadow-lg group-hover:-translate-y-0.5 transform duration-300">Tambah +</a>
                    </div>
                    <div class="text-5xl md:text-6xl opacity-20 transition-all duration-500 ease-out group-hover:scale-125 group-hover:-rotate-12 group-hover:opacity-40">📦</div>
                </div>

                <div class="group bg-gradient-to-br from-blue-600 to-cyan-600 rounded-2xl p-6 md:p-8 text-white flex justify-between items-center shadow-lg shadow-blue-600/20 hover:shadow-blue-600/40 transition-all duration-300">
                    <div>
                        <h3 class="text-lg md:text-xl font-bold mb-2 font-heading">Tambah Paper</h3>
                        <p class="text-blue-100 text-sm mb-4 font-body">Simpan jurnal referensi.</p>
                        <a href="paper" class="inline-block bg-white text-blue-600 px-5 py-2 rounded-lg text-sm font-bold hover:bg-blue-50 transition font-heading shadow-md group-hover:shadow-lg group-hover:-translate-y-0.5 transform duration-300">Ke Paper 📄</a>
                    </div>
                    <div class="text-5xl md:text-6xl opacity-20 transition-all duration-500 ease-out group-hover:-translate-y-3 group-hover:rotate-6 group-hover:scale-110 group-hover:opacity-40">📝</div>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <?php include 'custompopup.php'; ?>

    <div id="formSource" class="hidden">
        <form method="POST" action="" class="space-y-4 text-left" id="datasetForm">
            <input type="hidden" name="action_type" id="inputAction" value="create">
            <div><label class="block text-slate-500 text-xs font-bold uppercase tracking-wider mb-2 font-heading">URL Link</label><input type="url" id="inputUrl" name="link_dataset" placeholder="https://..." required class="w-full px-4 py-3 bg-gray-50 border border-gray-200 rounded-xl focus:outline-none focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500 transition text-sm font-body"><p class="text-[10px] text-gray-400 mt-1 font-body">Paste link Kaggle/HuggingFace untuk isi otomatis.</p></div><div class="border-b border-gray-100 my-2"></div><div><label class="block text-slate-500 text-xs font-bold uppercase tracking-wider mb-2 font-heading">Nama Dataset</label><input type="text" id="inputNama" name="nama_dataset" required class="w-full px-4 py-3 bg-gray-50 border border-gray-200 rounded-xl focus:outline-none focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500 transition text-sm font-body"></div><div><label class="block text-slate-500 text-xs font-bold uppercase tracking-wider mb-2 font-heading">Platform</label><div class="relative"><select id="inputJenis" name="jenis_dataset" required class="w-full px-4 py-3 bg-gray-50 border border-gray-200 rounded-xl focus:outline-none focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500 transition appearance-none cursor-pointer text-sm font-body"><option value="lainnya">Lainnya</option><option value="kaggle">Kaggle</option><option value="huggingface">Hugging Face</option><option value="openml">OpenML</option></select><div class="absolute inset-y-0 right-0 flex items-center px-4 pointer-events-none text-gray-500"><svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path></svg></div></div></div><div id="btnContainer" class="pt-2"><button type="submit" id="btnSubmit" class="w-full bg-blue-600 text-white font-bold py-3 rounded-xl hover:bg-blue-700 transition shadow-lg shadow-blue-600/20 text-sm font-heading">Simpan Data</button></div>
        </form>
    </div>

    <script>
        // Navbar Toggle Logic
        const btn = document.getElementById('mobile-menu-btn');
        const menu = document.getElementById('mobile-menu');

        btn.addEventListener('click', () => {
            menu.classList.toggle('hidden');
        });

        document.addEventListener('DOMContentLoaded', function() {
            const successAlert = document.getElementById('successAlert');
            if (successAlert) { setTimeout(() => { successAlert.classList.remove('opacity-100'); successAlert.classList.add('opacity-0'); setTimeout(() => { successAlert.remove(); window.history.replaceState(null, null, window.location.pathname); }, 500); }, 3000); }
            
            // --- SCROLL HORIZONTAL DENGAN MOUSE WHEEL ---
            const scrollContainer = document.getElementById('horizontalScroll');
            if (scrollContainer) {
                scrollContainer.addEventListener('wheel', (evt) => {
                    // Cek jika scrollnya vertikal (biasanya deltaY ada nilainya)
                    if (evt.deltaY !== 0) {
                        evt.preventDefault(); // Matikan scroll halaman ke bawah
                        scrollContainer.scrollLeft += evt.deltaY; // Ubah jadi scroll ke samping
                    }
                });
            }
        });
        function toTitleCase(str) { return str.replace(/\w\S*/g, function(txt) { return txt.charAt(0).toUpperCase() + txt.substr(1).toLowerCase(); }); }
        function setupAutoFillListener(modalTarget) {
            const inputUrl = modalTarget.querySelector('#inputUrl'); const inputNama = modalTarget.querySelector('#inputNama'); const inputJenis = modalTarget.querySelector('#inputJenis');
            if (inputUrl) {
                inputUrl.oninput = function() {
                    const val = this.value.trim(); let slug = ''; let platform = '';
                    if (val.includes('kaggle.com/datasets/')) { const parts = val.replace(/\/$/, '').split('/'); slug = parts[parts.length - 1]; platform = 'kaggle'; }
                    else if (val.includes('huggingface.co/datasets/')) { const parts = val.replace(/\/$/, '').split('/'); slug = parts[parts.length - 1]; platform = 'huggingface'; }
                    if (slug && platform) { inputNama.value = toTitleCase(slug.replace(/[-_]/g, ' ')); inputJenis.value = platform; }
                };
            }
        }
        function openInputModal() {
            const formContent = document.getElementById('formSource').innerHTML;
            const modalTarget = document.getElementById('modalContent');
            const headerActions = document.getElementById('modalHeaderActions');
            if(headerActions) headerActions.innerHTML = ''; 
            if(modalTarget) { modalTarget.innerHTML = formContent; setupAutoFillListener(modalTarget); }
            openModal('Tambah Dataset Baru');
        }
        
        function copyCode(btn) {
            // Logic copy code (termasuk fix mobile)
            const codeContainer = btn.nextElementSibling;
            if (codeContainer) { 
                navigator.clipboard.writeText(codeContainer.innerText).then(() => { 
                    const originalIcon = btn.innerHTML; 
                    btn.innerHTML = `<svg class="w-4 h-4 text-green-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path></svg>`; 
                    setTimeout(() => { btn.innerHTML = originalIcon; }, 2000); 
                }); 
            } else {
                // Fallback jika tidak ada sibling (untuk button copy di popup paper)
                navigator.clipboard.writeText(btn.nextElementSibling?.innerText || btn.parentNode.querySelector('div, pre').innerText).then(() => { 
                    const original = btn.innerHTML; 
                    btn.innerHTML = `<svg class="w-4 h-4 text-green-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path></svg>`; 
                    setTimeout(() => { btn.innerHTML = original; }, 2000); 
                });
            }
        }

        function showDetail(nama, link, jenis) {
            const headerActions = document.getElementById('modalHeaderActions');
            if(headerActions) headerActions.innerHTML = ''; 
            let caraPakaiHtml = '';
            if (jenis === 'kaggle') {
                const match = link.match(/kaggle\.com\/datasets\/([^\/]+\/[^\/?]+)/);
                if (match && match[1]) { const datasetId = match[1]; caraPakaiHtml = `<div class="mt-6 pt-4 border-t border-gray-100"><h4 class="text-xs font-bold uppercase tracking-wider text-slate-500 mb-2 font-heading">Cara Menggunakan (KaggleHub)</h4><div class="relative group bg-slate-900 rounded-xl overflow-hidden"><button onclick="copyCode(this)" class="absolute top-2 right-2 bg-white/10 hover:bg-white/20 text-slate-300 p-1.5 rounded-lg opacity-0 group-hover:opacity-100 transition-opacity" title="Copy Code"><svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 5H6a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2v-1M8 5a2 2 0 002 2h2a2 2 0 002-2M8 5a2 2 0 012-2h2a2 2 0 012 2m0 0h2a2 2 0 012 2v3m2 4H10m0 0l3-3m-3 3l3 3"></path></svg></button><pre class="p-4 text-slate-50 text-xs font-mono overflow-x-auto leading-relaxed"><code>import kagglehub\n# Download latest version\npath = kagglehub.dataset_download("${datasetId}")\nprint("Path to dataset files:", path)</code></pre></div></div>`; }
            } else if (jenis === 'huggingface') {
                const match = link.match(/huggingface\.co\/datasets\/([^\/]+\/[^\/?]+)/);
                if (match && match[1]) { const datasetId = match[1]; caraPakaiHtml = `<div class="mt-6 pt-4 border-t border-gray-100"><h4 class="text-xs font-bold uppercase tracking-wider text-slate-500 mb-2 font-heading">Cara Menggunakan (Hugging Face)</h4><div class="relative group bg-slate-900 rounded-xl overflow-hidden"><button onclick="copyCode(this)" class="absolute top-2 right-2 bg-white/10 hover:bg-white/20 text-slate-300 p-1.5 rounded-lg opacity-0 group-hover:opacity-100 transition-opacity" title="Copy Code"><svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 5H6a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2v-1M8 5a2 2 0 002 2h2a2 2 0 002-2M8 5a2 2 0 012-2h2a2 2 0 012 2m0 0h2a2 2 0 012 2v3m2 4H10m0 0l3-3m-3 3l3 3"></path></svg></button><pre class="p-4 text-slate-5 text-xs font-mono overflow-x-auto leading-relaxed"><code>from datasets import load_dataset\ndataset = load_dataset("${datasetId}")\nprint(dataset)</code></pre></div></div>`; }
            }
            const detailHtml = `<div class="text-left space-y-5"><div><label class="block text-slate-500 text-[10px] font-bold uppercase tracking-wider mb-1 font-heading">Nama Dataset</label><p class="text-slate-800 font-medium text-lg leading-tight break-words font-body">${nama}</p></div><div><label class="block text-slate-500 text-[10px] font-bold uppercase tracking-wider mb-1 font-heading">Link Dataset</label><a href="${link}" target="_blank" class="text-blue-600 hover:underline text-sm break-all flex items-center gap-1 font-body">${link}<svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"></path></svg></a></div>${caraPakaiHtml}</div>`;
            document.getElementById('modalContent').innerHTML = detailHtml;
            openModal('Informasi Dataset');
        }

        function resetModalSize() {
            const modalPanel = document.getElementById('modalPanel');
            if(modalPanel) { modalPanel.classList.remove('sm:max-w-6xl'); modalPanel.classList.add('sm:max-w-lg'); }
        }

        // --- FUNGSI POPUP PAPER (UPDATED) ---
        function showPaperPopup(title, authorRaw, doi, link_paper, link_upload, journal, year, publisher) {
            resetModalSize();
            const headerActions = document.getElementById('modalHeaderActions');
            if(headerActions) headerActions.innerHTML = ''; 

            // Format Authors untuk Bibtex & APA
            let authBib = "Unknown";
            let authAPA = "Unknown";
            try {
                // Cek apakah JSON string
                if (authorRaw.startsWith('[') || authorRaw.startsWith('{')) {
                    const parsed = JSON.parse(authorRaw);
                    // Bibtex: Last, First and Last, First
                    authBib = parsed.map(a => `${a.last}, ${a.first}`).join(' and ');
                    // APA: Last, F. & Last, F.
                    authAPA = parsed.map(a => {
                        const init = a.first ? a.first[0] + '.' : '';
                        return `${a.last}, ${init}`;
                    }).join(' & ');
                } else {
                    authBib = authorRaw;
                    authAPA = authorRaw;
                }
            } catch(e) { authBib = authorRaw; authAPA = authorRaw; }

            const bibtex = `@article{${authBib.split(',')[0].toLowerCase().replace(/[^a-z]/g, '')}_paper,\n  title={${title}},\n  author={${authBib}},\n  journal={${journal}},\n  year={${year}},\n  publisher={${publisher}},\n  doi={${doi}},\n  url={${link_paper || link_upload}}\n}`;
            const apaYear = year ? `(${year})` : '(n.d.)';
            const apaJournal = journal ? `<span class="italic">${journal}</span>` : '';
            const apaDoi = doi ? ` https://doi.org/${doi}` : (link_paper ? ` ${link_paper}` : '');
            const apaText = `${authAPA} ${apaYear}. ${title}. ${apaJournal}${apaJournal ? '.' : ''}${apaDoi}`;

            let actionButtons = '';
            if (doi) actionButtons += `<a href="https://doi.org/${doi}" target="_blank" class="flex-1 flex items-center justify-center py-3 rounded-xl bg-white text-slate-700 font-bold hover:bg-gray-50 transition shadow-sm border border-gray-300">DOI</a>`;
            if (link_paper) actionButtons += `<a href="${link_paper}" target="_blank" class="flex-1 flex items-center justify-center py-3 rounded-xl bg-white text-slate-700 font-bold hover:bg-gray-50 transition shadow-sm border border-gray-300">Link Jurnal</a>`;
            if (link_upload) actionButtons += `<a href="${link_upload}" target="_blank" class="flex-1 flex items-center justify-center py-3 rounded-xl bg-white text-slate-700 font-bold hover:bg-gray-50 transition shadow-sm border border-gray-300">Download PDF</a>`;
            const buttonContainer = actionButtons ? `<div class="flex flex-col lg:flex-row gap-3 mb-6 w-full">${actionButtons}</div>` : '';

            let metaHtml = `<div><label class="block text-slate-500 text-[10px] font-bold uppercase tracking-wider mb-1 font-heading">Judul</label><p class="text-slate-800 font-medium text-lg leading-tight">${title}</p></div>`;
            metaHtml += `<div><label class="block text-slate-500 text-[10px] font-bold uppercase tracking-wider mb-1 font-heading">Penulis</label><p class="text-slate-800 font-medium text-sm">${authAPA}</p></div>`;
            if(journal) metaHtml += `<div><label class="block text-slate-500 text-[10px] font-bold uppercase tracking-wider mb-1 font-heading">Jurnal / Konferensi</label><p class="text-slate-800 font-medium text-sm">${journal} ${year ? `(${year})` : ''}</p></div>`;

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