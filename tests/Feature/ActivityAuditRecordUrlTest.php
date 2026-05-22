<?php

namespace Tests\Feature;

use App\Filament\Pages\ActivityAudit;
use App\Models\Book\Company;
use App\Models\Book\Entry;
use App\Models\Book\EntryPayment;
use App\Models\Book\FiscalYear;
use App\Models\Meeting;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

class ActivityAuditRecordUrlTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    public function test_null_subject_id_returns_null(): void
    {
        $a = new Activity(['subject_type' => Student::class, 'subject_id' => null]);
        $this->assertNull(ActivityAudit::resolveRecordUrl($a));
    }

    public function test_student_subject_returns_student_edit_url(): void
    {
        $sumit = User::where('email', 'sumit@davya.local')->firstOrFail();
        $student = Student::create([
            'phone' => '9999900001', 'name' => 'X',
            'owner_id' => $sumit->id, 'lead_source' => 'Test',
        ]);
        $a = new Activity(['subject_type' => Student::class, 'subject_id' => $student->id]);

        $this->assertStringContainsString("/admin/students/{$student->id}/edit", (string) ActivityAudit::resolveRecordUrl($a));
    }

    public function test_meeting_subject_returns_student_edit_url(): void
    {
        $sumit = User::where('email', 'sumit@davya.local')->firstOrFail();
        $student = Student::create([
            'phone' => '9999900002', 'name' => 'Y',
            'owner_id' => $sumit->id, 'lead_source' => 'Test',
        ]);
        $meeting = Meeting::create([
            'student_id' => $student->id,
            'owner_id' => $sumit->id,
            'created_by_id' => $sumit->id,
            'scheduled_at' => now()->addDay(),
        ]);
        $a = new Activity(['subject_type' => Meeting::class, 'subject_id' => $meeting->id]);

        $this->assertStringContainsString("/admin/students/{$student->id}/edit", (string) ActivityAudit::resolveRecordUrl($a));
    }

    public function test_entry_payment_subject_returns_books_section_url(): void
    {
        $c = Company::create(['name' => 'A', 'slug' => 'a']);
        $fy = FiscalYear::create(['company_id' => $c->id,
            'start_date' => '2025-04-01', 'end_date' => '2026-03-31', 'label' => '2025-26']);
        $section = $c->sections()->where('slug', 'loan')->first();
        $entry = Entry::create([
            'company_id' => $c->id, 'fiscal_year_id' => $fy->id,
            'section_id' => $section->id, 'title' => 'X',
        ]);
        $payment = EntryPayment::create([
            'entry_id' => $entry->id, 'amount' => 100, 'direction' => 'in',
            'mode' => 'cash', 'occurred_on' => '2025-06-01',
        ]);
        $a = new Activity(['subject_type' => EntryPayment::class, 'subject_id' => $payment->id]);

        $this->assertSame('/admin/books/a/2025-26/section/loan', ActivityAudit::resolveRecordUrl($a));
    }

    public function test_unknown_subject_type_returns_null(): void
    {
        $a = new Activity(['subject_type' => 'App\\Models\\Nonexistent', 'subject_id' => 1]);
        $this->assertNull(ActivityAudit::resolveRecordUrl($a));
    }
}
