<?php

namespace Tests\Feature;

use App\Models\Student;
use App\Models\User;
use App\Services\PipelineSummary;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LeadsReportServiceTest extends TestCase
{
    use RefreshDatabase;

    private User $sumit;
    private User $sonam;
    private User $nikhil;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->sumit  = User::where('email', 'sumit@davya.local')->firstOrFail();
        $this->sonam  = User::where('email', 'sonam@davya.local')->firstOrFail();
        $this->nikhil = User::where('email', 'nikhil@davya.local')->firstOrFail();
    }

    private function makeStudent(User $owner, ?User $referrer, string $stage, string $phone): Student
    {
        return Student::create([
            'phone'         => $phone,
            'name'          => 'X '.$phone,
            'owner_id'      => $owner->id,
            'referrer_id'   => ($referrer ?? $owner)->id,
            'lead_source'   => $owner->name,
            'stage'         => $stage,
            'preference_r1' => 'Some College',
        ]);
    }

    public function test_by_owner_excludes_lead_captured_stage(): void
    {
        // Sonam owns 1 student still in Lead Captured → excluded
        $this->makeStudent($this->sonam, null, 'Lead Captured', '9100000001');
        // Sonam owns 2 students past Lead Captured → counted
        $this->makeStudent($this->sonam, null, 'Meeting Scheduled', '9100000002');
        $this->makeStudent($this->sonam, null, 'Admission Confirmed', '9100000003');

        $result = PipelineSummary::byOwnerAfterCaptured();

        $this->assertArrayHasKey($this->sonam->id, $result);
        $this->assertSame('Sonam', $result[$this->sonam->id]['name']);
        $this->assertSame(2, $result[$this->sonam->id]['count']);
    }

    public function test_by_owner_breaks_down_active_admitted_closed(): void
    {
        $this->makeStudent($this->sonam, null, 'Meeting Scheduled', '9100001001');   // active
        $this->makeStudent($this->sonam, null, 'Counselling In Progress', '9100001002'); // active
        $this->makeStudent($this->sonam, null, 'Admission Confirmed', '9100001003');  // admitted
        $this->makeStudent($this->sonam, null, 'Closed', '9100001004');               // closed
        $this->makeStudent($this->sonam, null, 'Lead Captured', '9100001005');        // excluded

        $r = PipelineSummary::byOwnerAfterCaptured()[$this->sonam->id];

        $this->assertSame(4, $r['count']);
        $this->assertSame(2, $r['active']);
        $this->assertSame(1, $r['admitted']);
        $this->assertSame(1, $r['closed']);
    }

    public function test_by_owner_omits_users_with_no_post_capture_students(): void
    {
        // Only Nikhil has post-capture students; Sonam has only Lead Captured
        $this->makeStudent($this->sonam, null, 'Lead Captured', '9100002001');
        $this->makeStudent($this->nikhil, null, 'Meeting Done', '9100002002');

        $result = PipelineSummary::byOwnerAfterCaptured();

        $this->assertArrayNotHasKey($this->sonam->id, $result);
        $this->assertArrayHasKey($this->nikhil->id, $result);
    }

    public function test_by_referrer_groups_by_referrer_not_owner(): void
    {
        // Owner=Sonam, referrer=Nikhil, past Lead Captured
        $this->makeStudent($this->sonam, $this->nikhil, 'Meeting Scheduled', '9100003001');
        $this->makeStudent($this->sonam, $this->nikhil, 'Admission Confirmed', '9100003002');
        // Same owner/referrer, still Lead Captured → excluded
        $this->makeStudent($this->sonam, $this->nikhil, 'Lead Captured', '9100003003');

        $result = PipelineSummary::byReferrerAfterCaptured();

        $this->assertArrayHasKey($this->nikhil->id, $result);
        $this->assertSame(2, $result[$this->nikhil->id]['count']);
        // Sonam should not appear as a referrer here (she owns but doesn't refer)
        $this->assertArrayNotHasKey($this->sonam->id, $result);
    }

    public function test_results_are_sorted_by_count_desc(): void
    {
        $this->makeStudent($this->sonam,  null, 'Meeting Done', '9100004001');
        $this->makeStudent($this->nikhil, null, 'Meeting Done', '9100004002');
        $this->makeStudent($this->nikhil, null, 'Admission Confirmed', '9100004003');
        $this->makeStudent($this->nikhil, null, 'Closed', '9100004004');

        $result = PipelineSummary::byOwnerAfterCaptured();
        $ids = array_keys($result);

        // Nikhil (3) should come before Sonam (1).
        $nikhilPos = array_search($this->nikhil->id, $ids, true);
        $sonamPos  = array_search($this->sonam->id, $ids, true);
        $this->assertLessThan($sonamPos, $nikhilPos);
    }
}
