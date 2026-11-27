<?php
namespace Tests\Unit;
use App\Services\Automation\ReadableReleaseError;
use PHPUnit\Framework\TestCase;

class ReadableReleaseErrorTest extends TestCase
{
    public function test_reject_editor_error_is_distinct_from_upload_validation_and_does_not_expose_internal_url(): void
    {
        $message = ReadableReleaseError::soundfreshMessage('SOUNDFRESH_UI_CHANGED:reject_reason_input:url=https://cms.soundfresh.id/v2/admin/releases/4657');
        $this->assertStringContainsString('Reject belum terkirim', $message);
        $this->assertStringContainsString('kolom alasan', $message);
        $this->assertStringContainsString('Retry menjalankan ulang proses upload', $message);
        $this->assertStringNotContainsString('https://', $message);
        $this->assertStringNotContainsString('SOUNDFRESH_UI_CHANGED', $message);
    }
    public function test_blocking_dialog_is_named_when_the_worker_identifies_its_title(): void
    {
        $result = ReadableReleaseError::explain('UI_CHANGED',
            'SOUNDON_UI_CHANGED:entity_control_not_clickable:blocking_dialog_title="Primary artists";Release date: intercepts pointer events');
        $this->assertStringContainsString('Jendela “Primary artists”', $result['reason']);
        $this->assertStringContainsString('tanggal rilis', $result['reason']);
        $this->assertStringNotContainsString('blocking_dialog_title', implode(' ', $result));
    }
    public function test_release_date_blocked_by_popup_is_explained_without_locator_jargon(): void
    {
        $result = ReadableReleaseError::explain('UI_INTERACTION_TIMEOUT',
            "SOUNDON_UI_CHANGED:entity_control_not_clickable:locator.click: Timeout 3000ms exceeded. "
            ."locator('.soundon-formik-field-label').filter({ hasText: 'Release date' }) "
            .'<div role="none" class="semi-modal-wrap semi-modal-wrap-center"> intercepts pointer events');
        $this->assertSame('Isian tertutup jendela lain', $result['title']);
        $this->assertStringContainsString('tanggal rilis', $result['reason']);
        $this->assertStringContainsString('menghalangi klik', $result['reason']);
        $this->assertStringContainsString('Tutup jendela pop-up', $result['action']);
        $this->assertStringContainsString('Retry', $result['action']);
        foreach (['locator', 'semi-modal', '3000ms', 'intercepts pointer events'] as $technicalTerm) {
            $this->assertStringNotContainsString($technicalTerm, implode(' ', $result));
        }
    }

    public function test_timeout_does_not_invent_a_popup_or_network_failure_without_evidence(): void
    {
        $result = ReadableReleaseError::explain('UI_INTERACTION_TIMEOUT', 'locator.waitFor: Timeout 3000ms exceeded');
        $this->assertStringContainsString('belum memastikan penyebabnya', $result['reason']);
        $this->assertStringNotContainsString('koneksi terputus', $result['reason']);
        $this->assertStringNotContainsString('menghalangi klik', $result['reason']);
    }

    public function test_draft_guards_explain_upload_was_blocked_without_deleting_drafts(): void
    {
        foreach (['DRAFT_CREATION_IN_PROGRESS', 'DRAFT_LOOKUP_UNCONFIRMED'] as $code) {
            $result = ReadableReleaseError::explain($code, $code);
            $this->assertSame('Pencegahan draft ganda aktif', $result['title']);
            $this->assertStringContainsString('tidak dihapus', $result['action']);
        }
    }
    public function test_review_failure_preserves_saved_draft_and_identifies_soundfresh(): void
    {
        $result = ReadableReleaseError::explain('SOUNDFRESH_REVIEW_FAILED', 'SOUNDFRESH_REVIEW_FAILED:locator.click: Timeout');
        $this->assertSame('Pembaruan status Soundfresh gagal', $result['title']);
        $this->assertStringContainsString('Draft Aggregator sudah tersimpan', $result['reason']);
        $this->assertStringContainsString('checkpoint', $result['action']);
    }

    public function test_required_field_failure_shows_the_actual_unresolved_credit(): void
    {
        $result = ReadableReleaseError::explain('SOUNDON_REQUIRED_FIELD_UNRESOLVED',
            'Detail teknis: SOUNDON_REQUIRED_FIELD_UNRESOLVED:Isian wajib belum terkonfirmasi tersimpan pada form. Isian: Songwriters: SHIONA');
        $this->assertStringContainsString('Songwriters: SHIONA', $result['reason']);
        $this->assertStringContainsString('tetap Pending', $result['action']);
    }
    public function test_soundon_name_validation_is_not_reported_as_missing_profile(): void
    {
        $result = ReadableReleaseError::explain('SOUNDON_CONTRIBUTOR_NOT_PERSISTED',
            'SOUNDON_CONTRIBUTOR_NOT_PERSISTED:Primary artists:M.Vaganzaee:stage=submit_verification:errors=Artist name cannot contain URLs or website links.');
        $this->assertSame('Nama artis ditolak validasi Aggregator', $result['title']);
        $this->assertStringContainsString('M.Vaganzaee', $result['reason']);
        $this->assertStringContainsString('tanda titik', $result['action']);
        $this->assertStringContainsString('status Soundfresh belum diubah', $result['action']);
    }

    public function test_monetization_failure_has_specific_explanation_even_for_old_ui_changed_code(): void
    {
        $result = ReadableReleaseError::explain('UI_CHANGED', 'Detail teknis: SOUNDON_MONETIZATION_NOT_ENABLED:YouTube Content ID');
        $this->assertSame('Pengaturan monetisasi Aggregator belum tersimpan', $result['title']);
        $this->assertStringContainsString('YouTube Content ID', $result['reason']);
        $this->assertStringContainsString('Retry untuk melanjutkan penyimpanan draft', $result['action']);
        $this->assertStringContainsString('dicatat sebagai peringatan', $result['action']);
    }
    public function test_credit_persistence_error_identifies_the_field_and_name_without_claiming_the_profile_is_missing(): void
    {
        $result = ReadableReleaseError::explain(
            'SOUNDON_CONTRIBUTOR_NOT_PERSISTED',
            'Detail teknis: SOUNDON_CONTRIBUTOR_NOT_PERSISTED:Primary artists:Zenal Farell:stage=verify_after_submit:role=none',
        );
        $this->assertSame('Kredit Aggregator belum tersimpan', $result['title']);
        $this->assertStringContainsString('Aggregator', $result['reason']);
        $this->assertStringContainsString('Zenal Farell', $result['reason']);
        $this->assertStringContainsString('Artis utama', $result['reason']);
        $this->assertStringNotContainsString('stage=', $result['reason']);
        $this->assertStringContainsString('Soundfresh', $result['action']);
    }
    public function test_old_saved_dialog_errors_are_explained_without_claiming_upload_failed(): void
    {
        $result = ReadableReleaseError::explain('UI_CHANGED', 'Pesan lama. Detail teknis: SOUNDON_UI_CHANGED:blocking_dialog:Primary artists');
        $this->assertStringContainsString('artis utama', $result['reason']);
        $this->assertStringContainsString('tidak membuktikan', $result['action']);
        $this->assertStringNotContainsString('UI_CHANGED', $result['title']);
    }

    public function test_missing_field_error_names_the_form_stage_and_field(): void
    {
        $result = ReadableReleaseError::explain(
            'UI_CHANGED',
            'Field tidak ditemukan. Detail teknis: SOUNDON_UI_CHANGED:field:Previously released?',
        );

        $this->assertSame('Pengisian metadata Aggregator terhenti', $result['title']);
        $this->assertStringContainsString('Previously released?', $result['reason']);
        $this->assertStringContainsString('belum mulai diunggah', $result['action']);
    }

    public function test_soundon_form_validation_explains_form_filling_failure(): void
    {
        $result = ReadableReleaseError::explain(
            'SOUNDON_FORM_VALIDATION',
            'SoundOn menolak metadata pada form. Detail teknis: Missing required field: Genre',
        );

        $this->assertSame('Pengisian form Aggregator gagal', $result['title']);
        $this->assertStringContainsString('bukan file rusak', $result['reason']);
        $this->assertStringContainsString('Genre', $result['action']);
        $this->assertStringContainsString('Perbaiki metadata', $result['action']);
    }
}
