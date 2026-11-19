<div>
    <x-automation-nav />
    <main class="page-shell">
        <section class="page-heading">
            <div><div class="eyebrow">SoundOn Drafts</div><h1 class="page-title">Hapus Draft SoundOn</h1><p class="page-subtitle">Pending, Under Review, Revision, Rejected, dan Uploading dilindungi. Judul yang sama juga dilindungi meski nama artis berbeda.</p></div>
            <button type="button" wire:click="scan" wire:loading.attr="disabled" class="primary-button">Periksa Draft</button>
        </section>
        @if ($message)<div class="panel mb-5 p-4 text-sm" role="status">{{ $message }}</div>@endif
        <div wire:loading class="panel mb-5 p-4">Memeriksa semua halaman; mohon tunggu…</div>
        <section class="panel overflow-hidden"><div class="overflow-x-auto"><table class="w-full min-w-[760px] text-left text-sm">
            <thead class="bg-slate-50 text-xs uppercase tracking-wide text-slate-500"><tr><th class="px-5 py-3">Rilisan</th><th class="px-5 py-3">Artis</th><th class="px-5 py-3">Track</th><th class="px-5 py-3">Pemeriksaan</th><th class="px-5 py-3">Aksi</th></tr></thead>
            <tbody class="divide-y divide-slate-100">
            @forelse ($items as $item)
                <tr wire:key="draft-{{ $item['draft_id'] }}"><td class="px-5 py-3">{{ $item['title'] }}</td><td class="px-5 py-3">{{ $item['artist'] }}</td><td class="px-5 py-3">{{ $item['track_count'] }}</td><td class="px-5 py-3">{{ $item['reason'] }}</td><td class="px-5 py-3">
                    @if ($item['eligible'])<button type="button" class="secondary-button text-rose-700" wire:click="deleteDraft('{{ $item['draft_id'] }}')" wire:confirm="Hapus draft {{ $item['title'] }} dari SoundOn? Tindakan ini mungkin tidak dapat dibatalkan. Status Soundfresh akan diperiksa ulang sebelum dihapus." wire:loading.attr="disabled">Hapus draft</button>@else<span>Dilindungi</span>@endif
                </td></tr>
            @empty<tr><td colspan="5" class="px-5 py-10 text-center">Tekan Periksa Draft untuk memuat data.</td></tr>@endforelse
            </tbody></table></div></section>
    </main>
</div>
