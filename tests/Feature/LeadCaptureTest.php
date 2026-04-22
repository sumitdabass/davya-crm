<?php

namespace Tests\Feature;

use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LeadCaptureTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'test-lead-token-abcdef0123456789';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        config(['leads.capture_token' => self::TOKEN]);
    }

    private function postLead(array $overrides = [], ?string $token = self::TOKEN): \Illuminate\Testing\TestResponse
    {
        $payload = array_merge([
            'phone'          => '9999911111',
            'name'           => 'Ankit Sharma',
            'father_name'    => 'Mr Sharma',
            'phone_2'        => null,
            'exam_appeared'  => 'IPU CET',
            'twelfth_marks'  => '85%',
            'category'       => 'Delhi',
            'course'         => 'BCA',
            'referrer_name'  => 'Nisha',
            'description'    => 'via form',
        ], $overrides);

        $headers = $token === null ? [] : ['X-Lead-Token' => $token];

        return $this->postJson('/api/leads', $payload, $headers);
    }

    // --- happy path + field storage ---

    public function test_valid_token_and_payload_creates_student_at_lead_captured(): void
    {
        $resp = $this->postLead();

        $resp->assertCreated();
        $resp->assertJsonStructure(['id', 'stage', 'owner', 'referrer']);

        $student = Student::find($resp->json('id'));
        $this->assertNotNull($student);
        $this->assertSame('Lead Captured', $student->stage);
        $this->assertSame('9999911111', $student->phone);
        $this->assertSame('Ankit Sharma', $student->name);
        $this->assertSame('Delhi', $student->category);
        $this->assertSame('BCA', $student->course);
    }

    // --- owner derivation ---

    public function test_member_referrer_assigns_owner_to_their_team_head(): void
    {
        $nisha  = User::where('email', 'nisha@davya.local')->first();
        $nikhil = User::where('email', 'nikhil@davya.local')->first();

        $resp = $this->postLead(['phone' => '9111111111', 'referrer_name' => 'Nisha']);

        $resp->assertCreated();
        $student = Student::find($resp->json('id'));
        $this->assertSame($nisha->id, $student->referrer_id);
        $this->assertSame($nikhil->id, $student->owner_id);
    }

    public function test_head_referrer_assigns_owner_to_self(): void
    {
        $sonam = User::where('email', 'sonam@davya.local')->first();

        $resp = $this->postLead(['phone' => '9222222222', 'referrer_name' => 'Sonam']);

        $resp->assertCreated();
        $student = Student::find($resp->json('id'));
        $this->assertSame($sonam->id, $student->referrer_id);
        $this->assertSame($sonam->id, $student->owner_id);
    }

    public function test_walk_in_option_sets_null_referrer_and_admin_owner(): void
    {
        $sumit = User::where('email', 'sumit@davya.local')->first();

        $resp = $this->postLead(['phone' => '9333333333', 'referrer_name' => 'Walk-in / Self']);

        $resp->assertCreated();
        $student = Student::find($resp->json('id'));
        $this->assertNull($student->referrer_id);
        $this->assertSame($sumit->id, $student->owner_id);
    }

    public function test_unknown_referrer_name_falls_back_to_null_referrer_and_admin_owner(): void
    {
        $sumit = User::where('email', 'sumit@davya.local')->first();

        $resp = $this->postLead(['phone' => '9444444444', 'referrer_name' => 'SomeRandomName']);

        $resp->assertCreated();
        $student = Student::find($resp->json('id'));
        $this->assertNull($student->referrer_id);
        $this->assertSame($sumit->id, $student->owner_id);
    }

    public function test_null_referrer_name_is_treated_as_walk_in(): void
    {
        $sumit = User::where('email', 'sumit@davya.local')->first();

        $resp = $this->postLead(['phone' => '9777777777', 'referrer_name' => null]);

        $resp->assertCreated();
        $student = Student::find($resp->json('id'));
        $this->assertNull($student->referrer_id);
        $this->assertSame($sumit->id, $student->owner_id);
        $this->assertSame('Walk-in / Self', $student->lead_source);
    }

    public function test_referrer_name_match_is_case_insensitive(): void
    {
        $nisha = User::where('email', 'nisha@davya.local')->first();

        $resp = $this->postLead(['phone' => '9555555555', 'referrer_name' => 'nIsHa']);

        $resp->assertCreated();
        $student = Student::find($resp->json('id'));
        $this->assertSame($nisha->id, $student->referrer_id);
    }

    // --- auth ---

    public function test_missing_token_returns_401(): void
    {
        $resp = $this->postLead([], token: null);
        $resp->assertStatus(401);
        $resp->assertJson(['error' => 'unauthorized']);
        $this->assertSame(0, Student::count());
    }

    public function test_wrong_token_returns_401(): void
    {
        $resp = $this->postLead([], token: 'not-the-real-token');
        $resp->assertStatus(401);
        $resp->assertJson(['error' => 'unauthorized']);
        $this->assertSame(0, Student::count());
    }

    // --- validation ---

    public function test_missing_phone_returns_422(): void
    {
        $resp = $this->postLead(['phone' => '']);
        $resp->assertStatus(422);
        $resp->assertJsonValidationErrors('phone');
    }

    public function test_missing_name_is_accepted_now_that_course_is_the_required_human_field(): void
    {
        $resp = $this->postLead(['name' => null]);
        $resp->assertCreated();
        $student = \App\Models\Student::find($resp->json('id'));
        $this->assertNull($student->name);
        $this->assertSame('BCA', $student->course);
    }

    public function test_invalid_category_returns_422(): void
    {
        $resp = $this->postLead(['category' => 'Mars']);
        $resp->assertStatus(422);
        $resp->assertJsonValidationErrors('category');
    }

    public function test_phone_is_normalized_to_digits_only(): void
    {
        $resp = $this->postLead(['phone' => '+91 99999 11111']);
        $resp->assertCreated();
        $student = Student::find($resp->json('id'));
        $this->assertSame('9999911111', $student->phone);
    }

    // --- conflict ---

    public function test_duplicate_phone_returns_409_with_existing_id(): void
    {
        $first = $this->postLead(['phone' => '9666666666']);
        $first->assertCreated();

        $second = $this->postLead(['phone' => '9666666666', 'name' => 'Other Name']);
        $second->assertStatus(409);
        $second->assertJson([
            'error'       => 'duplicate_phone',
            'existing_id' => $first->json('id'),
        ]);

        $this->assertSame(1, Student::where('phone', '9666666666')->count());
    }

    // --- new required/optional rules ---

    public function test_missing_course_returns_422(): void
    {
        $resp = $this->postLead(['course' => null]);
        $resp->assertStatus(422);
        $resp->assertJsonValidationErrors('course');
    }

    public function test_owner_name_overrides_referrer_mapping(): void
    {
        $sonam = \App\Models\User::where('email', 'sonam@davya.local')->first();
        $nisha = \App\Models\User::where('email', 'nisha@davya.local')->first();

        $resp = $this->postLead([
            'phone' => '9888000001',
            'referrer_name' => 'Nisha',
            'owner_name' => 'Sonam',
        ]);

        $resp->assertCreated();
        $student = \App\Models\Student::find($resp->json('id'));
        $this->assertSame($sonam->id, $student->owner_id);
        $this->assertSame($nisha->id, $student->referrer_id);
    }

    public function test_accepts_and_stores_new_optional_fields(): void
    {
        $resp = $this->postLead([
            'phone' => '9888000002',
            'rank' => '55000',
            'state' => 'Uttar Pradesh',
            'email' => 'lead@example.com',
            'college' => 'MAIT',
            'remarks' => 'asked about scholarship',
            'source' => 'Sheet:Sumit',
        ]);

        $resp->assertCreated();
        $student = \App\Models\Student::find($resp->json('id'));
        $this->assertSame('55000', $student->rank);
        $this->assertSame('Uttar Pradesh', $student->state);
        $this->assertSame('lead@example.com', $student->email);
        $this->assertSame('MAIT', $student->preference_r1);
        $this->assertSame('asked about scholarship', $student->extra_notes);
        $this->assertSame('Sheet:Sumit', $student->lead_source);
    }

    public function test_source_defaults_to_sheet_owner_when_blank(): void
    {
        $resp = $this->postLead([
            'phone' => '9888000003',
            'owner_name' => 'Sonam',
            'source' => null,
        ]);
        $resp->assertCreated();
        $student = \App\Models\Student::find($resp->json('id'));
        $this->assertSame('Sheet:Sonam', $student->lead_source);
    }
}
