<?php

namespace Tests\Unit\LeadImport\Parsers;

use App\Services\LeadImport\Parsers\TsvParser;
use Tests\TestCase;

class TsvParserTest extends TestCase
{
    public function test_parses_header_and_rows(): void
    {
        $tsv = "Phone\tCourse\tRank\n9000000001\tBCA\t1234\n9000000002\tBBA\t5678\n";
        $rows = (new TsvParser())->parse($tsv, ['Phone', 'Course', 'Rank']);

        $this->assertCount(2, $rows);
        $this->assertSame(['Phone' => '9000000001', 'Course' => 'BCA', 'Rank' => '1234'], $rows[0]);
        $this->assertSame(['Phone' => '9000000002', 'Course' => 'BBA', 'Rank' => '5678'], $rows[1]);
    }

    public function test_blank_lines_are_skipped(): void
    {
        $tsv = "A\tB\n1\t2\n\n3\t4\n";
        $this->assertCount(2, (new TsvParser())->parse($tsv, ['A', 'B']));
    }

    public function test_short_rows_are_padded_with_empty_strings(): void
    {
        $tsv = "A\tB\tC\n1\t2\n";
        $rows = (new TsvParser())->parse($tsv, ['A', 'B', 'C']);
        $this->assertSame(['A' => '1', 'B' => '2', 'C' => ''], $rows[0]);
    }

    public function test_missing_header_column_throws(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/missing required column.*Rank/i');
        (new TsvParser())->parse("Phone\tCourse\n9000000001\tBCA\n", ['Phone', 'Course', 'Rank']);
    }

    public function test_empty_input_returns_empty_array(): void
    {
        $this->assertSame([], (new TsvParser())->parse('', ['A']));
        $this->assertSame([], (new TsvParser())->parse("A\n", ['A']));  // header only
    }
}
