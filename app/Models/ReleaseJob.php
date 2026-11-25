<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ReleaseCheckpoint;
use App\Enums\ReleaseJobStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ReleaseJob extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['status' => ReleaseJobStatus::class, 'checkpoint' => ReleaseCheckpoint::class, 'metadata_snapshot_json' => 'array', 'asset_progress_json' => 'array', 'soundon_isrcs_json' => 'array', 'soundon_status_checked_at' => 'datetime', 'soundon_check_started_at' => 'datetime', 'soundon_check_finished_at' => 'datetime', 'soundfresh_verified_at' => 'datetime', 'soundfresh_rejected_at' => 'datetime', 'started_at' => 'datetime', 'finished_at' => 'datetime', 'progress_updated_at' => 'datetime'];
    }

    public function canChangeSoundfreshToUnderReview(): bool
    {
        return ($this->error_code === 'DUPLICATE_RELEASE_FOUND' || filled($this->soundon_draft_id))
            && $this->error_code !== 'DRAFT_MANUAL_REVIEW_PENDING'
            && ($this->soundfresh_workflow_status === 'pending'
                || ($this->soundfresh_workflow_status === null && $this->error_code === 'DUPLICATE_RELEASE_FOUND'))
            && $this->status !== ReleaseJobStatus::Running
            && ! in_array($this->soundfresh_verify_status, ['rejecting', 'rejected'], true)
            && filled($this->release_title) && filled($this->artist_name)
            && filled($this->soundfresh_release_url);
    }

    public function automationRun(): BelongsTo
    {
        return $this->belongsTo(AutomationRun::class);
    }

    public function assets(): HasMany
    {
        return $this->hasMany(ReleaseAsset::class);
    }
}
