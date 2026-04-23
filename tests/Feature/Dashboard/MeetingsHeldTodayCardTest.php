<?php

namespace Tests\Feature\Dashboard;

use App\Dashboard\Cards\Stat\MeetingsHeldTodayCard;
use App\Models\Meeting;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MeetingsHeldTodayCardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    private function admin(): User
    {
        return User::where('email', 'sumit@davya.local')->first();
    }

    private function createStudent(User $owner): Student
    {
        return Student::create([
            'phone' => (string) random_int(9000000000, 9999999999),
            'name' => 'Test '.uniqid(),
            'owner_id' => $owner->id,
            'lead_source' => 'Website',
            'stage' => 'Lead Captured',
        ]);
    }

    public function test_counts_meetings_held_today(): void
    {
        $admin = $this->admin();
        $student = $this->createStudent($admin);

        Meeting::create([
            'student_id' => $student->id,
            'owner_id' => $admin->id,
            'scheduled_at' => now()->subHours(2),
            'held_at' => now()->subHours(1),
            'status' => 'held',
            'mode' => 'in_person',
            'created_by_id' => $admin->id,
        ]);

        Meeting::create([
            'student_id' => $student->id,
            'owner_id' => $admin->id,
            'scheduled_at' => now()->subDay(),
            'held_at' => now()->subDay(),
            'status' => 'held',
            'mode' => 'in_person',
            'created_by_id' => $admin->id,
        ]);

        $card = new MeetingsHeldTodayCard;
        $payload = $card->drillDown($admin);

        $this->assertSame(1, $payload->query->count());
    }

    public function test_respects_scope_for_counsellors(): void
    {
        $admin = $this->admin();
        $counsellor = User::whereHas('roles', fn ($q) => $q->where('name', 'counsellor'))->first()
            ?? User::factory()->create();

        $adminStudent = $this->createStudent($admin);
        $counsellorStudent = $this->createStudent($counsellor);

        foreach ([$adminStudent, $counsellorStudent] as $s) {
            Meeting::create([
                'student_id' => $s->id,
                'owner_id' => $s->owner_id,
                'scheduled_at' => now()->subHours(1),
                'held_at' => now(),
                'status' => 'held',
                'mode' => 'in_person',
                'created_by_id' => $s->owner_id,
            ]);
        }

        $card = new MeetingsHeldTodayCard;
        $this->assertSame(2, $card->drillDown($admin)->query->count());
        $this->assertSame(1, $card->drillDown($counsellor)->query->count());
    }
}
