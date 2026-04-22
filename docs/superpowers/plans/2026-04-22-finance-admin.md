# Finance Admin Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ship the Finance admin bundle described in `docs/superpowers/specs/2026-04-22-finance-admin-design.md` — new Spatie `finance` role; Filament CRUD for Expenses + Investments; admin bypass preserved; manual entries display as `D{id}` while Slack-captured rows display as `#{id}`; Slack/n8n path untouched.

**Architecture:** Two parallel Filament resources (ExpenseResource, InvestmentResource) under a new "Finance" sidebar group. Access gated by `hasAnyRole(['admin','finance'])` via auto-discovered policies. A one-line migration relaxes `slack_message_id` to nullable so manual entries can exist. A `display_id` accessor on each model returns `"D{id}"` or `"#{id}"` based on the presence of `slack_message_id`.

**Tech Stack:** Laravel 11, Filament 3, Spatie Permission, MySQL, PHPUnit 11, PHP 8.5 local / 8.4 prod, Livewire for Filament component tests.

**Branch:** `feature/finance-admin` (already created, contains the spec commit `982dd90`).

**Local test runner:** `php -d memory_limit=512M vendor/bin/phpunit --filter=<name>` (plain `php artisan test` OOMs on the full suite with default memory).

**DEPR note:** On local PHP 8.5 every test emits a `PHP Deprecated: PDO::MYSQL_ATTR_SSL_CA` line. These are harmless; read the final `Tests: X passed` line. See memory `project_davya-crm_php85_deprecations.md`.

**Laravel 11 policy auto-discovery:** `App\Policies\{Model}Policy` is auto-bound to `App\Models\{Model}`. No `AuthServiceProvider` registration is needed (and the app doesn't even have one — Laravel 11 relies on `AppServiceProvider`).

---

## Seed fixture reference

From `database/seeders/UsersSeeder.php` (seeded by `$this->seed()`):

| User | Email | Roles |
|---|---|---|
| Sumit | `sumit@davya.local` | `admin`, `head` |
| Nikhil | `nikhil@davya.local` | `head` |
| Sonam | `sonam@davya.local` | `head` |
| Nisha | `nisha@davya.local` | `member` (team Nikhil) |
| Poonam | `poonam@davya.local` | `member` (team Sonam) |
| Neetu | `neetu@davya.local` | `member` (team Sonam) |
| Kapil | `kapil@davya.local` | `freelancer` |

Every test user has `must_change_password = true`; use an `unblock($user)` helper to flip it to `false` before acting as them (pattern lifted from `LeadsReportPageTest.php`).

---

## File structure

**Create**
- `database/migrations/2026_04_22_220000_make_finance_slack_id_nullable.php`
- `database/seeders/FinanceRoleSeeder.php`
- `app/Policies/ExpensePolicy.php`
- `app/Policies/InvestmentPolicy.php`
- `app/Filament/Resources/ExpenseResource.php`
- `app/Filament/Resources/ExpenseResource/Pages/ListExpenses.php`
- `app/Filament/Resources/ExpenseResource/Pages/CreateExpense.php`
- `app/Filament/Resources/ExpenseResource/Pages/EditExpense.php`
- `app/Filament/Resources/InvestmentResource.php`
- `app/Filament/Resources/InvestmentResource/Pages/ListInvestments.php`
- `app/Filament/Resources/InvestmentResource/Pages/CreateInvestment.php`
- `app/Filament/Resources/InvestmentResource/Pages/EditInvestment.php`
- `tests/Feature/FinanceRoleTest.php`
- `tests/Feature/ExpenseResourceTest.php`
- `tests/Feature/InvestmentResourceTest.php`

**Modify**
- `app/Models/Expense.php` — add `getDisplayIdAttribute()` accessor.
- `app/Models/Investment.php` — add `getDisplayIdAttribute()` accessor.
- `database/seeders/DatabaseSeeder.php` — call `FinanceRoleSeeder` after `UsersSeeder`.

No other files change. `FinancePaymentController`, `FinanceExpenseController`, `FinanceInvestmentController`, and every n8n workflow stay untouched.

---

## Task 1: Migration — `slack_message_id` nullable on finance tables

**Rationale:** Manual entries from the admin have no Slack ID. MySQL permits multiple NULLs in a unique index, so the Slack-dedup guarantee survives. Existing 7 expense + 2 investment rows already have values and stay untouched.

**Files:**
- Create: `database/migrations/2026_04_22_220000_make_finance_slack_id_nullable.php`

### - [ ] Step 1: Write the migration

Create the file with this exact content:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Relaxes slack_message_id to nullable on expenses + investments so that
    // manual (dashboard-entered) rows can exist. The unique index is preserved;
    // MySQL permits multiple NULLs in a unique column, so Slack dedup still
    // rejects duplicate slack_message_id values.
    //
    // ROLLBACK HAZARD: the down() method restores NOT NULL. If any manual
    // (NULL) rows exist at rollback time, it will fail. Backfill a sentinel
    // like 'manual-'.id before rolling back.

    public function up(): void
    {
        Schema::table('expenses', function (Blueprint $table) {
            $table->string('slack_message_id', 50)->nullable()->change();
        });
        Schema::table('investments', function (Blueprint $table) {
            $table->string('slack_message_id', 50)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('expenses', function (Blueprint $table) {
            $table->string('slack_message_id', 50)->nullable(false)->change();
        });
        Schema::table('investments', function (Blueprint $table) {
            $table->string('slack_message_id', 50)->nullable(false)->change();
        });
    }
};
```

### - [ ] Step 2: Run the migration locally

Run:
```bash
php -d memory_limit=512M artisan migrate
```
Expected output includes `Migrating: 2026_04_22_220000_make_finance_slack_id_nullable` and `Migrated:`.

### - [ ] Step 3: Verify the column is nullable

Run:
```bash
php artisan tinker --execute="echo \Schema::getColumnType('expenses', 'slack_message_id').PHP_EOL; dump(\DB::select('SHOW COLUMNS FROM expenses WHERE Field = \'slack_message_id\''));"
```
Expected: the `Null` field for both tables reads `YES`. (Use the same SHOW COLUMNS for investments to confirm.)

### - [ ] Step 4: Commit

```bash
git add database/migrations/2026_04_22_220000_make_finance_slack_id_nullable.php
git commit -m "$(cat <<'EOF'
feat(migration): relax expenses/investments slack_message_id to nullable

Manual (dashboard-entered) finance rows have no Slack ID. MySQL ignores
NULLs in unique indexes, so Slack dedup is preserved.

Ref: docs/superpowers/specs/2026-04-22-finance-admin-design.md (gap #6)

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 2: `display_id` accessor on Expense + Investment

**Rationale:** Manual rows (null `slack_message_id`) must render as `D{id}`; Slack-captured rows render as `#{id}`. Computed at render — no schema change, no counter column.

**Files:**
- Modify: `app/Models/Expense.php`
- Modify: `app/Models/Investment.php`
- Create: `tests/Feature/ExpenseResourceTest.php` (new file — first tests for this feature, more added later)
- Create: `tests/Feature/InvestmentResourceTest.php` (new file — first tests for this feature, more added later)

### - [ ] Step 1: Write failing tests for `display_id`

Create `tests/Feature/ExpenseResourceTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Expense;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExpenseResourceTest extends TestCase
{
    use RefreshDatabase;

    public function test_manual_expense_renders_D_prefix(): void
    {
        $e = Expense::create([
            'amount' => 1000,
            'description' => 'Manual test',
            'paid_at' => now(),
            'slack_message_id' => null,
        ]);
        $this->assertSame("D{$e->id}", $e->display_id, 'manual rows must use D prefix');
    }

    public function test_slack_captured_expense_renders_hash_prefix(): void
    {
        $e = Expense::create([
            'amount' => 2500,
            'description' => 'Captured from Slack',
            'paid_at' => now(),
            'slack_message_id' => '1776767527.655079',
        ]);
        $this->assertSame("#{$e->id}", $e->display_id, 'slack rows must use # prefix');
    }
}
```

Create `tests/Feature/InvestmentResourceTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Investment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InvestmentResourceTest extends TestCase
{
    use RefreshDatabase;

    public function test_manual_investment_renders_D_prefix(): void
    {
        $i = Investment::create([
            'asset_name' => 'Tata Motors',
            'amount' => 50000,
            'direction' => 'out',
            'transacted_at' => now(),
            'slack_message_id' => null,
        ]);
        $this->assertSame("D{$i->id}", $i->display_id);
    }

    public function test_slack_captured_investment_renders_hash_prefix(): void
    {
        $i = Investment::create([
            'asset_name' => 'Tata Motors',
            'amount' => 50000,
            'direction' => 'out',
            'transacted_at' => now(),
            'slack_message_id' => '1776582096.431769',
        ]);
        $this->assertSame("#{$i->id}", $i->display_id);
    }
}
```

### - [ ] Step 2: Run the tests — expect FAIL

Run:
```bash
php -d memory_limit=512M vendor/bin/phpunit --filter='ExpenseResourceTest|InvestmentResourceTest'
```
Expected: **4 errors / failures**, each complaining that `display_id` is missing (`Undefined property` or empty string assertion).

### - [ ] Step 3: Add accessor to `app/Models/Expense.php`

Replace the current file with:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Expense extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected $casts = [
        'amount'  => 'decimal:2',
        'paid_at' => 'datetime',
    ];

    public function getDisplayIdAttribute(): string
    {
        return $this->slack_message_id === null ? "D{$this->id}" : "#{$this->id}";
    }
}
```

### - [ ] Step 4: Add accessor to `app/Models/Investment.php`

Replace the current file with:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Investment extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected $casts = [
        'amount'        => 'decimal:2',
        'transacted_at' => 'datetime',
    ];

    public function getDisplayIdAttribute(): string
    {
        return $this->slack_message_id === null ? "D{$this->id}" : "#{$this->id}";
    }
}
```

### - [ ] Step 5: Run the tests — expect PASS

Run:
```bash
php -d memory_limit=512M vendor/bin/phpunit --filter='ExpenseResourceTest|InvestmentResourceTest'
```
Expected: **4 passed**.

### - [ ] Step 6: Commit

```bash
git add app/Models/Expense.php app/Models/Investment.php \
        tests/Feature/ExpenseResourceTest.php tests/Feature/InvestmentResourceTest.php
git commit -m "$(cat <<'EOF'
feat(finance): display_id accessor — D{id} for manual, #{id} for Slack

Computed at render from slack_message_id presence. No schema change.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 3: `FinanceRoleSeeder` + wire into DatabaseSeeder

**Rationale:** Introduce the Spatie role so it exists on local and prod after deploy. Idempotent — re-running deploy never errors.

**Files:**
- Create: `database/seeders/FinanceRoleSeeder.php`
- Modify: `database/seeders/DatabaseSeeder.php`

### - [ ] Step 1: Write the seeder

Create `database/seeders/FinanceRoleSeeder.php`:

```php
<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;

class FinanceRoleSeeder extends Seeder
{
    public function run(): void
    {
        Role::findOrCreate('finance');
    }
}
```

### - [ ] Step 2: Add to the main DatabaseSeeder

Replace the current `run()` method in `database/seeders/DatabaseSeeder.php`:

```php
public function run(): void
{
    $this->call([
        RolesSeeder::class,
        UsersSeeder::class,
        FinanceRoleSeeder::class,
    ]);
}
```

Leave the namespace/use statements at the top untouched.

### - [ ] Step 3: Run the seeder

Run:
```bash
php artisan db:seed --class=FinanceRoleSeeder --force
```
Expected: `Database seeding completed successfully.`

### - [ ] Step 4: Verify the role exists

Run:
```bash
php artisan tinker --execute="echo \Spatie\Permission\Models\Role::where('name','finance')->count().PHP_EOL;"
```
Expected: `1`.

### - [ ] Step 5: Re-run the seeder to confirm idempotence

Run:
```bash
php artisan db:seed --class=FinanceRoleSeeder --force && php artisan db:seed --class=FinanceRoleSeeder --force
```
Expected: both runs succeed, role count stays at `1`.

### - [ ] Step 6: Commit

```bash
git add database/seeders/FinanceRoleSeeder.php database/seeders/DatabaseSeeder.php
git commit -m "$(cat <<'EOF'
feat(finance): FinanceRoleSeeder — creates 'finance' Spatie role

Idempotent via Role::findOrCreate. Wired into DatabaseSeeder so prod
deploy script picks it up on next run.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 4: `ExpensePolicy` + `ExpenseResource` (CRUD + role gate + source badge)

**Rationale:** First half of the visible UI. Policy gates all CRUD on `hasAnyRole(['admin','finance'])`; resource provides the Filament pages.

**Files:**
- Create: `app/Policies/ExpensePolicy.php`
- Create: `app/Filament/Resources/ExpenseResource.php`
- Create: `app/Filament/Resources/ExpenseResource/Pages/ListExpenses.php`
- Create: `app/Filament/Resources/ExpenseResource/Pages/CreateExpense.php`
- Create: `app/Filament/Resources/ExpenseResource/Pages/EditExpense.php`
- Create: `tests/Feature/FinanceRoleTest.php`
- Modify: `tests/Feature/ExpenseResourceTest.php` (append CRUD + access tests)

### - [ ] Step 1: Write failing role-gate test

Create `tests/Feature/FinanceRoleTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Filament\Resources\ExpenseResource\Pages\ListExpenses;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class FinanceRoleTest extends TestCase
{
    use RefreshDatabase;

    private function unblock(User $u): User
    {
        $u->must_change_password = false;
        $u->save();
        return $u;
    }

    public function test_admin_can_access_expense_list(): void
    {
        $this->seed();
        $sumit = $this->unblock(User::where('email', 'sumit@davya.local')->firstOrFail());
        $this->actingAs($sumit);
        Livewire::test(ListExpenses::class)->assertStatus(200);
    }

    public function test_finance_role_user_can_access_expense_list(): void
    {
        $this->seed();
        $this->artisan('db:seed', ['--class' => 'FinanceRoleSeeder', '--force' => true]);

        $finUser = User::create([
            'name' => 'Finance User',
            'email' => 'finance@davya.local',
            'password' => bcrypt('x'),
            'is_active' => true,
            'must_change_password' => false,
        ]);
        $finUser->assignRole('finance');

        $this->actingAs($finUser);
        Livewire::test(ListExpenses::class)->assertStatus(200);
    }

    public function test_head_cannot_access_expense_list(): void
    {
        $this->seed();
        $nikhil = $this->unblock(User::where('email', 'nikhil@davya.local')->firstOrFail());
        $this->actingAs($nikhil);
        $this->assertFalse(\App\Filament\Resources\ExpenseResource::canViewAny());
    }

    public function test_member_cannot_access_expense_list(): void
    {
        $this->seed();
        $nisha = $this->unblock(User::where('email', 'nisha@davya.local')->firstOrFail());
        $this->actingAs($nisha);
        $this->assertFalse(\App\Filament\Resources\ExpenseResource::canViewAny());
    }
}
```

### - [ ] Step 2: Run — expect FAIL (classes don't exist yet)

Run:
```bash
php -d memory_limit=512M vendor/bin/phpunit --filter=FinanceRoleTest
```
Expected: **errors** complaining `App\Filament\Resources\ExpenseResource` or `ListExpenses` not found.

### - [ ] Step 3: Write `ExpensePolicy`

Create `app/Policies/ExpensePolicy.php`:

```php
<?php

namespace App\Policies;

use App\Models\Expense;
use App\Models\User;

class ExpensePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole(['admin', 'finance']);
    }

    public function view(User $user, Expense $expense): bool
    {
        return $user->hasAnyRole(['admin', 'finance']);
    }

    public function create(User $user): bool
    {
        return $user->hasAnyRole(['admin', 'finance']);
    }

    public function update(User $user, Expense $expense): bool
    {
        return $user->hasAnyRole(['admin', 'finance']);
    }

    public function delete(User $user, Expense $expense): bool
    {
        return $user->hasAnyRole(['admin', 'finance']);
    }
}
```

### - [ ] Step 4: Write `ExpenseResource`

Create `app/Filament/Resources/ExpenseResource.php`:

```php
<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ExpenseResource\Pages;
use App\Models\Expense;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class ExpenseResource extends Resource
{
    protected static ?string $model = Expense::class;

    protected static ?string $navigationIcon = 'heroicon-o-banknotes';

    protected static ?string $navigationGroup = 'Finance';

    protected static ?string $navigationLabel = 'Expenses';

    protected static ?int $navigationSort = 10;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('amount')
                ->numeric()
                ->required()
                ->prefix('₹'),
            Forms\Components\TextInput::make('category')
                ->maxLength(60),
            Forms\Components\Textarea::make('description')
                ->rows(2),
            Forms\Components\DateTimePicker::make('paid_at')
                ->required()
                ->native(false)
                ->default(now()),
            Forms\Components\Textarea::make('raw_input')
                ->label('Raw Slack input')
                ->disabled()
                ->dehydrated(false)
                ->visible(fn ($record) => $record?->slack_message_id !== null),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('display_id')
                    ->label('ID')
                    ->sortable(['id']),
                Tables\Columns\TextColumn::make('source')
                    ->label('Source')
                    ->badge()
                    ->state(fn (Expense $r) => $r->slack_message_id ? 'Slack' : 'Manual')
                    ->color(fn (string $state) => $state === 'Slack' ? 'info' : 'success'),
                Tables\Columns\TextColumn::make('amount')
                    ->money('INR', locale: 'en_IN')
                    ->sortable(),
                Tables\Columns\TextColumn::make('category')
                    ->searchable(),
                Tables\Columns\TextColumn::make('description')
                    ->limit(60)
                    ->searchable(),
                Tables\Columns\TextColumn::make('paid_at')
                    ->dateTime('d M Y, H:i')
                    ->sortable(),
            ])
            ->defaultSort('paid_at', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('source')
                    ->options(['slack' => 'Slack', 'manual' => 'Manual'])
                    ->query(function ($query, array $data) {
                        if (($data['value'] ?? null) === 'slack') {
                            $query->whereNotNull('slack_message_id');
                        } elseif (($data['value'] ?? null) === 'manual') {
                            $query->whereNull('slack_message_id');
                        }
                    }),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListExpenses::route('/'),
            'create' => Pages\CreateExpense::route('/create'),
            'edit' => Pages\EditExpense::route('/{record}/edit'),
        ];
    }
}
```

### - [ ] Step 5: Write the three page classes

Create `app/Filament/Resources/ExpenseResource/Pages/ListExpenses.php`:

```php
<?php

namespace App\Filament\Resources\ExpenseResource\Pages;

use App\Filament\Resources\ExpenseResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListExpenses extends ListRecords
{
    protected static string $resource = ExpenseResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
```

Create `app/Filament/Resources/ExpenseResource/Pages/CreateExpense.php`:

```php
<?php

namespace App\Filament\Resources\ExpenseResource\Pages;

use App\Filament\Resources\ExpenseResource;
use Filament\Resources\Pages\CreateRecord;

class CreateExpense extends CreateRecord
{
    protected static string $resource = ExpenseResource::class;
}
```

Create `app/Filament/Resources/ExpenseResource/Pages/EditExpense.php`:

```php
<?php

namespace App\Filament\Resources\ExpenseResource\Pages;

use App\Filament\Resources\ExpenseResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditExpense extends EditRecord
{
    protected static string $resource = ExpenseResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
```

### - [ ] Step 6: Run FinanceRoleTest — expect PASS

Run:
```bash
php -d memory_limit=512M vendor/bin/phpunit --filter=FinanceRoleTest
```
Expected: **4 passed**.

### - [ ] Step 7: Append CRUD tests to `ExpenseResourceTest`

Replace the whole file `tests/Feature/ExpenseResourceTest.php` with this (keeps Task-2 tests, adds CRUD):

```php
<?php

namespace Tests\Feature;

use App\Filament\Resources\ExpenseResource\Pages\CreateExpense;
use App\Filament\Resources\ExpenseResource\Pages\EditExpense;
use App\Filament\Resources\ExpenseResource\Pages\ListExpenses;
use App\Models\Expense;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ExpenseResourceTest extends TestCase
{
    use RefreshDatabase;

    private function unblock(User $u): User
    {
        $u->must_change_password = false;
        $u->save();
        return $u;
    }

    private function actingAsAdmin(): void
    {
        $this->seed();
        $sumit = $this->unblock(User::where('email', 'sumit@davya.local')->firstOrFail());
        $this->actingAs($sumit);
    }

    public function test_manual_expense_renders_D_prefix(): void
    {
        $e = Expense::create([
            'amount' => 1000,
            'description' => 'Manual test',
            'paid_at' => now(),
            'slack_message_id' => null,
        ]);
        $this->assertSame("D{$e->id}", $e->display_id, 'manual rows must use D prefix');
    }

    public function test_slack_captured_expense_renders_hash_prefix(): void
    {
        $e = Expense::create([
            'amount' => 2500,
            'description' => 'Captured from Slack',
            'paid_at' => now(),
            'slack_message_id' => '1776767527.655079',
        ]);
        $this->assertSame("#{$e->id}", $e->display_id, 'slack rows must use # prefix');
    }

    public function test_manual_create_via_form_leaves_slack_id_null(): void
    {
        $this->actingAsAdmin();

        Livewire::test(CreateExpense::class)
            ->fillForm([
                'amount' => 500,
                'category' => 'Office',
                'description' => 'printer paper',
                'paid_at' => now()->toDateTimeString(),
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $row = Expense::latest('id')->first();
        $this->assertNotNull($row, 'expense row must be created');
        $this->assertNull($row->slack_message_id, 'manual creates must leave slack_message_id NULL');
        $this->assertSame("D{$row->id}", $row->display_id);
        $this->assertEqualsWithDelta(500.0, (float) $row->amount, 0.01);
    }

    public function test_admin_can_update_expense(): void
    {
        $this->actingAsAdmin();
        $e = Expense::create([
            'amount' => 1000,
            'category' => 'Old',
            'description' => 'before',
            'paid_at' => now(),
            'slack_message_id' => null,
        ]);

        Livewire::test(EditExpense::class, ['record' => $e->getRouteKey()])
            ->fillForm(['amount' => 1234, 'category' => 'New', 'description' => 'after'])
            ->call('save')
            ->assertHasNoFormErrors();

        $e->refresh();
        $this->assertEqualsWithDelta(1234.0, (float) $e->amount, 0.01);
        $this->assertSame('New', $e->category);
        $this->assertSame('after', $e->description);
    }

    public function test_admin_can_delete_expense(): void
    {
        $this->actingAsAdmin();
        $e = Expense::create([
            'amount' => 999,
            'description' => 'to be deleted',
            'paid_at' => now(),
            'slack_message_id' => null,
        ]);

        // Policy allows delete; model supports delete.
        $this->assertTrue(auth()->user()->can('delete', $e), 'policy must allow admin delete');
        $e->delete();
        $this->assertNull(Expense::find($e->id), 'row must be gone');
    }

    public function test_slack_message_id_unique_constraint_survives_migration(): void
    {
        // Regression: Task 1's migration must not drop the unique index.
        Expense::create([
            'amount' => 1, 'description' => 'a',
            'paid_at' => now(), 'slack_message_id' => 'dup-1',
        ]);
        $this->expectException(QueryException::class);
        Expense::create([
            'amount' => 2, 'description' => 'b',
            'paid_at' => now(), 'slack_message_id' => 'dup-1',
        ]);
    }

    public function test_two_manual_rows_can_coexist(): void
    {
        // Null is allowed multiple times in a unique index on MySQL.
        $a = Expense::create(['amount'=>1,'description'=>'a','paid_at'=>now(),'slack_message_id'=>null]);
        $b = Expense::create(['amount'=>2,'description'=>'b','paid_at'=>now(),'slack_message_id'=>null]);
        $this->assertNotSame($a->id, $b->id);
    }
}
```

### - [ ] Step 8: Run `ExpenseResourceTest` — expect PASS

Run:
```bash
php -d memory_limit=512M vendor/bin/phpunit --filter=ExpenseResourceTest
```
Expected: **7 passed**.

### - [ ] Step 9: Commit

```bash
git add app/Policies/ExpensePolicy.php \
        app/Filament/Resources/ExpenseResource.php \
        app/Filament/Resources/ExpenseResource/ \
        tests/Feature/FinanceRoleTest.php \
        tests/Feature/ExpenseResourceTest.php
git commit -m "$(cat <<'EOF'
feat(finance): ExpenseResource CRUD + ExpensePolicy role gate

New Filament resource under "Finance" nav group. Policy gates on
admin|finance. Manual creates leave slack_message_id NULL → show as
D{id}. Slack-captured rows keep their ID and show as #{id}. raw_input
shown as read-only on edit for Slack rows only.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 5: `InvestmentPolicy` + `InvestmentResource` (CRUD + role gate)

**Rationale:** Mirror of Task 4 for Investments. Different model fields: `asset_name` + `direction` instead of `category` + `description`.

**Files:**
- Create: `app/Policies/InvestmentPolicy.php`
- Create: `app/Filament/Resources/InvestmentResource.php`
- Create: `app/Filament/Resources/InvestmentResource/Pages/ListInvestments.php`
- Create: `app/Filament/Resources/InvestmentResource/Pages/CreateInvestment.php`
- Create: `app/Filament/Resources/InvestmentResource/Pages/EditInvestment.php`
- Modify: `tests/Feature/FinanceRoleTest.php` (append investment access tests)
- Modify: `tests/Feature/InvestmentResourceTest.php` (append CRUD tests)

### - [ ] Step 1: Append failing investment-access tests to `FinanceRoleTest.php`

Append these methods inside `FinanceRoleTest` (before the closing `}`):

```php
public function test_admin_can_access_investment_list(): void
{
    $this->seed();
    $sumit = $this->unblock(User::where('email', 'sumit@davya.local')->firstOrFail());
    $this->actingAs($sumit);
    Livewire::test(\App\Filament\Resources\InvestmentResource\Pages\ListInvestments::class)->assertStatus(200);
}

public function test_head_cannot_access_investment_list(): void
{
    $this->seed();
    $nikhil = $this->unblock(User::where('email', 'nikhil@davya.local')->firstOrFail());
    $this->actingAs($nikhil);
    $this->assertFalse(\App\Filament\Resources\InvestmentResource::canViewAny());
}

public function test_member_cannot_access_investment_list(): void
{
    $this->seed();
    $nisha = $this->unblock(User::where('email', 'nisha@davya.local')->firstOrFail());
    $this->actingAs($nisha);
    $this->assertFalse(\App\Filament\Resources\InvestmentResource::canViewAny());
}
```

### - [ ] Step 2: Run — expect FAIL

Run:
```bash
php -d memory_limit=512M vendor/bin/phpunit --filter=FinanceRoleTest
```
Expected: **3 errors** on the new investment tests (class not found).

### - [ ] Step 3: Write `InvestmentPolicy`

Create `app/Policies/InvestmentPolicy.php`:

```php
<?php

namespace App\Policies;

use App\Models\Investment;
use App\Models\User;

class InvestmentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole(['admin', 'finance']);
    }

    public function view(User $user, Investment $investment): bool
    {
        return $user->hasAnyRole(['admin', 'finance']);
    }

    public function create(User $user): bool
    {
        return $user->hasAnyRole(['admin', 'finance']);
    }

    public function update(User $user, Investment $investment): bool
    {
        return $user->hasAnyRole(['admin', 'finance']);
    }

    public function delete(User $user, Investment $investment): bool
    {
        return $user->hasAnyRole(['admin', 'finance']);
    }
}
```

### - [ ] Step 4: Write `InvestmentResource`

Create `app/Filament/Resources/InvestmentResource.php`:

```php
<?php

namespace App\Filament\Resources;

use App\Filament\Resources\InvestmentResource\Pages;
use App\Models\Investment;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class InvestmentResource extends Resource
{
    protected static ?string $model = Investment::class;

    protected static ?string $navigationIcon = 'heroicon-o-chart-bar';

    protected static ?string $navigationGroup = 'Finance';

    protected static ?string $navigationLabel = 'Investments';

    protected static ?int $navigationSort = 20;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('asset_name')
                ->required()
                ->maxLength(80),
            Forms\Components\TextInput::make('amount')
                ->numeric()
                ->required()
                ->prefix('₹'),
            Forms\Components\Select::make('direction')
                ->options(['in' => 'In (buy / add)', 'out' => 'Out (sell / withdraw)'])
                ->required(),
            Forms\Components\DateTimePicker::make('transacted_at')
                ->required()
                ->native(false)
                ->default(now()),
            Forms\Components\Textarea::make('raw_input')
                ->label('Raw Slack input')
                ->disabled()
                ->dehydrated(false)
                ->visible(fn ($record) => $record?->slack_message_id !== null),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('display_id')
                    ->label('ID')
                    ->sortable(['id']),
                Tables\Columns\TextColumn::make('source')
                    ->label('Source')
                    ->badge()
                    ->state(fn (Investment $r) => $r->slack_message_id ? 'Slack' : 'Manual')
                    ->color(fn (string $state) => $state === 'Slack' ? 'info' : 'success'),
                Tables\Columns\TextColumn::make('asset_name')
                    ->searchable(),
                Tables\Columns\TextColumn::make('direction')
                    ->badge()
                    ->color(fn (string $state) => $state === 'in' ? 'success' : 'warning'),
                Tables\Columns\TextColumn::make('amount')
                    ->money('INR', locale: 'en_IN')
                    ->sortable(),
                Tables\Columns\TextColumn::make('transacted_at')
                    ->dateTime('d M Y, H:i')
                    ->sortable(),
            ])
            ->defaultSort('transacted_at', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('source')
                    ->options(['slack' => 'Slack', 'manual' => 'Manual'])
                    ->query(function ($query, array $data) {
                        if (($data['value'] ?? null) === 'slack') {
                            $query->whereNotNull('slack_message_id');
                        } elseif (($data['value'] ?? null) === 'manual') {
                            $query->whereNull('slack_message_id');
                        }
                    }),
                Tables\Filters\SelectFilter::make('direction')
                    ->options(['in' => 'In', 'out' => 'Out']),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListInvestments::route('/'),
            'create' => Pages\CreateInvestment::route('/create'),
            'edit' => Pages\EditInvestment::route('/{record}/edit'),
        ];
    }
}
```

### - [ ] Step 5: Write the three page classes

Create `app/Filament/Resources/InvestmentResource/Pages/ListInvestments.php`:

```php
<?php

namespace App\Filament\Resources\InvestmentResource\Pages;

use App\Filament\Resources\InvestmentResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListInvestments extends ListRecords
{
    protected static string $resource = InvestmentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
```

Create `app/Filament/Resources/InvestmentResource/Pages/CreateInvestment.php`:

```php
<?php

namespace App\Filament\Resources\InvestmentResource\Pages;

use App\Filament\Resources\InvestmentResource;
use Filament\Resources\Pages\CreateRecord;

class CreateInvestment extends CreateRecord
{
    protected static string $resource = InvestmentResource::class;
}
```

Create `app/Filament/Resources/InvestmentResource/Pages/EditInvestment.php`:

```php
<?php

namespace App\Filament\Resources\InvestmentResource\Pages;

use App\Filament\Resources\InvestmentResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditInvestment extends EditRecord
{
    protected static string $resource = InvestmentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
```

### - [ ] Step 6: Run FinanceRoleTest — expect PASS

Run:
```bash
php -d memory_limit=512M vendor/bin/phpunit --filter=FinanceRoleTest
```
Expected: **7 passed** (4 from Task 4 + 3 from Task 5).

### - [ ] Step 7: Expand `InvestmentResourceTest` with CRUD

Replace the whole file `tests/Feature/InvestmentResourceTest.php` with:

```php
<?php

namespace Tests\Feature;

use App\Filament\Resources\InvestmentResource\Pages\CreateInvestment;
use App\Filament\Resources\InvestmentResource\Pages\EditInvestment;
use App\Models\Investment;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class InvestmentResourceTest extends TestCase
{
    use RefreshDatabase;

    private function unblock(User $u): User
    {
        $u->must_change_password = false;
        $u->save();
        return $u;
    }

    private function actingAsAdmin(): void
    {
        $this->seed();
        $sumit = $this->unblock(User::where('email', 'sumit@davya.local')->firstOrFail());
        $this->actingAs($sumit);
    }

    public function test_manual_investment_renders_D_prefix(): void
    {
        $i = Investment::create([
            'asset_name' => 'Tata Motors',
            'amount' => 50000,
            'direction' => 'out',
            'transacted_at' => now(),
            'slack_message_id' => null,
        ]);
        $this->assertSame("D{$i->id}", $i->display_id);
    }

    public function test_slack_captured_investment_renders_hash_prefix(): void
    {
        $i = Investment::create([
            'asset_name' => 'Tata Motors',
            'amount' => 50000,
            'direction' => 'out',
            'transacted_at' => now(),
            'slack_message_id' => '1776582096.431769',
        ]);
        $this->assertSame("#{$i->id}", $i->display_id);
    }

    public function test_manual_create_via_form_leaves_slack_id_null(): void
    {
        $this->actingAsAdmin();

        Livewire::test(CreateInvestment::class)
            ->fillForm([
                'asset_name' => 'Reliance',
                'amount' => 75000,
                'direction' => 'in',
                'transacted_at' => now()->toDateTimeString(),
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $row = Investment::latest('id')->first();
        $this->assertNotNull($row);
        $this->assertNull($row->slack_message_id);
        $this->assertSame("D{$row->id}", $row->display_id);
        $this->assertSame('Reliance', $row->asset_name);
    }

    public function test_admin_can_update_investment(): void
    {
        $this->actingAsAdmin();
        $i = Investment::create([
            'asset_name' => 'Tata',
            'amount' => 1000,
            'direction' => 'out',
            'transacted_at' => now(),
            'slack_message_id' => null,
        ]);

        Livewire::test(EditInvestment::class, ['record' => $i->getRouteKey()])
            ->fillForm(['asset_name' => 'Tata Motors', 'amount' => 2000, 'direction' => 'in'])
            ->call('save')
            ->assertHasNoFormErrors();

        $i->refresh();
        $this->assertSame('Tata Motors', $i->asset_name);
        $this->assertEqualsWithDelta(2000.0, (float) $i->amount, 0.01);
        $this->assertSame('in', $i->direction);
    }

    public function test_admin_can_delete_investment(): void
    {
        $this->actingAsAdmin();
        $i = Investment::create([
            'asset_name' => 'Garbage',
            'amount' => 1,
            'direction' => 'out',
            'transacted_at' => now(),
            'slack_message_id' => null,
        ]);

        $this->assertTrue(auth()->user()->can('delete', $i), 'policy must allow admin delete');
        $i->delete();
        $this->assertNull(Investment::find($i->id));
    }

    public function test_slack_message_id_unique_constraint_survives_migration(): void
    {
        Investment::create([
            'asset_name' => 'X', 'amount' => 1, 'direction' => 'in',
            'transacted_at' => now(), 'slack_message_id' => 'inv-dup-1',
        ]);
        $this->expectException(QueryException::class);
        Investment::create([
            'asset_name' => 'Y', 'amount' => 2, 'direction' => 'in',
            'transacted_at' => now(), 'slack_message_id' => 'inv-dup-1',
        ]);
    }

    public function test_two_manual_rows_can_coexist(): void
    {
        $a = Investment::create(['asset_name'=>'A','amount'=>1,'direction'=>'in','transacted_at'=>now(),'slack_message_id'=>null]);
        $b = Investment::create(['asset_name'=>'B','amount'=>2,'direction'=>'in','transacted_at'=>now(),'slack_message_id'=>null]);
        $this->assertNotSame($a->id, $b->id);
    }
}
```

### - [ ] Step 8: Run `InvestmentResourceTest` — expect PASS

Run:
```bash
php -d memory_limit=512M vendor/bin/phpunit --filter=InvestmentResourceTest
```
Expected: **7 passed**.

### - [ ] Step 9: Commit

```bash
git add app/Policies/InvestmentPolicy.php \
        app/Filament/Resources/InvestmentResource.php \
        app/Filament/Resources/InvestmentResource/ \
        tests/Feature/FinanceRoleTest.php \
        tests/Feature/InvestmentResourceTest.php
git commit -m "$(cat <<'EOF'
feat(finance): InvestmentResource CRUD + InvestmentPolicy role gate

Mirror of ExpenseResource — Investments resource under same "Finance"
nav group. Same D{id}/#{id} display-id rule; same admin|finance gate.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 6: Full-suite verification + prod deploy

**Rationale:** Before merging, confirm the whole suite stays green (widgets, policies, existing Payment tests, etc.). Then deploy via pull + migrate + seed + cache-clear.

**Files:** none — verification + deploy only.

### - [ ] Step 1: Run the full suite

Run:
```bash
php -d memory_limit=1G vendor/bin/phpunit
```
Expected: the final line shows `Tests: NNN, Assertions: MMM` with **zero failures**. DEPR warnings are fine.

### - [ ] Step 2: Spot-check the existing lead-access suite

Run:
```bash
php -d memory_limit=512M vendor/bin/phpunit --filter='NikhilVisibilityTest|StudentPolicyTest|PaymentReportTest'
```
Expected: **all pass** — confirms no regression in the access-rule work that already shipped.

### - [ ] Step 3: Merge branch to main

```bash
git checkout main
git merge --ff-only feature/finance-admin
git log --oneline -6
```
Expected: fast-forward successful; top commits (in order from newest) are Task 6 nothing-to-commit, Task 5 Investment feature, Task 4 Expense feature, Task 3 Finance role seeder, Task 2 display_id accessor, Task 1 migration.

### - [ ] Step 4: Push to origin

```bash
git push origin main
```
Expected: push reports `→ main` successfully.

### - [ ] Step 5: Deploy to prod

Run:
```bash
ssh -i ~/.ssh/davyas-active ipuc@ipu.co.in "cd /home/ipuc/davya-crm && git pull --ff-only origin main && /opt/alt/php84/usr/bin/php artisan migrate --force && /opt/alt/php84/usr/bin/php artisan db:seed --class=FinanceRoleSeeder --force && /opt/alt/php84/usr/bin/php artisan optimize:clear && git log -1 --oneline"
```
Expected:
- `git pull` succeeds.
- `migrate` output includes `2026_04_22_220000_make_finance_slack_id_nullable ............... DONE`.
- `db:seed` prints `Database seeding completed successfully`.
- `optimize:clear` prints cache-clearing lines.
- `git log -1` shows the HEAD at the final Task 5 commit SHA.

### - [ ] Step 6: Prod smoke check

On prod browser (logged in as Sumit):
1. Reload the admin. Sidebar shows a new **Finance** group with **Expenses** and **Investments**.
2. Click **Expenses**. Table lists all 7 existing rows, each with a `#{id}` badge and blue "Slack" source badge. `raw_input` column (if visible) matches the original Slack text.
3. Click **Create**. Fill amount=₹1, description="smoke test", paid_at=now. Save.
4. The new row appears with `D{id}` (e.g. `D9`) and green "Manual" badge.
5. Edit the D-row — change amount to ₹2. Save. Confirm updated value persists.
6. Delete the D-row.
7. Click **Investments**. Table shows both Tata Motors ₹100k rows. Delete the duplicate (leave the oldest). Confirm only one row remains.

Also verify via tinker on prod:
```bash
ssh -i ~/.ssh/davyas-active ipuc@ipu.co.in "cd /home/ipuc/davya-crm && /opt/alt/php84/usr/bin/php artisan tinker --execute=\"echo \\Spatie\\Permission\\Models\\Role::where('name','finance')->count().PHP_EOL;\""
```
Expected: `1` (role exists).

### - [ ] Step 7: Report completion

Summarise to the user:
- All 6 implementation tasks green.
- Prod HEAD at the Task-5 commit.
- New Finance nav group live; 7 existing expenses + 2 investments visible; D-prefix working on a test manual row.
- Slack/n8n path untouched — verify by posting a fresh Slack expense in the chat and confirming it still lands with a `#{id}` badge (optional follow-up).

---

## Rollback plan

- Pure code/migration change. Revert:
  ```bash
  git revert <merge commit sha>
  git push origin main
  ssh ...  "cd /home/ipuc/davya-crm && git pull && /opt/alt/php84/usr/bin/php artisan migrate:rollback --step=1 --force && /opt/alt/php84/usr/bin/php artisan optimize:clear"
  ```
- **Rollback hazard:** if manual rows (`slack_message_id IS NULL`) exist on prod at rollback time, the migration down will fail. Backfill first:
  ```sql
  UPDATE expenses SET slack_message_id = CONCAT('manual-', id) WHERE slack_message_id IS NULL;
  UPDATE investments SET slack_message_id = CONCAT('manual-', id) WHERE slack_message_id IS NULL;
  ```
  Then retry `migrate:rollback`.
- The `finance` role row in the `roles` table survives rollback — harmless; re-deploying restores the code that uses it.
