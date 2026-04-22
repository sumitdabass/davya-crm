<?php

namespace Tests\Unit\LeadImport\Mappers;

use App\Services\LeadImport\Mappers\NikhilMapper;
use Tests\TestCase;

class NikhilMapperTest extends TestCase
{
    public function test_expected_headers(): void
    {
        $this->assertSame(
            ['Name', 'Phone', 'Course', 'Rank', 'State', 'Referrer', 'Remarks'],
            (new NikhilMapper())->expectedHeaders(),
        );
    }

    public function test_maps_clean_row(): void
    {
        $row = [
            'Name' => 'Asha Kumari',
            'Phone' => '9000000010',
            'Course' => 'BBA',
            'Rank' => '5678',
            'State' => 'UP',
            'Referrer' => 'Nisha',
            'Remarks' => 'Called back',
        ];
        $this->assertSame([
            'phone' => '9000000010',
            'name' => 'Asha Kumari',
            'course' => 'BBA',
            'rank' => '5678',
            'state' => 'UP',
            'referrer_name' => 'Nisha',
            'remarks' => 'Called back',
            'owner_name' => 'Nikhil',
            'source' => 'Sheet:Nikhil',
        ], (new NikhilMapper())->map($row));
    }

    public function test_empty_optionals_become_null(): void
    {
        $row = array_fill_keys(['Name', 'Phone', 'Course', 'Rank', 'State', 'Referrer', 'Remarks'], '');
        $row['Phone'] = '9000000011';
        $mapped = (new NikhilMapper())->map($row);
        $this->assertSame('9000000011', $mapped['phone']);
        $this->assertNull($mapped['name']);
        $this->assertNull($mapped['state']);
        $this->assertSame('Nikhil', $mapped['owner_name']);
    }

    public function test_owner_hint(): void
    {
        $this->assertSame('Nikhil', (new NikhilMapper())->ownerHint());
    }
}
