<?php

namespace Tests\Unit\LeadImport\Mappers;

use App\Services\LeadImport\Mappers\SumitWebsiteMapper;
use Tests\TestCase;

class SumitWebsiteMapperTest extends TestCase
{
    public function test_expected_headers(): void
    {
        $this->assertSame(
            ['Timestamp', 'Name', 'Email', 'Phone', 'Course', 'Rank', 'State', 'Message'],
            (new SumitWebsiteMapper())->expectedHeaders(),
        );
    }

    public function test_maps_clean_row(): void
    {
        $row = [
            'Timestamp' => '2026-04-22 14:30:00',
            'Name' => 'Ravi',
            'Email' => 'ravi@example.com',
            'Phone' => '9000000020',
            'Course' => 'B.Tech',
            'Rank' => '2345',
            'State' => 'Delhi',
            'Message' => 'Course info please',
        ];
        $this->assertSame([
            'phone' => '9000000020',
            'name' => 'Ravi',
            'email' => 'ravi@example.com',
            'course' => 'B.Tech',
            'rank' => '2345',
            'state' => 'Delhi',
            'remarks' => 'Course info please',
            'owner_name' => 'Sumit',
            'source' => 'Sheet:Sumit-website',
        ], (new SumitWebsiteMapper())->map($row));
    }

    public function test_empty_optionals_become_null(): void
    {
        $row = array_fill_keys(['Timestamp', 'Name', 'Email', 'Phone', 'Course', 'Rank', 'State', 'Message'], '');
        $row['Phone'] = '9000000021';
        $mapped = (new SumitWebsiteMapper())->map($row);
        $this->assertSame('9000000021', $mapped['phone']);
        $this->assertNull($mapped['email']);
        $this->assertNull($mapped['remarks']);
    }

    public function test_owner_hint(): void
    {
        $this->assertSame('Sumit', (new SumitWebsiteMapper())->ownerHint());
    }
}
