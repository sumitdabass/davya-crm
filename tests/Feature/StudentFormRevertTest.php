<?php

namespace Tests\Feature;

use App\Filament\Resources\StudentResource\Pages\CreateStudent;
use App\Filament\Resources\StudentResource\Pages\EditStudent;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class StudentFormRevertTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_and_edit_student_pages_mount_after_deal_tab_revert(): void
    {
        $this->seed();
        $sumit = User::where('email', 'sumit@davya.local')->firstOrFail();
        $this->actingAs($sumit);

        Livewire::test(CreateStudent::class)->assertSuccessful();

        $student = Student::create([
            'phone' => '9100000077',
            'name' => 'RevertTester',
            'owner_id' => $sumit->id,
            'referrer_id' => $sumit->id,
            'lead_source' => 'Sumit',
        ]);

        Livewire::test(EditStudent::class, ['record' => $student->getRouteKey()])->assertSuccessful();
    }

    public function test_deal_tab_no_longer_defines_a_payouts_repeater(): void
    {
        $source = file_get_contents(app_path('Filament/Resources/StudentResource.php'));
        $this->assertStringNotContainsString("Repeater::make('payouts')", $source);
        $this->assertStringNotContainsString("Placeholder::make('expected_profit_preview')", $source);
        $this->assertStringNotContainsString("Action::make('addPayment')", $source);
    }

    public function test_stage_section_shows_money_summary_on_existing_student(): void
    {
        $this->seed();
        $sumit = User::where('email', 'sumit@davya.local')->firstOrFail();
        $this->actingAs($sumit);

        $student = Student::create([
            'phone' => '9100000066', 'name' => 'SummaryTester', 'deal_amount' => 100000,
            'owner_id' => $sumit->id, 'referrer_id' => $sumit->id, 'lead_source' => 'Sumit',
        ]);
        $student->payouts()->create([
            'payee_type' => 'college', 'amount' => 30000, 'status' => 'to_pay',
            'recorded_by_user_id' => $sumit->id,
        ]);

        Livewire::test(EditStudent::class, ['record' => $student->getRouteKey()])
            ->assertSuccessful()
            ->assertSee('profit')
            ->assertSee('received')
            ->assertSeeHtml('data-testid="student-money-summary"');
    }

    public function test_stage_summary_shows_payout_paid_and_to_pay_breakdown(): void
    {
        $this->seed();
        $sumit = User::where('email', 'sumit@davya.local')->firstOrFail();
        $this->actingAs($sumit);

        // Deal 10L, total payout commitment 10L: pay 2L now, 8L still to pay.
        $student = Student::create([
            'phone' => '9100000077', 'name' => 'PayoutBreakdown', 'deal_amount' => 1000000,
            'owner_id' => $sumit->id, 'referrer_id' => $sumit->id, 'lead_source' => 'Sumit',
        ]);
        $student->payouts()->create([
            'payee_type' => 'college', 'amount' => 200000, 'status' => 'paid',
            'recorded_by_user_id' => $sumit->id,
        ]);
        $student->payouts()->create([
            'payee_type' => 'college', 'amount' => 800000, 'status' => 'to_pay',
            'recorded_by_user_id' => $sumit->id,
        ]);

        $this->assertSame(200000.0, $student->payouts_paid);
        $this->assertSame(800000.0, $student->payouts_outstanding);

        Livewire::test(EditStudent::class, ['record' => $student->getRouteKey()])
            ->assertSuccessful()
            ->assertSee('paid out')
            ->assertSee('to pay');

        // Mark the remaining payout paid → paid jumps to full, to-pay drops to zero.
        $student->payouts()->where('status', 'to_pay')->first()->update(['status' => 'paid']);
        $student->refresh();
        $this->assertSame(1000000.0, $student->payouts_paid);
        $this->assertSame(0.0, $student->payouts_outstanding);
    }

    public function test_stage_summary_segments_are_clickable(): void
    {
        $this->seed();
        $sumit = User::where('email', 'sumit@davya.local')->firstOrFail();
        $this->actingAs($sumit);

        $student = Student::create([
            'phone' => '9100000055', 'name' => 'ClickSummary', 'deal_amount' => 100000,
            'owner_id' => $sumit->id, 'referrer_id' => $sumit->id, 'lead_source' => 'Sumit',
        ]);

        Livewire::test(EditStudent::class, ['record' => $student->getRouteKey()])
            ->assertSuccessful()
            ->assertSeeHtml('wire:click="mountAction(\'editDeal\')"')
            ->assertSeeHtml('wire:click="mountAction(\'managePayment\')"')
            ->assertSeeHtml('wire:click="mountAction(\'managePayout\')"');
    }
}
