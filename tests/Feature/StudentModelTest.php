<?php

namespace Tests\Feature;

use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class StudentModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_ipu_password_is_encrypted_at_rest_but_decrypted_on_access(): void
    {
        $this->seed();
        $sumit = User::where('email', 'sumit@davya.local')->first();
        $student = Student::create([
            'phone' => '9999999999',
            'name' => 'Test Student',
            'owner_id' => $sumit->id,
            'referrer_id' => $sumit->id,
            'lead_source' => 'Sumit',
            'ipu_password' => 'secret-pw',
        ]);

        $rawValue = DB::table('students')->where('id', $student->id)->value('ipu_password');
        $this->assertNotEquals('secret-pw', $rawValue);
        $this->assertEquals('secret-pw', $student->fresh()->ipu_password);
    }
}
