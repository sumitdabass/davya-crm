<?php
namespace App\Livewire;

use App\Models\AiConversation;
use App\Services\Ai\AssistantService;
use App\Services\Ai\Providers\GroqException;
use Livewire\Attributes\Locked;
use Livewire\Component;

class AiAssistantDrawer extends Component
{
    public string $input = '';
    public ?int $conversationId = null;
    public array $thread = [];
    public ?string $error = null;
    public bool $busy = false;

    public function ask(AssistantService $svc): void
    {
        $user = auth()->user();
        if (!$user || !$user->can('use ai-agent')) abort(403);

        $question = trim($this->input);
        if ($question === '') return;
        $this->error = null;
        $this->busy = true;

        $conv = $this->conversationId
            ? AiConversation::where('user_id', $user->id)->find($this->conversationId)
            : null;

        if (!$conv) {
            $conv = AiConversation::create([
                'user_id'         => $user->id,
                'title'           => mb_substr($question, 0, 60),
                'started_at'      => now(),
                'last_message_at' => now(),
            ]);
            $this->conversationId = $conv->id;
        }

        $cap = (int) config('ai.daily_cap_per_user', 50);
        $todayCount = \App\Models\AiMessage::where('role', 'user')
            ->whereHas('conversation', fn($q) => $q->where('user_id', $user->id))
            ->where('created_at', '>=', now()->startOfDay())
            ->count();
        if ($todayCount >= $cap) {
            $this->error = "Hit today's question cap ($cap). Resets midnight IST.";
            $this->busy = false;
            return;
        }

        // Optimistic UI: show the user turn immediately; AssistantService persists it.
        $this->thread[] = ['role' => 'user', 'content' => $question, 'citations' => []];

        try {
            $msg = $svc->ask($conv, $question);
            $this->thread[] = [
                'role'      => 'assistant',
                'content'   => $msg->content,
                'citations' => $msg->citations ?? [],
            ];
        } catch (GroqException $e) {
            // Service already rolled back the user message in DB; drop optimistic row too.
            array_pop($this->thread);
            $this->error = $e->status === 429
                ? 'Busy, try in a moment.'
                : 'Something went wrong. Try again.';
        }

        $this->input = '';
        $this->busy = false;
    }

    public function newChat(): void
    {
        $this->conversationId = null;
        $this->thread = [];
        $this->input = '';
        $this->error = null;
    }

    public function render()
    {
        return view('livewire.ai-assistant-drawer');
    }
}
