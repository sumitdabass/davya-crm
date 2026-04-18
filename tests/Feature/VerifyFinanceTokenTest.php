<?php
namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class VerifyFinanceTokenTest extends TestCase
{
    private const TOKEN = 'test-finance-token-abcdef0123456789';

    protected function setUp(): void
    {
        parent::setUp();
        config(['finance.capture_token' => self::TOKEN]);
        Route::post('/__test-finance', fn () => response()->json(['ok' => true]))
            ->middleware(\App\Http\Middleware\VerifyFinanceToken::class);
    }

    public function test_valid_token_passes(): void
    {
        $this->postJson('/__test-finance', [], ['X-Finance-Token' => self::TOKEN])
             ->assertOk()->assertJson(['ok' => true]);
    }

    public function test_missing_token_returns_401(): void
    {
        $this->postJson('/__test-finance', [])->assertStatus(401)->assertJson(['error'=>'unauthorized']);
    }

    public function test_wrong_token_returns_401(): void
    {
        $this->postJson('/__test-finance', [], ['X-Finance-Token' => 'wrong'])
             ->assertStatus(401)->assertJson(['error'=>'unauthorized']);
    }
}
