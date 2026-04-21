<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class StudentsMultiSheetColumnsTest extends TestCase
{
    use RefreshDatabase;

    public function test_students_table_has_rank_state_and_email_columns(): void
    {
        $this->assertTrue(Schema::hasColumn('students', 'rank'));
        $this->assertTrue(Schema::hasColumn('students', 'state'));
        $this->assertTrue(Schema::hasColumn('students', 'email'));
    }

    public function test_students_name_column_is_nullable(): void
    {
        $admin = \App\Models\User::factory()->create();
        \DB::table('students')->insert([
            'phone' => '9000000001',
            'name' => null,
            'owner_id' => $admin->id,
            'stage' => 'Lead Captured',
            'lead_source' => 'Test',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->assertDatabaseHas('students', ['phone' => '9000000001', 'name' => null]);
    }
}
