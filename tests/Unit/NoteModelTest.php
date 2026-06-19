<?php
namespace Tests\Unit;

use App\Models\Note;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NoteModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_note_casts_noted_at_as_datetime(): void
    {
        $n = Note::create([
            'body' => 'Paid electrician advance',
            'noted_at' => '2026-06-19 10:00:00',
            'slack_message_id' => 'N2.1.1',
            'raw_input' => 'note Paid electrician advance',
        ]);
        $fresh = $n->fresh();
        $this->assertSame('Paid electrician advance', $fresh->body);
        $this->assertInstanceOf(\Illuminate\Support\Carbon::class, $fresh->noted_at);
    }

    public function test_manual_note_renders_D_prefix(): void
    {
        $n = Note::create(['body' => 'manual note', 'noted_at' => now(), 'slack_message_id' => null]);
        $this->assertSame("D{$n->id}", $n->display_id, 'manual rows must use D prefix');
    }

    public function test_slack_note_renders_hash_prefix(): void
    {
        $n = Note::create(['body' => 'from slack', 'noted_at' => now(), 'slack_message_id' => '1776767527.655079']);
        $this->assertSame("#{$n->id}", $n->display_id, 'slack rows must use # prefix');
    }
}
