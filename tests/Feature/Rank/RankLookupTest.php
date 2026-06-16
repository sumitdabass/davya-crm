<?php

namespace Tests\Feature\Rank;

use App\Filament\Pages\Rank\RankLookup;
use App\Models\User;
use App\Services\Rank\BranchFamilies;
use App\Services\Rank\GeminiCounsellor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Mockery;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class RankLookupTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        $this->admin = User::factory()->create(['is_active' => true]);
        $this->admin->assignRole('admin');
        $this->actingAs($this->admin);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        // Clean up the test admin user; cutoffs/colleges remain (read-only fixture).
        $this->admin->forceDelete();
        parent::tearDown();
    }

    /** @test */
    public function delhi_rank_138000_shows_top_7_colleges_in_demand_order(): void
    {
        $this->bindCounsellorMock();

        $component = Livewire::test(RankLookup::class)
            ->set('data.user_rank', 138000)
            ->set('data.region', 'delhi')
            ->set('data.year', 2026);

        $result = $component->instance()->getResultsProperty();
        $names = $result['colleges']->pluck('institute')->all();

        $this->assertSame(7, $result['visible_count']);
        $this->assertGreaterThanOrEqual(7, $result['colleges']->count());
        $this->assertSame('Maharaja Agrasen Institute of Technology', $names[0]);
        $this->assertSame('Maharaja Surajmal Institute of Technology', $names[1]);
        $this->assertSame("Bharati Vidyapeeth's College of Engineering", $names[2]);
    }

    /** @test */
    public function outside_delhi_rank_97000_returns_at_most_4_colleges(): void
    {
        $this->bindCounsellorMock();

        $component = Livewire::test(RankLookup::class)
            ->set('data.user_rank', 97000)
            ->set('data.region', 'outside_delhi')
            ->set('data.year', 2026);

        $result = $component->instance()->getResultsProperty();

        $this->assertSame('3', $result['prediction_round']);
        $this->assertLessThanOrEqual(7, $result['colleges']->count());
        $this->assertSame($result['colleges']->count(), $result['visible_count']);
    }

    /** @test */
    public function branch_family_filter_narrows_to_cs_only_excluding_it(): void
    {
        $this->bindCounsellorMock();

        $unfiltered = Livewire::test(RankLookup::class)
            ->set('data.user_rank', 138000)
            ->set('data.region', 'delhi')
            ->set('data.year', 2026)
            ->instance()->getResultsProperty();

        $csOnly = Livewire::test(RankLookup::class)
            ->set('data.user_rank', 138000)
            ->set('data.region', 'delhi')
            ->set('data.year', 2026)
            ->set('data.branch_families', ['cs'])
            ->instance()->getResultsProperty();

        $unfilteredBranches = $unfiltered['colleges']->sum(fn ($c) => count($c['branches']));
        $csOnlyBranches = $csOnly['colleges']->sum(fn ($c) => count($c['branches']));

        $this->assertLessThanOrEqual($unfilteredBranches, $csOnlyBranches);

        foreach ($csOnly['colleges'] as $col) {
            foreach ($col['branches'] as $b) {
                $this->assertSame(
                    'cs',
                    BranchFamilies::familyFor($b['branch']),
                    "branch '{$b['branch']}' is not cs_it",
                );
            }
        }
    }

    /** @test */
    public function show_more_expands_visible_count_to_total(): void
    {
        $this->bindCounsellorMock();

        $component = Livewire::test(RankLookup::class)
            ->set('data.user_rank', 138000)
            ->set('data.region', 'delhi')
            ->set('data.year', 2026);

        $before = $component->instance()->getResultsProperty();
        $beforeTotal = $before['colleges']->count();
        $this->assertSame(min(7, $beforeTotal), $before['visible_count']);

        $component->call('showMore');

        $after = $component->instance()->getResultsProperty();
        $this->assertSame($after['colleges']->count(), $after['visible_count']);
    }

    /** @test */
    public function ai_off_skips_gemini_and_leaves_notes_empty(): void
    {
        $mock = Mockery::mock(GeminiCounsellor::class);
        $mock->shouldNotReceive('note');
        $this->app->instance(GeminiCounsellor::class, $mock);

        $result = Livewire::test(RankLookup::class)
            ->set('data.aiOn', false)
            ->set('data.region', 'delhi')
            ->set('data.year', 2026)
            ->set('data.user_rank', 138000)
            ->instance()->getResultsProperty();

        $this->assertNotEmpty($result['colleges']);
        $this->assertEmpty($result['notes'] ?? []);
    }

    /** @test */
    public function lookup_source_drops_exam_and_process_select_inputs(): void
    {
        $src = file_get_contents(app_path('Filament/Pages/Rank/RankLookup.php'));
        $this->assertStringNotContainsString("Select::make('qualifying_exam_id')", $src);
        $this->assertStringNotContainsString("Select::make('admission_process_id')", $src);
    }

    private function bindCounsellorMock(): void
    {
        $mock = Mockery::mock(GeminiCounsellor::class);
        $mock->shouldReceive('note')->andReturn('Test note from mock.');
        $this->app->instance(GeminiCounsellor::class, $mock);
    }
}
