<?php

namespace Tests\Unit\LeadImport\Parsers;

use App\Services\LeadImport\Parsers\CsvParser;
use Tests\TestCase;

class CsvParserTest extends TestCase
{
    public function test_parses_basic_csv(): void
    {
        $csv = "Phone,Course,Rank\n9000000001,BCA,1234\n9000000002,BBA,5678\n";
        $rows = (new CsvParser())->parse($csv, ['Phone', 'Course', 'Rank']);

        $this->assertCount(2, $rows);
        $this->assertSame('9000000001', $rows[0]['Phone']);
        $this->assertSame('BBA', $rows[1]['Course']);
    }

    public function test_handles_quoted_fields_with_commas(): void
    {
        $csv = "Phone,Message\n9000000001,\"Hello, world\"\n";
        $rows = (new CsvParser())->parse($csv, ['Phone', 'Message']);
        $this->assertSame('Hello, world', $rows[0]['Message']);
    }

    public function test_missing_required_column_throws(): void
    {
        $this->expectException(\RuntimeException::class);
        (new CsvParser())->parse("Phone\n9000000001\n", ['Phone', 'Course']);
    }

    public function test_bom_is_stripped(): void
    {
        $csv = "\xEF\xBB\xBFPhone,Course\n9000000001,BCA\n";
        $rows = (new CsvParser())->parse($csv, ['Phone', 'Course']);
        $this->assertSame('9000000001', $rows[0]['Phone']);
    }
}
