<div id="customModal" class="fixed inset-0 z-[100] hidden" aria-labelledby="modal-title" role="dialog" aria-modal="true">
    
    <div class="fixed inset-0 bg-slate-900/40 backdrop-blur-sm transition-opacity opacity-0" id="modalBackdrop"></div>

    <div class="fixed inset-0 z-10 overflow-y-auto">
        <div class="flex min-h-full items-end justify-center p-4 text-center sm:items-center sm:p-0">
            
            <div class="relative transform overflow-hidden rounded-2xl bg-white text-left shadow-2xl transition-all sm:my-8 sm:w-full sm:max-w-lg opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95" id="modalPanel">
                
                <div class="bg-white px-6 py-4 border-b border-gray-100 flex justify-between items-center">
                    <h3 class="text-lg font-bold text-slate-800 font-heading" id="modalTitle">
                        Judul Modal
                    </h3>
                    
                    <div class="flex items-center gap-1">
                        <div id="modalHeaderActions"></div>

                        <button type="button" onclick="closeModal()" class="rounded-full p-2 hover:bg-gray-100 transition text-gray-400 hover:text-gray-600">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
                        </button>
                    </div>
                </div>

                <div class="px-6 py-6">
                    <div id="modalContent">
                        </div>
                </div>

            </div>
        </div>
    </div>
</div>

<script>
    const modal = document.getElementById('customModal');
    const backdrop = document.getElementById('modalBackdrop');
    const panel = document.getElementById('modalPanel');

    function openModal(title) {
        if(title) document.getElementById('modalTitle').innerText = title;
        modal.classList.remove('hidden');
        setTimeout(() => {
            backdrop.classList.remove('opacity-0');
            panel.classList.remove('opacity-0', 'translate-y-4', 'sm:scale-95');
            panel.classList.add('opacity-100', 'translate-y-0', 'sm:scale-100');
        }, 10);
    }

    function closeModal() {
        backdrop.classList.add('opacity-0');
        panel.classList.remove('opacity-100', 'translate-y-0', 'sm:scale-100');
        panel.classList.add('opacity-0', 'translate-y-4', 'sm:scale-95');
        setTimeout(() => {
            modal.classList.add('hidden');
            // Reset Header Actions saat ditutup agar bersih untuk modal berikutnya
            document.getElementById('modalHeaderActions').innerHTML = ''; 
        }, 300);
    }

    backdrop.addEventListener('click', closeModal);
</script>