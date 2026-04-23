<?php

namespace App\Http\Controllers;

use App\Dashboard\CardRegistry;
use App\Dashboard\RowFormatter;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DashboardDrillDownCsvController extends Controller
{
    public function __invoke(Request $request): StreamedResponse
    {
        $cardId = (string) $request->query('cardId');
        $search = trim((string) $request->query('search', ''));

        $card = CardRegistry::find($cardId);
        abort_if($card === null || $card->type() !== 'stat', 404);

        $payload = $card->drillDown($request->user());
        abort_if($payload === null, 404);

        $query = clone $payload->query;
        if ($search !== '') {
            $query->where(function ($q) use ($search): void {
                $q->where('name', 'like', '%'.$search.'%')
                  ->orWhere('phone', 'like', '%'.$search.'%');
            });
        }
        $query->with(['owner']);

        $filename = $payload->csvFilenamePrefix.'-'.now('Asia/Kolkata')->format('Y-m-d-His').'.csv';

        return response()->streamDownload(function () use ($query, $payload): void {
            $out = fopen('php://output', 'w');
            fputcsv($out, array_map(fn ($c) => $c['label'], $payload->columns));
            foreach ($query->cursor() as $row) {
                $line = [];
                foreach ($payload->columns as $col) {
                    $line[] = RowFormatter::format($row, $col['key']);
                }
                fputcsv($out, $line);
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
