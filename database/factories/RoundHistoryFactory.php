<?php

namespace Database\Factories;

use App\Models\RoundHistory;
use App\Models\Student;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\RoundHistory>
 */
class RoundHistoryFactory extends Factory
{
    protected $model = RoundHistory::class;

    public function definition(): array
    {
        return [
            'student_id'       => Student::factory(),
            'round_name'       => $this->faker->randomElement([
                'Online_R1', 'Online_R2', 'Online_R3', 'Online_Sliding', 'Online_Reporting',
                'S2_R1', 'S2_R3', 'Offline_R1', 'Offline_R2',
            ]),
            'allotted_college' => null,
            'allotted_course'  => null,
            'seat_fee_amount'  => null,
            'seat_fee_paid'    => false,
            'fee_paid_at'      => null,
            'outcome'          => $this->faker->randomElement([
                'Not Allotted', 'Allotted — Fee Pending', 'Allotted — Fee Paid',
                'Kicked Out — Fee Unpaid', 'Allotted — Frozen (Final)',
            ]),
            'notes'            => null,
        ];
    }
}
