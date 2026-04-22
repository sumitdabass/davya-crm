<?php

namespace Tests\Unit\LeadImport\Parsers;

use App\Services\LeadImport\Parsers\XlsxParser;
use Tests\TestCase;

class XlsxParserTest extends TestCase
{
    public function test_parses_first_sheet(): void
    {
        $bytes = file_get_contents(base_path('tests/Fixtures/lead-import/sample.xlsx'));
        $rows = (new XlsxParser())->parse($bytes, ['Phone', 'Course', 'Rank']);

        $this->assertCount(2, $rows);
        $this->assertSame('9000000001', $rows[0]['Phone']);
        $this->assertSame('BCA', $rows[0]['Course']);
        $this->assertSame('1234', $rows[0]['Rank']);
    }

    public function test_missing_required_column_throws(): void
    {
        $bytes = file_get_contents(base_path('tests/Fixtures/lead-import/sample.xlsx'));
        $this->expectException(\RuntimeException::class);
        (new XlsxParser())->parse($bytes, ['Phone', 'Course', 'State']);
    }

    public function test_malformed_bytes_throws(): void
    {
        $this->expectException(\RuntimeException::class);
        (new XlsxParser())->parse('not a real xlsx', ['Phone']);
    }
}
