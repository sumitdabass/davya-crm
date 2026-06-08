<?php

namespace Tests\Feature;

use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StudentPayoutFormTest extends TestCase
{
    use RefreshDatabase;

    public function test_payout_persists_via_relationship_and_stamps_recorder(): void
    {
        $user = User::factory()->create();
        $student = Student::factory()->create(['deal_amount' => 100000, 'owner_id' => $user->id]);
        $this->actingAs($user);
        $payout = $student->payouts()->create([
            'payee_type' => 'college',
            'payee_name' => 'GGSIPU',
            'amount' => 40000,
            'status' => 'to_pay',
            'recorded_by_user_id' => $user->id,
        ]);
        $this->assertDatabaseHas('payouts', [
            'id' => $payout->id,
            'student_id' => $student->id,
            'payee_type' => 'college',
            'amount' => 40000,
            'recorded_by_user_id' => $user->id,
        ]);
        $this->assertEquals(60000.0, $student->refresh()->expected_profit);
    }
}
