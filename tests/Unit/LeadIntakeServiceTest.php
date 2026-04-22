<?php

namespace Tests\Unit;

use App\Models\Student;
use App\Models\User;
use App\Services\LeadIntakeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LeadIntakeServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    private function service(): LeadIntakeService
    {
        return app(LeadIntakeService::class);
    }

    public function test_ingests_minimal_payload_with_phone_and_course(): void
    {
        $result = $this->service()->ingest([
            'phone' => '9000000001',
            'course' => 'BCA',
        ]);

        $this->assertArrayHasKey('student', $result);
        $student = $result['student'];
        $this->assertSame('9000000001', $student->phone);
        $this->assertSame('BCA', $student->course);
        $this->assertNull($student->name);
        $this->assertSame('Lead Captured', $student->stage);
    }

    public function test_owner_name_overrides_referrer_derived_owner(): void
    {
        $nisha = User::where('email', 'nisha@davya.local')->first();
        $sonam = User::where('email', 'sonam@davya.local')->first();

        $result = $this->service()->ingest([
            'phone' => '9000000002',
            'course' => 'BBA',
            'referrer_name' => 'Nisha',
            'owner_name' => 'Sonam',
        ]);

        $student = $result['student'];
        $this->assertSame($sonam->id, $student->owner_id);
        $this->assertSame($nisha->id, $student->referrer_id);
    }

    public function test_owner_name_lookup_is_case_insensitive(): void
    {
        $sonam = User::where('email', 'sonam@davya.local')->first();

        $result = $this->service()->ingest([
            'phone' => '9000000003',
            'course' => 'BBA',
            'owner_name' => 'sOnAm',
        ]);

        $this->assertSame($sonam->id, $result['student']->owner_id);
    }

    public function test_unknown_owner_name_falls_through_to_referrer_mapping(): void
    {
        $nisha  = User::where('email', 'nisha@davya.local')->first();
        $nikhil = User::where('email', 'nikhil@davya.local')->first();

        $result = $this->service()->ingest([
            'phone' => '9000000004',
            'course' => 'BCA',
            'referrer_name' => 'Nisha',
            'owner_name' => 'NobodyKnown',
        ]);

        $student = $result['student'];
        $this->assertSame($nisha->id, $student->referrer_id);
        $this->assertSame($nikhil->id, $student->owner_id);
    }

    public function test_no_owner_and_no_referrer_defaults_to_admin(): void
    {
        $sumit = User::where('email', 'sumit@davya.local')->first();

        $result = $this->service()->ingest([
            'phone' => '9000000005',
            'course' => 'BCA',
        ]);

        $this->assertSame($sumit->id, $result['student']->owner_id);
    }

    public function test_phone_is_normalized_to_ten_digits(): void
    {
        $result = $this->service()->ingest([
            'phone' => '+91 90000 00006',
            'course' => 'BCA',
        ]);
        $this->assertSame('9000000006', $result['student']->phone);
    }

    public function test_duplicate_phone_returns_duplicate_result_without_inserting(): void
    {
        $this->service()->ingest(['phone' => '9000000007', 'course' => 'BCA']);
        $result = $this->service()->ingest(['phone' => '9000000007', 'course' => 'BBA']);

        $this->assertTrue($result['duplicate']);
        $this->assertIsInt($result['existing_id']);
        $this->assertSame(1, Student::where('phone', '9000000007')->count());
    }

    public function test_remarks_maps_to_extra_notes_and_college_maps_to_preference_r1(): void
    {
        $result = $this->service()->ingest([
            'phone' => '9000000008',
            'course' => 'BCA',
            'remarks' => 'called twice, interested',
            'college' => 'MAIT',
        ]);

        $student = $result['student'];
        $this->assertSame('called twice, interested', $student->extra_notes);
        $this->assertSame('MAIT', $student->preference_r1);
    }

    public function test_source_defaults_to_sheet_owner_when_owner_name_present_and_source_blank(): void
    {
        $result = $this->service()->ingest([
            'phone' => '9000000009',
            'course' => 'BCA',
            'owner_name' => 'Sonam',
        ]);
        $this->assertSame('Sheet:Sonam', $result['student']->lead_source);
    }

    public function test_explicit_source_overrides_default(): void
    {
        $result = $this->service()->ingest([
            'phone' => '9000000010',
            'course' => 'BCA',
            'owner_name' => 'Sonam',
            'source' => 'Instagram DM',
        ]);
        $this->assertSame('Instagram DM', $result['student']->lead_source);
    }

    public function test_stores_new_fields_rank_state_email(): void
    {
        $result = $this->service()->ingest([
            'phone' => '9000000011',
            'course' => 'BCA',
            'rank' => '55000',
            'state' => 'Delhi',
            'email' => 'x@example.com',
        ]);
        $student = $result['student'];
        $this->assertSame('55000', $student->rank);
        $this->assertSame('Delhi', $student->state);
        $this->assertSame('x@example.com', $student->email);
    }
}
