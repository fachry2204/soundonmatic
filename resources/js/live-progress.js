// Lightweight snapshots update bars directly; Livewire refreshes changed state.
// Requests never overlap, and hidden tabs do not consume the fast interval.
export function mountLiveProgress() {
document.querySelectorAll('[data-live-progress]').forEach((root) => {
    if (root.dataset.progressMounted) return;
    root.dataset.progressMounted = '1';
    let signature;
    let lastRefresh = 0;
    let refreshing = false;
    let stopped = false;
    const controller = new AbortController();
    const stop = () => { stopped = true; controller.abort(); };
    window.addEventListener('pagehide', stop, { once: true });
    document.addEventListener('livewire:navigating', stop, { once: true });
    async function tick() {
        let delay = 250;
        try {
            if (document.hidden) { delay = 2000; return; }
            const rows = [...root.querySelectorAll('[data-live-job]')]
                .sort((a, b) => Number(b.dataset.liveStatus === 'running') - Number(a.dataset.liveStatus === 'running'))
                .slice(0, 100);
            if (!rows.length) { delay = 1000; return; }
            const params = new URLSearchParams();
            rows.forEach(row => params.append('ids[]', row.dataset.liveJob));
            const response = await fetch(`${root.dataset.liveProgress}?${params}`, {
                headers: { Accept: 'application/json' }, signal: controller.signal,
            });
            if (!response.ok) { delay = 3000; return; }
            const { jobs } = await response.json();
            for (const job of jobs) {
                if (job.status !== 'running' || job.progress_percent == null) continue;
                const row = rows.find(row => row.dataset.liveJob === job.id);
                const progress = row?.querySelector('.release-progress');
                if (!progress) continue;
                const minimum = { discovered: 5, metadata_extracted: 25, assets_downloaded: 45,
                    soundon_draft_created: 60, metadata_filled: 70, assets_uploaded: 85,
                    draft_saved: 95, soundfresh_reviewed: 95, completed: 100 };
                const percent = Math.max(minimum[job.checkpoint] ?? 0, Math.min(100, Number(job.progress_percent)));
                progress.querySelector('.release-progress__bar').style.width = `${percent}%`;
                progress.querySelector('[role="progressbar"]').setAttribute('aria-valuenow', percent);
                progress.querySelector('strong').textContent = `${percent}%`;
                if (job.progress_label) progress.querySelector('.release-progress__copy span').textContent = job.progress_label;
            }
            const active = jobs.filter(job => job.status === 'running' && job.progress_percent != null);
            const banner = root.querySelector('.active-progress-banner');
            if (active.length && banner) {
                const percent = Math.round(active.reduce((sum, job) => sum + Number(job.progress_percent), 0) / active.length);
                const bar = banner.querySelector('[role="progressbar"]');
                bar.setAttribute('aria-valuenow', percent);
                bar.firstElementChild.style.width = `${percent}%`;
                banner.querySelector('strong').textContent = `${percent}%`;
            }
            const next = JSON.stringify(jobs);
            if (signature !== undefined && next !== signature && !refreshing && Date.now() - lastRefresh > 1000) {
                refreshing = true;
                try {
                    await window.Livewire.find(root.getAttribute('wire:id')).$refresh();
                    lastRefresh = Date.now();
                    signature = next;
                } finally { refreshing = false; }
            } else if (signature === undefined) signature = next;
        } catch { delay = 3000; }
        finally { if (!stopped && root.isConnected) setTimeout(tick, delay); }
    }
    tick();
});
}
