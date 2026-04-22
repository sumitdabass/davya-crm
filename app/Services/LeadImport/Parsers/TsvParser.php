<?php

namespace App\Services\LeadImport\Parsers;

use App\Services\LeadImport\Parser;
use RuntimeException;

class TsvParser implements Parser
{
    public function parse(string $raw, array $expectedHeaders): array
    {
        $raw = str_replace("\r\n", "\n", $raw);
        $lines = array_values(array_filter(explode("\n", $raw), fn ($l) => trim($l) !== ''));
        if (empty($lines)) {
            return [];
        }

        $headers = explode("\t", array_shift($lines));
        $headers = array_map('trim', $headers);

        $missing = array_values(array_diff($expectedHeaders, $headers));
        if (!empty($missing)) {
            throw new RuntimeException('Missing required column(s): ' . implode(', ', $missing));
        }

        $rows = [];
        foreach ($lines as $line) {
            $cells = explode("\t", $line);
            $row = [];
            foreach ($headers as $i => $h) {
                $row[$h] = $cells[$i] ?? '';
            }
            $rows[] = $row;
        }
        return $rows;
    }
}
