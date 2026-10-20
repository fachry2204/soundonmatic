<div>
    <x-automation-nav />
    <main class="page-shell">
        <section class="page-heading">
            <div><div class="eyebrow">Soundfresh Review</div><h1 class="page-title">Review Rilisan</h1><p class="page-subtitle">Daftar rilisan diambil dari Soundfresh; Last Update Date dicocokkan dari SoundOn.</p></div>
            <button type="button" wire:click="fetchReviews" wire:loading.attr="disabled" wire:target="fetchReviews" class="primary-button">
                <span wire:loading.remove wire:target="fetchReviews">Ambil Data Review</span><span wire:loading wire:target="fetchReviews">Mengambil data…</span>
            </button>
        </section>

        @if ($message)
            <div class="panel mb-5 p-4 text-sm {{ str_contains($message, 'gagal') || str_contains($message, 'belum') ? 'text-rose-700' : 'text-emerald-700' }}" role="status">{{ $message }}</div>
        @endif

        <section class="panel overflow-hidden">
            <div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-100 p-5">
                <div><h2 class="font-bold text-slate-800">Rilisan Under Review Soundfresh</h2><p class="mt-1 text-sm text-slate-500">{{ count($items) }} data{{ $fetchedAt ? ' · Diambil '.$fetchedAt : '' }}</p></div>
                <span class="badge badge--warning">Soundfresh · Last Update SoundOn</span>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full min-w-[760px] text-left text-sm">
                    <thead class="bg-slate-50 text-xs uppercase tracking-wide text-slate-500"><tr><th class="px-5 py-3">Judul</th><th class="px-5 py-3">Nama Artis</th><th class="px-5 py-3">Tanggal Rilis</th><th class="px-5 py-3">Last Update SoundOn</th></tr></thead>
                    <tbody class="divide-y divide-slate-100">
                        @forelse ($items as $item)
                            <tr>
                                <td class="px-5 py-4 font-semibold text-slate-800">{{ $item['title'] ?? 'Tanpa judul' }}</td>
                                <td class="px-5 py-4 text-slate-600">{{ $item['artist'] ?? '—' }}</td>
                                <td class="px-5 py-4 text-slate-600">{{ $item['release_date'] ?? '—' }}</td>
                                <td class="px-5 py-4 text-slate-600">{{ $item['last_updated'] ?? '—' }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="px-5 py-12 text-center text-slate-500">Belum ada data. Tekan “Ambil Data Review” untuk membaca rilisan Under Review dari SoundOn.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>
    </main>
</div>
