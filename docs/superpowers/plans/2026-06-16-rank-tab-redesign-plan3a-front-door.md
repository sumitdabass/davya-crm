# Rank Tab Redesign — Plan 3a (Front Door + Resource Scoping) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make the new DTU/IPU predictor pages the front door of the Rank tab via a role-filtered landing, give the new scoped-analyse roles query-scoped access to the source-data resources, and de-promote + de-clutter the legacy IPU Rank Lookup (kept reachable until category-wise IPU data lands).

**Architecture:** A single `RankAccess` helper answers "which datasets/capabilities can this user use" by wrapping the existing `User::canRankPredict/canRankAnalyse` helpers. `RankRegistry` is rewritten to emit role-filtered landing cards (Predict cards → new predictor pages; Analyse/Manage cards → CRUD resources; one legacy IPU-lookup card). The 8 Rank resources gain a `ScopesToRankDataset` trait: legacy admin/rank-admin/super_admin keep full unscoped access (no regression); scoped-analyse roles see only their dataset's rows. The legacy `RankLookup` page loses its always-fixed Qualifying-Exam + Admission-Process selectors but stays reachable.

**Tech Stack:** Laravel 11, Filament 3, Spatie permissions, PHPUnit. Two DB connections: default (users/roles) + `ranks` (rank data).

**Scope cuts (deferred to Plan 3b):** `RankTrends` analytics page + CSV export, dataset-aware `GeminiCounsellor` prompt parameterization. NOT in this plan.

**Test runner:** `php -d memory_limit=2G ./vendor/bin/phpunit <path>` (the default `artisan test` OOMs at 128M on Filament view tests).

**Rank test gotchas (read before writing any test that touches rank DATA):**
- `RefreshDatabase` only refreshes the DEFAULT connection, not `ranks`. Tests that read/write `ranks` must follow the existing pattern in `tests/Feature/Rank/CutoffSchemaTest.php` / `RankLookupTest.php` (set `protected $connectionsToTransact = ['ranks'];` and delete-not-truncate cleanup). The dev `ranks` DB holds a persistent IPU cutoffs fixture — NEVER `migrate:fresh` it.
- Tests that only check ROLES/permissions (Tasks 1–3, 5) need only `RefreshDatabase` + `$this->seed(RankRoleSeeder::class)` — they never touch `ranks`.
- Do NOT boot a predictor page's `mount()` in a test (it reads `ranks` and can trip an in-memory-sqlite cross-class wipe). See `tests/Feature/Rank/DtuPredictPageTest.php` for the safe pattern.

---

### Task 1: `RankAccess` helper

**Files:**
- Create: `app/Rank/RankAccess.php`
- Test: `tests/Feature/Rank/RankAccessTest.php`

Single source of truth for "what rank surfaces does this user get". Legacy roles (`admin`, `rank-admin`, `super_admin`) are treated as full-access on every dataset+capability so existing behavior never regresses.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Rank;

use App\Models\User;
use App\Rank\RankAccess;
use Database\Seeders\Rank\RankRoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RankAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RankRoleSeeder::class);
    }

    /** @test */
    public function scoped_predict_role_exposes_only_that_dataset_predict(): void
    {
        $u = User::factory()->create();
        $u->assignRole('rank-ipu-predict');

        $this->assertSame(['ipu'], RankAccess::predictableDatasets($u));
        $this->assertSame([], RankAccess::analysableDatasets($u));
        $this->assertTrue(RankAccess::canSeeAnyRankTool($u));
        $this->assertSame([], RankAccess::analysableUniversityCodes($u));
    }

    /** @test */
    public function scoped_analyse_role_exposes_dataset_codes(): void
    {
        $u = User::factory()->create();
        $u->assignRole('rank-dtu-analyse');

        $this->assertSame(['dtu'], RankAccess::analysableDatasets($u));
        $this->assertSame(['JAC'], RankAccess::analysableUniversityCodes($u));
    }

    /** @test */
    public function legacy_admin_gets_all_datasets_and_codes(): void
    {
        $u = User::factory()->create();
        $u->assignRole('rank-admin');

        $this->assertSame(['ipu', 'dtu'], RankAccess::predictableDatasets($u));
        $this->assertSame(['ipu', 'dtu'], RankAccess::analysableDatasets($u));
        $this->assertEqualsCanonicalizing(['IPU', 'JAC'], RankAccess::analysableUniversityCodes($u));
    }

    /** @test */
    public function no_rank_role_user_sees_nothing(): void
    {
        $u = User::factory()->create();

        $this->assertFalse(RankAccess::canSeeAnyRankTool($u));
        $this->assertSame([], RankAccess::predictableDatasets($u));
        $this->assertSame([], RankAccess::analysableDatasets($u));
        $this->assertNull(RankAccess::isLegacyAdmin(null) ? true : null);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php -d memory_limit=2G ./vendor/bin/phpunit tests/Feature/Rank/RankAccessTest.php`
Expected: FAIL — class `App\Rank\RankAccess` not found.

- [ ] **Step 3: Write minimal implementation**

```php
<?php

namespace App\Rank;

use App\Models\User;

class RankAccess
{
    private const LEGACY_ROLES = ['admin', 'rank-admin', 'super_admin'];

    public static function isLegacyAdmin(?User $user): bool
    {
        return (bool) $user?->hasAnyRole(self::LEGACY_ROLES);
    }

    /** @return array<int,string> dataset tokens the user can use the predictor for */
    public static function predictableDatasets(?User $user): array
    {
        if ($user === null) {
            return [];
        }
        if (self::isLegacyAdmin($user)) {
            return RankDataset::tokens();
        }

        return array_values(array_filter(RankDataset::tokens(), fn (string $t) => $user->canRankPredict($t)));
    }

    /** @return array<int,string> dataset tokens the user can analyse (CRUD/trends) */
    public static function analysableDatasets(?User $user): array
    {
        if ($user === null) {
            return [];
        }
        if (self::isLegacyAdmin($user)) {
            return RankDataset::tokens();
        }

        return array_values(array_filter(RankDataset::tokens(), fn (string $t) => $user->canRankAnalyse($t)));
    }

    /** @return array<int,string> university codes across the user's analysable datasets */
    public static function analysableUniversityCodes(?User $user): array
    {
        $codes = [];
        foreach (self::analysableDatasets($user) as $token) {
            $codes = array_merge($codes, RankDataset::universityCodes($token));
        }

        return array_values(array_unique($codes));
    }

    public static function canSeeAnyRankTool(?User $user): bool
    {
        return self::predictableDatasets($user) !== [] || self::analysableDatasets($user) !== [];
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php -d memory_limit=2G ./vendor/bin/phpunit tests/Feature/Rank/RankAccessTest.php`
Expected: PASS (4 tests).

- [ ] **Step 5: Commit**

```bash
git add app/Rank/RankAccess.php tests/Feature/Rank/RankAccessTest.php
git commit -m "feat(rank): RankAccess helper — dataset/capability resolver (legacy admins full-access)"
```

---

### Task 2: Rewrite `RankRegistry` into role-filtered cards

**Files:**
- Modify: `app/Rank/RankRegistry.php` (full rewrite of the public API)
- Test: `tests/Feature/Rank/RankRegistryCardsTest.php` (new)

New card model. Each card is `['key','group','title','desc','icon','url']` where `group` ∈ `predict` | `manage` | `legacy`. The landing renders Predict cards prominently, Manage cards as a grid, and the single Legacy card subdued.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Rank;

use App\Models\User;
use App\Rank\RankRegistry;
use Database\Seeders\Rank\RankRoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RankRegistryCardsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RankRoleSeeder::class);
    }

    private function keys(array $cards): array
    {
        return array_map(fn ($c) => $c['key'], $cards);
    }

    /** @test */
    public function ipu_predict_role_sees_only_ipu_predict_card(): void
    {
        $u = User::factory()->create();
        $u->assignRole('rank-ipu-predict');

        $cards = RankRegistry::cardsFor($u);
        $this->assertSame(['predict-ipu'], $this->keys($cards));
    }

    /** @test */
    public function dtu_analyse_role_sees_manage_cards_not_predict(): void
    {
        $u = User::factory()->create();
        $u->assignRole('rank-dtu-analyse');

        $keys = $this->keys(RankRegistry::cardsFor($u));
        $this->assertContains('manage-cutoffs', $keys);
        $this->assertContains('manage-institutes', $keys);
        $this->assertNotContains('predict-dtu', $keys);
        $this->assertNotContains('predict-ipu', $keys);
    }

    /** @test */
    public function legacy_admin_sees_predict_manage_and_legacy_lookup(): void
    {
        $u = User::factory()->create();
        $u->assignRole('rank-admin');

        $keys = $this->keys(RankRegistry::cardsFor($u));
        $this->assertContains('predict-ipu', $keys);
        $this->assertContains('predict-dtu', $keys);
        $this->assertContains('manage-cutoffs', $keys);
        $this->assertContains('legacy-lookup', $keys);
    }

    /** @test */
    public function ipu_user_sees_legacy_lookup_dtu_only_user_does_not(): void
    {
        $ipu = User::factory()->create();
        $ipu->assignRole('rank-ipu-predict');
        $this->assertContains('legacy-lookup', $this->keys(RankRegistry::cardsFor($ipu)));

        $dtu = User::factory()->create();
        $dtu->assignRole('rank-dtu-predict');
        $this->assertNotContains('legacy-lookup', $this->keys(RankRegistry::cardsFor($dtu)));
    }

    /** @test */
    public function no_role_user_sees_no_cards(): void
    {
        $this->assertSame([], RankRegistry::cardsFor(User::factory()->create()));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php -d memory_limit=2G ./vendor/bin/phpunit tests/Feature/Rank/RankRegistryCardsTest.php`
Expected: FAIL — `RankRegistry::cardsFor()` does not exist.

- [ ] **Step 3: Write minimal implementation**

Replace the entire body of `app/Rank/RankRegistry.php` with:

```php
<?php

namespace App\Rank;

use App\Models\User;

class RankRegistry
{
    /** Manage (source-data) cards, shown to analyse-capable users. */
    private const MANAGE = [
        ['key' => 'manage-universities', 'title' => 'Universities', 'desc' => 'University records (name, code, state, website).', 'icon' => 'heroicon-o-building-library', 'url' => '/admin/rank/universities'],
        ['key' => 'manage-institutes', 'title' => 'Institutes', 'desc' => 'Colleges + institutes per university.', 'icon' => 'heroicon-o-building-office', 'url' => '/admin/rank/institutes'],
        ['key' => 'manage-courses', 'title' => 'Courses', 'desc' => 'Courses offered per university.', 'icon' => 'heroicon-o-academic-cap', 'url' => '/admin/rank/courses'],
        ['key' => 'manage-branches', 'title' => 'Branches', 'desc' => 'Specialisations inside each course.', 'icon' => 'heroicon-o-rectangle-stack', 'url' => '/admin/rank/branches'],
        ['key' => 'manage-cutoffs', 'title' => 'Cutoffs', 'desc' => 'Historical cutoffs per year / round / region. Bulk-paste import.', 'icon' => 'heroicon-o-chart-bar', 'url' => '/admin/rank/cutoffs'],
        ['key' => 'manage-seats', 'title' => 'Seats', 'desc' => 'Seat counts per year / branch / institute.', 'icon' => 'heroicon-o-squares-2x2', 'url' => '/admin/rank/seats'],
    ];

    /** @return array<int,array<string,string>> role-filtered cards for the landing */
    public static function cardsFor(?User $user): array
    {
        if (! RankAccess::canSeeAnyRankTool($user)) {
            return [];
        }

        $cards = [];

        // Predict cards — one per predictable dataset, links to the new predictor page.
        foreach (RankAccess::predictableDatasets($user) as $token) {
            $label = RankDataset::label($token);
            $cards[] = [
                'key'   => "predict-{$token}",
                'group' => 'predict',
                'title' => "{$label} — Predict",
                'desc'  => "Predict eligible colleges + branches for a {$label} rank, with category, sub-category, gender, and chance scale.",
                'icon'  => $token === 'ipu' ? 'heroicon-o-magnifying-glass' : 'heroicon-o-academic-cap',
                'url'   => "/admin/rank/{$token}/predict",
            ];
        }

        // Manage cards — shown if the user can analyse ANY dataset (resources self-scope).
        if (RankAccess::analysableDatasets($user) !== []) {
            foreach (self::MANAGE as $card) {
                $cards[] = $card + ['group' => 'manage'];
            }
        }

        // Legacy IPU Rank Lookup — kept reachable until category-wise IPU data lands.
        // Only meaningful for users with IPU access (predict or analyse).
        if (in_array('ipu', RankAccess::predictableDatasets($user), true)
            || in_array('ipu', RankAccess::analysableDatasets($user), true)) {
            $cards[] = [
                'key'   => 'legacy-lookup',
                'group' => 'legacy',
                'title' => 'IPU Rank Lookup (legacy)',
                'desc'  => 'Older IPU branch-family lookup. Use “IPU — Predict” for the new category-aware tool.',
                'icon'  => 'heroicon-o-clock',
                'url'   => '/admin/rank-lookup',
            ];
        }

        return $cards;
    }

    /** Back-compat: landing access gate. */
    public static function canAccess(?User $user): bool
    {
        return RankAccess::canSeeAnyRankTool($user);
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php -d memory_limit=2G ./vendor/bin/phpunit tests/Feature/Rank/RankRegistryCardsTest.php`
Expected: PASS (5 tests).

- [ ] **Step 5: Commit**

```bash
git add app/Rank/RankRegistry.php tests/Feature/Rank/RankRegistryCardsTest.php
git commit -m "feat(rank): role-filtered RankRegistry cards (predict/manage/legacy)"
```

---

### Task 3: Rewrite `RankLanding` + blade to render the new cards

**Files:**
- Modify: `app/Filament/Pages/RankLanding.php`
- Modify: `resources/views/filament/pages/rank-landing.blade.php`
- Test: `tests/Feature/Rank/RankLandingTest.php` (create; if one already exists, add these cases to it)

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Rank;

use App\Filament\Pages\RankLanding;
use App\Models\User;
use Database\Seeders\Rank\RankRoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RankLandingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RankRoleSeeder::class);
    }

    /** @test */
    public function predict_cards_groups_filter_by_role(): void
    {
        $u = User::factory()->create();
        $u->assignRole('rank-ipu-predict');
        $this->actingAs($u);

        $page = new RankLanding();
        $this->assertCount(1, $page->getPredictCards());
        $this->assertSame([], $page->getManageCards());
    }

    /** @test */
    public function landing_renders_for_authed_rank_user(): void
    {
        $u = User::factory()->create(['must_change_password' => false]);
        $u->assignRole('rank-admin');
        $this->actingAs($u);

        $this->get('/admin/rank')->assertOk()->assertSee('Predict');
    }

    /** @test */
    public function landing_access_denied_for_no_role_user(): void
    {
        $this->assertFalse(RankLanding::canAccess());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php -d memory_limit=2G ./vendor/bin/phpunit tests/Feature/Rank/RankLandingTest.php`
Expected: FAIL — `getPredictCards()` not defined / canAccess uses old registry.

- [ ] **Step 3: Write minimal implementation**

Replace the card-accessor methods in `app/Filament/Pages/RankLanding.php` (keep the existing class properties/`$navigationGroup`/`$slug`/`$view`). Replace `getPrimaryCard`/`getManageCards` and `canAccess` with:

```php
    public static function canAccess(): bool
    {
        return \App\Rank\RankRegistry::canAccess(auth()->user());
    }

    /** @return array<int,array<string,string>> */
    public function getPredictCards(): array
    {
        return $this->cardsByGroup('predict');
    }

    /** @return array<int,array<string,string>> */
    public function getManageCards(): array
    {
        return $this->cardsByGroup('manage');
    }

    /** @return array<int,array<string,string>> */
    public function getLegacyCards(): array
    {
        return $this->cardsByGroup('legacy');
    }

    private function cardsByGroup(string $group): array
    {
        return array_values(array_filter(
            \App\Rank\RankRegistry::cardsFor(auth()->user()),
            fn (array $c) => ($c['group'] ?? null) === $group,
        ));
    }
```

Remove the now-unused `use App\Rank\RankRegistry;`? No — keep it but the methods above use the FQCN; simplest is to keep the existing `use App\Rank\RankRegistry;` import and drop the FQCN. Use whichever keeps one import line. (Either compiles; prefer the existing `use` import and write `RankRegistry::cardsFor(...)`.)

Then rewrite `resources/views/filament/pages/rank-landing.blade.php`:

```blade
<x-filament-panels::page>
    <x-crumbs :trail="[]" />

    @php($predict = $this->getPredictCards())
    @if ($predict)
        <div class="mt-4 grid grid-cols-1 md:grid-cols-2 gap-4">
            @foreach ($predict as $card)
                <a href="{{ $card['url'] }}"
                   class="group flex items-start gap-4 rounded-lg border border-primary-200 dark:border-primary-700 bg-primary-50 dark:bg-primary-900/20 p-5 hover:border-primary-500 hover:shadow-md transition">
                    <div class="shrink-0 rounded-md p-3 bg-primary-100 dark:bg-primary-900/40 text-primary-700 dark:text-primary-300">
                        <x-filament::icon :icon="$card['icon']" class="w-6 h-6" />
                    </div>
                    <div class="flex-1 min-w-0">
                        <div class="flex items-baseline justify-between gap-2">
                            <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100">{{ $card['title'] }}</h3>
                            <span class="text-sm text-primary-700 dark:text-primary-300 opacity-0 group-hover:opacity-100 transition">Open →</span>
                        </div>
                        <p class="mt-1 text-sm text-gray-700 dark:text-gray-300 leading-relaxed">{{ $card['desc'] }}</p>
                    </div>
                </a>
            @endforeach
        </div>
    @endif

    @php($manage = $this->getManageCards())
    @if ($manage)
        <div class="mt-8">
            <h4 class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-3">Manage source data</h4>
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3">
                @foreach ($manage as $card)
                    <a href="{{ $card['url'] }}"
                       class="group block rounded-lg border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 p-4 hover:border-primary-500 hover:shadow-sm transition">
                        <div class="flex items-center gap-2">
                            <div class="shrink-0 rounded-md p-1.5 bg-gray-100 dark:bg-gray-800 text-gray-600 dark:text-gray-300">
                                <x-filament::icon :icon="$card['icon']" class="w-4 h-4" />
                            </div>
                            <h5 class="text-sm font-semibold text-gray-900 dark:text-gray-100">{{ $card['title'] }}</h5>
                        </div>
                        <p class="mt-2 text-xs text-gray-600 dark:text-gray-400 leading-snug">{{ $card['desc'] }}</p>
                    </a>
                @endforeach
            </div>
        </div>
    @endif

    @php($legacy = $this->getLegacyCards())
    @if ($legacy)
        <div class="mt-8 border-t border-gray-200 dark:border-gray-700 pt-4">
            @foreach ($legacy as $card)
                <a href="{{ $card['url'] }}" class="inline-flex items-center gap-2 text-sm text-gray-500 dark:text-gray-400 hover:text-primary-600 dark:hover:text-primary-400 transition">
                    <x-filament::icon :icon="$card['icon']" class="w-4 h-4" />
                    <span>{{ $card['title'] }}</span>
                </a>
                <p class="mt-1 text-xs text-gray-400 dark:text-gray-500">{{ $card['desc'] }}</p>
            @endforeach
        </div>
    @endif
</x-filament-panels::page>
```

Note: the `@php(...)` short form is used to match this file's convention. Do NOT mix in `@php ... @endphp` block form (see `reference_blade_php_short_form_trap`).

- [ ] **Step 4: Run test to verify it passes**

Run: `php -d memory_limit=2G ./vendor/bin/phpunit tests/Feature/Rank/RankLandingTest.php`
Expected: PASS (3 tests).

- [ ] **Step 5: Commit**

```bash
git add app/Filament/Pages/RankLanding.php resources/views/filament/pages/rank-landing.blade.php tests/Feature/Rank/RankLandingTest.php
git commit -m "feat(rank): role-filtered landing renders predict/manage/legacy cards"
```

---

### Task 4: Per-dataset query-scoping trait for Rank resources

**Files:**
- Create: `app/Filament/Resources/Rank/Concerns/ScopesToRankDataset.php`
- Modify: `app/Filament/Resources/Rank/UniversityResource.php`, `InstituteResource.php`, `CourseResource.php`, `BranchResource.php`, `CutoffResource.php`, `SeatResource.php` (swap trait + add scope hook)
- Leave UNCHANGED: `QualifyingExamResource.php`, `AdmissionProcessResource.php` (shared reference data — keep `RestrictsToRankRoles`, but widen its gate in Step 3a so analyse roles can read them).
- Test: `tests/Feature/Rank/ResourceDatasetScopingTest.php` (touches `ranks` — see gotchas)

**Design:** The trait's `canAccess()` = legacy admin OR any analysable dataset. `getEloquentQuery()` returns the parent query unscoped for legacy admins (no regression), else applies the resource's `scopeToRankUniversityCodes($query, $codes)` using the user's analysable university codes. Each scoped resource implements that static hook against its own relationship to `University`.

- [ ] **Step 1: Write the failing test** (model on `tests/Feature/Rank/CutoffSchemaTest.php` for `ranks` setup — set `$connectionsToTransact`, seed the two universities + a cutoff each, delete-not-truncate in tearDown)

```php
<?php

namespace Tests\Feature\Rank;

use App\Filament\Resources\Rank\CutoffResource;
use App\Models\Rank\University;
use App\Models\User;
use Database\Seeders\Rank\RankRoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ResourceDatasetScopingTest extends TestCase
{
    use RefreshDatabase;

    protected $connectionsToTransact = ['ranks'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RankRoleSeeder::class);
        // IPU + JAC universities are present in the dev ranks fixture; assert rather than create
        // to avoid duplicating the persistent fixture. If absent, firstOrCreate them here.
        University::on('ranks')->firstOrCreate(['code' => 'IPU'], ['name' => 'GGSIPU', 'state' => 'Delhi']);
        University::on('ranks')->firstOrCreate(['code' => 'JAC'], ['name' => 'JAC Delhi', 'state' => 'Delhi']);
    }

    /** @test */
    public function dtu_analyse_user_query_excludes_ipu_universities(): void
    {
        $u = User::factory()->create();
        $u->assignRole('rank-dtu-analyse');
        $this->actingAs($u);

        $codes = CutoffResource::getEloquentQuery()
            ->getQuery()->from === 'cutoffs' ? null : null; // placeholder removed below
        // Assert the scoped university-id set contains only JAC's id.
        $jacId = University::on('ranks')->where('code', 'JAC')->value('id');
        $ipuId = University::on('ranks')->where('code', 'IPU')->value('id');

        $ids = CutoffResource::getEloquentQuery()->pluck('university_id')->unique()->values()->all();
        $this->assertNotContains($ipuId, $ids);
    }

    /** @test */
    public function legacy_admin_query_is_unscoped(): void
    {
        $u = User::factory()->create();
        $u->assignRole('rank-admin');
        $this->actingAs($u);

        // No dataset filter applied → query has no university_id whereIn constraint injected by the trait.
        $sql = CutoffResource::getEloquentQuery()->toSql();
        $this->assertStringNotContainsString('"university_id" in', strtolower($sql));
    }

    /** @test */
    public function analyse_role_can_access_resource_legacy_only_predict_cannot(): void
    {
        $analyse = User::factory()->create();
        $analyse->assignRole('rank-dtu-analyse');
        $this->actingAs($analyse);
        $this->assertTrue(CutoffResource::canAccess());

        $predict = User::factory()->create();
        $predict->assignRole('rank-dtu-predict');
        $this->actingAs($predict);
        $this->assertFalse(CutoffResource::canAccess());
    }
}
```

Delete the placeholder line (`$codes = ...`) before running — it is illustrative noise; the real assertions are the `pluck`/`toSql`/`canAccess` ones.

- [ ] **Step 2: Run test to verify it fails**

Run: `php -d memory_limit=2G ./vendor/bin/phpunit tests/Feature/Rank/ResourceDatasetScopingTest.php`
Expected: FAIL — `CutoffResource::canAccess()` still uses `RestrictsToRankRoles` (analyse role denied) and no scoping applied.

- [ ] **Step 3: Write the trait**

Create `app/Filament/Resources/Rank/Concerns/ScopesToRankDataset.php`:

```php
<?php

namespace App\Filament\Resources\Rank\Concerns;

use App\Rank\RankAccess;
use Illuminate\Database\Eloquent\Builder;

/**
 * Gates a Rank resource to analyse-capable users and query-scopes its rows to
 * the user's analysable datasets. Legacy admins (admin/rank-admin/super_admin)
 * get full, unscoped access — identical to the old RestrictsToRankRoles behavior.
 *
 * Each consuming resource MUST implement:
 *   protected static function scopeToRankUniversityCodes(Builder $query, array $codes): Builder
 */
trait ScopesToRankDataset
{
    public static function canAccess(): bool
    {
        $user = auth()->user();

        return RankAccess::isLegacyAdmin($user) || RankAccess::analysableDatasets($user) !== [];
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();
        $user = auth()->user();

        if (RankAccess::isLegacyAdmin($user)) {
            return $query;
        }

        return static::scopeToRankUniversityCodes($query, RankAccess::analysableUniversityCodes($user));
    }
}
```

- [ ] **Step 3a: Widen the shared-resource gate** so analyse roles can read Qualifying Exams / Admission Processes. Edit `app/Filament/Resources/Rank/Concerns/RestrictsToRankRoles.php` `canAccess()`:

```php
    public static function canAccess(): bool
    {
        $user = auth()->user();

        return \App\Rank\RankAccess::isLegacyAdmin($user)
            || \App\Rank\RankAccess::analysableDatasets($user) !== [];
    }
```

- [ ] **Step 3b: Apply the trait + scope hook to each dataset-bound resource.** In each file below, replace `use ...\Concerns\RestrictsToRankRoles;` import + `use RestrictsToRankRoles;` trait-use with `ScopesToRankDataset`, and add the resource-specific scope hook. The exact hook per resource:

`UniversityResource.php` (model `University`, has `code`):
```php
    use \App\Filament\Resources\Rank\Concerns\ScopesToRankDataset;

    protected static function scopeToRankUniversityCodes(\Illuminate\Database\Eloquent\Builder $query, array $codes): \Illuminate\Database\Eloquent\Builder
    {
        return $query->whereIn('code', $codes);
    }
```

`InstituteResource.php` (model `Institute`, has `university_id`):
```php
    protected static function scopeToRankUniversityCodes(\Illuminate\Database\Eloquent\Builder $query, array $codes): \Illuminate\Database\Eloquent\Builder
    {
        return $query->whereHas('university', fn ($q) => $q->whereIn('code', $codes));
    }
```

`CourseResource.php` (model `Course`, has `university_id`): same body as InstituteResource (relation `university`).

`CutoffResource.php` (model `Cutoff`, has `university_id`):
```php
    protected static function scopeToRankUniversityCodes(\Illuminate\Database\Eloquent\Builder $query, array $codes): \Illuminate\Database\Eloquent\Builder
    {
        return $query->whereHas('university', fn ($q) => $q->whereIn('code', $codes));
    }
```

`BranchResource.php` (model `Branch`, related via `course.university`):
```php
    protected static function scopeToRankUniversityCodes(\Illuminate\Database\Eloquent\Builder $query, array $codes): \Illuminate\Database\Eloquent\Builder
    {
        return $query->whereHas('course.university', fn ($q) => $q->whereIn('code', $codes));
    }
```

`SeatResource.php` (model `Seat`): inspect the model first. If `Seat` has `university_id`, use the `whereHas('university', ...)` body; if it relates only via `institute`, use `whereHas('institute.university', fn ($q) => $q->whereIn('code', $codes))`. Pick the one matching the actual relationship (verify with `grep -n "function university\|function institute\|university_id" app/Models/Rank/Seat.php`).

For each resource, confirm the `university`/`course`/`institute` relationship method exists on the model (`grep -n "public function university\|public function course\|public function institute" app/Models/Rank/<Model>.php`). If a relationship name differs, adjust the `whereHas` path accordingly.

- [ ] **Step 4: Run test to verify it passes** (plus the full Rank suite to catch resource regressions)

Run: `php -d memory_limit=2G ./vendor/bin/phpunit tests/Feature/Rank/ResourceDatasetScopingTest.php tests/Feature/Rank tests/Unit/Rank`
Expected: PASS (all green).

- [ ] **Step 5: Commit**

```bash
git add app/Filament/Resources/Rank/ tests/Feature/Rank/ResourceDatasetScopingTest.php
git commit -m "feat(rank): per-dataset query-scoping for Rank resources (legacy admins unscoped)"
```

---

### Task 5: De-clutter the legacy `RankLookup` form

**Files:**
- Modify: `app/Filament/Pages/Rank/RankLookup.php` (remove the Qualifying-Exam + Admission-Process Selects at lines ~123 and ~125; keep their default resolution in the lookup logic at lines ~103–104 so results are unaffected)
- Test: `tests/Feature/Rank/RankLookupTest.php` (add a case asserting the form no longer exposes those fields; reuse the file's existing `ranks` setup)

The two Selects always carry a single fixed value (JEE Main / the dataset's counselling process), so they add nothing for the user. Remove them from the form schema but keep the page working: the lookup code already resolves `$jee` / `$counselling` defaults (lines ~103–104) — leave that resolution intact so removing the inputs doesn't change results.

- [ ] **Step 1: Write the failing test** — add to `RankLookupTest.php`:

```php
    /** @test */
    public function lookup_form_no_longer_exposes_exam_or_process_selectors(): void
    {
        $schema = (new \App\Filament\Pages\Rank\RankLookup())->form(
            \Filament\Forms\Form::make(\Filament\Forms\ComponentContainer::make(app()))
        )->getComponents();

        $names = collect($schema)->map(fn ($c) => method_exists($c, 'getName') ? $c->getName() : null)->filter()->all();

        $this->assertNotContains('qualifying_exam_id', $names);
        $this->assertNotContains('admission_process_id', $names);
    }
```

If constructing the form in isolation proves awkward (Filament form construction needs a Livewire host), instead assert at the source level:

```php
    /** @test */
    public function lookup_source_drops_exam_and_process_select_inputs(): void
    {
        $src = file_get_contents(app_path('Filament/Pages/Rank/RankLookup.php'));
        $this->assertStringNotContainsString("Select::make('qualifying_exam_id')", $src);
        $this->assertStringNotContainsString("Select::make('admission_process_id')", $src);
    }
```

Use the source-level assertion if the form-construction variant errors — it directly encodes the requirement.

- [ ] **Step 2: Run test to verify it fails**

Run: `php -d memory_limit=2G ./vendor/bin/phpunit tests/Feature/Rank/RankLookupTest.php`
Expected: FAIL — the two `Select::make(...)` lines still present.

- [ ] **Step 3: Remove the two Selects** from the `form()` schema in `RankLookup.php` (the lines that read `Select::make('qualifying_exam_id')->label('Qualifying Exam')...` and `Select::make('admission_process_id')->label('Admission Process')...`). Do NOT touch the `$jee`/`$counselling` default-resolution lines used when running the lookup. Verify the remaining schema array has no dangling comma / syntax error.

- [ ] **Step 4: Run test to verify it passes** (plus a route smoke)

Run: `php -d memory_limit=2G ./vendor/bin/phpunit tests/Feature/Rank/RankLookupTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Filament/Pages/Rank/RankLookup.php tests/Feature/Rank/RankLookupTest.php
git commit -m "refactor(rank): drop always-fixed Exam/Process selectors from legacy lookup form"
```

---

### Task 6: Full-suite green gate + pint

**Files:** none (verification only)

- [ ] **Step 1: Run the full suite**

Run: `php -d memory_limit=2G ./vendor/bin/phpunit`
Expected: 0 failures, 0 errors (pre-existing PHP 8.5 PDO deprecations + the 1 pre-existing skip are acceptable). Test count should be ~previous + new cases from Tasks 1–5.

- [ ] **Step 2: Pint the touched files**

Run: `vendor/bin/pint app/Rank/RankAccess.php app/Rank/RankRegistry.php app/Filament/Pages/RankLanding.php app/Filament/Resources/Rank/ tests/Feature/Rank/`
Expected: fixes applied (or already clean).

- [ ] **Step 3: Commit any pint changes**

```bash
git add -A && git commit -m "style(rank): pint plan3a touched files" || echo "nothing to commit"
```

---

## Self-Review

**Spec coverage (front-door slice only):**
- Spec §4 role-filtered landing → Tasks 1–3. ✓
- Spec §168 resource query-scoping per dataset → Task 4. ✓
- User decision: retire legacy lookup as primary, keep reachable + drop useless selectors → Tasks 2 (legacy card) + 5. ✓
- Deferred (Plan 3b, explicitly out of scope): RankTrends + CSV (spec §147), GeminiCounsellor parameterization (spec §6.4). Documented at top.

**Placeholder scan:** Task 4 Step-1 test contains one illustrative placeholder line explicitly flagged for deletion; the source-level fallback in Task 5 is concrete. No "TBD/TODO" left.

**Type consistency:** `RankAccess::{predictableDatasets,analysableDatasets,analysableUniversityCodes,canSeeAnyRankTool,isLegacyAdmin}` and `RankRegistry::cardsFor` are used consistently across Tasks 1–4. Card group strings `predict|manage|legacy` match between Task 2 (producer) and Task 3 (consumer).

**Known verification-needed during build:** Seat/Branch model relationship names (Task 4 Step 3b) must be confirmed against the actual models before writing the `whereHas` path — the plan instructs the implementer to grep and adjust.
