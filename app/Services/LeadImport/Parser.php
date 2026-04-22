<?php

namespace App\Services\LeadImport;

interface Parser
{
    /**
     * Parse raw input into an array of header-keyed rows.
     *
     * @param string $raw  Raw text content (TSV/CSV) or bytes (XLSX)
     * @param array<int, string> $expectedHeaders  Required header names; throws if any missing
     * @return array<int, array<string, string>>
     * @throws \RuntimeException on malformed input or missing required headers
     */
    public function parse(string $raw, array $expectedHeaders): array;
}
