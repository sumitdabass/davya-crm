# Today Mobile-First Action Checklist — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Turn `/admin/today` from a customizable card grid into a mobile-first daily **action checklist** — a stats strip + urgency-ordered, tap-to-act sections — while preserving Customize, the stats, the per-student peek drawer, and undo.

**Architecture:** Keep `TodayPage::cards()` (prefs-driven show/hide/order) to decide *which* sections render and in what order. Render `stat` cards as a compact top strip and `list` cards as checklist sections via a uniform partial fed by a new `App\Today\ChecklistSections` row-provider + `App\Today\SectionRegistry`. One new `PaymentsToChaseCard`. Scoped `today-skin.css` loaded only on the page. Rows dispatch `open-student-peek` → the globally-mounted `StudentPeekDrawer` (same drawer as Pipeline).

**Tech Stack:** Laravel 11, Filament 3, Livewire 3, Blade, PHPUnit. Branch: `feat/today-mobile-redesign`.

**Spec:** `docs/superpowers/specs/2026-06-11-today-mobile-checklist-design.md` · **Mockup:** `docs/superpowers/specs/mockups/today-checklist-mobile.html`

**Test runner:** `php -d memory_limit=2048M vendor/bin/phpunit` (plain `php artisan test` OOMs at 128M).

---

## File Structure

| File | Responsibility |
|---|---|
| `app/Dashboard/Cards/ListCards/PaymentsToChaseCard.php` (new) | New card: students with a pending balance, not closed. Exposes `query(User): Builder` for reuse. |
| `app/Dashboard/CardRegistry.php` (modify) | Register the new card. |
| `app/Dashboard/Cards/ListCards/{StuckLeads,SeatFeePending,ReEntryCandidates}Card.php` (modify) | `isDefaultOn` includes `today`. |
| `app/Today/ChecklistSections.php` (new) | Row-provider: `forCard(string $cardId, User): array` returning normalized rows. Single source of section data. |
| `app/Today/SectionRegistry.php` (new) | `descriptor(string $cardId): ?array` → label/icon/urgent for a list card id. |
| `resources/views/filament/pages/partials/checklist-section.blade.php` (new) | Uniform collapsible section render. |
| `resources/views/filament/pages/today-page.blade.php` (modify) | Stats strip + checklist sections; keep Customize, peek, slide-over, modal, undo. |
| `app/Providers/Filament/AdminPanelProvider.php` (modify) | `PAGE_START` render hook scoped to `[TodayPage::class]`. |
| `resources/css/today-skin.css` + `public/css/today-skin.css` (new) | Scoped skin under `body.davya-today-skin`. |
| `tests/Feature/MobileToday/*` (new) | Coverage. |

**Normalized row shape** (every provider returns a list of these associative arrays):
```php
[
    'student_id' => int,        // for open-student-peek; null-safe skip if missing
    'title'      => string,     // primary line (student name)
    'subtitle'   => string,     // secondary line (course/stage/context)
    'time'       => ?string,    // 'HH:MM' for meetings/payments; else null
    'amount'     => ?float,     // ₹ for payment rows; else null
    'dot'        => ?string,    // '#10B981' | '#F59E0B' | '#EF4444' aging dot; else null
    'pill'       => ?string,    // small right-side label e.g. '21 days'; else null
]
```

---

### Task 1: `PaymentsToChaseCard` + registration

**Files:**
- Create: `app/Dashboard/Cards/ListCards/PaymentsToChaseCard.php`
- Modify: `app/Dashboard/CardRegistry.php`
- Test: `tests/Feature/MobileToday/PaymentsToChaseCardTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\MobileToday;

use App\Dashboard\CardRegistry;
use App\Dashboard\Cards\ListCards\PaymentsToChaseCard;
use App\Models\Payment;
use App\Models\Student;
use App\Models\User;
use Tests\TestCase;

class PaymentsToChaseCardTest extends TestCase
{
    public function test_card_is_registered_and_defaults_on_today_only(): void
    {
        $card = CardRegistry::find('payments_to_chase');

        $this->assertInstanceOf(PaymentsToChaseCard::class, $card);
        $this->assertSame('list', $card->type());
        $this->assertTrue($card->isDefaultOn('today'));
        $this->assertFalse($card->isDefaultOn('dashboard'));
    }

    public function test_query_returns_students_with_pending_balance_and_excludes_closed_and_fully_paid(): void
    {
        $viewer = User::factory()->create(['role' => 'admin']);

        $pending = Student::factory()->create(['deal_amount' => 50000, 'stage' => 'Advance Received']);
        Payment::factory()->create(['student_id' => $pending->id, 'amount' => 10000]);

        $fullyPaid = Student::factory()->create(['deal_amount' => 30000, 'stage' => 'Advance Received']);
        Payment::factory()->create(['student_id' => $fullyPaid->id, 'amount' => 30000]);

        $closed = Student::factory()->create(['deal_amount' => 20000, 'stage' => 'Closed']);

        $ids = (new PaymentsToChaseCard())->query($viewer)->pluck('id')->all();

        $this->assertContains($pending->id, $ids);
        $this->assertNotContains($fullyPaid->id, $ids);
        $this->assertNotContains($closed->id, $ids);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php -d memory_limit=2048M vendor/bin/phpunit --filter PaymentsToChaseCardTest`
Expected: FAIL — `Class "App\Dashboard\Cards\ListCards\PaymentsToChaseCard" not found` / `CardRegistry::find` returns null.

- [ ] **Step 3: Create the card**

```php
<?php

namespace App\Dashboard\Cards\ListCards;

use App\Dashboard\Card;
use App\Dashboard\DrillDownPayload;
use App\Models\Student;
use App\Models\User;
use Illuminate\Contracts\Database\Eloquent\Builder;

class PaymentsToChaseCard implements Card
{
    public function id(): string { return 'payments_to_chase'; }
    public function label(): string { return 'Payments to Chase'; }
    public function surface(): string { return 'any'; }
    public function isDefaultOn(string $surface): bool { return $surface === 'today'; }
    public function type(): string { return 'list'; }

    /** Students with a positive pending balance who are not closed. */
    public function query(User $viewer): Builder
    {
        return Student::query()
            ->visibleTo($viewer)
            ->where('deal_amount', '>', 0)
            ->whereNotIn('stage', ['Closed'])
            ->whereRaw('students.deal_amount > (SELECT COALESCE(SUM(amount), 0) FROM payments WHERE payments.student_id = students.id)')
            ->with('owner')
            ->orderByDesc('updated_at');
    }

    public function render(User $viewer): string
    {
        // Not used on the Today checklist (rows come from ChecklistSections),
        // but the Card contract requires it; render a minimal list for any
        // future Dashboard use.
        $rows = $this->query($viewer)->limit(10)->get();

        return view('filament.widgets.payments-to-chase-card', ['rows' => $rows])->render();
    }

    public function drillDown(User $viewer): ?DrillDownPayload { return null; }
    public function viewAllHref(User $viewer): ?string { return null; }
    public function isAvailableFor(User $viewer): bool { return true; }
}
```

- [ ] **Step 4: Create the minimal render blade**

Create `resources/views/filament/widgets/payments-to-chase-card.blade.php`:
```blade
<div style="padding: 8px 16px;">
    @forelse ($rows as $r)
        <div style="display:flex;justify-content:space-between;padding:6px 0;border-bottom:1px solid #f1f1f1;font-size:13px;">
            <span>{{ $r->name }}</span>
            <span style="font-variant-numeric:tabular-nums;">₹{{ number_format($r->pending_amount) }}</span>
        </div>
    @empty
        <div style="padding:8px 0;color:#6b7280;font-size:13px;">Nothing to chase.</div>
    @endforelse
</div>
```

- [ ] **Step 5: Register in CardRegistry**

In `app/Dashboard/CardRegistry.php`, add the import near the other ListCards imports:
```php
use App\Dashboard\Cards\ListCards\PaymentsToChaseCard;
```
And add to the `$static` array (after `TodayPaymentsCard`):
```php
            new TodayPaymentsCard,
            new PaymentsToChaseCard,
```

- [ ] **Step 6: Run test to verify it passes**

Run: `php -d memory_limit=2048M vendor/bin/phpunit --filter PaymentsToChaseCardTest`
Expected: PASS (2 tests).

- [ ] **Step 7: Commit**

```bash
git add app/Dashboard/Cards/ListCards/PaymentsToChaseCard.php app/Dashboard/CardRegistry.php resources/views/filament/widgets/payments-to-chase-card.blade.php tests/Feature/MobileToday/PaymentsToChaseCardTest.php
git commit -m "feat(today): PaymentsToChaseCard (pending-balance students)"
```

---

### Task 2: Default-on `today` for the three watchlist cards

**Files:**
- Modify: `app/Dashboard/Cards/ListCards/StuckLeadsCard.php`, `SeatFeePendingCard.php`, `ReEntryCandidatesCard.php`
- Test: `tests/Feature/MobileToday/WatchlistCardsDefaultOnTodayTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\MobileToday;

use App\Dashboard\Cards\ListCards\ReEntryCandidatesCard;
use App\Dashboard\Cards\ListCards\SeatFeePendingCard;
use App\Dashboard\Cards\ListCards\StuckLeadsCard;
use Tests\TestCase;

class WatchlistCardsDefaultOnTodayTest extends TestCase
{
    public function test_watchlist_cards_default_on_today_and_dashboard(): void
    {
        foreach ([new StuckLeadsCard, new SeatFeePendingCard, new ReEntryCandidatesCard] as $card) {
            $this->assertTrue($card->isDefaultOn('today'), $card->id().' should default-on today');
            $this->assertTrue($card->isDefaultOn('dashboard'), $card->id().' should still default-on dashboard');
        }
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php -d memory_limit=2048M vendor/bin/phpunit --filter WatchlistCardsDefaultOnTodayTest`
Expected: FAIL — `stuck_leads should default-on today`.

- [ ] **Step 3: Edit each card's `isDefaultOn`**

In each of the three files, change:
```php
    public function isDefaultOn(string $surface): bool { return $surface === 'dashboard'; }
```
to:
```php
    public function isDefaultOn(string $surface): bool { return in_array($surface, ['dashboard', 'today'], true); }
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php -d memory_limit=2048M vendor/bin/phpunit --filter WatchlistCardsDefaultOnTodayTest`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Dashboard/Cards/ListCards/StuckLeadsCard.php app/Dashboard/Cards/ListCards/SeatFeePendingCard.php app/Dashboard/Cards/ListCards/ReEntryCandidatesCard.php tests/Feature/MobileToday/WatchlistCardsDefaultOnTodayTest.php
git commit -m "feat(today): watchlist cards default-on the Today surface"
```

---

### Task 3: `ChecklistSections` row-provider

**Files:**
- Create: `app/Today/ChecklistSections.php`
- Test: `tests/Feature/MobileToday/ChecklistSectionsTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\MobileToday;

use App\Models\Meeting;
use App\Models\Payment;
use App\Models\Student;
use App\Models\User;
use App\Today\ChecklistSections;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class ChecklistSectionsTest extends TestCase
{
    public function test_meetings_today_returns_only_todays_meetings(): void
    {
        $viewer = User::factory()->create(['role' => 'admin']);
        $student = Student::factory()->create(['name' => 'Shubham']);

        Meeting::factory()->create([
            'student_id' => $student->id,
            'scheduled_at' => Carbon::now('Asia/Kolkata')->setTime(11, 0),
            'status' => 'scheduled',
        ]);
        Meeting::factory()->create([
            'student_id' => $student->id,
            'scheduled_at' => Carbon::now('Asia/Kolkata')->addDays(2),
            'status' => 'scheduled',
        ]);

        $rows = (new ChecklistSections())->forCard('today_meetings', $viewer);

        $this->assertCount(1, $rows);
        $this->assertSame($student->id, $rows[0]['student_id']);
        $this->assertSame('Shubham', $rows[0]['title']);
        $this->assertSame('11:00', $rows[0]['time']);
    }

    public function test_payments_to_chase_rows_carry_pending_amount(): void
    {
        $viewer = User::factory()->create(['role' => 'admin']);
        $s = Student::factory()->create(['name' => 'Raghav', 'deal_amount' => 50000, 'stage' => 'Advance Received']);
        Payment::factory()->create(['student_id' => $s->id, 'amount' => 25000]);

        $rows = (new ChecklistSections())->forCard('payments_to_chase', $viewer);

        $this->assertCount(1, $rows);
        $this->assertSame($s->id, $rows[0]['student_id']);
        $this->assertEqualsWithDelta(25000.0, $rows[0]['amount'], 0.01);
    }

    public function test_payments_received_today_matches_todays_payments(): void
    {
        $viewer = User::factory()->create(['role' => 'admin']);
        $s = Student::factory()->create(['name' => 'Latika']);
        Payment::factory()->create([
            'student_id' => $s->id,
            'amount' => 10000,
            'received_at' => Carbon::now('Asia/Kolkata')->setTime(9, 40),
        ]);
        Payment::factory()->create([
            'student_id' => $s->id,
            'amount' => 5000,
            'received_at' => Carbon::now('Asia/Kolkata')->subDays(3),
        ]);

        $rows = (new ChecklistSections())->forCard('today_payments', $viewer);

        $this->assertCount(1, $rows);
        $this->assertSame('09:40', $rows[0]['time']);
        $this->assertEqualsWithDelta(10000.0, $rows[0]['amount'], 0.01);
    }

    public function test_unknown_card_returns_empty(): void
    {
        $viewer = User::factory()->create(['role' => 'admin']);
        $this->assertSame([], (new ChecklistSections())->forCard('nope', $viewer));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php -d memory_limit=2048M vendor/bin/phpunit --filter ChecklistSectionsTest`
Expected: FAIL — `Class "App\Today\ChecklistSections" not found`.

- [ ] **Step 3: Implement the service**

```php
<?php

namespace App\Today;

use App\Dashboard\Cards\ListCards\PaymentsToChaseCard;
use App\Models\Meeting;
use App\Models\Payment;
use App\Models\RoundHistory;
use App\Models\Student;
use App\Models\User;
use Illuminate\Support\Carbon;

class ChecklistSections
{
    private const TZ = 'Asia/Kolkata';

    /** @return array<int, array<string, mixed>> */
    public function forCard(string $cardId, User $viewer): array
    {
        return match ($cardId) {
            'today_meetings'      => $this->meetingsToday($viewer),
            'payments_to_chase'   => $this->paymentsToChase($viewer),
            'today_payments'      => $this->paymentsReceivedToday($viewer),
            'stuck_leads'         => $this->stuck($viewer),
            'seat_fee_pending'    => $this->seatFeePending($viewer),
            're_entry_candidates' => $this->reEntry($viewer),
            default               => [],
        };
    }

    /** @return array<int, array<string, mixed>> */
    private function meetingsToday(User $viewer): array
    {
        $start = Carbon::now(self::TZ)->startOfDay();
        $end   = Carbon::now(self::TZ)->endOfDay();

        return Meeting::query()
            ->whereBetween('scheduled_at', [$start, $end])
            ->whereIn('status', ['scheduled', 'held'])
            ->whereHas('student', fn ($q) => $q->visibleTo($viewer))
            ->with('student.owner')
            ->orderBy('scheduled_at')
            ->get()
            ->map(fn (Meeting $m) => [
                'student_id' => $m->student_id,
                'title'      => $m->student?->name ?? '—',
                'subtitle'   => trim(($m->student?->course ?? '—').' · '.($m->student?->owner?->name ?? 'Unassigned'), ' ·'),
                'time'       => $m->scheduled_at?->setTimezone(self::TZ)->format('H:i'),
                'amount'     => null,
                'dot'        => null,
                'pill'       => $m->status === 'held' ? 'held' : null,
            ])
            ->values()
            ->all();
    }

    /** @return array<int, array<string, mixed>> */
    private function paymentsToChase(User $viewer): array
    {
        return (new PaymentsToChaseCard())->query($viewer)
            ->limit(50)
            ->get()
            ->map(fn (Student $s) => [
                'student_id' => $s->id,
                'title'      => $s->name,
                'subtitle'   => $s->stage.' · '.($s->owner?->name ?? 'Unassigned'),
                'time'       => null,
                'amount'     => $s->pending_amount,
                'dot'        => $this->agingDot($s->updated_at),
                'pill'       => null,
            ])
            ->all();
    }

    /** @return array<int, array<string, mixed>> */
    private function paymentsReceivedToday(User $viewer): array
    {
        $start = Carbon::now(self::TZ)->startOfDay();
        $end   = Carbon::now(self::TZ)->endOfDay();

        return Payment::query()
            ->whereBetween('received_at', [$start, $end])
            ->whereHas('student', fn ($q) => $q->visibleTo($viewer))
            ->with('student')
            ->orderByDesc('received_at')
            ->get()
            ->map(fn (Payment $p) => [
                'student_id' => $p->student_id,
                'title'      => $p->student?->name ?? '—',
                'subtitle'   => ucfirst((string) $p->type).' · '.($p->mode ?? '—'),
                'time'       => $p->received_at?->setTimezone(self::TZ)->format('H:i'),
                'amount'     => (float) $p->amount,
                'dot'        => null,
                'pill'       => null,
            ])
            ->all();
    }

    /** @return array<int, array<string, mixed>> */
    private function stuck(User $viewer): array
    {
        return Student::query()
            ->stuck()
            ->visibleTo($viewer)
            ->with('owner')
            ->orderBy('updated_at')
            ->limit(50)
            ->get()
            ->map(fn (Student $s) => [
                'student_id' => $s->id,
                'title'      => $s->name,
                'subtitle'   => (string) $s->stage,
                'time'       => null,
                'amount'     => null,
                'dot'        => $this->agingDot($s->updated_at),
                'pill'       => $s->updated_at ? $s->updated_at->diffInDays(now()).' days' : null,
            ])
            ->all();
    }

    /** @return array<int, array<string, mixed>> */
    private function seatFeePending(User $viewer): array
    {
        return RoundHistory::query()
            ->seatFeePending()
            ->whereHas('student', fn ($q) => $q->visibleTo($viewer))
            ->with('student')
            ->orderBy('created_at')
            ->limit(50)
            ->get()
            ->map(fn (RoundHistory $r) => [
                'student_id' => $r->student_id,
                'title'      => $r->student?->name ?? '—',
                'subtitle'   => trim(($r->round_name ?? '—').' · '.($r->allotted_college ?? 'fee due'), ' ·'),
                'time'       => null,
                'amount'     => $r->seat_fee_amount !== null ? (float) $r->seat_fee_amount : null,
                'dot'        => $this->agingDot($r->created_at),
                'pill'       => null,
            ])
            ->all();
    }

    /** @return array<int, array<string, mixed>> */
    private function reEntry(User $viewer): array
    {
        return RoundHistory::query()
            ->reEntryCandidates()
            ->whereHas('student', fn ($q) => $q->visibleTo($viewer))
            ->with('student.owner')
            ->orderByDesc('created_at')
            ->limit(50)
            ->get()
            ->map(fn (RoundHistory $r) => [
                'student_id' => $r->student_id,
                'title'      => $r->student?->name ?? '—',
                'subtitle'   => ($r->round_name ?? '—').' · re-eligible',
                'time'       => null,
                'amount'     => null,
                'dot'        => $this->agingDot($r->student?->updated_at),
                'pill'       => null,
            ])
            ->all();
    }

    private function agingDot(?Carbon $ts): ?string
    {
        if ($ts === null) {
            return null;
        }
        $days = $ts->diffInDays(now());

        return $days <= 3 ? '#10B981' : ($days <= 14 ? '#F59E0B' : '#EF4444');
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php -d memory_limit=2048M vendor/bin/phpunit --filter ChecklistSectionsTest`
Expected: PASS (4 tests). If `Meeting::factory()` or `Payment::factory()` lacks fields, check the existing factories under `database/factories/` and adjust the test's explicit attributes (do not change the service).

- [ ] **Step 5: Commit**

```bash
git add app/Today/ChecklistSections.php tests/Feature/MobileToday/ChecklistSectionsTest.php
git commit -m "feat(today): ChecklistSections row-provider for all six sections"
```

---

### Task 4: `SectionRegistry` (presentation descriptors)

**Files:**
- Create: `app/Today/SectionRegistry.php`
- Test: `tests/Feature/MobileToday/SectionRegistryTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\MobileToday;

use App\Today\SectionRegistry;
use Tests\TestCase;

class SectionRegistryTest extends TestCase
{
    public function test_known_list_cards_have_descriptors(): void
    {
        foreach ([
            'today_meetings', 'payments_to_chase', 'today_payments',
            'stuck_leads', 'seat_fee_pending', 're_entry_candidates',
        ] as $id) {
            $d = SectionRegistry::descriptor($id);
            $this->assertNotNull($d, "$id should have a descriptor");
            $this->assertArrayHasKey('label', $d);
            $this->assertArrayHasKey('icon', $d);
            $this->assertArrayHasKey('urgent', $d);
        }
    }

    public function test_unknown_id_returns_null(): void
    {
        $this->assertNull(SectionRegistry::descriptor('nope'));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php -d memory_limit=2048M vendor/bin/phpunit --filter SectionRegistryTest`
Expected: FAIL — `Class "App\Today\SectionRegistry" not found`.

- [ ] **Step 3: Implement**

```php
<?php

namespace App\Today;

class SectionRegistry
{
    /**
     * Presentation descriptors for the Today checklist's list-card sections.
     * `icon` is a heroicon name; `urgent` flips the section to vermilion accent.
     *
     * @return array<string, array{label:string, icon:string, urgent:bool}>
     */
    public static function all(): array
    {
        return [
            'today_meetings'      => ['label' => 'Meetings today',          'icon' => 'heroicon-o-calendar-days',      'urgent' => false],
            'payments_to_chase'   => ['label' => 'Payments to chase',       'icon' => 'heroicon-o-credit-card',        'urgent' => true],
            'today_payments'      => ['label' => 'Received today',          'icon' => 'heroicon-o-banknotes',          'urgent' => false],
            'stuck_leads'         => ['label' => 'Stuck leads',             'icon' => 'heroicon-o-clock',              'urgent' => false],
            'seat_fee_pending'    => ['label' => 'Seat-fee pending',        'icon' => 'heroicon-o-academic-cap',       'urgent' => true],
            're_entry_candidates' => ['label' => 'Re-entry candidates',     'icon' => 'heroicon-o-arrow-path',         'urgent' => false],
        ];
    }

    /** @return array{label:string, icon:string, urgent:bool}|null */
    public static function descriptor(string $cardId): ?array
    {
        return self::all()[$cardId] ?? null;
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php -d memory_limit=2048M vendor/bin/phpunit --filter SectionRegistryTest`
Expected: PASS (2 tests).

- [ ] **Step 5: Commit**

```bash
git add app/Today/SectionRegistry.php tests/Feature/MobileToday/SectionRegistryTest.php
git commit -m "feat(today): SectionRegistry presentation descriptors"
```

---

### Task 5: Scoped skin + render hook

**Files:**
- Create: `resources/css/today-skin.css`, `public/css/today-skin.css` (identical copy)
- Modify: `app/Providers/Filament/AdminPanelProvider.php`
- Test: `tests/Feature/MobileToday/TodaySkinScopeTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\MobileToday;

use App\Models\User;
use Tests\TestCase;

class TodaySkinScopeTest extends TestCase
{
    public function test_skin_loads_on_today_and_not_elsewhere(): void
    {
        $user = User::factory()->create(['role' => 'admin']);

        $today = $this->actingAs($user)->get('/admin/today');
        $today->assertOk();
        $today->assertSee('today-skin.css', false);
        $today->assertSee('davya-today-skin', false);

        $students = $this->actingAs($user)->get('/admin/students');
        $students->assertOk();
        $students->assertDontSee('today-skin.css', false);
        $students->assertDontSee('davya-today-skin', false);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php -d memory_limit=2048M vendor/bin/phpunit --filter TodaySkinScopeTest`
Expected: FAIL — `today-skin.css` not seen on `/admin/today`.

- [ ] **Step 3: Create the skin stylesheet**

Create `resources/css/today-skin.css` with the styles below (derived from the mockup; scoped under `body.davya-today-skin`). Then copy it verbatim to `public/css/today-skin.css`.

```css
/* Today action checklist — scoped skin. Loaded only on /admin/today. */
@import url('https://fonts.googleapis.com/css2?family=Instrument+Serif:ital@0;1&family=Bricolage+Grotesque:opsz,wght@12..96,400..700&family=JetBrains+Mono:wght@400;500;600&display=swap');

body.davya-today-skin .davya-today{
  --cream:#FAF6EF; --paper:#fff; --ink:#1F2A24; --muted:#6B7A72; --line:#E7DFD2;
  --emerald:#0F6B4F; --emerald-soft:#E6F0EB; --vermilion:#D6452B; --vermilion-soft:#FBE9E4;
  font-family:'Bricolage Grotesque',system-ui,sans-serif; color:var(--ink);
  max-width:720px; margin:0 auto;
}
body.davya-today-skin .dt-h{display:flex;align-items:flex-end;justify-content:space-between;margin-bottom:14px;}
body.davya-today-skin .dt-h .t{font-family:'Instrument Serif',serif;font-style:italic;font-size:40px;line-height:.95;}
body.davya-today-skin .dt-h .d{font-size:12.5px;color:var(--muted);margin-top:4px;}
body.davya-today-skin .dt-stats{display:flex;gap:8px;margin-bottom:20px;}
body.davya-today-skin .dt-stat{flex:1;background:var(--paper);border:1px solid var(--line);border-radius:14px;padding:10px;text-align:center;}
body.davya-today-skin .dt-stat .n{font-family:'JetBrains Mono',monospace;font-size:21px;font-weight:600;}
body.davya-today-skin .dt-stat .l{font-size:10.5px;color:var(--muted);text-transform:uppercase;letter-spacing:.5px;}
body.davya-today-skin .dt-sec{background:var(--paper);border:1px solid var(--line);border-radius:18px;margin-bottom:14px;overflow:hidden;box-shadow:0 6px 18px rgba(31,42,36,.06);}
body.davya-today-skin .dt-sec.empty{opacity:.72;}
body.davya-today-skin .dt-sec-h{display:flex;align-items:center;gap:10px;padding:14px 15px;cursor:pointer;user-select:none;}
body.davya-today-skin .dt-sec-h .ic{width:30px;height:30px;border-radius:9px;display:grid;place-items:center;background:var(--emerald-soft);color:var(--emerald);flex:none;}
body.davya-today-skin .dt-sec-h.urgent .ic{background:var(--vermilion-soft);color:var(--vermilion);}
body.davya-today-skin .dt-sec-h .ic svg{width:17px;height:17px;}
body.davya-today-skin .dt-sec-h .ttl{font-family:'Instrument Serif',serif;font-style:italic;font-size:20px;flex:1;line-height:1;}
body.davya-today-skin .dt-sec-h .cnt{font-family:'JetBrains Mono',monospace;font-size:12px;font-weight:600;background:var(--ink);color:#fff;border-radius:999px;padding:2px 9px;min-width:24px;text-align:center;}
body.davya-today-skin .dt-sec-h.urgent .cnt{background:var(--vermilion);}
body.davya-today-skin .dt-sec-h .chev{color:var(--muted);transition:transform .18s;flex:none;}
body.davya-today-skin .dt-sec.collapsed .chev{transform:rotate(-90deg);}
body.davya-today-skin .dt-sec.collapsed .dt-sec-b{display:none;}
body.davya-today-skin .dt-sec-b{border-top:1px solid var(--line);}
body.davya-today-skin .dt-row{display:flex;align-items:center;gap:11px;padding:12px 15px;border-bottom:1px solid #F1ECE2;text-decoration:none;color:inherit;cursor:pointer;}
body.davya-today-skin .dt-row:last-child{border-bottom:none;}
body.davya-today-skin .dt-row:active{background:#FBF8F2;}
body.davya-today-skin .dt-dot{width:9px;height:9px;border-radius:50%;flex:none;}
body.davya-today-skin .dt-time{font-family:'JetBrains Mono',monospace;font-size:12.5px;font-weight:600;color:var(--emerald);width:52px;flex:none;}
body.davya-today-skin .dt-row .bd{flex:1;min-width:0;}
body.davya-today-skin .dt-row .nm{font-weight:600;font-size:14.5px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
body.davya-today-skin .dt-row .sub{font-size:12px;color:var(--muted);margin-top:1px;}
body.davya-today-skin .dt-amt{font-family:'JetBrains Mono',monospace;font-weight:600;text-align:right;}
body.davya-today-skin .dt-amt .w{display:block;font-size:10px;color:var(--muted);font-family:'Bricolage Grotesque';font-weight:400;}
body.davya-today-skin .dt-pill{display:inline-block;font-size:10.5px;font-weight:600;border-radius:999px;padding:2px 8px;background:#F0EADF;color:var(--muted);white-space:nowrap;}
body.davya-today-skin .dt-clear{padding:16px 15px;color:var(--muted);font-size:13px;}
```

- [ ] **Step 4: Copy to public**

Run: `cp resources/css/today-skin.css public/css/today-skin.css`

- [ ] **Step 5: Add the render hook**

In `app/Providers/Filament/AdminPanelProvider.php`, add a use import near the top (alongside `use App\Filament\Pages\KanbanBoard;`):
```php
use App\Filament\Pages\TodayPage;
```
And add a new render hook immediately after the existing pipeline-skin `->renderHook(...)` block (the one scoped to `[KanbanBoard::class]`):
```php
            ->renderHook(
                PanelsRenderHook::PAGE_START,
                fn (): string => <<<'HTML'
                    <link rel="stylesheet" href="/css/today-skin.css?v=1" id="davya-today-skin-css">
                    <script>document.body.classList.add('davya-today-skin');</script>
                    HTML,
                scopes: [TodayPage::class],
            )
```

- [ ] **Step 6: Run test to verify it passes**

Run: `php -d memory_limit=2048M vendor/bin/phpunit --filter TodaySkinScopeTest`
Expected: PASS. (The `davya-today-skin` body class is added by the script; the page also wraps its content in `.davya-today` in Task 6 — the assertion only needs the string present, which the hook provides.)

- [ ] **Step 7: Commit**

```bash
git add resources/css/today-skin.css public/css/today-skin.css app/Providers/Filament/AdminPanelProvider.php tests/Feature/MobileToday/TodaySkinScopeTest.php
git commit -m "feat(today): scoped today-skin.css + page-scoped render hook"
```

---

### Task 6: `checklist-section` partial

**Files:**
- Create: `resources/views/filament/pages/partials/checklist-section.blade.php`

(No standalone test — exercised by Task 7's render test.)

- [ ] **Step 1: Create the partial**

```blade
{{-- props: $id (card id), $label, $icon (heroicon name), $urgent (bool), $rows (array) --}}
@php($count = count($rows))
<div class="dt-sec @if($count === 0) empty collapsed @endif" wire:key="dt-sec-{{ $id }}">
    <div class="dt-sec-h @if($urgent) urgent @endif"
         onclick="this.closest('.dt-sec').classList.toggle('collapsed')">
        <span class="ic">@svg($icon, 'w-4 h-4')</span>
        <span class="ttl">{{ $label }}</span>
        <span class="cnt">{{ $count }}</span>
        <span class="chev">@svg('heroicon-m-chevron-down', 'w-4 h-4')</span>
    </div>
    <div class="dt-sec-b">
        @forelse ($rows as $r)
            <div class="dt-row"
                 @if($r['student_id']) wire:click="$dispatch('open-student-peek', { studentId: {{ $r['student_id'] }} })" @endif>
                @if (! empty($r['time']))
                    <span class="dt-time">{{ $r['time'] }}</span>
                @elseif (! empty($r['dot']))
                    <span class="dt-dot" style="background: {{ $r['dot'] }};"></span>
                @endif
                <div class="bd">
                    <div class="nm">{{ $r['title'] }}</div>
                    <div class="sub">{{ $r['subtitle'] }}</div>
                </div>
                @if (! is_null($r['amount']))
                    <span class="dt-amt">₹{{ number_format($r['amount']) }}<span class="w">{{ \App\Support\MoneyFormat::toIndianWords((int) $r['amount']) }}</span></span>
                @elseif (! empty($r['pill']))
                    <span class="dt-pill">{{ $r['pill'] }}</span>
                @else
                    <span class="chev">@svg('heroicon-m-chevron-right', 'w-4 h-4')</span>
                @endif
            </div>
        @empty
            <div class="dt-clear">All clear — nothing pending.</div>
        @endforelse
    </div>
</div>
```

- [ ] **Step 2: Verify MoneyFormat helper exists**

Run: `grep -n "function toIndianWords" app/Support/MoneyFormat.php`
Expected: a match. If the signature differs (e.g. takes float), adjust the cast in the partial accordingly.

- [ ] **Step 3: Commit**

```bash
git add resources/views/filament/pages/partials/checklist-section.blade.php
git commit -m "feat(today): uniform checklist-section partial"
```

---

### Task 7: Rewrite `today-page.blade.php`

**Files:**
- Modify: `resources/views/filament/pages/today-page.blade.php`
- Test: `tests/Feature/MobileToday/TodayChecklistRenderTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\MobileToday;

use App\Models\Meeting;
use App\Models\Student;
use App\Models\User;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class TodayChecklistRenderTest extends TestCase
{
    public function test_today_renders_stats_strip_and_sections_with_peek_dispatch(): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        $student = Student::factory()->create(['name' => 'Shubham']);
        Meeting::factory()->create([
            'student_id' => $student->id,
            'scheduled_at' => Carbon::now('Asia/Kolkata')->setTime(11, 0),
            'status' => 'scheduled',
        ]);

        $res = $this->actingAs($user)->get('/admin/today');

        $res->assertOk();
        $res->assertSee('davya-today', false);             // wrapper
        $res->assertSee('dt-stats', false);                // stats strip
        $res->assertSee('Meetings today', false);          // section label
        $res->assertSee('Shubham', false);                 // row
        $res->assertSee("open-student-peek', { studentId: {$student->id} }", false); // row dispatch
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php -d memory_limit=2048M vendor/bin/phpunit --filter TodayChecklistRenderTest`
Expected: FAIL — `davya-today` / `dt-stats` not seen.

- [ ] **Step 3: Rewrite the page view**

Replace the entire contents of `resources/views/filament/pages/today-page.blade.php` with:

```blade
@php
    use App\Today\ChecklistSections;
    use App\Today\SectionRegistry;

    $cards = $this->cards();
    $statCards = array_values(array_filter($cards, fn ($c) => $c->type() === 'stat'));
    $listCards = array_values(array_filter($cards, fn ($c) => $c->type() === 'list'));
    $sections  = app(ChecklistSections::class);
    $viewer    = auth()->user();
@endphp

<x-filament-panels::page>
    <div class="davya-today">
        <div class="dt-h">
            <div>
                <div class="t">Today</div>
                <div class="d">{{ now('Asia/Kolkata')->format('l, j F Y') }}</div>
            </div>
            <button type="button"
                    wire:click="$dispatch('open-customize-modal', { surface: 'today' })"
                    class="davya-action davya-action--solid">Customize</button>
        </div>

        @if (count($cards) === 0)
            <div class="dt-sec"><div class="dt-clear" style="text-align:center;">
                You've hidden all cards.
                <button type="button" wire:click="$dispatch('reset-cards-to-defaults', { surface: 'today' })"
                        class="davya-action" style="text-decoration:underline;">Reset to defaults</button>
            </div></div>
        @else
            {{-- stats strip --}}
            @if (count($statCards))
                <div class="dt-stats">
                    @foreach ($statCards as $card)
                        <button type="button" class="dt-stat"
                                wire:click="$dispatch('open-slide-over', { cardId: '{{ $card->id() }}' })">
                            {!! $card->render($viewer) !!}
                        </button>
                    @endforeach
                </div>
            @endif

            {{-- checklist sections, in prefs order --}}
            @foreach ($listCards as $card)
                @php($d = SectionRegistry::descriptor($card->id()))
                @if ($d)
                    @include('filament.pages.partials.checklist-section', [
                        'id'     => $card->id(),
                        'label'  => $d['label'],
                        'icon'   => $d['icon'],
                        'urgent' => $d['urgent'],
                        'rows'   => $sections->forCard($card->id(), $viewer),
                    ])
                @endif
            @endforeach
        @endif
    </div>

    @livewire(\App\Livewire\StudentSlideOver::class)
    @livewire(\App\Livewire\CustomizeCardsModal::class)

    <div
        x-data="{ toast: null }"
        x-on:card-removed.window="toast = $event.detail; setTimeout(() => { if (toast === $event.detail) toast = null }, 8000);"
        style="position: fixed; bottom: 16px; right: 16px; z-index: 9997;"
    >
        <template x-if="toast">
            <div style="display:flex;align-items:center;gap:12px;padding:10px 16px;background:#111827;color:#fff;border-radius:6px;box-shadow:0 4px 12px rgba(0,0,0,.25);font-size:13px;">
                <span>Removed <span x-text="toast.cardId"></span>.</span>
                <button type="button" class="davya-action davya-action--ghost-light"
                        x-on:click="$wire.dispatch('undo-remove', { surface: toast.surface, cardId: toast.cardId, position: toast.position }); toast = null;">Undo</button>
            </div>
        </template>
    </div>
</x-filament-panels::page>
```

> Note: the stat strip renders each stat card's existing `render()` HTML inside a `.dt-stat` button. If the stat-body markup looks heavy under the strip, that's cosmetic and handled by `today-skin.css` overriding inner styles — not a logic change.

- [ ] **Step 4: Run test to verify it passes**

Run: `php -d memory_limit=2048M vendor/bin/phpunit --filter TodayChecklistRenderTest`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add resources/views/filament/pages/today-page.blade.php tests/Feature/MobileToday/TodayChecklistRenderTest.php
git commit -m "feat(today): action-checklist page view (stats strip + sections)"
```

---

### Task 8: Customize parity + empty-state tests

**Files:**
- Test: `tests/Feature/MobileToday/TodayCustomizeParityTest.php`

- [ ] **Step 1: Write the tests**

```php
<?php

namespace Tests\Feature\MobileToday;

use App\Models\User;
use Tests\TestCase;

class TodayCustomizeParityTest extends TestCase
{
    public function test_hiding_a_card_removes_its_section(): void
    {
        $user = User::factory()->create(['role' => 'admin']);

        // Sanity: section visible by default.
        $this->actingAs($user)->get('/admin/today')->assertSee('Stuck leads', false);

        // Hide the stuck_leads card via the same prefs path Customize uses.
        \App\Models\DashboardCardPref::query()->updateOrCreate(
            ['user_id' => $user->id, 'surface' => 'today', 'card_id' => 'stuck_leads'],
            ['is_visible' => false, 'position' => 99],
        );

        $this->actingAs($user)->get('/admin/today')->assertDontSee('Stuck leads', false);
    }
}
```

> **Before writing:** confirm the prefs model + columns. Run `grep -rn "class.*Pref" app/Models` and inspect the table; adjust the model name / column names (`is_visible`, `surface`, `card_id`, `position`) in the test to match. If prefs are stored differently (e.g. a JSON column on `users`), set them the way `CustomizeCardsModal` does — read that component to mirror its write path.

- [ ] **Step 2: Run test to verify it fails or passes**

Run: `php -d memory_limit=2048M vendor/bin/phpunit --filter TodayCustomizeParityTest`
Expected: PASS (the page already iterates `$this->cards()`, which respects prefs). If it fails because the default section isn't shown (no data), seed a stuck student first or assert on the section label only (labels render even for empty sections unless count===0 collapses them — the label still renders, so `assertSee` on the label holds).

- [ ] **Step 3: Commit**

```bash
git add tests/Feature/MobileToday/TodayCustomizeParityTest.php
git commit -m "test(today): customize hides a checklist section (parity)"
```

---

### Task 9: Full-suite gate + pint + push

- [ ] **Step 1: Run the full suite**

Run: `php -d memory_limit=2048M vendor/bin/phpunit`
Expected: 0 failures, 0 errors (skips/deprecations OK). New MobileToday tests all green; existing 919 unaffected.

- [ ] **Step 2: Pint the new PHP files only**

Run: `vendor/bin/pint app/Today/ app/Dashboard/Cards/ListCards/PaymentsToChaseCard.php tests/Feature/MobileToday/`
Then verify: `vendor/bin/pint --test app/Today/ tests/Feature/MobileToday/`
Expected: `pass`. (Do not pint pre-existing files — they ship non-pint-clean by repo convention; only format the files this plan creates.)

- [ ] **Step 3: Local browser/curl smoke**

Run a local server and authed curl (mirror the Pipeline deploy gate):
```bash
php artisan serve --port=8902 >/tmp/today_serve.log 2>&1 &
sleep 4
curl -s -c /tmp/tc.txt http://127.0.0.1:8902/dev-login -o /dev/null
curl -s -b /tmp/tc.txt -L http://127.0.0.1:8902/admin/today -o /tmp/today.html -w "today:%{http_code}\n"
grep -c "davya-today" /tmp/today.html      # >=1
grep -c "dt-sec" /tmp/today.html           # >=1 section
grep -c "open-student-peek" /tmp/today.html
curl -s -b /tmp/tc.txt -L http://127.0.0.1:8902/admin/students -o /tmp/st.html
grep -c "today-skin.css" /tmp/st.html      # expect 0 (no leak)
pkill -f "artisan serve --port=8902"
```
Expected: `today:200`, `davya-today` and `dt-sec` present, `open-student-peek` present, 0 leak on students.

- [ ] **Step 4: Commit any pint changes + push**

```bash
git add -A
git commit -m "style(today): pint new files" --allow-empty
git push -u origin feat/today-mobile-redesign
```

- [ ] **Step 5: STOP — hand back for deploy decision**

Do NOT merge/deploy. Report: suite result, smoke output, and that the surface is code-complete pending Sumit's mobile browser pass → then merge + full deploy recipe (same as Pipeline). Per `feedback_no_auto_publish_to_customer_facing` and the program pattern, deploy is a separate, explicitly-authorized step.

---

## Self-Review

**Spec coverage:**
- Stats strip → Task 7 (stat cards rendered in `.dt-stats`). ✓
- Six list sections incl. two payment sections → Tasks 1, 3, 4, 6, 7. ✓
- New `PaymentsToChaseCard` → Task 1. ✓
- Default-on fix for 3 watchlist cards → Task 2. ✓
- `ChecklistSections` + `SectionRegistry` → Tasks 3, 4. ✓
- Scoped skin + page-scoped render hook → Task 5. ✓
- Row tap → `open-student-peek` (peek drawer, same as Pipeline) → Task 6/7. ✓
- Stat tap → `open-slide-over` (StudentSlideOver drill-down kept) → Task 7. ✓
- Customize / undo / CustomizeCardsModal preserved → Task 7 + parity test Task 8. ✓
- Empty state + all-hidden state → Task 6 (partial) + Task 7 (all-hidden block). ✓
- Desktop centered max-width 720px → Task 5 CSS (`.davya-today{max-width:720px;margin:0 auto}`). ✓
- Tests under `tests/Feature/MobileToday/` → Tasks 1–8. ✓
- Full suite stays green → Task 9. ✓

**Placeholder scan:** No TBD/TODO; every code step has complete code. The two "confirm the factory/prefs shape" notes (Task 3 Step 4, Task 8) are explicit verification instructions with a concrete fallback, not placeholders.

**Type consistency:** Row array keys (`student_id/title/subtitle/time/amount/dot/pill`) are identical across `ChecklistSections` (Task 3) and the partial (Task 6). `forCard(string,User)`, `query(User):Builder`, `descriptor(string):?array`, `all():array` signatures match across tasks. Card id strings (`payments_to_chase`, `today_meetings`, `today_payments`, `stuck_leads`, `seat_fee_pending`, `re_entry_candidates`) consistent throughout and verified against the real classes.

**Known verification points for the implementer** (flagged, not assumed): `Meeting`/`Payment`/`Student` factory field names (Task 3 Step 4); the dashboard-prefs model/columns (Task 8 Step 1); `MoneyFormat::toIndianWords` signature (Task 6 Step 2). Each step says exactly what to check and how to adapt the test (never the production logic) if reality differs.
