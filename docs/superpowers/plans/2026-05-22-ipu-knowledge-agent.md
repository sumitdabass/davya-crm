# IPU Knowledge Agent Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build an internal Filament TopBar AI assistant that answers IPU admission questions grounded in live ipu.co.in source files via Groq Llama 3.3 70B tool-calling.

**Architecture:** Right-drawer Livewire chat → controller (permission gate + daily cap) → AssistantService loop (≤3 tool round-trips) → Groq via `LlmProvider`. Two filesystem tools — `search_pages` (glob+stripos over `/home/ipuc/public_html`) and `read_page` (strip PHP+HTML, 16 KB cap) — replace any curated KB. Citations auto-appended from `read_page` tool log.

**Tech Stack:** Laravel 11, Filament 3, Livewire 3, Spatie Permission, PHPUnit. PHP 8.4 (server) / 8.5 (runtime). Groq HTTP API via `Illuminate\Support\Facades\Http`.

**Spec:** `docs/superpowers/specs/2026-05-06-ipu-knowledge-agent-design.md`

---

## File Structure

**New files (13 source + 13 test):**

| Path | Responsibility |
|---|---|
| `config/ai.php` | All tunables (provider, model, caps, docroot, excluded dirs) |
| `database/migrations/*_create_ai_conversations_table.php` | `ai_conversations` schema |
| `database/migrations/*_create_ai_messages_table.php` | `ai_messages` schema |
| `database/migrations/*_seed_use_ai_agent_permission.php` | Spatie permission seed + admin grant |
| `app/Models/AiConversation.php` | Conversation model |
| `app/Models/AiMessage.php` | Message model (no `updated_at`) |
| `app/Policies/AiConversationPolicy.php` | Owner-or-admin view rule |
| `app/Services/Ai/LlmProvider.php` | Provider interface |
| `app/Services/Ai/LlmResponse.php` | DTO returned by providers |
| `app/Services/Ai/Providers/GroqProvider.php` | Groq HTTP impl |
| `app/Services/Ai/Tools/SearchPagesTool.php` | Filesystem search |
| `app/Services/Ai/Tools/ReadPageTool.php` | Filesystem read + sanitize |
| `app/Services/Ai/AssistantService.php` | Tool-call loop orchestrator |
| `app/Http/Controllers/AiAssistantController.php` | Permission gate + daily cap + persistence |
| `app/Livewire/AiAssistantDrawer.php` + view | Right-drawer chat UI |
| `app/Filament/Pages/AiConversations.php` + view | Log browser |
| `app/Filament/Pages/AiAgentSettings.php` | Role-permission toggles |

**Modified files:**

| Path | Change |
|---|---|
| `resources/views/filament/components/top-bar.blade.php` | Add ✦ icon gated by `use ai-agent` |
| `app/Providers/AppServiceProvider.php` | Bind `LlmProvider` → `GroqProvider` |
| `routes/web.php` | POST `/ai/ask` route → controller |

---

## Task 1: Config + migrations

**Files:**
- Create: `config/ai.php`
- Create: `database/migrations/2026_05_22_000100_create_ai_conversations_table.php`
- Create: `database/migrations/2026_05_22_000200_create_ai_messages_table.php`
- Create: `database/migrations/2026_05_22_000300_seed_use_ai_agent_permission.php`
- Test: `tests/Feature/Ai/SchemaTest.php`

- [ ] **Step 1: Write failing schema test**

```php
<?php
namespace Tests\Feature\Ai;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class SchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_ai_tables_and_permission_exist(): void
    {
        $this->seed();
        $this->assertTrue(Schema::hasTable('ai_conversations'));
        $this->assertTrue(Schema::hasTable('ai_messages'));
        $this->assertTrue(Schema::hasColumns('ai_messages', [
            'conversation_id','role','content','tool_calls','tool_call_id',
            'citations','token_input','token_output','latency_ms','model','created_at',
        ]));
        $this->assertNotNull(Permission::where('name','use ai-agent')->first());
        $this->assertTrue(Role::where('name','admin')->first()->hasPermissionTo('use ai-agent'));
    }

    public function test_config_loaded(): void
    {
        $this->assertSame('groq', config('ai.provider'));
        $this->assertSame(50, config('ai.daily_cap_per_user'));
        $this->assertSame(3, config('ai.max_tool_roundtrips'));
        $this->assertSame(16384, config('ai.read_page_byte_cap'));
        $this->assertSame(['api','assets','cgi-bin','include'], config('ai.excluded_dirs'));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit --filter SchemaTest`
Expected: FAIL — `ai_conversations` table missing.

- [ ] **Step 3: Create `config/ai.php`**

```php
<?php
return [
    'provider' => env('AI_PROVIDER', 'groq'),
    'providers' => [
        'groq' => [
            'key'   => env('AI_GROQ_KEY'),
            'model' => env('AI_GROQ_MODEL', 'llama-3.3-70b-versatile'),
            'base_url' => env('AI_GROQ_BASE_URL', 'https://api.groq.com/openai/v1'),
            'timeout_seconds' => (int) env('AI_GROQ_TIMEOUT', 30),
        ],
    ],
    'daily_cap_per_user'   => (int) env('AI_DAILY_CAP', 50),
    'max_history_turns'    => (int) env('AI_MAX_HISTORY_TURNS', 10),
    'max_tool_roundtrips'  => (int) env('AI_MAX_TOOL_ROUNDTRIPS', 3),
    'ipu_docroot'          => env('AI_IPU_DOCROOT', '/home/ipuc/public_html'),
    'excluded_dirs'        => ['api', 'assets', 'cgi-bin', 'include'],
    'read_page_byte_cap'   => 16384,
];
```

- [ ] **Step 4: Create migration `*_create_ai_conversations_table.php`**

```php
<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('ai_conversations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('title')->nullable();
            $table->timestamp('started_at')->useCurrent();
            $table->timestamp('last_message_at')->useCurrent();
            $table->timestamps();
            $table->index(['user_id', 'last_message_at']);
        });
    }
    public function down(): void { Schema::dropIfExists('ai_conversations'); }
};
```

- [ ] **Step 5: Create migration `*_create_ai_messages_table.php`**

```php
<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('ai_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained('ai_conversations')->cascadeOnDelete();
            $table->enum('role', ['system', 'user', 'assistant', 'tool']);
            $table->text('content');
            $table->json('tool_calls')->nullable();
            $table->string('tool_call_id')->nullable();
            $table->json('citations')->nullable();
            $table->unsignedInteger('token_input')->nullable();
            $table->unsignedInteger('token_output')->nullable();
            $table->unsignedInteger('latency_ms')->nullable();
            $table->string('model')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['conversation_id', 'created_at']);
        });
    }
    public function down(): void { Schema::dropIfExists('ai_messages'); }
};
```

- [ ] **Step 6: Create migration `*_seed_use_ai_agent_permission.php`**

```php
<?php
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

return new class extends Migration {
    public function up(): void {
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
        Permission::firstOrCreate(['name' => 'use ai-agent', 'guard_name' => 'web']);
        Role::where('name', 'admin')->first()?->givePermissionTo('use ai-agent');
    }
    public function down(): void {
        Permission::where('name', 'use ai-agent')->delete();
    }
};
```

- [ ] **Step 7: Run test to verify it passes**

Run: `vendor/bin/phpunit --filter SchemaTest`
Expected: PASS, 2 tests, 7 assertions.

- [ ] **Step 8: Commit**

```bash
git add config/ai.php database/migrations/2026_05_22_0001* database/migrations/2026_05_22_0002* database/migrations/2026_05_22_0003* tests/Feature/Ai/SchemaTest.php
git commit -m "feat(ai): scaffolding — config, ai_conversations, ai_messages, use ai-agent permission"
```

---

## Task 2: Models + factories + policy

**Files:**
- Create: `app/Models/AiConversation.php`
- Create: `app/Models/AiMessage.php`
- Create: `app/Policies/AiConversationPolicy.php`
- Create: `database/factories/AiConversationFactory.php`
- Create: `database/factories/AiMessageFactory.php`
- Test: `tests/Feature/Ai/AiModelsTest.php`

- [ ] **Step 1: Write failing test**

```php
<?php
namespace Tests\Feature\Ai;

use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AiModelsTest extends TestCase
{
    use RefreshDatabase;

    public function test_conversation_has_messages_and_user(): void
    {
        $this->seed();
        $user = User::where('email','sumit@davya.local')->firstOrFail();
        $conv = AiConversation::factory()->for($user)->create();
        AiMessage::factory()->for($conv, 'conversation')->count(3)->create();

        $this->assertCount(3, $conv->refresh()->messages);
        $this->assertSame($user->id, $conv->user->id);
    }

    public function test_message_casts_json_columns(): void
    {
        $this->seed();
        $user = User::where('email','sumit@davya.local')->firstOrFail();
        $conv = AiConversation::factory()->for($user)->create();
        $msg = AiMessage::factory()->for($conv, 'conversation')->create([
            'role'       => 'assistant',
            'tool_calls' => [['name' => 'search_pages', 'args' => ['query' => 'foo']]],
            'citations'  => ['BBA.php', 'fees.php'],
        ]);

        $this->assertIsArray($msg->refresh()->tool_calls);
        $this->assertSame(['BBA.php','fees.php'], $msg->citations);
    }

    public function test_policy_admin_sees_all_user_sees_own(): void
    {
        $this->seed();
        $admin = User::where('email','sumit@davya.local')->firstOrFail();
        $admin->assignRole('admin');
        $other = User::factory()->create();
        $ownConv  = AiConversation::factory()->for($admin)->create();
        $theirConv = AiConversation::factory()->for($other)->create();

        $this->assertTrue($admin->can('view',  $ownConv));
        $this->assertTrue($admin->can('view',  $theirConv));
        $this->assertTrue($other->can('view',  $ownConv) === false);
        $this->assertTrue($other->can('view',  $theirConv));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit --filter AiModelsTest`
Expected: FAIL — `AiConversation` class not found.

- [ ] **Step 3: Create `app/Models/AiConversation.php`**

```php
<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AiConversation extends Model
{
    use HasFactory;

    protected $fillable = ['user_id', 'title', 'started_at', 'last_message_at'];
    protected $casts = [
        'started_at'      => 'datetime',
        'last_message_at' => 'datetime',
    ];

    public function user(): BelongsTo { return $this->belongsTo(User::class); }
    public function messages(): HasMany { return $this->hasMany(AiMessage::class, 'conversation_id')->orderBy('created_at'); }
}
```

- [ ] **Step 4: Create `app/Models/AiMessage.php`**

```php
<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiMessage extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'conversation_id','role','content','tool_calls','tool_call_id',
        'citations','token_input','token_output','latency_ms','model','created_at',
    ];

    protected $casts = [
        'tool_calls' => 'array',
        'citations'  => 'array',
        'created_at' => 'datetime',
    ];

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(AiConversation::class, 'conversation_id');
    }
}
```

- [ ] **Step 5: Create `app/Policies/AiConversationPolicy.php`**

```php
<?php
namespace App\Policies;

use App\Models\AiConversation;
use App\Models\User;

class AiConversationPolicy
{
    public function viewAny(User $user): bool { return $user->can('use ai-agent'); }

    public function view(User $user, AiConversation $conversation): bool
    {
        if ($conversation->user_id === $user->id) return true;
        return $user->hasAnyRole(['admin', 'super_admin']);
    }
}
```

- [ ] **Step 6: Register policy in `app/Providers/AppServiceProvider.php`**

Add to the `boot()` method:

```php
\Illuminate\Support\Facades\Gate::policy(
    \App\Models\AiConversation::class,
    \App\Policies\AiConversationPolicy::class,
);
```

- [ ] **Step 7: Create factories**

`database/factories/AiConversationFactory.php`:
```php
<?php
namespace Database\Factories;

use App\Models\AiConversation;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class AiConversationFactory extends Factory
{
    protected $model = AiConversation::class;
    public function definition(): array {
        return [
            'user_id'         => User::factory(),
            'title'           => $this->faker->sentence(4),
            'started_at'      => now(),
            'last_message_at' => now(),
        ];
    }
}
```

`database/factories/AiMessageFactory.php`:
```php
<?php
namespace Database\Factories;

use App\Models\AiConversation;
use App\Models\AiMessage;
use Illuminate\Database\Eloquent\Factories\Factory;

class AiMessageFactory extends Factory
{
    protected $model = AiMessage::class;
    public function definition(): array {
        return [
            'conversation_id' => AiConversation::factory(),
            'role'            => 'user',
            'content'         => $this->faker->sentence(),
            'created_at'      => now(),
        ];
    }
}
```

- [ ] **Step 8: Run test to verify it passes**

Run: `vendor/bin/phpunit --filter AiModelsTest`
Expected: PASS, 3 tests.

- [ ] **Step 9: Commit**

```bash
git add app/Models/AiConversation.php app/Models/AiMessage.php app/Policies/AiConversationPolicy.php app/Providers/AppServiceProvider.php database/factories/AiConversation*.php database/factories/AiMessage*.php tests/Feature/Ai/AiModelsTest.php
git commit -m "feat(ai): AiConversation + AiMessage models + policy + factories"
```

---

## Task 3: LlmProvider interface + LlmResponse DTO

**Files:**
- Create: `app/Services/Ai/LlmProvider.php`
- Create: `app/Services/Ai/LlmResponse.php`
- Test: `tests/Unit/Ai/LlmResponseTest.php`

- [ ] **Step 1: Write failing test**

```php
<?php
namespace Tests\Unit\Ai;

use App\Services\Ai\LlmResponse;
use PHPUnit\Framework\TestCase;

class LlmResponseTest extends TestCase
{
    public function test_dto_construction(): void
    {
        $r = new LlmResponse(
            content: 'hello',
            toolCalls: null,
            tokenInput: 100,
            tokenOutput: 50,
            latencyMs: 1234,
            model: 'llama-3.3-70b-versatile',
        );

        $this->assertSame('hello', $r->content);
        $this->assertNull($r->toolCalls);
        $this->assertFalse($r->wantsTools());
    }

    public function test_wants_tools_when_tool_calls_present(): void
    {
        $r = new LlmResponse(
            content: '',
            toolCalls: [['id' => 'call_1', 'name' => 'search_pages', 'arguments' => ['query' => 'foo']]],
            tokenInput: 100, tokenOutput: 20, latencyMs: 800, model: 'x',
        );
        $this->assertTrue($r->wantsTools());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit --filter LlmResponseTest`
Expected: FAIL — class not found.

- [ ] **Step 3: Create `app/Services/Ai/LlmProvider.php`**

```php
<?php
namespace App\Services\Ai;

interface LlmProvider
{
    /**
     * @param array<int, array<string, mixed>> $messages Chat messages (OpenAI-style).
     * @param array<int, array<string, mixed>> $tools    Tool definitions (OpenAI-style).
     */
    public function chat(array $messages, array $tools = []): LlmResponse;
}
```

- [ ] **Step 4: Create `app/Services/Ai/LlmResponse.php`**

```php
<?php
namespace App\Services\Ai;

final class LlmResponse
{
    public function __construct(
        public readonly string $content,
        public readonly ?array $toolCalls,
        public readonly int $tokenInput,
        public readonly int $tokenOutput,
        public readonly int $latencyMs,
        public readonly string $model,
    ) {}

    public function wantsTools(): bool
    {
        return is_array($this->toolCalls) && count($this->toolCalls) > 0;
    }
}
```

- [ ] **Step 5: Run test to verify it passes**

Run: `vendor/bin/phpunit --filter LlmResponseTest`
Expected: PASS, 2 tests.

- [ ] **Step 6: Commit**

```bash
git add app/Services/Ai/LlmProvider.php app/Services/Ai/LlmResponse.php tests/Unit/Ai/LlmResponseTest.php
git commit -m "feat(ai): LlmProvider interface + LlmResponse DTO"
```

---

## Task 4: SearchPagesTool

**Files:**
- Create: `app/Services/Ai/Tools/SearchPagesTool.php`
- Test: `tests/Unit/Ai/SearchPagesToolTest.php`

- [ ] **Step 1: Write failing test**

```php
<?php
namespace Tests\Unit\Ai;

use App\Services\Ai\Tools\SearchPagesTool;
use PHPUnit\Framework\TestCase;

class SearchPagesToolTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir().'/spt_'.uniqid();
        mkdir($this->root, 0777, true);
        mkdir($this->root.'/assets', 0777, true);

        file_put_contents($this->root.'/bba-fees.php',
            "<?php\n?><title>BBA Fees at VIPS-TC</title>\n<body>BBA fee is 95000 per semester at VIPS-TC.</body>");
        file_put_contents($this->root.'/hostel.php',
            "<?php\n?><title>Hostel</title>\n<body>MAIT hostel has 200 beds for boys.</body>");
        file_put_contents($this->root.'/no-title.php',
            "<?php\n?><body>BBA mention but no title tag at all.</body>");
        file_put_contents($this->root.'/assets/style.php',
            "<?php\n?>BBA fees garbage that should be excluded.");
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->root);
        parent::tearDown();
    }

    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) return;
        foreach (scandir($dir) as $f) {
            if ($f === '.' || $f === '..') continue;
            $p = "$dir/$f";
            is_dir($p) ? $this->rrmdir($p) : unlink($p);
        }
        rmdir($dir);
    }

    public function test_finds_matching_files_and_extracts_title(): void
    {
        $tool = new SearchPagesTool($this->root, ['assets']);
        $hits = $tool->execute('BBA');

        $slugs = array_column($hits, 'slug');
        sort($slugs);
        $this->assertSame(['bba-fees.php', 'no-title.php'], $slugs);

        $bba = collect($hits)->firstWhere('slug', 'bba-fees.php');
        $this->assertSame('BBA Fees at VIPS-TC', $bba['title']);
        $this->assertStringContainsString('BBA', $bba['snippet']);
    }

    public function test_falls_back_to_slug_when_no_title(): void
    {
        $tool = new SearchPagesTool($this->root, ['assets']);
        $hit = collect($tool->execute('BBA'))->firstWhere('slug', 'no-title.php');
        $this->assertSame('no-title', $hit['title']);
    }

    public function test_excludes_configured_dirs(): void
    {
        $tool = new SearchPagesTool($this->root, ['assets']);
        $slugs = array_column($tool->execute('BBA'), 'slug');
        $this->assertNotContains('style.php', $slugs);
    }

    public function test_caps_at_10_results(): void
    {
        for ($i = 0; $i < 15; $i++) {
            file_put_contents($this->root."/extra-{$i}.php", "<?php ?><title>x</title>BBA");
        }
        $tool = new SearchPagesTool($this->root, ['assets']);
        $this->assertCount(10, $tool->execute('BBA'));
    }

    public function test_returns_empty_when_docroot_missing(): void
    {
        $tool = new SearchPagesTool('/does/not/exist', []);
        $this->assertSame([], $tool->execute('anything'));
    }

    public function test_definition_shape(): void
    {
        $def = SearchPagesTool::definition();
        $this->assertSame('function', $def['type']);
        $this->assertSame('search_pages', $def['function']['name']);
        $this->assertArrayHasKey('query', $def['function']['parameters']['properties']);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit --filter SearchPagesToolTest`
Expected: FAIL — class not found.

- [ ] **Step 3: Create `app/Services/Ai/Tools/SearchPagesTool.php`**

```php
<?php
namespace App\Services\Ai\Tools;

final class SearchPagesTool
{
    public function __construct(
        private readonly string $docroot,
        private readonly array $excludedDirs,
    ) {}

    public static function definition(): array
    {
        return [
            'type' => 'function',
            'function' => [
                'name'        => 'search_pages',
                'description' => 'Search ipu.co.in pages by free-text query. Returns up to 10 matching pages with slug, title, snippet.',
                'parameters'  => [
                    'type' => 'object',
                    'properties' => [
                        'query' => ['type' => 'string', 'description' => 'Free-text search query'],
                    ],
                    'required' => ['query'],
                ],
            ],
        ];
    }

    /** @return array<int, array{slug:string,title:string,snippet:string}> */
    public function execute(string $query): array
    {
        if (!is_dir($this->docroot)) return [];

        $needle = strtolower($query);
        if ($needle === '') return [];

        $results = [];
        foreach ($this->iterPhpFiles($this->docroot) as $file) {
            $rel = ltrim(str_replace($this->docroot, '', $file), '/');
            $top = explode('/', $rel)[0] ?? '';
            if (in_array($top, $this->excludedDirs, true) && str_contains($rel, '/')) continue;

            $contents = @file_get_contents($file);
            if ($contents === false) continue;

            $pos = stripos($contents, $needle);
            if ($pos === false) continue;

            $results[] = [
                'slug'    => basename($file),
                'title'   => $this->extractTitle($contents, basename($file)),
                'snippet' => $this->snippet($contents, $pos),
            ];
            if (count($results) >= 10) break;
        }
        return $results;
    }

    /** @return iterable<string> */
    private function iterPhpFiles(string $dir): iterable
    {
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') continue;
            $path = "$dir/$entry";
            if (is_dir($path)) {
                if (in_array($entry, $this->excludedDirs, true)) continue;
                yield from $this->iterPhpFiles($path);
            } elseif (str_ends_with($entry, '.php')) {
                yield $path;
            }
        }
    }

    private function extractTitle(string $contents, string $slug): string
    {
        if (preg_match('/<title>(.*?)<\/title>/is', $contents, $m)) {
            $title = trim(html_entity_decode(strip_tags($m[1]), ENT_QUOTES | ENT_HTML5));
            if ($title !== '') return $title;
        }
        return preg_replace('/\.php$/', '', $slug);
    }

    private function snippet(string $contents, int $pos): string
    {
        $start  = max(0, $pos - 80);
        $window = substr($contents, $start, 300);
        $clean  = trim(preg_replace('/\s+/', ' ', strip_tags($window)));
        return mb_substr($clean, 0, 200);
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit --filter SearchPagesToolTest`
Expected: PASS, 6 tests.

- [ ] **Step 5: Commit**

```bash
git add app/Services/Ai/Tools/SearchPagesTool.php tests/Unit/Ai/SearchPagesToolTest.php
git commit -m "feat(ai): SearchPagesTool — recursive grep with title + snippet"
```

---

## Task 5: ReadPageTool

**Files:**
- Create: `app/Services/Ai/Tools/ReadPageTool.php`
- Test: `tests/Unit/Ai/ReadPageToolTest.php`

- [ ] **Step 1: Write failing test**

```php
<?php
namespace Tests\Unit\Ai;

use App\Services\Ai\Tools\ReadPageTool;
use PHPUnit\Framework\TestCase;

class ReadPageToolTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir().'/rpt_'.uniqid();
        mkdir($this->root);
        file_put_contents($this->root.'/page.php',
            "<?php include 'x.php'; ?>\n<title>Page</title>\n<h1>Hello</h1>\n<p>Body text here.</p>\n<?php echo 'secret'; ?>");
        file_put_contents($this->root.'/large.php', str_repeat('a', 20000));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->root.'/*') as $f) unlink($f);
        rmdir($this->root);
        parent::tearDown();
    }

    public function test_strips_php_and_html_keeps_text(): void
    {
        $tool = new ReadPageTool($this->root, 16384);
        $out = $tool->execute('page.php');

        $this->assertStringContainsString('Hello', $out);
        $this->assertStringContainsString('Body text here.', $out);
        $this->assertStringNotContainsString("echo 'secret'", $out);
        $this->assertStringNotContainsString('include', $out);
        $this->assertStringNotContainsString('<h1>', $out);
    }

    public function test_byte_cap_truncates(): void
    {
        $tool = new ReadPageTool($this->root, 16384);
        $out = $tool->execute('large.php');
        $this->assertLessThanOrEqual(16384, strlen($out));
    }

    public function test_rejects_traversal(): void
    {
        $tool = new ReadPageTool($this->root, 16384);
        $this->assertStringStartsWith('ERROR:', $tool->execute('../etc/passwd'));
        $this->assertStringStartsWith('ERROR:', $tool->execute('/etc/passwd'));
        $this->assertStringStartsWith('ERROR:', $tool->execute('sub/../page.php'));
    }

    public function test_missing_file_returns_error_string(): void
    {
        $tool = new ReadPageTool($this->root, 16384);
        $this->assertStringStartsWith('ERROR:', $tool->execute('nope.php'));
    }

    public function test_definition_shape(): void
    {
        $def = ReadPageTool::definition();
        $this->assertSame('read_page', $def['function']['name']);
        $this->assertArrayHasKey('slug', $def['function']['parameters']['properties']);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit --filter ReadPageToolTest`
Expected: FAIL — class not found.

- [ ] **Step 3: Create `app/Services/Ai/Tools/ReadPageTool.php`**

```php
<?php
namespace App\Services\Ai\Tools;

final class ReadPageTool
{
    public function __construct(
        private readonly string $docroot,
        private readonly int $byteCap,
    ) {}

    public static function definition(): array
    {
        return [
            'type' => 'function',
            'function' => [
                'name'        => 'read_page',
                'description' => 'Read the text content of an ipu.co.in page by slug. Returns up to 16 KB of HTML- and PHP-stripped text.',
                'parameters'  => [
                    'type' => 'object',
                    'properties' => [
                        'slug' => ['type' => 'string', 'description' => 'Page slug like "IPU-B-Tech-admission-2026.php"'],
                    ],
                    'required' => ['slug'],
                ],
            ],
        ];
    }

    public function execute(string $slug): string
    {
        if ($slug === '' || str_contains($slug, '..') || str_starts_with($slug, '/')) {
            return 'ERROR: invalid slug';
        }

        $path = $this->docroot.'/'.$slug;
        $real = realpath($path);
        $rootReal = realpath($this->docroot);
        if ($real === false || $rootReal === false || !str_starts_with($real, $rootReal.DIRECTORY_SEPARATOR) && $real !== $rootReal) {
            return 'ERROR: file not found';
        }
        if (!is_file($real)) return 'ERROR: not a file';

        $raw = file_get_contents($real);
        if ($raw === false) return 'ERROR: read failed';

        // Strip PHP blocks
        $stripped = preg_replace('/<\?php.*?\?>/is', ' ', $raw) ?? $raw;
        $stripped = preg_replace('/<\?=.*?\?>/is', ' ', $stripped) ?? $stripped;

        // Strip remaining tags but preserve whitespace + newlines around headings
        $text = preg_replace('/<\s*\/?\s*(h[1-6]|br|p)\s*[^>]*>/i', "\n", $stripped) ?? $stripped;
        $text = strip_tags($text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5);
        $text = preg_replace('/[ \t]+/', ' ', $text) ?? $text;
        $text = preg_replace('/\n{3,}/', "\n\n", $text) ?? $text;
        $text = trim($text);

        return mb_strcut($text, 0, $this->byteCap);
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit --filter ReadPageToolTest`
Expected: PASS, 5 tests.

- [ ] **Step 5: Commit**

```bash
git add app/Services/Ai/Tools/ReadPageTool.php tests/Unit/Ai/ReadPageToolTest.php
git commit -m "feat(ai): ReadPageTool — strips PHP+HTML, 16KB cap, traversal-safe"
```

---

## Task 6: GroqProvider

**Files:**
- Create: `app/Services/Ai/Providers/GroqProvider.php`
- Test: `tests/Feature/Ai/GroqProviderTest.php`

- [ ] **Step 1: Write failing test**

```php
<?php
namespace Tests\Feature\Ai;

use App\Services\Ai\Providers\GroqProvider;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GroqProviderTest extends TestCase
{
    private function provider(): GroqProvider
    {
        return new GroqProvider(
            apiKey: 'test-key',
            model: 'llama-3.3-70b-versatile',
            baseUrl: 'https://api.groq.com/openai/v1',
            timeoutSeconds: 5,
        );
    }

    public function test_basic_text_response(): void
    {
        Http::fake([
            'api.groq.com/*' => Http::response([
                'choices' => [['message' => ['role' => 'assistant', 'content' => 'hello world']]],
                'usage'   => ['prompt_tokens' => 50, 'completion_tokens' => 10],
                'model'   => 'llama-3.3-70b-versatile',
            ], 200),
        ]);

        $resp = $this->provider()->chat([
            ['role' => 'user', 'content' => 'hi'],
        ]);

        $this->assertSame('hello world', $resp->content);
        $this->assertNull($resp->toolCalls);
        $this->assertSame(50, $resp->tokenInput);
        $this->assertSame(10, $resp->tokenOutput);
        $this->assertFalse($resp->wantsTools());
    }

    public function test_tool_call_response(): void
    {
        Http::fake([
            'api.groq.com/*' => Http::response([
                'choices' => [[
                    'message' => [
                        'role' => 'assistant',
                        'content' => null,
                        'tool_calls' => [[
                            'id' => 'call_abc',
                            'type' => 'function',
                            'function' => ['name' => 'search_pages', 'arguments' => '{"query":"BBA"}'],
                        ]],
                    ],
                ]],
                'usage' => ['prompt_tokens' => 100, 'completion_tokens' => 20],
                'model' => 'llama-3.3-70b-versatile',
            ], 200),
        ]);

        $resp = $this->provider()->chat(
            [['role' => 'user', 'content' => 'BBA fees?']],
            [\App\Services\Ai\Tools\SearchPagesTool::definition()],
        );

        $this->assertTrue($resp->wantsTools());
        $this->assertSame('call_abc', $resp->toolCalls[0]['id']);
        $this->assertSame('search_pages', $resp->toolCalls[0]['name']);
        $this->assertSame(['query' => 'BBA'], $resp->toolCalls[0]['arguments']);
    }

    public function test_http_error_throws_runtime_exception(): void
    {
        Http::fake([
            'api.groq.com/*' => Http::response(['error' => 'rate limited'], 429),
        ]);

        $this->expectException(\App\Services\Ai\Providers\GroqException::class);
        $this->provider()->chat([['role' => 'user', 'content' => 'x']]);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit --filter GroqProviderTest`
Expected: FAIL — class not found.

- [ ] **Step 3: Create `app/Services/Ai/Providers/GroqProvider.php`**

```php
<?php
namespace App\Services\Ai\Providers;

use App\Services\Ai\LlmProvider;
use App\Services\Ai\LlmResponse;
use Illuminate\Support\Facades\Http;

class GroqException extends \RuntimeException
{
    public function __construct(string $message, public readonly int $status = 0)
    {
        parent::__construct($message);
    }
}

class GroqProvider implements LlmProvider
{
    public function __construct(
        private readonly string $apiKey,
        private readonly string $model,
        private readonly string $baseUrl,
        private readonly int $timeoutSeconds,
    ) {}

    public function chat(array $messages, array $tools = []): LlmResponse
    {
        $payload = [
            'model'    => $this->model,
            'messages' => $messages,
        ];
        if ($tools !== []) {
            $payload['tools'] = $tools;
            $payload['tool_choice'] = 'auto';
        }

        $start = microtime(true);
        $response = Http::withToken($this->apiKey)
            ->timeout($this->timeoutSeconds)
            ->acceptJson()
            ->asJson()
            ->post(rtrim($this->baseUrl, '/').'/chat/completions', $payload);
        $latency = (int) ((microtime(true) - $start) * 1000);

        if (!$response->successful()) {
            throw new GroqException(
                "Groq HTTP {$response->status()}: ".substr($response->body(), 0, 500),
                $response->status(),
            );
        }

        $body = $response->json();
        $msg = $body['choices'][0]['message'] ?? [];
        $content = (string) ($msg['content'] ?? '');

        $toolCalls = null;
        if (!empty($msg['tool_calls'])) {
            $toolCalls = array_map(fn (array $tc) => [
                'id'        => $tc['id'] ?? '',
                'name'      => $tc['function']['name'] ?? '',
                'arguments' => json_decode($tc['function']['arguments'] ?? '{}', true) ?: [],
            ], $msg['tool_calls']);
        }

        return new LlmResponse(
            content:     $content,
            toolCalls:   $toolCalls,
            tokenInput:  (int) ($body['usage']['prompt_tokens'] ?? 0),
            tokenOutput: (int) ($body['usage']['completion_tokens'] ?? 0),
            latencyMs:   $latency,
            model:       (string) ($body['model'] ?? $this->model),
        );
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit --filter GroqProviderTest`
Expected: PASS, 3 tests.

- [ ] **Step 5: Commit**

```bash
git add app/Services/Ai/Providers/GroqProvider.php tests/Feature/Ai/GroqProviderTest.php
git commit -m "feat(ai): GroqProvider with tool-calling + GroqException"
```

---

## Task 7: AssistantService (tool-call loop + citations)

**Files:**
- Create: `app/Services/Ai/AssistantService.php`
- Test: `tests/Feature/Ai/AssistantServiceTest.php`

- [ ] **Step 1: Write failing test**

```php
<?php
namespace Tests\Feature\Ai;

use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Models\User;
use App\Services\Ai\AssistantService;
use App\Services\Ai\LlmProvider;
use App\Services\Ai\LlmResponse;
use App\Services\Ai\Tools\ReadPageTool;
use App\Services\Ai\Tools\SearchPagesTool;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AssistantServiceTest extends TestCase
{
    use RefreshDatabase;

    private function service(LlmProvider $provider, array $fsFiles = []): AssistantService
    {
        $root = sys_get_temp_dir().'/asvc_'.uniqid();
        mkdir($root);
        foreach ($fsFiles as $slug => $body) file_put_contents("$root/$slug", $body);

        return new AssistantService(
            provider: $provider,
            search:   new SearchPagesTool($root, []),
            read:     new ReadPageTool($root, 16384),
            maxRoundTrips: 3,
            historyTurns:  10,
        );
    }

    private function stubProvider(array $sequence): LlmProvider
    {
        return new class($sequence) implements LlmProvider {
            public function __construct(private array $seq) {}
            public function chat(array $m, array $t = []): LlmResponse
            {
                if ($this->seq === []) throw new \RuntimeException('stub exhausted');
                return array_shift($this->seq);
            }
        };
    }

    private function emptyConversation(): AiConversation
    {
        $user = User::where('email','sumit@davya.local')->firstOrFail();
        return AiConversation::create([
            'user_id'         => $user->id,
            'started_at'      => now(),
            'last_message_at' => now(),
        ]);
    }

    public function test_zero_round_trips_direct_answer(): void
    {
        $conv = $this->emptyConversation();
        $svc = $this->service($this->stubProvider([
            new LlmResponse('The BBA fee is 95k.', null, 100, 20, 500, 'm'),
        ]));

        $msg = $svc->ask($conv, 'BBA fees at VIPS-TC?');

        $this->assertSame('assistant', $msg->role);
        $this->assertStringContainsString('95k', $msg->content);
        $this->assertSame([], $msg->citations);
        // Trace: 1 user + 1 final assistant
        $this->assertSame(2, $conv->messages()->count());
    }

    public function test_search_then_read_then_answer(): void
    {
        $conv = $this->emptyConversation();
        $svc  = $this->service(
            $this->stubProvider([
                new LlmResponse('', [['id' => 'c1', 'name' => 'search_pages', 'arguments' => ['query' => 'BBA']]], 100, 10, 200, 'm'),
                new LlmResponse('', [['id' => 'c2', 'name' => 'read_page', 'arguments' => ['slug' => 'bba.php']]], 110, 10, 200, 'm'),
                new LlmResponse('BBA fees are 95k.', null, 200, 30, 400, 'm'),
            ]),
            ['bba.php' => "<?php ?><title>BBA</title>\nBBA fee is 95k per semester."],
        );

        $msg = $svc->ask($conv, 'BBA fees?');

        $this->assertStringContainsString('95k', $msg->content);
        $this->assertSame(['bba.php'], $msg->citations);
        $this->assertStringContainsString('Source: bba.php', $msg->content);
        // Trace: user + (asst+tool)*2 + final asst = 6
        $this->assertSame(6, $conv->messages()->count());
        $this->assertSame(2, $conv->messages()->where('role','tool')->count());
        $assistantToolMsgs = $conv->messages()->where('role','assistant')->whereNotNull('tool_calls')->count();
        $this->assertSame(2, $assistantToolMsgs);
    }

    public function test_round_trip_cap_falls_back_to_last_text(): void
    {
        $conv = $this->emptyConversation();
        $svc  = $this->service(
            $this->stubProvider([
                new LlmResponse('still thinking', [['id'=>'c1','name'=>'search_pages','arguments'=>['query'=>'x']]], 10, 10, 50, 'm'),
                new LlmResponse('still thinking', [['id'=>'c2','name'=>'search_pages','arguments'=>['query'=>'y']]], 10, 10, 50, 'm'),
                new LlmResponse('still thinking', [['id'=>'c3','name'=>'search_pages','arguments'=>['query'=>'z']]], 10, 10, 50, 'm'),
            ]),
        );

        $msg = $svc->ask($conv, 'x');
        $this->assertStringContainsString("couldn't pin down", $msg->content);
    }

    public function test_citations_dedupe(): void
    {
        $conv = $this->emptyConversation();
        $svc  = $this->service(
            $this->stubProvider([
                new LlmResponse('', [
                    ['id'=>'c1','name'=>'read_page','arguments'=>['slug'=>'bba.php']],
                    ['id'=>'c2','name'=>'read_page','arguments'=>['slug'=>'bba.php']],
                    ['id'=>'c3','name'=>'read_page','arguments'=>['slug'=>'fees.php']],
                ], 10, 10, 100, 'm'),
                new LlmResponse('done', null, 10, 10, 100, 'm'),
            ]),
            ['bba.php' => "<?php ?>BBA content", 'fees.php' => "<?php ?>Fee content"],
        );

        $msg = $svc->ask($conv, 'x');
        $this->assertSame(['bba.php','fees.php'], $msg->citations);
    }

    public function test_persists_token_and_latency_and_tool_calls(): void
    {
        $conv = $this->emptyConversation();
        $svc  = $this->service($this->stubProvider([
            new LlmResponse('answer', null, 123, 45, 678, 'llama-3.3-70b-versatile'),
        ]));

        $msg = $svc->ask($conv, 'x');
        $this->assertSame(123, $msg->token_input);
        $this->assertSame(45,  $msg->token_output);
        $this->assertSame(678, $msg->latency_ms);
        $this->assertSame('llama-3.3-70b-versatile', $msg->model);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit --filter AssistantServiceTest`
Expected: FAIL — class not found.

- [ ] **Step 3: Create `app/Services/Ai/AssistantService.php`**

```php
<?php
namespace App\Services\Ai;

use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Services\Ai\Tools\ReadPageTool;
use App\Services\Ai\Tools\SearchPagesTool;

class AssistantService
{
    private const SYSTEM_PROMPT = <<<TXT
You are a counsellor assistant for IPU/GGSIPU admissions at https://ipu.co.in.
Use the search_pages and read_page tools to ground every answer in real ipu.co.in content.
Never fabricate facts. If no relevant page is found, say so plainly.
Keep answers under 200 words. Always end with a "Source:" line citing the slug(s) you read.
TXT;

    public function __construct(
        private readonly LlmProvider $provider,
        private readonly SearchPagesTool $search,
        private readonly ReadPageTool $read,
        private readonly int $maxRoundTrips,
        private readonly int $historyTurns,
    ) {}

    public function ask(AiConversation $conversation, string $userQuestion): AiMessage
    {
        // Persist user turn first so it's part of the loop's history view.
        $userMsg = AiMessage::create([
            'conversation_id' => $conversation->id,
            'role'            => 'user',
            'content'         => $userQuestion,
            'created_at'      => now(),
        ]);

        try {
            return $this->runLoop($conversation);
        } catch (\App\Services\Ai\Providers\GroqException $e) {
            // On provider failure, roll back the user turn so it doesn't tick the daily cap.
            $userMsg->delete();
            throw $e;
        }
    }

    private function runLoop(AiConversation $conversation): AiMessage
    {
        $messages = $this->buildMessages($conversation);
        $tools = [SearchPagesTool::definition(), ReadPageTool::definition()];

        $citations = [];
        $tokenIn = 0; $tokenOut = 0; $latency = 0; $model = '';
        $finalContent = '';
        $lastTextual = '';

        for ($i = 0; $i < $this->maxRoundTrips; $i++) {
            $resp = $this->provider->chat($messages, $tools);
            $tokenIn  += $resp->tokenInput;
            $tokenOut += $resp->tokenOutput;
            $latency  += $resp->latencyMs;
            $model    = $resp->model;

            if (!$resp->wantsTools()) {
                $finalContent = $resp->content;
                break;
            }

            if ($resp->content !== '') $lastTextual = $resp->content;

            $apiToolCalls = array_map(fn($tc) => [
                'id' => $tc['id'],
                'type' => 'function',
                'function' => [
                    'name' => $tc['name'],
                    'arguments' => json_encode($tc['arguments']),
                ],
            ], $resp->toolCalls);

            // Persist intermediate assistant turn (with tool_calls JSON).
            AiMessage::create([
                'conversation_id' => $conversation->id,
                'role'            => 'assistant',
                'content'         => $resp->content,
                'tool_calls'      => $apiToolCalls,
                'token_input'     => $resp->tokenInput,
                'token_output'    => $resp->tokenOutput,
                'latency_ms'      => $resp->latencyMs,
                'model'           => $resp->model,
                'created_at'      => now(),
            ]);

            // Mirror into the API-format messages array for the next round-trip.
            $messages[] = [
                'role'       => 'assistant',
                'content'    => $resp->content,
                'tool_calls' => $apiToolCalls,
            ];

            foreach ($resp->toolCalls as $tc) {
                $result = $this->executeTool($tc['name'], $tc['arguments'], $citations);
                $resultStr = is_string($result) ? $result : json_encode($result);

                AiMessage::create([
                    'conversation_id' => $conversation->id,
                    'role'            => 'tool',
                    'content'         => $resultStr,
                    'tool_call_id'    => $tc['id'],
                    'created_at'      => now(),
                ]);

                $messages[] = [
                    'role'         => 'tool',
                    'tool_call_id' => $tc['id'],
                    'content'      => $resultStr,
                ];
            }
        }

        if ($finalContent === '') {
            $finalContent = $lastTextual !== ''
                ? $lastTextual
                : "I couldn't pin down an answer in our pages — try rephrasing.";
        }

        $citations = array_values(array_unique($citations));

        if ($citations !== [] && !str_contains($finalContent, 'Source:')) {
            $finalContent .= "\n\nSource: ".implode(', ', $citations);
        } elseif ($citations !== []) {
            $finalContent = preg_replace('/\n*Source:.*$/s', '', $finalContent)
                ."\n\nSource: ".implode(', ', $citations);
        }

        $msg = AiMessage::create([
            'conversation_id' => $conversation->id,
            'role'            => 'assistant',
            'content'         => $finalContent,
            'citations'       => $citations,
            'token_input'     => $tokenIn,
            'token_output'    => $tokenOut,
            'latency_ms'      => $latency,
            'model'           => $model,
            'created_at'      => now(),
        ]);

        $conversation->update(['last_message_at' => now()]);

        return $msg;
    }

    private function buildMessages(AiConversation $conversation): array
    {
        $history = $conversation->messages()
            ->latest('created_at')
            ->limit($this->historyTurns * 2)
            ->get()
            ->reverse()
            ->values();

        $messages = [['role' => 'system', 'content' => self::SYSTEM_PROMPT]];
        foreach ($history as $m) {
            if ($m->role === 'tool') continue; // skip prior tool noise; only carry user/assistant
            $messages[] = ['role' => $m->role, 'content' => $m->content];
        }
        return $messages;
    }

    private function executeTool(string $name, array $args, array &$citations): string
    {
        return match ($name) {
            'search_pages' => json_encode($this->search->execute((string) ($args['query'] ?? ''))),
            'read_page'    => $this->readAndTrack((string) ($args['slug'] ?? ''), $citations),
            default        => 'ERROR: unknown tool',
        };
    }

    private function readAndTrack(string $slug, array &$citations): string
    {
        $out = $this->read->execute($slug);
        if (!str_starts_with($out, 'ERROR:')) {
            $citations[] = $slug;
        }
        return $out;
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit --filter AssistantServiceTest`
Expected: PASS, 5 tests.

- [ ] **Step 5: Commit**

```bash
git add app/Services/Ai/AssistantService.php tests/Feature/Ai/AssistantServiceTest.php
git commit -m "feat(ai): AssistantService — tool-call loop, citation dedupe, fallback on cap"
```

---

## Task 8: AiAssistantController + daily cap + route

**Files:**
- Create: `app/Http/Controllers/AiAssistantController.php`
- Modify: `routes/web.php` (append route)
- Modify: `app/Providers/AppServiceProvider.php` (bind LlmProvider + provide AssistantService)
- Test: `tests/Feature/Ai/AiAssistantControllerTest.php`

- [ ] **Step 1: Write failing test**

```php
<?php
namespace Tests\Feature\Ai;

use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Models\User;
use App\Services\Ai\LlmProvider;
use App\Services\Ai\LlmResponse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AiAssistantControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        $this->app->bind(LlmProvider::class, fn() => new class implements LlmProvider {
            public function chat(array $m, array $t = []): LlmResponse
            {
                return new LlmResponse('canned answer', null, 10, 5, 100, 'stub');
            }
        });
    }

    private function adminWithPermission(): User
    {
        $u = User::where('email','sumit@davya.local')->firstOrFail();
        $u->assignRole('admin');
        return $u;
    }

    public function test_permission_denied_for_user_without_use_ai_agent(): void
    {
        $u = User::factory()->create();
        $this->actingAs($u)
            ->postJson('/ai/ask', ['question' => 'hi'])
            ->assertStatus(403);
    }

    public function test_creates_conversation_and_persists_messages(): void
    {
        $u = $this->adminWithPermission();
        $resp = $this->actingAs($u)->postJson('/ai/ask', ['question' => 'BBA fees?']);

        $resp->assertOk()
            ->assertJsonPath('answer', 'canned answer')
            ->assertJsonStructure(['conversation_id', 'answer']);

        $this->assertSame(1, AiConversation::where('user_id', $u->id)->count());
        $this->assertSame(2, AiMessage::count()); // user + assistant
    }

    public function test_continues_existing_conversation(): void
    {
        $u = $this->adminWithPermission();
        $conv = AiConversation::create(['user_id' => $u->id, 'started_at' => now(), 'last_message_at' => now()]);

        $this->actingAs($u)->postJson('/ai/ask', [
            'question' => 'follow up',
            'conversation_id' => $conv->id,
        ])->assertOk();

        $this->assertSame(1, AiConversation::count());
        $this->assertSame(2, $conv->messages()->count());
    }

    public function test_daily_cap_enforced(): void
    {
        config(['ai.daily_cap_per_user' => 2]);
        $u = $this->adminWithPermission();
        $this->actingAs($u)->postJson('/ai/ask', ['question' => 'q1'])->assertOk();
        $this->actingAs($u)->postJson('/ai/ask', ['question' => 'q2'])->assertOk();
        $this->actingAs($u)->postJson('/ai/ask', ['question' => 'q3'])->assertStatus(429);
    }

    public function test_groq_failure_does_not_count_against_cap(): void
    {
        config(['ai.daily_cap_per_user' => 2]);
        $this->app->bind(LlmProvider::class, fn() => new class implements LlmProvider {
            public function chat(array $m, array $t = []): LlmResponse {
                throw new \App\Services\Ai\Providers\GroqException('boom', 500);
            }
        });

        $u = $this->adminWithPermission();
        $this->actingAs($u)->postJson('/ai/ask', ['question' => 'q1'])->assertStatus(503);
        $this->actingAs($u)->postJson('/ai/ask', ['question' => 'q1'])->assertStatus(503);
        // still no user-role messages persisted? — only assistant errors aren't persisted.
        $this->assertSame(0, AiMessage::where('role','user')->count());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit --filter AiAssistantControllerTest`
Expected: FAIL — route undefined.

- [ ] **Step 3: Create `app/Http/Controllers/AiAssistantController.php`**

```php
<?php
namespace App\Http\Controllers;

use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Services\Ai\AssistantService;
use App\Services\Ai\Providers\GroqException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class AiAssistantController extends Controller
{
    public function ask(Request $request, AssistantService $svc): JsonResponse
    {
        $user = $request->user();
        abort_unless($user && $user->can('use ai-agent'), 403);

        $data = $request->validate([
            'question' => ['required', 'string', 'min:1', 'max:2000'],
            'conversation_id' => ['nullable', 'integer', 'exists:ai_conversations,id'],
        ]);

        $cap = (int) config('ai.daily_cap_per_user', 50);
        $startOfDay = now()->startOfDay();
        $todayCount = AiMessage::where('role', 'user')
            ->whereHas('conversation', fn($q) => $q->where('user_id', $user->id))
            ->where('created_at', '>=', $startOfDay)
            ->count();
        if ($todayCount >= $cap) {
            return response()->json([
                'error' => "Hit today's question cap ($cap). Resets midnight IST.",
            ], 429);
        }

        $conversation = isset($data['conversation_id'])
            ? AiConversation::where('user_id', $user->id)->findOrFail($data['conversation_id'])
            : AiConversation::create([
                'user_id'         => $user->id,
                'title'           => mb_substr($data['question'], 0, 60),
                'started_at'      => now(),
                'last_message_at' => now(),
            ]);

        try {
            // AssistantService persists user + trace + final; rolls back user on Groq failure.
            $assistantMsg = $svc->ask($conversation, $data['question']);
        } catch (GroqException $e) {
            Log::warning('Groq failed', ['status' => $e->status, 'msg' => $e->getMessage()]);
            return response()->json([
                'error' => $e->status === 429
                    ? 'Busy, try in a moment.'
                    : 'Something went wrong. Try again.',
            ], 503);
        }

        return response()->json([
            'conversation_id' => $conversation->id,
            'answer'          => $assistantMsg->content,
            'citations'       => $assistantMsg->citations,
        ]);
    }
}
```

- [ ] **Step 4: Add route in `routes/web.php`**

Append:

```php
Route::middleware(['web', 'auth'])->group(function () {
    Route::post('/ai/ask', [\App\Http\Controllers\AiAssistantController::class, 'ask'])
        ->name('ai.ask');
});
```

- [ ] **Step 5: Bind LlmProvider + AssistantService in `app/Providers/AppServiceProvider.php`**

In `register()`:

```php
$this->app->singleton(\App\Services\Ai\Tools\SearchPagesTool::class, fn() => new \App\Services\Ai\Tools\SearchPagesTool(
    config('ai.ipu_docroot'),
    config('ai.excluded_dirs'),
));
$this->app->singleton(\App\Services\Ai\Tools\ReadPageTool::class, fn() => new \App\Services\Ai\Tools\ReadPageTool(
    config('ai.ipu_docroot'),
    (int) config('ai.read_page_byte_cap'),
));
$this->app->bind(\App\Services\Ai\LlmProvider::class, function () {
    $cfg = config('ai.providers.groq');
    return new \App\Services\Ai\Providers\GroqProvider(
        apiKey: $cfg['key'] ?? '',
        model: $cfg['model'],
        baseUrl: $cfg['base_url'],
        timeoutSeconds: $cfg['timeout_seconds'],
    );
});
$this->app->bind(\App\Services\Ai\AssistantService::class, fn($app) => new \App\Services\Ai\AssistantService(
    provider: $app->make(\App\Services\Ai\LlmProvider::class),
    search: $app->make(\App\Services\Ai\Tools\SearchPagesTool::class),
    read: $app->make(\App\Services\Ai\Tools\ReadPageTool::class),
    maxRoundTrips: (int) config('ai.max_tool_roundtrips'),
    historyTurns: (int) config('ai.max_history_turns'),
));
```

- [ ] **Step 6: Run test to verify it passes**

Run: `vendor/bin/phpunit --filter AiAssistantControllerTest`
Expected: PASS, 5 tests.

- [ ] **Step 7: Commit**

```bash
git add app/Http/Controllers/AiAssistantController.php routes/web.php app/Providers/AppServiceProvider.php tests/Feature/Ai/AiAssistantControllerTest.php
git commit -m "feat(ai): controller + daily cap + provider/service bindings"
```

---

## Task 9: AiAssistantDrawer Livewire component

**Files:**
- Create: `app/Livewire/AiAssistantDrawer.php`
- Create: `resources/views/livewire/ai-assistant-drawer.blade.php`
- Test: `tests/Feature/Ai/AiAssistantDrawerTest.php`

- [ ] **Step 1: Write failing test**

```php
<?php
namespace Tests\Feature\Ai;

use App\Livewire\AiAssistantDrawer;
use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Models\User;
use App\Services\Ai\LlmProvider;
use App\Services\Ai\LlmResponse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class AiAssistantDrawerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->app->bind(LlmProvider::class, fn() => new class implements LlmProvider {
            public function chat(array $m, array $t = []): LlmResponse
            {
                return new LlmResponse('drawer answer', null, 1, 1, 1, 'stub');
            }
        });
    }

    public function test_ask_appends_messages_to_thread(): void
    {
        $u = User::where('email','sumit@davya.local')->firstOrFail();
        $u->assignRole('admin');

        Livewire::actingAs($u)
            ->test(AiAssistantDrawer::class)
            ->set('input', 'What are BBA fees?')
            ->call('ask')
            ->assertSet('input', '')
            ->assertSee('drawer answer');

        $this->assertSame(1, AiConversation::count());
        $this->assertSame(2, AiMessage::count());
    }

    public function test_new_chat_resets_conversation(): void
    {
        $u = User::where('email','sumit@davya.local')->firstOrFail();
        $u->assignRole('admin');

        $component = Livewire::actingAs($u)
            ->test(AiAssistantDrawer::class)
            ->set('input', 'q1')->call('ask')
            ->call('newChat')
            ->assertSet('conversationId', null)
            ->assertSet('thread', []);

        $this->assertSame(1, AiConversation::count(), 'first conversation persists');
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit --filter AiAssistantDrawerTest`
Expected: FAIL — component not found.

- [ ] **Step 3: Create `app/Livewire/AiAssistantDrawer.php`**

```php
<?php
namespace App\Livewire;

use App\Models\AiConversation;
use App\Services\Ai\AssistantService;
use App\Services\Ai\Providers\GroqException;
use Livewire\Attributes\Locked;
use Livewire\Component;

class AiAssistantDrawer extends Component
{
    public string $input = '';
    public ?int $conversationId = null;
    public array $thread = [];
    public ?string $error = null;
    public bool $busy = false;

    public function ask(AssistantService $svc): void
    {
        $user = auth()->user();
        if (!$user || !$user->can('use ai-agent')) abort(403);

        $question = trim($this->input);
        if ($question === '') return;
        $this->error = null;
        $this->busy = true;

        $conv = $this->conversationId
            ? AiConversation::where('user_id', $user->id)->find($this->conversationId)
            : null;

        if (!$conv) {
            $conv = AiConversation::create([
                'user_id'         => $user->id,
                'title'           => mb_substr($question, 0, 60),
                'started_at'      => now(),
                'last_message_at' => now(),
            ]);
            $this->conversationId = $conv->id;
        }

        $cap = (int) config('ai.daily_cap_per_user', 50);
        $todayCount = \App\Models\AiMessage::where('role', 'user')
            ->whereHas('conversation', fn($q) => $q->where('user_id', $user->id))
            ->where('created_at', '>=', now()->startOfDay())
            ->count();
        if ($todayCount >= $cap) {
            $this->error = "Hit today's question cap ($cap). Resets midnight IST.";
            $this->busy = false;
            return;
        }

        // Optimistic UI: show the user turn immediately; AssistantService persists it.
        $this->thread[] = ['role' => 'user', 'content' => $question];

        try {
            $msg = $svc->ask($conv, $question);
            $this->thread[] = ['role' => 'assistant', 'content' => $msg->content];
        } catch (GroqException $e) {
            // Service already rolled back the user message in DB; drop optimistic row too.
            array_pop($this->thread);
            $this->error = $e->status === 429
                ? 'Busy, try in a moment.'
                : 'Something went wrong. Try again.';
        }

        $this->input = '';
        $this->busy = false;
    }

    public function newChat(): void
    {
        $this->conversationId = null;
        $this->thread = [];
        $this->input = '';
        $this->error = null;
    }

    public function render()
    {
        return view('livewire.ai-assistant-drawer');
    }
}
```

- [ ] **Step 4: Create `resources/views/livewire/ai-assistant-drawer.blade.php`**

```blade
<div class="davya-ai-drawer" x-data="{ open: $wire.entangle('open').defer ?? false }">
    <div class="davya-ai-thread" style="max-height:60vh; overflow-y:auto; padding:1rem;">
        @forelse ($thread as $m)
            <div class="davya-ai-msg davya-ai-msg--{{ $m['role'] }}">
                <div class="davya-ai-msg-role">{{ ucfirst($m['role']) }}</div>
                <div class="davya-ai-msg-content">{!! nl2br(e($m['content'])) !!}</div>
            </div>
        @empty
            <div class="davya-ai-empty">Ask a question about ipu.co.in admissions.</div>
        @endforelse

        @if ($busy)
            <div class="davya-ai-busy" wire:loading.delay>Thinking…</div>
        @endif
        @if ($error)
            <div class="davya-ai-error" role="alert">{{ $error }}</div>
        @endif
    </div>

    <form wire:submit.prevent="ask" class="davya-ai-form" style="display:flex; gap:.5rem; padding:1rem;">
        <textarea wire:model.live="input"
                  placeholder="e.g. BBA fee at VIPS-TC?"
                  rows="2"
                  style="flex:1; resize:vertical;"
                  @keydown.cmd.enter="$wire.ask()"
                  @keydown.ctrl.enter="$wire.ask()"></textarea>
        <button type="submit" class="davya-action davya-action--solid" wire:loading.attr="disabled">Send</button>
        <button type="button" class="davya-action davya-action--ghost-light" wire:click="newChat">New chat</button>
    </form>
</div>
```

- [ ] **Step 5: Run test to verify it passes**

Run: `vendor/bin/phpunit --filter AiAssistantDrawerTest`
Expected: PASS, 2 tests.

- [ ] **Step 6: Commit**

```bash
git add app/Livewire/AiAssistantDrawer.php resources/views/livewire/ai-assistant-drawer.blade.php tests/Feature/Ai/AiAssistantDrawerTest.php
git commit -m "feat(ai): AiAssistantDrawer Livewire component + view"
```

---

## Task 10: TopBar icon

**Files:**
- Modify: `resources/views/filament/components/top-bar.blade.php`
- Test: `tests/Feature/Ai/TopBarAiIconTest.php`

- [ ] **Step 1: Write failing test**

```php
<?php
namespace Tests\Feature\Ai;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TopBarAiIconTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    public function test_admin_sees_ai_icon(): void
    {
        $u = User::where('email','sumit@davya.local')->firstOrFail();
        $u->assignRole('admin');

        $this->actingAs($u)
            ->get('/admin')
            ->assertOk()
            ->assertSee('data-ai-drawer-trigger', false);
    }

    public function test_user_without_permission_does_not_see_icon(): void
    {
        $u = User::factory()->create();
        $u->assignRole('freelancer');

        $this->actingAs($u)
            ->get('/admin')
            ->assertOk()
            ->assertDontSee('data-ai-drawer-trigger', false);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit --filter TopBarAiIconTest`
Expected: FAIL — assertion missing.

- [ ] **Step 3: Modify `resources/views/filament/components/top-bar.blade.php`**

Find a sensible spot near the existing user-menu / search pill area. Add inline (adjust selector if needed):

```blade
@if (auth()->user()?->can('use ai-agent'))
    <button type="button"
            data-ai-drawer-trigger
            class="davya-topbar-btn"
            title="Knowledge agent"
            onclick="document.dispatchEvent(new CustomEvent('ai-drawer:open'))">
        <span aria-hidden="true">✦</span>
        <span class="sr-only">Open AI assistant</span>
    </button>
@endif
```

(Engineer note: this assumes `top-bar.blade.php` is included on every `/admin/*` page via `HEAD_END` or a panel render hook. If not, also include the drawer mount: `<livewire:ai-assistant-drawer />` under the trigger.)

- [ ] **Step 4: Add the Livewire mount alongside the trigger (same blade)**

After the button:

```blade
@if (auth()->user()?->can('use ai-agent'))
    <livewire:ai-assistant-drawer />
@endif
```

- [ ] **Step 5: Run test to verify it passes**

Run: `vendor/bin/phpunit --filter TopBarAiIconTest`
Expected: PASS, 2 tests.

- [ ] **Step 6: Commit**

```bash
git add resources/views/filament/components/top-bar.blade.php tests/Feature/Ai/TopBarAiIconTest.php
git commit -m "feat(ai): TopBar ✦ icon gated by use ai-agent + drawer mount"
```

---

## Task 11: AiConversations Filament log browser

**Files:**
- Create: `app/Filament/Pages/AiConversations.php`
- Create: `resources/views/filament/pages/ai-conversations.blade.php`
- Test: `tests/Feature/Ai/AiConversationsPageTest.php`

- [ ] **Step 1: Write failing test**

```php
<?php
namespace Tests\Feature\Ai;

use App\Filament\Pages\AiConversations;
use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class AiConversationsPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    public function test_admin_sees_all_conversations(): void
    {
        $admin = User::where('email','sumit@davya.local')->firstOrFail();
        $admin->assignRole('admin');
        $other = User::factory()->create();

        AiConversation::create(['user_id' => $admin->id, 'title' => 'A', 'started_at' => now(), 'last_message_at' => now()]);
        AiConversation::create(['user_id' => $other->id, 'title' => 'B', 'started_at' => now(), 'last_message_at' => now()]);

        Livewire::actingAs($admin)
            ->test(AiConversations::class)
            ->assertSee('A')
            ->assertSee('B');
    }

    public function test_regular_user_sees_only_own(): void
    {
        $other = User::factory()->create();
        $other->givePermissionTo('use ai-agent');
        $admin = User::where('email','sumit@davya.local')->firstOrFail();
        $admin->assignRole('admin');

        AiConversation::create(['user_id' => $admin->id, 'title' => 'Admin one', 'started_at' => now(), 'last_message_at' => now()]);
        AiConversation::create(['user_id' => $other->id, 'title' => 'Other one', 'started_at' => now(), 'last_message_at' => now()]);

        Livewire::actingAs($other)
            ->test(AiConversations::class)
            ->assertSee('Other one')
            ->assertDontSee('Admin one');
    }

    public function test_no_permission_cannot_access(): void
    {
        $u = User::factory()->create();
        $this->assertFalse(AiConversations::canAccess() && auth()->loginUsingId($u->id) !== null);
        // Direct call:
        auth()->login($u);
        $this->assertFalse(AiConversations::canAccess());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit --filter AiConversationsPageTest`
Expected: FAIL — page class missing.

- [ ] **Step 3: Create `app/Filament/Pages/AiConversations.php`**

```php
<?php
namespace App\Filament\Pages;

use App\Models\AiConversation;
use Filament\Pages\Page;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;

class AiConversations extends Page implements HasTable
{
    use InteractsWithTable;

    protected static ?string $navigationIcon = 'heroicon-o-chat-bubble-left-right';
    protected static ?string $navigationGroup = 'Reports';
    protected static string $view = 'filament.pages.ai-conversations';
    protected static ?string $slug = 'ai-conversations';
    protected static ?string $title = 'AI Conversations';

    public static function canAccess(): bool
    {
        return auth()->user()?->can('use ai-agent') ?? false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(
                AiConversation::query()
                    ->with('user:id,name')
                    ->withCount('messages')
                    ->when(
                        !auth()->user()?->hasAnyRole(['admin','super_admin']),
                        fn ($q) => $q->where('user_id', auth()->id()),
                    )
                    ->latest('last_message_at'),
            )
            ->columns([
                TextColumn::make('title')->limit(60)->searchable(),
                TextColumn::make('user.name')->label('User')->toggleable(),
                TextColumn::make('messages_count')->label('Msgs')->numeric(),
                TextColumn::make('last_message_at')->dateTime()->since()->sortable(),
            ]);
    }
}
```

- [ ] **Step 4: Create `resources/views/filament/pages/ai-conversations.blade.php`**

```blade
<x-filament-panels::page>
    {{ $this->table }}
</x-filament-panels::page>
```

- [ ] **Step 5: Run test to verify it passes**

Run: `vendor/bin/phpunit --filter AiConversationsPageTest`
Expected: PASS, 3 tests.

- [ ] **Step 6: Commit**

```bash
git add app/Filament/Pages/AiConversations.php resources/views/filament/pages/ai-conversations.blade.php tests/Feature/Ai/AiConversationsPageTest.php
git commit -m "feat(ai): /admin/ai-conversations log browser (own vs all by role)"
```

---

## Task 12: AiAgentSettings Filament page

**Files:**
- Create: `app/Filament/Pages/AiAgentSettings.php`
- Create: `resources/views/filament/pages/ai-agent-settings.blade.php`
- Test: `tests/Feature/Ai/AiAgentSettingsTest.php`

- [ ] **Step 1: Write failing test**

```php
<?php
namespace Tests\Feature\Ai;

use App\Filament\Pages\AiAgentSettings;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AiAgentSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    public function test_super_admin_can_toggle_permission_on_role(): void
    {
        $sa = User::where('email','sumit@davya.local')->firstOrFail();
        $sa->assignRole('super_admin');

        Livewire::actingAs($sa)
            ->test(AiAgentSettings::class)
            ->set('rolesWithAgent', ['admin', 'head'])
            ->call('save');

        $this->assertTrue(Role::findByName('head')->hasPermissionTo('use ai-agent'));
        $this->assertTrue(Role::findByName('admin')->hasPermissionTo('use ai-agent'));
        $this->assertFalse(Role::findByName('freelancer')->hasPermissionTo('use ai-agent'));
    }

    public function test_non_super_admin_cannot_access(): void
    {
        $u = User::factory()->create();
        $u->assignRole('admin');
        auth()->login($u);
        $this->assertFalse(AiAgentSettings::canAccess());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit --filter AiAgentSettingsTest`
Expected: FAIL — page class missing.

- [ ] **Step 3: Create `app/Filament/Pages/AiAgentSettings.php`**

```php
<?php
namespace App\Filament\Pages;

use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Pages\Page;
use Spatie\Permission\Models\Role;

class AiAgentSettings extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-cog-6-tooth';
    protected static ?string $navigationGroup = 'Settings';
    protected static string $view = 'filament.pages.ai-agent-settings';
    protected static ?string $slug = 'ai-agent';
    protected static ?string $title = 'AI Agent';

    public array $rolesWithAgent = [];

    public static function canAccess(): bool
    {
        return auth()->user()?->hasRole('super_admin') ?? false;
    }

    public function mount(): void
    {
        $this->rolesWithAgent = Role::permission('use ai-agent')->pluck('name')->all();
    }

    public function form(Form $form): Form
    {
        return $form->schema([
            CheckboxList::make('rolesWithAgent')
                ->label('Roles allowed to use the AI agent')
                ->options(Role::pluck('name', 'name')->all()),
        ])->statePath('rolesWithAgent');
    }

    public function save(): void
    {
        $selected = collect($this->rolesWithAgent ?? [])->filter()->values()->all();

        foreach (Role::all() as $role) {
            if (in_array($role->name, $selected, true)) {
                $role->givePermissionTo('use ai-agent');
            } else {
                $role->revokePermissionTo('use ai-agent');
            }
        }

        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
    }

    protected function getHeaderActions(): array
    {
        return [Action::make('save')->label('Save')->action('save')];
    }
}
```

- [ ] **Step 4: Create `resources/views/filament/pages/ai-agent-settings.blade.php`**

```blade
<x-filament-panels::page>
    <form wire:submit="save">
        {{ $this->form }}
    </form>
</x-filament-panels::page>
```

- [ ] **Step 5: Run test to verify it passes**

Run: `vendor/bin/phpunit --filter AiAgentSettingsTest`
Expected: PASS, 2 tests.

- [ ] **Step 6: Commit**

```bash
git add app/Filament/Pages/AiAgentSettings.php resources/views/filament/pages/ai-agent-settings.blade.php tests/Feature/Ai/AiAgentSettingsTest.php
git commit -m "feat(ai): /admin/ai-agent settings — role × permission toggle"
```

---

## Task 13: End-to-end smoke + final wiring

**Files:**
- Test: `tests/Feature/Ai/AiEndToEndTest.php`

- [ ] **Step 1: Write the E2E smoke test**

```php
<?php
namespace Tests\Feature\Ai;

use App\Models\User;
use App\Services\Ai\LlmProvider;
use App\Services\Ai\LlmResponse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AiEndToEndTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        $root = sys_get_temp_dir().'/e2e_'.uniqid();
        mkdir($root);
        file_put_contents("$root/bba-fees.php",
            "<?php ?><title>BBA Fees</title>\n<p>BBA tuition is Rs 95,000 per semester at VIPS-TC.</p>");
        config(['ai.ipu_docroot' => $root]);

        $this->app->bind(LlmProvider::class, fn() => new class implements LlmProvider {
            public int $call = 0;
            public function chat(array $m, array $t = []): LlmResponse
            {
                $this->call++;
                if ($this->call === 1) {
                    return new LlmResponse('', [['id'=>'c1','name'=>'search_pages','arguments'=>['query'=>'BBA']]], 10, 5, 50, 'stub');
                }
                if ($this->call === 2) {
                    return new LlmResponse('', [['id'=>'c2','name'=>'read_page','arguments'=>['slug'=>'bba-fees.php']]], 10, 5, 50, 'stub');
                }
                return new LlmResponse('BBA tuition is Rs 95,000.', null, 10, 5, 50, 'stub');
            }
        });
    }

    public function test_full_round_trip_via_http(): void
    {
        $u = User::where('email','sumit@davya.local')->firstOrFail();
        $u->assignRole('admin');

        $resp = $this->actingAs($u)->postJson('/ai/ask', ['question' => 'BBA fees at VIPS-TC?']);

        $resp->assertOk();
        $this->assertStringContainsString('Rs 95,000', $resp->json('answer'));
        $this->assertStringContainsString('Source: bba-fees.php', $resp->json('answer'));
        $this->assertSame(['bba-fees.php'], $resp->json('citations'));
    }
}
```

- [ ] **Step 2: Run test to verify it passes**

Run: `vendor/bin/phpunit --filter AiEndToEndTest`
Expected: PASS, 1 test.

- [ ] **Step 3: Run the full Ai suite**

Run: `vendor/bin/phpunit --testdox tests/Feature/Ai tests/Unit/Ai`
Expected: 30+ tests PASS, 0 failures.

- [ ] **Step 4: Run the entire suite to confirm no regressions**

Run: `vendor/bin/phpunit`
Expected: all pre-existing tests still pass (current baseline: 817 tests / 3 known pre-existing form-error skipped).

- [ ] **Step 5: Commit**

```bash
git add tests/Feature/Ai/AiEndToEndTest.php
git commit -m "test(ai): end-to-end search→read→answer round-trip via HTTP"
```

- [ ] **Step 6: Tag the milestone**

```bash
git tag v-ai-agent-v1
```

---

## Deploy notes (post-implementation)

1. Append to prod `.env` (Hostinger via SSH + chmod 600):
   ```
   AI_PROVIDER=groq
   AI_GROQ_KEY=<FRESH-key-NOT-the-2026-05-05-leaked-one>
   AI_DAILY_CAP=50
   ```
2. Run full Laravel deploy recipe per `feedback_full_deploy_recipe_no_shortcuts`:
   - `git pull`
   - `composer install --no-dev --optimize-autoloader`
   - `php artisan migrate --force` (3 new ai migrations)
   - `php artisan migrate --database=ranks --path=database/migrations/ranks --force`
   - 3 rank seeders
   - `config:cache`, `route:cache`, `view:cache`
3. **FPM toggle required** per `reference_hostinger_fpm_opcache.md` — toggling MultiPHP version for `davyas.ipu.co.in` flushes OPcache so the new Filament pages + Livewire component load. Without this `/admin/ai-conversations` will 404.
4. Update `project_ipu_knowledge_agent.md` memory entry: change status from "brainstorm paused" → "shipped <date>"; drop "Resume next session" block.

---

## Out of scope (do not implement)

Spec §"Out of scope (v1)":
- Cutoffs DB integration (rank-predictor v2)
- Streaming responses
- File/image uploads
- Public ipu.co.in widget
- Embedding-based RAG
- Conversation export
- Cross-user conversation sharing
- Answer feedback buttons
