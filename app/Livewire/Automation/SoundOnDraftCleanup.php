<?php

declare(strict_types=1);

namespace App\Livewire\Automation;

use App\Enums\Platform;
use App\Services\Automation\OperatorAudit;
use App\Services\Automation\PlaywrightClient;
use App\Services\Automation\SessionManager;
use App\Services\SoundOn\DraftDeletionEligibility;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;
use Throwable;

final class SoundOnDraftCleanup extends Component
{
    public array $items = [];
    public ?string $message = null;

    public function scan(SessionManager $sessions, PlaywrightClient $worker, DraftDeletionEligibility $eligibility): void
    {
        Gate::authorize('automation.retry');
        @set_time_limit(900);
        $this->items = [];
        try {
            [$soundon, $soundfresh] = $this->sessions($sessions);
            $drafts = $worker->soundonDraftInventory($soundon);
            $scans = $worker->protectedSoundfreshScans($soundfresh);
            foreach ($drafts as $draft) {
                $result = $eligibility->evaluate($draft, $scans);
                $this->items[] = [...$draft, ...$result];
            }
            $this->message = 'Pemeriksaan selesai. Periksa hasil sebelum menghapus setiap draft.';
        } catch (Throwable $error) {
            report($error);
            $this->message = 'Pemeriksaan gagal atau belum lengkap. Tidak ada draft yang boleh dihapus. '.$error->getMessage();
            $this->items = [];
        }
    }

    public function deleteDraft(string $draftId, SessionManager $sessions, PlaywrightClient $worker, DraftDeletionEligibility $eligibility): void
    {
        Gate::authorize('automation.retry');
        @set_time_limit(900);
        abort_unless(preg_match('/^\d{10,}$/', $draftId) === 1, 422);
        try {
            [$soundon, $soundfresh] = $this->sessions($sessions);
            // Never trust browser-supplied eligibility. Re-read both platforms
            // immediately before issuing the one-draft delete action.
            $drafts = $worker->soundonDraftInventory($soundon);
            $matching = array_values(array_filter($drafts, fn ($draft) => ($draft['draft_id'] ?? null) === $draftId));
            if (count($matching) !== 1) throw new \RuntimeException('Identitas draft tidak unik atau sudah tidak ada.');
            $draft = $matching[0];
            $result = $eligibility->evaluate($draft, $worker->protectedSoundfreshScans($soundfresh));
            if (! $result['eligible']) throw new \RuntimeException($result['reason']);
            $deleted = $worker->deleteSoundonDraft($draftId, (string) $draft['title'], $soundon);
            if (($deleted['deleted'] ?? false) !== true) throw new \RuntimeException('Penghapusan belum dikonfirmasi Aggregator.');
            $this->items = array_values(array_filter($this->items, fn ($item) => ($item['draft_id'] ?? null) !== $draftId));
            $this->message = 'Draft '.$draft['title'].' dihapus dari Aggregator.';
            try {
                app(OperatorAudit::class)->record('soundon_draft_deleted', 'Operator deleted a draft after a fresh protected-status scan.', null, null, ['draft_id' => $draftId, 'title' => $draft['title'], 'artist' => $draft['artist']]);
            } catch (Throwable $auditError) { report($auditError); }
        } catch (Throwable $error) {
            report($error);
            $this->items = [];
            $this->message = 'Penghapusan tidak terkonfirmasi. Periksa Aggregator sebelum mencoba lagi. '.$error->getMessage();
        }
    }

    private function sessions(SessionManager $sessions): array
    {
        $soundon = $sessions->ensure(Platform::SoundOn);
        $soundfresh = $sessions->ensure(Platform::Soundfresh);
        if ($soundon->status === 'manual_auth_required' || $soundfresh->status === 'manual_auth_required') throw new \RuntimeException('Login ulang kedua platform jika sesi belum aktif.');
        return [$soundon->session_state_encrypted, $soundfresh->session_state_encrypted];
    }

    public function render() { return view('livewire.automation.soundon-draft-cleanup')->layout('components.layouts.app'); }
}
