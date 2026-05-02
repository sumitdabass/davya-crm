<?php

namespace Tests\Feature;

use App\Models\Student;
use App\Models\User;
use App\Services\Rank\StudentChoicePredictor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StudentChoicePredictorTest extends TestCase
{
    use RefreshDatabase;

    public function test_returns_empty_when_rank_is_missing(): void
    {
        $this->seed();
        $owner = User::where('email', 'sonam@davya.local')->firstOrFail();
        $s = Student::create([
            'phone' => '9100050001', 'name' => 'NoRank',
            'owner_id' => $owner->id, 'referrer_id' => $owner->id,
            'lead_source' => 'X', 'stage' => 'Lead Captured',
            'category' => 'Delhi',
            // rank intentionally omitted
        ]);

        $this->assertSame([], app(StudentChoicePredictor::class)->topChoices($s));
    }

    public function test_returns_empty_when_rank_is_zero(): void
    {
        $this->seed();
        $owner = User::where('email', 'sonam@davya.local')->firstOrFail();
        $s = Student::create([
            'phone' => '9100050002', 'name' => 'ZeroRank',
            'owner_id' => $owner->id, 'referrer_id' => $owner->id,
            'lead_source' => 'X', 'stage' => 'Lead Captured',
            'category' => 'Delhi', 'rank' => 0,
        ]);

        $this->assertSame([], app(StudentChoicePredictor::class)->topChoices($s));
    }
}
