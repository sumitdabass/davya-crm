<?php

namespace App\Http\Controllers;

use App\Models\LeadImportBatch;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class LeadImportRejectionsController extends Controller
{
    public function show(Request $request, LeadImportBatch $batch): StreamedResponse
    {
        abort_unless($request->user()?->hasRole('admin'), 403);

        $path = $batch->rejections_csv_path;
        if ($path === null || !Storage::disk('local')->exists($path)) {
            abort(410, 'Rejections CSV no longer available.');
        }

        $filename = 'rejections-'.$batch->id.'-'.$batch->created_at->format('Y-m-d-His').'.csv';
        $contents = Storage::disk('local')->get($path);

        Storage::disk('local')->delete($path);
        $batch->rejections_csv_path = null;
        $batch->save();

        return response()->streamDownload(
            fn () => print($contents),
            $filename,
            ['Content-Type' => 'text/csv; charset=UTF-8'],
        );
    }
}
