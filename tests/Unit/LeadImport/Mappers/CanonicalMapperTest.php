<?php

namespace Tests\Unit\LeadImport\Mappers;

use App\Services\LeadImport\Mappers\CanonicalMapper;
use Tests\TestCase;

class CanonicalMapperTest extends TestCase
{
    public function test_expected_headers_are_canonical_crm_fields(): void
    {
        $headers = (new CanonicalMapper())->expectedHeaders();
        $this->assertSame(
            ['phone', 'name', 'course', 'rank', 'state', 'referrer_name', 'remarks', 'source'],
            $headers,
        );
    }

    public function test_maps_row_one_to_one_with_trim(): void
    {
        $mapper = new CanonicalMapper();
        $row = [
            'phone' => ' 9000000001 ',
            'name' => 'Asha',
            'course' => 'BCA',
            'rank' => '1234',
            'state' => 'Delhi',
            'referrer_name' => '',
            'remarks' => 'Walk-in',
            'source' => 'Website',
        ];
        $this->assertSame([
            'phone' => '9000000001',
            'name' => 'Asha',
            'course' => 'BCA',
            'rank' => '1234',
            'state' => 'Delhi',
            'referrer_name' => null,
            'remarks' => 'Walk-in',
            'source' => 'Website',
            'owner_name' => null,
        ], $mapper->map($row));
    }

    public function test_owner_hint_is_null_for_canonical(): void
    {
        $this->assertNull((new CanonicalMapper())->ownerHint());
    }
}
