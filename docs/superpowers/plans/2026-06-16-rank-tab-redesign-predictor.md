# Rank Tab Redesign — Plan 2: Predictor Pages Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ship working in-CRM rank predictors for both datasets — a DTU (JAC) predictor and an upgraded IPU predictor — sharing one engine and UI, with gender/category/sub-category/region filtering, SAFE→UNLIKELY chance chips, NSUT campus column, a "within reach only" toggle, and Print / Save-as-PDF.

**Architecture:** A dataset-agnostic `DatasetCutoffPredictor::predict(PredictorContext)` reuses the Plan 1 `BenchmarkRoundStrategy` + `RankPredictor::chance()`, scopes every query to one dataset's universities (never merging IPU and DTU), and returns rows grouped by institute (NSUT campus = institute) + branch. An abstract Filament page `AbstractRankPredict` holds the shared form + results; two thin subclasses `DtuPredict` and `IpuPredict` configure dataset, course behaviour, and access. One shared blade renders the table + print styles.

**Tech Stack:** Laravel 11, Filament 3 (Pages + Forms + Livewire), PHPUnit 11, MySQL (default + `ranks` connections).

---

## Builds on Plan 1 (already merged on this branch)

Available: `App\Rank\RankDataset` (tokens `ipu`/`dtu`, `universityCodes()`, `courseFixedToBtech()`), `App\Services\Rank\BenchmarkRoundStrategy::pick($token,$category,$availableRounds)`, `App\Services\Rank\RankPredictor::chance($rank,$cr)` + `withinReach()`, `App\Services\Rank\PredictorContext`, scoped roles `rank-{ipu,dtu}-{predict,analyse}`, `User::canRankPredict($dataset)`, and loaded JAC cutoffs (DTU/NSUT Main+East+West/IGDTUW; category/sub_category populated; min_rank=0, max_rank=closing).

## Conventions

- Tests: `php artisan test --filter <Name>`. Rank models use the `ranks` connection; `RefreshDatabase` does NOT refresh it. New tests that read/write `ranks` set `protected $connectionsToTransact = ['ranks'];` and, when asserting counts, isolate via a `setUp()` that `forceDelete()`s the JAC university's cutoffs (rolls back with the transaction). NEVER `migrate:fresh` the `ranks` DB (it wipes the shared IPU fixture).
- Filament-view feature tests can exceed the 128 MB `artisan test` worker cap — if so, run that one test via `php -d memory_limit=2G ./vendor/bin/phpunit --filter <Name>`.
- Commit after each task. Branch `feat/rank-tab-redesign`.
- Category vocabulary `general|ews|obc|sc|st`; sub-category `gender_neutral|girl|single_girl|pwd|defense_cw|kashmiri_migrant`; female-only sub-categories: `girl`, `single_girl`; women-only institute names: `IGDTUW`.

---

## Task 1: `DatasetCutoffPredictor` engine

**Files:**
- Create: `app/Services/Rank/DatasetCutoffPredictor.php`
- Test: `tests/Feature/Rank/DatasetCutoffPredictorTest.php`

Returns prediction rows for ONE dataset. Reuses `BenchmarkRoundStrategy` + `RankPredictor::chance()`. Scopes by category + sub_category + region. Male input excludes female-only sub-categories and women-only institutes.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Rank;

use App\Models\Rank\AdmissionProcess;
use App\Models\Rank\Branch;
use App\Models\Rank\Course;
use App\Models\Rank\Cutoff;
use App\Models\Rank\Institute;
use App\Models\Rank\QualifyingExam;
use App\Models\Rank\University;
use App\Services\Rank\DatasetCutoffPredictor;
use App\Services\Rank\PredictorContext;
use Database\Seeders\Rank\JacDelhiSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DatasetCutoffPredictorTest extends TestCase
{
    use RefreshDatabase;

    protected $connectionsToTransact = ['ranks'];

    private University $jac;
    private Course $course;
    private QualifyingExam $exam;
    private AdmissionProcess $process;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(JacDelhiSeeder::class);
        $this->jac = University::where('code', 'JAC')->first();
        // isolate from any bulk-imported JAC cutoffs
        Cutoff::where('university_id', $this->jac->id)->forceDelete();
        $this->course = Course::where('university_id', $this->jac->id)->where('name', 'B.Tech')->first();
        $this->exam = QualifyingExam::where('code', 'JEE_MAIN')->first();
        $this->process = AdmissionProcess::where('code', 'JAC')->first();
    }

    private function cutoff(string $institute, string $branchName, string $category, string $sub, string $region, string $round, int $cr): void
    {
        $inst = Institute::firstOrCreate(['university_id' => $this->jac->id, 'name' => $institute]);
        $branch = Branch::firstOrCreate(['course_id' => $this->course->id, 'name' => $branchName]);
        Cutoff::create([
            'university_id' => $this->jac->id, 'course_id' => $this->course->id,
            'qualifying_exam_id' => $this->exam->id, 'admission_process_id' => $this->process->id,
            'year' => 2025, 'round' => $round, 'institute_id' => $inst->id, 'branch_id' => $branch->id,
            'shift' => null, 'region' => $region, 'category' => $category, 'sub_category' => $sub,
            'min_rank' => 0, 'max_rank' => $cr, 'source' => 'official',
        ]);
    }

    /** @test */
    public function predicts_dtu_rows_with_chance_and_uses_final_round(): void
    {
        $this->cutoff('DTU', 'Computer Science and Engineering', 'general', 'gender_neutral', 'delhi', '1', 9000);
        $this->cutoff('DTU', 'Computer Science and Engineering', 'general', 'gender_neutral', 'delhi', '5', 11352);

        $ctx = new PredictorContext('dtu', rank: 11000, region: 'delhi', category: 'general', subCategory: 'gender_neutral', gender: 'male', year: 2025);
        $result = (new DatasetCutoffPredictor)->predict($ctx);

        $this->assertCount(1, $result['rows']);
        $row = $result['rows'][0];
        $this->assertSame('DTU', $row['institute']);
        $this->assertSame('Computer Science and Engineering', $row['branch']);
        $this->assertSame(11352, $row['final_cr']);    // final round (5), not round 1
        $this->assertSame(9000, $row['r1_cr']);
        $this->assertSame('LIKELY', $row['chance']);    // 11000/11352 ≈ 0.97
        $this->assertSame(1, $result['reach_count']);
    }

    /** @test */
    public function male_excludes_women_only_institute_and_female_subcategories(): void
    {
        $this->cutoff('IGDTUW', 'CSE-AI', 'general', 'gender_neutral', 'delhi', '5', 44405);
        $this->cutoff('DTU', 'Computer Science and Engineering', 'general', 'gender_neutral', 'delhi', '5', 11352);

        $male = new PredictorContext('dtu', rank: 50000, region: 'delhi', category: 'general', subCategory: 'gender_neutral', gender: 'male', year: 2025);
        $names = array_column((new DatasetCutoffPredictor)->predict($male)['rows'], 'institute');
        $this->assertContains('DTU', $names);
        $this->assertNotContains('IGDTUW', $names);   // women-only, hidden for male

        $female = new PredictorContext('dtu', rank: 50000, region: 'delhi', category: 'general', subCategory: 'gender_neutral', gender: 'female', year: 2025);
        $namesF = array_column((new DatasetCutoffPredictor)->predict($female)['rows'], 'institute');
        $this->assertContains('IGDTUW', $namesF);     // visible for female
    }

    /** @test */
    public function scopes_strictly_to_the_dataset_university(): void
    {
        // An IPU cutoff must never appear in a DTU prediction.
        $ipu = University::firstOrCreate(['code' => 'IPU'], ['name' => 'IPU']);
        $ipuCourse = Course::firstOrCreate(['university_id' => $ipu->id, 'name' => 'B.Tech']);
        $ipuInst = Institute::firstOrCreate(['university_id' => $ipu->id, 'name' => 'USICT']);
        $ipuBranch = Branch::firstOrCreate(['course_id' => $ipuCourse->id, 'name' => 'CSE']);
        Cutoff::create([
            'university_id' => $ipu->id, 'course_id' => $ipuCourse->id, 'qualifying_exam_id' => $this->exam->id,
            'admission_process_id' => $this->process->id, 'year' => 2025, 'round' => '5',
            'institute_id' => $ipuInst->id, 'branch_id' => $ipuBranch->id, 'shift' => null,
            'region' => 'delhi', 'category' => 'general', 'sub_category' => 'gender_neutral',
            'min_rank' => 0, 'max_rank' => 30000, 'source' => 'official',
        ]);
        $this->cutoff('DTU', 'Computer Science and Engineering', 'general', 'gender_neutral', 'delhi', '5', 11352);

        $names = array_column((new DatasetCutoffPredictor)->predict(
            new PredictorContext('dtu', rank: 50000, region: 'delhi', category: 'general', subCategory: 'gender_neutral', year: 2025)
        )['rows'], 'institute');
        $this->assertSame(['DTU'], array_values(array_unique($names)));   // no USICT
    }
}
```

- [ ] **Step 2: Run → FAIL** (`php artisan test --filter DatasetCutoffPredictorTest`) — class not found.

- [ ] **Step 3: Implement `app/Services/Rank/DatasetCutoffPredictor.php`**

```php
<?php

namespace App\Services\Rank;

use App\Models\Rank\Course;
use App\Models\Rank\Cutoff;
use App\Models\Rank\University;
use App\Rank\RankDataset;

class DatasetCutoffPredictor
{
    /** Sub-categories only available to female candidates. */
    private const FEMALE_ONLY_SUBS = ['girl', 'single_girl'];

    /** Institute names that admit women only. */
    private const WOMEN_ONLY_INSTITUTES = ['IGDTUW'];

    public function __construct(
        private RankPredictor $predictor = new RankPredictor,
        private BenchmarkRoundStrategy $rounds = new BenchmarkRoundStrategy,
    ) {}

    /**
     * @return array{rows: array<int, array{institute:string, branch:string, chance:string, final_round:string, final_cr:int, r1_cr:int, women_only:bool}>, reach_count:int}
     */
    public function predict(PredictorContext $ctx): array
    {
        $empty = ['rows' => [], 'reach_count' => 0];
        if ($ctx->rank <= 0) {
            return $empty;
        }

        // Male candidates cannot use female-only sub-categories.
        if ($ctx->isMale() && in_array($ctx->subCategory, self::FEMALE_ONLY_SUBS, true)) {
            return $empty;
        }

        $universityIds = University::whereIn('code', RankDataset::universityCodes($ctx->datasetToken))
            ->pluck('id')->all();
        if ($universityIds === []) {
            return $empty;
        }

        // Resolve course: DTU is fixed to B.Tech; IPU uses the provided course.
        $courseId = $ctx->courseId;
        if (RankDataset::courseFixedToBtech($ctx->datasetToken)) {
            $courseId = Course::whereIn('university_id', $universityIds)->where('name', 'B.Tech')->value('id');
        }
        if (! $courseId) {
            return $empty;
        }

        $year = $ctx->year ?? (int) Cutoff::whereIn('university_id', $universityIds)
            ->where('course_id', $courseId)->max('year');

        $query = Cutoff::with(['institute', 'branch'])
            ->whereIn('university_id', $universityIds)
            ->where('course_id', $courseId)
            ->where('year', $year)
            ->where('region', $ctx->region)
            ->where('category', $ctx->category);
        if ($ctx->subCategory !== null) {
            $query->where('sub_category', $ctx->subCategory);
        }
        if ($ctx->branchIds !== null) {
            $query->whereIn('branch_id', $ctx->branchIds);
        }

        // group by institute+branch
        $groups = [];
        foreach ($query->get() as $c) {
            $instName = $c->institute?->name ?? '—';
            if ($ctx->isMale() && in_array($instName, self::WOMEN_ONLY_INSTITUTES, true)) {
                continue;
            }
            $key = $c->institute_id.'|'.$c->branch_id;
            $groups[$key] ??= [
                'institute' => $instName,
                'branch' => $c->branch?->name ?? '—',
                'women_only' => in_array($instName, self::WOMEN_ONLY_INSTITUTES, true),
                'rounds' => [],
            ];
            $groups[$key]['rounds'][$c->round] = (int) $c->max_rank;
        }

        $rows = [];
        foreach ($groups as $g) {
            $present = array_keys($g['rounds']);
            $round = $this->rounds->pick($ctx->datasetToken, $ctx->category, $present);
            if ($round === null) {
                continue;
            }
            $cr = $g['rounds'][$round];
            $r1 = $g['rounds']['1'] ?? $g['rounds'][min($present)] ?? $cr;
            $rows[] = [
                'institute' => $g['institute'],
                'branch' => $g['branch'],
                'women_only' => $g['women_only'],
                'final_round' => $round,
                'final_cr' => $cr,
                'r1_cr' => $r1,
                'chance' => $this->predictor->chance($ctx->rank, $cr),
            ];
        }

        usort($rows, fn ($a, $b) => $a['final_cr'] <=> $b['final_cr']);
        $reach = count(array_filter($rows, fn ($r) => $r['chance'] !== 'UNLIKELY'));

        return ['rows' => $rows, 'reach_count' => $reach];
    }
}
```

- [ ] **Step 4: Run → PASS** (`php artisan test --filter DatasetCutoffPredictorTest`).

- [ ] **Step 5: Commit**

```bash
git add app/Services/Rank/DatasetCutoffPredictor.php tests/Feature/Rank/DatasetCutoffPredictorTest.php
git commit -m "feat(rank): DatasetCutoffPredictor engine (dataset-scoped, chance, gender rules)"
```

---

## Task 2: Abstract predictor page `AbstractRankPredict`

**Files:**
- Create: `app/Filament/Pages/Rank/AbstractRankPredict.php`
- (no test here — exercised via the concrete pages in Tasks 3-4)

Holds the shared form, the `results` computed property (delegating to the engine), the `withinReachOnly` toggle, and abstract hooks `datasetToken()` / `showsCourseSelector()`. Dropdowns are gender → category → sub-category, plus region. Sub-category options drop the female-only ones when gender = male.

- [ ] **Step 1: Create the abstract page**

```php
<?php

namespace App\Filament\Pages\Rank;

use App\Models\Rank\Course;
use App\Models\Rank\Cutoff;
use App\Models\Rank\University;
use App\Rank\RankDataset;
use App\Services\Rank\DatasetCutoffPredictor;
use App\Services\Rank\PredictorContext;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Pages\Page;

abstract class AbstractRankPredict extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationGroup = 'Rank Predictor';

    protected static string $view = 'filament.pages.rank.rank-predict';

    public ?array $data = [];

    /** Dataset token: 'ipu' | 'dtu'. */
    abstract protected function datasetToken(): string;

    protected function showsCourseSelector(): bool
    {
        return ! RankDataset::courseFixedToBtech($this->datasetToken());
    }

    public function mount(): void
    {
        $this->form->fill([
            'gender' => 'male',
            'category' => 'general',
            'sub_category' => 'gender_neutral',
            'region' => 'delhi',
            'user_rank' => null,
            'course_id' => $this->defaultCourseId(),
            'within_reach_only' => true,
        ]);
    }

    private function universityIds(): array
    {
        return University::whereIn('code', RankDataset::universityCodes($this->datasetToken()))->pluck('id')->all();
    }

    private function defaultCourseId(): ?int
    {
        $q = Course::whereIn('university_id', $this->universityIds());
        if (RankDataset::courseFixedToBtech($this->datasetToken())) {
            return $q->where('name', 'B.Tech')->value('id');
        }

        return $q->orderBy('name')->value('id');
    }

    /** Sub-category options, filtered by gender. */
    public static function subCategoryOptions(?string $gender): array
    {
        $all = [
            'gender_neutral' => 'Gender-Neutral',
            'girl' => 'Girl',
            'single_girl' => 'Single-Girl',
            'pwd' => 'PwD',
            'defense_cw' => 'Defense (CW)',
        ];
        if (mb_strtolower((string) $gender) === 'male') {
            unset($all['girl'], $all['single_girl']);
        }

        return $all;
    }

    public function form(Form $form): Form
    {
        $schema = [
            TextInput::make('user_rank')->label('JEE-Main Rank (CRL)')->numeric()->minValue(1)
                ->required()->placeholder('e.g. 45000'),
            Select::make('gender')->options(['male' => 'Male', 'female' => 'Female'])
                ->required()->reactive()
                ->afterStateUpdated(function ($state, callable $set, callable $get) {
                    if ($state === 'male' && in_array($get('sub_category'), ['girl', 'single_girl'], true)) {
                        $set('sub_category', 'gender_neutral');
                    }
                }),
            Select::make('category')->options([
                'general' => 'General', 'ews' => 'EWS', 'obc' => 'OBC', 'sc' => 'SC', 'st' => 'ST',
            ])->required(),
            Select::make('sub_category')
                ->options(fn (callable $get) => static::subCategoryOptions($get('gender')))
                ->required(),
            Select::make('region')->options(['delhi' => 'Delhi', 'outside_delhi' => 'Outside Delhi'])->required(),
        ];

        if ($this->showsCourseSelector()) {
            $schema[] = Select::make('course_id')->label('Course')
                ->options(Course::whereIn('university_id', $this->universityIds())->pluck('name', 'id'))
                ->required();
        }

        $schema[] = Toggle::make('within_reach_only')->label('Show only options within reach')->default(true);

        return $form->schema($schema)->columns(['default' => 1, 'md' => 3])->statePath('data');
    }

    public function getResultsProperty(): array
    {
        if (empty($this->data['user_rank'])) {
            return ['rows' => [], 'reach_count' => 0, 'submitted' => false];
        }

        $ctx = new PredictorContext(
            datasetToken: $this->datasetToken(),
            rank: (int) $this->data['user_rank'],
            region: $this->data['region'] ?? 'delhi',
            category: $this->data['category'] ?? 'general',
            subCategory: $this->data['sub_category'] ?? null,
            gender: $this->data['gender'] ?? null,
            courseId: isset($this->data['course_id']) ? (int) $this->data['course_id'] : null,
        );

        $result = app(DatasetCutoffPredictor::class)->predict($ctx);
        $rows = $result['rows'];
        if (! empty($this->data['within_reach_only'])) {
            $rows = array_values(array_filter($rows, fn ($r) => $r['chance'] !== 'UNLIKELY'));
        }

        return ['rows' => $rows, 'reach_count' => $result['reach_count'], 'submitted' => true];
    }

    public function submit(): void
    {
        $this->form->getState();
    }
}
```

- [ ] **Step 2: Commit**

```bash
git add app/Filament/Pages/Rank/AbstractRankPredict.php
git commit -m "feat(rank): AbstractRankPredict shared predictor page"
```

---

## Task 3: `DtuPredict` page + shared blade

**Files:**
- Create: `app/Filament/Pages/Rank/DtuPredict.php`
- Create: `resources/views/filament/pages/rank/rank-predict.blade.php`
- Test: `tests/Feature/Rank/DtuPredictPageTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Rank;

use App\Filament\Pages\Rank\DtuPredict;
use App\Models\Rank\Branch;
use App\Models\Rank\Course;
use App\Models\Rank\Cutoff;
use App\Models\Rank\Institute;
use App\Models\Rank\AdmissionProcess;
use App\Models\Rank\QualifyingExam;
use App\Models\Rank\University;
use App\Models\User;
use Database\Seeders\Rank\JacDelhiSeeder;
use Database\Seeders\Rank\RankRoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class DtuPredictPageTest extends TestCase
{
    use RefreshDatabase;

    protected $connectionsToTransact = ['ranks'];

    /** @test */
    public function dtu_predict_returns_rows_for_a_rank(): void
    {
        $this->seed(RankRoleSeeder::class);
        $this->seed(JacDelhiSeeder::class);
        $jac = University::where('code', 'JAC')->first();
        Cutoff::where('university_id', $jac->id)->forceDelete();

        $course = Course::where('university_id', $jac->id)->where('name', 'B.Tech')->first();
        $exam = QualifyingExam::where('code', 'JEE_MAIN')->first();
        $process = AdmissionProcess::where('code', 'JAC')->first();
        $inst = Institute::where('university_id', $jac->id)->where('name', 'DTU')->first();
        $branch = Branch::firstOrCreate(['course_id' => $course->id, 'name' => 'Computer Science and Engineering']);
        Cutoff::create([
            'university_id' => $jac->id, 'course_id' => $course->id, 'qualifying_exam_id' => $exam->id,
            'admission_process_id' => $process->id, 'year' => 2025, 'round' => '5',
            'institute_id' => $inst->id, 'branch_id' => $branch->id, 'shift' => null,
            'region' => 'delhi', 'category' => 'general', 'sub_category' => 'gender_neutral',
            'min_rank' => 0, 'max_rank' => 11352, 'source' => 'official',
        ]);

        $user = User::factory()->create();
        $user->assignRole('rank-dtu-predict');
        $this->actingAs($user);

        $result = Livewire::test(DtuPredict::class)
            ->set('data.user_rank', 11000)
            ->set('data.gender', 'male')
            ->set('data.category', 'general')
            ->set('data.sub_category', 'gender_neutral')
            ->set('data.region', 'delhi')
            ->instance()->getResultsProperty();

        $this->assertTrue($result['submitted']);
        $this->assertCount(1, $result['rows']);
        $this->assertSame('DTU', $result['rows'][0]['institute']);
        $this->assertSame('LIKELY', $result['rows'][0]['chance']);
    }

    /** @test */
    public function dtu_predict_denied_to_ipu_only_user(): void
    {
        $this->seed(RankRoleSeeder::class);
        $user = User::factory()->create();
        $user->assignRole('rank-ipu-predict');

        $this->assertFalse(DtuPredict::canAccess());
    }
}
```

NOTE: `canAccess()` reads `auth()->user()`; in the second test we don't `actingAs`, so assert via a logged-in IPU user instead — adjust: `$this->actingAs($user); $this->assertFalse(DtuPredict::canAccess());`. Use that form.

- [ ] **Step 2: Run → FAIL** — class not found.

- [ ] **Step 3: Create `app/Filament/Pages/Rank/DtuPredict.php`**

```php
<?php

namespace App\Filament\Pages\Rank;

class DtuPredict extends AbstractRankPredict
{
    protected static ?string $navigationIcon = 'heroicon-o-academic-cap';

    protected static ?string $navigationLabel = 'DTU — Predict';

    protected static ?string $title = 'DTU Rank Predictor';

    protected static ?string $slug = 'rank/dtu/predict';

    protected static ?int $navigationSort = 2;

    protected function datasetToken(): string
    {
        return 'dtu';
    }

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->canRankPredict('dtu');
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }
}
```

- [ ] **Step 4: Create the shared blade `resources/views/filament/pages/rank/rank-predict.blade.php`**

```blade
<x-filament-panels::page>
    <form wire:submit="submit">
        {{ $this->form }}
        <div class="mt-4">
            <x-filament::button type="submit">Predict</x-filament::button>
        </div>
    </form>

    @php($result = $this->results)
    @if ($result['submitted'])
        <div class="mt-6" id="rank-results">
            <div class="flex items-center justify-between mb-3">
                <p class="text-sm text-gray-600 dark:text-gray-400">
                    <span class="font-semibold text-gray-900 dark:text-gray-100">{{ $result['reach_count'] }}</span>
                    option(s) within reach
                </p>
                <x-filament::button color="gray" tag="button" onclick="window.print()" class="print:hidden">
                    Save as PDF / Print
                </x-filament::button>
            </div>

            @if (count($result['rows']) === 0)
                <p class="text-sm text-gray-500 py-6">No options for this selection. Untick “within reach” to see long-shots, or adjust filters.</p>
            @else
                <table class="w-full text-sm border-collapse">
                    <thead>
                        <tr class="text-left text-xs uppercase tracking-wide text-gray-500 border-b border-gray-200 dark:border-gray-700">
                            <th class="py-2 pr-3">Chance</th>
                            <th class="py-2 pr-3">Institute / Campus</th>
                            <th class="py-2 pr-3">Branch</th>
                            <th class="py-2 pr-3 text-right">Final CR</th>
                            <th class="py-2 pr-3 text-right">R1 CR</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($result['rows'] as $row)
                            @php($colors = [
                                'SAFE' => 'bg-green-100 text-green-800',
                                'LIKELY' => 'bg-green-100 text-green-800',
                                'BORDERLINE' => 'bg-yellow-100 text-yellow-800',
                                'STRETCH' => 'bg-orange-100 text-orange-800',
                                'UNLIKELY' => 'bg-red-100 text-red-800',
                            ])
                            <tr class="border-b border-gray-100 dark:border-gray-800">
                                <td class="py-2 pr-3">
                                    <span class="inline-block rounded-full px-2 py-0.5 text-xs font-semibold {{ $colors[$row['chance']] ?? '' }}">{{ $row['chance'] }}</span>
                                </td>
                                <td class="py-2 pr-3">{{ $row['institute'] }}@if($row['women_only']) <span title="Women-only institute">♀</span>@endif</td>
                                <td class="py-2 pr-3">{{ $row['branch'] }}</td>
                                <td class="py-2 pr-3 text-right tabular-nums">{{ number_format($row['final_cr']) }}</td>
                                <td class="py-2 pr-3 text-right tabular-nums">{{ number_format($row['r1_cr']) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
                <p class="mt-3 text-xs text-gray-500">CR = closing rank (last admitted JEE-Main All-India rank). Final CR is the prediction benchmark; lower = more competitive. ♀ = women-only institute.</p>
            @endif
        </div>
    @endif

    <style>
        @media print {
            .fi-sidebar, .fi-topbar, form, .print\:hidden { display: none !important; }
            #rank-results { margin: 0; }
        }
    </style>
</x-filament-panels::page>
```

- [ ] **Step 5: Run → PASS.** If the Livewire test hits the 128 MB cap, run via `php -d memory_limit=2G ./vendor/bin/phpunit --filter DtuPredictPageTest`.

- [ ] **Step 6: Commit**

```bash
git add app/Filament/Pages/Rank/DtuPredict.php resources/views/filament/pages/rank/rank-predict.blade.php tests/Feature/Rank/DtuPredictPageTest.php
git commit -m "feat(rank): DTU predictor page + shared results blade with chance chips + print"
```

---

## Task 4: `IpuPredict` page (course selector)

**Files:**
- Create: `app/Filament/Pages/Rank/IpuPredict.php`
- Test: `tests/Feature/Rank/IpuPredictPageTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Rank;

use App\Filament\Pages\Rank\IpuPredict;
use App\Models\User;
use Database\Seeders\Rank\RankRoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IpuPredictPageTest extends TestCase
{
    use RefreshDatabase;

    protected $connectionsToTransact = ['ranks'];

    /** @test */
    public function ipu_predict_access_follows_ipu_role(): void
    {
        $this->seed(RankRoleSeeder::class);

        $ipuUser = User::factory()->create();
        $ipuUser->assignRole('rank-ipu-predict');
        $this->actingAs($ipuUser);
        $this->assertTrue(IpuPredict::canAccess());

        $dtuUser = User::factory()->create();
        $dtuUser->assignRole('rank-dtu-predict');
        $this->actingAs($dtuUser);
        $this->assertFalse(IpuPredict::canAccess());
    }

    /** @test */
    public function ipu_predict_uses_ipu_dataset_token(): void
    {
        $this->assertSame('ipu', (new \ReflectionMethod(IpuPredict::class, 'datasetToken'))
            ->invoke(new IpuPredict));
    }
}
```

- [ ] **Step 2: Run → FAIL** — class not found.

- [ ] **Step 3: Create `app/Filament/Pages/Rank/IpuPredict.php`**

```php
<?php

namespace App\Filament\Pages\Rank;

class IpuPredict extends AbstractRankPredict
{
    protected static ?string $navigationIcon = 'heroicon-o-magnifying-glass';

    protected static ?string $navigationLabel = 'IPU — Predict';

    protected static ?string $title = 'IPU Rank Predictor';

    protected static ?string $slug = 'rank/ipu/predict';

    protected static ?int $navigationSort = 3;

    protected function datasetToken(): string
    {
        return 'ipu';
    }

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->canRankPredict('ipu');
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }
}
```

- [ ] **Step 4: Run → PASS** (`php artisan test --filter IpuPredictPageTest`).

- [ ] **Step 5: Commit**

```bash
git add app/Filament/Pages/Rank/IpuPredict.php tests/Feature/Rank/IpuPredictPageTest.php
git commit -m "feat(rank): IPU predictor page (multi-course, ipu-scoped access)"
```

---

## Task 5: Smoke-verify both pages render (controller-run, no new code)

**Files:** none (manual verification).

- [ ] **Step 1: Confirm both pages are registered + reachable**

Run:
```bash
php artisan route:list 2>/dev/null | grep -iE "rank/(ipu|dtu)/predict" || echo "pages auto-register via Filament discovery (no explicit routes)"
php -d memory_limit=2G ./vendor/bin/phpunit --filter "DtuPredictPageTest|IpuPredictPageTest|DatasetCutoffPredictorTest" --no-coverage
```
Expected: all three test classes green.

- [ ] **Step 2: Confirm no regressions in the rank suite**

Run: `php artisan test --filter Rank`
Expected: all green (no failures).

- [ ] **Step 3: (No commit — verification only.)**

---

## Self-Review Notes (author)

- **Spec coverage (this plan):** dataset-scoped predictor engine + chance scale (§6.2/§6.2a/§8) → Task 1; gender/girl-quota/women-only (§6.3) → Task 1; shared predictor UI with gender→category→sub→region, chance chips, NSUT campus (institute), within-reach toggle, print/PDF (§8) → Tasks 2-3; IPU course selector, DTU B.Tech-only (§8) → Tasks 2-4; per-dataset predict access (§3) → Tasks 3-4 `canAccess`.
- **Deferred to Plan 3:** role-gated `RankLanding` cards (§4) + `RankAccess`/`RankRegistry::cardsFor`, Filament resource query-scoping (§3), `GeminiCounsellor` parameterization (§6.4), `RankTrends` + CSV export (§9). Pages self-register in the Filament nav meanwhile, so the predictors are reachable without the landing redesign.
- **IPU/DTU separation:** the engine resolves `universityIds` from the single dataset token and filters every query by it; Task-1 test `scopes_strictly_to_the_dataset_university` asserts an IPU cutoff never appears in a DTU prediction. No code path merges datasets.
- **Type consistency:** `predict()` returns `{rows, reach_count}`; rows carry `institute, branch, women_only, final_round, final_cr, r1_cr, chance`. The page wraps it as `{rows, reach_count, submitted}` and the blade reads exactly those keys. `subCategoryOptions()`, `datasetToken()`, `showsCourseSelector()` consistent across Tasks 2-4.
- **Placeholder scan:** none.

## Plan 3 preview (not part of this plan)

`RankAccess` helper + role-gated `RankLanding` (IPU/DTU × Predict/Analyse cards via `RankRegistry::cardsFor`), dataset query-scoping on the Filament Rank resources, `GeminiCounsellor` prompt parameterization (dataset/course label), and a `RankTrends` analytics page (closing-rank movement across rounds/years) with CSV export — all gated by the `rank-*-analyse` roles.
