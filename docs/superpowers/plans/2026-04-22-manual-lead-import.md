# Manual Bulk Lead Import Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ship an admin-only Filament page at `/admin/lead-import` that lets Sumit paste TSV from Google Sheets or upload CSV/XLSX, preview create/merge/flag/reject decisions, then commit in one transaction — replacing the three paused n8n Sheet-trigger workflows.

**Architecture:** A Livewire Filament page collects input → a per-source `SourceMapper` normalizes columns → `LeadIntakeService::preview()` (new pure method, extracted from `ingest()`) decides each row → admin confirms → `LeadImportService::commit()` runs `ingest()` per row inside one DB transaction → rejection rows land in a one-shot CSV. No new dedup logic; everything routes through the existing `LeadIntakeService`.

**Tech Stack:** Laravel 11, Filament 3, Livewire, PHP 8.4, MySQL, PhpSpreadsheet (new dep for XLSX), PHPUnit 11.

**Spec:** `docs/superpowers/specs/2026-04-22-manual-lead-import-design.md`

---

## File Structure

**Create:**
- `app/Services/LeadImport/SourceMapper.php` — interface
- `app/Services/LeadImport/Mappers/CanonicalMapper.php`
- `app/Services/LeadImport/Mappers/SonamMapper.php`
- `app/Services/LeadImport/Mappers/NikhilMapper.php`
- `app/Services/LeadImport/Mappers/SumitWebsiteMapper.php`
- `app/Services/LeadImport/Parser.php` — interface
- `app/Services/LeadImport/Parsers/TsvParser.php`
- `app/Services/LeadImport/Parsers/CsvParser.php`
- `app/Services/LeadImport/Parsers/XlsxParser.php`
- `app/Services/LeadImport/ImportAction.php` — value object
- `app/Services/LeadImport/ImportPreview.php` — value object
- `app/Services/LeadImport/LeadImportService.php`
- `app/Models/LeadImportBatch.php`
- `app/Filament/Pages/LeadImport.php`
- `app/Http/Controllers/LeadImportRejectionsController.php`
- `database/migrations/2026_04_22_120000_create_lead_import_batches_table.php`
- `resources/views/filament/pages/lead-import.blade.php`
- `public/templates/lead-import-canonical.csv`
- `public/templates/lead-import-sonam.csv`
- `public/templates/lead-import-nikhil.csv`
- `public/templates/lead-import-sumit-website.csv`
- `tests/Unit/LeadImport/Mappers/CanonicalMapperTest.php`
- `tests/Unit/LeadImport/Mappers/SonamMapperTest.php`
- `tests/Unit/LeadImport/Mappers/NikhilMapperTest.php`
- `tests/Unit/LeadImport/Mappers/SumitWebsiteMapperTest.php`
- `tests/Unit/LeadImport/Parsers/TsvParserTest.php`
- `tests/Unit/LeadImport/Parsers/CsvParserTest.php`
- `tests/Unit/LeadImport/Parsers/XlsxParserTest.php`
- `tests/Unit/LeadImport/LeadImportServicePreviewTest.php`
- `tests/Feature/LeadImport/LeadImportCommitTest.php`
- `tests/Feature/LeadImport/LeadImportPageTest.php`
- `tests/Feature/LeadImport/LeadImportRejectionsDownloadTest.php`
- `tests/Feature/LeadIntakeServiceParityTest.php`
- `tests/Fixtures/lead-import/sample.xlsx` (binary fixture)

**Modify:**
- `app/Services/LeadIntakeService.php` — extract `preview()`, refactor `ingest()` to use it
- `routes/web.php` — signed route for rejection CSV download
- `composer.json` / `composer.lock` — add `phpoffice/phpspreadsheet`

---

## Task 1: Extract `preview()` from `LeadIntakeService` (refactor with parity test)

**Goal:** Split decision logic from persistence in `LeadIntakeService` so the import UI can dry-run without writing. Behavior must be identical before/after.

**Files:**
- Create: `app/Services/LeadImport/ImportAction.php`
- Modify: `app/Services/LeadIntakeService.php`
- Create: `tests/Feature/LeadIntakeServiceParityTest.php`

- [ ] **Step 1: Create `ImportAction` value object**

Create `app/Services/LeadImport/ImportAction.php`:

```php
<?php

namespace App\Services\LeadImport;

class ImportAction
{
    public const CREATE = 'create';
    public const MERGE  = 'merge';   // demote existing, insert new
    public const FLAG   = 'flag';    // head-vs-head conflict
    public const REJECT = 'reject';  // duplicate, same/lower tier

    public function __construct(
        public readonly string $action,
        public readonly array $mappedPayload,
        public readonly ?int $existingStudentId = null,
        public readonly ?string $reason = null,
        public readonly ?int $rowNumber = null,
    ) {}

    public static function create(array $payload, ?int $rowNumber = null): self
    {
        return new self(self::CREATE, $payload, rowNumber: $rowNumber);
    }

    public static function merge(array $payload, int $existingId, ?int $rowNumber = null): self
    {
        return new self(self::MERGE, $payload, existingStudentId: $existingId, rowNumber: $rowNumber);
    }

    public static function flag(array $payload, int $existingId, ?int $rowNumber = null): self
    {
        return new self(self::FLAG, $payload, existingStudentId: $existingId, rowNumber: $rowNumber);
    }

    public static function reject(array $payload, string $reason, ?int $existingId = null, ?int $rowNumber = null): self
    {
        return new self(self::REJECT, $payload, existingStudentId: $existingId, reason: $reason, rowNumber: $rowNumber);
    }
}
```

- [ ] **Step 2: Write the parity test (will fail until refactor lands)**

Create `tests/Feature/LeadIntakeServiceParityTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Student;
use App\Services\LeadImport\ImportAction;
use App\Services\LeadIntakeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LeadIntakeServiceParityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    public function test_preview_action_matches_what_ingest_does_for_plain_insert(): void
    {
        $svc = app(LeadIntakeService::class);
        $payload = ['phone' => '9000000100', 'course' => 'BCA'];

        $previewed = $svc->preview($payload);
        $this->assertSame(ImportAction::CREATE, $previewed->action);

        // Same payload, now ingest
        $result = $svc->ingest($payload);
        $this->assertArrayHasKey('student', $result);
        $this->assertArrayNotHasKey('duplicate', $result);
        $this->assertArrayNotHasKey('flag', $result);
    }

    public function test_preview_flags_head_vs_head_conflict(): void
    {
        $svc = app(LeadIntakeService::class);
        $svc->ingest(['phone' => '9000000200', 'course' => 'BBA', 'owner_name' => 'Sonam']);

        $preview = $svc->preview(['phone' => '9000000200', 'course' => 'BBA', 'owner_name' => 'Nikhil']);
        $this->assertSame(ImportAction::FLAG, $preview->action);
        $this->assertNotNull($preview->existingStudentId);
    }

    public function test_preview_rejects_same_tier_duplicate(): void
    {
        $svc = app(LeadIntakeService::class);
        $first = $svc->ingest(['phone' => '9000000300', 'course' => 'BCA', 'owner_name' => 'Sumit']);

        $preview = $svc->preview(['phone' => '9000000300', 'course' => 'BCA', 'owner_name' => 'Sumit']);
        $this->assertSame(ImportAction::REJECT, $preview->action);
        $this->assertSame($first['student']->id, $preview->existingStudentId);
    }

    public function test_preview_merges_when_incoming_tier_beats_existing(): void
    {
        $svc = app(LeadIntakeService::class);
        $first = $svc->ingest(['phone' => '9000000400', 'course' => 'BCA', 'owner_name' => 'Sumit']);

        $preview = $svc->preview(['phone' => '9000000400', 'course' => 'BCA', 'owner_name' => 'Sonam']);
        $this->assertSame(ImportAction::MERGE, $preview->action);
        $this->assertSame($first['student']->id, $preview->existingStudentId);
    }

    public function test_preview_rejects_blank_phone(): void
    {
        $preview = app(LeadIntakeService::class)->preview(['phone' => '', 'course' => 'BCA']);
        $this->assertSame(ImportAction::REJECT, $preview->action);
        $this->assertSame('phone missing or unparseable', $preview->reason);
    }

    public function test_preview_does_not_write_to_db(): void
    {
        $before = Student::count();
        app(LeadIntakeService::class)->preview(['phone' => '9000000500', 'course' => 'BCA']);
        $this->assertSame($before, Student::count());
    }
}
```

- [ ] **Step 3: Run the parity test to confirm it fails**

Run: `/opt/alt/php84/usr/bin/php artisan test --filter=LeadIntakeServiceParityTest`

Expected: FAIL with "Method preview does not exist" (or similar).

- [ ] **Step 4: Refactor `LeadIntakeService` — add `preview()`, have `ingest()` call it**

Edit `app/Services/LeadIntakeService.php`:

Add imports at top:
```php
use App\Services\LeadImport\ImportAction;
```

Replace the `ingest()` body and add `preview()`. Key idea: `preview()` runs ALL the decision logic and returns an `ImportAction`. `ingest()` calls `preview()` and then *executes* the decision (inserting, demoting, flagging). No decision branches live outside `preview()` anymore.

```php
public function preview(array $data): ImportAction
{
    $phone = $this->normalizePhone($data['phone'] ?? null);
    if ($phone === null || $phone === '') {
        return ImportAction::reject($data, 'phone missing or unparseable');
    }

    $ownerName    = $this->trimOrNull($data['owner_name']    ?? null);
    $referrerName = $this->trimOrNull($data['referrer_name'] ?? null);
    [$ownerId, $referrerId] = $this->resolveOwnership($ownerName, $referrerName);
    $leadSource = $this->deriveLeadSource($data, $ownerName, $referrerName);
    $mapped = $this->buildStudentAttributes($data, $phone, $ownerId, $referrerId, $leadSource);

    $existing = Student::where('phone', $phone)->first();
    if ($existing === null) {
        return ImportAction::create($mapped);
    }

    $incomingTier = LeadPriority::tierByName($ownerName);
    $existingTier = LeadPriority::tier($existing->owner);

    if (LeadPriority::isHeadTier($incomingTier)
        && LeadPriority::isHeadTier($existingTier)
        && $ownerId !== null
        && $existing->owner_id !== null
        && $existing->owner_id !== $ownerId
    ) {
        return ImportAction::flag($mapped, $existing->id);
    }

    if ($incomingTier > $existingTier) {
        return ImportAction::merge($mapped, $existing->id);
    }

    return ImportAction::reject($mapped, 'duplicate of existing student', $existing->id);
}

public function ingest(array $data): array
{
    $decision = $this->preview($data);

    return match ($decision->action) {
        ImportAction::CREATE => ['student' => DB::transaction(fn () => Student::create($decision->mappedPayload))],
        ImportAction::MERGE  => $this->executeMerge($decision),
        ImportAction::FLAG   => $this->executeFlag($decision),
        ImportAction::REJECT => ['duplicate' => true, 'existing_id' => $decision->existingStudentId],
    };
}

private function executeMerge(ImportAction $decision): array
{
    return DB::transaction(function () use ($decision) {
        $existing = Student::findOrFail($decision->existingStudentId);
        $existing->phone = '__DEMOTED_'.$existing->id;
        $existing->saveQuietly();

        $new = Student::create($decision->mappedPayload);
        $this->reparentChildren($existing, $new);
        $demotedId = $existing->id;
        $existing->delete();
        return ['student' => $new, 'demoted_existing_id' => $demotedId];
    });
}

private function executeFlag(ImportAction $decision): array
{
    return DB::transaction(function () use ($decision) {
        $attrs = $decision->mappedPayload;
        $attrs['flagged_for_review'] = true;
        $attrs['flag_reason'] = DuplicateFlag::REASON_HEAD_OWNERSHIP;
        $new = Student::create($attrs);

        $existing = Student::findOrFail($decision->existingStudentId);
        $existing->flagged_for_review = true;
        $existing->flag_reason = DuplicateFlag::REASON_HEAD_OWNERSHIP;
        $existing->save();

        $flag = DuplicateFlag::create([
            'phone'        => $new->phone,
            'student_a_id' => $existing->id,
            'student_b_id' => $new->id,
            'reason'       => DuplicateFlag::REASON_HEAD_OWNERSHIP,
        ]);
        return ['student' => $new, 'flag' => $flag];
    });
}
```

Delete the old `resolveDuplicate()` method — its logic now lives inside `preview()` + `executeMerge()` + `executeFlag()`.

- [ ] **Step 5: Run parity test + full existing `LeadIntakeService` tests**

Run: `/opt/alt/php84/usr/bin/php artisan test --filter="LeadIntakeServiceParityTest|LeadIntakeServiceTest|LeadPriorityDedupTest|LeadCaptureTest"`

Expected: ALL PASS. If existing `LeadIntakeServiceTest` or `LeadPriorityDedupTest` fails, the refactor changed observable behavior — fix the refactor until they pass unchanged.

- [ ] **Step 6: Commit**

```bash
git add app/Services/LeadImport/ImportAction.php app/Services/LeadIntakeService.php tests/Feature/LeadIntakeServiceParityTest.php
git commit -m "refactor(lead-intake): extract preview() for dry-run decisions

ingest() now calls preview() then executes. Parity test locks the
invariant that dry-run and commit agree on create/merge/flag/reject
for the same payload."
```

---

## Task 2: `SourceMapper` interface + `CanonicalMapper`

**Goal:** Define the interface every source mapper satisfies, and ship the simplest one (pass-through for CRM-canonical headers).

**Files:**
- Create: `app/Services/LeadImport/SourceMapper.php`
- Create: `app/Services/LeadImport/Mappers/CanonicalMapper.php`
- Create: `tests/Unit/LeadImport/Mappers/CanonicalMapperTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/LeadImport/Mappers/CanonicalMapperTest.php`:

```php
<?php

namespace Tests\Unit\LeadImport\Mappers;

use App\Services\LeadImport\Mappers\CanonicalMapper;
use Tests\TestCase;

class CanonicalMapperTest extends TestCase
{
    public function test_expected_headers_are_canonical_crm_fields(): void
    {
        $headers = (new CanonicalMapper())->expectedHeaders();
        $this->assertSame(
            ['phone', 'name', 'course', 'rank', 'state', 'referrer_name', 'remarks', 'source'],
            $headers,
        );
    }

    public function test_maps_row_one_to_one_with_trim(): void
    {
        $mapper = new CanonicalMapper();
        $row = [
            'phone' => ' 9000000001 ',
            'name' => 'Asha',
            'course' => 'BCA',
            'rank' => '1234',
            'state' => 'Delhi',
            'referrer_name' => '',
            'remarks' => 'Walk-in',
            'source' => 'Website',
        ];
        $this->assertSame([
            'phone' => '9000000001',
            'name' => 'Asha',
            'course' => 'BCA',
            'rank' => '1234',
            'state' => 'Delhi',
            'referrer_name' => null,
            'remarks' => 'Walk-in',
            'source' => 'Website',
            'owner_name' => null,
        ], $mapper->map($row));
    }

    public function test_owner_hint_is_null_for_canonical(): void
    {
        $this->assertNull((new CanonicalMapper())->ownerHint());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `/opt/alt/php84/usr/bin/php artisan test --filter=CanonicalMapperTest`

Expected: FAIL with "Class CanonicalMapper not found".

- [ ] **Step 3: Create interface and implementation**

Create `app/Services/LeadImport/SourceMapper.php`:

```php
<?php

namespace App\Services\LeadImport;

interface SourceMapper
{
    /** Canonical header order for the downloadable template. */
    public function expectedHeaders(): array;

    /** Map one raw row (keyed by the source's header names) into a LeadIntakeService payload. */
    public function map(array $row): array;

    /** Owner name to inject if the row doesn't carry one, or null to leave unset. */
    public function ownerHint(): ?string;
}
```

Create `app/Services/LeadImport/Mappers/CanonicalMapper.php`:

```php
<?php

namespace App\Services\LeadImport\Mappers;

use App\Services\LeadImport\SourceMapper;

class CanonicalMapper implements SourceMapper
{
    public function expectedHeaders(): array
    {
        return ['phone', 'name', 'course', 'rank', 'state', 'referrer_name', 'remarks', 'source'];
    }

    public function map(array $row): array
    {
        return [
            'phone'         => $this->clean($row['phone'] ?? null),
            'name'          => $this->clean($row['name'] ?? null),
            'course'        => $this->clean($row['course'] ?? null),
            'rank'          => $this->clean($row['rank'] ?? null),
            'state'         => $this->clean($row['state'] ?? null),
            'referrer_name' => $this->clean($row['referrer_name'] ?? null),
            'remarks'       => $this->clean($row['remarks'] ?? null),
            'source'        => $this->clean($row['source'] ?? null),
            'owner_name'    => null,
        ];
    }

    public function ownerHint(): ?string
    {
        return null;
    }

    private function clean(?string $v): ?string
    {
        if ($v === null) return null;
        $v = trim($v);
        return $v === '' ? null : $v;
    }
}
```

- [ ] **Step 4: Run tests to verify pass**

Run: `/opt/alt/php84/usr/bin/php artisan test --filter=CanonicalMapperTest`

Expected: 3 tests PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Services/LeadImport/SourceMapper.php app/Services/LeadImport/Mappers/CanonicalMapper.php tests/Unit/LeadImport/Mappers/CanonicalMapperTest.php
git commit -m "feat(lead-import): SourceMapper interface + CanonicalMapper"
```

---

## Task 3: `SonamMapper`

**Goal:** Map Sonam's narrow sheet (`Date | Ph no | Course | Rank | D/OD | enquiry | connected to.`) to canonical payload. Owner is always "Sonam".

**Files:**
- Create: `app/Services/LeadImport/Mappers/SonamMapper.php`
- Create: `tests/Unit/LeadImport/Mappers/SonamMapperTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/LeadImport/Mappers/SonamMapperTest.php`:

```php
<?php

namespace Tests\Unit\LeadImport\Mappers;

use App\Services\LeadImport\Mappers\SonamMapper;
use Tests\TestCase;

class SonamMapperTest extends TestCase
{
    public function test_expected_headers_match_sonam_sheet_exactly(): void
    {
        $this->assertSame(
            ['Date', 'Ph no', 'Course', 'Rank', 'D/OD', 'enquiry', 'connected to.'],
            (new SonamMapper())->expectedHeaders(),
        );
    }

    public function test_maps_clean_row(): void
    {
        $row = [
            'Date' => '2026-04-20',
            'Ph no' => '9000000001',
            'Course' => 'BCA',
            'Rank' => '1234',
            'D/OD' => 'D',
            'enquiry' => 'Fees query',
            'connected to.' => 'Nisha',
        ];
        $this->assertSame([
            'phone' => '9000000001',
            'course' => 'BCA',
            'rank' => '1234',
            'category' => 'D',
            'remarks' => 'Fees query',
            'referrer_name' => 'Nisha',
            'owner_name' => 'Sonam',
            'source' => 'Sheet:Sonam',
        ], (new SonamMapper())->map($row));
    }

    public function test_normalizes_whitespace_and_empty_optional_columns(): void
    {
        $row = [
            'Date' => '',
            'Ph no' => '  +91 90000-00002 ',
            'Course' => ' BBA ',
            'Rank' => '',
            'D/OD' => '',
            'enquiry' => '',
            'connected to.' => '',
        ];
        $mapped = (new SonamMapper())->map($row);
        $this->assertSame('919000000002', $mapped['phone']);  // raw digits; LeadIntakeService re-normalizes
        $this->assertSame('BBA', $mapped['course']);
        $this->assertNull($mapped['rank']);
        $this->assertNull($mapped['referrer_name']);
    }

    public function test_owner_hint_is_sonam(): void
    {
        $this->assertSame('Sonam', (new SonamMapper())->ownerHint());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `/opt/alt/php84/usr/bin/php artisan test --filter=SonamMapperTest`

Expected: FAIL with "Class SonamMapper not found".

- [ ] **Step 3: Implement `SonamMapper`**

The mapper trims whitespace on text fields and strips all non-digits from phone (leaving the `91` country prefix for `LeadIntakeService::normalizePhone()` to chop later).

Create `app/Services/LeadImport/Mappers/SonamMapper.php`:

```php
<?php

namespace App\Services\LeadImport\Mappers;

use App\Services\LeadImport\SourceMapper;

class SonamMapper implements SourceMapper
{
    public function expectedHeaders(): array
    {
        return ['Date', 'Ph no', 'Course', 'Rank', 'D/OD', 'enquiry', 'connected to.'];
    }

    public function map(array $row): array
    {
        return [
            'phone'         => $this->cleanPhone($row['Ph no'] ?? null),
            'course'        => $this->clean($row['Course'] ?? null),
            'rank'          => $this->clean($row['Rank'] ?? null),
            'category'      => $this->clean($row['D/OD'] ?? null),
            'remarks'       => $this->clean($row['enquiry'] ?? null),
            'referrer_name' => $this->clean($row['connected to.'] ?? null),
            'owner_name'    => 'Sonam',
            'source'        => 'Sheet:Sonam',
        ];
    }

    public function ownerHint(): ?string
    {
        return 'Sonam';
    }

    private function clean(?string $v): ?string
    {
        if ($v === null) return null;
        $v = preg_replace('/\s+/', ' ', trim($v));
        return $v === '' ? null : $v;
    }

    private function cleanPhone(?string $v): ?string
    {
        if ($v === null) return null;
        $digits = preg_replace('/\D+/', '', $v);
        return $digits === '' ? null : $digits;
    }
}
```

- [ ] **Step 4: Run tests to verify pass**

Run: `/opt/alt/php84/usr/bin/php artisan test --filter=SonamMapperTest`

Expected: 4 tests PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Services/LeadImport/Mappers/SonamMapper.php tests/Unit/LeadImport/Mappers/SonamMapperTest.php
git commit -m "feat(lead-import): SonamMapper for narrow sheet columns"
```

---

## Task 4: `NikhilMapper`

**Goal:** Map Nikhil's sheet columns to canonical payload. Owner is "Nikhil".

**Files:**
- Create: `app/Services/LeadImport/Mappers/NikhilMapper.php`
- Create: `tests/Unit/LeadImport/Mappers/NikhilMapperTest.php`

**Column contract:** Nikhil's sheet headers are `Name | Phone | Course | Rank | State | Referrer | Remarks` (per the n8n `lead-nikhil-sheet` Set-node mapping — verify against the live n8n workflow before committing; if the live mapping differs, update the headers and the test in lockstep).

- [ ] **Step 1: Verify current n8n column mapping**

Open n8n workflow `v3b8K2UC08QY4V3H` (`lead-nikhil-sheet`) in the UI, inspect the Set node. Confirm the seven headers above. If any differ, replace below accordingly before writing the test.

- [ ] **Step 2: Write the failing test**

Create `tests/Unit/LeadImport/Mappers/NikhilMapperTest.php`:

```php
<?php

namespace Tests\Unit\LeadImport\Mappers;

use App\Services\LeadImport\Mappers\NikhilMapper;
use Tests\TestCase;

class NikhilMapperTest extends TestCase
{
    public function test_expected_headers(): void
    {
        $this->assertSame(
            ['Name', 'Phone', 'Course', 'Rank', 'State', 'Referrer', 'Remarks'],
            (new NikhilMapper())->expectedHeaders(),
        );
    }

    public function test_maps_clean_row(): void
    {
        $row = [
            'Name' => 'Asha Kumari',
            'Phone' => '9000000010',
            'Course' => 'BBA',
            'Rank' => '5678',
            'State' => 'UP',
            'Referrer' => 'Nisha',
            'Remarks' => 'Called back',
        ];
        $this->assertSame([
            'phone' => '9000000010',
            'name' => 'Asha Kumari',
            'course' => 'BBA',
            'rank' => '5678',
            'state' => 'UP',
            'referrer_name' => 'Nisha',
            'remarks' => 'Called back',
            'owner_name' => 'Nikhil',
            'source' => 'Sheet:Nikhil',
        ], (new NikhilMapper())->map($row));
    }

    public function test_empty_optionals_become_null(): void
    {
        $row = array_fill_keys(['Name', 'Phone', 'Course', 'Rank', 'State', 'Referrer', 'Remarks'], '');
        $row['Phone'] = '9000000011';
        $mapped = (new NikhilMapper())->map($row);
        $this->assertSame('9000000011', $mapped['phone']);
        $this->assertNull($mapped['name']);
        $this->assertNull($mapped['state']);
        $this->assertSame('Nikhil', $mapped['owner_name']);
    }

    public function test_owner_hint(): void
    {
        $this->assertSame('Nikhil', (new NikhilMapper())->ownerHint());
    }
}
```

- [ ] **Step 3: Run test to verify it fails**

Run: `/opt/alt/php84/usr/bin/php artisan test --filter=NikhilMapperTest`

Expected: FAIL with "Class NikhilMapper not found".

- [ ] **Step 4: Implement `NikhilMapper`**

Create `app/Services/LeadImport/Mappers/NikhilMapper.php`:

```php
<?php

namespace App\Services\LeadImport\Mappers;

use App\Services\LeadImport\SourceMapper;

class NikhilMapper implements SourceMapper
{
    public function expectedHeaders(): array
    {
        return ['Name', 'Phone', 'Course', 'Rank', 'State', 'Referrer', 'Remarks'];
    }

    public function map(array $row): array
    {
        return [
            'phone'         => $this->cleanPhone($row['Phone'] ?? null),
            'name'          => $this->clean($row['Name'] ?? null),
            'course'        => $this->clean($row['Course'] ?? null),
            'rank'          => $this->clean($row['Rank'] ?? null),
            'state'         => $this->clean($row['State'] ?? null),
            'referrer_name' => $this->clean($row['Referrer'] ?? null),
            'remarks'       => $this->clean($row['Remarks'] ?? null),
            'owner_name'    => 'Nikhil',
            'source'        => 'Sheet:Nikhil',
        ];
    }

    public function ownerHint(): ?string
    {
        return 'Nikhil';
    }

    private function clean(?string $v): ?string
    {
        if ($v === null) return null;
        $v = preg_replace('/\s+/', ' ', trim($v));
        return $v === '' ? null : $v;
    }

    private function cleanPhone(?string $v): ?string
    {
        if ($v === null) return null;
        $digits = preg_replace('/\D+/', '', $v);
        return $digits === '' ? null : $digits;
    }
}
```

- [ ] **Step 5: Run tests to verify pass**

Run: `/opt/alt/php84/usr/bin/php artisan test --filter=NikhilMapperTest`

Expected: 4 tests PASS.

- [ ] **Step 6: Commit**

```bash
git add app/Services/LeadImport/Mappers/NikhilMapper.php tests/Unit/LeadImport/Mappers/NikhilMapperTest.php
git commit -m "feat(lead-import): NikhilMapper"
```

---

## Task 5: `SumitWebsiteMapper`

**Goal:** Map the Sumit-website sheet (ipu.co.in lead form output) columns to canonical payload. Owner is "Sumit".

**Files:**
- Create: `app/Services/LeadImport/Mappers/SumitWebsiteMapper.php`
- Create: `tests/Unit/LeadImport/Mappers/SumitWebsiteMapperTest.php`

**Column contract:** Website-form output has `Timestamp | Name | Email | Phone | Course | Rank | State | Message` per the live form. Confirm against the n8n `lead-sumit-website-sheet` Set node before coding.

- [ ] **Step 1: Verify current n8n column mapping**

Open workflow `7cqS00mq6r2yGJDG` in n8n, verify Set-node headers. Update this task if they differ.

- [ ] **Step 2: Write the failing test**

Create `tests/Unit/LeadImport/Mappers/SumitWebsiteMapperTest.php`:

```php
<?php

namespace Tests\Unit\LeadImport\Mappers;

use App\Services\LeadImport\Mappers\SumitWebsiteMapper;
use Tests\TestCase;

class SumitWebsiteMapperTest extends TestCase
{
    public function test_expected_headers(): void
    {
        $this->assertSame(
            ['Timestamp', 'Name', 'Email', 'Phone', 'Course', 'Rank', 'State', 'Message'],
            (new SumitWebsiteMapper())->expectedHeaders(),
        );
    }

    public function test_maps_clean_row(): void
    {
        $row = [
            'Timestamp' => '2026-04-22 14:30:00',
            'Name' => 'Ravi',
            'Email' => 'ravi@example.com',
            'Phone' => '9000000020',
            'Course' => 'B.Tech',
            'Rank' => '2345',
            'State' => 'Delhi',
            'Message' => 'Course info please',
        ];
        $this->assertSame([
            'phone' => '9000000020',
            'name' => 'Ravi',
            'email' => 'ravi@example.com',
            'course' => 'B.Tech',
            'rank' => '2345',
            'state' => 'Delhi',
            'remarks' => 'Course info please',
            'owner_name' => 'Sumit',
            'source' => 'Sheet:Sumit-website',
        ], (new SumitWebsiteMapper())->map($row));
    }

    public function test_empty_optionals_become_null(): void
    {
        $row = array_fill_keys(['Timestamp', 'Name', 'Email', 'Phone', 'Course', 'Rank', 'State', 'Message'], '');
        $row['Phone'] = '9000000021';
        $mapped = (new SumitWebsiteMapper())->map($row);
        $this->assertSame('9000000021', $mapped['phone']);
        $this->assertNull($mapped['email']);
        $this->assertNull($mapped['remarks']);
    }

    public function test_owner_hint(): void
    {
        $this->assertSame('Sumit', (new SumitWebsiteMapper())->ownerHint());
    }
}
```

- [ ] **Step 3: Run test to verify it fails**

Run: `/opt/alt/php84/usr/bin/php artisan test --filter=SumitWebsiteMapperTest`

Expected: FAIL with "Class SumitWebsiteMapper not found".

- [ ] **Step 4: Implement `SumitWebsiteMapper`**

Create `app/Services/LeadImport/Mappers/SumitWebsiteMapper.php`:

```php
<?php

namespace App\Services\LeadImport\Mappers;

use App\Services\LeadImport\SourceMapper;

class SumitWebsiteMapper implements SourceMapper
{
    public function expectedHeaders(): array
    {
        return ['Timestamp', 'Name', 'Email', 'Phone', 'Course', 'Rank', 'State', 'Message'];
    }

    public function map(array $row): array
    {
        return [
            'phone'      => $this->cleanPhone($row['Phone'] ?? null),
            'name'       => $this->clean($row['Name'] ?? null),
            'email'      => $this->clean($row['Email'] ?? null),
            'course'     => $this->clean($row['Course'] ?? null),
            'rank'       => $this->clean($row['Rank'] ?? null),
            'state'      => $this->clean($row['State'] ?? null),
            'remarks'    => $this->clean($row['Message'] ?? null),
            'owner_name' => 'Sumit',
            'source'     => 'Sheet:Sumit-website',
        ];
    }

    public function ownerHint(): ?string
    {
        return 'Sumit';
    }

    private function clean(?string $v): ?string
    {
        if ($v === null) return null;
        $v = preg_replace('/\s+/', ' ', trim($v));
        return $v === '' ? null : $v;
    }

    private function cleanPhone(?string $v): ?string
    {
        if ($v === null) return null;
        $digits = preg_replace('/\D+/', '', $v);
        return $digits === '' ? null : $digits;
    }
}
```

- [ ] **Step 5: Run tests to verify pass**

Run: `/opt/alt/php84/usr/bin/php artisan test --filter=SumitWebsiteMapperTest`

Expected: 4 tests PASS.

- [ ] **Step 6: Commit**

```bash
git add app/Services/LeadImport/Mappers/SumitWebsiteMapper.php tests/Unit/LeadImport/Mappers/SumitWebsiteMapperTest.php
git commit -m "feat(lead-import): SumitWebsiteMapper"
```

---

## Task 6: `Parser` interface + `TsvParser`

**Goal:** Parse a pasted TSV block (copied from Google Sheets) into an array of associative rows keyed by the header line.

**Files:**
- Create: `app/Services/LeadImport/Parser.php`
- Create: `app/Services/LeadImport/Parsers/TsvParser.php`
- Create: `tests/Unit/LeadImport/Parsers/TsvParserTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/LeadImport/Parsers/TsvParserTest.php`:

```php
<?php

namespace Tests\Unit\LeadImport\Parsers;

use App\Services\LeadImport\Parsers\TsvParser;
use Tests\TestCase;

class TsvParserTest extends TestCase
{
    public function test_parses_header_and_rows(): void
    {
        $tsv = "Phone\tCourse\tRank\n9000000001\tBCA\t1234\n9000000002\tBBA\t5678\n";
        $rows = (new TsvParser())->parse($tsv, ['Phone', 'Course', 'Rank']);

        $this->assertCount(2, $rows);
        $this->assertSame(['Phone' => '9000000001', 'Course' => 'BCA', 'Rank' => '1234'], $rows[0]);
        $this->assertSame(['Phone' => '9000000002', 'Course' => 'BBA', 'Rank' => '5678'], $rows[1]);
    }

    public function test_blank_lines_are_skipped(): void
    {
        $tsv = "A\tB\n1\t2\n\n3\t4\n";
        $this->assertCount(2, (new TsvParser())->parse($tsv, ['A', 'B']));
    }

    public function test_short_rows_are_padded_with_empty_strings(): void
    {
        $tsv = "A\tB\tC\n1\t2\n";
        $rows = (new TsvParser())->parse($tsv, ['A', 'B', 'C']);
        $this->assertSame(['A' => '1', 'B' => '2', 'C' => ''], $rows[0]);
    }

    public function test_missing_header_column_throws(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/missing required column.*Rank/i');
        (new TsvParser())->parse("Phone\tCourse\n9000000001\tBCA\n", ['Phone', 'Course', 'Rank']);
    }

    public function test_empty_input_returns_empty_array(): void
    {
        $this->assertSame([], (new TsvParser())->parse('', ['A']));
        $this->assertSame([], (new TsvParser())->parse("A\n", ['A']));  // header only
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `/opt/alt/php84/usr/bin/php artisan test --filter=TsvParserTest`

Expected: FAIL with "Class TsvParser not found".

- [ ] **Step 3: Create interface and parser**

Create `app/Services/LeadImport/Parser.php`:

```php
<?php

namespace App\Services\LeadImport;

interface Parser
{
    /**
     * Parse raw input into an array of header-keyed rows.
     *
     * @param string $raw  Raw text content (TSV/CSV) or bytes (XLSX)
     * @param array<int, string> $expectedHeaders  Required header names; throws if any missing
     * @return array<int, array<string, string>>
     * @throws \RuntimeException on malformed input or missing required headers
     */
    public function parse(string $raw, array $expectedHeaders): array;
}
```

Create `app/Services/LeadImport/Parsers/TsvParser.php`:

```php
<?php

namespace App\Services\LeadImport\Parsers;

use App\Services\LeadImport\Parser;
use RuntimeException;

class TsvParser implements Parser
{
    public function parse(string $raw, array $expectedHeaders): array
    {
        $raw = str_replace("\r\n", "\n", $raw);
        $lines = array_values(array_filter(explode("\n", $raw), fn ($l) => trim($l) !== ''));
        if (empty($lines)) {
            return [];
        }

        $headers = explode("\t", array_shift($lines));
        $headers = array_map('trim', $headers);

        $missing = array_values(array_diff($expectedHeaders, $headers));
        if (!empty($missing)) {
            throw new RuntimeException('Missing required column(s): ' . implode(', ', $missing));
        }

        $rows = [];
        foreach ($lines as $line) {
            $cells = explode("\t", $line);
            $row = [];
            foreach ($headers as $i => $h) {
                $row[$h] = $cells[$i] ?? '';
            }
            $rows[] = $row;
        }
        return $rows;
    }
}
```

- [ ] **Step 4: Run tests to verify pass**

Run: `/opt/alt/php84/usr/bin/php artisan test --filter=TsvParserTest`

Expected: 5 tests PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Services/LeadImport/Parser.php app/Services/LeadImport/Parsers/TsvParser.php tests/Unit/LeadImport/Parsers/TsvParserTest.php
git commit -m "feat(lead-import): Parser interface + TsvParser"
```

---

## Task 7: `CsvParser`

**Goal:** Parse uploaded CSV text into the same shape as `TsvParser`.

**Files:**
- Create: `app/Services/LeadImport/Parsers/CsvParser.php`
- Create: `tests/Unit/LeadImport/Parsers/CsvParserTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/LeadImport/Parsers/CsvParserTest.php`:

```php
<?php

namespace Tests\Unit\LeadImport\Parsers;

use App\Services\LeadImport\Parsers\CsvParser;
use Tests\TestCase;

class CsvParserTest extends TestCase
{
    public function test_parses_basic_csv(): void
    {
        $csv = "Phone,Course,Rank\n9000000001,BCA,1234\n9000000002,BBA,5678\n";
        $rows = (new CsvParser())->parse($csv, ['Phone', 'Course', 'Rank']);

        $this->assertCount(2, $rows);
        $this->assertSame('9000000001', $rows[0]['Phone']);
        $this->assertSame('BBA', $rows[1]['Course']);
    }

    public function test_handles_quoted_fields_with_commas(): void
    {
        $csv = "Phone,Message\n9000000001,\"Hello, world\"\n";
        $rows = (new CsvParser())->parse($csv, ['Phone', 'Message']);
        $this->assertSame('Hello, world', $rows[0]['Message']);
    }

    public function test_missing_required_column_throws(): void
    {
        $this->expectException(\RuntimeException::class);
        (new CsvParser())->parse("Phone\n9000000001\n", ['Phone', 'Course']);
    }

    public function test_bom_is_stripped(): void
    {
        $csv = "\xEF\xBB\xBFPhone,Course\n9000000001,BCA\n";
        $rows = (new CsvParser())->parse($csv, ['Phone', 'Course']);
        $this->assertSame('9000000001', $rows[0]['Phone']);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `/opt/alt/php84/usr/bin/php artisan test --filter=CsvParserTest`

Expected: FAIL with "Class CsvParser not found".

- [ ] **Step 3: Implement `CsvParser`**

Create `app/Services/LeadImport/Parsers/CsvParser.php`:

```php
<?php

namespace App\Services\LeadImport\Parsers;

use App\Services\LeadImport\Parser;
use RuntimeException;

class CsvParser implements Parser
{
    public function parse(string $raw, array $expectedHeaders): array
    {
        // Strip UTF-8 BOM
        if (str_starts_with($raw, "\xEF\xBB\xBF")) {
            $raw = substr($raw, 3);
        }
        $raw = str_replace("\r\n", "\n", $raw);

        $handle = fopen('php://temp', 'r+');
        fwrite($handle, $raw);
        rewind($handle);

        $headers = fgetcsv($handle);
        if ($headers === false) {
            fclose($handle);
            return [];
        }
        $headers = array_map('trim', $headers);

        $missing = array_values(array_diff($expectedHeaders, $headers));
        if (!empty($missing)) {
            fclose($handle);
            throw new RuntimeException('Missing required column(s): ' . implode(', ', $missing));
        }

        $rows = [];
        while (($cells = fgetcsv($handle)) !== false) {
            if (count($cells) === 1 && ($cells[0] === null || trim($cells[0]) === '')) {
                continue;
            }
            $row = [];
            foreach ($headers as $i => $h) {
                $row[$h] = $cells[$i] ?? '';
            }
            $rows[] = $row;
        }
        fclose($handle);
        return $rows;
    }
}
```

- [ ] **Step 4: Run tests to verify pass**

Run: `/opt/alt/php84/usr/bin/php artisan test --filter=CsvParserTest`

Expected: 4 tests PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Services/LeadImport/Parsers/CsvParser.php tests/Unit/LeadImport/Parsers/CsvParserTest.php
git commit -m "feat(lead-import): CsvParser"
```

---

## Task 8: `XlsxParser` + add PhpSpreadsheet dependency

**Goal:** Accept XLSX uploads, parse via PhpSpreadsheet, first sheet, first row as headers.

**Files:**
- Modify: `composer.json`, `composer.lock`
- Create: `app/Services/LeadImport/Parsers/XlsxParser.php`
- Create: `tests/Unit/LeadImport/Parsers/XlsxParserTest.php`
- Create: `tests/Fixtures/lead-import/sample.xlsx` (binary)

- [ ] **Step 1: Add PhpSpreadsheet**

Run: `/opt/alt/php84/usr/bin/php /usr/local/bin/composer require phpoffice/phpspreadsheet:^2.0`

Expected: `phpoffice/phpspreadsheet` added to `require`, `composer.lock` updated.

- [ ] **Step 2: Generate the binary fixture**

Run (one-liner, executed via tinker to create the XLSX on disk):

```bash
/opt/alt/php84/usr/bin/php artisan tinker --execute="
\$s = new PhpOffice\PhpSpreadsheet\Spreadsheet();
\$sheet = \$s->getActiveSheet();
\$sheet->fromArray([
    ['Phone', 'Course', 'Rank'],
    ['9000000001', 'BCA', 1234],
    ['9000000002', 'BBA', 5678],
]);
@mkdir(base_path('tests/Fixtures/lead-import'), 0755, true);
(new PhpOffice\PhpSpreadsheet\Writer\Xlsx(\$s))->save(base_path('tests/Fixtures/lead-import/sample.xlsx'));
echo 'ok';
"
```

Expected output: `ok`. File exists at `tests/Fixtures/lead-import/sample.xlsx`.

- [ ] **Step 3: Write the failing test**

Create `tests/Unit/LeadImport/Parsers/XlsxParserTest.php`:

```php
<?php

namespace Tests\Unit\LeadImport\Parsers;

use App\Services\LeadImport\Parsers\XlsxParser;
use Tests\TestCase;

class XlsxParserTest extends TestCase
{
    public function test_parses_first_sheet(): void
    {
        $bytes = file_get_contents(base_path('tests/Fixtures/lead-import/sample.xlsx'));
        $rows = (new XlsxParser())->parse($bytes, ['Phone', 'Course', 'Rank']);

        $this->assertCount(2, $rows);
        $this->assertSame('9000000001', $rows[0]['Phone']);
        $this->assertSame('BCA', $rows[0]['Course']);
        $this->assertSame('1234', $rows[0]['Rank']);
    }

    public function test_missing_required_column_throws(): void
    {
        $bytes = file_get_contents(base_path('tests/Fixtures/lead-import/sample.xlsx'));
        $this->expectException(\RuntimeException::class);
        (new XlsxParser())->parse($bytes, ['Phone', 'Course', 'State']);
    }

    public function test_malformed_bytes_throws(): void
    {
        $this->expectException(\RuntimeException::class);
        (new XlsxParser())->parse('not a real xlsx', ['Phone']);
    }
}
```

- [ ] **Step 4: Run test to verify it fails**

Run: `/opt/alt/php84/usr/bin/php artisan test --filter=XlsxParserTest`

Expected: FAIL with "Class XlsxParser not found".

- [ ] **Step 5: Implement `XlsxParser`**

Create `app/Services/LeadImport/Parsers/XlsxParser.php`:

```php
<?php

namespace App\Services\LeadImport\Parsers;

use App\Services\LeadImport\Parser;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\Exception as ReaderException;
use RuntimeException;

class XlsxParser implements Parser
{
    public function parse(string $raw, array $expectedHeaders): array
    {
        $tmp = tempnam(sys_get_temp_dir(), 'xlsx_');
        file_put_contents($tmp, $raw);

        try {
            $reader = IOFactory::createReader('Xlsx');
            $reader->setReadDataOnly(true);
            $spreadsheet = $reader->load($tmp);
        } catch (ReaderException $e) {
            unlink($tmp);
            throw new RuntimeException('Could not read XLSX: ' . $e->getMessage(), 0, $e);
        } finally {
            if (file_exists($tmp)) {
                unlink($tmp);
            }
        }

        $sheet = $spreadsheet->getActiveSheet();
        $data = $sheet->toArray(null, true, false, false);

        if (empty($data)) {
            return [];
        }

        $headers = array_map(fn ($v) => trim((string) $v), array_shift($data));
        $missing = array_values(array_diff($expectedHeaders, $headers));
        if (!empty($missing)) {
            throw new RuntimeException('Missing required column(s): ' . implode(', ', $missing));
        }

        $rows = [];
        foreach ($data as $line) {
            if ($this->rowIsEmpty($line)) continue;
            $row = [];
            foreach ($headers as $i => $h) {
                $row[$h] = trim((string) ($line[$i] ?? ''));
            }
            $rows[] = $row;
        }
        return $rows;
    }

    private function rowIsEmpty(array $cells): bool
    {
        foreach ($cells as $c) {
            if ($c !== null && trim((string) $c) !== '') return false;
        }
        return true;
    }
}
```

- [ ] **Step 6: Run tests to verify pass**

Run: `/opt/alt/php84/usr/bin/php artisan test --filter=XlsxParserTest`

Expected: 3 tests PASS.

- [ ] **Step 7: Commit**

```bash
git add composer.json composer.lock app/Services/LeadImport/Parsers/XlsxParser.php tests/Unit/LeadImport/Parsers/XlsxParserTest.php tests/Fixtures/lead-import/sample.xlsx
git commit -m "feat(lead-import): XlsxParser + phpoffice/phpspreadsheet dep"
```

---

## Task 9: `LeadImportBatch` model + migration

**Goal:** Audit-trail row per successful commit. One row holds aggregated counts + rejection CSV path.

**Files:**
- Create: `database/migrations/2026_04_22_120000_create_lead_import_batches_table.php`
- Create: `app/Models/LeadImportBatch.php`
- Create: `tests/Unit/LeadImportBatchModelTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/LeadImportBatchModelTest.php`:

```php
<?php

namespace Tests\Unit;

use App\Models\LeadImportBatch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LeadImportBatchModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_can_create_batch_row(): void
    {
        $this->seed();
        $user = User::role('admin')->firstOrFail();
        $batch = LeadImportBatch::create([
            'user_id' => $user->id,
            'source' => 'sonam',
            'row_count' => 40,
            'created_count' => 28,
            'merged_count' => 8,
            'flagged_count' => 3,
            'rejected_count' => 1,
            'rejections_csv_path' => 'lead-imports/uuid.csv',
        ]);
        $this->assertTrue($batch->exists);
        $this->assertSame(40, $batch->row_count);
        $this->assertSame($user->id, $batch->user->id);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `/opt/alt/php84/usr/bin/php artisan test --filter=LeadImportBatchModelTest`

Expected: FAIL with "Class LeadImportBatch not found".

- [ ] **Step 3: Create migration**

Create `database/migrations/2026_04_22_120000_create_lead_import_batches_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('lead_import_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('source', 32);
            $table->unsignedInteger('row_count')->default(0);
            $table->unsignedInteger('created_count')->default(0);
            $table->unsignedInteger('merged_count')->default(0);
            $table->unsignedInteger('flagged_count')->default(0);
            $table->unsignedInteger('rejected_count')->default(0);
            $table->string('rejections_csv_path', 255)->nullable();
            $table->timestamps();
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lead_import_batches');
    }
};
```

- [ ] **Step 4: Create model**

Create `app/Models/LeadImportBatch.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LeadImportBatch extends Model
{
    protected $guarded = [];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
```

- [ ] **Step 5: Run test to verify it passes**

Run: `/opt/alt/php84/usr/bin/php artisan test --filter=LeadImportBatchModelTest`

Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add database/migrations/2026_04_22_120000_create_lead_import_batches_table.php app/Models/LeadImportBatch.php tests/Unit/LeadImportBatchModelTest.php
git commit -m "feat(lead-import): LeadImportBatch model + migration"
```

---

## Task 10: `LeadImportService::preview()` orchestrator

**Goal:** Service method that takes (source, raw input, parser hint) and returns an `ImportPreview` holding all `ImportAction`s, no DB writes.

**Files:**
- Create: `app/Services/LeadImport/ImportPreview.php`
- Create: `app/Services/LeadImport/LeadImportService.php`
- Create: `tests/Unit/LeadImport/LeadImportServicePreviewTest.php`

- [ ] **Step 1: Create the `ImportPreview` value object**

Create `app/Services/LeadImport/ImportPreview.php`:

```php
<?php

namespace App\Services\LeadImport;

class ImportPreview
{
    /**
     * @param array<int, ImportAction> $actions
     */
    public function __construct(
        public readonly string $source,
        public readonly array $actions,
    ) {}

    /** @return array<int, ImportAction> */
    public function byAction(string $action): array
    {
        return array_values(array_filter($this->actions, fn (ImportAction $a) => $a->action === $action));
    }

    public function countBy(string $action): int
    {
        return count($this->byAction($action));
    }

    public function rowCount(): int
    {
        return count($this->actions);
    }
}
```

- [ ] **Step 2: Write the failing test**

Create `tests/Unit/LeadImport/LeadImportServicePreviewTest.php`:

```php
<?php

namespace Tests\Unit\LeadImport;

use App\Services\LeadImport\ImportAction;
use App\Services\LeadImport\LeadImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class LeadImportServicePreviewTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    public function test_previews_tsv_paste_for_sonam_source(): void
    {
        $tsv = "Date\tPh no\tCourse\tRank\tD/OD\tenquiry\tconnected to.\n"
             . "2026-04-22\t9000000600\tBCA\t1234\tD\tFees\tNisha\n"
             . "2026-04-22\t9000000601\tBBA\t5678\tOD\t\tNisha\n";

        $preview = app(LeadImportService::class)->preview('sonam', $tsv);

        $this->assertSame('sonam', $preview->source);
        $this->assertSame(2, $preview->rowCount());
        $this->assertSame(2, $preview->countBy(ImportAction::CREATE));
    }

    public function test_bad_phone_row_previews_as_reject(): void
    {
        $tsv = "Date\tPh no\tCourse\tRank\tD/OD\tenquiry\tconnected to.\n"
             . "2026-04-22\t\tBCA\t\t\t\t\n";
        $preview = app(LeadImportService::class)->preview('sonam', $tsv);
        $this->assertSame(1, $preview->countBy(ImportAction::REJECT));
    }

    public function test_invalid_source_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        app(LeadImportService::class)->preview('not-a-source', "anything");
    }

    public function test_previews_csv_upload_for_canonical_source(): void
    {
        $csv = "phone,name,course,rank,state,referrer_name,remarks,source\n"
             . "9000000700,Asha,BCA,1234,Delhi,,,\n";
        $file = UploadedFile::fake()->createWithContent('import.csv', $csv);

        $preview = app(LeadImportService::class)->preview('canonical', $file);
        $this->assertSame(1, $preview->rowCount());
        $this->assertSame(ImportAction::CREATE, $preview->actions[0]->action);
    }

    public function test_parse_failure_bubbles_up(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/Missing required column/');
        app(LeadImportService::class)->preview('sonam', "A\tB\n1\t2\n");
    }
}
```

- [ ] **Step 3: Run test to verify it fails**

Run: `/opt/alt/php84/usr/bin/php artisan test --filter=LeadImportServicePreviewTest`

Expected: FAIL with "Class LeadImportService not found".

- [ ] **Step 4: Implement `LeadImportService`**

Create `app/Services/LeadImport/LeadImportService.php` (commit will only include `preview()` for now; `commit()` lands in Task 11):

```php
<?php

namespace App\Services\LeadImport;

use App\Services\LeadImport\Mappers\CanonicalMapper;
use App\Services\LeadImport\Mappers\NikhilMapper;
use App\Services\LeadImport\Mappers\SonamMapper;
use App\Services\LeadImport\Mappers\SumitWebsiteMapper;
use App\Services\LeadImport\Parsers\CsvParser;
use App\Services\LeadImport\Parsers\TsvParser;
use App\Services\LeadImport\Parsers\XlsxParser;
use App\Services\LeadIntakeService;
use Illuminate\Http\UploadedFile;
use InvalidArgumentException;

class LeadImportService
{
    public const SOURCES = ['sonam', 'nikhil', 'sumit-website', 'canonical'];

    public function __construct(private LeadIntakeService $intake) {}

    /**
     * @param string|UploadedFile $input  TSV string (paste) or an uploaded CSV/XLSX file
     */
    public function preview(string $source, string|UploadedFile $input): ImportPreview
    {
        $mapper = $this->mapperFor($source);
        [$raw, $parser] = $this->parserFor($input);

        $rawRows = $parser->parse($raw, $mapper->expectedHeaders());

        $actions = [];
        foreach ($rawRows as $i => $rawRow) {
            $rowNumber = $i + 2;  // header is row 1
            $mapped = $mapper->map($rawRow);
            $action = $this->intake->preview($mapped);
            $actions[] = new ImportAction(
                action: $action->action,
                mappedPayload: $action->mappedPayload,
                existingStudentId: $action->existingStudentId,
                reason: $action->reason,
                rowNumber: $rowNumber,
            );
        }
        return new ImportPreview($source, $actions);
    }

    private function mapperFor(string $source): SourceMapper
    {
        return match ($source) {
            'sonam'         => new SonamMapper(),
            'nikhil'        => new NikhilMapper(),
            'sumit-website' => new SumitWebsiteMapper(),
            'canonical'     => new CanonicalMapper(),
            default => throw new InvalidArgumentException("Unknown source: {$source}"),
        };
    }

    /** @return array{0: string, 1: Parser} */
    private function parserFor(string|UploadedFile $input): array
    {
        if (is_string($input)) {
            return [$input, new TsvParser()];
        }
        $ext = strtolower($input->getClientOriginalExtension());
        $bytes = file_get_contents($input->getRealPath());
        return match ($ext) {
            'csv', 'tsv' => [$bytes, $ext === 'tsv' ? new TsvParser() : new CsvParser()],
            'xlsx'       => [$bytes, new XlsxParser()],
            default      => throw new InvalidArgumentException("Unsupported file type: {$ext}"),
        };
    }
}
```

- [ ] **Step 5: Run tests to verify pass**

Run: `/opt/alt/php84/usr/bin/php artisan test --filter=LeadImportServicePreviewTest`

Expected: 5 tests PASS.

- [ ] **Step 6: Commit**

```bash
git add app/Services/LeadImport/ImportPreview.php app/Services/LeadImport/LeadImportService.php tests/Unit/LeadImport/LeadImportServicePreviewTest.php
git commit -m "feat(lead-import): LeadImportService::preview() orchestrator"
```

---

## Task 11: `LeadImportService::commit()` with transactional ingest

**Goal:** Take an `ImportPreview`, loop non-rejected rows through `LeadIntakeService::ingest()` inside a single transaction, write a `LeadImportBatch` row, write rejections CSV to storage.

**Files:**
- Modify: `app/Services/LeadImport/LeadImportService.php`
- Create: `tests/Feature/LeadImport/LeadImportCommitTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/LeadImport/LeadImportCommitTest.php`:

```php
<?php

namespace Tests\Feature\LeadImport;

use App\Models\LeadImportBatch;
use App\Models\Student;
use App\Models\User;
use App\Services\LeadImport\LeadImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class LeadImportCommitTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        Storage::fake('local');
    }

    public function test_commits_new_rows_and_writes_batch_row(): void
    {
        $admin = User::role('admin')->firstOrFail();
        $svc = app(LeadImportService::class);

        $tsv = "Date\tPh no\tCourse\tRank\tD/OD\tenquiry\tconnected to.\n"
             . "2026-04-22\t9000000800\tBCA\t1234\tD\tFees\tNisha\n"
             . "2026-04-22\t9000000801\tBBA\t5678\tOD\t\tNisha\n";
        $preview = $svc->preview('sonam', $tsv);
        $batch = $svc->commit($preview, $admin);

        $this->assertInstanceOf(LeadImportBatch::class, $batch);
        $this->assertSame('sonam', $batch->source);
        $this->assertSame(2, $batch->row_count);
        $this->assertSame(2, $batch->created_count);
        $this->assertSame(0, $batch->rejected_count);
        $this->assertNull($batch->rejections_csv_path);  // no rejections → no CSV written
        $this->assertDatabaseHas('students', ['phone' => '9000000800']);
        $this->assertDatabaseHas('students', ['phone' => '9000000801']);
    }

    public function test_rejected_rows_are_written_to_csv(): void
    {
        $admin = User::role('admin')->firstOrFail();
        $svc = app(LeadImportService::class);

        $tsv = "Date\tPh no\tCourse\tRank\tD/OD\tenquiry\tconnected to.\n"
             . "2026-04-22\t\tBCA\t\t\t\t\n"
             . "2026-04-22\t9000000900\tBBA\t\t\t\t\n";
        $preview = $svc->preview('sonam', $tsv);
        $batch = $svc->commit($preview, $admin);

        $this->assertSame(1, $batch->rejected_count);
        $this->assertSame(1, $batch->created_count);
        $this->assertNotNull($batch->rejections_csv_path);
        Storage::disk('local')->assertExists($batch->rejections_csv_path);

        $csv = Storage::disk('local')->get($batch->rejections_csv_path);
        $this->assertStringContainsString('row_number,reason', $csv);
        $this->assertStringContainsString('phone missing or unparseable', $csv);
    }

    public function test_exception_during_commit_rolls_back(): void
    {
        $admin = User::role('admin')->firstOrFail();
        $svc = app(LeadImportService::class);

        // Action 1 would insert successfully; action 2 is a MERGE pointing to a
        // non-existent existing_student_id, which makes executeMerge() throw
        // via Student::findOrFail(). Deterministic trigger for rollback.
        $preview = new \App\Services\LeadImport\ImportPreview('sonam', [
            \App\Services\LeadImport\ImportAction::create(
                ['phone' => '9000001000', 'course' => 'BCA', 'stage' => 'Lead Captured'],
                2,
            ),
            \App\Services\LeadImport\ImportAction::merge(
                ['phone' => '9000001001', 'course' => 'BCA', 'stage' => 'Lead Captured'],
                existingId: 999999,
                rowNumber: 3,
            ),
        ]);

        $before = Student::count();
        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
        try {
            $svc->commit($preview, $admin);
        } finally {
            $this->assertSame($before, Student::count(), 'rollback should restore student count');
            $this->assertSame(0, LeadImportBatch::count(), 'batch row should not persist on rollback');
        }
    }
}
```

Note on the rollback test: The second `ImportAction` manually passes `'phone' => null` which will hit NOT NULL / unique constraint depending on schema. If schema allows null phone, change the assertion payload to trigger a real DB error (e.g., a duplicate phone that's not in preview but exists from a seed). If the schema disallows null phone, this will throw and the test passes.

- [ ] **Step 2: Run test to verify it fails**

Run: `/opt/alt/php84/usr/bin/php artisan test --filter=LeadImportCommitTest`

Expected: FAIL with "Method commit does not exist".

- [ ] **Step 3: Add `commit()` to `LeadImportService`**

Append to `app/Services/LeadImport/LeadImportService.php`:

```php
use App\Models\LeadImportBatch;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

// …inside LeadImportService class…

public function commit(ImportPreview $preview, User $user): LeadImportBatch
{
    return DB::transaction(function () use ($preview, $user) {
        $counts = ['create' => 0, 'merge' => 0, 'flag' => 0, 'reject' => 0];

        foreach ($preview->actions as $action) {
            if ($action->action === ImportAction::REJECT) {
                $counts['reject']++;
                continue;
            }
            $this->intake->ingest($action->mappedPayload);
            $counts[$action->action]++;
        }

        $rejections = $preview->byAction(ImportAction::REJECT);
        $rejectionPath = null;
        if (!empty($rejections)) {
            $rejectionPath = 'lead-imports/'.Str::uuid()->toString().'.csv';
            Storage::disk('local')->put($rejectionPath, $this->rejectionsToCsv($rejections));
        }

        return LeadImportBatch::create([
            'user_id' => $user->id,
            'source' => $preview->source,
            'row_count' => $preview->rowCount(),
            'created_count' => $counts['create'],
            'merged_count' => $counts['merge'],
            'flagged_count' => $counts['flag'],
            'rejected_count' => $counts['reject'],
            'rejections_csv_path' => $rejectionPath,
        ]);
    });
}

/** @param array<int, ImportAction> $rejections */
private function rejectionsToCsv(array $rejections): string
{
    $handle = fopen('php://temp', 'r+');
    fputcsv($handle, ['row_number', 'reason', 'phone', 'course', 'raw_payload_json']);
    foreach ($rejections as $r) {
        fputcsv($handle, [
            $r->rowNumber,
            $r->reason ?? 'unknown',
            $r->mappedPayload['phone'] ?? '',
            $r->mappedPayload['course'] ?? '',
            json_encode($r->mappedPayload, JSON_UNESCAPED_SLASHES),
        ]);
    }
    rewind($handle);
    $csv = stream_get_contents($handle);
    fclose($handle);
    return $csv;
}
```

- [ ] **Step 4: Run tests to verify pass**

Run: `/opt/alt/php84/usr/bin/php artisan test --filter=LeadImportCommitTest`

Expected: 3 tests PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Services/LeadImport/LeadImportService.php tests/Feature/LeadImport/LeadImportCommitTest.php
git commit -m "feat(lead-import): commit() with transaction + rejections CSV"
```

---

## Task 12: Signed download route for rejections CSV

**Goal:** One-shot download endpoint: admin clicks link, gets CSV, server deletes the file and clears `rejections_csv_path`.

**Files:**
- Create: `app/Http/Controllers/LeadImportRejectionsController.php`
- Modify: `routes/web.php`
- Create: `tests/Feature/LeadImport/LeadImportRejectionsDownloadTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/LeadImport/LeadImportRejectionsDownloadTest.php`:

```php
<?php

namespace Tests\Feature\LeadImport;

use App\Models\LeadImportBatch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class LeadImportRejectionsDownloadTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        Storage::fake('local');
    }

    public function test_admin_can_download_rejections_then_file_is_cleared(): void
    {
        $admin = User::role('admin')->firstOrFail();
        Storage::disk('local')->put('lead-imports/test.csv', "row_number,reason\n2,bad phone\n");

        $batch = LeadImportBatch::create([
            'user_id' => $admin->id,
            'source' => 'sonam', 'row_count' => 1, 'created_count' => 0,
            'merged_count' => 0, 'flagged_count' => 0, 'rejected_count' => 1,
            'rejections_csv_path' => 'lead-imports/test.csv',
        ]);

        $url = URL::signedRoute('lead-imports.rejections', ['batch' => $batch->id]);

        $res = $this->actingAs($admin)->get($url);
        $res->assertOk();
        $res->assertHeader('content-type', 'text/csv; charset=UTF-8');
        $this->assertStringContainsString('bad phone', $res->streamedContent());

        $batch->refresh();
        $this->assertNull($batch->rejections_csv_path);
        Storage::disk('local')->assertMissing('lead-imports/test.csv');
    }

    public function test_non_admin_gets_403(): void
    {
        $admin = User::role('admin')->firstOrFail();
        $counsellor = User::whereHas('roles', fn ($q) => $q->where('name', 'counsellor'))->firstOrFail();
        $batch = LeadImportBatch::create([
            'user_id' => $admin->id,
            'source' => 'sonam', 'row_count' => 0, 'created_count' => 0,
            'merged_count' => 0, 'flagged_count' => 0, 'rejected_count' => 0,
        ]);
        $url = URL::signedRoute('lead-imports.rejections', ['batch' => $batch->id]);
        $this->actingAs($counsellor)->get($url)->assertForbidden();
    }

    public function test_expired_or_missing_file_returns_410(): void
    {
        $admin = User::role('admin')->firstOrFail();
        $batch = LeadImportBatch::create([
            'user_id' => $admin->id,
            'source' => 'sonam', 'row_count' => 0, 'created_count' => 0,
            'merged_count' => 0, 'flagged_count' => 0, 'rejected_count' => 0,
            'rejections_csv_path' => null,
        ]);
        $url = URL::signedRoute('lead-imports.rejections', ['batch' => $batch->id]);
        $this->actingAs($admin)->get($url)->assertStatus(410);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `/opt/alt/php84/usr/bin/php artisan test --filter=LeadImportRejectionsDownloadTest`

Expected: FAIL ("Route [lead-imports.rejections] not defined").

- [ ] **Step 3: Create controller**

Create `app/Http/Controllers/LeadImportRejectionsController.php`:

```php
<?php

namespace App\Http\Controllers;

use App\Models\LeadImportBatch;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class LeadImportRejectionsController extends Controller
{
    public function show(Request $request, LeadImportBatch $batch): StreamedResponse
    {
        abort_unless($request->user()?->hasRole('admin'), 403);

        $path = $batch->rejections_csv_path;
        if ($path === null || !Storage::disk('local')->exists($path)) {
            abort(410, 'Rejections CSV no longer available.');
        }

        $filename = 'rejections-'.$batch->id.'-'.$batch->created_at->format('Y-m-d-His').'.csv';
        $contents = Storage::disk('local')->get($path);

        Storage::disk('local')->delete($path);
        $batch->rejections_csv_path = null;
        $batch->save();

        return response()->streamDownload(
            fn () => print($contents),
            $filename,
            ['Content-Type' => 'text/csv; charset=UTF-8'],
        );
    }
}
```

- [ ] **Step 4: Register the signed route**

Edit `routes/web.php`. Add inside the authenticated group (or create one if missing):

```php
use App\Http\Controllers\LeadImportRejectionsController;

Route::middleware(['auth', 'signed'])
    ->get('/lead-imports/{batch}/rejections', [LeadImportRejectionsController::class, 'show'])
    ->name('lead-imports.rejections');
```

If `routes/web.php` does not already bootstrap `Route::middleware('auth')`, wrap the new route in both middlewares individually: `middleware(['auth', 'signed'])`.

- [ ] **Step 5: Run tests to verify pass**

Run: `/opt/alt/php84/usr/bin/php artisan test --filter=LeadImportRejectionsDownloadTest`

Expected: 3 tests PASS.

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/LeadImportRejectionsController.php routes/web.php tests/Feature/LeadImport/LeadImportRejectionsDownloadTest.php
git commit -m "feat(lead-import): signed one-shot rejections CSV download"
```

---

## Task 13: Filament `LeadImport` page

**Goal:** Admin-only Livewire page at `/admin/lead-import` with source → input → preview → confirm → done flow.

**Files:**
- Create: `app/Filament/Pages/LeadImport.php`
- Create: `resources/views/filament/pages/lead-import.blade.php`
- Create: `tests/Feature/LeadImport/LeadImportPageTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/LeadImport/LeadImportPageTest.php`:

```php
<?php

namespace Tests\Feature\LeadImport;

use App\Models\User;
use App\Filament\Pages\LeadImport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class LeadImportPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    public function test_admin_can_access_page(): void
    {
        $admin = User::role('admin')->firstOrFail();
        $this->actingAs($admin)->get('/admin/lead-import')->assertOk();
    }

    public function test_non_admin_cannot_access_page(): void
    {
        $counsellor = User::whereHas('roles', fn ($q) => $q->where('name', 'counsellor'))->firstOrFail();
        $this->actingAs($counsellor)->get('/admin/lead-import')->assertForbidden();
    }

    public function test_paste_preview_and_commit_creates_students_and_batch(): void
    {
        $admin = User::role('admin')->firstOrFail();
        $this->actingAs($admin);

        $tsv = "Date\tPh no\tCourse\tRank\tD/OD\tenquiry\tconnected to.\n"
             . "2026-04-22\t9000001100\tBCA\t1234\tD\t\tNisha\n";

        Livewire::test(LeadImport::class)
            ->set('source', 'sonam')
            ->set('paste', $tsv)
            ->call('runPreview')
            ->assertSet('step', 'preview')
            ->assertSet('previewCreateCount', 1)
            ->call('commitPreview')
            ->assertSet('step', 'done')
            ->assertSet('committedCreateCount', 1);

        $this->assertDatabaseHas('students', ['phone' => '9000001100']);
        $this->assertDatabaseHas('lead_import_batches', ['source' => 'sonam', 'created_count' => 1]);
    }

    public function test_parse_error_surfaces_in_ui_and_does_not_advance_step(): void
    {
        $admin = User::role('admin')->firstOrFail();
        $this->actingAs($admin);

        Livewire::test(LeadImport::class)
            ->set('source', 'sonam')
            ->set('paste', "Wrong\tHeaders\n1\t2\n")
            ->call('runPreview')
            ->assertSet('step', 'input')
            ->assertSet('parseError', fn ($v) => is_string($v) && str_contains($v, 'Missing required column'));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `/opt/alt/php84/usr/bin/php artisan test --filter=LeadImportPageTest`

Expected: FAIL with "Class App\Filament\Pages\LeadImport not found".

- [ ] **Step 3: Create the Filament page**

Create `app/Filament/Pages/LeadImport.php`:

```php
<?php

namespace App\Filament\Pages;

use App\Services\LeadImport\ImportAction;
use App\Services\LeadImport\ImportPreview;
use App\Services\LeadImport\LeadImportService;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Http\UploadedFile;

class LeadImport extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-arrow-up-tray';
    protected static ?string $navigationLabel = 'Lead import';
    protected static ?string $navigationGroup = 'Reports';
    protected static ?string $slug = 'lead-import';
    protected static ?string $title = 'Bulk lead import';
    protected static string $view = 'filament.pages.lead-import';

    public string $step = 'input';   // input | preview | done
    public string $source = 'sonam';
    public string $paste = '';
    /** @var UploadedFile|null */
    public $upload = null;
    public ?string $parseError = null;

    // Preview state (serialized across requests by Livewire)
    public int $previewCreateCount = 0;
    public int $previewMergeCount = 0;
    public int $previewFlagCount = 0;
    public int $previewRejectCount = 0;
    public array $previewRows = [];          // [{action, row_number, phone, reason, ...}]

    // Done state
    public ?int $committedBatchId = null;
    public int $committedCreateCount = 0;
    public int $committedMergeCount = 0;
    public int $committedFlagCount = 0;
    public int $committedRejectCount = 0;
    public ?string $rejectionsUrl = null;

    public static function canAccess(): bool
    {
        return auth()->user()?->hasRole('admin') ?? false;
    }

    public function runPreview(LeadImportService $svc): void
    {
        $this->parseError = null;
        $input = $this->upload instanceof UploadedFile ? $this->upload : $this->paste;
        if ((is_string($input) && trim($input) === '') && !$this->upload) {
            $this->parseError = 'Paste rows or upload a file before previewing.';
            return;
        }
        try {
            $preview = $svc->preview($this->source, $input);
        } catch (\Throwable $e) {
            $this->parseError = $e->getMessage();
            return;
        }
        $this->storePreview($preview);
        $this->step = 'preview';
    }

    public function backToInput(): void
    {
        $this->step = 'input';
    }

    public function commitPreview(LeadImportService $svc): void
    {
        $preview = $this->rehydratePreview();
        $batch = $svc->commit($preview, auth()->user());

        $this->committedBatchId     = $batch->id;
        $this->committedCreateCount = $batch->created_count;
        $this->committedMergeCount  = $batch->merged_count;
        $this->committedFlagCount   = $batch->flagged_count;
        $this->committedRejectCount = $batch->rejected_count;
        $this->rejectionsUrl = $batch->rejections_csv_path
            ? \Illuminate\Support\Facades\URL::signedRoute('lead-imports.rejections', ['batch' => $batch->id])
            : null;
        $this->step = 'done';

        Notification::make()->success()->title("Imported batch #{$batch->id}")->send();
    }

    public function resetForm(): void
    {
        $this->step = 'input';
        $this->paste = '';
        $this->upload = null;
        $this->parseError = null;
        $this->previewRows = [];
        $this->previewCreateCount = 0;
        $this->previewMergeCount = 0;
        $this->previewFlagCount = 0;
        $this->previewRejectCount = 0;
    }

    private function storePreview(ImportPreview $preview): void
    {
        $this->previewCreateCount = $preview->countBy(ImportAction::CREATE);
        $this->previewMergeCount  = $preview->countBy(ImportAction::MERGE);
        $this->previewFlagCount   = $preview->countBy(ImportAction::FLAG);
        $this->previewRejectCount = $preview->countBy(ImportAction::REJECT);
        $this->previewRows = array_map(fn (ImportAction $a) => [
            'action' => $a->action,
            'row_number' => $a->rowNumber,
            'phone' => $a->mappedPayload['phone'] ?? null,
            'course' => $a->mappedPayload['course'] ?? null,
            'name' => $a->mappedPayload['name'] ?? null,
            'reason' => $a->reason,
            'existing_student_id' => $a->existingStudentId,
            'mapped' => $a->mappedPayload,
        ], $preview->actions);
    }

    private function rehydratePreview(): ImportPreview
    {
        $actions = [];
        foreach ($this->previewRows as $row) {
            $actions[] = new ImportAction(
                action: $row['action'],
                mappedPayload: $row['mapped'],
                existingStudentId: $row['existing_student_id'] ?? null,
                reason: $row['reason'] ?? null,
                rowNumber: $row['row_number'] ?? null,
            );
        }
        return new ImportPreview($this->source, $actions);
    }
}
```

- [ ] **Step 4: Create the Blade view**

Create `resources/views/filament/pages/lead-import.blade.php`:

```blade
<x-filament-panels::page>
    @if ($step === 'input')
        <div class="space-y-6">
            <div>
                <label class="font-semibold">Source</label>
                <div class="mt-2 flex gap-4">
                    @foreach (['sonam' => 'Sonam', 'nikhil' => 'Nikhil', 'sumit-website' => 'Sumit — Website', 'canonical' => 'Other (canonical)'] as $val => $label)
                        <label class="flex items-center gap-2">
                            <input type="radio" wire:model.live="source" value="{{ $val }}">
                            <span>{{ $label }}</span>
                        </label>
                    @endforeach
                </div>
                <div class="mt-2 text-sm">
                    <a class="text-primary-600 underline" href="{{ asset('templates/lead-import-'.$source.'.csv') }}" download>Download {{ $source }} template</a>
                </div>
            </div>

            <div>
                <label class="font-semibold">Paste TSV from Google Sheets</label>
                <textarea wire:model.defer="paste" rows="10" class="mt-2 w-full rounded border p-2 font-mono text-xs"></textarea>
            </div>

            <div>
                <label class="font-semibold">Or upload CSV / XLSX</label>
                <input type="file" wire:model="upload" accept=".csv,.tsv,.xlsx" class="mt-2 block">
            </div>

            @if ($parseError)
                <div class="rounded bg-danger-50 p-3 text-danger-700">{{ $parseError }}</div>
            @endif

            <x-filament::button wire:click="runPreview">Preview</x-filament::button>
        </div>
    @elseif ($step === 'preview')
        <div class="space-y-6">
            <div class="grid grid-cols-4 gap-4">
                <div class="rounded bg-success-50 p-3"><div class="text-xs">Create</div><div class="text-2xl">{{ $previewCreateCount }}</div></div>
                <div class="rounded bg-warning-50 p-3"><div class="text-xs">Merge</div><div class="text-2xl">{{ $previewMergeCount }}</div></div>
                <div class="rounded bg-primary-50 p-3"><div class="text-xs">Flag</div><div class="text-2xl">{{ $previewFlagCount }}</div></div>
                <div class="rounded bg-danger-50 p-3"><div class="text-xs">Reject</div><div class="text-2xl">{{ $previewRejectCount }}</div></div>
            </div>

            <details open>
                <summary class="cursor-pointer font-semibold">Rows ({{ count($previewRows) }})</summary>
                <table class="mt-3 w-full text-sm">
                    <thead><tr class="border-b"><th class="p-1 text-left">#</th><th class="p-1 text-left">Action</th><th class="p-1 text-left">Phone</th><th class="p-1 text-left">Course</th><th class="p-1 text-left">Name</th><th class="p-1 text-left">Reason</th></tr></thead>
                    <tbody>
                    @foreach (array_slice($previewRows, 0, 200) as $row)
                        <tr class="border-b">
                            <td class="p-1">{{ $row['row_number'] }}</td>
                            <td class="p-1 font-mono">{{ $row['action'] }}</td>
                            <td class="p-1">{{ $row['phone'] }}</td>
                            <td class="p-1">{{ $row['course'] }}</td>
                            <td class="p-1">{{ $row['name'] }}</td>
                            <td class="p-1 text-xs text-gray-500">{{ $row['reason'] }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
                @if (count($previewRows) > 200)
                    <div class="mt-2 text-xs text-gray-500">Showing first 200 of {{ count($previewRows) }} rows.</div>
                @endif
            </details>

            <div class="flex gap-2">
                <x-filament::button wire:click="commitPreview">Confirm import</x-filament::button>
                <x-filament::button color="gray" wire:click="backToInput">Back</x-filament::button>
            </div>
        </div>
    @elseif ($step === 'done')
        <div class="space-y-6">
            <div class="rounded bg-success-50 p-4">
                <div class="text-lg font-semibold">Batch #{{ $committedBatchId }} committed</div>
                <ul class="mt-2 text-sm">
                    <li>Created: {{ $committedCreateCount }}</li>
                    <li>Merged: {{ $committedMergeCount }}</li>
                    <li>Flagged: {{ $committedFlagCount }}</li>
                    <li>Rejected: {{ $committedRejectCount }}</li>
                </ul>
            </div>
            @if ($rejectionsUrl)
                <a class="text-primary-600 underline" href="{{ $rejectionsUrl }}" download>Download rejections CSV</a>
                <div class="text-xs text-gray-500">This link is one-shot — the CSV is deleted after you download it.</div>
            @endif
            <x-filament::button wire:click="resetForm">Import another batch</x-filament::button>
        </div>
    @endif
</x-filament-panels::page>
```

- [ ] **Step 5: Run tests to verify pass**

Run: `/opt/alt/php84/usr/bin/php artisan test --filter=LeadImportPageTest`

Expected: 4 tests PASS.

- [ ] **Step 6: Commit**

```bash
git add app/Filament/Pages/LeadImport.php resources/views/filament/pages/lead-import.blade.php tests/Feature/LeadImport/LeadImportPageTest.php
git commit -m "feat(lead-import): Filament admin page with preview-then-commit flow"
```

---

## Task 14: Static template CSV files

**Goal:** Four downloadable templates at `public/templates/lead-import-*.csv`. Each holds header row only.

**Files:**
- Create: `public/templates/lead-import-sonam.csv`
- Create: `public/templates/lead-import-nikhil.csv`
- Create: `public/templates/lead-import-sumit-website.csv`
- Create: `public/templates/lead-import-canonical.csv`
- Create: `tests/Feature/LeadImport/TemplatesExistTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/LeadImport/TemplatesExistTest.php`:

```php
<?php

namespace Tests\Feature\LeadImport;

use App\Services\LeadImport\Mappers\CanonicalMapper;
use App\Services\LeadImport\Mappers\NikhilMapper;
use App\Services\LeadImport\Mappers\SonamMapper;
use App\Services\LeadImport\Mappers\SumitWebsiteMapper;
use Tests\TestCase;

class TemplatesExistTest extends TestCase
{
    /** @dataProvider provideSources */
    public function test_template_exists_and_header_matches_mapper(string $slug, string $mapperClass): void
    {
        $path = public_path("templates/lead-import-{$slug}.csv");
        $this->assertFileExists($path);

        $headerLine = rtrim((string) file($path)[0], "\r\n");
        $fields = str_getcsv($headerLine);
        $expected = (new $mapperClass())->expectedHeaders();

        $this->assertSame($expected, $fields, "template header for {$slug} must match mapper::expectedHeaders()");
    }

    public static function provideSources(): array
    {
        return [
            'sonam'         => ['sonam',         SonamMapper::class],
            'nikhil'        => ['nikhil',        NikhilMapper::class],
            'sumit-website' => ['sumit-website', SumitWebsiteMapper::class],
            'canonical'     => ['canonical',     CanonicalMapper::class],
        ];
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `/opt/alt/php84/usr/bin/php artisan test --filter=TemplatesExistTest`

Expected: 4 FAILs ("File does not exist").

- [ ] **Step 3: Write each template**

Create `public/templates/lead-import-sonam.csv` (one line, exact):
```
Date,Ph no,Course,Rank,D/OD,enquiry,connected to.
```

Create `public/templates/lead-import-nikhil.csv`:
```
Name,Phone,Course,Rank,State,Referrer,Remarks
```

Create `public/templates/lead-import-sumit-website.csv`:
```
Timestamp,Name,Email,Phone,Course,Rank,State,Message
```

Create `public/templates/lead-import-canonical.csv`:
```
phone,name,course,rank,state,referrer_name,remarks,source
```

- [ ] **Step 4: Run tests to verify pass**

Run: `/opt/alt/php84/usr/bin/php artisan test --filter=TemplatesExistTest`

Expected: 4 tests PASS.

- [ ] **Step 5: Commit**

```bash
git add public/templates/lead-import-*.csv tests/Feature/LeadImport/TemplatesExistTest.php
git commit -m "feat(lead-import): downloadable template CSVs"
```

---

## Task 15: End-to-end dedup-demote flow test

**Goal:** One high-confidence feature test covering the most complex flow: existing Sumit row gets demoted by a Sonam-sourced import, payments re-parent, DB stays consistent.

**Files:**
- Create: `tests/Feature/LeadImport/LeadImportE2EDedupTest.php`

- [ ] **Step 1: Write the test**

Create `tests/Feature/LeadImport/LeadImportE2EDedupTest.php`:

```php
<?php

namespace Tests\Feature\LeadImport;

use App\Filament\Pages\LeadImport;
use App\Models\Payment;
use App\Models\Student;
use App\Models\User;
use App\Services\LeadIntakeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class LeadImportE2EDedupTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    public function test_sonam_import_demotes_existing_sumit_row_and_reparents_payments(): void
    {
        // Existing Sumit-owned student with a payment
        $intake = app(LeadIntakeService::class);
        $existing = $intake->ingest(['phone' => '9000002000', 'course' => 'BCA', 'owner_name' => 'Sumit'])['student'];
        Payment::factory()->create(['student_id' => $existing->id, 'amount' => 100]);

        $admin = User::role('admin')->firstOrFail();
        $tsv = "Date\tPh no\tCourse\tRank\tD/OD\tenquiry\tconnected to.\n"
             . "2026-04-22\t9000002000\tBCA\t1234\tD\t\t\n";

        $this->actingAs($admin);
        Livewire::test(LeadImport::class)
            ->set('source', 'sonam')
            ->set('paste', $tsv)
            ->call('runPreview')
            ->assertSet('previewMergeCount', 1)
            ->call('commitPreview')
            ->assertSet('committedMergeCount', 1);

        // Old student gone; new student exists; payment re-parented
        $this->assertDatabaseMissing('students', ['id' => $existing->id]);
        $new = Student::where('phone', '9000002000')->first();
        $this->assertNotNull($new);
        $this->assertSame(User::whereRaw('LOWER(name) = ?', ['sonam'])->firstOrFail()->id, $new->owner_id);
        $this->assertSame(1, Payment::where('student_id', $new->id)->count());
    }
}
```

- [ ] **Step 2: Run the test — it should already pass if Tasks 1–13 landed correctly**

Run: `/opt/alt/php84/usr/bin/php artisan test --filter=LeadImportE2EDedupTest`

Expected: PASS. If it fails, do NOT hack around it — the failure is surfacing a real integration bug in one of the earlier tasks. Debug and fix the root cause there.

- [ ] **Step 3: Commit**

```bash
git add tests/Feature/LeadImport/LeadImportE2EDedupTest.php
git commit -m "test(lead-import): e2e dedup-demote flow with payment re-parent"
```

---

## Task 16: Final verification + docs + n8n pause note

**Goal:** Run the full test suite, confirm no regressions, drop a small operator doc.

**Files:**
- Create: `docs/LEAD_IMPORT.md`

- [ ] **Step 1: Full test suite**

Run: `/opt/alt/php84/usr/bin/php artisan test`

Expected: ALL PASS. PHP 8.5 deprecation lines (`DEPR`) on every test are expected and NOT failures — see `project_davya-crm_php85_deprecations` memory.

- [ ] **Step 2: Write operator doc**

Create `docs/LEAD_IMPORT.md`:

```markdown
# Manual Bulk Lead Import

Admin-only screen at `/admin/lead-import`. Replaces the three n8n Sheet-trigger workflows while they're paused.

## How to use

1. **Pick source** — Sonam / Nikhil / Sumit-website / Other (canonical).
2. **Paste rows** — copy a range from the Google Sheet (including the header row) into the textarea. OR upload a CSV/XLSX with the matching columns. Download the template if unsure.
3. **Preview** — click **Preview**. You'll see counts for create / merge / flag / reject, plus a row-level table. Nothing is written yet.
4. **Confirm import** — click it. Rows are ingested inside one transaction. If anything throws, everything rolls back.
5. **Done** — download the rejections CSV (one-shot link; clears after download).

## Dedup rules

Same as the existing n8n pipeline. `Sonam > Nikhil > Sumit` — higher tier wins, demotes the loser, re-parents payments/notes. Head-vs-head conflicts (Sonam vs Nikhil) land on **Reports → Duplicate review** for admin resolution.

## Pausing and resuming n8n Sheet-trigger workflows

The three Sheet-trigger workflows were deactivated on 2026-04-22 when this page went live:

- `lead-sumit-website-sheet` — `7cqS00mq6r2yGJDG`
- `lead-nikhil-sheet` — `v3b8K2UC08QY4V3H`
- `lead-sonam-sheet` — `P1e55kFMiE7AYlmN`

`/api/leads` webhook and the legacy `Davya Lead Capture` Forms workflow stay **active**.

To resume: open n8n UI, toggle each workflow back on. No code change required on the CRM side — manual and automated paths happily coexist.
```

- [ ] **Step 3: Pause n8n workflows (operational, not code)**

Open n8n UI at `srv1117424.hstgr.cloud`. Deactivate these three workflows:
- `7cqS00mq6r2yGJDG` (lead-sumit-website-sheet)
- `v3b8K2UC08QY4V3H` (lead-nikhil-sheet)
- `P1e55kFMiE7AYlmN` (lead-sonam-sheet)

Leave `/api/leads` webhook workflow and `Davya Lead Capture` Forms workflow active.

Confirm each shows "Inactive" in the UI.

- [ ] **Step 4: Run the full suite one more time and commit docs**

Run: `/opt/alt/php84/usr/bin/php artisan test`

Expected: ALL PASS.

```bash
git add docs/LEAD_IMPORT.md
git commit -m "docs(lead-import): operator guide for manual bulk import"
```

- [ ] **Step 5: Deploy**

Follow `docs/DEPLOY.md` — SSH to Hostinger, `git pull`, run the migration (`php artisan migrate --force`), clear caches. Verify `/admin/lead-import` loads for admin, returns 403 for counsellor.

- [ ] **Step 6: Smoke test in prod**

As admin on `https://davyas.ipu.co.in/admin/lead-import`:
1. Pick "Sonam", paste 5 rows from her live Google Sheet.
2. Verify preview counts look sane.
3. Click Confirm.
4. Spot-check the created students in `/admin/students`.
5. If there were rejections, download the CSV and open it.

If anything is off, roll back with `git revert` on the commit range and re-deploy. Do NOT attempt to patch in prod.

---

## Summary

16 tasks, ~60 commits, covers: `LeadIntakeService` refactor with parity test → 4 source mappers → 3 parsers → `LeadImportBatch` model → preview+commit service → signed CSV download → Filament page + view → static templates → e2e test → docs + n8n pause.

Every DB write goes through `LeadIntakeService::ingest()` unchanged in behavior — the UI only gives admin a preview and a single confirm button. Rollback on any commit: `git revert <sha>` + redeploy; data integrity protected by the per-batch transaction.
