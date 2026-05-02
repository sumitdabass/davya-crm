<?php

namespace Tests\Feature\Performance;

use App\Models\Student;
use App\Models\User;
use App\Services\Rank\StudentChoicePredictor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class StudentRankProbabilityObserverTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    public function test_creating_student_with_rank_calls_predictor_and_caches_probability(): void
    {
        $this->mockPredictor(returning: 73);

        $student = $this->makeStudent(['rank' => '12345', 'category' => 'Delhi', 'preference_r1' => 'NSUT/IT']);

        $this->assertSame(73, (int) $student->fresh()->rank_prob_first_choice);
    }

    public function test_creating_student_without_rank_leaves_cache_null(): void
    {
        $this->mockPredictor(returning: null);

        $student = $this->makeStudent(['rank' => null, 'category' => 'Delhi', 'preference_r1' => 'NSUT/IT']);

        $this->assertNull($student->fresh()->rank_prob_first_choice);
    }

    public function test_updating_rank_recomputes_probability(): void
    {
        $this->mockPredictor(returning: 50);
        $student = $this->makeStudent(['rank' => '20000', 'category' => 'Delhi', 'preference_r1' => 'NSUT/IT']);
        $this->assertSame(50, (int) $student->fresh()->rank_prob_first_choice);

        $this->mockPredictor(returning: 88);
        $student->update(['rank' => '5000']);

        $this->assertSame(88, (int) $student->fresh()->rank_prob_first_choice);
    }

    public function test_updating_unrelated_attribute_does_not_recompute(): void
    {
        $this->mockPredictor(returning: 50);
        $student = $this->makeStudent(['rank' => '20000', 'category' => 'Delhi', 'preference_r1' => 'NSUT/IT']);
        $this->assertSame(50, (int) $student->fresh()->rank_prob_first_choice);

        $spy = Mockery::mock(StudentChoicePredictor::class);
        $spy->shouldNotReceive('topChoices');
        $this->app->instance(StudentChoicePredictor::class, $spy);

        $student->update(['father_name' => 'Updated Name']);

        $this->assertSame(50, (int) $student->fresh()->rank_prob_first_choice);
    }

    public function test_updating_category_recomputes(): void
    {
        $this->mockPredictor(returning: 60);
        $student = $this->makeStudent(['rank' => '10000', 'category' => 'Delhi', 'preference_r1' => 'NSUT/IT']);
        $this->assertSame(60, (int) $student->fresh()->rank_prob_first_choice);

        $this->mockPredictor(returning: 40);
        $student->update(['category' => 'Outside']);

        $this->assertSame(40, (int) $student->fresh()->rank_prob_first_choice);
    }

    public function test_updating_preference_r1_recomputes(): void
    {
        $this->mockPredictor(returning: 60);
        $student = $this->makeStudent(['rank' => '10000', 'category' => 'Delhi', 'preference_r1' => 'NSUT/IT']);
        $this->assertSame(60, (int) $student->fresh()->rank_prob_first_choice);

        $this->mockPredictor(returning: 95);
        $student->update(['preference_r1' => 'IGDTUW/CSE']);

        $this->assertSame(95, (int) $student->fresh()->rank_prob_first_choice);
    }

    public function test_predictor_returning_empty_array_leaves_cache_null(): void
    {
        $mock = Mockery::mock(StudentChoicePredictor::class);
        $mock->shouldReceive('topChoices')->andReturn([]);
        $this->app->instance(StudentChoicePredictor::class, $mock);

        $student = $this->makeStudent(['rank' => '999999', 'category' => 'Delhi', 'preference_r1' => 'NSUT/IT']);

        $this->assertNull($student->fresh()->rank_prob_first_choice);
    }

    private function mockPredictor(?int $returning): void
    {
        $mock = Mockery::mock(StudentChoicePredictor::class);
        if ($returning === null) {
            $mock->shouldReceive('topChoices')->andReturn([]);
        } else {
            $mock->shouldReceive('topChoices')->andReturn([
                [
                    'rank' => 1,
                    'college' => 'NSUT',
                    'branch' => 'IT',
                    'probability_pct' => $returning,
                    'bucket' => 'safe',
                ],
            ]);
        }
        $this->app->instance(StudentChoicePredictor::class, $mock);
    }

    private function makeStudent(array $overrides): Student
    {
        $owner = User::factory()->create();
        return Student::factory()->create(array_merge([
            'owner_id' => $owner->id,
            'referrer_id' => $owner->id,
        ], $overrides));
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
