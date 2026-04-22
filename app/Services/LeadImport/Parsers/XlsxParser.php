<?php

namespace App\Services\LeadImport\Parsers;

use App\Services\LeadImport\Parser;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\Exception as ReaderException;
use RuntimeException;

class XlsxParser implements Parser
{
    public function parse(string $raw, array $expectedHeaders): array
    {
        $tmp = tempnam(sys_get_temp_dir(), 'xlsx_');
        file_put_contents($tmp, $raw);

        try {
            $reader = IOFactory::createReader('Xlsx');
            $reader->setReadDataOnly(true);
            $spreadsheet = $reader->load($tmp);
        } catch (ReaderException $e) {
            unlink($tmp);
            throw new RuntimeException('Could not read XLSX: ' . $e->getMessage(), 0, $e);
        } finally {
            if (file_exists($tmp)) {
                unlink($tmp);
            }
        }

        $sheet = $spreadsheet->getActiveSheet();
        $data = $sheet->toArray(null, true, false, false);

        if (empty($data)) {
            return [];
        }

        $headers = array_map(fn ($v) => trim((string) $v), array_shift($data));
        $missing = array_values(array_diff($expectedHeaders, $headers));
        if (!empty($missing)) {
            throw new RuntimeException('Missing required column(s): ' . implode(', ', $missing));
        }

        $rows = [];
        foreach ($data as $line) {
            if ($this->rowIsEmpty($line)) continue;
            $row = [];
            foreach ($headers as $i => $h) {
                $row[$h] = trim((string) ($line[$i] ?? ''));
            }
            $rows[] = $row;
        }
        return $rows;
    }

    private function rowIsEmpty(array $cells): bool
    {
        foreach ($cells as $c) {
            if ($c !== null && trim((string) $c) !== '') return false;
        }
        return true;
    }
}
