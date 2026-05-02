<?php

namespace Tests\Feature\Performance;

use App\Models\Student;
use App\Models\User;
use App\Services\Rank\StudentChoicePredictor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Mockery;
use Tests\TestCase;

class BackfillRankProbabilityCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    public function test_command_populates_probability_for_students_with_rank(): void
    {
        $this->mockPredictor(returning: 50);
        $owner = User::factory()->create();
        Student::factory()->count(3)->create([
            'owner_id' => $owner->id,
            'referrer_id' => $owner->id,
            'rank' => '10000',
            'category' => 'Delhi',
            'preference_r1' => 'NSUT/IT',
        ]);

        Student::query()->update(['rank_prob_first_choice' => null]);

        $this->mockPredictor(returning: 77);

        $exitCode = Artisan::call('performance:backfill-rank-probabilities');

        $this->assertSame(0, $exitCode);
        $this->assertEquals(
            [77, 77, 77],
            Student::pluck('rank_prob_first_choice')->map(fn($v) => (int) $v)->all()
        );
    }

    public function test_command_skips_students_without_rank(): void
    {
        $owner = User::factory()->create();
        $withRank = Student::factory()->create([
            'owner_id' => $owner->id, 'referrer_id' => $owner->id,
            'rank' => '5000', 'category' => 'Delhi', 'preference_r1' => 'NSUT/IT',
        ]);
        $withoutRank = Student::factory()->create([
            'owner_id' => $owner->id, 'referrer_id' => $owner->id,
            'rank' => null, 'category' => 'Delhi', 'preference_r1' => 'NSUT/IT',
        ]);

        Student::query()->update(['rank_prob_first_choice' => null]);
        $this->mockPredictor(returning: 60);

        Artisan::call('performance:backfill-rank-probabilities');

        $this->assertSame(60, (int) $withRank->fresh()->rank_prob_first_choice);
        $this->assertNull($withoutRank->fresh()->rank_prob_first_choice);
    }

    public function test_command_is_idempotent(): void
    {
        $owner = User::factory()->create();
        Student::factory()->count(2)->create([
            'owner_id' => $owner->id, 'referrer_id' => $owner->id,
            'rank' => '5000', 'category' => 'Delhi', 'preference_r1' => 'NSUT/IT',
        ]);
        $this->mockPredictor(returning: 60);
        Student::query()->update(['rank_prob_first_choice' => null]);

        Artisan::call('performance:backfill-rank-probabilities');
        Artisan::call('performance:backfill-rank-probabilities');

        $this->assertEquals(
            [60, 60],
            Student::pluck('rank_prob_first_choice')->map(fn($v) => (int) $v)->all()
        );
    }

    private function mockPredictor(?int $returning): void
    {
        $mock = Mockery::mock(StudentChoicePredictor::class);
        if ($returning === null) {
            $mock->shouldReceive('topChoices')->andReturn([]);
        } else {
            $mock->shouldReceive('topChoices')->andReturn([
                ['rank'=>1,'college'=>'NSUT','branch'=>'IT','probability_pct'=>$returning,'bucket'=>'safe'],
            ]);
        }
        $this->app->instance(StudentChoicePredictor::class, $mock);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
