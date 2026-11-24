<div wire:init="checkForUpdate(true)" @if($popup) wire:poll.60s="checkForUpdate(true)" @endif>
    @if($popup)
        @if($showPopup)
            <dialog x-data x-init="$el.showModal()" x-on:cancel.prevent="if (!$el.querySelector('[data-update-dismiss]').disabled) { $el.close(); $wire.dismissPopup() }" aria-labelledby="system-update-title" aria-describedby="system-update-description" class="system-update-dialog m-auto max-h-[90vh] w-[calc(100%-2rem)] max-w-lg overflow-y-auto rounded-2xl bg-white p-6 text-slate-900 shadow-xl backdrop:bg-slate-950/50">
                <span class="badge badge--success">Update tersedia</span>
                <h2 id="system-update-title" class="mt-4 text-xl font-bold">Sistem versi terbaru tersedia</h2>
                <p class="my-4 text-2xl font-bold">v{{ $currentVersion }} <span aria-label="ke">→</span> v{{ $latestVersion }}</p>
                <p id="system-update-description" class="text-sm leading-6 text-slate-600">Klik Update Sistem. Pembaruan diunduh dan dipasang otomatis, lalu aplikasi dibuka kembali. Tidak perlu mengunduh atau menjalankan EXE secara manual. Data dan sesi login tetap dipertahankan.</p>
                    <p class="mt-3 text-sm leading-6 text-slate-600">Selesaikan upload dan pemeriksaan rilisan sebelum memperbarui aplikasi.</p>
                @if($message)
                    <p class="mt-4 rounded-lg bg-slate-100 p-3 text-sm leading-6" role="status">{{ $message }}</p>
                @endif
                <p wire:loading wire:target="downloadAndInstall" class="mt-4 text-sm leading-6" role="status">Mengunduh dan memverifikasi pembaruan. Jangan tutup aplikasi...</p>
                <div class="mt-6 flex flex-wrap justify-end gap-3">
                    <button type="button" data-update-dismiss wire:click="dismissPopup" wire:loading.attr="disabled" @disabled($installStarted) class="secondary-button">Nanti</button>
                        <button type="button" wire:click="downloadAndInstall" wire:loading.attr="disabled" @disabled(!$installerUrl || $installStarted) class="primary-button" autofocus>
                            <span wire:loading.remove wire:target="downloadAndInstall">Update Sistem</span>
                            <span wire:loading wire:target="downloadAndInstall">Menyiapkan update...</span>
                        </button>
                </div>
            </dialog>
        @endif
    @else
    <x-automation-nav />
    <main class="page-shell">
        <section class="page-heading">
            <div>
                <div class="eyebrow">Application maintenance</div>
                <h1 class="page-title">Update Aplikasi</h1>
                <p class="page-subtitle">Perbarui SoundMatic langsung dari aplikasi tanpa mengunduh dan menjalankan EXE secara manual.</p>
            </div>
            <span class="badge badge--brand">Versi {{ $currentVersion }}</span>
        </section>

        @if ($message)
            <div class="info-banner mb-6" role="status"><strong>Status update</strong><span>{{ $message }}</span></div>
        @endif
        @if($updatedFrom)
            <div class="info-banner mb-6" role="status"><strong>Sistem berhasil diperbarui</strong><span>v{{ $updatedFrom }} → v{{ $currentVersion }}. Pembaruan telah selesai dipasang.</span></div>
        @endif

        <article class="panel overflow-hidden">
            <header class="panel-header">
                <div>
                    <h2 class="panel-title">GitHub Update</h2>
                    <p class="panel-copy">Repository: {{ config('automation.update_repository') }}</p>
                </div>
                @if($updateAvailable)
                    <span class="badge badge--success">Update tersedia</span>
                @else
                    <span class="badge badge--brand">Versi lokal {{ $currentVersion }}</span>
                @endif
            </header>
            <div class="grid gap-5 p-5 md:grid-cols-2">
                <div>
                    <p class="text-xs font-semibold text-slate-500">Versi terpasang</p>
                    <p class="mt-2 text-2xl font-bold text-slate-800">v{{ $currentVersion }}</p>
                </div>
                <div>
                    <p class="text-xs font-semibold text-slate-500">Versi GitHub terbaru</p>
                    <p class="mt-2 text-2xl font-bold text-slate-800">{{ $latestVersion ? 'v'.$latestVersion : 'Belum dicek' }}</p>
                    @if($installerName)<p class="mt-1 text-xs text-slate-400">{{ $installerName }}</p>@endif
                </div>
            </div>
            <div class="flex flex-wrap items-center gap-3 border-t border-slate-100 p-5">
                <button type="button" wire:click="checkForUpdate" wire:loading.attr="disabled" wire:target="checkForUpdate" class="secondary-button">
                    <span wire:loading.remove wire:target="checkForUpdate">Cek Update</span>
                    <span wire:loading wire:target="checkForUpdate">Mengecek GitHub...</span>
                </button>
                <button type="button" wire:click="downloadAndInstall" wire:confirm="Perbarui sistem sekarang? Pembaruan diunduh dan dipasang otomatis, lalu aplikasi dibuka kembali. Selesaikan proses rilisan terlebih dahulu." wire:loading.attr="disabled" wire:target="downloadAndInstall" @disabled(! $updateAvailable || ! $installerUrl || $installStarted) class="primary-button">
                    <span wire:loading.remove wire:target="downloadAndInstall">Update Sistem</span>
                    <span wire:loading wire:target="downloadAndInstall">Download update...</span>
                </button>
                @if($releaseUrl)<a href="{{ $releaseUrl }}" target="_blank" rel="noopener noreferrer" class="text-xs font-semibold text-indigo-600">Lihat GitHub Release ↗</a>@endif
            </div>
            <div class="border-t border-slate-100 px-5 pb-5" aria-live="polite">
                <div class="mb-2 flex items-center justify-between text-xs font-semibold text-slate-500">
                    <span>Progress download update</span>
                    <span wire:loading.remove wire:target="downloadAndInstall">Siap</span>
                    <span wire:loading wire:target="downloadAndInstall">Sedang mengunduh dan memverifikasi...</span>
                </div>
                <div class="h-2 overflow-hidden rounded-full bg-slate-100" role="progressbar" aria-label="Progress download update">
                    <div wire:loading.remove wire:target="downloadAndInstall" class="h-full w-0 rounded-full bg-indigo-600"></div>
                    <div wire:loading wire:target="downloadAndInstall" class="h-full w-2/3 animate-pulse rounded-full bg-indigo-600"></div>
                </div>
                <p class="mt-2 text-xs text-slate-400">Aplikasi otomatis ditutup setelah download selesai, installer terbuka otomatis, lalu file installer dihapus otomatis setelah update.</p>
            </div>
        </article>

        <div class="info-banner mt-6"><strong>Syarat update</strong><span>GitHub Release harus memakai tag versi seperti v1.1.41 dan memiliki asset installer bernama SoundMatic-Setup-v1.1.41.exe.</span></div>
    </main>
    @endif

    @script
    <script>
        $wire.on('soundmatic-update-ready', ({ path }) => {
            if (!window.chrome?.webview || typeof path !== 'string' || path.length === 0) {
                return;
            }

            window.chrome.webview.postMessage(JSON.stringify({
                type: 'soundmatic-update-ready',
                path,
            }));
        });
    </script>
    @endscript
</div>
