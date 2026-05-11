# Books — Multi-Company Finance Module Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build a brand-new, super_admin-only, multi-company FY-scoped finance module inside davya-crm — companies, fiscal years, user-defined sections, entries with Salary/Loan amounts, time-resolved payment sub-table, depreciation engine, custom fields, multi-doc attachments, and per-FY dashboard.

**Architecture:** Approach C (typed money columns + Phase-A-style custom fields). 10 new `book_*` tables, no FK to existing CRM tables. Feature-flagged via `BOOKS_MODULE` env. Cash vs non-cash outflow kept separate end-to-end. Closed FYs are snapshot-frozen.

**Tech Stack:** Laravel 11, Filament 3, MySQL (SQLite for tests), Pest, Spatie ActivityLog + Permission, flysystem-gdrive.

**Spec:** `docs/superpowers/specs/2026-05-11-books-finance-module-design.md`

**Branch:** `feat/books-module`

---

## File Structure (locked in before tasks)

**Migrations** (`database/migrations/`)
- `2026_05_11_010001_create_book_companies_table.php`
- `2026_05_11_010002_create_book_fiscal_years_table.php`
- `2026_05_11_010003_create_book_sections_table.php`
- `2026_05_11_010004_create_book_entries_table.php`
- `2026_05_11_010005_create_book_entry_payments_table.php`
- `2026_05_11_010006_create_book_assets_table.php`
- `2026_05_11_010007_create_book_income_entries_table.php`
- `2026_05_11_010008_create_book_fields_table.php`
- `2026_05_11_010009_create_book_field_values_table.php`
- `2026_05_11_010010_create_book_attachments_table.php`

**Models** (`app/Models/Book/`) — one file per table.

**Services** (`app/Books/Services/`)
- `DepreciationCalculator.php` — SL + WDV maths, accumulated dep, book value
- `FiscalYearAggregator.php` — total_income / cash_outflow / non_cash_outflow / net_pl / carryover
- `ClosingSnapshotWriter.php` — close FY → freeze `closing_summary_json`; reopen → null it
- `BuiltInFieldsSeeder.php` — seed PAN/Aadhaar/etc on new company create

**Custom Fields** (`app/Books/Fields/`)
- `FieldRenderer.php` — adapted from `app/StudentFields/FieldRenderer.php`

**Filament Pages** (`app/Filament/Pages/Book/`)
- `CompaniesLanding.php` — landing page, list companies
- `CompanyDashboard.php` — `/admin/books/{company}/{fy}` (default per company × FY)
- `IncomePage.php` — `/admin/books/{company}/{fy}/income`
- `SectionPage.php` — `/admin/books/{company}/{fy}/section/{slug}` (one class, slug-driven)
- `CompanySettings.php` — `/admin/books/{company}/settings`

**Filament Resources** (`app/Filament/Resources/Book/`) — none. Books uses pages + Livewire actions, not auto-generated Resources (more control over nested URLs).

**Config**
- `config/books.php` — `enabled` (env `BOOKS_MODULE`), defaults

**Tests** (`tests/Feature/Books/` + `tests/Unit/Books/`)

**Provider edit**
- `app/Providers/Filament/AdminPanelProvider.php` — register Book pages, guard nav by super_admin + flag

---

## Task 1: Worktree, branch, feature flag scaffold

**Files:**
- Modify: `config/books.php` (create)
- Modify: `app/Providers/Filament/AdminPanelProvider.php`
- Test: `tests/Feature/Books/FeatureFlagTest.php`

- [ ] **Step 1.1: Create worktree using `superpowers:using-git-worktrees`**

Use the skill's defaults. Branch name `feat/books-module`. From this point all paths are relative to the worktree.

- [ ] **Step 1.2: Write the failing test**

```php
<?php

use App\Models\User;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    Role::firstOrCreate(['name' => 'super_admin']);
});

it('hides books module when feature flag is off', function () {
    config()->set('books.enabled', false);
    $user = User::factory()->create();
    $user->assignRole('super_admin');

    $this->actingAs($user)->get('/admin/books')->assertNotFound();
});

it('serves books landing for super_admin when flag is on', function () {
    config()->set('books.enabled', true);
    $user = User::factory()->create();
    $user->assignRole('super_admin');

    $this->actingAs($user)->get('/admin/books')->assertSuccessful();
});

it('returns 403 to non-super-admin when flag is on', function () {
    config()->set('books.enabled', true);
    $user = User::factory()->create();

    $this->actingAs($user)->get('/admin/books')->assertForbidden();
});
```

- [ ] **Step 1.3: Run and verify failure**

Run: `vendor/bin/pest tests/Feature/Books/FeatureFlagTest.php`
Expected: FAIL — `/admin/books` route does not exist yet.

- [ ] **Step 1.4: Create config file**

```php
// config/books.php
<?php

return [
    'enabled' => env('BOOKS_MODULE', false),
    'currency' => 'INR',
    'attachments_disk' => env('BOOKS_ATTACHMENTS_DISK', 'gdrive'),
];
```

- [ ] **Step 1.5: Add CompaniesLanding stub page**

```php
// app/Filament/Pages/Book/CompaniesLanding.php
<?php

namespace App\Filament\Pages\Book;

use Filament\Pages\Page;

class CompaniesLanding extends Page
{
    protected static ?string $slug = 'books';
    protected static ?string $title = 'Books';
    protected static ?string $navigationGroup = 'Books';
    protected static string $view = 'filament.pages.book.companies-landing';

    public static function canAccess(): bool
    {
        return config('books.enabled')
            && auth()->user()?->isSuperAdmin();
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }
}
```

- [ ] **Step 1.6: Create stub view**

```blade
{{-- resources/views/filament/pages/book/companies-landing.blade.php --}}
<x-filament-panels::page>
    <div>Books — landing</div>
</x-filament-panels::page>
```

- [ ] **Step 1.7: Register page in AdminPanelProvider**

In `app/Providers/Filament/AdminPanelProvider.php`, find the `->pages([...])` array and add `\App\Filament\Pages\Book\CompaniesLanding::class`.

- [ ] **Step 1.8: Run and verify pass**

Run: `vendor/bin/pest tests/Feature/Books/FeatureFlagTest.php`
Expected: PASS (3/3).

- [ ] **Step 1.9: Commit**

```bash
git add config/books.php app/Filament/Pages/Book/CompaniesLanding.php \
        resources/views/filament/pages/book/companies-landing.blade.php \
        app/Providers/Filament/AdminPanelProvider.php \
        tests/Feature/Books/FeatureFlagTest.php
git commit -m "feat(books): scaffold feature flag + super_admin-gated landing"
```

---

## Task 2: book_companies migration + Company model + CRUD test

**Files:**
- Create: `database/migrations/2026_05_11_010001_create_book_companies_table.php`
- Create: `app/Models/Book/Company.php`
- Create: `database/factories/Book/CompanyFactory.php`
- Test: `tests/Feature/Books/CompanyCrudTest.php`

- [ ] **Step 2.1: Write failing test**

```php
<?php

use App\Models\Book\Company;
use App\Models\User;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    config()->set('books.enabled', true);
    Role::firstOrCreate(['name' => 'super_admin']);
});

it('creates a company with INR default', function () {
    $c = Company::create(['name' => 'Davyas Consultancy', 'slug' => 'davyas']);
    expect($c->currency)->toBe('INR');
    expect($c->slug)->toBe('davyas');
});

it('enforces unique company slug', function () {
    Company::create(['name' => 'A', 'slug' => 'foo']);
    expect(fn () => Company::create(['name' => 'B', 'slug' => 'foo']))
        ->toThrow(\Illuminate\Database\QueryException::class);
});

it('rejects non-INR currency at the model level', function () {
    expect(fn () => Company::create(['name' => 'X', 'slug' => 'x', 'currency' => 'USD']))
        ->toThrow(\InvalidArgumentException::class);
});

it('soft-deletes a company', function () {
    $c = Company::create(['name' => 'A', 'slug' => 'a']);
    $c->delete();
    expect(Company::count())->toBe(0);
    expect(Company::withTrashed()->count())->toBe(1);
});
```

- [ ] **Step 2.2: Run and verify failure**

Run: `vendor/bin/pest tests/Feature/Books/CompanyCrudTest.php`
Expected: FAIL — model class does not exist.

- [ ] **Step 2.3: Create migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('book_companies', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('slug')->unique();
            $t->string('currency', 3)->default('INR');
            $t->string('timezone')->default('Asia/Kolkata');
            $t->timestamps();
            $t->softDeletes();
        });
    }
    public function down(): void { Schema::dropIfExists('book_companies'); }
};
```

- [ ] **Step 2.4: Create model**

```php
<?php

namespace App\Models\Book;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Company extends Model
{
    use SoftDeletes, HasFactory;

    protected $table = 'book_companies';
    protected $fillable = ['name', 'slug', 'currency', 'timezone'];
    protected $attributes = ['currency' => 'INR', 'timezone' => 'Asia/Kolkata'];

    protected static function booted(): void
    {
        static::saving(function (Company $c) {
            if ($c->currency !== 'INR') {
                throw new \InvalidArgumentException('Books v1 is INR-only');
            }
        });
    }

    protected static function newFactory()
    {
        return \Database\Factories\Book\CompanyFactory::new();
    }
}
```

- [ ] **Step 2.5: Create factory**

```php
<?php

namespace Database\Factories\Book;

use App\Models\Book\Company;
use Illuminate\Database\Eloquent\Factories\Factory;

class CompanyFactory extends Factory
{
    protected $model = Company::class;

    public function definition(): array
    {
        $name = $this->faker->company();
        return [
            'name' => $name,
            'slug' => str()->slug($name).'-'.$this->faker->unique()->numberBetween(1, 99999),
        ];
    }
}
```

- [ ] **Step 2.6: Run migration + tests**

Run: `php artisan migrate && vendor/bin/pest tests/Feature/Books/CompanyCrudTest.php`
Expected: PASS (4/4).

- [ ] **Step 2.7: Commit**

```bash
git add database/migrations/2026_05_11_010001_create_book_companies_table.php \
        app/Models/Book/Company.php \
        database/factories/Book/CompanyFactory.php \
        tests/Feature/Books/CompanyCrudTest.php
git commit -m "feat(books): book_companies table + model + INR guard"
```

---

## Task 3: book_fiscal_years migration + FiscalYear model

**Files:**
- Create: `database/migrations/2026_05_11_010002_create_book_fiscal_years_table.php`
- Create: `app/Models/Book/FiscalYear.php`
- Create: `database/factories/Book/FiscalYearFactory.php`
- Test: `tests/Feature/Books/FiscalYearTest.php`

- [ ] **Step 3.1: Write failing test**

```php
<?php

use App\Models\Book\Company;
use App\Models\Book\FiscalYear;

it('creates a FY scoped to a company', function () {
    $c = Company::factory()->create();
    $fy = FiscalYear::create([
        'company_id' => $c->id,
        'start_date' => '2025-04-01',
        'end_date' => '2026-03-31',
        'label' => '2025-26',
    ]);
    expect($fy->is_closed)->toBeFalse();
    expect($fy->closing_summary)->toBeNull();
});

it('enforces unique (company_id, label)', function () {
    $c = Company::factory()->create();
    FiscalYear::create([
        'company_id' => $c->id, 'start_date' => '2025-04-01',
        'end_date' => '2026-03-31', 'label' => '2025-26',
    ]);
    expect(fn () => FiscalYear::create([
        'company_id' => $c->id, 'start_date' => '2025-04-01',
        'end_date' => '2026-03-31', 'label' => '2025-26',
    ]))->toThrow(\Illuminate\Database\QueryException::class);
});

it('casts closing_summary as array', function () {
    $c = Company::factory()->create();
    $fy = FiscalYear::create([
        'company_id' => $c->id, 'start_date' => '2025-04-01',
        'end_date' => '2026-03-31', 'label' => '2025-26',
        'closing_summary_json' => ['net_pl' => 12345],
    ]);
    expect($fy->fresh()->closing_summary)->toBe(['net_pl' => 12345]);
});
```

- [ ] **Step 3.2: Run and verify failure**

Run: `vendor/bin/pest tests/Feature/Books/FiscalYearTest.php`
Expected: FAIL — table not found.

- [ ] **Step 3.3: Create migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('book_fiscal_years', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained('book_companies')->cascadeOnDelete();
            $t->date('start_date');
            $t->date('end_date');
            $t->string('label', 16);
            $t->boolean('is_closed')->default(false);
            $t->json('closing_summary_json')->nullable();
            $t->timestamps();
            $t->softDeletes();
            $t->unique(['company_id', 'label']);
        });
    }
    public function down(): void { Schema::dropIfExists('book_fiscal_years'); }
};
```

- [ ] **Step 3.4: Create model**

```php
<?php

namespace App\Models\Book;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FiscalYear extends Model
{
    use SoftDeletes, HasFactory;

    protected $table = 'book_fiscal_years';
    protected $fillable = [
        'company_id', 'start_date', 'end_date', 'label',
        'is_closed', 'closing_summary_json',
    ];
    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'is_closed' => 'bool',
        'closing_summary_json' => 'array',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function getClosingSummaryAttribute(): ?array
    {
        return $this->closing_summary_json;
    }

    protected static function newFactory()
    {
        return \Database\Factories\Book\FiscalYearFactory::new();
    }
}
```

- [ ] **Step 3.5: Create factory**

```php
<?php

namespace Database\Factories\Book;

use App\Models\Book\Company;
use App\Models\Book\FiscalYear;
use Illuminate\Database\Eloquent\Factories\Factory;

class FiscalYearFactory extends Factory
{
    protected $model = FiscalYear::class;
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'start_date' => '2025-04-01',
            'end_date' => '2026-03-31',
            'label' => '2025-26',
        ];
    }
}
```

- [ ] **Step 3.6: Run migration + tests**

Run: `php artisan migrate && vendor/bin/pest tests/Feature/Books/FiscalYearTest.php`
Expected: PASS (3/3).

- [ ] **Step 3.7: Commit**

```bash
git add database/migrations/2026_05_11_010002_create_book_fiscal_years_table.php \
        app/Models/Book/FiscalYear.php \
        database/factories/Book/FiscalYearFactory.php \
        tests/Feature/Books/FiscalYearTest.php
git commit -m "feat(books): book_fiscal_years + closing_summary cast"
```

---

## Task 4: book_sections migration + Section model + visible_money_columns

**Files:**
- Create: `database/migrations/2026_05_11_010003_create_book_sections_table.php`
- Create: `app/Models/Book/Section.php`
- Create: `database/factories/Book/SectionFactory.php`
- Test: `tests/Feature/Books/SectionTest.php`

- [ ] **Step 4.1: Write failing test**

```php
<?php

use App\Models\Book\Company;
use App\Models\Book\Section;

it('creates a section with default visible_money_columns', function () {
    $c = Company::factory()->create();
    $s = Section::create([
        'company_id' => $c->id,
        'slug' => 'salary',
        'name' => 'Salary',
        'kind' => 'generic',
        'sort_order' => 1,
    ]);
    expect($s->visible_money_columns)->toBe(['salary', 'paid', 'balance']);
});

it('lets admin override visible columns', function () {
    $c = Company::factory()->create();
    $s = Section::create([
        'company_id' => $c->id, 'slug' => 'mixed', 'name' => 'Mixed',
        'kind' => 'generic', 'sort_order' => 1,
        'visible_money_columns' => ['salary', 'loan', 'paid', 'received_back', 'balance', 'loan_outstanding'],
    ]);
    expect($s->fresh()->visible_money_columns)
        ->toBe(['salary', 'loan', 'paid', 'received_back', 'balance', 'loan_outstanding']);
});

it('enforces unique slug per company', function () {
    $c = Company::factory()->create();
    Section::create(['company_id' => $c->id, 'slug' => 'salary', 'name' => 'Salary',
        'kind' => 'generic', 'sort_order' => 1]);
    expect(fn () => Section::create(['company_id' => $c->id, 'slug' => 'salary',
        'name' => 'Other', 'kind' => 'generic', 'sort_order' => 2]))
        ->toThrow(\Illuminate\Database\QueryException::class);
});

it('allows same slug across different companies', function () {
    $a = Company::factory()->create();
    $b = Company::factory()->create();
    Section::create(['company_id' => $a->id, 'slug' => 'salary', 'name' => 'Salary',
        'kind' => 'generic', 'sort_order' => 1]);
    $sB = Section::create(['company_id' => $b->id, 'slug' => 'salary', 'name' => 'Salary',
        'kind' => 'generic', 'sort_order' => 1]);
    expect($sB->id)->toBeInt();
});
```

- [ ] **Step 4.2: Run and verify failure**

Run: `vendor/bin/pest tests/Feature/Books/SectionTest.php`
Expected: FAIL.

- [ ] **Step 4.3: Create migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('book_sections', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained('book_companies')->cascadeOnDelete();
            $t->string('slug');
            $t->string('name');
            $t->enum('kind', ['generic', 'asset'])->default('generic');
            $t->unsignedInteger('sort_order')->default(0);
            $t->string('icon')->nullable();
            $t->json('visible_money_columns')->nullable();
            $t->timestamps();
            $t->softDeletes();
            $t->unique(['company_id', 'slug']);
        });
    }
    public function down(): void { Schema::dropIfExists('book_sections'); }
};
```

- [ ] **Step 4.4: Create model**

```php
<?php

namespace App\Models\Book;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Section extends Model
{
    use SoftDeletes, HasFactory;

    public const DEFAULT_COLUMNS = [
        'salary'  => ['salary', 'paid', 'balance'],
        'loan'    => ['loan', 'received_back', 'loan_outstanding'],
        'rent'    => ['paid'],
        'expense' => ['paid'],
        'asset'   => ['original_value', 'this_year_dep', 'accumulated_dep', 'book_value'],
    ];

    protected $table = 'book_sections';
    protected $fillable = [
        'company_id', 'slug', 'name', 'kind', 'sort_order',
        'icon', 'visible_money_columns',
    ];
    protected $casts = ['visible_money_columns' => 'array'];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function getVisibleMoneyColumnsAttribute($value): array
    {
        if ($value === null) {
            return self::DEFAULT_COLUMNS[$this->slug] ?? ['paid'];
        }
        return is_array($value) ? $value : json_decode($value, true);
    }
}
```

- [ ] **Step 4.5: Create factory**

```php
<?php

namespace Database\Factories\Book;

use App\Models\Book\Company;
use App\Models\Book\Section;
use Illuminate\Database\Eloquent\Factories\Factory;

class SectionFactory extends Factory
{
    protected $model = Section::class;
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'slug' => 'salary',
            'name' => 'Salary',
            'kind' => 'generic',
            'sort_order' => 1,
        ];
    }
}
```

- [ ] **Step 4.6: Run migration + tests**

Run: `php artisan migrate && vendor/bin/pest tests/Feature/Books/SectionTest.php`
Expected: PASS (4/4).

- [ ] **Step 4.7: Commit**

```bash
git add database/migrations/2026_05_11_010003_create_book_sections_table.php \
        app/Models/Book/Section.php database/factories/Book/SectionFactory.php \
        tests/Feature/Books/SectionTest.php
git commit -m "feat(books): book_sections + visible_money_columns with sensible defaults"
```

---

## Task 5: book_entries migration + Entry model (no paid/received_back columns)

**Files:**
- Create: `database/migrations/2026_05_11_010004_create_book_entries_table.php`
- Create: `app/Models/Book/Entry.php`
- Create: `database/factories/Book/EntryFactory.php`
- Test: `tests/Feature/Books/EntryTest.php`

- [ ] **Step 5.1: Write failing test**

```php
<?php

use App\Models\Book\Company;
use App\Models\Book\FiscalYear;
use App\Models\Book\Section;
use App\Models\Book\Entry;

it('creates an entry with money columns defaulting to zero', function () {
    $c = Company::factory()->create();
    $fy = FiscalYear::factory()->create(['company_id' => $c->id]);
    $s = Section::factory()->create(['company_id' => $c->id]);

    $e = Entry::create([
        'company_id' => $c->id, 'fiscal_year_id' => $fy->id, 'section_id' => $s->id,
        'title' => 'Usha', 'salary_amount' => 1200000,
    ]);
    expect((float) $e->salary_amount)->toBe(1200000.0);
    expect((float) $e->loan_amount)->toBe(0.0);
    expect($e->is_loan)->toBeFalse();
});

it('flags is_loan when loan_amount > 0', function () {
    $c = Company::factory()->create();
    $fy = FiscalYear::factory()->create(['company_id' => $c->id]);
    $s = Section::factory()->create(['company_id' => $c->id]);
    $e = Entry::create([
        'company_id' => $c->id, 'fiscal_year_id' => $fy->id, 'section_id' => $s->id,
        'title' => 'Lansdown', 'loan_amount' => 1000000,
    ]);
    expect($e->is_loan)->toBeTrue();
});

it('supports both salary and loan on the same entry', function () {
    $c = Company::factory()->create();
    $fy = FiscalYear::factory()->create(['company_id' => $c->id]);
    $s = Section::factory()->create(['company_id' => $c->id]);
    $e = Entry::create([
        'company_id' => $c->id, 'fiscal_year_id' => $fy->id, 'section_id' => $s->id,
        'title' => 'Mixed', 'salary_amount' => 500000, 'loan_amount' => 200000,
    ]);
    expect($e->is_loan)->toBeTrue();
    expect((float) $e->salary_amount)->toBe(500000.0);
});

it('allows multiple rows with same title in one section', function () {
    $c = Company::factory()->create();
    $fy = FiscalYear::factory()->create(['company_id' => $c->id]);
    $s = Section::factory()->create(['company_id' => $c->id]);
    Entry::create(['company_id' => $c->id, 'fiscal_year_id' => $fy->id,
        'section_id' => $s->id, 'title' => 'Vendor X']);
    $second = Entry::create(['company_id' => $c->id, 'fiscal_year_id' => $fy->id,
        'section_id' => $s->id, 'title' => 'Vendor X']);
    expect($second->id)->toBeInt();
});
```

- [ ] **Step 5.2: Run and verify failure**

Run: `vendor/bin/pest tests/Feature/Books/EntryTest.php`
Expected: FAIL.

- [ ] **Step 5.3: Create migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('book_entries', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained('book_companies')->cascadeOnDelete();
            $t->foreignId('fiscal_year_id')->constrained('book_fiscal_years')->cascadeOnDelete();
            $t->foreignId('section_id')->constrained('book_sections')->cascadeOnDelete();
            $t->string('title');
            $t->decimal('salary_amount', 14, 2)->default(0);
            $t->decimal('loan_amount',   14, 2)->default(0);
            $t->text('notes')->nullable();
            $t->unsignedInteger('sort_order')->default(0);
            $t->timestamps();
            $t->softDeletes();
            $t->index(['company_id', 'fiscal_year_id', 'section_id']);
        });
    }
    public function down(): void { Schema::dropIfExists('book_entries'); }
};
```

- [ ] **Step 5.4: Create model**

```php
<?php

namespace App\Models\Book;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Entry extends Model
{
    use SoftDeletes, HasFactory;

    protected $table = 'book_entries';
    protected $fillable = [
        'company_id', 'fiscal_year_id', 'section_id', 'title',
        'salary_amount', 'loan_amount', 'notes', 'sort_order',
    ];
    protected $casts = [
        'salary_amount' => 'decimal:2',
        'loan_amount' => 'decimal:2',
    ];

    public function company(): BelongsTo { return $this->belongsTo(Company::class); }
    public function fiscalYear(): BelongsTo { return $this->belongsTo(FiscalYear::class); }
    public function section(): BelongsTo { return $this->belongsTo(Section::class); }

    public function getIsLoanAttribute(): bool
    {
        return (float) $this->loan_amount > 0;
    }
}
```

- [ ] **Step 5.5: Create factory**

```php
<?php

namespace Database\Factories\Book;

use App\Models\Book\Company;
use App\Models\Book\Entry;
use App\Models\Book\FiscalYear;
use App\Models\Book\Section;
use Illuminate\Database\Eloquent\Factories\Factory;

class EntryFactory extends Factory
{
    protected $model = Entry::class;
    public function definition(): array
    {
        $c = Company::factory()->create();
        $fy = FiscalYear::factory()->create(['company_id' => $c->id]);
        $s = Section::factory()->create(['company_id' => $c->id]);
        return [
            'company_id' => $c->id,
            'fiscal_year_id' => $fy->id,
            'section_id' => $s->id,
            'title' => $this->faker->name(),
        ];
    }
}
```

- [ ] **Step 5.6: Run migration + tests**

Run: `php artisan migrate && vendor/bin/pest tests/Feature/Books/EntryTest.php`
Expected: PASS (4/4).

- [ ] **Step 5.7: Commit**

```bash
git add database/migrations/2026_05_11_010004_create_book_entries_table.php \
        app/Models/Book/Entry.php database/factories/Book/EntryFactory.php \
        tests/Feature/Books/EntryTest.php
git commit -m "feat(books): book_entries with typed salary+loan and is_loan accessor"
```

---

## Task 6: book_entry_payments + Payment model + paid/received_back computed accessors

**Files:**
- Create: `database/migrations/2026_05_11_010005_create_book_entry_payments_table.php`
- Create: `app/Models/Book/EntryPayment.php`
- Modify: `app/Models/Book/Entry.php` (add accessors + relation)
- Create: `database/factories/Book/EntryPaymentFactory.php`
- Test: `tests/Feature/Books/EntryPaymentTest.php`

- [ ] **Step 6.1: Write failing test**

```php
<?php

use App\Models\Book\Entry;
use App\Models\Book\EntryPayment;

it('computes paid from sum of out-direction payments', function () {
    $e = Entry::factory()->create(['salary_amount' => 1200000]);
    EntryPayment::create(['entry_id' => $e->id, 'amount' => 200000,
        'direction' => 'out', 'mode' => 'bank', 'occurred_on' => '2025-05-01']);
    EntryPayment::create(['entry_id' => $e->id, 'amount' => 300000,
        'direction' => 'out', 'mode' => 'cash', 'occurred_on' => '2025-06-01']);
    expect((float) $e->fresh()->paid)->toBe(500000.0);
});

it('computes received_back from in-direction payments', function () {
    $e = Entry::factory()->create(['loan_amount' => 1000000]);
    EntryPayment::create(['entry_id' => $e->id, 'amount' => 100000,
        'direction' => 'in', 'mode' => 'bank', 'occurred_on' => '2025-07-01']);
    expect((float) $e->fresh()->received_back)->toBe(100000.0);
});

it('computes balance = salary + loan - paid - received_back', function () {
    $e = Entry::factory()->create(['salary_amount' => 1200000, 'loan_amount' => 0]);
    EntryPayment::create(['entry_id' => $e->id, 'amount' => 200000,
        'direction' => 'out', 'mode' => 'bank', 'occurred_on' => '2025-05-01']);
    expect((float) $e->fresh()->balance)->toBe(1000000.0);
});

it('computes loan_outstanding = loan_amount - received_back', function () {
    $e = Entry::factory()->create(['loan_amount' => 1000000]);
    EntryPayment::create(['entry_id' => $e->id, 'amount' => 100000,
        'direction' => 'in', 'mode' => 'bank', 'occurred_on' => '2025-07-01']);
    expect((float) $e->fresh()->loan_outstanding)->toBe(900000.0);
});

it('rejects an invalid direction', function () {
    $e = Entry::factory()->create();
    expect(fn () => EntryPayment::create(['entry_id' => $e->id, 'amount' => 1,
        'direction' => 'sideways', 'mode' => 'bank', 'occurred_on' => '2025-05-01']))
        ->toThrow(\InvalidArgumentException::class);
});
```

- [ ] **Step 6.2: Run and verify failure**

Run: `vendor/bin/pest tests/Feature/Books/EntryPaymentTest.php`
Expected: FAIL.

- [ ] **Step 6.3: Create migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('book_entry_payments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('entry_id')->constrained('book_entries')->cascadeOnDelete();
            $t->date('occurred_on');
            $t->decimal('amount', 14, 2);
            $t->enum('direction', ['out', 'in']);
            $t->enum('mode', ['cash', 'bank', 'upi', 'cheque', 'other']);
            $t->string('reference')->nullable();
            $t->text('notes')->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->softDeletes();
            $t->index(['entry_id', 'occurred_on']);
        });
    }
    public function down(): void { Schema::dropIfExists('book_entry_payments'); }
};
```

- [ ] **Step 6.4: Create EntryPayment model**

```php
<?php

namespace App\Models\Book;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EntryPayment extends Model
{
    use SoftDeletes, HasFactory;

    public const DIRECTIONS = ['out', 'in'];
    public const MODES = ['cash', 'bank', 'upi', 'cheque', 'other'];

    protected $table = 'book_entry_payments';
    protected $fillable = [
        'entry_id', 'occurred_on', 'amount', 'direction', 'mode',
        'reference', 'notes', 'created_by',
    ];
    protected $casts = [
        'occurred_on' => 'date',
        'amount' => 'decimal:2',
    ];

    protected static function booted(): void
    {
        static::saving(function (EntryPayment $p) {
            if (! in_array($p->direction, self::DIRECTIONS, true)) {
                throw new \InvalidArgumentException("Invalid direction: {$p->direction}");
            }
            if (! in_array($p->mode, self::MODES, true)) {
                throw new \InvalidArgumentException("Invalid mode: {$p->mode}");
            }
        });
    }

    public function entry(): BelongsTo { return $this->belongsTo(Entry::class); }
}
```

- [ ] **Step 6.5: Add accessors + relation to Entry model**

In `app/Models/Book/Entry.php`, add at end of class:

```php
public function payments(): HasMany
{
    return $this->hasMany(EntryPayment::class, 'entry_id');
}

public function getPaidAttribute(): string
{
    return (string) $this->payments()->where('direction', 'out')->sum('amount');
}

public function getReceivedBackAttribute(): string
{
    return (string) $this->payments()->where('direction', 'in')->sum('amount');
}

public function getBalanceAttribute(): string
{
    return (string) (
        (float) $this->salary_amount + (float) $this->loan_amount
        - (float) $this->paid - (float) $this->received_back
    );
}

public function getLoanOutstandingAttribute(): string
{
    return (string) ((float) $this->loan_amount - (float) $this->received_back);
}
```

- [ ] **Step 6.6: Create factory**

```php
<?php

namespace Database\Factories\Book;

use App\Models\Book\Entry;
use App\Models\Book\EntryPayment;
use Illuminate\Database\Eloquent\Factories\Factory;

class EntryPaymentFactory extends Factory
{
    protected $model = EntryPayment::class;
    public function definition(): array
    {
        return [
            'entry_id' => Entry::factory(),
            'occurred_on' => '2025-05-01',
            'amount' => 10000,
            'direction' => 'out',
            'mode' => 'bank',
        ];
    }
}
```

- [ ] **Step 6.7: Run and verify pass**

Run: `php artisan migrate && vendor/bin/pest tests/Feature/Books/EntryPaymentTest.php`
Expected: PASS (5/5).

- [ ] **Step 6.8: Commit**

```bash
git add database/migrations/2026_05_11_010005_create_book_entry_payments_table.php \
        app/Models/Book/EntryPayment.php app/Models/Book/Entry.php \
        database/factories/Book/EntryPaymentFactory.php \
        tests/Feature/Books/EntryPaymentTest.php
git commit -m "feat(books): payment sub-table + computed paid/received_back/balance"
```

---

## Task 7: book_assets + Asset model

**Files:**
- Create: `database/migrations/2026_05_11_010006_create_book_assets_table.php`
- Create: `app/Models/Book/Asset.php`
- Create: `database/factories/Book/AssetFactory.php`
- Test: `tests/Feature/Books/AssetTest.php`

- [ ] **Step 7.1: Write failing test**

```php
<?php

use App\Models\Book\Asset;
use App\Models\Book\Entry;

it('creates an asset linked 1:1 to an entry', function () {
    $e = Entry::factory()->create(['title' => 'Car']);
    $a = Asset::create([
        'entry_id' => $e->id,
        'original_value' => 300000,
        'dep_percent' => 20,
        'dep_years' => 5,
        'dep_started_at' => '2025-04-01',
        'method' => 'straight_line',
    ]);
    expect((float) $a->original_value)->toBe(300000.0);
    expect($a->entry->title)->toBe('Car');
});

it('enforces unique entry_id (1:1)', function () {
    $e = Entry::factory()->create();
    Asset::create(['entry_id' => $e->id, 'original_value' => 100,
        'dep_percent' => 10, 'dep_years' => 5, 'dep_started_at' => '2025-04-01',
        'method' => 'straight_line']);
    expect(fn () => Asset::create(['entry_id' => $e->id, 'original_value' => 200,
        'dep_percent' => 10, 'dep_years' => 5, 'dep_started_at' => '2025-04-01',
        'method' => 'straight_line']))
        ->toThrow(\Illuminate\Database\QueryException::class);
});

it('rejects invalid method', function () {
    $e = Entry::factory()->create();
    expect(fn () => Asset::create(['entry_id' => $e->id, 'original_value' => 100,
        'dep_percent' => 10, 'dep_years' => 5, 'dep_started_at' => '2025-04-01',
        'method' => 'martian']))
        ->toThrow(\InvalidArgumentException::class);
});
```

- [ ] **Step 7.2: Run and verify failure**

Run: `vendor/bin/pest tests/Feature/Books/AssetTest.php`
Expected: FAIL.

- [ ] **Step 7.3: Create migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('book_assets', function (Blueprint $t) {
            $t->id();
            $t->foreignId('entry_id')->unique()->constrained('book_entries')->cascadeOnDelete();
            $t->decimal('original_value', 14, 2);
            $t->decimal('dep_percent', 5, 2);
            $t->unsignedInteger('dep_years');
            $t->date('dep_started_at');
            $t->enum('method', ['straight_line', 'wdv'])->default('straight_line');
            $t->timestamps();
        });
    }
    public function down(): void { Schema::dropIfExists('book_assets'); }
};
```

- [ ] **Step 7.4: Create Asset model**

```php
<?php

namespace App\Models\Book;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Asset extends Model
{
    use HasFactory;

    public const METHODS = ['straight_line', 'wdv'];

    protected $table = 'book_assets';
    protected $fillable = [
        'entry_id', 'original_value', 'dep_percent', 'dep_years',
        'dep_started_at', 'method',
    ];
    protected $casts = [
        'dep_started_at' => 'date',
        'original_value' => 'decimal:2',
        'dep_percent' => 'decimal:2',
        'dep_years' => 'int',
    ];

    protected static function booted(): void
    {
        static::saving(function (Asset $a) {
            if (! in_array($a->method, self::METHODS, true)) {
                throw new \InvalidArgumentException("Invalid method: {$a->method}");
            }
        });
    }

    public function entry(): BelongsTo { return $this->belongsTo(Entry::class); }
}
```

- [ ] **Step 7.5: Create factory**

```php
<?php

namespace Database\Factories\Book;

use App\Models\Book\Asset;
use App\Models\Book\Entry;
use Illuminate\Database\Eloquent\Factories\Factory;

class AssetFactory extends Factory
{
    protected $model = Asset::class;
    public function definition(): array
    {
        return [
            'entry_id' => Entry::factory(),
            'original_value' => 300000,
            'dep_percent' => 20,
            'dep_years' => 5,
            'dep_started_at' => '2025-04-01',
            'method' => 'straight_line',
        ];
    }
}
```

- [ ] **Step 7.6: Run and verify pass**

Run: `php artisan migrate && vendor/bin/pest tests/Feature/Books/AssetTest.php`
Expected: PASS (3/3).

- [ ] **Step 7.7: Commit**

```bash
git add database/migrations/2026_05_11_010006_create_book_assets_table.php \
        app/Models/Book/Asset.php database/factories/Book/AssetFactory.php \
        tests/Feature/Books/AssetTest.php
git commit -m "feat(books): book_assets 1:1 to entry with method validation"
```

---

## Task 8: book_income_entries + IncomeEntry model

**Files:**
- Create: `database/migrations/2026_05_11_010007_create_book_income_entries_table.php`
- Create: `app/Models/Book/IncomeEntry.php`
- Create: `database/factories/Book/IncomeEntryFactory.php`
- Test: `tests/Feature/Books/IncomeEntryTest.php`

- [ ] **Step 8.1: Write failing test**

```php
<?php

use App\Models\Book\Company;
use App\Models\Book\FiscalYear;
use App\Models\Book\IncomeEntry;

it('creates an income entry scoped to company+fy', function () {
    $c = Company::factory()->create();
    $fy = FiscalYear::factory()->create(['company_id' => $c->id]);
    $i = IncomeEntry::create([
        'company_id' => $c->id, 'fiscal_year_id' => $fy->id,
        'occurred_on' => '2025-05-15', 'source' => 'Client A',
        'amount' => 500000, 'notes' => 'invoice INV-001',
    ]);
    expect($i->source)->toBe('Client A');
    expect((float) $i->amount)->toBe(500000.0);
});

it('sums income per FY', function () {
    $c = Company::factory()->create();
    $fy = FiscalYear::factory()->create(['company_id' => $c->id]);
    IncomeEntry::create(['company_id' => $c->id, 'fiscal_year_id' => $fy->id,
        'occurred_on' => '2025-04-15', 'source' => 'A', 'amount' => 1000000]);
    IncomeEntry::create(['company_id' => $c->id, 'fiscal_year_id' => $fy->id,
        'occurred_on' => '2025-05-15', 'source' => 'B', 'amount' => 500000]);
    expect((float) IncomeEntry::where('fiscal_year_id', $fy->id)->sum('amount'))
        ->toBe(1500000.0);
});
```

- [ ] **Step 8.2: Run and verify failure**

Run: `vendor/bin/pest tests/Feature/Books/IncomeEntryTest.php`
Expected: FAIL.

- [ ] **Step 8.3: Create migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('book_income_entries', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained('book_companies')->cascadeOnDelete();
            $t->foreignId('fiscal_year_id')->constrained('book_fiscal_years')->cascadeOnDelete();
            $t->date('occurred_on');
            $t->string('source');
            $t->decimal('amount', 14, 2);
            $t->text('notes')->nullable();
            $t->timestamps();
            $t->softDeletes();
            $t->index(['company_id', 'fiscal_year_id', 'occurred_on']);
        });
    }
    public function down(): void { Schema::dropIfExists('book_income_entries'); }
};
```

- [ ] **Step 8.4: Create model + factory**

```php
// app/Models/Book/IncomeEntry.php
<?php

namespace App\Models\Book;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IncomeEntry extends Model
{
    use SoftDeletes, HasFactory;

    protected $table = 'book_income_entries';
    protected $fillable = ['company_id', 'fiscal_year_id', 'occurred_on',
        'source', 'amount', 'notes'];
    protected $casts = ['occurred_on' => 'date', 'amount' => 'decimal:2'];

    public function company(): BelongsTo { return $this->belongsTo(Company::class); }
    public function fiscalYear(): BelongsTo { return $this->belongsTo(FiscalYear::class); }
}
```

```php
// database/factories/Book/IncomeEntryFactory.php
<?php

namespace Database\Factories\Book;

use App\Models\Book\Company;
use App\Models\Book\FiscalYear;
use App\Models\Book\IncomeEntry;
use Illuminate\Database\Eloquent\Factories\Factory;

class IncomeEntryFactory extends Factory
{
    protected $model = IncomeEntry::class;
    public function definition(): array
    {
        $c = Company::factory()->create();
        return [
            'company_id' => $c->id,
            'fiscal_year_id' => FiscalYear::factory()->create(['company_id' => $c->id]),
            'occurred_on' => '2025-05-15',
            'source' => $this->faker->company(),
            'amount' => 100000,
        ];
    }
}
```

- [ ] **Step 8.5: Run and verify pass**

Run: `php artisan migrate && vendor/bin/pest tests/Feature/Books/IncomeEntryTest.php`
Expected: PASS (2/2).

- [ ] **Step 8.6: Commit**

```bash
git add database/migrations/2026_05_11_010007_create_book_income_entries_table.php \
        app/Models/Book/IncomeEntry.php database/factories/Book/IncomeEntryFactory.php \
        tests/Feature/Books/IncomeEntryTest.php
git commit -m "feat(books): book_income_entries for itemized FY income"
```

---

## Task 9: book_fields + book_field_values + Field model (Phase-A-style)

**Files:**
- Create: `database/migrations/2026_05_11_010008_create_book_fields_table.php`
- Create: `database/migrations/2026_05_11_010009_create_book_field_values_table.php`
- Create: `app/Models/Book/Field.php`
- Create: `app/Models/Book/FieldValue.php`
- Create: `database/factories/Book/FieldFactory.php`
- Test: `tests/Feature/Books/FieldTest.php`

- [ ] **Step 9.1: Write failing test**

```php
<?php

use App\Models\Book\Company;
use App\Models\Book\Section;
use App\Models\Book\Entry;
use App\Models\Book\Field;
use App\Models\Book\FieldValue;

it('creates a custom field scoped to a section', function () {
    $c = Company::factory()->create();
    $s = Section::factory()->create(['company_id' => $c->id, 'slug' => 'salary']);
    $f = Field::create([
        'company_id' => $c->id, 'section_id' => $s->id,
        'key' => 'pan', 'label' => 'PAN', 'type' => 'text',
        'sort_order' => 1, 'show_in_table' => true,
    ]);
    expect($f->section->slug)->toBe('salary');
});

it('stores a text value for an entry+field pair', function () {
    $c = Company::factory()->create();
    $s = Section::factory()->create(['company_id' => $c->id]);
    $e = Entry::factory()->create(['company_id' => $c->id, 'section_id' => $s->id]);
    $f = Field::create(['company_id' => $c->id, 'section_id' => $s->id,
        'key' => 'pan', 'label' => 'PAN', 'type' => 'text', 'sort_order' => 1]);

    $v = FieldValue::create(['entry_id' => $e->id, 'field_id' => $f->id,
        'value_text' => 'ABCDE1234F']);
    expect($v->value_text)->toBe('ABCDE1234F');
});

it('enforces unique (entry, field) pair', function () {
    $c = Company::factory()->create();
    $s = Section::factory()->create(['company_id' => $c->id]);
    $e = Entry::factory()->create(['company_id' => $c->id, 'section_id' => $s->id]);
    $f = Field::create(['company_id' => $c->id, 'section_id' => $s->id,
        'key' => 'pan', 'label' => 'PAN', 'type' => 'text', 'sort_order' => 1]);
    FieldValue::create(['entry_id' => $e->id, 'field_id' => $f->id, 'value_text' => 'A']);
    expect(fn () => FieldValue::create(['entry_id' => $e->id, 'field_id' => $f->id,
        'value_text' => 'B']))->toThrow(\Illuminate\Database\QueryException::class);
});
```

- [ ] **Step 9.2: Run and verify failure**

Run: `vendor/bin/pest tests/Feature/Books/FieldTest.php`
Expected: FAIL.

- [ ] **Step 9.3: Create fields migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('book_fields', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained('book_companies')->cascadeOnDelete();
            $t->foreignId('section_id')->nullable()
                ->constrained('book_sections')->cascadeOnDelete();
            $t->string('key');
            $t->string('label');
            $t->string('type', 32);
            $t->json('options_json')->nullable();
            $t->boolean('is_required')->default(false);
            $t->boolean('show_in_table')->default(false);
            $t->boolean('is_built_in')->default(false);
            $t->unsignedInteger('sort_order')->default(0);
            $t->timestamp('archived_at')->nullable();
            $t->timestamps();
            $t->unique(['company_id', 'section_id', 'key']);
        });
    }
    public function down(): void { Schema::dropIfExists('book_fields'); }
};
```

- [ ] **Step 9.4: Create field_values migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('book_field_values', function (Blueprint $t) {
            $t->id();
            $t->foreignId('entry_id')->constrained('book_entries')->cascadeOnDelete();
            $t->foreignId('field_id')->constrained('book_fields')->cascadeOnDelete();
            // value_text limited to 191 to fit MySQL utf8mb4 key length on unique
            if (config('database.default') === 'mysql') {
                $t->string('value_text', 191)->nullable();
            } else {
                $t->text('value_text')->nullable();
            }
            $t->decimal('value_number', 18, 4)->nullable();
            $t->date('value_date')->nullable();
            $t->json('value_json')->nullable();
            $t->foreignId('value_attachment_id')->nullable();
            $t->timestamps();
            $t->unique(['entry_id', 'field_id']);
        });
    }
    public function down(): void { Schema::dropIfExists('book_field_values'); }
};
```

- [ ] **Step 9.5: Create Field model**

```php
<?php

namespace App\Models\Book;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Field extends Model
{
    use HasFactory;

    public const TYPES = ['text','textarea','number','date','email',
                         'dropdown','checkbox','multiselect','file'];

    protected $table = 'book_fields';
    protected $fillable = ['company_id','section_id','key','label','type',
        'options_json','is_required','show_in_table','is_built_in',
        'sort_order','archived_at'];
    protected $casts = [
        'options_json' => 'array',
        'is_required' => 'bool',
        'show_in_table' => 'bool',
        'is_built_in' => 'bool',
        'archived_at' => 'datetime',
    ];

    public function section(): BelongsTo { return $this->belongsTo(Section::class); }
    public function company(): BelongsTo { return $this->belongsTo(Company::class); }
}
```

- [ ] **Step 9.6: Create FieldValue model**

```php
<?php

namespace App\Models\Book;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FieldValue extends Model
{
    protected $table = 'book_field_values';
    protected $fillable = ['entry_id','field_id','value_text','value_number',
        'value_date','value_json','value_attachment_id'];
    protected $casts = [
        'value_number' => 'decimal:4',
        'value_date' => 'date',
        'value_json' => 'array',
    ];

    public function entry(): BelongsTo { return $this->belongsTo(Entry::class); }
    public function field(): BelongsTo { return $this->belongsTo(Field::class); }
}
```

- [ ] **Step 9.7: Create factory**

```php
<?php

namespace Database\Factories\Book;

use App\Models\Book\Company;
use App\Models\Book\Section;
use App\Models\Book\Field;
use Illuminate\Database\Eloquent\Factories\Factory;

class FieldFactory extends Factory
{
    protected $model = Field::class;
    public function definition(): array
    {
        $c = Company::factory()->create();
        return [
            'company_id' => $c->id,
            'section_id' => Section::factory()->create(['company_id' => $c->id])->id,
            'key' => 'pan_'.$this->faker->unique()->randomNumber(),
            'label' => 'PAN',
            'type' => 'text',
            'sort_order' => 1,
        ];
    }
}
```

- [ ] **Step 9.8: Run and verify pass**

Run: `php artisan migrate && vendor/bin/pest tests/Feature/Books/FieldTest.php`
Expected: PASS (3/3).

- [ ] **Step 9.9: Commit**

```bash
git add database/migrations/2026_05_11_010008_create_book_fields_table.php \
        database/migrations/2026_05_11_010009_create_book_field_values_table.php \
        app/Models/Book/Field.php app/Models/Book/FieldValue.php \
        database/factories/Book/FieldFactory.php \
        tests/Feature/Books/FieldTest.php
git commit -m "feat(books): custom fields + values (Phase-A pattern, scoped per section)"
```

---

## Task 10: book_attachments (polymorphic) + Attachment model

**Files:**
- Create: `database/migrations/2026_05_11_010010_create_book_attachments_table.php`
- Create: `app/Models/Book/Attachment.php`
- Test: `tests/Feature/Books/AttachmentTest.php`

- [ ] **Step 10.1: Write failing test**

```php
<?php

use App\Models\Book\Entry;
use App\Models\Book\Attachment;

it('attaches a file polymorphically to an entry', function () {
    $e = Entry::factory()->create();
    $a = Attachment::create([
        'attachable_type' => $e::class,
        'attachable_id' => $e->id,
        'disk' => 'gdrive',
        'path' => 'books/test/2025-26/salary/1/file.pdf',
        'original_name' => 'salary-slip.pdf',
        'mime' => 'application/pdf',
        'size' => 12345,
        'uploaded_by' => null,
    ]);
    expect($a->attachable_id)->toBe($e->id);
    expect($e->attachments()->count())->toBe(1);
});
```

- [ ] **Step 10.2: Run and verify failure**

Run: `vendor/bin/pest tests/Feature/Books/AttachmentTest.php`
Expected: FAIL.

- [ ] **Step 10.3: Create migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('book_attachments', function (Blueprint $t) {
            $t->id();
            $t->morphs('attachable'); // creates attachable_id + attachable_type + index
            $t->string('disk', 32)->default('gdrive');
            $t->string('path');
            $t->string('original_name');
            $t->string('mime', 128)->nullable();
            $t->unsignedBigInteger('size')->nullable();
            $t->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('uploaded_at')->useCurrent();
            $t->timestamps();
        });
    }
    public function down(): void { Schema::dropIfExists('book_attachments'); }
};
```

- [ ] **Step 10.4: Create Attachment model**

```php
<?php

namespace App\Models\Book;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Attachment extends Model
{
    protected $table = 'book_attachments';
    protected $fillable = ['attachable_type', 'attachable_id', 'disk', 'path',
        'original_name', 'mime', 'size', 'uploaded_by', 'uploaded_at'];
    protected $casts = ['uploaded_at' => 'datetime'];

    public function attachable(): MorphTo { return $this->morphTo(); }
}
```

- [ ] **Step 10.5: Wire morph relation on Entry**

Add to `app/Models/Book/Entry.php`:

```php
use Illuminate\Database\Eloquent\Relations\MorphMany;

public function attachments(): MorphMany
{
    return $this->morphMany(Attachment::class, 'attachable');
}
```

Also add the same `attachments()` method to `app/Models/Book/FieldValue.php`.

- [ ] **Step 10.6: Run and verify pass**

Run: `php artisan migrate && vendor/bin/pest tests/Feature/Books/AttachmentTest.php`
Expected: PASS (1/1).

- [ ] **Step 10.7: Commit**

```bash
git add database/migrations/2026_05_11_010010_create_book_attachments_table.php \
        app/Models/Book/Attachment.php app/Models/Book/Entry.php \
        app/Models/Book/FieldValue.php tests/Feature/Books/AttachmentTest.php
git commit -m "feat(books): polymorphic attachments (entry OR field_value)"
```

---

## Task 11: FiscalYearAggregator service (income, cash_outflow, non_cash_outflow, net_pl)

**Files:**
- Create: `app/Books/Services/FiscalYearAggregator.php`
- Test: `tests/Unit/Books/FiscalYearAggregatorTest.php`

- [ ] **Step 11.1: Write failing test**

```php
<?php

use App\Books\Services\FiscalYearAggregator;
use App\Models\Book\Company;
use App\Models\Book\FiscalYear;
use App\Models\Book\IncomeEntry;
use App\Models\Book\Section;
use App\Models\Book\Entry;
use App\Models\Book\EntryPayment;

function makeFyWithRows(): array {
    $c = Company::factory()->create();
    $fy = FiscalYear::factory()->create(['company_id' => $c->id,
        'start_date' => '2025-04-01', 'end_date' => '2026-03-31', 'label' => '2025-26']);
    $s = Section::factory()->create(['company_id' => $c->id, 'slug' => 'salary']);
    IncomeEntry::create(['company_id' => $c->id, 'fiscal_year_id' => $fy->id,
        'occurred_on' => '2025-04-15', 'source' => 'A', 'amount' => 12500000]);
    $e = Entry::create(['company_id' => $c->id, 'fiscal_year_id' => $fy->id,
        'section_id' => $s->id, 'title' => 'Usha', 'salary_amount' => 1200000]);
    EntryPayment::create(['entry_id' => $e->id, 'occurred_on' => '2025-05-01',
        'amount' => 200000, 'direction' => 'out', 'mode' => 'bank']);
    return [$c, $fy];
}

it('sums total_income across the FY', function () {
    [, $fy] = makeFyWithRows();
    $agg = new FiscalYearAggregator();
    expect((float) $agg->totalIncome($fy))->toBe(12500000.0);
});

it('sums cash_outflow from payments direction=out', function () {
    [, $fy] = makeFyWithRows();
    $agg = new FiscalYearAggregator();
    expect((float) $agg->cashOutflow($fy))->toBe(200000.0);
});

it('returns zero non_cash_outflow when no asset sections', function () {
    [, $fy] = makeFyWithRows();
    $agg = new FiscalYearAggregator();
    expect((float) $agg->nonCashOutflow($fy))->toBe(0.0);
});

it('computes net_pl = income + recoveries - total_outflow', function () {
    [, $fy] = makeFyWithRows();
    $agg = new FiscalYearAggregator();
    // 12500000 + 0 - (200000 + 0) = 12300000
    expect((float) $agg->netPl($fy))->toBe(12300000.0);
});
```

- [ ] **Step 11.2: Run and verify failure**

Run: `vendor/bin/pest tests/Unit/Books/FiscalYearAggregatorTest.php`
Expected: FAIL — class not found.

- [ ] **Step 11.3: Create service**

```php
<?php

namespace App\Books\Services;

use App\Models\Book\FiscalYear;
use App\Models\Book\EntryPayment;
use App\Models\Book\IncomeEntry;
use App\Models\Book\Entry;
use App\Models\Book\Section;
use App\Models\Book\Asset;

class FiscalYearAggregator
{
    public function __construct(
        private ?DepreciationCalculator $dep = null,
    ) {
        $this->dep ??= new DepreciationCalculator();
    }

    public function totalIncome(FiscalYear $fy): float
    {
        return (float) IncomeEntry::where('fiscal_year_id', $fy->id)->sum('amount');
    }

    public function cashOutflow(FiscalYear $fy): float
    {
        return (float) EntryPayment::whereHas('entry',
                fn ($q) => $q->where('fiscal_year_id', $fy->id))
            ->where('direction', 'out')->sum('amount');
    }

    public function cashInflowFromRecoveries(FiscalYear $fy): float
    {
        return (float) EntryPayment::whereHas('entry',
                fn ($q) => $q->where('fiscal_year_id', $fy->id))
            ->where('direction', 'in')->sum('amount');
    }

    public function nonCashOutflow(FiscalYear $fy): float
    {
        $total = 0.0;
        $entries = Entry::where('fiscal_year_id', $fy->id)
            ->whereHas('section', fn ($q) => $q->where('kind', 'asset'))
            ->with('section')->get();
        foreach ($entries as $entry) {
            $asset = Asset::where('entry_id', $entry->id)->first();
            if (! $asset) continue;
            $total += (float) $this->dep->yearlyDepFor($asset, $fy);
        }
        return $total;
    }

    public function totalOutflow(FiscalYear $fy): float
    {
        return $this->cashOutflow($fy) + $this->nonCashOutflow($fy);
    }

    public function netPl(FiscalYear $fy): float
    {
        return $this->totalIncome($fy)
             + $this->cashInflowFromRecoveries($fy)
             - $this->totalOutflow($fy);
    }

    public function carryover(FiscalYear $fy): array
    {
        $prior = FiscalYear::where('company_id', $fy->company_id)
            ->where('end_date', '<', $fy->start_date)
            ->orderByDesc('end_date')->first();
        if (! $prior) return ['value' => 0.0, 'estimate' => false];
        if ($prior->is_closed && $prior->closing_summary) {
            return [
                'value' => (float) ($prior->closing_summary['net_pl'] ?? 0),
                'estimate' => false,
            ];
        }
        return ['value' => $this->netPl($prior), 'estimate' => true];
    }
}
```

- [ ] **Step 11.4: Run and verify pass**

Run: `vendor/bin/pest tests/Unit/Books/FiscalYearAggregatorTest.php`
Expected: PASS (4/4). (Note: `DepreciationCalculator` is referenced but not yet built — its stub method must return 0; create that stub next step.)

- [ ] **Step 11.5: Create DepreciationCalculator stub**

```php
<?php

namespace App\Books\Services;

use App\Models\Book\Asset;
use App\Models\Book\FiscalYear;

class DepreciationCalculator
{
    public function yearlyDepFor(Asset $asset, FiscalYear $fy): float
    {
        return 0.0; // stub — filled in Task 12
    }

    public function accumulatedDepThrough(Asset $asset, FiscalYear $fy): float
    {
        return 0.0;
    }

    public function bookValueAtEndOf(Asset $asset, FiscalYear $fy): float
    {
        return (float) $asset->original_value;
    }
}
```

- [ ] **Step 11.6: Re-run tests**

Run: `vendor/bin/pest tests/Unit/Books/FiscalYearAggregatorTest.php`
Expected: PASS (4/4).

- [ ] **Step 11.7: Commit**

```bash
git add app/Books/Services/FiscalYearAggregator.php \
        app/Books/Services/DepreciationCalculator.php \
        tests/Unit/Books/FiscalYearAggregatorTest.php
git commit -m "feat(books): FiscalYearAggregator for FY-level KPIs"
```

---

## Task 12: DepreciationCalculator — Straight Line + WDV + accumulated

**Files:**
- Modify: `app/Books/Services/DepreciationCalculator.php` (replace stub)
- Test: `tests/Unit/Books/DepreciationCalculatorTest.php`

- [ ] **Step 12.1: Write failing test**

```php
<?php

use App\Books\Services\DepreciationCalculator;
use App\Models\Book\Asset;
use App\Models\Book\Company;
use App\Models\Book\FiscalYear;
use App\Models\Book\Section;
use App\Models\Book\Entry;

function makeAsset(array $assetAttrs = []): array {
    $c = Company::factory()->create();
    $fy25 = FiscalYear::factory()->create(['company_id' => $c->id,
        'start_date' => '2025-04-01', 'end_date' => '2026-03-31', 'label' => '2025-26']);
    $fy26 = FiscalYear::factory()->create(['company_id' => $c->id,
        'start_date' => '2026-04-01', 'end_date' => '2027-03-31', 'label' => '2026-27']);
    $s = Section::factory()->create(['company_id' => $c->id, 'kind' => 'asset',
        'slug' => 'assets']);
    $e = Entry::factory()->create(['company_id' => $c->id, 'section_id' => $s->id,
        'fiscal_year_id' => $fy25->id, 'title' => 'Car']);
    $asset = Asset::create(array_merge([
        'entry_id' => $e->id,
        'original_value' => 300000,
        'dep_percent' => 20,
        'dep_years' => 5,
        'dep_started_at' => '2025-04-01',
        'method' => 'straight_line',
    ], $assetAttrs));
    return [$asset, $fy25, $fy26];
}

it('computes straight-line dep for full FY', function () {
    [$asset, $fy25] = makeAsset();
    $calc = new DepreciationCalculator();
    expect((float) $calc->yearlyDepFor($asset, $fy25))->toBe(60000.0); // 300k * 20%
});

it('prorates straight-line dep when started mid-FY', function () {
    [$asset, $fy25] = makeAsset(['dep_started_at' => '2025-10-01']); // 182 days in fy25 of 365
    $calc = new DepreciationCalculator();
    $expected = 300000 * 0.20 * (182 / 365);
    expect((float) $calc->yearlyDepFor($asset, $fy25))->toEqualWithDelta($expected, 1.0);
});

it('computes WDV dep on declining book value', function () {
    [$asset, $fy25, $fy26] = makeAsset(['method' => 'wdv']);
    $calc = new DepreciationCalculator();
    expect((float) $calc->yearlyDepFor($asset, $fy25))->toBe(60000.0);
    // year 2 = (300k - 60k) * 20% = 48000
    expect((float) $calc->yearlyDepFor($asset, $fy26))->toBe(48000.0);
});

it('accumulates dep across closed prior FYs', function () {
    [$asset, $fy25, $fy26] = makeAsset();
    $fy25->update(['is_closed' => true]);
    $calc = new DepreciationCalculator();
    expect((float) $calc->accumulatedDepThrough($asset, $fy26))->toBe(120000.0);
});

it('computes book value at end of FY', function () {
    [$asset, $fy25] = makeAsset();
    $calc = new DepreciationCalculator();
    expect((float) $calc->bookValueAtEndOf($asset, $fy25))->toBe(240000.0);
});
```

- [ ] **Step 12.2: Run and verify failure**

Run: `vendor/bin/pest tests/Unit/Books/DepreciationCalculatorTest.php`
Expected: FAIL (stub returns 0).

- [ ] **Step 12.3: Replace the stub**

```php
<?php

namespace App\Books\Services;

use App\Models\Book\Asset;
use App\Models\Book\FiscalYear;
use Carbon\CarbonImmutable;

class DepreciationCalculator
{
    public function yearlyDepFor(Asset $asset, FiscalYear $fy): float
    {
        $start = CarbonImmutable::parse($fy->start_date);
        $end   = CarbonImmutable::parse($fy->end_date);
        $depStart = CarbonImmutable::parse($asset->dep_started_at);

        if ($depStart->greaterThan($end)) return 0.0;

        $effectiveStart = $depStart->greaterThan($start) ? $depStart : $start;
        $daysInFy = $end->diffInDays($start) + 1;
        $effectiveDays = $end->diffInDays($effectiveStart) + 1;
        $rate = (float) $asset->dep_percent / 100.0;

        if ($asset->method === 'wdv') {
            $bookValueAtStart = $this->bookValueAtEndOfPriorTo($asset, $fy);
            $proration = min(1.0, $effectiveDays / $daysInFy);
            return round($bookValueAtStart * $rate * $proration, 2);
        }

        // straight line
        $proration = min(1.0, $effectiveDays / $daysInFy);
        return round((float) $asset->original_value * $rate * $proration, 2);
    }

    public function accumulatedDepThrough(Asset $asset, FiscalYear $through): float
    {
        $accum = 0.0;
        $priorYears = FiscalYear::where('company_id', $through->company_id)
            ->where('end_date', '<=', $through->end_date)
            ->where('start_date', '>=', $asset->dep_started_at)
            ->orderBy('start_date')->get();
        foreach ($priorYears as $fy) {
            $accum += $this->yearlyDepFor($asset, $fy);
        }
        return round($accum, 2);
    }

    public function bookValueAtEndOf(Asset $asset, FiscalYear $fy): float
    {
        return round(
            (float) $asset->original_value - $this->accumulatedDepThrough($asset, $fy),
            2
        );
    }

    private function bookValueAtEndOfPriorTo(Asset $asset, FiscalYear $fy): float
    {
        $prior = FiscalYear::where('company_id', $fy->company_id)
            ->where('end_date', '<', $fy->start_date)
            ->orderByDesc('end_date')->first();
        if (! $prior) return (float) $asset->original_value;
        return $this->bookValueAtEndOf($asset, $prior);
    }
}
```

- [ ] **Step 12.4: Run and verify pass**

Run: `vendor/bin/pest tests/Unit/Books/DepreciationCalculatorTest.php`
Expected: PASS (5/5).

- [ ] **Step 12.5: Commit**

```bash
git add app/Books/Services/DepreciationCalculator.php \
        tests/Unit/Books/DepreciationCalculatorTest.php
git commit -m "feat(books): depreciation engine — SL + WDV + accumulated + book value"
```

---

## Task 13: ClosingSnapshotWriter + closed-FY write guard

**Files:**
- Create: `app/Books/Services/ClosingSnapshotWriter.php`
- Modify: `app/Models/Book/Entry.php`
- Modify: `app/Models/Book/EntryPayment.php`
- Modify: `app/Models/Book/IncomeEntry.php`
- Test: `tests/Feature/Books/FiscalYearClosingTest.php`

- [ ] **Step 13.1: Write failing test**

```php
<?php

use App\Books\Services\ClosingSnapshotWriter;
use App\Models\Book\Company;
use App\Models\Book\FiscalYear;
use App\Models\Book\Section;
use App\Models\Book\Entry;
use App\Models\Book\EntryPayment;
use App\Models\Book\IncomeEntry;

it('writes a closing snapshot on close', function () {
    $c = Company::factory()->create();
    $fy = FiscalYear::factory()->create(['company_id' => $c->id]);
    IncomeEntry::create(['company_id' => $c->id, 'fiscal_year_id' => $fy->id,
        'occurred_on' => '2025-04-15', 'source' => 'A', 'amount' => 1000000]);

    (new ClosingSnapshotWriter())->close($fy);
    $fy->refresh();
    expect($fy->is_closed)->toBeTrue();
    expect($fy->closing_summary['total_income'])->toBe(1000000.0);
});

it('nulls snapshot on reopen', function () {
    $c = Company::factory()->create();
    $fy = FiscalYear::factory()->create(['company_id' => $c->id]);
    (new ClosingSnapshotWriter())->close($fy);
    (new ClosingSnapshotWriter())->reopen($fy->fresh());
    $fy->refresh();
    expect($fy->is_closed)->toBeFalse();
    expect($fy->closing_summary_json)->toBeNull();
});

it('blocks writes to entries in a closed FY', function () {
    $c = Company::factory()->create();
    $fy = FiscalYear::factory()->create(['company_id' => $c->id, 'is_closed' => true]);
    $s = Section::factory()->create(['company_id' => $c->id]);
    expect(fn () => Entry::create(['company_id' => $c->id, 'fiscal_year_id' => $fy->id,
        'section_id' => $s->id, 'title' => 'late row']))
        ->toThrow(\DomainException::class, 'closed');
});

it('blocks writes to payments whose entry is in a closed FY', function () {
    $c = Company::factory()->create();
    $fy = FiscalYear::factory()->create(['company_id' => $c->id]);
    $s = Section::factory()->create(['company_id' => $c->id]);
    $e = Entry::create(['company_id' => $c->id, 'fiscal_year_id' => $fy->id,
        'section_id' => $s->id, 'title' => 'pre-close']);
    $fy->update(['is_closed' => true]);
    expect(fn () => EntryPayment::create(['entry_id' => $e->id, 'amount' => 100,
        'direction' => 'out', 'mode' => 'bank', 'occurred_on' => '2025-05-01']))
        ->toThrow(\DomainException::class, 'closed');
});

it('blocks writes to income in a closed FY', function () {
    $c = Company::factory()->create();
    $fy = FiscalYear::factory()->create(['company_id' => $c->id, 'is_closed' => true]);
    expect(fn () => IncomeEntry::create(['company_id' => $c->id,
        'fiscal_year_id' => $fy->id, 'occurred_on' => '2025-05-01',
        'source' => 'A', 'amount' => 1]))->toThrow(\DomainException::class, 'closed');
});
```

- [ ] **Step 13.2: Run and verify failure**

Run: `vendor/bin/pest tests/Feature/Books/FiscalYearClosingTest.php`
Expected: FAIL.

- [ ] **Step 13.3: Create writer**

```php
<?php

namespace App\Books\Services;

use App\Models\Book\FiscalYear;

class ClosingSnapshotWriter
{
    public function __construct(
        private ?FiscalYearAggregator $agg = null,
    ) {
        $this->agg ??= new FiscalYearAggregator();
    }

    public function close(FiscalYear $fy): void
    {
        $fy->update([
            'is_closed' => true,
            'closing_summary_json' => [
                'total_income' => $this->agg->totalIncome($fy),
                'cash_outflow' => $this->agg->cashOutflow($fy),
                'non_cash_outflow' => $this->agg->nonCashOutflow($fy),
                'cash_inflow_from_recoveries' => $this->agg->cashInflowFromRecoveries($fy),
                'net_pl' => $this->agg->netPl($fy),
                'closed_at' => now()->toIso8601String(),
            ],
        ]);
    }

    public function reopen(FiscalYear $fy): void
    {
        $fy->update([
            'is_closed' => false,
            'closing_summary_json' => null,
        ]);
    }
}
```

- [ ] **Step 13.4: Add guards to Entry, EntryPayment, IncomeEntry**

In each model's `booted()` method, add an early `saving` callback:

Entry — add to existing `booted()` (or create one):

```php
protected static function booted(): void
{
    static::saving(function (Entry $e) {
        $fy = $e->fiscalYear()->first()
            ?? FiscalYear::find($e->fiscal_year_id);
        if ($fy && $fy->is_closed && $e->isDirty(['salary_amount','loan_amount','title','notes','section_id'])) {
            throw new \DomainException("Cannot edit entry — FY {$fy->label} is closed");
        }
        if ($fy && $fy->is_closed && ! $e->exists) {
            throw new \DomainException("Cannot create entry — FY {$fy->label} is closed");
        }
    });
    static::deleting(function (Entry $e) {
        $fy = FiscalYear::find($e->fiscal_year_id);
        if ($fy && $fy->is_closed) {
            throw new \DomainException("Cannot delete entry — FY {$fy->label} is closed");
        }
    });
}
```

EntryPayment — extend existing `booted()`:

```php
protected static function booted(): void
{
    static::saving(function (EntryPayment $p) {
        if (! in_array($p->direction, self::DIRECTIONS, true)) {
            throw new \InvalidArgumentException("Invalid direction: {$p->direction}");
        }
        if (! in_array($p->mode, self::MODES, true)) {
            throw new \InvalidArgumentException("Invalid mode: {$p->mode}");
        }
        $entry = Entry::find($p->entry_id);
        if ($entry) {
            $fy = FiscalYear::find($entry->fiscal_year_id);
            if ($fy && $fy->is_closed) {
                throw new \DomainException("Cannot record payment — FY {$fy->label} is closed");
            }
        }
    });
}
```

IncomeEntry — add `booted()`:

```php
protected static function booted(): void
{
    static::saving(function (IncomeEntry $i) {
        $fy = FiscalYear::find($i->fiscal_year_id);
        if ($fy && $fy->is_closed) {
            throw new \DomainException("Cannot edit income — FY {$fy->label} is closed");
        }
    });
}
```

- [ ] **Step 13.5: Add the `use` import in each model**

In Entry, EntryPayment, IncomeEntry — `use App\Models\Book\FiscalYear;` near the top.

- [ ] **Step 13.6: Run and verify pass**

Run: `vendor/bin/pest tests/Feature/Books/FiscalYearClosingTest.php`
Expected: PASS (5/5).

- [ ] **Step 13.7: Commit**

```bash
git add app/Books/Services/ClosingSnapshotWriter.php \
        app/Models/Book/Entry.php app/Models/Book/EntryPayment.php \
        app/Models/Book/IncomeEntry.php \
        tests/Feature/Books/FiscalYearClosingTest.php
git commit -m "feat(books): closing snapshot writer + closed-FY write guard"
```

---

## Task 14: BuiltInFieldsSeeder — seed PAN/Aadhaar/etc on company create

**Files:**
- Create: `app/Books/Services/BuiltInFieldsSeeder.php`
- Create: `app/Observers/Book/CompanyObserver.php`
- Modify: `app/Providers/AppServiceProvider.php`
- Test: `tests/Feature/Books/BuiltInFieldsSeederTest.php`

- [ ] **Step 14.1: Write failing test**

```php
<?php

use App\Models\Book\Company;
use App\Models\Book\Field;

it('seeds salary section and 7 built-in fields on company create', function () {
    $c = Company::create(['name' => 'Davyas', 'slug' => 'davyas']);
    $salary = $c->sections()->where('slug', 'salary')->first();
    expect($salary)->not->toBeNull();
    expect(Field::where('section_id', $salary->id)->where('is_built_in', true)->count())
        ->toBe(7); // PAN, Aadhaar, Cancelled Cheque, Account Number, IFSC, Offer, Joining
});

it('does not re-seed on update', function () {
    $c = Company::create(['name' => 'X', 'slug' => 'x']);
    $c->update(['name' => 'X (renamed)']);
    expect(Field::where('company_id', $c->id)->where('is_built_in', true)->count())
        ->toBe(7);
});
```

- [ ] **Step 14.2: Run and verify failure**

Run: `vendor/bin/pest tests/Feature/Books/BuiltInFieldsSeederTest.php`
Expected: FAIL.

- [ ] **Step 14.3: Add sections() to Company model**

In `app/Models/Book/Company.php`, add:

```php
use Illuminate\Database\Eloquent\Relations\HasMany;

public function sections(): HasMany { return $this->hasMany(Section::class); }
```

- [ ] **Step 14.4: Create seeder service**

```php
<?php

namespace App\Books\Services;

use App\Models\Book\Company;
use App\Models\Book\Section;
use App\Models\Book\Field;

class BuiltInFieldsSeeder
{
    public function seed(Company $company): void
    {
        $sections = [
            ['slug' => 'salary',  'name' => 'Salary',         'kind' => 'generic', 'sort_order' => 1],
            ['slug' => 'rent',    'name' => 'Rent',           'kind' => 'generic', 'sort_order' => 2],
            ['slug' => 'loan',    'name' => 'Loan',           'kind' => 'generic', 'sort_order' => 3],
            ['slug' => 'assets',  'name' => 'Depreciation',   'kind' => 'asset',   'sort_order' => 4],
            ['slug' => 'expense', 'name' => 'Expense',        'kind' => 'generic', 'sort_order' => 5],
        ];
        foreach ($sections as $s) {
            Section::firstOrCreate(
                ['company_id' => $company->id, 'slug' => $s['slug']],
                array_merge($s, ['company_id' => $company->id])
            );
        }

        $salary = $company->sections()->where('slug', 'salary')->first();
        $builtins = [
            ['key' => 'pan',              'label' => 'PAN',               'type' => 'text', 'show_in_table' => true],
            ['key' => 'aadhaar',          'label' => 'Aadhaar',           'type' => 'text', 'show_in_table' => false],
            ['key' => 'cancelled_cheque', 'label' => 'Cancelled Cheque',  'type' => 'file', 'show_in_table' => false],
            ['key' => 'account_number',   'label' => 'Account Number',    'type' => 'text', 'show_in_table' => false],
            ['key' => 'ifsc',             'label' => 'IFSC',              'type' => 'text', 'show_in_table' => false],
            ['key' => 'offer_letter',     'label' => 'Offer Letter',      'type' => 'file', 'show_in_table' => false],
            ['key' => 'joining_letter',   'label' => 'Joining Letter',    'type' => 'file', 'show_in_table' => false],
        ];
        foreach ($builtins as $idx => $b) {
            Field::firstOrCreate(
                ['company_id' => $company->id, 'section_id' => $salary->id, 'key' => $b['key']],
                array_merge($b, [
                    'company_id' => $company->id,
                    'section_id' => $salary->id,
                    'is_built_in' => true,
                    'sort_order' => $idx + 1,
                ])
            );
        }
    }
}
```

- [ ] **Step 14.5: Create observer**

```php
<?php

namespace App\Observers\Book;

use App\Books\Services\BuiltInFieldsSeeder;
use App\Models\Book\Company;

class CompanyObserver
{
    public function created(Company $company): void
    {
        (new BuiltInFieldsSeeder())->seed($company);
    }
}
```

- [ ] **Step 14.6: Register observer**

In `app/Providers/AppServiceProvider.php` `boot()`:

```php
\App\Models\Book\Company::observe(\App\Observers\Book\CompanyObserver::class);
```

- [ ] **Step 14.7: Run and verify pass**

Run: `vendor/bin/pest tests/Feature/Books/BuiltInFieldsSeederTest.php`
Expected: PASS (2/2).

- [ ] **Step 14.8: Commit**

```bash
git add app/Books/Services/BuiltInFieldsSeeder.php \
        app/Observers/Book/CompanyObserver.php \
        app/Providers/AppServiceProvider.php app/Models/Book/Company.php \
        tests/Feature/Books/BuiltInFieldsSeederTest.php
git commit -m "feat(books): auto-seed 5 sections + 7 built-in fields on company create"
```

---

## Task 15: ActivityLog wiring on all book_* models

**Files:**
- Modify: `app/Models/Book/{Company,FiscalYear,Section,Entry,EntryPayment,IncomeEntry,Asset,Field,FieldValue,Attachment}.php`
- Test: `tests/Feature/Books/ActivityLogTest.php`

- [ ] **Step 15.1: Write failing test**

```php
<?php

use App\Models\Book\Company;
use App\Models\Book\FiscalYear;
use App\Models\Book\Section;
use App\Models\Book\Entry;
use App\Models\Book\EntryPayment;
use App\Models\Book\IncomeEntry;
use Spatie\Activitylog\Models\Activity;

it('logs entry create + update + delete', function () {
    $c = Company::factory()->create();
    $fy = FiscalYear::factory()->create(['company_id' => $c->id]);
    $s = Section::factory()->create(['company_id' => $c->id]);
    $before = Activity::count();

    $e = Entry::create(['company_id' => $c->id, 'fiscal_year_id' => $fy->id,
        'section_id' => $s->id, 'title' => 'X', 'salary_amount' => 100]);
    $e->update(['salary_amount' => 200]);
    $e->delete();

    expect(Activity::count() - $before)->toBe(3);
});

it('logs payment events', function () {
    $e = Entry::factory()->create();
    $before = Activity::count();
    EntryPayment::create(['entry_id' => $e->id, 'amount' => 100,
        'direction' => 'out', 'mode' => 'bank', 'occurred_on' => '2025-05-01']);
    expect(Activity::count() - $before)->toBe(1);
});

it('logs income events', function () {
    $c = Company::factory()->create();
    $fy = FiscalYear::factory()->create(['company_id' => $c->id]);
    $before = Activity::count();
    IncomeEntry::create(['company_id' => $c->id, 'fiscal_year_id' => $fy->id,
        'occurred_on' => '2025-05-01', 'source' => 'A', 'amount' => 1]);
    expect(Activity::count() - $before)->toBe(1);
});
```

- [ ] **Step 15.2: Run and verify failure**

Run: `vendor/bin/pest tests/Feature/Books/ActivityLogTest.php`
Expected: FAIL.

- [ ] **Step 15.3: Add LogsActivity trait to each model**

In each of `Company`, `FiscalYear`, `Section`, `Entry`, `EntryPayment`, `IncomeEntry`, `Asset`, `Field`, `FieldValue`, `Attachment`, add at top of class:

```php
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Activitylog\LogOptions;

// inside class:
use LogsActivity;

public function getActivitylogOptions(): LogOptions
{
    return LogOptions::defaults()
        ->logFillable()
        ->logOnlyDirty()
        ->dontSubmitEmptyLogs()
        ->useLogName('books');
}
```

Do not duplicate `use HasFactory, SoftDeletes;` — add `LogsActivity` after the existing trait imports.

- [ ] **Step 15.4: Run and verify pass**

Run: `vendor/bin/pest tests/Feature/Books/ActivityLogTest.php`
Expected: PASS (3/3).

- [ ] **Step 15.5: Commit**

```bash
git add app/Models/Book/ tests/Feature/Books/ActivityLogTest.php
git commit -m "feat(books): Spatie ActivityLog on all book_* models"
```

---

## Task 16: Companies landing page — Filament list + Create modal

**Files:**
- Modify: `app/Filament/Pages/Book/CompaniesLanding.php`
- Modify: `resources/views/filament/pages/book/companies-landing.blade.php`
- Test: `tests/Feature/Books/CompaniesLandingPageTest.php`

- [ ] **Step 16.1: Write failing test**

```php
<?php

use App\Models\Book\Company;
use App\Models\User;
use Spatie\Permission\Models\Role;
use Livewire\Livewire;
use App\Filament\Pages\Book\CompaniesLanding;

beforeEach(function () {
    config()->set('books.enabled', true);
    Role::firstOrCreate(['name' => 'super_admin']);
    $u = User::factory()->create();
    $u->assignRole('super_admin');
    $this->actingAs($u);
});

it('lists companies', function () {
    Company::create(['name' => 'Davyas', 'slug' => 'davyas']);
    Company::create(['name' => 'Spillin Beans', 'slug' => 'spillin-beans']);
    $this->get('/admin/books')->assertSee('Davyas')->assertSee('Spillin Beans');
});

it('creates a company via Livewire action', function () {
    Livewire::test(CompaniesLanding::class)
        ->callAction('createCompany', ['name' => 'Kyne', 'slug' => 'kyne'])
        ->assertHasNoActionErrors();
    expect(Company::where('slug', 'kyne')->exists())->toBeTrue();
});
```

- [ ] **Step 16.2: Run and verify failure**

Run: `vendor/bin/pest tests/Feature/Books/CompaniesLandingPageTest.php`
Expected: FAIL.

- [ ] **Step 16.3: Build the page**

```php
<?php

namespace App\Filament\Pages\Book;

use App\Models\Book\Company;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Select;
use Filament\Pages\Page;

class CompaniesLanding extends Page
{
    protected static ?string $slug = 'books';
    protected static ?string $title = 'Books';
    protected static ?string $navigationGroup = 'Books';
    protected static string $view = 'filament.pages.book.companies-landing';

    public static function canAccess(): bool
    {
        return config('books.enabled') && auth()->user()?->isSuperAdmin();
    }

    public static function shouldRegisterNavigation(): bool { return static::canAccess(); }

    public function getCompanies()
    {
        return Company::orderBy('name')->get();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('createCompany')
                ->label('+ New Company')
                ->form([
                    TextInput::make('name')->required(),
                    TextInput::make('slug')->required()
                        ->unique('book_companies', 'slug')
                        ->alphaDash(),
                    Select::make('currency')
                        ->options(['INR' => 'INR'])->default('INR')->required(),
                ])
                ->action(fn (array $data) => Company::create($data)),
        ];
    }
}
```

- [ ] **Step 16.4: Build the view**

```blade
{{-- resources/views/filament/pages/book/companies-landing.blade.php --}}
<x-filament-panels::page>
    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
        @forelse ($this->getCompanies() as $c)
            <a href="{{ url('/admin/books/'.$c->slug) }}"
               class="block p-4 rounded-lg border hover:shadow-md transition">
                <div class="text-lg font-semibold">{{ $c->name }}</div>
                <div class="text-sm text-gray-500">{{ $c->currency }} · {{ $c->timezone }}</div>
            </a>
        @empty
            <div class="text-gray-500">No companies yet — click "+ New Company".</div>
        @endforelse
    </div>
</x-filament-panels::page>
```

- [ ] **Step 16.5: Run and verify pass**

Run: `vendor/bin/pest tests/Feature/Books/CompaniesLandingPageTest.php`
Expected: PASS (2/2).

- [ ] **Step 16.6: Commit**

```bash
git add app/Filament/Pages/Book/CompaniesLanding.php \
        resources/views/filament/pages/book/companies-landing.blade.php \
        tests/Feature/Books/CompaniesLandingPageTest.php
git commit -m "feat(books): companies landing page + create action"
```

---

## Task 17: Company × FY Dashboard page (5 regions)

**Files:**
- Create: `app/Filament/Pages/Book/CompanyDashboard.php`
- Create: `resources/views/filament/pages/book/company-dashboard.blade.php`
- Test: `tests/Feature/Books/CompanyDashboardPageTest.php`

- [ ] **Step 17.1: Write failing test**

```php
<?php

use App\Models\Book\Company;
use App\Models\Book\FiscalYear;
use App\Models\Book\Section;
use App\Models\Book\Entry;
use App\Models\Book\EntryPayment;
use App\Models\Book\IncomeEntry;
use App\Models\User;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    config()->set('books.enabled', true);
    Role::firstOrCreate(['name' => 'super_admin']);
    $u = User::factory()->create(); $u->assignRole('super_admin');
    $this->actingAs($u);
});

it('renders the dashboard with KPI numbers', function () {
    $c = Company::create(['name' => 'A', 'slug' => 'a']);
    $fy = FiscalYear::create(['company_id' => $c->id, 'start_date' => '2025-04-01',
        'end_date' => '2026-03-31', 'label' => '2025-26']);
    IncomeEntry::create(['company_id' => $c->id, 'fiscal_year_id' => $fy->id,
        'occurred_on' => '2025-05-01', 'source' => 'X', 'amount' => 1000000]);
    $s = $c->sections()->where('slug', 'salary')->first();
    $e = Entry::create(['company_id' => $c->id, 'fiscal_year_id' => $fy->id,
        'section_id' => $s->id, 'title' => 'Usha', 'salary_amount' => 100000]);
    EntryPayment::create(['entry_id' => $e->id, 'amount' => 50000,
        'direction' => 'out', 'mode' => 'bank', 'occurred_on' => '2025-05-15']);

    $this->get("/admin/books/{$c->slug}/{$fy->label}")
        ->assertSuccessful()
        ->assertSee('1,000,000') // total income (Indian format depends on impl — see Step 17.3)
        ->assertSee('50,000');   // cash outflow
});

it('badges carryover as estimate when prior FY is open', function () {
    $c = Company::create(['name' => 'A', 'slug' => 'a']);
    FiscalYear::create(['company_id' => $c->id, 'start_date' => '2024-04-01',
        'end_date' => '2025-03-31', 'label' => '2024-25']); // open
    $fy = FiscalYear::create(['company_id' => $c->id, 'start_date' => '2025-04-01',
        'end_date' => '2026-03-31', 'label' => '2025-26']);

    $this->get("/admin/books/{$c->slug}/{$fy->label}")
        ->assertSee('estimate');
});
```

- [ ] **Step 17.2: Run and verify failure**

Run: `vendor/bin/pest tests/Feature/Books/CompanyDashboardPageTest.php`
Expected: FAIL (route not registered).

- [ ] **Step 17.3: Create page**

```php
<?php

namespace App\Filament\Pages\Book;

use App\Books\Services\FiscalYearAggregator;
use App\Books\Services\DepreciationCalculator;
use App\Models\Book\Asset;
use App\Models\Book\Company;
use App\Models\Book\Entry;
use App\Models\Book\FiscalYear;
use Filament\Pages\Page;
use Illuminate\Support\Number;

class CompanyDashboard extends Page
{
    protected static ?string $slug = 'books/{company}/{fy}';
    protected static bool $shouldRegisterNavigation = false;
    protected static string $view = 'filament.pages.book.company-dashboard';

    public Company $company;
    public FiscalYear $fy;

    public function mount(string $company, string $fy): void
    {
        abort_unless(config('books.enabled') && auth()->user()?->isSuperAdmin(), 403);
        $this->company = Company::where('slug', $company)->firstOrFail();
        $this->fy = FiscalYear::where('company_id', $this->company->id)
            ->where('label', $fy)->firstOrFail();
    }

    public function getKpis(): array
    {
        $agg = new FiscalYearAggregator();
        $carry = $agg->carryover($this->fy);
        return [
            'total_income'     => $agg->totalIncome($this->fy),
            'cash_outflow'     => $agg->cashOutflow($this->fy),
            'non_cash_outflow' => $agg->nonCashOutflow($this->fy),
            'total_outflow'    => $agg->totalOutflow($this->fy),
            'net_pl'           => $agg->netPl($this->fy),
            'carryover'        => $carry,
            'cumulative_pl'    => $agg->netPl($this->fy) + $carry['value'],
        ];
    }

    public function getSectionRollups(): array
    {
        return $this->company->sections()
            ->orderBy('sort_order')->get()
            ->map(function ($s) {
                $entries = Entry::where('section_id', $s->id)
                    ->where('fiscal_year_id', $this->fy->id)->get();
                return [
                    'section' => $s,
                    'count' => $entries->count(),
                    'salary_total' => $entries->sum(fn ($e) => (float) $e->salary_amount),
                    'loan_total'   => $entries->sum(fn ($e) => (float) $e->loan_amount),
                    'paid_total'   => $entries->sum(fn ($e) => (float) $e->paid),
                    'balance_total'=> $entries->sum(fn ($e) => (float) $e->balance),
                ];
            })->all();
    }

    public function getAssetRegister(): array
    {
        $calc = new DepreciationCalculator();
        return Asset::whereHas('entry',
                fn ($q) => $q->where('fiscal_year_id', $this->fy->id))
            ->with('entry')->get()
            ->map(fn ($a) => [
                'name' => $a->entry->title,
                'original' => (float) $a->original_value,
                'this_year' => $calc->yearlyDepFor($a, $this->fy),
                'accumulated' => $calc->accumulatedDepThrough($a, $this->fy),
                'book_value' => $calc->bookValueAtEndOf($a, $this->fy),
            ])->all();
    }

    public function getLoansOutstanding(): array
    {
        return Entry::where('fiscal_year_id', $this->fy->id)
            ->where('loan_amount', '>', 0)->get()
            ->filter(fn ($e) => (float) $e->loan_outstanding > 0)
            ->map(fn ($e) => [
                'title' => $e->title,
                'loan' => (float) $e->loan_amount,
                'received_back' => (float) $e->received_back,
                'outstanding' => (float) $e->loan_outstanding,
            ])->values()->all();
    }
}
```

- [ ] **Step 17.4: Register page**

In `app/Providers/Filament/AdminPanelProvider.php` `->pages([...])`, add `\App\Filament\Pages\Book\CompanyDashboard::class`.

- [ ] **Step 17.5: Create blade view**

```blade
{{-- resources/views/filament/pages/book/company-dashboard.blade.php --}}
<x-filament-panels::page>
    @php($kpis = $this->getKpis())
    @php($rollups = $this->getSectionRollups())
    @php($assets = $this->getAssetRegister())
    @php($loans = $this->getLoansOutstanding())

    <div class="mb-4 flex items-center gap-3">
        <div class="text-2xl font-semibold">{{ $company->name }}</div>
        <div class="px-3 py-1 rounded-full bg-emerald-100 text-emerald-800 text-sm font-medium">
            FY {{ $fy->label }} {{ $fy->is_closed ? '(closed)' : '' }}
        </div>
    </div>

    {{-- KPI tiles --}}
    <div class="grid grid-cols-1 md:grid-cols-4 gap-3 mb-6">
        @foreach (['total_income'=>'Total Income','cash_outflow'=>'Cash Outflow','non_cash_outflow'=>'Non-Cash (Dep)','total_outflow'=>'Total Outflow','net_pl'=>'Net P/L','cumulative_pl'=>'Cumulative P/L'] as $k=>$lbl)
            <div class="p-4 rounded-lg border bg-white">
                <div class="text-xs text-gray-500">{{ $lbl }}</div>
                <div class="text-xl font-semibold">₹ {{ number_format($kpis[$k], 2) }}</div>
            </div>
        @endforeach
        <div class="p-4 rounded-lg border bg-white">
            <div class="text-xs text-gray-500">
                Carryover {{ $kpis['carryover']['estimate'] ? '(estimate)' : '' }}
            </div>
            <div class="text-xl font-semibold">₹ {{ number_format($kpis['carryover']['value'], 2) }}</div>
        </div>
    </div>

    {{-- Section roll-ups --}}
    <div class="grid grid-cols-1 md:grid-cols-3 gap-3 mb-6">
        @foreach ($rollups as $r)
            <a href="{{ url('/admin/books/'.$company->slug.'/'.$fy->label.'/section/'.$r['section']->slug) }}"
               class="block p-4 rounded-lg border bg-white hover:shadow-md">
                <div class="font-semibold">{{ $r['section']->name }}</div>
                <div class="text-xs text-gray-500">{{ $r['count'] }} entries</div>
                <div class="mt-2 text-sm">Salary ₹ {{ number_format($r['salary_total'], 2) }}</div>
                <div class="text-sm">Loan ₹ {{ number_format($r['loan_total'], 2) }}</div>
                <div class="text-sm">Paid ₹ {{ number_format($r['paid_total'], 2) }}</div>
            </a>
        @endforeach
    </div>

    {{-- Asset register --}}
    @if (count($assets))
        <div class="mb-6">
            <h3 class="font-semibold mb-2">Asset Register</h3>
            <table class="w-full text-sm">
                <thead class="bg-gray-50"><tr>
                    <th class="text-left p-2">Asset</th><th class="text-right p-2">Original</th>
                    <th class="text-right p-2">Dep (This FY)</th>
                    <th class="text-right p-2">Accumulated</th>
                    <th class="text-right p-2">Book Value</th>
                </tr></thead>
                <tbody>
                    @foreach ($assets as $a)
                        <tr class="border-t">
                            <td class="p-2">{{ $a['name'] }}</td>
                            <td class="p-2 text-right">{{ number_format($a['original'], 2) }}</td>
                            <td class="p-2 text-right">{{ number_format($a['this_year'], 2) }}</td>
                            <td class="p-2 text-right">{{ number_format($a['accumulated'], 2) }}</td>
                            <td class="p-2 text-right">{{ number_format($a['book_value'], 2) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

    {{-- Loans outstanding --}}
    @if (count($loans))
        <div class="mb-6">
            <h3 class="font-semibold mb-2">Loans Outstanding</h3>
            <table class="w-full text-sm">
                <thead class="bg-gray-50"><tr>
                    <th class="text-left p-2">Counterparty</th>
                    <th class="text-right p-2">Loan</th>
                    <th class="text-right p-2">Received Back</th>
                    <th class="text-right p-2">Outstanding</th>
                </tr></thead>
                <tbody>
                    @foreach ($loans as $l)
                        <tr class="border-t">
                            <td class="p-2">{{ $l['title'] }}</td>
                            <td class="p-2 text-right">{{ number_format($l['loan'], 2) }}</td>
                            <td class="p-2 text-right">{{ number_format($l['received_back'], 2) }}</td>
                            <td class="p-2 text-right font-semibold">
                                {{ number_format($l['outstanding'], 2) }}
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</x-filament-panels::page>
```

- [ ] **Step 17.6: Run and verify pass**

Run: `vendor/bin/pest tests/Feature/Books/CompanyDashboardPageTest.php`
Expected: PASS (2/2). If the comma-format assertion mismatches, adjust the test's `assertSee` to `1,000,000.00`.

- [ ] **Step 17.7: Commit**

```bash
git add app/Filament/Pages/Book/CompanyDashboard.php \
        resources/views/filament/pages/book/company-dashboard.blade.php \
        app/Providers/Filament/AdminPanelProvider.php \
        tests/Feature/Books/CompanyDashboardPageTest.php
git commit -m "feat(books): per-FY dashboard with KPIs, rollups, assets, loans"
```

---

## Task 18: Section page — entries table + + Add Row + drawer

**Files:**
- Create: `app/Filament/Pages/Book/SectionPage.php`
- Create: `resources/views/filament/pages/book/section.blade.php`
- Create: `app/Livewire/Book/EntryDrawer.php`
- Create: `resources/views/livewire/book/entry-drawer.blade.php`
- Test: `tests/Feature/Books/SectionPageTest.php`

- [ ] **Step 18.1: Write failing test**

```php
<?php

use App\Models\Book\Company;
use App\Models\Book\FiscalYear;
use App\Models\Book\Entry;
use App\Models\User;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    config()->set('books.enabled', true);
    Role::firstOrCreate(['name' => 'super_admin']);
    $u = User::factory()->create(); $u->assignRole('super_admin');
    $this->actingAs($u);
});

it('renders the section table with entries', function () {
    $c = Company::create(['name' => 'A', 'slug' => 'a']);
    $fy = FiscalYear::create(['company_id' => $c->id, 'start_date' => '2025-04-01',
        'end_date' => '2026-03-31', 'label' => '2025-26']);
    $s = $c->sections()->where('slug', 'salary')->first();
    Entry::create(['company_id' => $c->id, 'fiscal_year_id' => $fy->id,
        'section_id' => $s->id, 'title' => 'Usha', 'salary_amount' => 1200000]);

    $this->get("/admin/books/{$c->slug}/{$fy->label}/section/salary")
        ->assertSuccessful()
        ->assertSee('Usha')
        ->assertSee('1,200,000');
});

it('creates an entry through the page action', function () {
    $c = Company::create(['name' => 'A', 'slug' => 'a']);
    $fy = FiscalYear::create(['company_id' => $c->id, 'start_date' => '2025-04-01',
        'end_date' => '2026-03-31', 'label' => '2025-26']);

    \Livewire\Livewire::test(\App\Filament\Pages\Book\SectionPage::class,
        ['company' => 'a', 'fy' => '2025-26', 'section' => 'salary'])
        ->callAction('createEntry', ['title' => 'Magha', 'salary_amount' => 1200000])
        ->assertHasNoActionErrors();

    expect(Entry::where('title', 'Magha')->exists())->toBeTrue();
});
```

- [ ] **Step 18.2: Run and verify failure**

Run: `vendor/bin/pest tests/Feature/Books/SectionPageTest.php`
Expected: FAIL.

- [ ] **Step 18.3: Create page**

```php
<?php

namespace App\Filament\Pages\Book;

use App\Models\Book\Company;
use App\Models\Book\Entry;
use App\Models\Book\FiscalYear;
use App\Models\Book\Section;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Pages\Page;

class SectionPage extends Page
{
    protected static ?string $slug = 'books/{company}/{fy}/section/{section}';
    protected static bool $shouldRegisterNavigation = false;
    protected static string $view = 'filament.pages.book.section';

    public Company $companyModel;
    public FiscalYear $fyModel;
    public Section $sectionModel;

    public function mount(string $company, string $fy, string $section): void
    {
        abort_unless(config('books.enabled') && auth()->user()?->isSuperAdmin(), 403);
        $this->companyModel = Company::where('slug', $company)->firstOrFail();
        $this->fyModel = FiscalYear::where('company_id', $this->companyModel->id)
            ->where('label', $fy)->firstOrFail();
        $this->sectionModel = Section::where('company_id', $this->companyModel->id)
            ->where('slug', $section)->firstOrFail();
    }

    public function getEntries()
    {
        return Entry::where('section_id', $this->sectionModel->id)
            ->where('fiscal_year_id', $this->fyModel->id)
            ->orderBy('sort_order')->orderBy('id')->get();
    }

    public function getVisibleMoneyColumns(): array
    {
        return $this->sectionModel->visible_money_columns;
    }

    protected function getHeaderActions(): array
    {
        $cols = $this->getVisibleMoneyColumns();
        $form = [TextInput::make('title')->required()];
        if (in_array('salary', $cols, true)) {
            $form[] = TextInput::make('salary_amount')->numeric()->default(0);
        }
        if (in_array('loan', $cols, true)) {
            $form[] = TextInput::make('loan_amount')->numeric()->default(0);
        }
        $form[] = Textarea::make('notes')->rows(2);

        return [
            Action::make('createEntry')
                ->label('+ Add Row')
                ->form($form)
                ->action(function (array $data) {
                    if ($this->fyModel->is_closed) {
                        throw new \DomainException('FY is closed');
                    }
                    Entry::create([
                        'company_id' => $this->companyModel->id,
                        'fiscal_year_id' => $this->fyModel->id,
                        'section_id' => $this->sectionModel->id,
                        'title' => $data['title'],
                        'salary_amount' => $data['salary_amount'] ?? 0,
                        'loan_amount'   => $data['loan_amount']   ?? 0,
                        'notes' => $data['notes'] ?? null,
                    ]);
                }),
        ];
    }
}
```

- [ ] **Step 18.4: Register page in AdminPanelProvider**

Add `\App\Filament\Pages\Book\SectionPage::class` to `->pages([...])`.

- [ ] **Step 18.5: Create the section view**

```blade
{{-- resources/views/filament/pages/book/section.blade.php --}}
<x-filament-panels::page>
    <div class="mb-4">
        <a href="{{ url('/admin/books/'.$companyModel->slug.'/'.$fyModel->label) }}"
           class="text-sm text-gray-500">← {{ $companyModel->name }} / FY {{ $fyModel->label }}</a>
        <h2 class="text-xl font-semibold">{{ $sectionModel->name }}</h2>
    </div>

    @php($cols = $this->getVisibleMoneyColumns())
    @php($entries = $this->getEntries())

    <table class="w-full text-sm border rounded-lg overflow-hidden">
        <thead class="bg-gray-50">
            <tr>
                <th class="text-left p-2 w-12">#</th>
                <th class="text-left p-2">Title</th>
                @if (in_array('salary', $cols)) <th class="text-right p-2">Salary</th>@endif
                @if (in_array('loan', $cols)) <th class="text-right p-2">Loan</th>@endif
                @if (in_array('paid', $cols)) <th class="text-right p-2">Paid</th>@endif
                @if (in_array('received_back', $cols)) <th class="text-right p-2">Received Back</th>@endif
                @if (in_array('balance', $cols)) <th class="text-right p-2">Balance</th>@endif
                @if (in_array('loan_outstanding', $cols)) <th class="text-right p-2">Loan Outstanding</th>@endif
                <th class="text-left p-2">Notes</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($entries as $i => $e)
                <tr class="border-t">
                    <td class="p-2">{{ $i + 1 }}</td>
                    <td class="p-2 font-medium">{{ $e->title }}</td>
                    @if (in_array('salary', $cols)) <td class="p-2 text-right">{{ number_format((float)$e->salary_amount, 2) }}</td>@endif
                    @if (in_array('loan', $cols)) <td class="p-2 text-right">{{ number_format((float)$e->loan_amount, 2) }}</td>@endif
                    @if (in_array('paid', $cols)) <td class="p-2 text-right">{{ number_format((float)$e->paid, 2) }}</td>@endif
                    @if (in_array('received_back', $cols)) <td class="p-2 text-right">{{ number_format((float)$e->received_back, 2) }}</td>@endif
                    @if (in_array('balance', $cols)) <td class="p-2 text-right">{{ number_format((float)$e->balance, 2) }}</td>@endif
                    @if (in_array('loan_outstanding', $cols)) <td class="p-2 text-right">{{ number_format((float)$e->loan_outstanding, 2) }}</td>@endif
                    <td class="p-2 text-gray-500">{{ $e->notes }}</td>
                </tr>
            @empty
                <tr><td colspan="9" class="p-4 text-gray-500 text-center">No entries — click "+ Add Row".</td></tr>
            @endforelse
        </tbody>
    </table>
</x-filament-panels::page>
```

- [ ] **Step 18.6: Run and verify pass**

Run: `vendor/bin/pest tests/Feature/Books/SectionPageTest.php`
Expected: PASS (2/2).

- [ ] **Step 18.7: Commit**

```bash
git add app/Filament/Pages/Book/SectionPage.php \
        resources/views/filament/pages/book/section.blade.php \
        app/Providers/Filament/AdminPanelProvider.php \
        tests/Feature/Books/SectionPageTest.php
git commit -m "feat(books): section page with adaptive money columns + Add Row action"
```

---

## Task 19: Income page CRUD

**Files:**
- Create: `app/Filament/Pages/Book/IncomePage.php`
- Create: `resources/views/filament/pages/book/income.blade.php`
- Test: `tests/Feature/Books/IncomePageTest.php`

- [ ] **Step 19.1: Write failing test**

```php
<?php

use App\Models\Book\Company;
use App\Models\Book\FiscalYear;
use App\Models\Book\IncomeEntry;
use App\Models\User;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    config()->set('books.enabled', true);
    Role::firstOrCreate(['name' => 'super_admin']);
    $u = User::factory()->create(); $u->assignRole('super_admin');
    $this->actingAs($u);
});

it('lists income entries', function () {
    $c = Company::create(['name' => 'A', 'slug' => 'a']);
    $fy = FiscalYear::create(['company_id' => $c->id, 'start_date' => '2025-04-01',
        'end_date' => '2026-03-31', 'label' => '2025-26']);
    IncomeEntry::create(['company_id' => $c->id, 'fiscal_year_id' => $fy->id,
        'occurred_on' => '2025-05-15', 'source' => 'Client A', 'amount' => 500000]);
    $this->get("/admin/books/{$c->slug}/{$fy->label}/income")
        ->assertSee('Client A')->assertSee('500,000');
});

it('creates income via action', function () {
    $c = Company::create(['name' => 'A', 'slug' => 'a']);
    $fy = FiscalYear::create(['company_id' => $c->id, 'start_date' => '2025-04-01',
        'end_date' => '2026-03-31', 'label' => '2025-26']);
    \Livewire\Livewire::test(\App\Filament\Pages\Book\IncomePage::class,
        ['company' => 'a', 'fy' => '2025-26'])
        ->callAction('createIncome', [
            'occurred_on' => '2025-06-01', 'source' => 'Y', 'amount' => 250000])
        ->assertHasNoActionErrors();
    expect(IncomeEntry::where('source', 'Y')->exists())->toBeTrue();
});
```

- [ ] **Step 19.2: Run and verify failure**

Run: `vendor/bin/pest tests/Feature/Books/IncomePageTest.php`
Expected: FAIL.

- [ ] **Step 19.3: Create page**

```php
<?php

namespace App\Filament\Pages\Book;

use App\Models\Book\Company;
use App\Models\Book\FiscalYear;
use App\Models\Book\IncomeEntry;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Pages\Page;

class IncomePage extends Page
{
    protected static ?string $slug = 'books/{company}/{fy}/income';
    protected static bool $shouldRegisterNavigation = false;
    protected static string $view = 'filament.pages.book.income';

    public Company $companyModel;
    public FiscalYear $fyModel;

    public function mount(string $company, string $fy): void
    {
        abort_unless(config('books.enabled') && auth()->user()?->isSuperAdmin(), 403);
        $this->companyModel = Company::where('slug', $company)->firstOrFail();
        $this->fyModel = FiscalYear::where('company_id', $this->companyModel->id)
            ->where('label', $fy)->firstOrFail();
    }

    public function getIncome()
    {
        return IncomeEntry::where('fiscal_year_id', $this->fyModel->id)
            ->orderByDesc('occurred_on')->get();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('createIncome')
                ->label('+ Add Income')
                ->form([
                    DatePicker::make('occurred_on')->required(),
                    TextInput::make('source')->required(),
                    TextInput::make('amount')->numeric()->required(),
                    Textarea::make('notes')->rows(2),
                ])
                ->action(fn (array $data) => IncomeEntry::create(array_merge($data, [
                    'company_id' => $this->companyModel->id,
                    'fiscal_year_id' => $this->fyModel->id,
                ]))),
        ];
    }
}
```

- [ ] **Step 19.4: Register page + create view**

Add to AdminPanelProvider `pages([...])`. Then create:

```blade
{{-- resources/views/filament/pages/book/income.blade.php --}}
<x-filament-panels::page>
    <h2 class="text-xl font-semibold mb-3">Income — {{ $companyModel->name }} / FY {{ $fyModel->label }}</h2>
    <table class="w-full text-sm border rounded-lg overflow-hidden">
        <thead class="bg-gray-50">
            <tr><th class="text-left p-2">Date</th><th class="text-left p-2">Source</th>
                <th class="text-right p-2">Amount</th><th class="text-left p-2">Notes</th></tr>
        </thead>
        <tbody>
            @forelse ($this->getIncome() as $i)
                <tr class="border-t">
                    <td class="p-2">{{ $i->occurred_on->format('d M Y') }}</td>
                    <td class="p-2 font-medium">{{ $i->source }}</td>
                    <td class="p-2 text-right">{{ number_format((float) $i->amount, 2) }}</td>
                    <td class="p-2 text-gray-500">{{ $i->notes }}</td>
                </tr>
            @empty
                <tr><td colspan="4" class="p-4 text-gray-500 text-center">No income yet.</td></tr>
            @endforelse
        </tbody>
    </table>
</x-filament-panels::page>
```

- [ ] **Step 19.5: Run and verify pass**

Run: `vendor/bin/pest tests/Feature/Books/IncomePageTest.php`
Expected: PASS (2/2).

- [ ] **Step 19.6: Commit**

```bash
git add app/Filament/Pages/Book/IncomePage.php \
        resources/views/filament/pages/book/income.blade.php \
        app/Providers/Filament/AdminPanelProvider.php \
        tests/Feature/Books/IncomePageTest.php
git commit -m "feat(books): income page with create action + list"
```

---

## Task 20: Multi-company isolation tests + super_admin gate sweep

**Files:**
- Test: `tests/Feature/Books/MultiCompanyIsolationTest.php`
- Test: `tests/Feature/Books/AccessControlTest.php`

- [ ] **Step 20.1: Write isolation test**

```php
<?php

use App\Models\Book\Company;
use App\Models\Book\FiscalYear;
use App\Models\Book\Section;
use App\Models\Book\Entry;
use App\Models\User;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    config()->set('books.enabled', true);
    Role::firstOrCreate(['name' => 'super_admin']);
    $u = User::factory()->create(); $u->assignRole('super_admin');
    $this->actingAs($u);
});

it('does not leak entries from one company into another', function () {
    $a = Company::create(['name' => 'A', 'slug' => 'a']);
    $b = Company::create(['name' => 'B', 'slug' => 'b']);
    $fyA = FiscalYear::create(['company_id' => $a->id, 'start_date' => '2025-04-01',
        'end_date' => '2026-03-31', 'label' => '2025-26']);
    $fyB = FiscalYear::create(['company_id' => $b->id, 'start_date' => '2025-04-01',
        'end_date' => '2026-03-31', 'label' => '2025-26']);
    $sA = $a->sections()->where('slug', 'salary')->first();
    $sB = $b->sections()->where('slug', 'salary')->first();
    Entry::create(['company_id' => $a->id, 'fiscal_year_id' => $fyA->id,
        'section_id' => $sA->id, 'title' => 'Only-In-A']);
    Entry::create(['company_id' => $b->id, 'fiscal_year_id' => $fyB->id,
        'section_id' => $sB->id, 'title' => 'Only-In-B']);

    $this->get("/admin/books/a/2025-26/section/salary")
        ->assertSee('Only-In-A')->assertDontSee('Only-In-B');
});

it('returns 404 when FY label does not exist for that company', function () {
    Company::create(['name' => 'A', 'slug' => 'a']);
    $this->get("/admin/books/a/9999-00")->assertNotFound();
});
```

- [ ] **Step 20.2: Write access control test**

```php
<?php

use App\Models\Book\Company;
use App\Models\Book\FiscalYear;
use App\Models\User;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    config()->set('books.enabled', true);
    foreach (['admin','head','member','freelancer','finance','super_admin'] as $r) {
        Role::firstOrCreate(['name' => $r]);
    }
});

it('blocks admin role from books URLs', function () {
    $u = User::factory()->create(); $u->assignRole('admin');
    $this->actingAs($u)->get('/admin/books')->assertForbidden();
});

it('blocks finance role from books URLs', function () {
    $u = User::factory()->create(); $u->assignRole('finance');
    $this->actingAs($u)->get('/admin/books')->assertForbidden();
});

it('blocks head from a deep books URL', function () {
    $u = User::factory()->create(); $u->assignRole('head');
    $c = Company::create(['name' => 'A', 'slug' => 'a']);
    $fy = FiscalYear::create(['company_id' => $c->id, 'start_date' => '2025-04-01',
        'end_date' => '2026-03-31', 'label' => '2025-26']);
    $this->actingAs($u)->get("/admin/books/a/2025-26")->assertForbidden();
});

it('allows super_admin everywhere', function () {
    $u = User::factory()->create(); $u->assignRole('super_admin');
    $c = Company::create(['name' => 'A', 'slug' => 'a']);
    $fy = FiscalYear::create(['company_id' => $c->id, 'start_date' => '2025-04-01',
        'end_date' => '2026-03-31', 'label' => '2025-26']);
    $this->actingAs($u)->get("/admin/books/a/2025-26")->assertSuccessful();
});
```

- [ ] **Step 20.3: Run and verify pass**

Run: `vendor/bin/pest tests/Feature/Books/MultiCompanyIsolationTest.php tests/Feature/Books/AccessControlTest.php`
Expected: PASS (6/6). If a Forbidden case returns 200, recheck `abort_unless` guards on each page.

- [ ] **Step 20.4: Commit**

```bash
git add tests/Feature/Books/MultiCompanyIsolationTest.php \
        tests/Feature/Books/AccessControlTest.php
git commit -m "test(books): multi-company isolation + super_admin-only access control"
```

---

## Task 21: Seed Sumit's spreadsheet via tinker script (manual smoke)

**Files:**
- Create: `database/seeders/Book/SumitSpreadsheetSeeder.php`

- [ ] **Step 21.1: Create seeder**

```php
<?php

namespace Database\Seeders\Book;

use App\Models\Book\Company;
use App\Models\Book\FiscalYear;
use App\Models\Book\Section;
use App\Models\Book\Entry;
use App\Models\Book\EntryPayment;
use App\Models\Book\IncomeEntry;
use App\Models\Book\Asset;
use Illuminate\Database\Seeder;

class SumitSpreadsheetSeeder extends Seeder
{
    public function run(): void
    {
        $c = Company::firstOrCreate(['slug' => 'davyas-fy25'], [
            'name' => 'Davyas (Spreadsheet)',
            'slug' => 'davyas-fy25',
        ]);
        $fy = FiscalYear::firstOrCreate(
            ['company_id' => $c->id, 'label' => '2025-26'],
            ['company_id' => $c->id, 'start_date' => '2025-04-01',
             'end_date' => '2026-03-31']
        );

        IncomeEntry::firstOrCreate([
            'company_id' => $c->id, 'fiscal_year_id' => $fy->id,
            'source' => 'Total Income (lumped)',
        ], [
            'occurred_on' => '2025-04-01', 'amount' => 12500000,
        ]);

        $sectionMap = [
            'salary' => 'Salary', 'rent' => 'Rent', 'loan' => 'Loan',
            'assets' => 'Depreciation', 'expense' => 'Expense',
        ];

        $rows = [
            // [section, title, salary, loan, paid_out, received_in]
            ['salary', 'Usha',          1200000, 0,       200000, 0],
            ['salary', 'Magha',         1200000, 0,       450000, 0],
            ['salary', 'Lansdown',      0,       1000000, 0,      100000],
            ['salary', 'Shri Bhagwan',  0,       1000000, 0,      0],
            ['salary', 'Shubham Deswal',0,       1000000, 0,      0],
            ['salary', 'Gagandeep',     0,       1000000, 0,      0],
            ['salary', 'Poonam Sanju',  0,       800000,  0,      0],
            ['salary', 'Nisha',         0,       800000,  0,      0],
            ['rent',   'Parmit',        0,       0,       450000, 0],
            ['loan',   'Spillin Beans', 0,       1500000, 0,      0],
            ['loan',   'Kyne',          0,       2000000, 0,      0],
            ['expense','Credit Card',   0,       0,       400000, 300000],
            ['expense','Expenses',      0,       0,       1860000, 0],
        ];

        foreach ($rows as $r) {
            [$sectionSlug, $title, $sal, $loan, $paid, $back] = $r;
            $section = Section::firstWhere(['company_id' => $c->id, 'slug' => $sectionSlug]);
            $entry = Entry::firstOrCreate(
                ['company_id' => $c->id, 'fiscal_year_id' => $fy->id,
                 'section_id' => $section->id, 'title' => $title],
                ['salary_amount' => $sal, 'loan_amount' => $loan]
            );
            if ($paid > 0) {
                EntryPayment::firstOrCreate(
                    ['entry_id' => $entry->id, 'occurred_on' => '2025-05-01',
                     'direction' => 'out', 'amount' => $paid],
                    ['mode' => 'bank']
                );
            }
            if ($back > 0) {
                EntryPayment::firstOrCreate(
                    ['entry_id' => $entry->id, 'occurred_on' => '2025-07-01',
                     'direction' => 'in', 'amount' => $back],
                    ['mode' => 'bank']
                );
            }
        }

        // Assets: Car (₹3,00,000 / Dep ₹2,00,000) + Solar (₹4,90,000 / Dep ₹4,90,000)
        $assetSection = Section::firstWhere(['company_id' => $c->id, 'slug' => 'assets']);
        foreach ([
            ['Car', 300000, 200000, 5],   // ~67% effective rate to match ₹2L in year 1
            ['Solar', 490000, 490000, 1], // fully depreciated year 1
        ] as [$name, $original, $depYear1, $life]) {
            $e = Entry::firstOrCreate(
                ['company_id' => $c->id, 'fiscal_year_id' => $fy->id,
                 'section_id' => $assetSection->id, 'title' => $name],
                ['salary_amount' => 0, 'loan_amount' => 0]
            );
            $percent = round($depYear1 / $original * 100, 2);
            Asset::firstOrCreate(
                ['entry_id' => $e->id],
                ['original_value' => $original, 'dep_percent' => $percent,
                 'dep_years' => $life, 'dep_started_at' => '2025-04-01',
                 'method' => 'straight_line']
            );
        }
    }
}
```

- [ ] **Step 21.2: Run the seeder locally**

Run: `php artisan db:seed --class=Database\\Seeders\\Book\\SumitSpreadsheetSeeder`
Expected: completes without error.

- [ ] **Step 21.3: Manual smoke — visit the URLs**

In a browser with `BOOKS_MODULE=true` in `.env`:

- `http://localhost:8000/admin/books` → see "Davyas (Spreadsheet)" card
- `http://localhost:8000/admin/books/davyas-fy25/2025-26` → KPIs show Income ₹1.25 Cr, Cash Outflow ~₹29.6L, Carryover ₹0 (no prior FY)
- `http://localhost:8000/admin/books/davyas-fy25/2025-26/section/salary` → 8 rows incl. Usha, Magha, Lansdown
- `http://localhost:8000/admin/books/davyas-fy25/2025-26/income` → ₹1,25,00,000

- [ ] **Step 21.4: Commit**

```bash
git add database/seeders/Book/SumitSpreadsheetSeeder.php
git commit -m "feat(books): seeder for Sumit's spreadsheet smoke data"
```

---

## Task 22: Final verification + DEPLOY checklist

**Files:**
- Modify: `docs/DEPLOY.md`
- Test: full suite

- [ ] **Step 22.1: Run full suite**

Run: `vendor/bin/pest`
Expected: full suite green. Note baseline test count (e.g. 590+ pre-Books) and confirm new tests pushed it past +50.

- [ ] **Step 22.2: Append a Books deploy section to DEPLOY.md**

Append a new section after the existing recurring deploy block:

```markdown
## Books module deploy

Prerequisites: `BOOKS_MODULE` must be `false` until smoke green.

1. SSH to Hostinger as ipuc.
2. `cd ~/davya-crm && git pull`
3. `/opt/alt/php84/usr/bin/php artisan migrate`  ← 10 new book_* tables
4. `/opt/alt/php84/usr/bin/php artisan optimize:clear`
5. Flip Filament FPM via cPanel MultiPHP Manager (8.4 off → on) — needed for new classes
6. Manually edit `.env` on prod: `BOOKS_MODULE=true`
7. `/opt/alt/php84/usr/bin/php artisan config:clear`
8. Visit `https://davyas.ipu.co.in/admin/books` as `sumitdabass@gmail.com` → see landing
9. Rollback: `BOOKS_MODULE=false` + `config:clear`; tables stay, no data loss
```

- [ ] **Step 22.3: Final commit**

```bash
git add docs/DEPLOY.md
git commit -m "docs(books): deploy checklist + verification notes"
```

- [ ] **Step 22.4: Push branch**

```bash
git push -u origin feat/books-module
```

---

## Self-review

After writing this plan, ran the four checks from the writing-plans skill:

**1. Spec coverage:**
- ✅ Company CRUD — Tasks 2, 16
- ✅ Fiscal Year — Tasks 3, 13
- ✅ Sections + visible_money_columns — Task 4
- ✅ Entries with typed Salary/Loan + is_loan — Task 5
- ✅ `book_entry_payments` sub-table + computed paid/received_back/balance — Task 6
- ✅ Assets + Depreciation engine (SL + WDV + accumulated + book value) — Tasks 7, 12
- ✅ Income entries — Task 8
- ✅ Custom fields (Phase-A pattern, per section) — Task 9
- ✅ Polymorphic attachments — Task 10
- ✅ FY aggregator (cash/non-cash separated, carryover with estimate badge) — Task 11
- ✅ Closing snapshot writer + closed-FY write guard — Task 13
- ✅ Built-in fields seeder on company create — Task 14
- ✅ ActivityLog on every model — Task 15
- ✅ Companies landing + Dashboard + Section + Income pages — Tasks 16–19
- ✅ Multi-company isolation + super_admin-only access — Task 20
- ✅ Smoke seed + deploy checklist — Tasks 21–22

**Gaps to flag for follow-up plans (NOT in scope of this plan, called out so they don't get forgotten):**
- Custom-field admin CRUD UI (Phase-A-style page) — spec calls for `/admin/books/{co}/settings` Fields tab; this plan ships the data layer only. Field admin UI is a follow-up plan.
- Attachment upload UI (multi-file repeater) on entry drawer — this plan ships the polymorphic table and model; the actual upload form is a follow-up.
- Year-switcher dropdown in dashboard header — fully working as URL navigation, but the dropdown chrome is not implemented here.
- Income vs Outflow monthly chart — KPIs land; the chart widget is a follow-up.
- Entry drawer with 5 tabs (Details / Payments / Documents / Custom Fields / Activity Log) — drawer scaffolded as a placeholder in Task 18; full tab content is a follow-up.

These five items justify a Plan 2 ("Books v1 UI completion") of about 6–8 tasks. The current plan ships a working multi-company FY-scoped finance core (data layer + landing + dashboard + entry CRUD + income CRUD + access control + smoke seed) — that's the right v1 cut for Sumit to validate the shape before investing in chrome.

**2. Placeholder scan:** none. Every step has actual code/commands.

**3. Type consistency:** `paid` / `received_back` / `balance` / `loan_outstanding` accessor names match across Entry model (Task 6), FiscalYearAggregator (Task 11), and views (Tasks 17–18). `closing_summary_json` (column) vs `closing_summary` (accessor) used consistently after Task 3.

**4. Ambiguity check:** none material. The Task 17 KPI test's `assertSee('1,000,000')` may need to match the actual `number_format(..., 2)` output of `1,000,000.00` — flagged inline in Step 17.6 with the fix.
