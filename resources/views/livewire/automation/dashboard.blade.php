<div wire:poll.10s.keep-alive="refreshMonitor" class="dashboard-workspace" x-data="{}" data-live-progress="{{ route('automation.progress') }}">
    <x-automation-nav />

    <main class="page-shell">
        @if ($configurationError)
            <div role="alert" class="mb-6 info-banner" style="border-color:#f2d49b;background:#fff9ec;color:#8c5a11">
                <div><strong>Konfigurasi belum lengkap.</strong> {{ $configurationError }} <a class="font-semibold underline" href="{{ route('automation.sessions') }}">Buka Pengaturan</a></div>
            </div>
        @endif

        @if ($uploadMessage)
            <div role="status" class="mb-6 info-banner">
                <div><strong>Upload draft Aggregator.</strong> {{ $uploadMessage }}</div>
            </div>
        @endif

        @if ($operationError)
            <div role="alert" class="mb-6 info-banner" style="border-color:#f4b8bf;background:#fff1f3;color:#a72f3d">
                <div><strong>Proses tidak dapat dilanjutkan.</strong> {{ $operationError }}</div>
            </div>
        @endif

        <section class="page-heading dashboard-hero">
            <div>
                <div class="eyebrow">Dashboard Operasional</div>
                <span class="sr-only">Aggregator Release Automation · Upload ke Aggregator</span><h1 class="page-title">Dashboard Rilisan</h1>
                <p class="page-subtitle">Pantau proses pengunggahan rilisan dari Soundfresh ke Aggregator secara real-time.</p>
            </div>
            @can('automation.run')
                <div class="dashboard-primary-actions">
                    <div class="flex flex-wrap items-center gap-3">
                    <button wire:click="start" wire:loading.attr="disabled" wire:confirm="Bersihkan seluruh data monitor, status Aggregator, notifikasi, dan antrean lama lalu ambil ulang semua rilisan pending?" class="primary-button dashboard-cta">
                        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3v12m0 0 4-4m-4 4-4-4M5 20h14"/></svg>
                        <span wire:loading.remove wire:target="start">Ambil Rilisan</span>
                        <span wire:loading wire:target="start">Menyiapkan...</span>
                    </button>
                    <span class="primary-button" style="background:#0f766e; cursor:default" role="status">
                        {{ $workerOnline ? 'Worker aktif' : 'Worker offline' }}
                    </span>
                    </div>
                    <p class="dashboard-action-hint">Semua rilisan nonduplikat langsung di-upload ke Aggregator setelah pemeriksaan duplikat selesai. Rilisan duplikat tetap diblokir.</p>
                </div>
            @endcan
        </section>

        <section class="metric-grid">
            <button type="button" wire:click="setReleaseTab('all')" aria-pressed="{{ $releaseTab === 'all' ? 'true' : 'false' }}" class="metric-card metric-card--queue" style="--metric-soft:#eef2ff">
                <span class="metric-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M4 7l8-4 8 4-8 4-8-4zm0 5l8 4 8-4M4 17l8 4 8-4"/></svg></span>
                <div><span class="sr-only">Pending Soundfresh</span><p class="metric-label">Diambil</p><p class="metric-value">{{ $releaseCount }}</p><p class="metric-meta">Menunggu diproses</p></div>
            </button>
            <button type="button" wire:click="setReleaseTab('queued')" aria-pressed="{{ $releaseTab === 'queued' ? 'true' : 'false' }}" class="metric-card metric-card--waiting" style="--metric-soft:#eef2ff">
                <span class="metric-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M12 16V4m0 0L8 8m4-4 4 4M5 14v5h14v-5"/></svg></span>
                <div><p class="metric-label">Antrean</p><p class="metric-value">{{ $queuedCount }}</p><p class="metric-meta">Sedang diunggah ke Aggregator</p></div>
            </button>
            <button type="button" wire:click="setReleaseTab('uploaded')" aria-pressed="{{ $releaseTab === 'uploaded' ? 'true' : 'false' }}" class="metric-card metric-card--success" style="--metric-soft:#e8f8f2">
                <span class="metric-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M5 12l4 4L19 6"/></svg></span>
                <div><span class="sr-only">Berhasil upload ke Aggregator</span><p class="metric-label">Berhasil</p><p class="metric-value">{{ $soundonUploadSuccessCount }}</p><p class="metric-meta">Berhasil diunggah</p></div>
            </button>
            <button type="button" wire:click="setReleaseTab('failed')" aria-pressed="{{ $releaseTab === 'failed' ? 'true' : 'false' }}" class="metric-card metric-card--attention" style="--metric-soft:#fff7ed">
                <span class="metric-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M12 8v5m0 4h.01M10.3 4.2L2.6 18a2 2 0 001.7 3h15.4a2 2 0 001.7-3L13.7 4.2a2 2 0 00-3.4 0z"/></svg></span>
                <div><span class="sr-only">Gagal upload ke Aggregator</span><p class="metric-label">Perlu Perhatian</p><p class="metric-value">{{ $soundonUploadFailedCount }}</p><p class="metric-meta">Perlu tindakan agar bisa diunggah</p></div>
            </button>
        </section>

        <div class="worker-strip">
            <strong>Proses aktif</strong>
            <span class="worker-chip {{ $workerOnline ? 'is-online' : '' }}"><i></i>Browser {{ $workerOnline ? 'aktif' : 'offline' }}</span>
            <span class="worker-chip"><i></i>{{ $activeJobsCount }} worker · {{ $queuedCount }} antrean</span>
            <span class="worker-chip"><i></i>Upload otomatis aktif</span>
            @can('automation.run')<button type="button" wire:click="stopAllWorkers" wire:loading.attr="disabled" wire:confirm="Yakin ingin menghentikan SEMUA worker, antrean, dan proses automation yang sedang berjalan?" class="secondary-button worker-stop">Hentikan</button>@endcan
        </div>


        <section class="panel monitor-panel">
            <header class="panel-header">
                <div>
                    <span class="sr-only">Antrian Rilisan</span><h2 class="panel-title panel-title--large">Monitor Rilisan</h2>

                </div>

            </header>
            <div class="monitor-toolbar">
                <label class="monitor-search"><span class="sr-only">Cari rilisan</span><input type="search" wire:model.live.debounce.250ms="releaseSearch" placeholder="Cari judul, artis, atau ID..." aria-label="Cari rilisan"></label>
                <button type="button" wire:click="setReleaseTab('all')" class="px-3 py-1.5 text-xs font-bold rounded-lg transition-all {{ $releaseTab === 'all' ? 'bg-indigo-600 text-white shadow-sm' : 'text-slate-600 hover:bg-slate-200/60' }}">
                    Semua ({{ $totalReleaseJobsCount }})
                </button>
                <button type="button" wire:click="setReleaseTab('uploaded')" class="px-3 py-1.5 text-xs font-bold rounded-lg transition-all {{ $releaseTab === 'uploaded' ? 'bg-emerald-600 text-white shadow-sm' : 'text-slate-600 hover:bg-slate-200/60' }}">
                    Selesai terupload ({{ $uploadedCount }})
                </button>
                <button type="button" wire:click="setReleaseTab('failed')" class="px-3 py-1.5 text-xs font-bold rounded-lg transition-all {{ $releaseTab === 'failed' ? 'bg-red-600 text-white shadow-sm' : 'text-slate-600 hover:bg-slate-200/60' }}">
                    Gagal ({{ $failedCount }})
                </button>
            </div>

            <div wire:loading.flex wire:target="start" class="flex-col gap-3 border-t border-indigo-100 bg-indigo-50 px-5 py-4 text-sm font-semibold text-indigo-700">
                <div class="flex items-center gap-3">
                    <span class="inline-block h-4 w-4 animate-spin rounded-full border-2 border-indigo-200 border-t-indigo-600"></span>
                    Membersihkan monitor dan mengambil rilisan terbaru dari Soundfresh...
                </div>
                <div class="h-2 w-full overflow-hidden rounded-full bg-indigo-100" role="progressbar" aria-label="Mengambil rilisan terbaru">
                    <div class="h-full w-2/3 animate-pulse rounded-full bg-indigo-600"></div>
                </div>
            </div>
            <div wire:loading.remove wire:target="start" class="monitor-total-progress sr-only">
                <div class="mb-2 flex items-center justify-between gap-3 text-xs font-semibold text-slate-500">
                    <span>Progress rilisan terbaru</span>
                    <span>{{ $processedCount }} / {{ $releaseCount }} selesai ({{ $progressPercent }}%)</span>
                </div>
                <div class="h-2 overflow-hidden rounded-full bg-slate-100" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ $progressPercent }}">
                    <div class="h-full rounded-full bg-indigo-600 transition-all duration-500" style="width: {{ $progressPercent }}%"></div>
                </div>
            </div>
            <div wire:loading.remove wire:target="start" class="table-scroll">
                <table class="data-table release-monitor-table">
                    <colgroup>
                        <col class="release-col--title">
                        <col class="release-col--source">
                        <col class="release-col--soundon">
                        <col class="release-col--progress">
                        <col class="release-col--status">
                    </colgroup>
                    <thead><tr><th>Rilisan</th><th>Soundfresh</th><th>Aggregator</th><th>Progres</th><th>Aksi</th></tr></thead>
                    <tbody wire:key="release-monitor-body-{{ $monitorRun?->id ?? 'none' }}-{{ $releaseJobs->isEmpty() ? 'empty' : 'populated' }}">
                    @forelse ($releaseJobs as $job)
                        @php
                            $jobStatus = $job->status->value;
                            $checkpoint = $job->checkpoint->value;
                            $hasMetadata = $job->metadata_snapshot_json !== null;
                            $hasDraft = filled($job->soundon_draft_id);
                            $assetProgress = $job->asset_progress_json ?? [];
                            $displayTitle = filled($job->release_title) && strtolower($job->release_title) !== 'view' ? $job->release_title : 'Rilisan #'.$job->soundfresh_release_id;
                            $releaseTypeLabel = match ($job->release_type) {
                                'ep', 'album' => 'EP/Album',
                                'single' => 'Single',
                                default => ((int) ($job->track_count ?? 1) > 1 ? 'EP/Album' : 'Single'),
                            };
                            $checkpointProgress = [
                                'discovered' => 5,
                                'metadata_extracted' => 25,
                                'assets_downloaded' => 45,
                                'soundon_draft_created' => 60,
                                'metadata_filled' => 70,
                                'assets_uploaded' => 85,
                                'draft_saved' => 95,
                                'soundfresh_reviewed' => 95,
                                'completed' => 100,
                            ];
                            $jobProgress = $jobStatus === 'completed' ? 100 : max((int) ($job->progress_percent ?? 0), ($checkpointProgress[$checkpoint] ?? 0));
                            $progressTone = in_array($jobStatus, ['failed', 'needs_attention', 'manual_auth_required', 'stopped'], true) ? 'release-progress--danger' : ($jobStatus === 'completed' ? 'release-progress--success' : '');
                            $progressLabel = ($jobStatus === 'running' || $job->error_code === 'DUPLICATE_CHECK_PENDING') && filled($job->progress_label) ? $job->progress_label : ($jobStatus === 'stopped' ? 'Proses dihentikan' : ($jobStatus === 'queued' ? match ($checkpoint) {
                                'discovered' => 'Menunggu giliran ekstraksi',
                                'metadata_extracted' => 'Metadata siap — menunggu unduh aset',
                                'assets_downloaded' => 'Aset siap — menunggu giliran Aggregator',
                                'soundon_draft_created' => 'Draft siap — menunggu pengisian',
                                'metadata_filled' => 'Metadata siap — menunggu upload aset',
                                'assets_uploaded' => 'Upload siap — menunggu simpan draft',
                                'draft_saved', 'soundfresh_reviewed' => 'Draft siap — menunggu penyelesaian',
                                'completed' => 'Selesai',
                                default => 'Menunggu giliran',
                            } : match ($checkpoint) {
                                'discovered' => 'Memulai proses',
                                'metadata_extracted' => 'Metadata diekstrak',
                                'assets_downloaded' => 'Aset diunduh',
                                'soundon_draft_created' => 'Draft dibuat',
                                'metadata_filled' => 'Metadata diisi',
                                'assets_uploaded' => 'Aset diunggah',
                                'draft_saved' => 'Draft disimpan',
                                'soundfresh_reviewed' => 'Review selesai',
                                'completed' => 'Selesai',
                                default => str_replace('_', ' ', $checkpoint),
                            }));
                            $statusLabel = match ($jobStatus) {
                                'queued' => 'Menunggu giliran',
                                'running' => 'Sedang diproses',
                                'completed' => 'Selesai',
                                'failed' => 'Gagal',
                                'needs_attention' => 'Perlu perhatian',
                                'manual_auth_required' => 'Perlu login ulang',
                                'stopped' => 'Dihentikan',
                                default => str_replace('_', ' ', $jobStatus),
                            };
                        @endphp
                        @php
                            if ($job->soundfresh_verify_status === 'rejecting' || $job->soundfresh_verify_status === 'rejected'
                                || str_starts_with((string) $job->progress_label, 'Reject')) {
                                $jobProgress = (int) $job->progress_percent;
                                $progressLabel = $job->progress_label ?: 'Memproses Reject di Soundfresh';
                                $progressTone = $job->soundfresh_verify_status === 'rejected' ? 'release-progress--success'
                                    : ($job->soundfresh_verify_status === 'rejecting' ? '' : 'release-progress--danger');
                            }
                        @endphp
                        <tr wire:key="release-monitor-{{ $job->id }}" data-live-job="{{ $job->id }}" data-live-status="{{ $jobStatus }}">
                            <td data-label="Rilisan"><div class="min-w-52"><a href="{{ $job->soundfresh_release_url }}" target="_blank" rel="noopener noreferrer">{{ $displayTitle }}</a><div class="mt-1 flex flex-wrap items-center gap-1.5"><span class="release-type-copy">{{ $releaseTypeLabel }} ·</span><span class="text-[11px] text-slate-400">ID {{ $job->soundfresh_release_id }}@if($job->artist_name) · {{ $job->artist_name }}@endif</span></div></div></td>
                            <td data-label="Soundfresh">
                                @php
                                $soundfreshLabel = match ($job->soundfresh_workflow_status) {
                                    'pending' => 'Pending', 'under_review' => 'Under Review',
                                    'uploading' => 'Uploading', 'verified' => 'Verified', 'rejected' => 'Rejected',
                                    default => 'Sudah diambil',
                                };
                                @endphp
                                <span class="badge {{ $job->soundfresh_workflow_status === 'pending' ? 'badge--warning' : 'badge--success' }}">{{ $soundfreshLabel }}</span>
                                <p class="mt-1 text-[11px] text-slate-400">{{ $hasMetadata ? 'Metadata berhasil dibaca' : 'Menunggu ekstraksi metadata' }}</p>
                            </td>
                            <td data-label="Aggregator">
                                @if ($hasDraft)
                                    @php
                                        $draftLabel = filled($job->soundon_release_status) ? 'Sudah ada di Aggregator' : ($job->soundon_draft_status === 'already_exists'
                                            ? 'Sudah ada di draft Aggregator'
                                            : 'Draft tersimpan');
                                    @endphp
                                    @if ($job->soundon_draft_url)<a href="{{ $job->soundon_draft_url }}" target="_blank" rel="noopener noreferrer" class="badge badge--success">{{ $draftLabel }}</a>@else<span class="badge badge--success">{{ $draftLabel }}</span>@endif
                                    <p class="mt-1 text-[11px] text-slate-400">Draft ID {{ $job->soundon_draft_id }}</p>
                                @elseif(filled($job->soundon_release_status))
                                    <span class="badge badge--success">Sudah ada di Aggregator</span>
                                    <p class="mt-1 text-[11px] text-slate-400">{{ str_replace('_', ' ', ucfirst($job->soundon_release_status)) }}</p>
                                @else
                                    <span class="badge {{ in_array($jobStatus, ['failed','needs_attention','manual_auth_required'], true) ? 'badge--danger' : 'badge--warning' }}">Belum diunggah</span><p class="mt-1 max-w-44 text-[11px] text-slate-400">{{ in_array($jobStatus, ['failed','needs_attention','manual_auth_required'], true) && $job->error_code ? $job->error_code : 'Menunggu proses Aggregator' }}</p>
                                @endif
                            </td>
                            <td data-label="Progres">
                                <div class="release-progress {{ $progressTone }}">
                                    <div class="release-progress__copy"><span>{{ \App\Services\Automation\ReadableReleaseError::displayText($progressLabel) }}</span><strong>{{ $jobProgress }}%</strong></div>
                                    <div class="release-progress__track" role="progressbar" aria-label="Progress {{ $displayTitle }}" aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ $jobProgress }}">
                                        <div class="release-progress__bar" style="width: {{ $jobProgress }}%"></div>
                                    </div>
                                </div>
                                @if(!empty($assetProgress))
                                    <div class="mt-2 grid gap-1 text-[10px] text-slate-500" aria-label="Status unduhan aset {{ $displayTitle }}">
                                        @foreach(['cover', 'audio', 'tiktok_audio'] as $assetType)
                                            @php
                                                $assetItem = $assetProgress[$assetType] ?? ['label' => match($assetType) { 'cover' => 'Cover Art', 'audio' => 'Audio Master', default => 'Audio Clip' }, 'status' => 'pending'];
                                                $assetStatus = $assetItem['status'] ?? 'pending';
                                                $assetIcon = match($assetStatus) { 'completed' => '✓', 'downloading' => '↓', 'failed' => '!', 'unavailable' => '—', default => '○' };
                                                $assetCopy = match($assetStatus) { 'completed' => 'selesai', 'downloading' => 'sedang diunduh', 'failed' => 'gagal', 'unavailable' => 'tidak tersedia', default => 'menunggu' };
                                                $assetError = $assetStatus === 'failed' ? ($assetItem['error'] ?? null) : null;
                                            @endphp
                                            <div title="{{ $assetError }}" class="flex items-center gap-1.5 {{ $assetStatus === 'completed' ? 'font-semibold text-emerald-600' : ($assetStatus === 'failed' ? 'font-semibold text-red-600' : ($assetStatus === 'downloading' ? 'font-semibold text-indigo-600' : '')) }}">
                                                <span aria-hidden="true">{{ $assetIcon }}</span>
                                                <span>{{ $assetItem['label'] }} — {{ $assetCopy }}</span>
                                            </div>
                                        @endforeach
                                    </div>
                                @endif
                            </td>
                            <td data-label="Aksi">
                                <details wire:ignore.self wire:key="release-actions-{{ $job->id }}" class="row-action-details" x-on:open-release-detail.window="if ($event.detail.id === '{{ $job->id }}') $el.open = true" x-on:keydown.escape.window="$el.open = false">
                                    <summary>Detail</summary>
                                    <div class="release-detail-dialog" x-on:click="if ($event.target.closest('button')?.hasAttribute('wire:click')) $el.closest('details').open = false" role="dialog" aria-modal="true" aria-label="Detail {{ $displayTitle }}">
                                        <header><div><h3>{{ $displayTitle }}</h3><p>ID {{ $job->soundfresh_release_id }} · {{ $job->artist_name }}</p></div><button type="button" class="secondary-button" x-on:click="$el.closest('details').open = false" aria-label="Tutup detail">Tutup</button></header>

                                <span title="{{ $jobStatus === 'queued' ? 'Rilisan sudah tersimpan di antrean dan akan diproses setelah rilisan aktif selesai.' : ($jobStatus === 'running' ? 'Rilisan ini sedang dikerjakan oleh automation worker.' : '') }}" class="badge {{ $jobStatus === 'completed' ? 'badge--success' : (in_array($jobStatus, ['failed','needs_attention','manual_auth_required','stopped']) ? 'badge--danger' : 'badge--warning') }}">{{ $statusLabel }}</span>
                                @if(in_array($jobStatus, ['failed','needs_attention','manual_auth_required'], true) && $job->error_message)
                                    <details wire:ignore.self wire:key="error-panel-{{ $job->id }}" class="release-error-box mt-2 rounded-lg border border-red-100 bg-red-50 p-2 text-[11px] text-red-700" title="{{ \App\Services\Automation\ReadableReleaseError::displayText($job->error_message) }}">
                                        @php
                                            $readableError = \App\Services\Automation\ReadableReleaseError::explain($job->error_code, $job->error_message);
                                        @endphp
                                        <summary class="cursor-pointer font-semibold">Lihat detail error</summary><strong class="mt-2 block">{{ $readableError['title'] }}</strong>
                                        <p class="mt-1"><strong>Yang terjadi:</strong> {{ $readableError['reason'] }}</p>
                                        @if($readableError['action'])
                                            <p class="mt-2"><strong>Yang perlu dilakukan:</strong> {{ $readableError['action'] }}</p>
                                        @endif
                                        <details wire:key="release-error-details-{{ $job->id }}" wire:ignore.self class="mt-2"><summary class="cursor-pointer">Log asli untuk bantuan teknis</summary><p class="mt-1 break-words">{{ $job->error_code }} — {{ \App\Services\Automation\ReadableReleaseError::displayText($job->error_message) }}</p></details>
                                    </details>
                                @endif
                                @if($job->soundfresh_verify_status === 'rejecting')
                                    <p class="mt-2 text-sm text-amber-800" role="status">Reject Soundfresh sedang diproses...</p>
                                @elseif($job->soundfresh_verify_status === 'rejected')
                                    <p class="mt-2 text-sm text-emerald-700" role="status">Sudah ditolak di Soundfresh</p>
                                @elseif($job->soundfresh_verify_status === 'failed' && $job->soundfresh_verify_error)
                                    <p class="mt-2 text-sm text-red-700">{{ \App\Services\Automation\ReadableReleaseError::displayText($job->soundfresh_verify_error) }}</p>
                                @endif
                                @if(\App\Services\Automation\FailedUploadRejection::eligible($job))
                                    @can('automation.retry')
                                        <button type="button" wire:click="openRejectFailedUpload('{{ $job->id }}')" wire:loading.attr="disabled" class="secondary-button failed-upload-reject-button mt-2" aria-label="Reject {{ $displayTitle }} di Soundfresh">Reject di Soundfresh</button>
                                    @endcan
                                @endif
                                @if(in_array($jobStatus, ['failed','needs_attention','manual_auth_required'], true) && !in_array($job->soundfresh_verify_status, ['rejecting', 'rejected'], true) && !str_starts_with((string) $job->error_code, 'DUPLICATE_'))
                                    @can('automation.retry')
                                        <button type="button" wire:click="retryRelease('{{ $job->id }}')" wire:loading.attr="disabled" wire:target="retryRelease('{{ $job->id }}')" class="secondary-button mt-2" aria-label="Retry proses {{ $displayTitle }}">
                                            <span wire:loading.remove wire:target="retryRelease('{{ $job->id }}')">Retry</span>
                                            <span wire:loading wire:target="retryRelease('{{ $job->id }}')">Mengantrekan...</span>
                                        </button>
                                    @endcan
                                @endif
                                @if($job->canChangeSoundfreshToUnderReview())
                                    @can('automation.retry')
                                        <button type="button" wire:click="reviewVerifiedDraft('{{ $job->id }}')" wire:loading.attr="disabled" wire:target="reviewVerifiedDraft('{{ $job->id }}')" class="secondary-button mt-2 min-h-11" aria-label="Ubah status Soundfresh {{ $displayTitle }} ke Under Review">
                                            <span wire:loading.remove wire:target="reviewVerifiedDraft('{{ $job->id }}')">Ubah Status ke Under Review</span>
                                            <span wire:loading wire:target="reviewVerifiedDraft('{{ $job->id }}')">Mengantrekan...</span>
                                        </button>
                                        <p class="mt-1 text-xs text-slate-500">Judul dan artis akan diperiksa ulang di Aggregator sebelum status Soundfresh diubah.</p>
                                    @endcan
                                @elseif($job->error_code === 'DRAFT_MANUAL_REVIEW_PENDING')
                                    <span class="badge badge--warning" role="status">Mengubah status ke Under Review...</span>
                                @endif
                                @if($job->error_code === 'DUPLICATE_RELEASE_FOUND' && blank($job->soundon_draft_id))
                                    @can('automation.retry')
                                        <button type="button" wire:click="forceDuplicateUpload('{{ $job->id }}')" wire:loading.attr="disabled" wire:target="forceDuplicateUpload('{{ $job->id }}')" class="secondary-button mt-2 border-amber-300 text-amber-800 hover:bg-amber-50" aria-label="Tetap upload {{ $displayTitle }} meskipun duplikat">
                                            <span wire:loading.remove wire:target="forceDuplicateUpload('{{ $job->id }}')">Tetap Upload</span>
                                            <span wire:loading wire:target="forceDuplicateUpload('{{ $job->id }}')">Mengantrekan...</span>
                                        </button>
                                        <p class="mt-1 text-[10px] text-amber-700">Melewati pemeriksaan judul dan artis yang sama.</p>
                                    @endcan
                                @endif
                                <a class="release-run-link" href="{{ route('automation.runs.show', $job->automation_run_id) }}">Detail run</a>

                                    </div>
                                </details>
                                @if(in_array($jobStatus, ['failed','needs_attention','manual_auth_required'], true) && !in_array($job->soundfresh_verify_status, ['rejecting', 'rejected'], true) && !str_starts_with((string) $job->error_code, 'DUPLICATE_'))
                                    @can('automation.retry')<button type="button" wire:click="retryRelease('{{ $job->id }}')" wire:loading.attr="disabled" class="secondary-button row-retry">Retry</button>@endcan
                                @endif
                            </td>

                        </tr>
                                @php
                            $rowMessages = [];
                            if (in_array($jobStatus, ['failed','needs_attention','manual_auth_required'], true) && filled($job->error_message)) {
                                $rowError = \App\Services\Automation\ReadableReleaseError::explain($job->error_code, $job->error_message);
                                $rowMessages['Alasan error'] = $rowError['reason'];
                                if (filled($rowError['action'])) {
                                    $rowMessages['Yang perlu dilakukan'] = $rowError['action'];
                                }
                            }
                            if (filled($job->soundon_rejection_reason)) {
                                $rowMessages['Alasan ditolak'] = $job->soundon_rejection_reason;
                            }
                            if (filled($job->soundfresh_verify_error)) {
                                $rowMessages['Error Soundfresh'] = $job->soundfresh_verify_error;
                            }
                        @endphp
                        @if(count($rowMessages))
                            <tr class="monitor-error-row" wire:key="release-short-error-{{ $job->id }}"><td colspan="5"><div class="release-message-box"><section>
                                @foreach($rowMessages as $messageLabel => $message)
                                    <p><strong>{{ $messageLabel }}:</strong> {{ \App\Services\Automation\ReadableReleaseError::displayText($message) }}</p>
                                @endforeach
                            </section><button type="button" x-on:click="$dispatch('open-release-detail', {id: '{{ $job->id }}'})">Lihat detail</button></div></td></tr>
                        @endif
                    @empty
                        <tr wire:key="release-monitor-empty"><td colspan="5" class="empty-state">
                            @if($monitorRun && $monitorRun->status->value === 'failed' && !empty($monitorRun->summary_json['error']))
                                <strong class="text-red-700">Pengambilan rilisan gagal:</strong> {{ $monitorRun->summary_json['error'] }}
                                <p class="mt-2 text-xs">Periksa credential Soundfresh di Pengaturan Platform, atau cek storage/logs/laravel.log untuk detail.</p>
                            @elseif($monitorRun && (in_array($monitorRun->status->value, ['queued', 'running'], true) || ($monitorRun->summary_json['collection_pending'] ?? false)))
                                Sedang mengambil rilisan Soundfresh. Daftar akan tampil otomatis tanpa refresh.
                            @elseif($monitorRun && $monitorRun->status->value === 'completed_no_pending')
                                Tidak ada rilisan pending di Soundfresh saat ini. Upload rilisan baru ke Soundfresh lalu jalankan automation lagi.
                            @else
                                Tidak ada rilisan pada pengambilan terbaru. Jalankan automation untuk mengambil pending release.
                            @endif
                        </td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
            <footer wire:loading.remove wire:target="start" class="flex flex-wrap items-center justify-between gap-3 border-t border-slate-100 px-5 py-4 text-xs text-slate-500"><span>Monitor hanya menampilkan {{ $releaseCount }} rilisan dari pengambilan terbaru.</span><span><strong>Catatan:</strong> sistem tidak melakukan publish otomatis; hasil akhir selalu berupa draft Aggregator.</span></footer>
        </section>

        <details wire:ignore.self class="panel worker-disclosure"><summary>Kontrol worker <span>Browser · Pipeline · Pemeriksaan</span><b>Buka pengaturan</b></summary>
            <header class="panel-header flex flex-wrap items-center justify-between gap-4">
                <div>
                    <div class="flex items-center gap-2">
                        <h2 class="panel-title">Worker & Process Aktif Saat Ini</h2>
                        @if($totalActiveWorkers > 0 || $workerOnline)
                            <span class="badge badge--success">
                                <span class="status-dot status-dot--active"></span>
                                Worker Aktif
                            </span>
                        @else
                            <span class="badge badge--warning">
                                <span class="status-dot status-dot--warning"></span>
                                Worker Standby
                            </span>
                        @endif
                    </div>
                    <p class="panel-copy">Status worker browser, proses antrean pipeline, dan kontrol penghentian worker untuk menghindari bentrok.</p>
                </div>
                @can('automation.run')
                    <div>
                        <button type="button"
                            wire:click="stopAllWorkers"
                            wire:loading.attr="disabled"
                            wire:confirm="Yakin ingin menghentikan SEMUA worker, antrean, dan proses automation yang sedang berjalan?"
                            class="secondary-button"
                            style="border-color:#f4b8bf;background:#fff1f3;color:#a72f3d;font-weight:700;">
                            <span wire:loading.remove wire:target="stopAllWorkers">Hentikan Semua Worker</span>
                            <span wire:loading wire:target="stopAllWorkers">Menghentikan...</span>
                        </button>
                    </div>
                @endcan
            </header>

            <div class="worker-grid" style="display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 16px; padding: 20px;">
                <div class="flex flex-col justify-between rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
                    <div>
                        <div class="flex items-center justify-between mb-2">
                            <span class="text-xs font-semibold text-slate-500 uppercase tracking-wider">Browser Worker</span>
                            @if($workerOnline)
                                <span class="badge badge--success text-xs"><span class="status-dot status-dot--active"></span>Online</span>
                            @else
                                <span class="badge badge--danger text-xs"><span class="status-dot status-dot--warning"></span>Offline</span>
                            @endif
                        </div>
                        <p class="text-sm font-bold text-slate-800">Node.js Service (Port 3100)</p>
                        <p class="text-xs text-slate-500 mt-1">Mengendalikan browser headless Playwright.</p>
                    </div>
                    @can('automation.run')
                        <div class="mt-4 flex items-center gap-2 pt-3 border-t border-slate-100">
                            <button type="button" wire:click="startWorkerProcess" wire:loading.attr="disabled" class="secondary-button text-xs py-1.5 px-3 flex-1 flex items-center justify-center gap-1" style="background:#f0fdf4;border-color:#bbf7d0;color:#15803d;font-weight:600;">
                                <span>Jalankan Worker</span>
                            </button>
                            <button type="button" wire:click="stopWorkerProcess" wire:loading.attr="disabled" class="secondary-button text-xs py-1.5 px-3 flex-1 flex items-center justify-center gap-1" style="background:#fef2f2;border-color:#fecaca;color:#b91c1c;font-weight:600;">
                                <span>Hentikan Worker</span>
                            </button>
                        </div>
                    @endcan
                </div>

                <div class="flex flex-col justify-between rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
                    <div>
                        <div class="flex items-center justify-between mb-2">
                            <span class="text-xs font-semibold text-slate-500 uppercase tracking-wider">Pipeline Queue Worker</span>
                            @if($activeRunsCount > 0 || $activeJobsCount > 0)
                                <span class="badge badge--brand text-xs">{{ $activeRunsCount }} Run Aktif</span>
                            @else
                                <span class="badge text-xs text-slate-500 bg-slate-100">Idle</span>
                            @endif
                        </div>
                        <p class="text-sm font-bold text-slate-800">{{ $activeJobsCount }} Diproses · {{ $queuedCount }} Menunggu</p>
                        <p class="text-xs text-slate-500 mt-1">Queue: `release-automation`</p>
                    </div>
                    @can('automation.run')
                        <div class="mt-4 flex items-center gap-2 pt-3 border-t border-slate-100">
                            <button type="button" wire:click="startQueueWorker" wire:loading.attr="disabled" class="secondary-button text-xs py-1.5 px-3 flex-1 flex items-center justify-center gap-1" style="background:#f0fdf4;border-color:#bbf7d0;color:#15803d;font-weight:600;">
                                <span>Jalankan Antrean</span>
                            </button>
                            <button type="button" wire:click="stopQueueWorker" wire:loading.attr="disabled" class="secondary-button text-xs py-1.5 px-3 flex-1 flex items-center justify-center gap-1" style="background:#fef2f2;border-color:#fecaca;color:#b91c1c;font-weight:600;">
                                <span>Hentikan Antrean</span>
                            </button>
                        </div>
                    @endcan
                </div>

                <div class="flex flex-col justify-between rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
                    <div>
                        <div class="flex items-center justify-between mb-2">
                            <span class="text-xs font-semibold text-slate-500 uppercase tracking-wider">Verification & Check Worker</span>
                            @if($verificationActiveCount > 0 || $statusCheckActiveCount > 0)
                                <span class="badge badge--warning text-xs">Memeriksa</span>
                            @else
                                <span class="badge text-xs text-slate-500 bg-slate-100">Idle</span>
                            @endif
                        </div>
                        <p class="text-sm font-bold text-slate-800">{{ $verificationActiveCount }} Verify · {{ $statusCheckActiveCount }} Status Check</p>
                        <p class="text-xs text-slate-500 mt-1">Queue: `status-checks`</p>
                    </div>
                    @can('automation.run')
                        <div class="mt-4 flex items-center gap-2 pt-3 border-t border-slate-100">
                            <button type="button" wire:click="startCheckWorker" wire:loading.attr="disabled" class="secondary-button text-xs py-1.5 px-3 flex-1 flex items-center justify-center gap-1" style="background:#f0fdf4;border-color:#bbf7d0;color:#15803d;font-weight:600;">
                                <span>Jalankan Pemeriksa</span>
                            </button>
                            <button type="button" wire:click="stopCheckWorker" wire:loading.attr="disabled" class="secondary-button text-xs py-1.5 px-3 flex-1 flex items-center justify-center gap-1" style="background:#fef2f2;border-color:#fecaca;color:#b91c1c;font-weight:600;">
                                <span>Hentikan Pemeriksa</span>
                            </button>
                        </div>
                    @endcan
                </div>
            </div>
            @if($activeJobs->isNotEmpty())
                @php
                    $activePercent = max(5, (int) round($activeJobs->avg(fn ($job) => (int) ($job->progress_percent ?? 5))));
                @endphp
                <div class="active-progress-banner border-t border-indigo-100 bg-indigo-50 px-5 py-4" role="status" aria-live="polite">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div class="min-w-0">
                            <div class="flex items-center gap-2 text-sm font-bold text-indigo-900">
                                <span class="inline-block h-3 w-3 animate-pulse rounded-full bg-indigo-600"></span>
                                {{ $activeJobs->count() }} dari {{ $systemStatus['browser_slots'] }} worker sedang memproses
                            </div>
                            @foreach($activeJobs as $activeJob)
                                @php
                                    $activeTitle = filled($activeJob->release_title) && strtolower($activeJob->release_title) !== 'view' ? $activeJob->release_title : 'Rilisan #'.$activeJob->soundfresh_release_id;
                                    $activeSince = $activeJob->progress_updated_at ?? $activeJob->updated_at;
                                @endphp
                                <p class="mt-1 text-xs font-semibold text-indigo-700">Worker {{ $loop->iteration }}: {{ $activeTitle }} — {{ $activeJob->progress_label ?: 'Menyiapkan proses rilisan' }}</p>
                                <p class="mt-1 text-[11px] text-indigo-500">ID {{ $activeJob->soundfresh_release_id }}@if($activeJob->artist_name) · {{ $activeJob->artist_name }}@endif · tahap diperbarui {{ $activeSince?->diffForHumans() ?? 'baru saja' }}</p>
                            @endforeach
                        </div>
                        <div class="text-right text-xs text-indigo-700">
                            <strong class="block text-lg">{{ $activePercent }}%</strong>
                            <span>{{ $queuedCount }} rilisan menunggu giliran</span>
                        </div>
                    </div>
                    <div class="mt-3 h-3 overflow-hidden rounded-full bg-indigo-100" role="progressbar" aria-label="Progress rilisan aktif {{ $activeTitle }}" aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ $activePercent }}">
                        <div class="h-full animate-pulse rounded-full bg-indigo-600 transition-all duration-500" style="width: {{ $activePercent }}%"></div>
                    </div>
                    <p class="mt-2 text-[11px] text-indigo-500">Progres mengikuti laporan terbaru worker. Tahap yang sedang berjalan tetap ditampilkan sampai ada pembaruan.</p>
                </div>
            @elseif($duplicateCheckingCount > 0)
                <div class="border-t border-indigo-100 bg-indigo-50 px-5 py-3 text-xs font-semibold text-indigo-800" role="status" aria-live="polite">
                    <div class="flex items-center justify-between gap-3">
                        <span>Memeriksa duplikat {{ $duplicateCheckingCount }} rilisan di Soundfresh, All releases Aggregator, dan Drafts Aggregator</span>
                        <strong>{{ $duplicateProgressPercent }}%</strong>
                    </div>
                    <div class="mt-2 h-2 w-full overflow-hidden rounded-full bg-indigo-100" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ $duplicateProgressPercent }}">
                        <div class="h-full rounded-full bg-indigo-600 transition-all duration-500" style="width: {{ $duplicateProgressPercent }}%"></div>
                    </div>
                    <div class="mt-2 font-normal text-indigo-700">{{ $duplicateCheckingJob?->progress_label ?? 'Menyiapkan pemeriksaan duplikat' }}</div>
                </div>
            @elseif($queuedCount > 0)
                <div class="border-t border-amber-100 bg-amber-50 px-5 py-3 text-xs font-semibold text-amber-800" role="status">
                    Tidak ada worker yang sedang memproses; {{ $queuedCount }} rilisan masih menunggu. Sistem pemulihan akan menjalankan kembali antrean saat aplikasi aktif.
                </div>
            @endif

        </details>

        <details wire:ignore.self class="panel worker-disclosure activity-disclosure"><summary>Aktivitas automation terbaru <b>Lihat riwayat proses</b></summary>
            <header class="panel-header">
                <div><h2 class="panel-title">Aktivitas automation terbaru</h2><p class="panel-copy">Riwayat eksekusi dan progres pipeline rilisan.</p></div>
                <span class="badge badge--brand">Live · 3 detik</span>
            </header>
            <div class="table-scroll">
                <table class="data-table">
                    <thead><tr><th>Run ID</th><th>Status</th><th>Pending ditemukan</th><th>Total job</th><th>Waktu mulai</th><th>Aksi</th></tr></thead>
                    <tbody>
                    @forelse ($runs as $run)
                        @php
                            $status = $run->status->value;
                            $runStatusLabel = match ($status) {
                                'queued' => 'Menunggu giliran',
                                'running' => 'Sedang berjalan',
                                'paused' => 'Dijeda',
                                'stopped' => 'Dihentikan',
                                'completed' => 'Selesai',
                                'completed_no_pending' => 'Selesai — tidak ada pending',
                                'failed' => 'Gagal',
                                'manual_auth_required' => 'Perlu login ulang',
                                default => str_replace('_', ' ', $status),
                            };
                        @endphp
                        <tr wire:key="automation-run-{{ $run->id }}">
                            <td><a class="font-mono" href="{{ route('automation.runs.show', $run) }}">{{ Str::limit($run->id, 18) }}</a></td>
                            <td><span class="badge {{ in_array($status, ['completed','running']) ? 'badge--success' : (in_array($status, ['failed','stopped']) ? 'badge--danger' : 'badge--warning') }}">{{ $runStatusLabel }}</span></td>
                            <td>{{ $run->pending_found }}</td>
                            <td>{{ $run->release_jobs_count }}</td>
                            <td>{{ $run->started_at?->diffForHumans() ?? '—' }}</td>
                            <td>
                                @can('automation.run')
                                    <div class="run-actions">
                                        @if ($status === 'running')
                                        <button type="button" wire:click="pause('{{ $run->id }}')" wire:loading.attr="disabled" wire:target="pause('{{ $run->id }}')" class="run-action-button run-action-button--pause">
                                            <span wire:loading.remove wire:target="pause('{{ $run->id }}')">Pause</span><span wire:loading wire:target="pause('{{ $run->id }}')">Memproses...</span>
                                        </button>
                                        @endif
                                        @if ($status === 'paused')
                                        <button type="button" wire:click="resume('{{ $run->id }}')" wire:loading.attr="disabled" wire:target="resume('{{ $run->id }}')" class="run-action-button run-action-button--resume">
                                            <span wire:loading.remove wire:target="resume('{{ $run->id }}')">Continue</span><span wire:loading wire:target="resume('{{ $run->id }}')">Melanjutkan...</span>
                                        </button>
                                        @endif
                                        @if (in_array($status, ['queued', 'running', 'paused'], true))
                                        <button type="button" wire:click="stop('{{ $run->id }}')" wire:loading.attr="disabled" wire:target="stop('{{ $run->id }}')" class="run-action-button run-action-button--stop">
                                            <span wire:loading.remove wire:target="stop('{{ $run->id }}')">Stop</span><span wire:loading wire:target="stop('{{ $run->id }}')">Menghentikan...</span>
                                        </button>
                                        @else
                                            <span class="text-muted">Tidak ada proses aktif</span>
                                        @endif
                                    </div>
                                @endcan
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="empty-state">Belum ada automation run. Gunakan tombol “Ambil semua rilisan pending” untuk memulai.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </details>

        <div class="info-banner mt-5">
            <strong>Draft-only protection</strong>
            <span>Semua proses berhenti pada tahap draft. Review Soundfresh hanya berjalan setelah referensi draft Aggregator tersedia.</span>
        </div>

        @if($rejectingUploadJobId)
            <dialog wire:ignore.self x-data x-init="$el.showModal()" x-on:cancel.prevent="$el.close(); $wire.cancelRejectFailedUpload()" wire:key="reject-failed-{{ $rejectingUploadJobId }}" aria-labelledby="reject-upload-title" aria-describedby="reject-upload-description" class="failed-upload-reject-dialog m-auto max-h-[90vh] w-[calc(100%-2rem)] max-w-lg overflow-y-auto rounded-2xl bg-white p-6 text-slate-900 shadow-xl backdrop:bg-slate-950/50">
                <form wire:submit="rejectFailedUpload">
                    <h2 id="reject-upload-title" class="text-lg font-bold">Reject rilisan di Soundfresh?</h2>
                    <p class="mt-2 text-sm font-semibold">{{ \App\Models\ReleaseJob::find($rejectingUploadJobId)?->release_title }}</p>
                    <p id="reject-upload-description" class="mt-2 text-sm leading-6 text-slate-600">Alasan diambil dari kegagalan upload. Periksa atau ubah sebelum mengirim. Aksi ini mengubah status di Soundfresh, tidak menghapus draft Aggregator.</p>
                    <label for="upload-reject-reason" class="mt-4 block text-sm font-semibold">Alasan penolakan</label>
                    <textarea id="upload-reject-reason" wire:model="uploadRejectReason" rows="5" maxlength="3000" required autofocus class="mt-2 w-full rounded-lg border border-slate-300 p-3 text-base focus:outline-2 focus:outline-red-700" aria-describedby="upload-reject-error"></textarea>
                    <div id="upload-reject-error" class="mt-1 text-sm text-red-700" role="alert">@error('uploadRejectReason') {{ \App\Services\Automation\ReadableReleaseError::displayText($message) }} @enderror</div>
                    <div class="mt-5 flex flex-wrap justify-end gap-3">
                        <button type="button" x-on:click="$el.closest('dialog').close()" wire:click="cancelRejectFailedUpload" wire:loading.attr="disabled" class="secondary-button min-h-11">Batal</button>
                        <button type="submit" wire:loading.attr="disabled" wire:target="rejectFailedUpload" class="secondary-button failed-upload-reject-confirm">
                            <span wire:loading.remove wire:target="rejectFailedUpload">Konfirmasi Reject di Soundfresh</span>
                            <span wire:loading wire:target="rejectFailedUpload">Mengantrekan Reject...</span>
                        </button>
                    </div>
                </form>
            </dialog>
        @endif
        @if($showWorkerOperationModal)
            <div class="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/50 p-4" role="dialog" aria-modal="true" aria-labelledby="worker-operation-title" wire:click.self="closeWorkerOperationModal">
                <div class="w-full max-w-md rounded-2xl bg-white p-6 shadow-2xl">
                    <div class="flex items-start gap-3">
                        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-emerald-100 text-lg text-emerald-700">✓</span>
                        <div class="min-w-0 flex-1">
                            <h2 id="worker-operation-title" class="text-lg font-bold text-slate-900">{{ $workerOperationTitle }}</h2>
                            <p class="mt-2 text-sm leading-6 text-slate-600">{{ $workerOperationMessage }}</p>
                        </div>
                        <button type="button" wire:click="closeWorkerOperationModal" class="rounded-lg p-1 text-slate-400 hover:bg-slate-100 hover:text-slate-700" aria-label="Tutup informasi">×</button>
                    </div>
                    <dl class="mt-5 grid grid-cols-3 gap-2 rounded-xl bg-slate-50 p-3 text-center text-[11px] text-slate-500">
                        <div><dt>Browser</dt><dd class="mt-1 font-semibold {{ $systemStatus['worker'] ? 'text-emerald-700' : 'text-red-600' }}">{{ $systemStatus['worker'] ? 'Online' : 'Offline' }}</dd></div>
                        <div><dt>Pipeline</dt><dd class="mt-1 font-semibold {{ $systemStatus['release_workers'] > 0 ? 'text-emerald-700' : 'text-slate-500' }}">{{ $systemStatus['release_workers'] > 0 ? 'Aktif' : 'Berhenti' }}</dd></div>
                        <div><dt>Checker</dt><dd class="mt-1 font-semibold {{ $systemStatus['status_workers'] > 0 ? 'text-emerald-700' : 'text-slate-500' }}">{{ $systemStatus['status_workers'] > 0 ? 'Aktif' : 'Berhenti' }}</dd></div>
                    </dl>
                    <div class="mt-5 flex justify-end">
                        <button type="button" wire:click="closeWorkerOperationModal" class="primary-button">Mengerti</button>
                    </div>
                </div>
            </div>
        @endif
    </main>
</div>
