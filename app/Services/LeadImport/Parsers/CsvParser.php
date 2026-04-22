<?php

namespace App\Services\LeadImport\Parsers;

use App\Services\LeadImport\Parser;
use RuntimeException;

class CsvParser implements Parser
{
    public function parse(string $raw, array $expectedHeaders): array
    {
        // Strip UTF-8 BOM
        if (str_starts_with($raw, "\xEF\xBB\xBF")) {
            $raw = substr($raw, 3);
        }
        $raw = str_replace("\r\n", "\n", $raw);

        $handle = fopen('php://temp', 'r+');
        fwrite($handle, $raw);
        rewind($handle);

        $headers = fgetcsv($handle);
        if ($headers === false) {
            fclose($handle);
            return [];
        }
        $headers = array_map('trim', $headers);

        $missing = array_values(array_diff($expectedHeaders, $headers));
        if (!empty($missing)) {
            fclose($handle);
            throw new RuntimeException('Missing required column(s): ' . implode(', ', $missing));
        }

        $rows = [];
        while (($cells = fgetcsv($handle)) !== false) {
            if (count($cells) === 1 && ($cells[0] === null || trim($cells[0]) === '')) {
                continue;
            }
            $row = [];
            foreach ($headers as $i => $h) {
                $row[$h] = $cells[$i] ?? '';
            }
            $rows[] = $row;
        }
        fclose($handle);
        return $rows;
    }
}
