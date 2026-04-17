<?php

namespace Tests\Feature;

use App\Filament\Pages\PaymentReport;
use App\Models\Payment;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class PaymentReportTest extends TestCase
{
    use RefreshDatabase;

    public function test_report_aggregates_received_refunds_and_count_by_owner(): void
    {
        $this->seed();
        $nikhil = User::where('email', 'nikhil@davya.local')->first();
        $nisha  = User::where('email', 'nisha@davya.local')->first();

        $a = Student::create([
            'phone' => '9100007001', 'name' => 'A',
            'owner_id' => $nikhil->id, 'referrer_id' => $nikhil->id,
            'lead_source' => 'Nikhil', 'stage' => 'Onboarded',
        ]);
        $b = Student::create([
            'phone' => '9100007002', 'name' => 'B',
            'owner_id' => $nisha->id, 'referrer_id' => $nisha->id,
            'lead_source' => 'Nisha', 'stage' => 'Onboarded',
        ]);

        Payment::create([
            'student_id' => $a->id, 'type' => 'advance', 'amount' => 30000,
            'received_at' => Carbon::now()->startOfMonth()->addDays(1), 'recorded_by_user_id' => $nikhil->id,
        ]);
        Payment::create([
            'student_id' => $a->id, 'type' => 'refund', 'amount' => 5000, // will be flipped to -5000 by Payment saving hook
            'received_at' => Carbon::now()->startOfMonth()->addDays(2), 'recorded_by_user_id' => $nikhil->id,
        ]);
        Payment::create([
            'student_id' => $b->id, 'type' => 'partial', 'amount' => 15000,
            'received_at' => Carbon::now()->startOfMonth()->addDays(3), 'recorded_by_user_id' => $nisha->id,
        ]);

        $page = new PaymentReport;
        $page->data = [
            'from' => Carbon::now()->startOfMonth()->toDateString(),
            'to'   => Carbon::now()->endOfMonth()->toDateString(),
            'owner_id' => null,
        ];

        $r = $page->getReport();

        $this->assertSame(3, $r['totals']['count']);
        $this->assertEqualsWithDelta(45000.0, $r['totals']['received'], 0.01);
        $this->assertEqualsWithDelta(-5000.0, $r['totals']['refunds'], 0.01);
        $this->assertEqualsWithDelta(40000.0, $r['totals']['net'], 0.01);

        $this->assertArrayHasKey($nikhil->id, $r['byOwner']);
        $this->assertEqualsWithDelta(30000.0, $r['byOwner'][$nikhil->id]['received'], 0.01);
        $this->assertEqualsWithDelta(-5000.0, $r['byOwner'][$nikhil->id]['refunds'], 0.01);
        $this->assertSame(2, $r['byOwner'][$nikhil->id]['count']);

        $this->assertArrayHasKey($nisha->id, $r['byOwner']);
        $this->assertEqualsWithDelta(15000.0, $r['byOwner'][$nisha->id]['received'], 0.01);
    }

    public function test_report_respects_owner_filter(): void
    {
        $this->seed();
        $nikhil = User::where('email', 'nikhil@davya.local')->first();
        $nisha  = User::where('email', 'nisha@davya.local')->first();

        $a = Student::create([
            'phone' => '9100007010', 'name' => 'A',
            'owner_id' => $nikhil->id, 'referrer_id' => $nikhil->id,
            'lead_source' => 'Nikhil', 'stage' => 'Onboarded',
        ]);
        Payment::create([
            'student_id' => $a->id, 'type' => 'advance', 'amount' => 10000,
            'received_at' => now(), 'recorded_by_user_id' => $nikhil->id,
        ]);
        $b = Student::create([
            'phone' => '9100007011', 'name' => 'B',
            'owner_id' => $nisha->id, 'referrer_id' => $nisha->id,
            'lead_source' => 'Nisha', 'stage' => 'Onboarded',
        ]);
        Payment::create([
            'student_id' => $b->id, 'type' => 'advance', 'amount' => 20000,
            'received_at' => now(), 'recorded_by_user_id' => $nisha->id,
        ]);

        $page = new PaymentReport;
        $page->data = [
            'from' => now()->startOfMonth()->toDateString(),
            'to'   => now()->endOfMonth()->toDateString(),
            'owner_id' => $nikhil->id,
        ];

        $r = $page->getReport();
        $this->assertEqualsWithDelta(10000.0, $r['totals']['received'], 0.01);
        $this->assertSame(1, $r['totals']['count']);
    }
}
