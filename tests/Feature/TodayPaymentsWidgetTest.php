<?php

namespace Tests\Feature;

use App\Filament\Widgets\TodayPaymentsWidget;
use App\Models\Payment;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class TodayPaymentsWidgetTest extends TestCase
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
            'phone' => '9966'.str_pad((string) random_int(1, 999999), 6, '0', STR_PAD_LEFT),
            'course' => 'BBA',
            'stage' => 'Lead Captured',
            'owner_id' => $owner->id,
            'lead_source' => 'Test',
        ]);
    }

    public function test_today_rows_only(): void
    {
        $this->seed();
        $nikhil = $this->unblock(User::where('email', 'nikhil@davya.local')->firstOrFail());
        $this->actingAs($nikhil);

        $s = $this->mkStudent($nikhil);

        Payment::create([
            'student_id' => $s->id,
            'type' => 'advance', 'amount' => 1000, 'mode' => 'cash',
            'received_at' => now('Asia/Kolkata')->startOfDay()->addHours(9),
            'recorded_by_user_id' => $nikhil->id,
        ]);
        Payment::create([
            'student_id' => $s->id,
            'type' => 'partial', 'amount' => 500, 'mode' => 'upi',
            'received_at' => now('Asia/Kolkata')->subDay(),
            'recorded_by_user_id' => $nikhil->id,
        ]);

        $rows  = Livewire::test(TodayPaymentsWidget::class)->get('rows');
        $total = Livewire::test(TodayPaymentsWidget::class)->get('total');

        $this->assertCount(1, $rows, 'only today rows must appear');
        $this->assertSame(1000.0, (float) $total);
    }

    public function test_scoping_head_sees_own_team_payments_only(): void
    {
        $this->seed();
        $nikhil = $this->unblock(User::where('email', 'nikhil@davya.local')->firstOrFail());
        $sonam  = User::where('email', 'sonam@davya.local')->firstOrFail();

        $ns = $this->mkStudent($nikhil, 'N');
        $ss = $this->mkStudent($sonam, 'S');

        Payment::create([
            'student_id' => $ns->id, 'type' => 'advance', 'amount' => 100, 'mode' => 'cash',
            'received_at' => now('Asia/Kolkata'), 'recorded_by_user_id' => $nikhil->id,
        ]);
        Payment::create([
            'student_id' => $ss->id, 'type' => 'advance', 'amount' => 999, 'mode' => 'cash',
            'received_at' => now('Asia/Kolkata'), 'recorded_by_user_id' => $sonam->id,
        ]);

        $this->actingAs($nikhil);
        $rows = Livewire::test(TodayPaymentsWidget::class)->get('rows');
        $this->assertCount(1, $rows);
        $this->assertSame(100.0, (float) $rows[0]['amount']);
    }
}
