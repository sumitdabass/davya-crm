<?php

namespace Tests\Feature\MobileToday;

use App\Dashboard\CardRegistry;
use App\Dashboard\Cards\ListCards\PaymentsToChaseCard;
use App\Models\Payment;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PaymentsToChaseCardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        CardRegistry::reset();
    }

    private function admin(): User
    {
        return User::where('email', 'sumit@davya.local')->first();
    }

    public function test_card_is_registered_and_defaults_on_today_only(): void
    {
        $card = CardRegistry::find('payments_to_chase');

        $this->assertInstanceOf(PaymentsToChaseCard::class, $card);
        $this->assertSame('list', $card->type());
        $this->assertTrue($card->isDefaultOn('today'));
        $this->assertFalse($card->isDefaultOn('dashboard'));
    }

    public function test_query_returns_students_with_pending_balance_and_excludes_closed_and_fully_paid(): void
    {
        $viewer = $this->admin();

        $pending = Student::factory()->create(['deal_amount' => 50000, 'stage' => 'Advance Received']);
        Payment::factory()->create(['student_id' => $pending->id, 'amount' => 10000]);

        $fullyPaid = Student::factory()->create(['deal_amount' => 30000, 'stage' => 'Advance Received']);
        Payment::factory()->create(['student_id' => $fullyPaid->id, 'amount' => 30000]);

        $closed = Student::factory()->create(['deal_amount' => 20000, 'stage' => 'Closed']);

        $ids = (new PaymentsToChaseCard())->query($viewer)->pluck('id')->all();

        $this->assertContains($pending->id, $ids);
        $this->assertNotContains($fullyPaid->id, $ids);
        $this->assertNotContains($closed->id, $ids);
    }
}
