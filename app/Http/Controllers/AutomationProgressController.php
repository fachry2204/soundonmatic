<?php

namespace App\Http\Controllers;

use App\Models\ReleaseJob;
use Illuminate\Http\Request;

final class AutomationProgressController extends Controller
{
    public function __invoke(Request $request)
    {
        $data = $request->validate(['ids' => ['array', 'max:100'], 'ids.*' => ['uuid']]);
        $jobs = ReleaseJob::query()->whereIn('id', $data['ids'] ?? [])
            ->get(['id', 'status', 'checkpoint', 'progress_percent', 'progress_label', 'updated_at',
                'soundon_check_status', 'soundon_check_progress', 'error_code', 'soundfresh_verify_status']);

        return response()->json(['jobs' => $jobs])->header('Cache-Control', 'no-store');
    }
}
