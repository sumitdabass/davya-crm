<?php

namespace Tests\Feature;

use App\Filament\Widgets\TodayMeetingsWidget;
use App\Models\Meeting;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class TodayMeetingsWidgetTest extends TestCase
{
    use RefreshDatabase;

    private function unblock(User $u): User
    {
        $u->must_change_password = false;
        $u->save();
        return $u;
    }

    private function mkStudent(User $owner, string $name = 'S'): Student
    {
        return Student::create([
            'name' => $name,
            'phone' => '9977'.str_pad((string) random_int(1, 999999), 6, '0', STR_PAD_LEFT),
            'course' => 'BBA',
            'stage' => 'Lead Captured',
            'owner_id' => $owner->id,
            'lead_source' => 'Test',
        ]);
    }

    private function mkMeeting(Student $student, User $owner, \Carbon\Carbon $at, string $status = 'scheduled'): Meeting
    {
        return Meeting::create([
            'student_id' => $student->id,
            'owner_id' => $owner->id,
            'scheduled_at' => $at,
            'mode' => 'in_person',
            'status' => $status,
            'created_by_id' => $owner->id,
        ]);
    }

    public function test_widget_window_is_today_plus_four_days(): void
    {
        $this->seed();
        $nikhil = $this->unblock(User::where('email', 'nikhil@davya.local')->firstOrFail());
        $this->actingAs($nikhil);
        $s = $this->mkStudent($nikhil);

        $this->mkMeeting($s, $nikhil, now('Asia/Kolkata')->startOfDay()->addHours(10));
        $this->mkMeeting($s, $nikhil, now('Asia/Kolkata')->addDays(4)->startOfDay());
        $this->mkMeeting($s, $nikhil, now('Asia/Kolkata')->addDays(5)->startOfDay());
        $this->mkMeeting($s, $nikhil, now('Asia/Kolkata')->subDays(1)->startOfDay());

        $days = Livewire::test(TodayMeetingsWidget::class)->get('days');

        $this->assertCount(5, $days);
        $this->assertGreaterThanOrEqual(1, count($days[0]['meetings']));
        $this->assertCount(1, $days[4]['meetings']);
    }

    public function test_overdue_flag_on_past_scheduled_meeting(): void
    {
        $this->seed();
        $nikhil = $this->unblock(User::where('email', 'nikhil@davya.local')->firstOrFail());
        $this->actingAs($nikhil);
        $s = $this->mkStudent($nikhil);

        $meeting = $this->mkMeeting($s, $nikhil, now('Asia/Kolkata')->subHour(), 'scheduled');

        $days = Livewire::test(TodayMeetingsWidget::class)->get('days');
        $todayCards = $days[0]['meetings'];

        $overdue = collect($todayCards)->firstWhere('id', $meeting->id);
        $this->assertNotNull($overdue, 'overdue meeting must render in Today column');
        $this->assertTrue($overdue['is_overdue'], 'past scheduled meeting must be flagged overdue');
    }

    public function test_scoping_head_sees_own_team_only(): void
    {
        $this->seed();
        $nikhil = User::where('email', 'nikhil@davya.local')->firstOrFail();
        $sonam  = User::where('email', 'sonam@davya.local')->firstOrFail();

        $this->mkMeeting(
            $this->mkStudent($nikhil, 'N Student'),
            $nikhil,
            now('Asia/Kolkata')->addHours(3),
        );
        $this->mkMeeting(
            $this->mkStudent($sonam, 'S Student'),
            $sonam,
            now('Asia/Kolkata')->addHours(3),
        );

        $this->actingAs($this->unblock($nikhil));
        $days = Livewire::test(TodayMeetingsWidget::class)->get('days');
        $total = collect($days)->sum(fn ($d) => count($d['meetings']));
        $this->assertSame(1, $total, 'head sees own team meetings only');
    }

    public function test_schedule_action_creates_a_meeting_for_today(): void
    {
        $this->seed();
        $nikhil = $this->unblock(User::where('email', 'nikhil@davya.local')->firstOrFail());
        $this->actingAs($nikhil);
        $s = $this->mkStudent($nikhil, 'For Scheduling');

        $at = now('Asia/Kolkata')->setTime(15, 0);

        Livewire::test(TodayMeetingsWidget::class)
            ->callAction('schedule', data: [
                'student_id'   => $s->id,
                'scheduled_at' => $at->toDateTimeString(),
                'mode'         => 'phone',
                'notes'        => 'from today strip',
            ])
            ->assertHasNoActionErrors();

        $m = Meeting::where('student_id', $s->id)->first();
        $this->assertNotNull($m);
        $this->assertSame('scheduled', $m->status);
        $this->assertSame('phone', $m->mode);
        $this->assertSame($nikhil->id, $m->owner_id);
        $this->assertSame($nikhil->id, $m->created_by_id);
    }
}
