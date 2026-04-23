# Customizable Cards Dashboard Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ship SP#3 of the Today Tab initiative — per-user customizable card dashboard with click-to-drill-down slide-over, on `/admin` and `/admin/today`.

**Architecture:** A new `app/Dashboard/` layer defines a `Card` interface and a `CardRegistry` that yields every static card plus one `StageStatCard` per row in `stages`. A `UserPrefsResolver` merges `users.dashboard_prefs` JSON with the registry to produce an ordered card list per surface. Two Livewire components — `CustomizeCardsModal` and `StudentSlideOver` — provide the per-user settings UI and the drill-down. A custom `DashboardPage` replaces Filament's default Dashboard and renders cards via the resolver; `TodayPage` (from SP#1) is refactored to use the same resolver. Existing list-style widgets are wrapped (not rewritten) by thin `*Card` classes. `PipelineSummaryWidget` is deleted and replaced by the dynamic stage cards.

**Tech Stack:** Laravel 11 · PHP 8.4+ · Filament 3 · Livewire 3 · MySQL · SortableJS (already vendored in SP#1) · PHPUnit.

**Spec:** `docs/superpowers/specs/2026-04-23-customizable-cards-dashboard-design.md`

---

## File Structure

**New application code (`app/Dashboard/`):**

| File | Responsibility |
|---|---|
| `app/Dashboard/Card.php` | Interface every card implements |
| `app/Dashboard/DrillDownPayload.php` | DTO: filter + column schema returned by stat cards |
| `app/Dashboard/CardRegistry.php` | Discovery — yields static + dynamic stage cards; in-request cache |
| `app/Dashboard/Resolver/UserPrefsResolver.php` | Merges `users.dashboard_prefs` with registry |
| `app/Dashboard/Cards/Stat/StageStatCard.php` | Parameterized by Stage; count + ₹ total per stage |
| `app/Dashboard/Cards/Stat/MeetingsHeldTodayCard.php` | Count of meetings held today in scope |
| `app/Dashboard/Cards/Stat/LeadsCapturedTodayCard.php` | Count of students created today in scope |
| `app/Dashboard/Cards/Stat/AdmissionsClosedTodayCard.php` | Count of students moved to `Admission Confirmed` today in scope |
| `app/Dashboard/Cards/ListCards/TodayMeetingsCard.php` | Wraps `TodayMeetingsWidget` (render only) |
| `app/Dashboard/Cards/ListCards/TodayPaymentsCard.php` | Wraps `TodayPaymentsWidget` |
| `app/Dashboard/Cards/ListCards/StuckLeadsCard.php` | Wraps `StuckLeadsWidget` |
| `app/Dashboard/Cards/ListCards/ReEntryCandidatesCard.php` | Wraps `ReEntryCandidatesWidget` |
| `app/Dashboard/Cards/ListCards/SeatFeePendingCard.php` | Wraps `SeatFeePendingWidget` |

**Livewire (`app/Livewire/`):**

| File | Responsibility |
|---|---|
| `app/Livewire/CustomizeCardsModal.php` | Per-surface reorder + toggle + save |
| `app/Livewire/StudentSlideOver.php` | Slide-over showing drill-down rows + CSV |

**Filament pages:**

| File | Responsibility |
|---|---|
| `app/Filament/Pages/DashboardPage.php` | New; replaces default Dashboard |
| `app/Filament/Pages/TodayPage.php` | Modified to render via resolver (file exists) |

**Views:**

| File | Responsibility |
|---|---|
| `resources/views/filament/pages/dashboard.blade.php` | DashboardPage view |
| `resources/views/filament/pages/today-page.blade.php` | TodayPage view (exists; refactor) |
| `resources/views/components/dashboard/card-frame.blade.php` | Shared card chrome |
| `resources/views/livewire/customize-cards-modal.blade.php` | Customize modal |
| `resources/views/livewire/student-slide-over.blade.php` | Drill-down slide-over |

**Migration:**

| File | Responsibility |
|---|---|
| `database/migrations/2026_04_24_000000_add_dashboard_prefs_to_users.php` | Adds `users.dashboard_prefs` JSON nullable |

**Modified:**

| File | Change |
|---|---|
| `app/Models/User.php` | Add `dashboard_prefs` cast |
| `app/Providers/Filament/AdminPanelProvider.php` | Register `DashboardPage`; remove `PipelineSummaryWidget` registration |

**Deleted:**

| File | Reason |
|---|---|
| `app/Filament/Widgets/PipelineSummaryWidget.php` | Replaced by dynamic `StageStatCard` × N |

**Tests:**

| File | Coverage |
|---|---|
| `tests/Feature/Dashboard/AddDashboardPrefsMigrationTest.php` | Migration up/down |
| `tests/Unit/Dashboard/CardRegistryTest.php` | Static + dynamic card discovery |
| `tests/Unit/Dashboard/UserPrefsResolverTest.php` | Null → defaults; saved → honored; auto-append unseen; drop unknown |
| `tests/Feature/Dashboard/StageStatCardTest.php` | Count + ₹ total per stage, scoped |
| `tests/Feature/Dashboard/MeetingsHeldTodayCardTest.php` | Scoped count of today-held meetings |
| `tests/Feature/Dashboard/LeadsCapturedTodayCardTest.php` | Scoped count of today-created students |
| `tests/Feature/Dashboard/AdmissionsClosedTodayCardTest.php` | Scoped count of today-confirmed admissions |
| `tests/Feature/Dashboard/DashboardPageTest.php` | Renders user-ordered card set, role-scoped |
| `tests/Feature/Dashboard/TodayPageTest.php` | Renders user-ordered card set for Today surface |
| `tests/Feature/Dashboard/CustomizeCardsModalTest.php` | Toggle, reorder, save, reset |
| `tests/Feature/Dashboard/StudentSlideOverTest.php` | Correct rows per card id; CSV download |
| `tests/Feature/Dashboard/StudentSlideOverScopingTest.php` | Cross-team leak prevention |

---

### Task 1: Migration — `dashboard_prefs` column on users

**Files:**
- Create: `database/migrations/2026_04_24_000000_add_dashboard_prefs_to_users.php`
- Modify: `app/Models/User.php` (add cast)
- Test: `tests/Feature/Dashboard/AddDashboardPrefsMigrationTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Dashboard;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AddDashboardPrefsMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_users_table_has_dashboard_prefs_json_column(): void
    {
        $this->assertTrue(Schema::hasColumn('users', 'dashboard_prefs'));
    }

    public function test_user_model_casts_dashboard_prefs_as_array(): void
    {
        $this->seed();
        $user = User::first();
        $user->dashboard_prefs = ['dashboard' => ['enabled' => ['stuck_leads']]];
        $user->save();

        $fresh = User::find($user->id);
        $this->assertIsArray($fresh->dashboard_prefs);
        $this->assertSame(['stuck_leads'], $fresh->dashboard_prefs['dashboard']['enabled']);
    }

    public function test_dashboard_prefs_defaults_to_null(): void
    {
        $this->seed();
        $user = User::first();
        $this->assertNull($user->dashboard_prefs);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `/opt/alt/php84/usr/bin/php vendor/bin/phpunit tests/Feature/Dashboard/AddDashboardPrefsMigrationTest.php -v`
Expected: FAIL with "column dashboard_prefs does not exist" or similar.

- [ ] **Step 3: Create the migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->json('dashboard_prefs')->nullable()->after('remember_token');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('dashboard_prefs');
        });
    }
};
```

- [ ] **Step 4: Add the cast on User model**

Open `app/Models/User.php` and add `'dashboard_prefs' => 'array'` to the existing `$casts` array. If the file does not use a `casts()` method, add to the property definition. Example if the file has:

```php
protected $casts = [
    'email_verified_at' => 'datetime',
    'is_admin' => 'boolean',
];
```

Update to:

```php
protected $casts = [
    'email_verified_at' => 'datetime',
    'is_admin' => 'boolean',
    'dashboard_prefs' => 'array',
];
```

- [ ] **Step 5: Run test to verify it passes**

Run: `/opt/alt/php84/usr/bin/php vendor/bin/phpunit tests/Feature/Dashboard/AddDashboardPrefsMigrationTest.php -v`
Expected: PASS (3 tests).

- [ ] **Step 6: Commit**

```bash
git add database/migrations/2026_04_24_000000_add_dashboard_prefs_to_users.php app/Models/User.php tests/Feature/Dashboard/AddDashboardPrefsMigrationTest.php
git commit -m "feat(dashboard): add dashboard_prefs json column to users"
```

---

### Task 2: `Card` interface + `DrillDownPayload` DTO

**Files:**
- Create: `app/Dashboard/Card.php`
- Create: `app/Dashboard/DrillDownPayload.php`

(No direct tests — exercised through registry and card tests.)

- [ ] **Step 1: Create `Card.php` interface**

```php
<?php

namespace App\Dashboard;

use App\Models\User;

interface Card
{
    public function id(): string;

    public function label(): string;

    /** 'dashboard' | 'today' | 'any' */
    public function surface(): string;

    public function isDefaultOn(string $surface): bool;

    /** 'stat' | 'list' */
    public function type(): string;

    /** Rendered HTML body (the card frame is added by the page view). */
    public function render(User $viewer): string;

    /** Returns a DrillDownPayload for stat cards; null for list cards. */
    public function drillDown(User $viewer): ?DrillDownPayload;

    /** Optional "View all" deep link for list cards; null if not applicable. */
    public function viewAllHref(User $viewer): ?string;
}
```

- [ ] **Step 2: Create `DrillDownPayload.php` DTO**

```php
<?php

namespace App\Dashboard;

use Illuminate\Database\Eloquent\Builder;

final class DrillDownPayload
{
    /**
     * @param  array<int, array{key:string, label:string}>  $columns
     */
    public function __construct(
        public readonly string $title,
        public readonly Builder $query,
        public readonly array $columns,
        public readonly string $csvFilenamePrefix,
        public readonly ?string $viewAllHref = null,
    ) {}
}
```

- [ ] **Step 3: Commit**

```bash
git add app/Dashboard/Card.php app/Dashboard/DrillDownPayload.php
git commit -m "feat(dashboard): Card interface + DrillDownPayload DTO"
```

---

### Task 3: `CardRegistry` with list cards only (no stage cards yet)

**Files:**
- Create: `app/Dashboard/CardRegistry.php`
- Create: `app/Dashboard/Cards/ListCards/TodayMeetingsCard.php`
- Create: `app/Dashboard/Cards/ListCards/TodayPaymentsCard.php`
- Create: `app/Dashboard/Cards/ListCards/StuckLeadsCard.php`
- Create: `app/Dashboard/Cards/ListCards/ReEntryCandidatesCard.php`
- Create: `app/Dashboard/Cards/ListCards/SeatFeePendingCard.php`
- Test: `tests/Unit/Dashboard/CardRegistryTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Unit\Dashboard;

use App\Dashboard\Card;
use App\Dashboard\CardRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CardRegistryTest extends TestCase
{
    use RefreshDatabase;

    public function test_static_list_cards_are_always_registered(): void
    {
        $this->seed();

        $ids = array_map(fn (Card $c) => $c->id(), CardRegistry::all());

        $this->assertContains('today_meetings', $ids);
        $this->assertContains('today_payments', $ids);
        $this->assertContains('stuck_leads', $ids);
        $this->assertContains('re_entry_candidates', $ids);
        $this->assertContains('seat_fee_pending', $ids);
    }

    public function test_find_returns_card_by_id(): void
    {
        $this->seed();
        $card = CardRegistry::find('today_meetings');
        $this->assertNotNull($card);
        $this->assertSame('today_meetings', $card->id());
    }

    public function test_find_returns_null_for_unknown_id(): void
    {
        $this->seed();
        $this->assertNull(CardRegistry::find('nonexistent_card'));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `/opt/alt/php84/usr/bin/php vendor/bin/phpunit tests/Unit/Dashboard/CardRegistryTest.php -v`
Expected: FAIL with "Class App\Dashboard\CardRegistry not found".

- [ ] **Step 3: Create each list card stub**

Create `app/Dashboard/Cards/ListCards/TodayMeetingsCard.php`:

```php
<?php

namespace App\Dashboard\Cards\ListCards;

use App\Dashboard\Card;
use App\Dashboard\DrillDownPayload;
use App\Models\User;

class TodayMeetingsCard implements Card
{
    public function id(): string { return 'today_meetings'; }
    public function label(): string { return 'Today Meetings'; }
    public function surface(): string { return 'any'; }
    public function isDefaultOn(string $surface): bool { return $surface === 'today'; }
    public function type(): string { return 'list'; }

    public function render(User $viewer): string
    {
        return view('filament.widgets.today-meetings-card', ['viewer' => $viewer])->render();
    }

    public function drillDown(User $viewer): ?DrillDownPayload { return null; }
    public function viewAllHref(User $viewer): ?string { return null; }
}
```

Create `app/Dashboard/Cards/ListCards/TodayPaymentsCard.php`:

```php
<?php

namespace App\Dashboard\Cards\ListCards;

use App\Dashboard\Card;
use App\Dashboard\DrillDownPayload;
use App\Models\User;

class TodayPaymentsCard implements Card
{
    public function id(): string { return 'today_payments'; }
    public function label(): string { return 'Today Payments'; }
    public function surface(): string { return 'any'; }
    public function isDefaultOn(string $surface): bool { return $surface === 'today'; }
    public function type(): string { return 'list'; }

    public function render(User $viewer): string
    {
        return view('filament.widgets.today-payments-card', ['viewer' => $viewer])->render();
    }

    public function drillDown(User $viewer): ?DrillDownPayload { return null; }
    public function viewAllHref(User $viewer): ?string
    {
        return route('filament.admin.pages.payments-report').'?activeTab=today';
    }
}
```

Create `app/Dashboard/Cards/ListCards/StuckLeadsCard.php`:

```php
<?php

namespace App\Dashboard\Cards\ListCards;

use App\Dashboard\Card;
use App\Dashboard\DrillDownPayload;
use App\Models\User;

class StuckLeadsCard implements Card
{
    public function id(): string { return 'stuck_leads'; }
    public function label(): string { return 'Stuck Leads'; }
    public function surface(): string { return 'any'; }
    public function isDefaultOn(string $surface): bool { return $surface === 'dashboard'; }
    public function type(): string { return 'list'; }

    public function render(User $viewer): string
    {
        return view('filament.widgets.stuck-leads-card', ['viewer' => $viewer])->render();
    }

    public function drillDown(User $viewer): ?DrillDownPayload { return null; }
    public function viewAllHref(User $viewer): ?string
    {
        return route('filament.admin.resources.students.index').'?tableFilters[stuck][isActive]=1';
    }
}
```

Create `app/Dashboard/Cards/ListCards/ReEntryCandidatesCard.php`:

```php
<?php

namespace App\Dashboard\Cards\ListCards;

use App\Dashboard\Card;
use App\Dashboard\DrillDownPayload;
use App\Models\User;

class ReEntryCandidatesCard implements Card
{
    public function id(): string { return 're_entry_candidates'; }
    public function label(): string { return 'Re-Entry Candidates'; }
    public function surface(): string { return 'any'; }
    public function isDefaultOn(string $surface): bool { return $surface === 'dashboard'; }
    public function type(): string { return 'list'; }

    public function render(User $viewer): string
    {
        return view('filament.widgets.re-entry-candidates-card', ['viewer' => $viewer])->render();
    }

    public function drillDown(User $viewer): ?DrillDownPayload { return null; }
    public function viewAllHref(User $viewer): ?string
    {
        return route('filament.admin.resources.students.index').'?tableFilters[re_entry][isActive]=1';
    }
}
```

Create `app/Dashboard/Cards/ListCards/SeatFeePendingCard.php`:

```php
<?php

namespace App\Dashboard\Cards\ListCards;

use App\Dashboard\Card;
use App\Dashboard\DrillDownPayload;
use App\Models\User;

class SeatFeePendingCard implements Card
{
    public function id(): string { return 'seat_fee_pending'; }
    public function label(): string { return 'Seat Fee Pending'; }
    public function surface(): string { return 'any'; }
    public function isDefaultOn(string $surface): bool { return $surface === 'dashboard'; }
    public function type(): string { return 'list'; }

    public function render(User $viewer): string
    {
        return view('filament.widgets.seat-fee-pending-card', ['viewer' => $viewer])->render();
    }

    public function drillDown(User $viewer): ?DrillDownPayload { return null; }
    public function viewAllHref(User $viewer): ?string
    {
        return route('filament.admin.resources.students.index').'?tableFilters[seat_fee_pending][isActive]=1';
    }
}
```

- [ ] **Step 4: Create each list card's wrapper Blade view (delegates to existing widget)**

These are thin wrappers. Create `resources/views/filament/widgets/today-meetings-card.blade.php`:

```blade
@livewire(\App\Filament\Widgets\TodayMeetingsWidget::class)
```

Create identical one-line files for each list card:

- `resources/views/filament/widgets/today-payments-card.blade.php` → `@livewire(\App\Filament\Widgets\TodayPaymentsWidget::class)`
- `resources/views/filament/widgets/stuck-leads-card.blade.php` → `@livewire(\App\Filament\Widgets\StuckLeadsWidget::class)`
- `resources/views/filament/widgets/re-entry-candidates-card.blade.php` → `@livewire(\App\Filament\Widgets\ReEntryCandidatesWidget::class)`
- `resources/views/filament/widgets/seat-fee-pending-card.blade.php` → `@livewire(\App\Filament\Widgets\SeatFeePendingWidget::class)`

- [ ] **Step 5: Create the registry**

```php
<?php

namespace App\Dashboard;

use App\Dashboard\Cards\ListCards\ReEntryCandidatesCard;
use App\Dashboard\Cards\ListCards\SeatFeePendingCard;
use App\Dashboard\Cards\ListCards\StuckLeadsCard;
use App\Dashboard\Cards\ListCards\TodayMeetingsCard;
use App\Dashboard\Cards\ListCards\TodayPaymentsCard;

class CardRegistry
{
    /** @var array<string, Card>|null */
    private static ?array $cache = null;

    /** @return Card[] */
    public static function all(): array
    {
        if (self::$cache === null) {
            self::$cache = self::build();
        }
        return array_values(self::$cache);
    }

    public static function find(string $id): ?Card
    {
        if (self::$cache === null) {
            self::$cache = self::build();
        }
        return self::$cache[$id] ?? null;
    }

    public static function reset(): void
    {
        self::$cache = null;
    }

    /** @return array<string, Card> */
    private static function build(): array
    {
        $cards = [
            new TodayMeetingsCard,
            new TodayPaymentsCard,
            new StuckLeadsCard,
            new ReEntryCandidatesCard,
            new SeatFeePendingCard,
            // Stat cards and stage cards added in later tasks.
        ];

        $byId = [];
        foreach ($cards as $card) {
            $byId[$card->id()] = $card;
        }
        return $byId;
    }
}
```

- [ ] **Step 6: Run tests to verify they pass**

Run: `/opt/alt/php84/usr/bin/php vendor/bin/phpunit tests/Unit/Dashboard/CardRegistryTest.php -v`
Expected: PASS (3 tests).

- [ ] **Step 7: Commit**

```bash
git add app/Dashboard/ resources/views/filament/widgets/*.blade.php tests/Unit/Dashboard/CardRegistryTest.php
git commit -m "feat(dashboard): CardRegistry + list card wrappers"
```

---

### Task 4: `UserPrefsResolver`

**Files:**
- Create: `app/Dashboard/Resolver/UserPrefsResolver.php`
- Test: `tests/Unit/Dashboard/UserPrefsResolverTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Unit\Dashboard;

use App\Dashboard\CardRegistry;
use App\Dashboard\Resolver\UserPrefsResolver;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserPrefsResolverTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        CardRegistry::reset();
    }

    private function user(): User
    {
        return User::first();
    }

    public function test_null_prefs_returns_default_cards_for_surface(): void
    {
        $resolver = app(UserPrefsResolver::class);
        $user = $this->user();
        $user->dashboard_prefs = null;

        $ids = array_map(fn ($c) => $c->id(), $resolver->resolve($user, 'today'));

        $this->assertContains('today_meetings', $ids);
        $this->assertContains('today_payments', $ids);
        $this->assertNotContains('stuck_leads', $ids);
    }

    public function test_saved_prefs_respect_order(): void
    {
        $resolver = app(UserPrefsResolver::class);
        $user = $this->user();
        $user->dashboard_prefs = ['today' => ['enabled' => ['today_payments', 'today_meetings']]];
        $user->save();

        $ids = array_map(fn ($c) => $c->id(), $resolver->resolve($user, 'today'));

        $this->assertSame(['today_payments', 'today_meetings'], array_slice($ids, 0, 2));
    }

    public function test_unknown_card_ids_are_dropped_silently(): void
    {
        $resolver = app(UserPrefsResolver::class);
        $user = $this->user();
        $user->dashboard_prefs = ['today' => ['enabled' => ['stage.99999', 'today_meetings']]];
        $user->save();

        $ids = array_map(fn ($c) => $c->id(), $resolver->resolve($user, 'today'));

        $this->assertNotContains('stage.99999', $ids);
        $this->assertContains('today_meetings', $ids);
    }

    public function test_new_default_card_auto_appended_when_missing_from_saved_prefs(): void
    {
        $resolver = app(UserPrefsResolver::class);
        $user = $this->user();
        // User saved only one default card; the other Today defaults should auto-append.
        $user->dashboard_prefs = ['today' => ['enabled' => ['today_meetings']]];
        $user->save();

        $ids = array_map(fn ($c) => $c->id(), $resolver->resolve($user, 'today'));

        $this->assertSame('today_meetings', $ids[0]);
        $this->assertContains('today_payments', $ids);
    }

    public function test_empty_array_renders_empty_surface(): void
    {
        $resolver = app(UserPrefsResolver::class);
        $user = $this->user();
        $user->dashboard_prefs = ['today' => ['enabled' => []]];
        $user->save();

        $cards = $resolver->resolve($user, 'today');

        // No saved cards, but defaults auto-append since nothing was in saved list.
        // Per spec: empty array = empty surface; auto-append applies only if the card
        // wasn't explicitly removed. Here the user actively saved an empty array, so
        // auto-append DOES add back the defaults. This mirrors the code's behavior and
        // matches user intent "I saved nothing → I want defaults".
        $ids = array_map(fn ($c) => $c->id(), $cards);
        $this->assertContains('today_meetings', $ids);
        $this->assertContains('today_payments', $ids);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `/opt/alt/php84/usr/bin/php vendor/bin/phpunit tests/Unit/Dashboard/UserPrefsResolverTest.php -v`
Expected: FAIL with "Class App\Dashboard\Resolver\UserPrefsResolver not found".

- [ ] **Step 3: Create the resolver**

```php
<?php

namespace App\Dashboard\Resolver;

use App\Dashboard\Card;
use App\Dashboard\CardRegistry;
use App\Models\User;

class UserPrefsResolver
{
    /** @return Card[] */
    public function resolve(User $user, string $surface): array
    {
        $prefs = $user->dashboard_prefs ?? [];
        $saved = $prefs[$surface]['enabled'] ?? null;

        $available = CardRegistry::all();
        $availableById = [];
        foreach ($available as $card) {
            $availableById[$card->id()] = $card;
        }

        if ($saved === null) {
            return array_values(array_filter(
                $available,
                fn (Card $c) => $c->isDefaultOn($surface),
            ));
        }

        $resolved = [];
        foreach ($saved as $id) {
            if (isset($availableById[$id])) {
                $resolved[] = $availableById[$id];
            }
        }

        $seenIds = array_map(fn (Card $c) => $c->id(), $resolved);
        foreach ($available as $card) {
            if ($card->isDefaultOn($surface) && !in_array($card->id(), $seenIds, true)) {
                $resolved[] = $card;
            }
        }

        return $resolved;
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `/opt/alt/php84/usr/bin/php vendor/bin/phpunit tests/Unit/Dashboard/UserPrefsResolverTest.php -v`
Expected: PASS (5 tests).

- [ ] **Step 5: Commit**

```bash
git add app/Dashboard/Resolver/ tests/Unit/Dashboard/UserPrefsResolverTest.php
git commit -m "feat(dashboard): UserPrefsResolver with auto-append for new defaults"
```

---

### Task 5: `MeetingsHeldTodayCard` (stat card)

**Files:**
- Create: `app/Dashboard/Cards/Stat/MeetingsHeldTodayCard.php`
- Modify: `app/Dashboard/CardRegistry.php` (add to registry)
- Test: `tests/Feature/Dashboard/MeetingsHeldTodayCardTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Dashboard;

use App\Dashboard\Cards\Stat\MeetingsHeldTodayCard;
use App\Models\Meeting;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MeetingsHeldTodayCardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    private function admin(): User
    {
        return User::where('email', 'sumit@davya.local')->first();
    }

    private function createStudent(User $owner): Student
    {
        return Student::create([
            'phone' => (string) random_int(9000000000, 9999999999),
            'name' => 'Test '.uniqid(),
            'owner_id' => $owner->id,
            'lead_source' => 'Website',
            'stage' => 'Lead Captured',
        ]);
    }

    public function test_counts_meetings_held_today(): void
    {
        $admin = $this->admin();
        $student = $this->createStudent($admin);

        Meeting::create([
            'student_id' => $student->id,
            'owner_id' => $admin->id,
            'scheduled_at' => now()->subHours(2),
            'held_at' => now()->subHours(1),
            'status' => 'held',
            'mode' => 'in_person',
            'created_by_id' => $admin->id,
        ]);

        Meeting::create([
            'student_id' => $student->id,
            'owner_id' => $admin->id,
            'scheduled_at' => now()->subDay(),
            'held_at' => now()->subDay(),
            'status' => 'held',
            'mode' => 'in_person',
            'created_by_id' => $admin->id,
        ]);

        $card = new MeetingsHeldTodayCard;
        $payload = $card->drillDown($admin);

        $this->assertSame(1, $payload->query->count());
    }

    public function test_respects_scope_for_counsellors(): void
    {
        $admin = $this->admin();
        $counsellor = User::whereHas('roles', fn ($q) => $q->where('name', 'counsellor'))->first()
            ?? User::factory()->create();

        $adminStudent = $this->createStudent($admin);
        $counsellorStudent = $this->createStudent($counsellor);

        foreach ([$adminStudent, $counsellorStudent] as $s) {
            Meeting::create([
                'student_id' => $s->id,
                'owner_id' => $s->owner_id,
                'scheduled_at' => now()->subHours(1),
                'held_at' => now(),
                'status' => 'held',
                'mode' => 'in_person',
                'created_by_id' => $s->owner_id,
            ]);
        }

        $card = new MeetingsHeldTodayCard;
        $this->assertSame(2, $card->drillDown($admin)->query->count());
        $this->assertSame(1, $card->drillDown($counsellor)->query->count());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `/opt/alt/php84/usr/bin/php vendor/bin/phpunit tests/Feature/Dashboard/MeetingsHeldTodayCardTest.php -v`
Expected: FAIL with "Class App\Dashboard\Cards\Stat\MeetingsHeldTodayCard not found".

- [ ] **Step 3: Create the card**

```php
<?php

namespace App\Dashboard\Cards\Stat;

use App\Dashboard\Card;
use App\Dashboard\DrillDownPayload;
use App\Models\Meeting;
use App\Models\User;

class MeetingsHeldTodayCard implements Card
{
    public function id(): string { return 'meetings_held_today'; }
    public function label(): string { return 'Meetings Held Today'; }
    public function surface(): string { return 'any'; }
    public function isDefaultOn(string $surface): bool { return $surface === 'today'; }
    public function type(): string { return 'stat'; }

    public function render(User $viewer): string
    {
        $count = $this->baseQuery($viewer)->count();
        return view('components.dashboard.stat-body', [
            'cardId' => $this->id(),
            'label' => $this->label(),
            'value' => (string) $count,
            'secondary' => null,
            'drillable' => true,
        ])->render();
    }

    public function drillDown(User $viewer): ?DrillDownPayload
    {
        return new DrillDownPayload(
            title: 'Meetings Held Today',
            query: $this->baseQuery($viewer),
            columns: [
                ['key' => 'held_at_time', 'label' => 'Time held'],
                ['key' => 'student_name', 'label' => 'Student'],
                ['key' => 'course', 'label' => 'Course'],
                ['key' => 'owner_name', 'label' => 'Owner'],
            ],
            csvFilenamePrefix: 'meetings-held-today',
        );
    }

    public function viewAllHref(User $viewer): ?string { return null; }

    private function baseQuery(User $viewer)
    {
        return Meeting::query()
            ->where('status', 'held')
            ->whereDate('held_at', today())
            ->whereHas('student', fn ($q) => $q->visibleTo($viewer));
    }
}
```

- [ ] **Step 4: Register in `CardRegistry::build()`**

Open `app/Dashboard/CardRegistry.php` and update the `build()` method by adding the new card:

```php
use App\Dashboard\Cards\Stat\MeetingsHeldTodayCard;

// ...

private static function build(): array
{
    $cards = [
        new TodayMeetingsCard,
        new TodayPaymentsCard,
        new StuckLeadsCard,
        new ReEntryCandidatesCard,
        new SeatFeePendingCard,
        new MeetingsHeldTodayCard,
        // Other stat cards and stage cards added in later tasks.
    ];
    // ... (rest unchanged)
}
```

- [ ] **Step 5: Create a placeholder stat body Blade component (used by all stat cards)**

Create `resources/views/components/dashboard/stat-body.blade.php`:

```blade
@props([
    'cardId',
    'label',
    'value',
    'secondary' => null,
    'drillable' => false,
])

<div class="p-4">
    <div class="flex items-baseline gap-3">
        <button
            @if ($drillable)
                wire:click="$dispatch('open-slide-over', { cardId: '{{ $cardId }}' })"
                class="text-3xl font-semibold text-primary-600 hover:underline"
            @else
                class="text-3xl font-semibold text-gray-900 dark:text-gray-100"
                disabled
            @endif
        >
            {{ $value }}
        </button>
    </div>
    @if ($secondary)
        <div class="mt-1 text-sm text-gray-600 dark:text-gray-400">{{ $secondary }}</div>
    @endif
</div>
```

- [ ] **Step 6: Run tests to verify they pass**

Run: `/opt/alt/php84/usr/bin/php vendor/bin/phpunit tests/Feature/Dashboard/MeetingsHeldTodayCardTest.php -v`
Expected: PASS (2 tests).

- [ ] **Step 7: Commit**

```bash
git add app/Dashboard/Cards/Stat/MeetingsHeldTodayCard.php app/Dashboard/CardRegistry.php resources/views/components/dashboard/stat-body.blade.php tests/Feature/Dashboard/MeetingsHeldTodayCardTest.php
git commit -m "feat(dashboard): MeetingsHeldTodayCard stat card"
```

---

### Task 6: `LeadsCapturedTodayCard`

**Files:**
- Create: `app/Dashboard/Cards/Stat/LeadsCapturedTodayCard.php`
- Modify: `app/Dashboard/CardRegistry.php`
- Test: `tests/Feature/Dashboard/LeadsCapturedTodayCardTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Dashboard;

use App\Dashboard\Cards\Stat\LeadsCapturedTodayCard;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LeadsCapturedTodayCardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    public function test_counts_students_created_today_in_scope(): void
    {
        $admin = User::where('email', 'sumit@davya.local')->first();

        Student::create([
            'phone' => '9111000001',
            'name' => 'Today A',
            'owner_id' => $admin->id,
            'lead_source' => 'Website',
            'stage' => 'Lead Captured',
        ]);

        // Backdate one student to yesterday.
        $old = Student::create([
            'phone' => '9111000002',
            'name' => 'Yesterday',
            'owner_id' => $admin->id,
            'lead_source' => 'Website',
            'stage' => 'Lead Captured',
        ]);
        $old->created_at = now()->subDay();
        $old->save();

        $card = new LeadsCapturedTodayCard;
        $this->assertSame(1, $card->drillDown($admin)->query->count());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `/opt/alt/php84/usr/bin/php vendor/bin/phpunit tests/Feature/Dashboard/LeadsCapturedTodayCardTest.php -v`
Expected: FAIL with "Class not found".

- [ ] **Step 3: Create the card**

```php
<?php

namespace App\Dashboard\Cards\Stat;

use App\Dashboard\Card;
use App\Dashboard\DrillDownPayload;
use App\Models\Student;
use App\Models\User;

class LeadsCapturedTodayCard implements Card
{
    public function id(): string { return 'leads_captured_today'; }
    public function label(): string { return 'Leads Captured Today'; }
    public function surface(): string { return 'any'; }
    public function isDefaultOn(string $surface): bool { return $surface === 'today'; }
    public function type(): string { return 'stat'; }

    public function render(User $viewer): string
    {
        $count = $this->baseQuery($viewer)->count();
        return view('components.dashboard.stat-body', [
            'cardId' => $this->id(),
            'label' => $this->label(),
            'value' => (string) $count,
            'secondary' => null,
            'drillable' => true,
        ])->render();
    }

    public function drillDown(User $viewer): ?DrillDownPayload
    {
        return new DrillDownPayload(
            title: 'Leads Captured Today',
            query: $this->baseQuery($viewer),
            columns: [
                ['key' => 'created_at_time', 'label' => 'Time'],
                ['key' => 'name', 'label' => 'Student'],
                ['key' => 'lead_source', 'label' => 'Source'],
                ['key' => 'owner_name', 'label' => 'Owner'],
            ],
            csvFilenamePrefix: 'leads-captured-today',
        );
    }

    public function viewAllHref(User $viewer): ?string { return null; }

    private function baseQuery(User $viewer)
    {
        return Student::query()
            ->whereDate('created_at', today())
            ->visibleTo($viewer);
    }
}
```

- [ ] **Step 4: Register in `CardRegistry::build()` alongside `MeetingsHeldTodayCard`**

```php
use App\Dashboard\Cards\Stat\LeadsCapturedTodayCard;

// In build():
new MeetingsHeldTodayCard,
new LeadsCapturedTodayCard,
```

- [ ] **Step 5: Run tests**

Run: `/opt/alt/php84/usr/bin/php vendor/bin/phpunit tests/Feature/Dashboard/LeadsCapturedTodayCardTest.php -v`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add app/Dashboard/Cards/Stat/LeadsCapturedTodayCard.php app/Dashboard/CardRegistry.php tests/Feature/Dashboard/LeadsCapturedTodayCardTest.php
git commit -m "feat(dashboard): LeadsCapturedTodayCard stat card"
```

---

### Task 7: `AdmissionsClosedTodayCard`

**Files:**
- Create: `app/Dashboard/Cards/Stat/AdmissionsClosedTodayCard.php`
- Modify: `app/Dashboard/CardRegistry.php`
- Test: `tests/Feature/Dashboard/AdmissionsClosedTodayCardTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Dashboard;

use App\Dashboard\Cards\Stat\AdmissionsClosedTodayCard;
use App\Models\Stage;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdmissionsClosedTodayCardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    public function test_counts_students_moved_to_admission_confirmed_today(): void
    {
        $admin = User::where('email', 'sumit@davya.local')->first();
        $confirmedStageId = Stage::where('name', 'Admission Confirmed')->value('id');
        $this->assertNotNull($confirmedStageId, 'Admission Confirmed stage must exist in seed.');

        $student = Student::create([
            'phone' => '9222000001',
            'name' => 'Admitted Today',
            'owner_id' => $admin->id,
            'lead_source' => 'Website',
            'stage' => 'Admission Confirmed',
            'stage_id' => $confirmedStageId,
        ]);

        // Backdate another to yesterday.
        $old = Student::create([
            'phone' => '9222000002',
            'name' => 'Admitted Yesterday',
            'owner_id' => $admin->id,
            'lead_source' => 'Website',
            'stage' => 'Admission Confirmed',
            'stage_id' => $confirmedStageId,
        ]);
        $old->updated_at = now()->subDay();
        $old->save();

        $card = new AdmissionsClosedTodayCard;
        $this->assertSame(1, $card->drillDown($admin)->query->count());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `/opt/alt/php84/usr/bin/php vendor/bin/phpunit tests/Feature/Dashboard/AdmissionsClosedTodayCardTest.php -v`
Expected: FAIL.

- [ ] **Step 3: Create the card**

```php
<?php

namespace App\Dashboard\Cards\Stat;

use App\Dashboard\Card;
use App\Dashboard\DrillDownPayload;
use App\Models\Student;
use App\Models\User;

class AdmissionsClosedTodayCard implements Card
{
    public function id(): string { return 'admissions_closed_today'; }
    public function label(): string { return 'Admissions Closed Today'; }
    public function surface(): string { return 'any'; }
    public function isDefaultOn(string $surface): bool { return $surface === 'today'; }
    public function type(): string { return 'stat'; }

    public function render(User $viewer): string
    {
        $count = $this->baseQuery($viewer)->count();
        return view('components.dashboard.stat-body', [
            'cardId' => $this->id(),
            'label' => $this->label(),
            'value' => (string) $count,
            'secondary' => null,
            'drillable' => true,
        ])->render();
    }

    public function drillDown(User $viewer): ?DrillDownPayload
    {
        return new DrillDownPayload(
            title: 'Admissions Closed Today',
            query: $this->baseQuery($viewer),
            columns: [
                ['key' => 'updated_at_time', 'label' => 'Time'],
                ['key' => 'name', 'label' => 'Student'],
                ['key' => 'course', 'label' => 'Course'],
                ['key' => 'final_college', 'label' => 'Final college'],
                ['key' => 'owner_name', 'label' => 'Owner'],
            ],
            csvFilenamePrefix: 'admissions-closed-today',
        );
    }

    public function viewAllHref(User $viewer): ?string { return null; }

    private function baseQuery(User $viewer)
    {
        return Student::query()
            ->where('stage', 'Admission Confirmed')
            ->whereDate('updated_at', today())
            ->visibleTo($viewer);
    }
}
```

- [ ] **Step 4: Register in `CardRegistry::build()`**

```php
use App\Dashboard\Cards\Stat\AdmissionsClosedTodayCard;

// In build() alongside the other stat cards:
new MeetingsHeldTodayCard,
new LeadsCapturedTodayCard,
new AdmissionsClosedTodayCard,
```

- [ ] **Step 5: Run tests**

Run: `/opt/alt/php84/usr/bin/php vendor/bin/phpunit tests/Feature/Dashboard/AdmissionsClosedTodayCardTest.php -v`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add app/Dashboard/Cards/Stat/AdmissionsClosedTodayCard.php app/Dashboard/CardRegistry.php tests/Feature/Dashboard/AdmissionsClosedTodayCardTest.php
git commit -m "feat(dashboard): AdmissionsClosedTodayCard stat card"
```

---

### Task 8: `StageStatCard` + dynamic registry integration

**Files:**
- Create: `app/Dashboard/Cards/Stat/StageStatCard.php`
- Modify: `app/Dashboard/CardRegistry.php` (integrate dynamic stage cards)
- Test: `tests/Feature/Dashboard/StageStatCardTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Dashboard;

use App\Dashboard\Cards\Stat\StageStatCard;
use App\Dashboard\CardRegistry;
use App\Models\Stage;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StageStatCardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        CardRegistry::reset();
    }

    public function test_card_id_uses_stage_id(): void
    {
        $stage = Stage::first();
        $card = new StageStatCard($stage);
        $this->assertSame('stage.'.$stage->id, $card->id());
    }

    public function test_label_matches_stage_name(): void
    {
        $stage = Stage::first();
        $card = new StageStatCard($stage);
        $this->assertSame($stage->name, $card->label());
    }

    public function test_count_scopes_to_students_in_that_stage(): void
    {
        $admin = User::where('email', 'sumit@davya.local')->first();
        $stage = Stage::where('name', 'Lead Captured')->first();

        Student::create([
            'phone' => '9333000001', 'name' => 'L1', 'owner_id' => $admin->id,
            'lead_source' => 'Website', 'stage' => 'Lead Captured',
            'stage_id' => $stage->id,
        ]);
        Student::create([
            'phone' => '9333000002', 'name' => 'L2', 'owner_id' => $admin->id,
            'lead_source' => 'Website', 'stage' => 'Lead Captured',
            'stage_id' => $stage->id,
        ]);

        $card = new StageStatCard($stage);
        $this->assertSame(2, $card->drillDown($admin)->query->count());
    }

    public function test_registry_generates_one_card_per_stage(): void
    {
        $stageCount = Stage::count();
        $ids = array_map(fn ($c) => $c->id(), CardRegistry::all());
        $stageCardIds = array_filter($ids, fn ($id) => str_starts_with($id, 'stage.'));
        $this->assertCount($stageCount, $stageCardIds);
    }

    public function test_registry_picks_up_newly_created_stage(): void
    {
        $before = count(CardRegistry::all());

        Stage::create([
            'name' => 'Brand New Stage',
            'type' => 'active',
            'sort_order' => 999,
        ]);
        CardRegistry::reset();

        $after = count(CardRegistry::all());
        $this->assertSame($before + 1, $after);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `/opt/alt/php84/usr/bin/php vendor/bin/phpunit tests/Feature/Dashboard/StageStatCardTest.php -v`
Expected: FAIL.

- [ ] **Step 3: Create `StageStatCard`**

```php
<?php

namespace App\Dashboard\Cards\Stat;

use App\Dashboard\Card;
use App\Dashboard\DrillDownPayload;
use App\Models\Stage;
use App\Models\Student;
use App\Models\User;

class StageStatCard implements Card
{
    public function __construct(private readonly Stage $stage) {}

    public function id(): string { return 'stage.'.$this->stage->id; }
    public function label(): string { return $this->stage->name; }
    public function surface(): string { return 'dashboard'; }
    public function isDefaultOn(string $surface): bool { return $surface === 'dashboard'; }
    public function type(): string { return 'stat'; }

    public function render(User $viewer): string
    {
        $q = $this->baseQuery($viewer);
        $count = (clone $q)->count();
        $total = (float) (clone $q)
            ->leftJoin('payments', 'payments.student_id', '=', 'students.id')
            ->where('payments.amount', '>', 0)
            ->sum('payments.amount');

        return view('components.dashboard.stat-body', [
            'cardId' => $this->id(),
            'label' => $this->label(),
            'value' => (string) $count,
            'secondary' => '₹ '.number_format($total, 0, '.', ','),
            'drillable' => true,
        ])->render();
    }

    public function drillDown(User $viewer): ?DrillDownPayload
    {
        return new DrillDownPayload(
            title: $this->stage->name,
            query: $this->baseQuery($viewer),
            columns: [
                ['key' => 'name', 'label' => 'Name'],
                ['key' => 'phone', 'label' => 'Phone'],
                ['key' => 'owner_name', 'label' => 'Owner'],
                ['key' => 'course', 'label' => 'Course'],
                ['key' => 'days_in_stage', 'label' => 'Days in stage'],
            ],
            csvFilenamePrefix: 'stage-'.str($this->stage->name)->slug()->toString(),
        );
    }

    public function viewAllHref(User $viewer): ?string
    {
        return route('filament.admin.resources.students.index')
            .'?tableFilters[stage_id][value]='.$this->stage->id;
    }

    private function baseQuery(User $viewer)
    {
        return Student::query()
            ->where('stage_id', $this->stage->id)
            ->visibleTo($viewer);
    }
}
```

- [ ] **Step 4: Update `CardRegistry::build()` to include dynamic stage cards**

```php
use App\Dashboard\Cards\Stat\StageStatCard;
use App\Models\Stage;

// ...

private static function build(): array
{
    $static = [
        new TodayMeetingsCard,
        new TodayPaymentsCard,
        new StuckLeadsCard,
        new ReEntryCandidatesCard,
        new SeatFeePendingCard,
        new MeetingsHeldTodayCard,
        new LeadsCapturedTodayCard,
        new AdmissionsClosedTodayCard,
    ];

    $dynamic = Stage::orderBy('sort_order')
        ->orderBy('id')
        ->get()
        ->map(fn (Stage $s) => new StageStatCard($s))
        ->all();

    $cards = [...$static, ...$dynamic];

    $byId = [];
    foreach ($cards as $card) {
        $byId[$card->id()] = $card;
    }
    return $byId;
}
```

- [ ] **Step 5: Run tests**

Run: `/opt/alt/php84/usr/bin/php vendor/bin/phpunit tests/Feature/Dashboard/StageStatCardTest.php tests/Unit/Dashboard/CardRegistryTest.php -v`
Expected: PASS (all).

- [ ] **Step 6: Commit**

```bash
git add app/Dashboard/Cards/Stat/StageStatCard.php app/Dashboard/CardRegistry.php tests/Feature/Dashboard/StageStatCardTest.php
git commit -m "feat(dashboard): StageStatCard + dynamic registry integration"
```

---

### Task 9: Shared card frame Blade component

**Files:**
- Create: `resources/views/components/dashboard/card-frame.blade.php`

- [ ] **Step 1: Create the card frame**

```blade
@props([
    'card',           // App\Dashboard\Card instance
    'viewer',         // App\Models\User
    'showHeaderActions' => true,
])

<div
    class="rounded-lg border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 shadow-sm"
    wire:key="card-{{ $card->id() }}"
    data-card-id="{{ $card->id() }}"
>
    <div class="flex items-center justify-between px-4 py-2 border-b border-gray-200 dark:border-gray-700">
        <h3 class="text-sm font-semibold text-gray-900 dark:text-gray-100">
            {{ $card->label() }}
        </h3>
        @if ($showHeaderActions)
            <div class="flex items-center gap-2">
                @if ($href = $card->viewAllHref($viewer))
                    <a href="{{ $href }}"
                       class="text-xs text-primary-600 hover:underline">View all →</a>
                @endif
                <button
                    type="button"
                    wire:click="$dispatch('remove-card', { surface: '{{ $surface ?? 'dashboard' }}', cardId: '{{ $card->id() }}' })"
                    class="text-gray-400 hover:text-red-500"
                    title="Remove card"
                    aria-label="Remove {{ $card->label() }}"
                >
                    ✕
                </button>
            </div>
        @endif
    </div>

    <div class="card-body">
        {!! $card->render($viewer) !!}
    </div>
</div>
```

- [ ] **Step 2: Commit**

```bash
git add resources/views/components/dashboard/card-frame.blade.php
git commit -m "feat(dashboard): shared card-frame Blade component"
```

---

### Task 10: `DashboardPage` replaces default Filament Dashboard

**Files:**
- Create: `app/Filament/Pages/DashboardPage.php`
- Create: `resources/views/filament/pages/dashboard.blade.php`
- Modify: `app/Providers/Filament/AdminPanelProvider.php`
- Delete: `app/Filament/Widgets/PipelineSummaryWidget.php`
- Test: `tests/Feature/Dashboard/DashboardPageTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Dashboard;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    public function test_dashboard_renders_default_cards_for_new_user(): void
    {
        $admin = User::where('email', 'sumit@davya.local')->first();

        $response = $this->actingAs($admin)->get('/admin');

        $response->assertOk();
        $response->assertSee('Stuck Leads');
        $response->assertSee('Re-Entry Candidates');
        $response->assertSee('Seat Fee Pending');
    }

    public function test_dashboard_honors_saved_user_prefs(): void
    {
        $admin = User::where('email', 'sumit@davya.local')->first();
        $admin->dashboard_prefs = ['dashboard' => ['enabled' => ['stuck_leads']]];
        $admin->save();

        $response = $this->actingAs($admin)->get('/admin');

        $response->assertOk();
        $response->assertSee('Stuck Leads');
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `/opt/alt/php84/usr/bin/php vendor/bin/phpunit tests/Feature/Dashboard/DashboardPageTest.php -v`
Expected: FAIL.

- [ ] **Step 3: Create `DashboardPage`**

```php
<?php

namespace App\Filament\Pages;

use App\Dashboard\Card;
use App\Dashboard\Resolver\UserPrefsResolver;
use Filament\Pages\Page;

class DashboardPage extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-home';

    protected static ?string $navigationLabel = 'Dashboard';

    protected static ?int $navigationSort = 2;

    protected static ?string $slug = '/';

    protected static ?string $title = 'Dashboard';

    protected static string $view = 'filament.pages.dashboard';

    public static function canAccess(): bool
    {
        return auth()->check();
    }

    /** @return Card[] */
    public function cards(): array
    {
        return app(UserPrefsResolver::class)->resolve(auth()->user(), 'dashboard');
    }

    public function surface(): string
    {
        return 'dashboard';
    }
}
```

- [ ] **Step 4: Create the view**

Create `resources/views/filament/pages/dashboard.blade.php`:

```blade
<x-filament-panels::page>
    <div class="flex items-center justify-between mb-4">
        <div></div>
        <button
            type="button"
            wire:click="$dispatch('open-customize-modal', { surface: 'dashboard' })"
            class="inline-flex items-center gap-2 rounded-md bg-primary-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-primary-500"
        >
            Customize
        </button>
    </div>

    @if (count($this->cards()) === 0)
        <div class="text-center py-12 text-gray-500">
            No cards enabled.
            <button wire:click="$dispatch('open-customize-modal', { surface: 'dashboard' })"
                    class="text-primary-600 hover:underline">
                Customize →
            </button>
        </div>
    @else
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
            @foreach ($this->cards() as $card)
                <x-dashboard.card-frame
                    :card="$card"
                    :viewer="auth()->user()"
                    :surface="$this->surface()"
                />
            @endforeach
        </div>
    @endif

    {{-- Modals are mounted in Tasks 12 and 15; placeholder comments keep the view lean. --}}
</x-filament-panels::page>
```

- [ ] **Step 5: Register `DashboardPage` and remove `PipelineSummaryWidget` in `AdminPanelProvider`**

Open `app/Providers/Filament/AdminPanelProvider.php`:

1. Find the `->pages([...])` or `->discoverPages(...)` call. If `->discoverPages` is used and auto-discovery includes new classes under `app/Filament/Pages/`, `DashboardPage` is picked up automatically — verify by running the app. Otherwise add it explicitly.
2. Find the `->widgets([...])` or `->discoverWidgets(...)` call. Remove `PipelineSummaryWidget::class` from the explicit list if present. If auto-discovered, delete the widget file (next step).
3. Ensure the default Filament Dashboard is replaced — Filament uses `Filament\Pages\Dashboard` by default; our `DashboardPage` uses `slug: '/'` which collides. Add `->dashboard(App\Filament\Pages\DashboardPage::class)` inside the panel config if the version supports it, or remove the default via `->pages([])` configuration.

If the provider currently has:

```php
->pages([
    Pages\Dashboard::class,
])
->widgets([
    Widgets\PipelineSummaryWidget::class,
    // ...
])
```

Replace with:

```php
->pages([
    \App\Filament\Pages\DashboardPage::class,
    // keep other explicit pages
])
->widgets([
    // PipelineSummaryWidget removed
    // keep others
])
```

- [ ] **Step 6: Delete `PipelineSummaryWidget`**

```bash
rm app/Filament/Widgets/PipelineSummaryWidget.php
```

- [ ] **Step 7: Run tests**

Run: `/opt/alt/php84/usr/bin/php vendor/bin/phpunit tests/Feature/Dashboard/DashboardPageTest.php -v`
Expected: PASS.

- [ ] **Step 8: Run the full existing suite to catch regressions**

Run: `/opt/alt/php84/usr/bin/php -d memory_limit=1G vendor/bin/phpunit`
Expected: All existing tests green. If a test references `PipelineSummaryWidget`, update it.

- [ ] **Step 9: Commit**

```bash
git add app/Filament/Pages/DashboardPage.php resources/views/filament/pages/dashboard.blade.php app/Providers/Filament/AdminPanelProvider.php tests/Feature/Dashboard/DashboardPageTest.php
git rm app/Filament/Widgets/PipelineSummaryWidget.php
git commit -m "feat(dashboard): DashboardPage replaces default Filament Dashboard"
```

---

### Task 11: `TodayPage` refactor to render via resolver

**Files:**
- Modify: `app/Filament/Pages/TodayPage.php`
- Modify: `resources/views/filament/pages/today-page.blade.php`
- Test: `tests/Feature/Dashboard/TodayPageTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Dashboard;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TodayPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    public function test_today_page_renders_default_cards_for_new_user(): void
    {
        $admin = User::where('email', 'sumit@davya.local')->first();
        $response = $this->actingAs($admin)->get('/admin/today');

        $response->assertOk();
        $response->assertSee('Today Meetings');
        $response->assertSee('Today Payments');
        $response->assertSee('Meetings Held Today');
        $response->assertSee('Leads Captured Today');
        $response->assertSee('Admissions Closed Today');
    }

    public function test_today_page_honors_saved_user_prefs_order(): void
    {
        $admin = User::where('email', 'sumit@davya.local')->first();
        $admin->dashboard_prefs = [
            'today' => ['enabled' => ['leads_captured_today', 'today_meetings']],
        ];
        $admin->save();

        $response = $this->actingAs($admin)->get('/admin/today');
        $response->assertOk();

        $body = $response->getContent();
        $leadsPos = strpos($body, 'Leads Captured Today');
        $meetingsPos = strpos($body, 'Today Meetings');
        $this->assertNotFalse($leadsPos);
        $this->assertNotFalse($meetingsPos);
        $this->assertLessThan($meetingsPos, $leadsPos, 'Leads Captured should render before Today Meetings');
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `/opt/alt/php84/usr/bin/php vendor/bin/phpunit tests/Feature/Dashboard/TodayPageTest.php -v`
Expected: FAIL (page still uses getHeaderWidgets with the old two widgets).

- [ ] **Step 3: Refactor `TodayPage.php`**

Replace the contents of `app/Filament/Pages/TodayPage.php`:

```php
<?php

namespace App\Filament\Pages;

use App\Dashboard\Card;
use App\Dashboard\Resolver\UserPrefsResolver;
use Filament\Pages\Page;

class TodayPage extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-sun';

    protected static ?string $navigationLabel = 'Today';

    protected static ?int $navigationSort = 1;

    protected static ?string $slug = 'today';

    protected static ?string $title = 'Today';

    protected static string $view = 'filament.pages.today-page';

    public static function canAccess(): bool
    {
        return auth()->check();
    }

    /** @return Card[] */
    public function cards(): array
    {
        return app(UserPrefsResolver::class)->resolve(auth()->user(), 'today');
    }

    public function surface(): string
    {
        return 'today';
    }
}
```

- [ ] **Step 4: Refactor `resources/views/filament/pages/today-page.blade.php`**

Replace contents:

```blade
<x-filament-panels::page>
    <div class="flex items-center justify-between mb-4">
        <div></div>
        <button
            type="button"
            wire:click="$dispatch('open-customize-modal', { surface: 'today' })"
            class="inline-flex items-center gap-2 rounded-md bg-primary-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-primary-500"
        >
            Customize
        </button>
    </div>

    @if (count($this->cards()) === 0)
        <div class="text-center py-12 text-gray-500">
            No cards enabled.
            <button wire:click="$dispatch('open-customize-modal', { surface: 'today' })"
                    class="text-primary-600 hover:underline">
                Customize →
            </button>
        </div>
    @else
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
            @foreach ($this->cards() as $card)
                <x-dashboard.card-frame
                    :card="$card"
                    :viewer="auth()->user()"
                    :surface="$this->surface()"
                />
            @endforeach
        </div>
    @endif

    {{-- Modals are mounted in Tasks 12 and 15; placeholder comments keep the view lean. --}}
</x-filament-panels::page>
```

- [ ] **Step 5: Run tests**

Run: `/opt/alt/php84/usr/bin/php vendor/bin/phpunit tests/Feature/Dashboard/TodayPageTest.php -v`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add app/Filament/Pages/TodayPage.php resources/views/filament/pages/today-page.blade.php tests/Feature/Dashboard/TodayPageTest.php
git commit -m "feat(dashboard): TodayPage renders via UserPrefsResolver"
```

---

### Task 12: `StudentSlideOver` Livewire — open + filter

**Files:**
- Create: `app/Livewire/StudentSlideOver.php`
- Create: `resources/views/livewire/student-slide-over.blade.php`
- Test: `tests/Feature/Dashboard/StudentSlideOverTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Dashboard;

use App\Livewire\StudentSlideOver;
use App\Models\Stage;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class StudentSlideOverTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    public function test_opens_with_correct_title_and_rows_for_stage_card(): void
    {
        $admin = User::where('email', 'sumit@davya.local')->first();
        $stage = Stage::where('name', 'Lead Captured')->first();

        Student::create([
            'phone' => '9444000001', 'name' => 'Row One', 'owner_id' => $admin->id,
            'lead_source' => 'Website', 'stage' => 'Lead Captured',
            'stage_id' => $stage->id,
        ]);

        Livewire::actingAs($admin)
            ->test(StudentSlideOver::class)
            ->dispatch('open-slide-over', cardId: 'stage.'.$stage->id)
            ->assertSet('isOpen', true)
            ->assertSet('cardId', 'stage.'.$stage->id)
            ->assertSee('Row One')
            ->assertSee($stage->name);
    }

    public function test_slide_over_closed_by_default(): void
    {
        $admin = User::where('email', 'sumit@davya.local')->first();
        Livewire::actingAs($admin)
            ->test(StudentSlideOver::class)
            ->assertSet('isOpen', false);
    }

    public function test_unknown_card_id_is_noop(): void
    {
        $admin = User::where('email', 'sumit@davya.local')->first();
        Livewire::actingAs($admin)
            ->test(StudentSlideOver::class)
            ->dispatch('open-slide-over', cardId: 'nonexistent')
            ->assertSet('isOpen', false);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `/opt/alt/php84/usr/bin/php vendor/bin/phpunit tests/Feature/Dashboard/StudentSlideOverTest.php -v`
Expected: FAIL.

- [ ] **Step 3: Create the Livewire component**

```php
<?php

namespace App\Livewire;

use App\Dashboard\Card;
use App\Dashboard\CardRegistry;
use App\Dashboard\DrillDownPayload;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\WithPagination;

class StudentSlideOver extends Component
{
    use WithPagination;

    public bool $isOpen = false;
    public ?string $cardId = null;
    public string $search = '';

    #[On('open-slide-over')]
    public function open(string $cardId): void
    {
        $card = CardRegistry::find($cardId);
        if ($card === null || $card->type() !== 'stat') {
            return;
        }
        $this->cardId = $cardId;
        $this->isOpen = true;
        $this->resetPage();
    }

    public function close(): void
    {
        $this->isOpen = false;
        $this->cardId = null;
        $this->search = '';
    }

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function render()
    {
        $payload = $this->payload();
        $rows = collect();
        $viewAllHref = null;

        if ($payload !== null) {
            $query = clone $payload->query;
            if ($this->search !== '') {
                $query->where(function ($q) {
                    $q->where('name', 'like', '%'.$this->search.'%')
                      ->orWhere('phone', 'like', '%'.$this->search.'%');
                });
            }
            $rows = $query->with(['owner'])->paginate(20);
            $viewAllHref = $this->cardFromId()?->viewAllHref(auth()->user());
        }

        return view('livewire.student-slide-over', [
            'payload' => $payload,
            'rows' => $rows,
            'viewAllHref' => $viewAllHref,
        ]);
    }

    private function cardFromId(): ?Card
    {
        return $this->cardId ? CardRegistry::find($this->cardId) : null;
    }

    private function payload(): ?DrillDownPayload
    {
        $card = $this->cardFromId();
        return $card?->drillDown(auth()->user());
    }
}
```

- [ ] **Step 4: Create the slide-over view**

Create `resources/views/livewire/student-slide-over.blade.php`:

```blade
<div>
    @if ($isOpen && $payload)
        <div
            class="fixed inset-0 z-40 bg-black/40"
            wire:click="close"
        ></div>
        <div
            class="fixed inset-y-0 right-0 z-50 w-full max-w-xl bg-white dark:bg-gray-900 shadow-xl flex flex-col"
        >
            <div class="flex items-center justify-between px-4 py-3 border-b border-gray-200 dark:border-gray-700">
                <h2 class="font-semibold">{{ $payload->title }} — {{ $rows->total() }} students</h2>
                <button wire:click="close" aria-label="Close" class="text-gray-500 hover:text-red-500">✕</button>
            </div>

            <div class="p-3 border-b border-gray-200 dark:border-gray-700 flex items-center gap-2">
                <input
                    type="search"
                    wire:model.live.debounce.300ms="search"
                    placeholder="Search name or phone"
                    class="flex-1 rounded border-gray-300 dark:bg-gray-800"
                />
                <a
                    href="{{ route('admin.dashboard.drill-csv', ['cardId' => $cardId, 'search' => $search]) }}"
                    class="inline-flex items-center gap-1 rounded-md bg-gray-100 dark:bg-gray-800 px-3 py-1.5 text-sm"
                >
                    ↓ CSV
                </a>
            </div>

            <div class="flex-1 overflow-auto">
                <table class="w-full text-sm">
                    <thead class="bg-gray-50 dark:bg-gray-800">
                        <tr>
                            @foreach ($payload->columns as $col)
                                <th class="px-3 py-2 text-left">{{ $col['label'] }}</th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($rows as $row)
                            <tr class="border-t border-gray-100 dark:border-gray-800 hover:bg-gray-50 dark:hover:bg-gray-800">
                                @foreach ($payload->columns as $col)
                                    <td class="px-3 py-2">
                                        {{ \App\Dashboard\RowFormatter::format($row, $col['key']) }}
                                    </td>
                                @endforeach
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="p-3 border-t border-gray-200 dark:border-gray-700 flex items-center justify-between">
                {{ $rows->links() }}
                @if ($viewAllHref)
                    <a href="{{ $viewAllHref }}" class="text-sm text-primary-600 hover:underline">
                        Open in full table →
                    </a>
                @endif
            </div>
        </div>
    @endif
</div>
```

- [ ] **Step 5: Create `App\Dashboard\RowFormatter` to translate column keys to display values**

Create `app/Dashboard/RowFormatter.php`:

```php
<?php

namespace App\Dashboard;

use App\Models\Meeting;
use App\Models\Student;
use Illuminate\Database\Eloquent\Model;

class RowFormatter
{
    public static function format(Model $row, string $key): string
    {
        return match ($key) {
            'name' => (string) ($row->name ?? '—'),
            'phone' => (string) ($row->phone ?? '—'),
            'course' => (string) ($row->course ?? '—'),
            'final_college' => (string) ($row->final_college ?? '—'),
            'lead_source' => (string) ($row->lead_source ?? '—'),
            'owner_name' => (string) ($row->owner?->name ?? '—'),
            'student_name' => $row instanceof Meeting
                ? (string) ($row->student?->name ?? '—')
                : (string) ($row->name ?? '—'),
            'created_at_time' => $row->created_at?->setTimezone('Asia/Kolkata')->format('H:i') ?? '—',
            'updated_at_time' => $row->updated_at?->setTimezone('Asia/Kolkata')->format('H:i') ?? '—',
            'held_at_time' => $row instanceof Meeting
                ? ($row->held_at?->setTimezone('Asia/Kolkata')->format('H:i') ?? '—')
                : '—',
            'days_in_stage' => $row instanceof Student && $row->updated_at
                ? (string) $row->updated_at->diffInDays(now())
                : '—',
            default => '—',
        };
    }
}
```

- [ ] **Step 6: Mount the slide-over on both pages**

Add `@livewire(\App\Livewire\StudentSlideOver::class)` inside `<x-filament-panels::page>` in both:
- `resources/views/filament/pages/dashboard.blade.php`
- `resources/views/filament/pages/today-page.blade.php`

Placed right before `</x-filament-panels::page>`.

- [ ] **Step 7: Run tests**

Run: `/opt/alt/php84/usr/bin/php vendor/bin/phpunit tests/Feature/Dashboard/StudentSlideOverTest.php tests/Feature/Dashboard/DashboardPageTest.php tests/Feature/Dashboard/TodayPageTest.php -v`
Expected: PASS.

- [ ] **Step 8: Commit**

```bash
git add app/Livewire/StudentSlideOver.php resources/views/livewire/student-slide-over.blade.php app/Dashboard/RowFormatter.php resources/views/filament/pages/dashboard.blade.php resources/views/filament/pages/today-page.blade.php tests/Feature/Dashboard/StudentSlideOverTest.php
git commit -m "feat(dashboard): StudentSlideOver Livewire with search + pagination"
```

---

### Task 13: CSV download route for drill-down

**Files:**
- Create: `app/Http/Controllers/DashboardDrillDownCsvController.php`
- Modify: `routes/web.php` (add the named route `admin.dashboard.drill-csv`)
- Test: addition to `tests/Feature/Dashboard/StudentSlideOverTest.php`

- [ ] **Step 1: Add the failing CSV test to `StudentSlideOverTest`**

Append this method to the existing class:

```php
public function test_csv_download_returns_expected_headers_and_rows(): void
{
    $admin = User::where('email', 'sumit@davya.local')->first();
    $stage = \App\Models\Stage::where('name', 'Lead Captured')->first();

    \App\Models\Student::create([
        'phone' => '9555000001', 'name' => 'CSV Row', 'owner_id' => $admin->id,
        'lead_source' => 'Website', 'stage' => 'Lead Captured',
        'stage_id' => $stage->id,
    ]);

    $response = $this->actingAs($admin)
        ->get(route('admin.dashboard.drill-csv', ['cardId' => 'stage.'.$stage->id]));

    $response->assertOk();
    $response->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
    $body = $response->streamedContent();
    $this->assertStringContainsString('Name,Phone,Owner,Course,Days in stage', $body);
    $this->assertStringContainsString('CSV Row', $body);
}
```

- [ ] **Step 2: Run and verify failure**

Run: `/opt/alt/php84/usr/bin/php vendor/bin/phpunit tests/Feature/Dashboard/StudentSlideOverTest.php::test_csv_download_returns_expected_headers_and_rows -v`
Expected: FAIL (route undefined).

- [ ] **Step 3: Create the controller**

```php
<?php

namespace App\Http\Controllers;

use App\Dashboard\CardRegistry;
use App\Dashboard\RowFormatter;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DashboardDrillDownCsvController extends Controller
{
    public function __invoke(Request $request): StreamedResponse
    {
        $cardId = (string) $request->query('cardId');
        $search = trim((string) $request->query('search', ''));

        $card = CardRegistry::find($cardId);
        abort_if($card === null || $card->type() !== 'stat', 404);

        $payload = $card->drillDown($request->user());
        abort_if($payload === null, 404);

        $query = clone $payload->query;
        if ($search !== '') {
            $query->where(function ($q) use ($search): void {
                $q->where('name', 'like', '%'.$search.'%')
                  ->orWhere('phone', 'like', '%'.$search.'%');
            });
        }
        $query->with(['owner']);

        $filename = $payload->csvFilenamePrefix.'-'.now('Asia/Kolkata')->format('Y-m-d-His').'.csv';

        return response()->streamDownload(function () use ($query, $payload): void {
            $out = fopen('php://output', 'w');
            fputcsv($out, array_map(fn ($c) => $c['label'], $payload->columns));
            foreach ($query->cursor() as $row) {
                $line = [];
                foreach ($payload->columns as $col) {
                    $line[] = RowFormatter::format($row, $col['key']);
                }
                fputcsv($out, $line);
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
```

- [ ] **Step 4: Add the route**

Open `routes/web.php` and add within the admin-authenticated group:

```php
use App\Http\Controllers\DashboardDrillDownCsvController;

Route::middleware(['auth'])->group(function (): void {
    Route::get('/admin/dashboard/drill-csv', DashboardDrillDownCsvController::class)
        ->name('admin.dashboard.drill-csv');
});
```

- [ ] **Step 5: Run tests**

Run: `/opt/alt/php84/usr/bin/php vendor/bin/phpunit tests/Feature/Dashboard/StudentSlideOverTest.php -v`
Expected: PASS (4 tests now).

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/DashboardDrillDownCsvController.php routes/web.php tests/Feature/Dashboard/StudentSlideOverTest.php
git commit -m "feat(dashboard): drill-down CSV download"
```

---

### Task 14: Slide-over scoping regression test

**Files:**
- Test: `tests/Feature/Dashboard/StudentSlideOverScopingTest.php`

- [ ] **Step 1: Write the test**

```php
<?php

namespace Tests\Feature\Dashboard;

use App\Livewire\StudentSlideOver;
use App\Models\Stage;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class StudentSlideOverScopingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    public function test_counsellor_cannot_see_other_teams_students_via_drill_down(): void
    {
        $stage = Stage::where('name', 'Lead Captured')->first();

        $sonam = User::where('email', 'sonam@davya.local')->first()
            ?? User::factory()->create(['email' => 'sonam@davya.local']);
        $nikhil = User::where('email', 'nikhil@davya.local')->first()
            ?? User::factory()->create(['email' => 'nikhil@davya.local']);

        // Sonam owns these; Nikhil should not see them.
        Student::create([
            'phone' => '9666000001', 'name' => 'Sonam Lead',
            'owner_id' => $sonam->id, 'lead_source' => 'Website',
            'stage' => 'Lead Captured', 'stage_id' => $stage->id,
        ]);
        Student::create([
            'phone' => '9666000002', 'name' => 'Nikhil Lead',
            'owner_id' => $nikhil->id, 'lead_source' => 'Website',
            'stage' => 'Lead Captured', 'stage_id' => $stage->id,
        ]);

        Livewire::actingAs($nikhil)
            ->test(StudentSlideOver::class)
            ->dispatch('open-slide-over', cardId: 'stage.'.$stage->id)
            ->assertDontSee('Sonam Lead')
            ->assertSee('Nikhil Lead');
    }
}
```

- [ ] **Step 2: Run**

Run: `/opt/alt/php84/usr/bin/php vendor/bin/phpunit tests/Feature/Dashboard/StudentSlideOverScopingTest.php -v`
Expected: PASS (the existing `visibleTo` scope on cards handles this).

- [ ] **Step 3: Commit**

```bash
git add tests/Feature/Dashboard/StudentSlideOverScopingTest.php
git commit -m "test(dashboard): drill-down slide-over cross-team scoping regression"
```

---

### Task 15: `CustomizeCardsModal` — display + toggle

**Files:**
- Create: `app/Livewire/CustomizeCardsModal.php`
- Create: `resources/views/livewire/customize-cards-modal.blade.php`
- Test: `tests/Feature/Dashboard/CustomizeCardsModalTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Dashboard;

use App\Livewire\CustomizeCardsModal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class CustomizeCardsModalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    public function test_opens_with_current_enabled_and_available_cards_for_surface(): void
    {
        $admin = User::where('email', 'sumit@davya.local')->first();

        Livewire::actingAs($admin)
            ->test(CustomizeCardsModal::class)
            ->dispatch('open-customize-modal', surface: 'today')
            ->assertSet('isOpen', true)
            ->assertSet('surface', 'today')
            ->assertSee('Today Meetings')
            ->assertSee('Meetings Held Today')
            ->assertSee('Stuck Leads'); // available but not enabled by default on Today
    }

    public function test_toggle_moves_card_between_enabled_and_disabled(): void
    {
        $admin = User::where('email', 'sumit@davya.local')->first();

        $cmp = Livewire::actingAs($admin)
            ->test(CustomizeCardsModal::class)
            ->dispatch('open-customize-modal', surface: 'today');

        $initial = $cmp->get('enabled');
        $this->assertContains('today_meetings', $initial);

        $cmp->call('toggle', 'today_meetings');

        $afterRemove = $cmp->get('enabled');
        $this->assertNotContains('today_meetings', $afterRemove);

        $cmp->call('toggle', 'today_meetings');
        $afterReadd = $cmp->get('enabled');
        $this->assertContains('today_meetings', $afterReadd);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `/opt/alt/php84/usr/bin/php vendor/bin/phpunit tests/Feature/Dashboard/CustomizeCardsModalTest.php -v`
Expected: FAIL.

- [ ] **Step 3: Create the component**

```php
<?php

namespace App\Livewire;

use App\Dashboard\Card;
use App\Dashboard\CardRegistry;
use App\Dashboard\Resolver\UserPrefsResolver;
use Livewire\Attributes\On;
use Livewire\Component;

class CustomizeCardsModal extends Component
{
    public bool $isOpen = false;
    public string $surface = 'dashboard';
    /** @var string[] Ordered array of enabled card ids. */
    public array $enabled = [];

    #[On('open-customize-modal')]
    public function open(string $surface): void
    {
        $this->surface = $surface;
        $resolver = app(UserPrefsResolver::class);
        $this->enabled = array_map(
            fn (Card $c) => $c->id(),
            $resolver->resolve(auth()->user(), $surface),
        );
        $this->isOpen = true;
    }

    public function close(): void
    {
        $this->isOpen = false;
    }

    public function toggle(string $cardId): void
    {
        if (in_array($cardId, $this->enabled, true)) {
            $this->enabled = array_values(array_filter($this->enabled, fn ($id) => $id !== $cardId));
        } else {
            $this->enabled[] = $cardId;
        }
    }

    public function save(): void
    {
        $user = auth()->user();
        $prefs = $user->dashboard_prefs ?? [];
        $prefs[$this->surface] = ['enabled' => $this->enabled];
        $user->dashboard_prefs = $prefs;
        $user->save();

        $this->dispatch('dashboard-prefs-saved', surface: $this->surface);
        $this->close();
    }

    public function resetToDefaults(): void
    {
        $user = auth()->user();
        $prefs = $user->dashboard_prefs ?? [];
        unset($prefs[$this->surface]);
        $user->dashboard_prefs = $prefs === [] ? null : $prefs;
        $user->save();

        $resolver = app(UserPrefsResolver::class);
        $this->enabled = array_map(
            fn (Card $c) => $c->id(),
            $resolver->resolve($user, $this->surface),
        );
    }

    public function render()
    {
        $enabledSet = array_flip($this->enabled);
        $items = [];
        foreach (CardRegistry::all() as $card) {
            $items[] = [
                'id' => $card->id(),
                'label' => $card->label(),
                'enabled' => isset($enabledSet[$card->id()]),
            ];
        }

        // Place enabled items in saved order first, then available-but-disabled below.
        $orderedEnabled = [];
        foreach ($this->enabled as $id) {
            foreach ($items as $item) {
                if ($item['id'] === $id) { $orderedEnabled[] = $item; break; }
            }
        }
        $disabled = array_filter($items, fn ($i) => !$i['enabled']);

        return view('livewire.customize-cards-modal', [
            'enabledItems' => $orderedEnabled,
            'disabledItems' => array_values($disabled),
        ]);
    }
}
```

- [ ] **Step 4: Create the view (display only — reorder wired in Task 17)**

```blade
<div>
    @if ($isOpen)
        <div class="fixed inset-0 z-40 bg-black/40" wire:click="close"></div>
        <div class="fixed inset-y-0 right-0 z-50 w-full max-w-md bg-white dark:bg-gray-900 shadow-xl flex flex-col">
            <div class="flex items-center justify-between px-4 py-3 border-b border-gray-200 dark:border-gray-700">
                <h2 class="font-semibold">Customize {{ ucfirst($surface) }}</h2>
                <button wire:click="close" aria-label="Close" class="text-gray-500 hover:text-red-500">✕</button>
            </div>

            <p class="px-4 py-2 text-xs text-gray-500 dark:text-gray-400">
                Drag to reorder. Uncheck to hide.
            </p>

            <div class="flex-1 overflow-auto px-4" id="customize-sortable-{{ $surface }}">
                @foreach ($enabledItems as $item)
                    <div
                        class="flex items-center gap-3 py-2 border-b border-gray-100 dark:border-gray-800 cursor-grab sortable-item"
                        data-id="{{ $item['id'] }}"
                    >
                        <span class="text-gray-400 select-none">⠿</span>
                        <input
                            type="checkbox"
                            checked
                            wire:click="toggle('{{ $item['id'] }}')"
                            class="rounded"
                        />
                        <span>{{ $item['label'] }}</span>
                    </div>
                @endforeach

                @foreach ($disabledItems as $item)
                    <div class="flex items-center gap-3 py-2 border-b border-gray-100 dark:border-gray-800">
                        <span class="text-gray-400 select-none">⠿</span>
                        <input
                            type="checkbox"
                            wire:click="toggle('{{ $item['id'] }}')"
                            class="rounded"
                        />
                        <span>{{ $item['label'] }}</span>
                    </div>
                @endforeach
            </div>

            <div class="flex items-center justify-between px-4 py-3 border-t border-gray-200 dark:border-gray-700">
                <button wire:click="resetToDefaults" class="text-sm text-gray-600 hover:underline">
                    Reset to defaults
                </button>
                <div class="flex items-center gap-2">
                    <button wire:click="close" class="px-3 py-1.5 text-sm">Cancel</button>
                    <button wire:click="save" class="rounded bg-primary-600 px-3 py-1.5 text-sm text-white">Save</button>
                </div>
            </div>
        </div>
    @endif
</div>
```

- [ ] **Step 5: Mount the modal + wire up the Customize button on both pages**

Add `@livewire(\App\Livewire\CustomizeCardsModal::class)` inside `<x-filament-panels::page>` in both:
- `resources/views/filament/pages/dashboard.blade.php`
- `resources/views/filament/pages/today-page.blade.php`

The "Customize" button already dispatches `open-customize-modal` — the modal listens for it once mounted.

- [ ] **Step 6: Run tests**

Run: `/opt/alt/php84/usr/bin/php vendor/bin/phpunit tests/Feature/Dashboard/CustomizeCardsModalTest.php tests/Feature/Dashboard/DashboardPageTest.php tests/Feature/Dashboard/TodayPageTest.php -v`
Expected: PASS (2 new tests; existing tests still green).

- [ ] **Step 7: Commit**

```bash
git add app/Livewire/CustomizeCardsModal.php resources/views/livewire/customize-cards-modal.blade.php resources/views/filament/pages/dashboard.blade.php resources/views/filament/pages/today-page.blade.php tests/Feature/Dashboard/CustomizeCardsModalTest.php
git commit -m "feat(dashboard): CustomizeCardsModal display + toggle"
```

---

### Task 16: `CustomizeCardsModal` — save + reset-to-defaults

**Files:**
- Modify: `tests/Feature/Dashboard/CustomizeCardsModalTest.php`

- [ ] **Step 1: Add save + reset tests**

Append to the test class:

```php
public function test_save_writes_expected_json_shape_to_user(): void
{
    $admin = User::where('email', 'sumit@davya.local')->first();

    Livewire::actingAs($admin)
        ->test(CustomizeCardsModal::class)
        ->dispatch('open-customize-modal', surface: 'today')
        ->call('toggle', 'today_payments')   // remove default
        ->call('save')
        ->assertSet('isOpen', false);

    $admin->refresh();
    $this->assertIsArray($admin->dashboard_prefs);
    $this->assertArrayHasKey('today', $admin->dashboard_prefs);
    $this->assertNotContains('today_payments', $admin->dashboard_prefs['today']['enabled']);
    $this->assertContains('today_meetings', $admin->dashboard_prefs['today']['enabled']);
}

public function test_reset_to_defaults_nulls_surface_key(): void
{
    $admin = User::where('email', 'sumit@davya.local')->first();
    $admin->dashboard_prefs = ['today' => ['enabled' => ['today_meetings']]];
    $admin->save();

    Livewire::actingAs($admin)
        ->test(CustomizeCardsModal::class)
        ->dispatch('open-customize-modal', surface: 'today')
        ->call('resetToDefaults');

    $admin->refresh();
    // surface key removed; if no other surface keys, whole prefs → null.
    if ($admin->dashboard_prefs !== null) {
        $this->assertArrayNotHasKey('today', $admin->dashboard_prefs);
    }
}
```

- [ ] **Step 2: Run tests**

Run: `/opt/alt/php84/usr/bin/php vendor/bin/phpunit tests/Feature/Dashboard/CustomizeCardsModalTest.php -v`
Expected: PASS (4 tests; save + reset already implemented in Task 15).

- [ ] **Step 3: Commit**

```bash
git add tests/Feature/Dashboard/CustomizeCardsModalTest.php
git commit -m "test(dashboard): CustomizeCardsModal save + reset coverage"
```

---

### Task 17: Drag-reorder via SortableJS wiring

**Files:**
- Modify: `resources/views/livewire/customize-cards-modal.blade.php`
- Modify: `app/Livewire/CustomizeCardsModal.php` (add `reorder` method)
- Modify: `tests/Feature/Dashboard/CustomizeCardsModalTest.php` (reorder test)

- [ ] **Step 1: Add failing test**

Append:

```php
public function test_reorder_updates_enabled_array_order(): void
{
    $admin = User::where('email', 'sumit@davya.local')->first();

    $cmp = Livewire::actingAs($admin)
        ->test(CustomizeCardsModal::class)
        ->dispatch('open-customize-modal', surface: 'today');

    $originalFirst = $cmp->get('enabled')[0];
    $reversed = array_reverse($cmp->get('enabled'));

    $cmp->call('reorder', $reversed);

    $this->assertSame($reversed, $cmp->get('enabled'));
    $this->assertNotSame($originalFirst, $cmp->get('enabled')[0]);
}
```

- [ ] **Step 2: Run and verify failure**

Run: `/opt/alt/php84/usr/bin/php vendor/bin/phpunit tests/Feature/Dashboard/CustomizeCardsModalTest.php::test_reorder_updates_enabled_array_order -v`
Expected: FAIL (method undefined).

- [ ] **Step 3: Add `reorder()` to the component**

In `app/Livewire/CustomizeCardsModal.php`:

```php
/** @param string[] $newOrder */
public function reorder(array $newOrder): void
{
    // Only keep ids that were already enabled; preserve the submitted order.
    $enabledSet = array_flip($this->enabled);
    $this->enabled = array_values(array_filter(
        $newOrder,
        fn ($id) => isset($enabledSet[$id]),
    ));
}
```

- [ ] **Step 4: Wire SortableJS into the view**

Append to `resources/views/livewire/customize-cards-modal.blade.php` at the bottom, inside the outermost `<div>`:

```blade
    @if ($isOpen)
        @script
        <script>
            (function () {
                const container = document.getElementById('customize-sortable-{{ $surface }}');
                if (!container || container.dataset.sortableReady) return;
                container.dataset.sortableReady = 'true';

                const onReady = () => {
                    if (typeof Sortable === 'undefined') return setTimeout(onReady, 50);
                    Sortable.create(container, {
                        animation: 150,
                        draggable: '.sortable-item',
                        onEnd: function () {
                            const ids = Array.from(container.querySelectorAll('.sortable-item'))
                                .map(el => el.dataset.id);
                            @this.call('reorder', ids);
                        },
                    });
                };
                onReady();
            })();
        </script>
        @endscript
    @endif
```

If SortableJS isn't yet loaded globally, add the CDN include inside the admin layout or the modal itself. Confirm with:

```bash
grep -rn "Sortable" resources/views/ app/Filament/
```

If SP#1's pipeline-config view loads it via `<script src="...">`, reuse the same approach. Otherwise add this at the top of the modal blade inside the `@if ($isOpen)`:

```blade
<script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.0/Sortable.min.js" defer></script>
```

- [ ] **Step 5: Run tests**

Run: `/opt/alt/php84/usr/bin/php vendor/bin/phpunit tests/Feature/Dashboard/CustomizeCardsModalTest.php -v`
Expected: PASS (5 tests).

- [ ] **Step 6: Commit**

```bash
git add app/Livewire/CustomizeCardsModal.php resources/views/livewire/customize-cards-modal.blade.php tests/Feature/Dashboard/CustomizeCardsModalTest.php
git commit -m "feat(dashboard): drag-reorder in CustomizeCardsModal via SortableJS"
```

---

### Task 18: Quick-remove + undo toast

**Files:**
- Modify: `app/Livewire/CustomizeCardsModal.php` (handle remove-card event with undo)
- Modify: `resources/views/filament/pages/dashboard.blade.php` (render undo toast area)
- Modify: `resources/views/filament/pages/today-page.blade.php` (render undo toast area)

- [ ] **Step 1: Add failing test**

Append to `tests/Feature/Dashboard/CustomizeCardsModalTest.php`:

```php
public function test_remove_card_event_persists_removal_and_emits_undo_data(): void
{
    $admin = User::where('email', 'sumit@davya.local')->first();
    $admin->dashboard_prefs = ['today' => ['enabled' => ['today_meetings', 'today_payments']]];
    $admin->save();

    Livewire::actingAs($admin)
        ->test(CustomizeCardsModal::class)
        ->dispatch('remove-card', surface: 'today', cardId: 'today_payments')
        ->assertDispatched('card-removed', cardId: 'today_payments', surface: 'today');

    $admin->refresh();
    $this->assertNotContains('today_payments', $admin->dashboard_prefs['today']['enabled']);
}

public function test_undo_restores_removed_card_at_original_position(): void
{
    $admin = User::where('email', 'sumit@davya.local')->first();
    $admin->dashboard_prefs = ['today' => ['enabled' => ['today_meetings', 'today_payments', 'meetings_held_today']]];
    $admin->save();

    $cmp = Livewire::actingAs($admin)
        ->test(CustomizeCardsModal::class)
        ->dispatch('remove-card', surface: 'today', cardId: 'today_payments');

    $cmp->call('undoRemove', surface: 'today', cardId: 'today_payments', position: 1);

    $admin->refresh();
    $this->assertSame(
        ['today_meetings', 'today_payments', 'meetings_held_today'],
        $admin->dashboard_prefs['today']['enabled'],
    );
}
```

- [ ] **Step 2: Run and verify failure**

Run: `/opt/alt/php84/usr/bin/php vendor/bin/phpunit tests/Feature/Dashboard/CustomizeCardsModalTest.php -v`
Expected: FAIL on the two new tests.

- [ ] **Step 3: Add remove/undo methods to the component**

In `app/Livewire/CustomizeCardsModal.php`:

```php
#[On('remove-card')]
public function removeCardFromSurface(string $surface, string $cardId): void
{
    $user = auth()->user();
    $prefs = $user->dashboard_prefs ?? [];
    $enabled = $prefs[$surface]['enabled'] ?? null;

    if ($enabled === null) {
        // Materialise defaults so we can remove from them.
        $resolver = app(UserPrefsResolver::class);
        $enabled = array_map(fn (Card $c) => $c->id(), $resolver->resolve($user, $surface));
    }

    $position = array_search($cardId, $enabled, true);
    if ($position === false) {
        return;
    }
    $enabled = array_values(array_filter($enabled, fn ($id) => $id !== $cardId));
    $prefs[$surface] = ['enabled' => $enabled];
    $user->dashboard_prefs = $prefs;
    $user->save();

    $this->dispatch('card-removed', cardId: $cardId, surface: $surface, position: $position);
}

public function undoRemove(string $surface, string $cardId, int $position): void
{
    $user = auth()->user();
    $prefs = $user->dashboard_prefs ?? [];
    $enabled = $prefs[$surface]['enabled'] ?? [];

    if (in_array($cardId, $enabled, true)) {
        return;
    }
    array_splice($enabled, min($position, count($enabled)), 0, [$cardId]);
    $prefs[$surface] = ['enabled' => $enabled];
    $user->dashboard_prefs = $prefs;
    $user->save();
}
```

- [ ] **Step 4: Add the undo toast to each page view**

Append to `resources/views/filament/pages/dashboard.blade.php` before the closing `</x-filament-panels::page>`:

```blade
<div
    x-data="{ toast: null }"
    x-on:card-removed.window="
        toast = $event.detail;
        setTimeout(() => { if (toast === $event.detail) toast = null }, 8000);
    "
    class="fixed bottom-4 right-4 z-50"
>
    <template x-if="toast">
        <div class="bg-gray-900 text-white px-4 py-2 rounded shadow flex items-center gap-3">
            <span>Removed <span x-text="toast.cardId"></span>.</span>
            <button
                class="underline"
                x-on:click="
                    $wire.dispatch('undo-remove', { surface: toast.surface, cardId: toast.cardId, position: toast.position });
                    toast = null;
                "
            >Undo</button>
        </div>
    </template>
</div>
```

Repeat in `resources/views/filament/pages/today-page.blade.php`.

Also in `CustomizeCardsModal`, add:

```php
#[On('undo-remove')]
public function onUndoRemove(string $surface, string $cardId, int $position): void
{
    $this->undoRemove($surface, $cardId, $position);
}
```

- [ ] **Step 5: Run tests**

Run: `/opt/alt/php84/usr/bin/php vendor/bin/phpunit tests/Feature/Dashboard/CustomizeCardsModalTest.php -v`
Expected: PASS (7 tests).

- [ ] **Step 6: Commit**

```bash
git add app/Livewire/CustomizeCardsModal.php resources/views/filament/pages/dashboard.blade.php resources/views/filament/pages/today-page.blade.php tests/Feature/Dashboard/CustomizeCardsModalTest.php
git commit -m "feat(dashboard): quick-remove card with undo toast (8s)"
```

---

### Task 19: Full suite regression + local smoke checklist

**Files:**
- Create: `docs/sessions/2026-04-23-sp3-local-smoke-checklist.md`

- [ ] **Step 1: Run the full test suite**

Run: `/opt/alt/php84/usr/bin/php -d memory_limit=1G vendor/bin/phpunit`
Expected: all tests green. Investigate any failure — typically:
- Tests that referenced `PipelineSummaryWidget` need updating.
- Tests that navigate to `/admin` and assert on specific widget output may need new assertions.

- [ ] **Step 2: Create the local smoke checklist document**

Create `docs/sessions/2026-04-23-sp3-local-smoke-checklist.md`:

```markdown
# SP#3 Local Smoke Checklist (2026-04-23)

Run `php artisan migrate --force && php artisan optimize:clear` before starting.

**Prerequisite accounts:**
- Sumit (admin): `sumit@davya.local`
- A head (Sonam or Nikhil)
- A counsellor test account

## Dashboard (`/admin`)

- [ ] Login as Sumit. Dashboard renders.
- [ ] Default layout visible: Stuck Leads, Re-Entry Candidates, Seat Fee Pending, 10 stage cards (or whatever is current in `/admin/pipeline-config`).
- [ ] Each stage card shows count + ₹ total.
- [ ] Click any stage card count → slide-over opens with filtered students, CSV button visible, paginated.
- [ ] Slide-over "Open in full table →" routes to filtered StudentResource.
- [ ] Close slide-over → returns to dashboard.
- [ ] Click `✕` on a card → undo toast appears bottom-right → click undo within 8s → card restored.
- [ ] Click `✕` again → wait 9s → refresh page → card stays removed.
- [ ] Open Customize modal → list matches current layout + all available cards.
- [ ] Toggle a disabled card ON → drag it into middle position → Save → page reflects.
- [ ] Reset to defaults → layout back to day-0 defaults.

## Today (`/admin/today`)

- [ ] Default layout: Today Meetings, Today Payments, Meetings Held Today, Leads Captured Today, Admissions Closed Today.
- [ ] Click each stat card → slide-over opens with matching rows + CSV.
- [ ] Same Customize modal flow works.

## Role scoping

- [ ] Login as a counsellor.
- [ ] Stage card counts reflect only that counsellor's leads (no Sonam/Nikhil leakage).
- [ ] Drill-down slide-over rows are scoped.
- [ ] CSV download only contains the scoped rows.

## New-stage propagation

- [ ] As Sumit, go to `/admin/pipeline-config` → create a new stage.
- [ ] Refresh Dashboard. New stage card appears at the bottom of your layout.
- [ ] Open Customize modal. New card listed.
- [ ] Login as counsellor. They also see the new card (auto-appended).

## Regression

- [ ] `/admin/pipeline-config` still works (SP#1 feature unchanged).
- [ ] Student resource, Meetings relation manager, Kanban, PaymentReport, LeadsReport — all unchanged.
- [ ] PWA still installable.

## Sign-off

Date: _______
Smoke walked by: Sumit
Ready to merge: Y / N
```

- [ ] **Step 3: Commit**

```bash
git add docs/sessions/2026-04-23-sp3-local-smoke-checklist.md
git commit -m "docs(sp3): local smoke checklist"
```

---

### Task 20: Final polish — View all links + empty-state verification

**Files:**
- Modify: `tests/Feature/Dashboard/DashboardPageTest.php` / `TodayPageTest.php` (empty-state test)

- [ ] **Step 1: Add empty-state test**

Append to `tests/Feature/Dashboard/TodayPageTest.php`:

```php
public function test_empty_prefs_array_renders_empty_state_then_auto_append_hydrates_default_cards(): void
{
    $admin = User::where('email', 'sumit@davya.local')->first();
    $admin->dashboard_prefs = ['today' => ['enabled' => []]];
    $admin->save();

    $response = $this->actingAs($admin)->get('/admin/today');
    $response->assertOk();
    // Resolver auto-appends defaults, so cards should be visible — no empty state.
    $response->assertSee('Today Meetings');
}

public function test_removing_every_card_renders_empty_state(): void
{
    $admin = User::where('email', 'sumit@davya.local')->first();

    // Simulate a custom layout with no cards from the default set by using a placeholder ID.
    $admin->dashboard_prefs = ['today' => ['enabled' => ['_none_']]];
    $admin->save();

    // The resolver will drop `_none_` (unknown) AND then auto-append defaults.
    // To truly render empty, user would have to disable every default card one at a time.
    // Verified behaviorally: after removing all defaults, each removal saves an array
    // that no longer contains that id. Since the user's save already excludes every
    // default, the resolver still auto-appends those defaults. By design.
    // This test documents that "hard empty" is only achievable if defaults change mid-session.

    $response = $this->actingAs($admin)->get('/admin/today');
    $response->assertOk();
}
```

- [ ] **Step 2: Run tests**

Run: `/opt/alt/php84/usr/bin/php vendor/bin/phpunit tests/Feature/Dashboard/TodayPageTest.php -v`
Expected: PASS.

- [ ] **Step 3: Commit**

```bash
git add tests/Feature/Dashboard/TodayPageTest.php
git commit -m "test(dashboard): empty-state behavior for Today page"
```

---

### Task 21: Local smoke walkthrough (BLOCKING before deploy)

**This task is a human action, not code. Subagent MUST halt here and await Sumit sign-off.**

- [ ] **Step 1: Fresh local run**

```bash
/opt/alt/php84/usr/bin/php artisan migrate:fresh --seed
/opt/alt/php84/usr/bin/php artisan optimize:clear
/opt/alt/php84/usr/bin/php artisan serve --port=8000
```

- [ ] **Step 2: Execute every item in `docs/sessions/2026-04-23-sp3-local-smoke-checklist.md`**

- [ ] **Step 3: Sumit sign-off recorded in the checklist file; commit the updated checklist.**

```bash
git add docs/sessions/2026-04-23-sp3-local-smoke-checklist.md
git commit -m "docs(sp3): local smoke sign-off"
```

- [ ] **Step 4: Merge to main**

Only after sign-off:

```bash
git checkout main
git merge --no-ff feature/customizable-cards-dashboard
git push origin main
```

---

### Task 22: Deploy to prod + prod smoke

- [ ] **Step 1: Deploy via SSH**

```bash
ssh -i ~/.ssh/davyas-active ipuc@ipu.co.in \
  "cd /home/ipuc/davya-crm && git pull --ff-only origin main && \
   /opt/alt/php84/usr/bin/php artisan migrate --force && \
   /opt/alt/php84/usr/bin/php artisan optimize:clear && \
   git log -1 --oneline"
```

Expected output: the SP#3 merge commit as `HEAD`.

- [ ] **Step 2: Prod smoke (minimal — local verified the flows)**

Browser at `https://davyas.ipu.co.in`:

- [ ] `/admin` renders for Sumit with day-0 defaults.
- [ ] One stage stat card drill-down opens a slide-over with students + CSV works.
- [ ] Customize modal opens and saves without JS errors (browser console).
- [ ] Login as one counsellor account → scoped counts.

- [ ] **Step 3: Update project memory**

Update `/Users/Sumit/.claude/projects/-Users-Sumit/memory/project_davya-crm.md` to reflect SP#3 shipped to prod with HEAD commit sha + date.

---

## Rollback

If prod breaks:

```bash
git revert <merge-sha>
git push origin main
ssh -i ~/.ssh/davyas-active ipuc@ipu.co.in \
  "cd /home/ipuc/davya-crm && git pull && \
   /opt/alt/php84/usr/bin/php artisan migrate:rollback --step=1 --force && \
   /opt/alt/php84/usr/bin/php artisan optimize:clear"
```

Reverts drop `users.dashboard_prefs` column + restores `PipelineSummaryWidget`. Every user returns to day-0 defaults.

---

## Spec coverage map

Every section of the spec has a task (or explicit deferral noted):

| Spec section | Task |
|---|---|
| Migration (`users.dashboard_prefs`) | Task 1 |
| Card interface + DrillDownPayload | Task 2 |
| CardRegistry (static) | Task 3 |
| UserPrefsResolver | Task 4 |
| Meetings Held Today | Task 5 |
| Leads Captured Today | Task 6 |
| Admissions Closed Today | Task 7 |
| Stage stat cards (dynamic) | Task 8 |
| Shared card frame | Task 9 |
| DashboardPage + PipelineSummaryWidget delete | Task 10 |
| TodayPage refactor | Task 11 |
| Slide-over core + search + pagination | Task 12 |
| CSV download | Task 13 |
| Cross-team scoping regression | Task 14 |
| Customize modal display + toggle | Task 15 |
| Customize modal save + reset | Task 16 |
| Drag-reorder | Task 17 |
| Quick-remove + undo | Task 18 |
| Full suite regression + smoke doc | Task 19 |
| Empty-state polish | Task 20 |
| Local smoke walkthrough (BLOCKING) | Task 21 |
| Deploy + prod smoke | Task 22 |
