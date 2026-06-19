<?php

namespace Tests\Feature;

use App\Models\Note;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class NoteCaptureTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'test-finance-token-abcdef0123456789';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        config(['finance.capture_token' => self::TOKEN]);
    }

    private function postPayload(array $overrides = [], ?string $token = self::TOKEN)
    {
        $payload = array_merge([
            'body' => 'Paid electrician advance, adjust next month',
            'slack_message_id' => 'N.'.uniqid(),
            'raw_input' => 'note Paid electrician advance, adjust next month',
        ], $overrides);
        $headers = $token === null ? [] : ['X-Finance-Token' => $token];
        return $this->postJson('/api/finance/notes', $payload, $headers);
    }

    public function test_happy_path_creates_note(): void
    {
        $this->postPayload()->assertCreated();
        $n = Note::first();
        $this->assertNotNull($n);
        $this->assertSame('Paid electrician advance, adjust next month', $n->body);
        $this->assertNotNull($n->slack_message_id);
    }

    public function test_missing_token_returns_401(): void
    {
        $this->postPayload([], token: null)->assertStatus(401);
    }

    public function test_missing_body_returns_422(): void
    {
        $this->postPayload(['body' => null])->assertStatus(422)->assertJsonValidationErrors('body');
    }

    public function test_missing_slack_message_id_returns_422(): void
    {
        $this->postPayload(['slack_message_id' => null])->assertStatus(422)->assertJsonValidationErrors('slack_message_id');
    }

    public function test_duplicate_slack_message_id_returns_409(): void
    {
        $first = $this->postPayload(['slack_message_id' => 'N.DUPE']);
        $first->assertCreated();
        $this->postPayload(['slack_message_id' => 'N.DUPE'])
            ->assertStatus(409)
            ->assertJson(['error' => 'duplicate_slack_message', 'existing_id' => $first->json('id')]);
    }

    public function test_slack_message_id_race_returns_409_not_500(): void
    {
        $slackId = 'N.RACE';
        $raced = false;
        DB::listen(function ($q) use (&$raced, $slackId) {
            if ($raced) return;
            if (!str_contains($q->sql, 'notes')) return;
            if (!str_starts_with(strtolower(ltrim($q->sql)), 'select')) return;
            if (!in_array($slackId, $q->bindings, true)) return;
            $raced = true;
            DB::table('notes')->insert([
                'body'             => 'raced',
                'noted_at'         => now(),
                'slack_message_id' => $slackId,
                'created_at'       => now(),
                'updated_at'       => now(),
            ]);
        });
        $resp = $this->postPayload(['slack_message_id' => $slackId]);
        $resp->assertStatus(409)->assertJson(['error' => 'duplicate_slack_message']);
        $this->assertNotNull($resp->json('existing_id'));
        $this->assertSame(1, Note::where('slack_message_id', $slackId)->count());
    }
}
