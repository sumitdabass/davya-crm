<?php

namespace Tests\Feature;

use App\Filament\Pages\KanbanBoard;
use App\Models\Payment;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class KanbanAggregateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    private function admin(): User
    {
        $user = User::where('email', 'sumit@davya.local')->firstOrFail();
        $user->must_change_password = false;
        $user->save();
        return $user;
    }

    private function student(array $overrides): Student
    {
        $nikhil = User::where('email', 'nikhil@davya.local')->first();

        return Student::create(array_merge([
            'phone'       => '9'.rand(100000000, 999999999),
            'name'        => 'S',
            'owner_id'    => $nikhil->id,
            'referrer_id' => $nikhil->id,
            'lead_source' => 'Nikhil',
        ], $overrides));
    }

    public function test_stage_column_reports_received_and_pending_totals(): void
    {
        $nikhil = User::where('email', 'nikhil@davya.local')->first();
        $admin  = $this->admin();

        $s1 = $this->student(['stage' => 'Advance Received', 'deal_amount' => 200000]);
        $s2 = $this->student(['stage' => 'Advance Received', 'deal_amount' => 100000]);

        Payment::create([
            'student_id'           => $s1->id,
            'type'                 => 'advance',
            'amount'               => 125000,
            'received_at'          => now(),
            'recorded_by_user_id'  => $nikhil->id,
        ]);
        Payment::create([
            'student_id'           => $s2->id,
            'type'                 => 'advance',
            'amount'               => 40000,
            'received_at'          => now(),
            'recorded_by_user_id'  => $nikhil->id,
        ]);

        $this->actingAs($admin);
        $page  = new KanbanBoard;
        $board = $page->getBoard();

        $column = collect($board)->firstWhere('stage', 'Advance Received');
        $this->assertNotNull($column, 'Advance Received column should be present in board');
        $this->assertSame(165000.0, (float) $column['received_total']);
        $this->assertSame(135000.0, (float) $column['pending_total']);
        $this->assertSame(2, $column['count']);
    }
}
