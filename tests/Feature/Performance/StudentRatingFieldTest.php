<?php

namespace Tests\Feature\Performance;

use App\Models\Student;
use App\Models\User;
use App\Services\Rank\StudentChoicePredictor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class StudentRatingFieldTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    public function test_separate_rating_tab_exists_after_academic_tab(): void
    {
        $body = file_get_contents(app_path('Filament/Resources/StudentResource.php'));

        // The Rating tab is a top-level Tab — separate from Academic, not inline with rank
        $this->assertStringContainsString("Tabs\\Tab::make('Rating')", $body);

        // The rating field is on the Rating tab, not the Academic tab
        $this->assertStringContainsString("TextInput::make('rank_prob_first_choice')", $body);
        $this->assertStringContainsString('Rating — 1st choice probability', $body);

        // And the Rating tab appears AFTER the Academic tab and BEFORE the Deal & Counselling tab
        $academicPos = strpos($body, "Tabs\\Tab::make('Academic')");
        $ratingPos = strpos($body, "Tabs\\Tab::make('Rating')");
        $dealPos = strpos($body, "Tabs\\Tab::make('Deal & Counselling')");

        $this->assertNotFalse($academicPos);
        $this->assertNotFalse($ratingPos);
        $this->assertNotFalse($dealPos);
        $this->assertGreaterThan($academicPos, $ratingPos, 'Rating tab must come after Academic');
        $this->assertLessThan($dealPos, $ratingPos, 'Rating tab must come before Deal & Counselling');
    }

    public function test_manual_rating_override_persists_when_rank_unchanged(): void
    {
        // Mock the predictor so the observer's compute path is deterministic
        $mock = Mockery::mock(StudentChoicePredictor::class);
        $mock->shouldReceive('topChoices')->andReturn([
            ['rank' => 1, 'college' => 'X', 'branch' => 'Y', 'probability_pct' => 30, 'bucket' => 'safe'],
        ]);
        $this->app->instance(StudentChoicePredictor::class, $mock);

        $owner = User::factory()->create();
        $s = Student::factory()->create([
            'owner_id' => $owner->id, 'referrer_id' => $owner->id,
            'rank' => '10000', 'category' => 'Delhi', 'preference_r1' => 'NSUT/IT',
        ]);

        $this->assertSame(30, (int) $s->fresh()->rank_prob_first_choice);

        // User manually edits ONLY the rating — observer should NOT recompute
        $s->rank_prob_first_choice = 90;
        $s->save();

        $this->assertSame(90, (int) $s->fresh()->rank_prob_first_choice,
            'Manual rating override must persist when rank/category/preference_r1 unchanged.');
    }

    public function test_manual_rating_override_loses_to_auto_compute_when_rank_changes(): void
    {
        $mock = Mockery::mock(StudentChoicePredictor::class);
        $mock->shouldReceive('topChoices')->andReturn([
            ['rank' => 1, 'college' => 'X', 'branch' => 'Y', 'probability_pct' => 30, 'bucket' => 'safe'],
        ]);
        $this->app->instance(StudentChoicePredictor::class, $mock);

        $owner = User::factory()->create();
        $s = Student::factory()->create([
            'owner_id' => $owner->id, 'referrer_id' => $owner->id,
            'rank' => '10000', 'category' => 'Delhi', 'preference_r1' => 'NSUT/IT',
        ]);

        // Override manually first
        $s->rank_prob_first_choice = 90;
        $s->save();
        $this->assertSame(90, (int) $s->fresh()->rank_prob_first_choice);

        // Re-mock to a different value, then change rank → observer recomputes
        $mock2 = Mockery::mock(StudentChoicePredictor::class);
        $mock2->shouldReceive('topChoices')->andReturn([
            ['rank' => 1, 'college' => 'X', 'branch' => 'Y', 'probability_pct' => 55, 'bucket' => 'safe'],
        ]);
        $this->app->instance(StudentChoicePredictor::class, $mock2);

        $s->update(['rank' => '5000']);

        $this->assertSame(55, (int) $s->fresh()->rank_prob_first_choice,
            'Auto-compute must overwrite manual override when rank changes.');
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
