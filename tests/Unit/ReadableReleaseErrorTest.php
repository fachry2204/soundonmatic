<?php
namespace Tests\Unit;
use App\Services\Automation\ReadableReleaseError;
use PHPUnit\Framework\TestCase;

class ReadableReleaseErrorTest extends TestCase
{
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

        $this->assertSame('Pengisian metadata SoundOn terhenti', $result['title']);
        $this->assertStringContainsString('Previously released?', $result['reason']);
        $this->assertStringContainsString('belum mulai diunggah', $result['action']);
    }
}
