# Finance Notes via Slack/n8n — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Capture free-form finance notes from Slack — `note <message>` → n8n → `POST /api/finance/notes` → stored and shown under Filament Finance › Notes.

**Architecture:** A deliberate mirror of the existing expense capture path (`FinanceExpenseController` → `expenses` → `ExpenseResource`), minus amount and ledger routing. New `notes` table, token-protected API endpoint with `slack_message_id` dedup, and a Filament resource gated by a Spatie-role policy.

**Tech Stack:** Laravel 11, Filament 3, Spatie Permission, PHPUnit, MariaDB/SQLite (tests).

## Global Constraints

- PHP/Laravel: match existing codebase (Laravel 11). No new dependencies.
- API endpoint lives inside the existing `finance` route group — reuses `VerifyFinanceToken` (header `X-Finance-Token`, value `config('finance.capture_token')`) + `throttle:60,1`. Do not add new middleware.
- Notes never touch `LedgerRoutingService` and never create `LedgerEntry` rows.
- `display_id` convention (verbatim from `Expense`): `"D{id}"` when `slack_message_id` is null, `"#{id}"` otherwise.
- Role gating (verbatim from `ExpensePolicy`): view/create/update → `hasAnyRole(['admin','finance'])`; delete → `isSuperAdmin()`.
- Filament resource nav group is `'Finance'`.
- Seeded test users: `sumit@davya.local` (admin), `sonam@davya.local` (head, no finance role). Tests call `$this->seed()` and unblock `must_change_password`.
- Commit after each task. Branch: `feat/finance-notes-slack` (already checked out).

---

### Task 1: Notes data layer (migration + model + factory)

**Files:**
- Create: `database/migrations/2026_06_19_000000_create_notes_table.php`
- Create: `app/Models/Note.php`
- Create: `database/factories/NoteFactory.php`
- Test: `tests/Unit/NoteModelTest.php`

**Interfaces:**
- Consumes: nothing.
- Produces: `App\Models\Note` (mass-assignable, `$guarded = []`); columns `id, body, slack_message_id, raw_input, noted_at, timestamps`; cast `noted_at => datetime`; accessor `display_id` (`"D{id}"`/`"#{id}"`). `Note::factory()`.

- [ ] **Step 1: Write the failing test**

```php
<?php
namespace Tests\Unit;

use App\Models\Note;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NoteModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_note_casts_noted_at_as_datetime(): void
    {
        $n = Note::create([
            'body' => 'Paid electrician advance',
            'noted_at' => '2026-06-19 10:00:00',
            'slack_message_id' => 'N2.1.1',
            'raw_input' => 'note Paid electrician advance',
        ]);
        $fresh = $n->fresh();
        $this->assertSame('Paid electrician advance', $fresh->body);
        $this->assertInstanceOf(\Illuminate\Support\Carbon::class, $fresh->noted_at);
    }

    public function test_manual_note_renders_D_prefix(): void
    {
        $n = Note::create(['body' => 'manual note', 'noted_at' => now(), 'slack_message_id' => null]);
        $this->assertSame("D{$n->id}", $n->display_id, 'manual rows must use D prefix');
    }

    public function test_slack_note_renders_hash_prefix(): void
    {
        $n = Note::create(['body' => 'from slack', 'noted_at' => now(), 'slack_message_id' => '1776767527.655079']);
        $this->assertSame("#{$n->id}", $n->display_id, 'slack rows must use # prefix');
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Unit/NoteModelTest.php`
Expected: FAIL — `Class "App\Models\Note" not found` (and no `notes` table).

- [ ] **Step 3: Create the migration**

`database/migrations/2026_06_19_000000_create_notes_table.php`:

```php
<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('notes', function (Blueprint $table) {
            $table->id();
            $table->text('body');
            $table->string('slack_message_id', 50)->nullable()->unique();
            $table->text('raw_input')->nullable();
            $table->timestamp('noted_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notes');
    }
};
```

- [ ] **Step 4: Create the model**

`app/Models/Note.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Note extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected $casts = [
        'noted_at' => 'datetime',
    ];

    public function getDisplayIdAttribute(): string
    {
        return $this->slack_message_id === null ? "D{$this->id}" : "#{$this->id}";
    }
}
```

- [ ] **Step 5: Create the factory**

`database/factories/NoteFactory.php`:

```php
<?php
namespace Database\Factories;

use App\Models\Note;
use Illuminate\Database\Eloquent\Factories\Factory;

class NoteFactory extends Factory
{
    protected $model = Note::class;

    public function definition(): array
    {
        return [
            'body' => $this->faker->sentence(),
            'slack_message_id' => 'NTEST.'.$this->faker->unique()->numerify('##########.######'),
            'raw_input' => 'note '.$this->faker->sentence(),
            'noted_at' => now(),
        ];
    }
}
```

- [ ] **Step 6: Run test to verify it passes**

Run: `php artisan test tests/Unit/NoteModelTest.php`
Expected: PASS (3 tests).

- [ ] **Step 7: Commit**

```bash
git add database/migrations/2026_06_19_000000_create_notes_table.php app/Models/Note.php database/factories/NoteFactory.php tests/Unit/NoteModelTest.php
git commit -m "feat(finance): notes data layer (migration, model, factory)"
```

---

### Task 2: Notes capture API endpoint

**Files:**
- Create: `app/Http/Requests/StoreNoteRequest.php`
- Create: `app/Http/Controllers/FinanceNoteController.php`
- Modify: `routes/api.php` — add `/notes` route inside the `finance` group
- Test: `tests/Feature/NoteCaptureTest.php`

**Interfaces:**
- Consumes: `App\Models\Note` (Task 1); `VerifyFinanceToken` middleware; `config('finance.capture_token')`.
- Produces: `POST /api/finance/notes` → `201 {"id": <int>}` on success; `409 {"error":"duplicate_slack_message","existing_id":<int>}` on dup; `422` on validation; `401` on bad token.

- [ ] **Step 1: Write the failing test**

`tests/Feature/NoteCaptureTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Note;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class NoteCaptureTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'test-finance-token-abcdef0123456789';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        config(['finance.capture_token' => self::TOKEN]);
    }

    private function postPayload(array $overrides = [], ?string $token = self::TOKEN)
    {
        $payload = array_merge([
            'body' => 'Paid electrician advance, adjust next month',
            'slack_message_id' => 'N.'.uniqid(),
            'raw_input' => 'note Paid electrician advance, adjust next month',
        ], $overrides);
        $headers = $token === null ? [] : ['X-Finance-Token' => $token];
        return $this->postJson('/api/finance/notes', $payload, $headers);
    }

    public function test_happy_path_creates_note(): void
    {
        $this->postPayload()->assertCreated();
        $n = Note::first();
        $this->assertNotNull($n);
        $this->assertSame('Paid electrician advance, adjust next month', $n->body);
        $this->assertNotNull($n->slack_message_id);
    }

    public function test_missing_token_returns_401(): void
    {
        $this->postPayload([], token: null)->assertStatus(401);
    }

    public function test_missing_body_returns_422(): void
    {
        $this->postPayload(['body' => null])->assertStatus(422)->assertJsonValidationErrors('body');
    }

    public function test_missing_slack_message_id_returns_422(): void
    {
        $this->postPayload(['slack_message_id' => null])->assertStatus(422)->assertJsonValidationErrors('slack_message_id');
    }

    public function test_duplicate_slack_message_id_returns_409(): void
    {
        $first = $this->postPayload(['slack_message_id' => 'N.DUPE']);
        $first->assertCreated();
        $this->postPayload(['slack_message_id' => 'N.DUPE'])
            ->assertStatus(409)
            ->assertJson(['error' => 'duplicate_slack_message', 'existing_id' => $first->json('id')]);
    }

    public function test_slack_message_id_race_returns_409_not_500(): void
    {
        $slackId = 'N.RACE';
        $raced = false;
        DB::listen(function ($q) use (&$raced, $slackId) {
            if ($raced) return;
            if (!str_contains($q->sql, 'notes')) return;
            if (!str_starts_with(strtolower(ltrim($q->sql)), 'select')) return;
            if (!in_array($slackId, $q->bindings, true)) return;
            $raced = true;
            DB::table('notes')->insert([
                'body'             => 'raced',
                'noted_at'         => now(),
                'slack_message_id' => $slackId,
                'created_at'       => now(),
                'updated_at'       => now(),
            ]);
        });
        $resp = $this->postPayload(['slack_message_id' => $slackId]);
        $resp->assertStatus(409)->assertJson(['error' => 'duplicate_slack_message']);
        $this->assertNotNull($resp->json('existing_id'));
        $this->assertSame(1, Note::where('slack_message_id', $slackId)->count());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/NoteCaptureTest.php`
Expected: FAIL — 404 (route not defined) on all POSTs.

- [ ] **Step 3: Create the form request**

`app/Http/Requests/StoreNoteRequest.php`:

```php
<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class StoreNoteRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'body'             => ['required', 'string', 'max:4000'],
            'slack_message_id' => ['required', 'string', 'max:50'],
            'raw_input'        => ['nullable', 'string', 'max:4000'],
            'noted_at'         => ['nullable', 'date'],
        ];
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(response()->json([
            'message' => 'The given data was invalid.',
            'errors'  => $validator->errors(),
        ], 422));
    }
}
```

- [ ] **Step 4: Create the controller**

`app/Http/Controllers/FinanceNoteController.php`:

```php
<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreNoteRequest;
use App\Models\Note;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class FinanceNoteController extends Controller
{
    public function store(StoreNoteRequest $request): JsonResponse
    {
        $data = $request->validated();

        $existing = Note::where('slack_message_id', $data['slack_message_id'])->first();
        if ($existing !== null) {
            return response()->json([
                'error' => 'duplicate_slack_message',
                'existing_id' => $existing->id,
            ], 409);
        }

        try {
            $note = DB::transaction(function () use ($data) {
                return Note::create([
                    'body'             => $data['body'],
                    'slack_message_id' => $data['slack_message_id'],
                    'raw_input'        => $data['raw_input'] ?? null,
                    'noted_at'         => $data['noted_at']  ?? now(),
                ]);
            });
        } catch (QueryException $e) {
            if (($e->errorInfo[0] ?? null) === '23000') {
                $existing = Note::where('slack_message_id', $data['slack_message_id'])->first();
                if ($existing !== null) {
                    return response()->json([
                        'error'       => 'duplicate_slack_message',
                        'existing_id' => $existing->id,
                    ], 409);
                }
            }
            throw $e;
        }

        Log::info('finance.note.captured', [
            'note_id'  => $note->id,
            'slack_id' => $data['slack_message_id'],
        ]);

        return response()->json(['id' => $note->id], 201);
    }
}
```

- [ ] **Step 5: Register the route**

In `routes/api.php`, add the import near the other finance controller imports:

```php
use App\Http\Controllers\FinanceNoteController;
```

And inside the existing `Route::prefix('finance')->middleware(...)->group(...)` block, add after the `/expenses` line:

```php
        Route::post('/notes',       [FinanceNoteController::class,       'store']);
```

- [ ] **Step 6: Run test to verify it passes**

Run: `php artisan test tests/Feature/NoteCaptureTest.php`
Expected: PASS (6 tests).

- [ ] **Step 7: Commit**

```bash
git add app/Http/Requests/StoreNoteRequest.php app/Http/Controllers/FinanceNoteController.php routes/api.php tests/Feature/NoteCaptureTest.php
git commit -m "feat(finance): POST /api/finance/notes capture endpoint with dedup"
```

---

### Task 3: Notes admin (policy + Filament resource)

**Files:**
- Create: `app/Policies/NotePolicy.php`
- Create: `app/Filament/Resources/NoteResource.php`
- Create: `app/Filament/Resources/NoteResource/Pages/ListNotes.php`
- Create: `app/Filament/Resources/NoteResource/Pages/CreateNote.php`
- Create: `app/Filament/Resources/NoteResource/Pages/EditNote.php`
- Test: `tests/Feature/NoteResourceTest.php`

**Interfaces:**
- Consumes: `App\Models\Note` (Task 1); `NotePolicy` (auto-discovered by Laravel naming — no `Gate::policy()` registration).
- Produces: `NoteResource::canViewAny()` delegating to the policy; Filament pages `ListNotes`/`CreateNote`/`EditNote`.

- [ ] **Step 1: Write the failing test**

`tests/Feature/NoteResourceTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Filament\Resources\NoteResource;
use App\Filament\Resources\NoteResource\Pages\CreateNote;
use App\Filament\Resources\NoteResource\Pages\EditNote;
use App\Models\Note;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class NoteResourceTest extends TestCase
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

    public function test_manual_create_via_form_leaves_slack_id_null(): void
    {
        $this->actingAsAdmin();

        Livewire::test(CreateNote::class)
            ->fillForm(['body' => 'printer paper reminder'])
            ->call('create')
            ->assertHasNoFormErrors();

        $row = Note::latest('id')->first();
        $this->assertNotNull($row, 'note row must be created');
        $this->assertNull($row->slack_message_id, 'manual creates must leave slack_message_id NULL');
        $this->assertSame("D{$row->id}", $row->display_id);
        $this->assertSame('printer paper reminder', $row->body);
    }

    public function test_admin_can_update_note(): void
    {
        $this->actingAsAdmin();
        $n = Note::create(['body' => 'before', 'noted_at' => now(), 'slack_message_id' => null]);

        Livewire::test(EditNote::class, ['record' => $n->getRouteKey()])
            ->fillForm(['body' => 'after'])
            ->call('save')
            ->assertHasNoFormErrors();

        $n->refresh();
        $this->assertSame('after', $n->body);
    }

    public function test_can_view_any_gates_resource_at_route_level(): void
    {
        $this->seed();
        $this->assertFalse(NoteResource::canViewAny(), 'guest must not see NoteResource');

        $sumit = $this->unblock(User::where('email', 'sumit@davya.local')->firstOrFail());
        $this->actingAs($sumit);
        $this->assertTrue(NoteResource::canViewAny(), 'admin can see NoteResource');

        $sonam = $this->unblock(User::where('email', 'sonam@davya.local')->firstOrFail());
        $this->actingAs($sonam);
        $this->assertFalse(NoteResource::canViewAny(), 'head without finance role cannot see NoteResource');
    }

    public function test_admin_cannot_delete_note(): void
    {
        $this->actingAsAdmin();
        $n = Note::create(['body' => 'admin tries delete', 'noted_at' => now(), 'slack_message_id' => null]);
        $this->assertFalse(auth()->user()->can('delete', $n), 'policy must reject admin delete');
    }

    public function test_super_admin_can_delete_note(): void
    {
        $this->seed();
        \Spatie\Permission\Models\Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);
        $sumit = $this->unblock(User::where('email', 'sumit@davya.local')->firstOrFail());
        $sumit->assignRole('super_admin');
        $this->actingAs($sumit);

        $n = Note::create(['body' => 'to be deleted', 'noted_at' => now(), 'slack_message_id' => null]);
        $this->assertTrue(auth()->user()->can('delete', $n), 'policy must allow super_admin delete');
        $n->delete();
        $this->assertNull(Note::find($n->id), 'row must be gone');
    }

    public function test_slack_message_id_unique_constraint(): void
    {
        Note::create(['body' => 'a', 'noted_at' => now(), 'slack_message_id' => 'dup-1']);
        $this->expectException(QueryException::class);
        Note::create(['body' => 'b', 'noted_at' => now(), 'slack_message_id' => 'dup-1']);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/NoteResourceTest.php`
Expected: FAIL — `Class "App\Filament\Resources\NoteResource" not found`.

- [ ] **Step 3: Create the policy**

`app/Policies/NotePolicy.php`:

```php
<?php

namespace App\Policies;

use App\Models\Note;
use App\Models\User;

class NotePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole(['admin', 'finance']);
    }

    public function view(User $user, Note $note): bool
    {
        return $user->hasAnyRole(['admin', 'finance']);
    }

    public function create(User $user): bool
    {
        return $user->hasAnyRole(['admin', 'finance']);
    }

    public function update(User $user, Note $note): bool
    {
        return $user->hasAnyRole(['admin', 'finance']);
    }

    public function delete(User $user, Note $note): bool
    {
        return $user->isSuperAdmin();
    }
}
```

- [ ] **Step 4: Create the Filament resource**

`app/Filament/Resources/NoteResource.php`:

```php
<?php

namespace App\Filament\Resources;

use App\Filament\Resources\NoteResource\Pages;
use App\Models\Note;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class NoteResource extends Resource
{
    protected static ?string $model = Note::class;

    protected static ?string $navigationIcon = 'heroicon-o-pencil-square';

    protected static ?string $navigationGroup = 'Finance';

    protected static ?string $navigationLabel = 'Notes';

    protected static ?int $navigationSort = 11;

    public static function canViewAny(): bool
    {
        return auth()->user()?->can('viewAny', Note::class) ?? false;
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Textarea::make('body')
                ->required()
                ->rows(3)
                ->columnSpanFull(),
            Forms\Components\Textarea::make('raw_input')
                ->label('Raw Slack input')
                ->disabled()
                ->dehydrated(false)
                ->columnSpanFull()
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
                    ->state(fn (Note $r) => $r->slack_message_id ? 'Slack' : 'Manual')
                    ->color(fn (string $state) => $state === 'Slack' ? 'info' : 'success'),
                Tables\Columns\TextColumn::make('body')
                    ->limit(80)
                    ->searchable(),
                Tables\Columns\TextColumn::make('created_at')
                    ->since()
                    ->tooltip(fn ($record) => $record->created_at?->format('d M Y, H:i'))
                    ->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
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
            'index' => Pages\ListNotes::route('/'),
            'create' => Pages\CreateNote::route('/create'),
            'edit' => Pages\EditNote::route('/{record}/edit'),
        ];
    }
}
```

- [ ] **Step 5: Create the resource pages**

`app/Filament/Resources/NoteResource/Pages/ListNotes.php`:

```php
<?php

namespace App\Filament\Resources\NoteResource\Pages;

use App\Filament\Resources\NoteResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListNotes extends ListRecords
{
    protected static string $resource = NoteResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
```

`app/Filament/Resources/NoteResource/Pages/CreateNote.php`:

```php
<?php

namespace App\Filament\Resources\NoteResource\Pages;

use App\Filament\Resources\NoteResource;
use Filament\Resources\Pages\CreateRecord;

class CreateNote extends CreateRecord
{
    protected static string $resource = NoteResource::class;
}
```

`app/Filament/Resources/NoteResource/Pages/EditNote.php`:

```php
<?php

namespace App\Filament\Resources\NoteResource\Pages;

use App\Filament\Resources\NoteResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditNote extends EditRecord
{
    protected static string $resource = NoteResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
```

- [ ] **Step 6: Run test to verify it passes**

Run: `php artisan test tests/Feature/NoteResourceTest.php`
Expected: PASS (6 tests).

- [ ] **Step 7: Run the full notes suite + commit**

Run: `php artisan test --filter=Note`
Expected: PASS (all Note* tests green).

```bash
git add app/Policies/NotePolicy.php app/Filament/Resources/NoteResource.php app/Filament/Resources/NoteResource/Pages tests/Feature/NoteResourceTest.php
git commit -m "feat(finance): Notes Filament resource + policy under Finance group"
```

---

## Post-implementation (manual, Sumit)

- **Deploy** per the full Laravel deploy recipe (composer + `php artisan migrate` + clear/cache config, route, view). The new `notes` migration must run on prod.
- **n8n:** add a branch to the existing Slack→CRM workflow: if the Slack message text starts with `note` (case-insensitive), strip the keyword and POST `{ body, slack_message_id, raw_input }` to `{APP_URL}/api/finance/notes` with header `X-Finance-Token: <finance.capture_token>`. Use the Slack message ts/id as `slack_message_id` for idempotency. Wire an error branch (do not rely on `neverError`).
- Browser smoke: Finance › Notes visible to admin/finance; manual create works; a Slack-captured note shows `#id` with Source = Slack.
