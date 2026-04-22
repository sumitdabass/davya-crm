<?php

namespace Tests\Unit\LeadImport\Mappers;

use App\Services\LeadImport\Mappers\SonamMapper;
use Tests\TestCase;

class SonamMapperTest extends TestCase
{
    public function test_expected_headers_match_sonam_sheet_exactly(): void
    {
        $this->assertSame(
            ['Date', 'Ph no', 'Course', 'Rank', 'D/OD', 'enquiry', 'connected to.'],
            (new SonamMapper())->expectedHeaders(),
        );
    }

    public function test_maps_clean_row(): void
    {
        $row = [
            'Date' => '2026-04-20',
            'Ph no' => '9000000001',
            'Course' => 'BCA',
            'Rank' => '1234',
            'D/OD' => 'D',
            'enquiry' => 'Fees query',
            'connected to.' => 'Nisha',
        ];
        $this->assertSame([
            'phone' => '9000000001',
            'course' => 'BCA',
            'rank' => '1234',
            'category' => 'Delhi',
            'remarks' => 'Fees query',
            'referrer_name' => 'Nisha',
            'owner_name' => 'Sonam',
            'source' => 'Sheet:Sonam',
        ], (new SonamMapper())->map($row));
    }

    public function test_domicile_translation(): void
    {
        $mapper = new SonamMapper();
        $row = fn (string $dod) => [
            'Date' => '', 'Ph no' => '9000000010', 'Course' => 'BCA',
            'Rank' => '', 'D/OD' => $dod, 'enquiry' => '', 'connected to.' => '',
        ];

        $this->assertSame('Delhi',   $mapper->map($row('D'))['category']);
        $this->assertSame('Delhi',   $mapper->map($row('d'))['category']);
        $this->assertSame('Delhi',   $mapper->map($row('Delhi'))['category']);
        $this->assertSame('Outside', $mapper->map($row('OD'))['category']);
        $this->assertSame('Outside', $mapper->map($row('od'))['category']);
        $this->assertSame('Outside', $mapper->map($row('Outside'))['category']);
        $this->assertNull($mapper->map($row(''))['category']);
        $this->assertNull($mapper->map($row('whatever'))['category']);
    }

    public function test_normalizes_whitespace_and_empty_optional_columns(): void
    {
        $row = [
            'Date' => '',
            'Ph no' => '  +91 90000-00002 ',
            'Course' => ' BBA ',
            'Rank' => '',
            'D/OD' => '',
            'enquiry' => '',
            'connected to.' => '',
        ];
        $mapped = (new SonamMapper())->map($row);
        $this->assertSame('919000000002', $mapped['phone']);
        $this->assertSame('BBA', $mapped['course']);
        $this->assertNull($mapped['rank']);
        $this->assertNull($mapped['referrer_name']);
    }

    public function test_owner_hint_is_sonam(): void
    {
        $this->assertSame('Sonam', (new SonamMapper())->ownerHint());
    }
}
