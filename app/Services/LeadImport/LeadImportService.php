<?php

namespace App\Services\LeadImport;

use App\Services\LeadImport\Mappers\CanonicalMapper;
use App\Services\LeadImport\Mappers\NikhilMapper;
use App\Services\LeadImport\Mappers\SonamMapper;
use App\Services\LeadImport\Mappers\SumitWebsiteMapper;
use App\Services\LeadImport\Parsers\CsvParser;
use App\Services\LeadImport\Parsers\TsvParser;
use App\Services\LeadImport\Parsers\XlsxParser;
use App\Services\LeadIntakeService;
use Illuminate\Http\UploadedFile;
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
