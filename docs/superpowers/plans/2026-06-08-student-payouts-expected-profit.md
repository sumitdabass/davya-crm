# Student Payouts & Expected Profit Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Track money paid out to colleges/other parties per student as line-items, and surface Expected Profit (Deal − committed payouts) on the student form, the students list, and the Payment Report.

**Architecture:** A new `payouts` sub-table mirrors `payments` but represents outflow, with a `status` (`to_pay`/`paid`) distinguishing "to be paid" from "paid". `Student` gains payout-sum + `expected_profit` accessors. The Deal tab gets a relationship-bound Repeater plus a live profit preview. The list column sorts via a DB subquery; the Payment Report adds profit rollup tiles.

**Tech Stack:** Laravel 11, Filament 3, PHPUnit, MySQL (prod) / SQLite (local tests). Spec: `docs/superpowers/specs/2026-06-08-student-payouts-expected-profit-design.md`.

**Baseline:** suite is 884 passing / 1 skipped. Run the full suite with `php -d memory_limit=2G ./vendor/bin/phpunit` (the `artisan test` wrapper hits a 128M child-process memory cap and ParaTest is not installed). Run a single file with `php -d memory_limit=2G ./vendor/bin/phpunit tests/Feature/SomeTest.php`.

---

### Task 1: `payouts` table, `Payout` model, factory, Student relation + accessors

**Files:**
- Create: `database/migrations/2026_06_08_000100_create_payouts_table.php`
- Create: `app/Models/Payout.php`
- Create: `database/factories/PayoutFactory.php`
- Modify: `app/Models/Student.php` (add relation + 4 accessors + `withExpectedProfit` scope; the `expected_profit` accessor at lines 91-99 sits alongside `getTotalReceivedAttribute`/`getPendingAmountAttribute`)
- Test: `tests/Feature/PayoutModelTest.php`

- [ ] **Step 1: Write the migration**

Create `database/migrations/2026_06_08_000100_create_payouts_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payouts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->enum('payee_type', ['college', 'other'])->default('college');
            $table->string('payee_name', 120)->nullable();
            $table->decimal('amount', 12, 2);
            $table->enum('status', ['to_pay', 'paid'])->default('to_pay');
            $table->dateTime('paid_at')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('recorded_by_user_id')->constrained('users');
            $table->timestamps();
            $table->index(['student_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payouts');
    }
};
```

- [ ] **Step 2: Write the `Payout` model**

Create `app/Models/Payout.php` (mirrors `app/Models/Payment.php`):

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Payout extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected $casts = [
        'amount' => 'decimal:2',
        'paid_at' => 'datetime',
    ];

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by_user_id');
    }

    protected static function booted(): void
    {
        static::saving(function (Payout $p) {
            $p->amount = abs((float) $p->amount); // payouts are never negative
            if ($p->status === 'paid' && $p->paid_at === null) {
                $p->paid_at = now();
            }
            if ($p->status === 'to_pay') {
                $p->paid_at = null;
            }
        });
    }
}
```

- [ ] **Step 3: Write the factory**

Create `database/factories/PayoutFactory.php`:

```php
<?php

namespace Database\Factories;

use App\Models\Payout;
use App\Models\Student;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class PayoutFactory extends Factory
{
    protected $model = Payout::class;

    public function definition(): array
    {
        return [
            'student_id' => Student::factory(),
            'payee_type' => 'college',
            'payee_name' => $this->faker->company(),
            'amount' => $this->faker->numberBetween(1000, 50000),
            'status' => 'to_pay',
            'paid_at' => null,
            'notes' => null,
            'recorded_by_user_id' => User::factory(),
        ];
    }

    public function paid(): static
    {
        return $this->state(fn () => ['status' => 'paid', 'paid_at' => now()]);
    }
}
```

- [ ] **Step 4: Add relation, accessors, and scope to `Student`**

In `app/Models/Student.php`, add the relation next to the other `HasMany` relations (after `payments()` at line 78):

```php
    public function payouts(): HasMany { return $this->hasMany(Payout::class); }
```

Add these accessors next to `getPendingAmountAttribute` (after line 99):

```php
    public function getTotalPayoutsAttribute(): float
    {
        return (float) $this->payouts()->sum('amount');
    }

    public function getPayoutsPaidAttribute(): float
    {
        return (float) $this->payouts()->where('status', 'paid')->sum('amount');
    }

    public function getPayoutsOutstandingAttribute(): float
    {
        return $this->total_payouts - $this->payouts_paid;
    }

    public function getExpectedProfitAttribute(): float
    {
        // Prefer a value already selected by withExpectedProfit() to avoid N+1 on the list.
        if (array_key_exists('expected_profit', $this->attributes)) {
            return (float) $this->attributes['expected_profit'];
        }

        return (float) ($this->deal_amount ?? 0) - $this->total_payouts;
    }
```

Add the scope near the other scopes (after `scopeVisibleTo`, around line 27). Confirm `use Illuminate\Database\Eloquent\Builder;` is already imported (it is — used by `scopeStuck`):

```php
    public function scopeWithExpectedProfit(Builder $query): Builder
    {
        return $query
            ->select('students.*')
            ->selectRaw('students.deal_amount - COALESCE((SELECT SUM(amount) FROM payouts WHERE payouts.student_id = students.id), 0) AS expected_profit');
    }
```

- [ ] **Step 5: Write the failing test**

Create `tests/Feature/PayoutModelTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Payout;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PayoutModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_amount_is_forced_positive_and_paid_at_set_when_paid(): void
    {
        $student = Student::factory()->create(['deal_amount' => 100000]);
        $user = User::factory()->create();

        $payout = Payout::create([
            'student_id' => $student->id,
            'payee_type' => 'college',
            'amount' => -5000,
            'status' => 'paid',
            'recorded_by_user_id' => $user->id,
        ]);

        $this->assertEquals(5000.0, (float) $payout->amount);
        $this->assertNotNull($payout->paid_at);
    }

    public function test_to_pay_clears_paid_at(): void
    {
        $student = Student::factory()->create();
        $user = User::factory()->create();

        $payout = Payout::factory()->paid()->create([
            'student_id' => $student->id,
            'recorded_by_user_id' => $user->id,
        ]);
        $payout->update(['status' => 'to_pay']);

        $this->assertNull($payout->fresh()->paid_at);
    }

    public function test_profit_accessors(): void
    {
        $student = Student::factory()->create(['deal_amount' => 100000]);
        $user = User::factory()->create();

        Payout::factory()->create(['student_id' => $student->id, 'amount' => 30000, 'status' => 'to_pay', 'recorded_by_user_id' => $user->id]);
        Payout::factory()->paid()->create(['student_id' => $student->id, 'amount' => 20000, 'recorded_by_user_id' => $user->id]);

        $student->refresh();
        $this->assertEquals(50000.0, $student->total_payouts);
        $this->assertEquals(20000.0, $student->payouts_paid);
        $this->assertEquals(30000.0, $student->payouts_outstanding);
        $this->assertEquals(50000.0, $student->expected_profit); // 100000 - 50000
    }

    public function test_with_expected_profit_scope_selects_and_sorts(): void
    {
        $user = User::factory()->create();
        $a = Student::factory()->create(['deal_amount' => 100000]);
        $b = Student::factory()->create(['deal_amount' => 100000]);
        Payout::factory()->create(['student_id' => $a->id, 'amount' => 80000, 'recorded_by_user_id' => $user->id]); // profit 20k
        Payout::factory()->create(['student_id' => $b->id, 'amount' => 10000, 'recorded_by_user_id' => $user->id]); // profit 90k

        $rows = Student::withExpectedProfit()->orderBy('expected_profit', 'desc')->get();

        $this->assertEquals($b->id, $rows->first()->id);
        $this->assertEquals(20000.0, $rows->firstWhere('id', $a->id)->expected_profit);
    }
}
```

- [ ] **Step 6: Run the test — expect failure**

Run: `php -d memory_limit=2G ./vendor/bin/phpunit tests/Feature/PayoutModelTest.php`
Expected: FAIL (no `payouts` table / `Payout` class) until migration + model are in place. After Steps 1-4 are saved, run again.

- [ ] **Step 7: Run the test — expect pass**

Run: `php -d memory_limit=2G ./vendor/bin/phpunit tests/Feature/PayoutModelTest.php`
Expected: PASS (4 tests).

- [ ] **Step 8: Commit**

```bash
git add database/migrations/2026_06_08_000100_create_payouts_table.php app/Models/Payout.php database/factories/PayoutFactory.php app/Models/Student.php tests/Feature/PayoutModelTest.php
git commit -m "feat(payouts): payouts table, model, and Student profit accessors"
```

---

### Task 2: Update `plan` dropdown options

**Files:**
- Create: `database/migrations/2026_06_08_000200_update_plan_field_options.php`
- Modify: `database/migrations/2026_04_24_010400_seed_live_form_sections_and_fields.php:66` (so a fresh `migrate:fresh` seeds the new options)
- Modify: `app/Filament/Resources/StudentResource.php:231` and `:485` (fallback list)
- Test: `tests/Feature/PlanOptionsTest.php`

- [ ] **Step 1: Write the data migration**

Create `database/migrations/2026_06_08_000200_update_plan_field_options.php`:

```php
<?php

use App\Models\StudentField;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        StudentField::where('key', 'plan')->update([
            'options' => ['Sitting', 'Counselling Online', 'Counselling Offline'],
        ]);
    }

    public function down(): void
    {
        StudentField::where('key', 'plan')->update([
            'options' => ['Online', 'Offline', 'All'],
        ]);
    }
};
```

- [ ] **Step 2: Update the seed migration's plan options**

In `database/migrations/2026_04_24_010400_seed_live_form_sections_and_fields.php:66`, change the `plan` field's `'options'` from `$label(['Online','Offline','All'])` to `$label(['Sitting','Counselling Online','Counselling Offline'])`.

- [ ] **Step 3: Update the two `optionsFor` fallbacks in StudentResource**

In `app/Filament/Resources/StudentResource.php`, change both occurrences (`:231` form Select and `:485` SelectFilter) from `self::optionsFor('plan', ['Online', 'Offline', 'All'])` to `self::optionsFor('plan', ['Sitting', 'Counselling Online', 'Counselling Offline'])`.

- [ ] **Step 4: Write the test**

Create `tests/Feature/PlanOptionsTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\StudentField;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlanOptionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_plan_field_has_new_options(): void
    {
        $options = StudentField::where('key', 'plan')->value('options');

        $this->assertEqualsCanonicalizing(
            ['Sitting', 'Counselling Online', 'Counselling Offline'],
            $options
        );
    }
}
```

- [ ] **Step 5: Run the test — expect pass**

Run: `php -d memory_limit=2G ./vendor/bin/phpunit tests/Feature/PlanOptionsTest.php`
Expected: PASS. (RefreshDatabase runs all migrations including the new data migration; the seed migration sets it and the data migration is idempotent.)

- [ ] **Step 6: Commit**

```bash
git add database/migrations/2026_06_08_000200_update_plan_field_options.php database/migrations/2026_04_24_010400_seed_live_form_sections_and_fields.php app/Filament/Resources/StudentResource.php tests/Feature/PlanOptionsTest.php
git commit -m "feat(students): plan options -> Sitting / Counselling Online / Counselling Offline"
```

---

### Task 3: Payouts Repeater + live profit preview in the Deal tab

**Files:**
- Modify: `app/Filament/Resources/StudentResource.php` (imports near line 24-31; Deal tab schema at lines 229-266)
- Test: `tests/Feature/StudentPayoutFormTest.php`

- [ ] **Step 1: Add imports**

In `app/Filament/Resources/StudentResource.php`, add these to the `Filament\Forms\Components` import block (alphabetical, near lines 24-31):

```php
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Get;
use Illuminate\Support\HtmlString;
```

- [ ] **Step 2: Add the Repeater + profit preview to the Deal tab schema**

In the Deal tab schema array (the first array passed to `array_merge` at line 229, after the `deal_amount` TextInput at line 230), insert:

```php
                            Repeater::make('payouts')
                                ->relationship()
                                ->label('Payouts (to college / other)')
                                ->columnSpanFull()
                                ->defaultItems(0)
                                ->addActionLabel('Add payout')
                                ->schema([
                                    Select::make('payee_type')
                                        ->options(['college' => 'College', 'other' => 'Other'])
                                        ->default('college')
                                        ->required(),
                                    TextInput::make('payee_name')
                                        ->label('Payee name')
                                        ->placeholder('College / party name')
                                        ->maxLength(120),
                                    TextInput::make('amount')->numeric()->prefix('₹')->required(),
                                    Select::make('status')
                                        ->options(['to_pay' => 'To be paid', 'paid' => 'Paid'])
                                        ->default('to_pay')
                                        ->live()
                                        ->required(),
                                    DateTimePicker::make('paid_at')
                                        ->label('Paid on')
                                        ->visible(fn (Get $get) => $get('status') === 'paid'),
                                ])
                                ->columns(['default' => 1, 'md' => 2])
                                ->mutateRelationshipDataBeforeCreateUsing(function (array $data): array {
                                    $data['recorded_by_user_id'] = auth()->id();

                                    return $data;
                                })
                                ->live(),
                            Placeholder::make('expected_profit_preview')
                                ->label('Expected profit')
                                ->columnSpanFull()
                                ->content(function (Get $get): HtmlString {
                                    $deal = (float) ($get('deal_amount') ?? 0);
                                    $payouts = collect($get('payouts') ?? [])
                                        ->sum(fn ($row) => (float) ($row['amount'] ?? 0));
                                    $profit = $deal - $payouts;

                                    return new HtmlString(
                                        '₹'.number_format($deal, 0).' deal − ₹'.number_format($payouts, 0).' payouts = '
                                        .\App\Support\MoneyFormat::asInlineHtml($profit, $profit < 0, true)
                                    );
                                }),
```

- [ ] **Step 3: Write the test**

Create `tests/Feature/StudentPayoutFormTest.php`. This uses Livewire to exercise the CreateStudent page form and asserts a payout persists via the relationship and `recorded_by_user_id` is stamped. Follow the pattern in `tests/Feature/PaymentsRelationManagerTest.php` for authenticating + mounting Filament pages.

```php
<?php

namespace Tests\Feature;

use App\Models\Payout;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StudentPayoutFormTest extends TestCase
{
    use RefreshDatabase;

    public function test_payout_persists_via_relationship_and_stamps_recorder(): void
    {
        $user = User::factory()->create();
        $student = Student::factory()->create(['deal_amount' => 100000, 'owner_id' => $user->id]);

        // Simulate what the Repeater relationship + mutateRelationshipDataBeforeCreateUsing do:
        $this->actingAs($user);
        $payout = $student->payouts()->create([
            'payee_type' => 'college',
            'payee_name' => 'GGSIPU',
            'amount' => 40000,
            'status' => 'to_pay',
            'recorded_by_user_id' => $user->id,
        ]);

        $this->assertDatabaseHas('payouts', [
            'id' => $payout->id,
            'student_id' => $student->id,
            'payee_type' => 'college',
            'amount' => 40000,
            'recorded_by_user_id' => $user->id,
        ]);
        $this->assertEquals(60000.0, $student->refresh()->expected_profit);
    }
}
```

Note: a full Livewire form-fill test for the Repeater is brittle across Filament versions; this test locks the relationship contract + accessor. If `tests/Feature/PaymentsRelationManagerTest.php` shows a stable Livewire fill pattern for repeaters, add a second test that fills the create form and asserts the payout row saves — otherwise this contract test is sufficient.

- [ ] **Step 4: Run the test — expect pass**

Run: `php -d memory_limit=2G ./vendor/bin/phpunit tests/Feature/StudentPayoutFormTest.php`
Expected: PASS.

- [ ] **Step 5: Sanity-check the form renders**

Run: `php -d memory_limit=2G ./vendor/bin/phpunit tests/Feature/ListStudentsPageTest.php` (and any CreateStudent page test if present) to confirm the new imports + schema don't break page mount.
Expected: PASS (no regressions).

- [ ] **Step 6: Commit**

```bash
git add app/Filament/Resources/StudentResource.php tests/Feature/StudentPayoutFormTest.php
git commit -m "feat(students): payouts repeater + live expected-profit preview in Deal tab"
```

---

### Task 4: Expected Profit column on the students list

**Files:**
- Modify: `app/Filament/Resources/StudentResource.php` (table columns after the `pending_amount` column at lines 419-422; `getEloquentQuery` at lines 566-569)
- Test: `tests/Feature/ListStudentsPageTest.php` (append) or new `tests/Feature/StudentProfitColumnTest.php`

- [ ] **Step 1: Wire the scope into `getEloquentQuery`**

In `app/Filament/Resources/StudentResource.php:566-569`, change to:

```php
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->visibleTo(auth()->user())
            ->withExpectedProfit();
    }
```

- [ ] **Step 2: Add the column**

After the `pending_amount` column (ends at line 422), add:

```php
            TextColumn::make('expected_profit')->label('Profit')->sortable()
                ->alignEnd()
                ->toggleable()
                ->formatStateUsing(fn ($state) => \App\Support\MoneyFormat::asInlineHtml((float) $state, (float) $state < 0))->html(),
```

- [ ] **Step 3: Add mobile column-shedding**

This repo hides rank/state/email/updated_at on the students list at ≤640px (per the mobile column-shedding work). Find the CSS rule that hides those columns (grep `davya-compact` / a `@media (max-width: 640px)` block in `resources/css/tokens.css` or the StudentResource-related stylesheet) and add the Profit column's `<th>/<td>` nth selector to the same hide rule so Profit sheds on phones too. If the shedding is done by column-name CSS class, add the matching class. If no such rule is found in CSS (it may be done via Filament `->visibleFrom('sm')`), instead append `->visibleFrom('md')` to the column from Step 2 and skip the CSS edit.

- [ ] **Step 4: Write the test**

Create `tests/Feature/StudentProfitColumnTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Filament\Resources\StudentResource\Pages\ListStudents;
use App\Models\Payout;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class StudentProfitColumnTest extends TestCase
{
    use RefreshDatabase;

    public function test_list_sorts_by_expected_profit(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin'); // match the auth pattern used in ListStudentsPageTest
        $this->actingAs($admin);

        $low = Student::factory()->create(['deal_amount' => 100000, 'owner_id' => $admin->id]);
        $high = Student::factory()->create(['deal_amount' => 100000, 'owner_id' => $admin->id]);
        Payout::factory()->create(['student_id' => $low->id, 'amount' => 90000, 'recorded_by_user_id' => $admin->id]);  // profit 10k
        Payout::factory()->create(['student_id' => $high->id, 'amount' => 10000, 'recorded_by_user_id' => $admin->id]); // profit 90k

        Livewire::test(ListStudents::class)
            ->sortTable('expected_profit', 'desc')
            ->assertCanSeeTableRecords([$high, $low], inOrder: true);
    }
}
```

Note: copy the exact auth/role-assignment setup from `tests/Feature/ListStudentsPageTest.php` (role name, whether `assignRole` or a factory state is used) so the acting user can see the records. Adjust the role line accordingly.

- [ ] **Step 5: Run the test — expect pass**

Run: `php -d memory_limit=2G ./vendor/bin/phpunit tests/Feature/StudentProfitColumnTest.php tests/Feature/ListStudentsPageTest.php`
Expected: PASS (new test + no regression in existing list test).

- [ ] **Step 6: Commit**

```bash
git add app/Filament/Resources/StudentResource.php tests/Feature/StudentProfitColumnTest.php
git commit -m "feat(students): sortable Expected Profit column on the list"
```

---

### Task 5: Profit rollup on the Payment Report

**Files:**
- Modify: `app/Filament/Pages/PaymentReport.php` (`getReport()` at lines 133-194)
- Modify: `resources/views/filament/pages/payment-report.blade.php` (after the totals grid that ends ~line 88)
- Test: `tests/Feature/PaymentReportTest.php` (append a profit-rollup test)

**Scope decision (locked):** the profit rollup is over students that are `visibleTo` the user, match the owner filter, and whose `created_at` falls inside the report's `[from, to]` window — i.e. "profit on deals opened in this period". Payout sums are taken for exactly those students (not date-filtered on the payout itself).

- [ ] **Step 1: Add the profit block to `getReport()`**

In `app/Filament/Pages/PaymentReport.php`, inside `getReport()` after `$byType` is built (before the `return` at line 184), add. Reference `Student` and `Payout` with full namespaces or add `use App\Models\Student; use App\Models\Payout;` at the top:

```php
        $studentBase = \App\Models\Student::query()
            ->visibleTo($user)
            ->whereBetween('created_at', [$from, $to]);
        if (! empty($ownerIds)) {
            $studentBase->whereIn('owner_id', $ownerIds);
        }

        $totalDeal = (float) (clone $studentBase)->sum('deal_amount');
        $studentIds = (clone $studentBase)->pluck('id');
        $committedPayouts = (float) \App\Models\Payout::whereIn('student_id', $studentIds)->sum('amount');
        $paidPayouts = (float) \App\Models\Payout::whereIn('student_id', $studentIds)->where('status', 'paid')->sum('amount');
```

Then add a `'profit'` key to the returned array (alongside `'totals'`, `'byOwner'`, `'byType'`):

```php
            'profit' => [
                'total_deal'       => $totalDeal,
                'committed'        => $committedPayouts,
                'paid_out'         => $paidPayouts,
                'expected_profit'  => $totalDeal - $committedPayouts,
                'outstanding'      => $committedPayouts - $paidPayouts,
            ],
```

- [ ] **Step 2: Add per-owner expected profit to the `byOwner` loop**

Inside the `foreach (User::orderBy('name')->get() as $u)` loop (lines 156-177), before building `$byOwner[$u->id]`, compute the owner's profit over the same student window:

```php
            $ownerStudentIds = \App\Models\Student::query()
                ->visibleTo($user)
                ->where('owner_id', $u->id)
                ->whereBetween('created_at', [$from, $to])
                ->pluck('id');
            $ownerDeal = (float) \App\Models\Student::whereIn('id', $ownerStudentIds)->sum('deal_amount');
            $ownerCommitted = (float) \App\Models\Payout::whereIn('student_id', $ownerStudentIds)->sum('amount');
            $ownerProfit = $ownerDeal - $ownerCommitted;
```

Add `'expected_profit' => $ownerProfit,` to the `$byOwner[$u->id]` array. Keep the existing `continue` skip condition as-is (owners with no payments still skip; that is acceptable for v1 — the report row is payment-driven).

- [ ] **Step 3: Write the failing test**

Append to `tests/Feature/PaymentReportTest.php` a test (mirror the existing setup/auth in that file for constructing the page + applying filters):

```php
    public function test_report_includes_expected_profit_rollup(): void
    {
        // Follow this file's existing auth + page-construction pattern.
        $owner = \App\Models\User::factory()->create();
        $student = \App\Models\Student::factory()->create(['deal_amount' => 100000, 'owner_id' => $owner->id]);
        \App\Models\Payout::factory()->create(['student_id' => $student->id, 'amount' => 30000, 'status' => 'to_pay', 'recorded_by_user_id' => $owner->id]);
        \App\Models\Payout::factory()->paid()->create(['student_id' => $student->id, 'amount' => 20000, 'recorded_by_user_id' => $owner->id]);
        \App\Models\Payment::factory()->create(['student_id' => $student->id, 'amount' => 25000, 'type' => 'advance', 'received_at' => now(), 'recorded_by_user_id' => $owner->id]);

        $this->actingAs($owner);
        $page = new \App\Filament\Pages\PaymentReport;
        $page->data = ['from' => now()->startOfMonth()->toDateString(), 'to' => now()->toDateString(), 'owner_ids' => []];
        $report = $page->getReport();

        $this->assertEquals(100000.0, $report['profit']['total_deal']);
        $this->assertEquals(50000.0, $report['profit']['committed']);
        $this->assertEquals(20000.0, $report['profit']['paid_out']);
        $this->assertEquals(50000.0, $report['profit']['expected_profit']);   // 100000 - 50000
        $this->assertEquals(30000.0, $report['profit']['outstanding']);        // 50000 - 20000
    }
```

Adjust page construction to match how other tests in `PaymentReportTest.php` instantiate the page (they may use `Livewire::test(PaymentReport::class)` + `->call('getReport')` instead of `new`). Use whichever the file already establishes.

- [ ] **Step 4: Run the test — expect pass**

Run: `php -d memory_limit=2G ./vendor/bin/phpunit tests/Feature/PaymentReportTest.php`
Expected: PASS (new test + existing report tests still green).

- [ ] **Step 5: Add profit tiles to the blade**

In `resources/views/filament/pages/payment-report.blade.php`, after the existing totals grid (the `<div class="mt-6 grid ... md:grid-cols-4 ...">` block that ends ~line 88), add a second tile grid using the same `davya-books-kpi` + `<x-book-amount>` markup:

```blade
        {{-- Profit rollup (deal − payouts) --}}
        <div class="mt-4 grid grid-cols-1 md:grid-cols-4 gap-3">
            <div class="davya-books-kpi">
                <div class="davya-books-kpi__label">Total deal</div>
                <x-book-amount :v="(float) $r['profit']['total_deal']" big />
            </div>
            <div class="davya-books-kpi">
                <div class="davya-books-kpi__label">Paid out</div>
                <x-book-amount :v="(float) $r['profit']['paid_out']" big />
            </div>
            <div class="davya-books-kpi">
                <div class="davya-books-kpi__label">Outstanding payouts</div>
                <x-book-amount :v="(float) $r['profit']['outstanding']" big :danger="(float) $r['profit']['outstanding'] > 0" />
            </div>
            <div class="davya-books-kpi">
                <div class="davya-books-kpi__label">Expected profit</div>
                <x-book-amount :v="(float) $r['profit']['expected_profit']" big :danger="(float) $r['profit']['expected_profit'] < 0" />
            </div>
        </div>
```

Then add an Expected-profit cell to the by-owner table (the `@forelse ($r['byOwner'] ...)` block around line 103): add a header `<th>Profit</th>` and a row cell `<td>₹{{ number_format($row['expected_profit'] ?? 0, 0, '.', ',') }}</td>` mirroring the existing `received` cell's markup at line 108.

- [ ] **Step 6: Confirm the blade renders**

Run: `php -d memory_limit=2G ./vendor/bin/phpunit tests/Feature/PaymentReportTabsTest.php tests/Feature/PaymentReportTest.php`
Expected: PASS (page renders with the new data keys; no undefined-index errors).

- [ ] **Step 7: Commit**

```bash
git add app/Filament/Pages/PaymentReport.php resources/views/filament/pages/payment-report.blade.php tests/Feature/PaymentReportTest.php
git commit -m "feat(reports): expected-profit rollup on Payment Report"
```

---

### Task 6: Full-suite verification

- [ ] **Step 1: Run the entire suite**

Run: `php -d memory_limit=2G ./vendor/bin/phpunit`
Expected: all previously-passing tests still pass (884 baseline + new tests), 0 failures, 1 pre-existing skip. The 4 PHP/PHPUnit deprecations are pre-existing and expected.

- [ ] **Step 2: Pint format the touched files**

Run: `./vendor/bin/pint app/Models/Payout.php app/Models/Student.php app/Filament/Resources/StudentResource.php app/Filament/Pages/PaymentReport.php database/factories/PayoutFactory.php`
Then commit any formatting: `git add -A && git commit -m "style: pint on payouts feature"` (skip if no changes).

- [ ] **Step 3: Deploy (Sumit / pre-deploy gate)**

Do NOT deploy without Sumit's go-ahead. When approved, follow the full davya-crm recipe (no shortcuts): SSH `ssh -i ~/.ssh/davyas-active ipuc@ipu.co.in` → `cd ~/davya-crm` → `git pull` → `composer install --no-dev --optimize-autoloader` → `php artisan migrate` (applies `create_payouts_table` + `update_plan_field_options`) → run the 3 rank seeders (idempotent) → `php artisan config:cache && route:cache && view:cache`. New `Payout` model is not a Filament page/resource class, so FPM opcache new-class discovery is not a concern; verify `/admin/students=302`, `/admin/payments-report=302`, `/admin/login=200` via curl.

---

## Self-Review

**Spec coverage:**
- §Data model `payouts` table → Task 1 ✓
- §Profit accessors (total_payouts, payouts_paid, payouts_outstanding, expected_profit) → Task 1 ✓
- §Form Payouts repeater + live profit → Task 3 ✓
- §Plan dropdown update → Task 2 ✓
- §Students list column (sortable, DB subquery) → Task 1 (scope) + Task 4 (column) ✓
- §Payment Report rollup (deal/paid-out/committed/profit/outstanding + by-owner) → Task 5 ✓
- §Testing → tests in every task + Task 6 full-suite ✓
- §Out of scope (Agent, proof upload, cash-in-hand, Books, refunds) → not implemented ✓

**Type/name consistency:** `payouts` relation, `expected_profit` accessor + SQL alias (same name, accessor prefers selected attribute), `withExpectedProfit()` scope, `status` values `to_pay`/`paid`, `payee_type` values `college`/`other`, report `'profit'` array keys (`total_deal`/`committed`/`paid_out`/`expected_profit`/`outstanding`) — all consistent across tasks 1, 4, 5 and the blade.

**Placeholder scan:** no TBD/TODO; every code step shows full code. Two tasks (3 Step 3, 4 Step 4, 5 Step 3) instruct copying the established auth/Livewire pattern from a named existing test file rather than reproducing it blind — this is deliberate (those patterns vary by repo setup) and names the exact file to copy from.
