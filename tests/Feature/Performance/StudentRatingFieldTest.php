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

    public function test_academic_tab_renders_rating_field_after_rank(): void
    {
        $body = file_get_contents(app_path('Filament/Resources/StudentResource.php'));

        // Field is wired up
        $this->assertStringContainsString("TextInput::make('rank_prob_first_choice')", $body);
        $this->assertStringContainsString('Rating (1st choice probability %)', $body);

        // And appears AFTER the rank input (placement matters per Sumit's instruction)
        $rankPos = strpos($body, "TextInput::make('rank')->maxLength(40)");
        $ratingPos = strpos($body, "TextInput::make('rank_prob_first_choice')");

        $this->assertNotFalse($rankPos);
        $this->assertNotFalse($ratingPos);
        $this->assertGreaterThan($rankPos, $ratingPos, 'Rating field must appear after the rank field');
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
