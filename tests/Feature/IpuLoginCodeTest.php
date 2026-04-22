<?php

namespace Tests\Feature;

use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class IpuLoginCodeTest extends TestCase
{
    use RefreshDatabase;

    public function test_column_is_renamed(): void
    {
        $this->assertFalse(Schema::hasColumn('students', 'ipu_password'));
        $this->assertTrue(Schema::hasColumn('students', 'ipu_login_code'));
    }

    public function test_value_is_stored_plain_text(): void
    {
        $this->seed();
        $owner = User::first();
        $s = Student::create([
            'phone' => '9999900200', 'name' => 'Test',
            'owner_id' => $owner->id, 'referrer_id' => null, 'lead_source' => 'Website',
            'ipu_login_code' => 'plain-value-123',
        ]);

        $raw = \DB::table('students')->where('id', $s->id)->value('ipu_login_code');
        $this->assertSame('plain-value-123', $raw, 'must NOT be encrypted');
        $this->assertSame('plain-value-123', $s->fresh()->ipu_login_code);
    }

    public function test_reveal_action_class_is_deleted(): void
    {
        $this->assertFalse(class_exists(\App\Actions\RevealIpuPassword::class));
    }
}
