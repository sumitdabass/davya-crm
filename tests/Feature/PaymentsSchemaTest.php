<?php
namespace Tests\Feature;

use App\Models\Payment;
use App\Models\Student;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PaymentsSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_payments_table_has_slack_columns(): void
    {
        $this->assertTrue(Schema::hasColumn('payments', 'slack_message_id'));
        $this->assertTrue(Schema::hasColumn('payments', 'raw_input'));
    }

    public function test_payments_recorded_by_user_id_is_nullable(): void
    {
        $this->seed();
        $sumit = User::where('email','sumit@davya.local')->first();
        $student = Student::create([
            'phone' => '9000000001', 'name' => 'T',
            'owner_id' => $sumit->id, 'referrer_id' => $sumit->id,
            'lead_source' => 'Sumit', 'stage' => 'Lead Captured',
        ]);
        $p = Payment::create([
            'student_id' => $student->id, 'type' => 'full',
            'amount' => 100, 'received_at' => now(),
            'recorded_by_user_id' => null,
            'slack_message_id' => 'C1.1111.1',
            'raw_input' => 'got 100 from T',
        ]);
        $this->assertNull($p->fresh()->recorded_by_user_id);
    }

    public function test_payments_slack_message_id_is_unique(): void
    {
        $this->seed();
        $sumit = User::where('email','sumit@davya.local')->first();
        $student = Student::create([
            'phone' => '9000000002','name' => 'T',
            'owner_id' => $sumit->id,'referrer_id' => $sumit->id,
            'lead_source'=>'Sumit','stage'=>'Lead Captured',
        ]);
        Payment::create([
            'student_id'=>$student->id,'type'=>'full','amount'=>100,
            'received_at'=>now(),'recorded_by_user_id'=>null,
            'slack_message_id'=>'C1.2222.1','raw_input'=>'x',
        ]);
        $this->expectException(QueryException::class);
        Payment::create([
            'student_id'=>$student->id,'type'=>'full','amount'=>200,
            'received_at'=>now(),'recorded_by_user_id'=>null,
            'slack_message_id'=>'C1.2222.1','raw_input'=>'y',
        ]);
    }
}
