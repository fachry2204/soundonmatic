<div>
    <x-automation-nav />
    <main class="page-shell">
        <section class="page-heading">
            <div><div class="eyebrow">Platform connections</div><h1 class="page-title">Pengaturan Platform</h1><p class="page-subtitle">Kelola credential terenkripsi dan kesehatan sesi Soundfresh serta SoundOn. Password tersimpan tidak pernah ditampilkan kembali.</p></div>
        </section>

        @if ($message)
            <div class="info-banner mb-6"><strong>Status terbaru</strong><span>{{ $message }}</span></div>
        @endif

        <div class="grid gap-5 lg:grid-cols-2">
            @foreach (['soundfresh' => 'Soundfresh', 'soundon' => 'SoundOn'] as $key => $label)
                @php($account = $accounts->get($key))
                @php($active = $account?->status === 'active')
                @php($statusLabel = match ($account?->status) {
                    'active' => 'Aktif',
                    'credentials_need_reentry' => 'Isi ulang credential',
                    'authentication_failed' => 'Login gagal',
                    'manual_auth_required' => 'Perlu login manual',
                    'expired' => 'Sesi kedaluwarsa',
                    'disabled' => 'Dinonaktifkan',
                    default => 'Belum dikonfigurasi',
                })
                <article class="panel overflow-hidden">
                    <header class="panel-header">
                        <div class="flex items-center gap-3">
                            <span class="grid h-11 w-11 place-items-center rounded-xl {{ $key === 'soundfresh' ? 'bg-violet-100 text-violet-700' : 'bg-blue-100 text-blue-700' }} font-bold">{{ $key === 'soundfresh' ? 'SF' : 'SO' }}</span>
                            <div><h2 class="panel-title">{{ $label }}</h2><p class="panel-copy">Akun sumber {{ $key === 'soundfresh' ? 'metadata rilisan' : 'draft distribusi' }}</p></div>
                        </div>
                        <span class="badge {{ $active ? 'badge--success' : 'badge--warning' }}"><span class="status-dot {{ $active ? 'status-dot--active' : 'status-dot--warning' }}"></span>{{ $statusLabel }}</span>
                    </header>

                    <div class="p-5">
                        <form wire:submit="saveAndLogin('{{ $key }}')" class="space-y-4">
                            <div><label class="form-label">Email akun {{ $label }}</label><input type="email" wire:model="emails.{{ $key }}" autocomplete="username" class="form-input" placeholder="nama@perusahaan.com" required></div>
                            <div><label class="form-label">Password</label><input type="password" wire:model="passwords.{{ $key }}" autocomplete="new-password" placeholder="{{ $credentialAvailability[$key] ?? false ? 'Kosongkan jika tidak diubah' : 'Masukkan password' }}" class="form-input"></div>
                            <button class="primary-button" wire:loading.attr="disabled" wire:target="saveAndLogin('{{ $key }}')"><span wire:loading.remove wire:target="saveAndLogin('{{ $key }}')">Simpan &amp; login</span><span wire:loading wire:target="saveAndLogin('{{ $key }}')">Menyimpan dan login...</span></button>
                        </form>

                        <div class="my-5 h-px bg-slate-100"></div>
                        <dl class="grid grid-cols-3 gap-3 text-xs">
                            <div><dt class="text-slate-400">Credential</dt><dd class="mt-1 font-semibold text-slate-700">{{ $credentialAvailability[$key] ?? false ? 'Tersimpan' : 'Perlu diisi ulang' }}</dd></div>
                            <div><dt class="text-slate-400">Login terakhir</dt><dd class="mt-1 font-semibold text-slate-700">{{ $account?->last_authenticated_at?->diffForHumans() ?? '—' }}</dd></div>
                            <div><dt class="text-slate-400">Kedaluwarsa</dt><dd class="mt-1 font-semibold text-slate-700">{{ $account?->session_expires_at?->format('d M, H:i') ?? '—' }}</dd></div>
                        </dl>

                        <div class="mt-5 flex flex-wrap items-center gap-3">
                            <button type="button" wire:click="refresh('{{ $key }}')" @disabled(! ($credentialAvailability[$key] ?? false)) wire:loading.attr="disabled" wire:target="refresh('{{ $key }}')" class="secondary-button disabled:cursor-not-allowed disabled:opacity-50" title="{{ $credentialAvailability[$key] ?? false ? 'Login menggunakan credential tersimpan' : 'Isi email dan password lalu tekan Simpan & login' }}"><span wire:loading.remove wire:target="refresh('{{ $key }}')">Login / refresh sesi</span><span wire:loading wire:target="refresh('{{ $key }}')">Sedang login...</span></button>
                            <button type="button" wire:click="clear('{{ $key }}')" wire:confirm="Hapus cached session {{ $label }}?" class="text-xs font-semibold text-amber-700">Hapus sesi</button>
                            <button type="button" wire:click="clearCredentials('{{ $key }}')" wire:confirm="Hapus credential dan session {{ $label }}?" class="text-xs font-semibold text-red-600">Hapus credential</button>
                        </div>
                    </div>
                </article>
            @endforeach
        </div>

        <article class="panel mt-6 overflow-hidden">
            <header class="panel-header">
                <div>
                    <h2 class="panel-title">Penyimpanan hasil download</h2>
                    <p class="panel-copy">Cover Art, Audio Master, dan Audio Clip otomatis dihapus setelah draft SoundOn berhasil disimpan.</p>
                </div>
                <span class="badge badge--success">{{ $downloadFiles }} file · {{ $downloadSize }}</span>
            </header>
            <div class="flex flex-wrap items-center gap-3 p-5">
                <button type="button" wire:click="openDownloadFolder" class="secondary-button">Buka folder download</button>
                <button type="button" wire:click="clearDownloadData" wire:confirm="Hapus seluruh Cover Art, Audio Master, Audio Clip, dan cache worker? Semua proses upload yang sedang berjalan atau mengantre akan dihentikan." wire:loading.attr="disabled" wire:target="clearDownloadData" class="primary-button bg-red-600 hover:bg-red-700">
                    <span wire:loading.remove wire:target="clearDownloadData">Hapus data download</span>
                    <span wire:loading wire:target="clearDownloadData">Menghapus...</span>
                </button>
            </div>
        </article>

        <div class="info-banner mt-6"><strong>Keamanan autentikasi</strong><span>Jika muncul CAPTCHA atau OTP, status berubah menjadi manual authentication required. Sistem tidak mencoba melakukan bypass.</span></div>
    </main>
</div>
