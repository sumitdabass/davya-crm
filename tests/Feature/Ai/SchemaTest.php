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
