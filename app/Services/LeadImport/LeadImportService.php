<?php

namespace App\Services\LeadImport;

use App\Models\LeadImportBatch;
use App\Models\User;
use App\Services\LeadImport\Mappers\CanonicalMapper;
use App\Services\LeadImport\Mappers\NikhilMapper;
use App\Services\LeadImport\Mappers\SonamMapper;
use App\Services\LeadImport\Mappers\SumitWebsiteMapper;
use App\Services\LeadImport\Parsers\CsvParser;
use App\Services\LeadImport\Parsers\TsvParser;
use App\Services\LeadImport\Parsers\XlsxParser;
use App\Services\LeadIntakeService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;

class LeadImportService
{
    public const SOURCES = ['sonam', 'nikhil', 'sumit-website', 'canonical'];

    public function __construct(private LeadIntakeService $intake) {}

    /**
     * @param string|UploadedFile $input  TSV string (paste) or an uploaded CSV/XLSX file
     */
    public function preview(string $source, string|UploadedFile $input): ImportPreview
    {
        $mapper = $this->mapperFor($source);
        [$raw, $parser] = $this->parserFor($input);

        $rawRows = $parser->parse($raw, $mapper->expectedHeaders());

        $actions = [];
        foreach ($rawRows as $i => $rawRow) {
            $rowNumber = $i + 2;  // header is row 1
            $mapped = $mapper->map($rawRow);
            $action = $this->intake->preview($mapped);
            $actions[] = new ImportAction(
                action: $action->action,
                mappedPayload: $action->mappedPayload,
                existingStudentId: $action->existingStudentId,
                reason: $action->reason,
                rowNumber: $rowNumber,
            );
        }
        return new ImportPreview($source, $actions);
    }

    public function commit(ImportPreview $preview, User $user): LeadImportBatch
    {
        return DB::transaction(function () use ($preview, $user) {
            $counts = ['create' => 0, 'merge' => 0, 'flag' => 0, 'reject' => 0];

            foreach ($preview->actions as $action) {
                if ($action->action === ImportAction::REJECT) {
                    $counts['reject']++;
                    continue;
                }
                $this->intake->ingestDecision($action);
                $counts[$action->action]++;
            }

            $rejections = $preview->byAction(ImportAction::REJECT);
            $rejectionPath = null;
            if (!empty($rejections)) {
                $rejectionPath = 'lead-imports/'.Str::uuid()->toString().'.csv';
                Storage::disk('local')->put($rejectionPath, $this->rejectionsToCsv($rejections));
            }

            return LeadImportBatch::create([
                'user_id'              => $user->id,
                'source'               => $preview->source,
                'row_count'            => $preview->rowCount(),
                'created_count'        => $counts['create'],
                'merged_count'         => $counts['merge'],
                'flagged_count'        => $counts['flag'],
                'rejected_count'       => $counts['reject'],
                'rejections_csv_path'  => $rejectionPath,
            ]);
        });
    }

    /** @param array<int, ImportAction> $rejections */
    private function rejectionsToCsv(array $rejections): string
    {
        $handle = fopen('php://temp', 'r+');
        fputcsv($handle, ['row_number', 'reason', 'phone', 'course', 'raw_payload_json'], escape: '');
        foreach ($rejections as $r) {
            fputcsv($handle, [
                $r->rowNumber,
                $r->reason ?? 'unknown',
                $r->mappedPayload['phone'] ?? '',
                $r->mappedPayload['course'] ?? '',
                json_encode($r->mappedPayload, JSON_UNESCAPED_SLASHES),
            ], escape: '');
        }
        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);
        return $csv;
    }

    private function mapperFor(string $source): SourceMapper
    {
        return match ($source) {
            'sonam'         => new SonamMapper(),
            'nikhil'        => new NikhilMapper(),
            'sumit-website' => new SumitWebsiteMapper(),
            'canonical'     => new CanonicalMapper(),
            default => throw new InvalidArgumentException("Unknown source: {$source}"),
        };
    }

    /** @return array{0: string, 1: Parser} */
    private function parserFor(string|UploadedFile $input): array
    {
        if (is_string($input)) {
            return [$input, new TsvParser()];
        }
        $ext = strtolower($input->getClientOriginalExtension());
        $bytes = file_get_contents($input->getRealPath());
        return match ($ext) {
            'csv', 'tsv' => [$bytes, $ext === 'tsv' ? new TsvParser() : new CsvParser()],
            'xlsx'       => [$bytes, new XlsxParser()],
            default      => throw new InvalidArgumentException("Unsupported file type: {$ext}"),
        };
    }
}
