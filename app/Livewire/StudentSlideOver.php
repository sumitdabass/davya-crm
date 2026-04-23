<?php

namespace App\Livewire;

use App\Dashboard\Card;
use App\Dashboard\CardRegistry;
use App\Dashboard\DrillDownPayload;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\WithPagination;

class StudentSlideOver extends Component
{
    use WithPagination;

    public bool $isOpen = false;
    public ?string $cardId = null;
    public string $search = '';

    #[On('open-slide-over')]
    public function open(string $cardId): void
    {
        $card = CardRegistry::find($cardId);
        if ($card === null || $card->type() !== 'stat') {
            return;
        }
        $this->cardId = $cardId;
        $this->isOpen = true;
        $this->resetPage();
    }

    public function close(): void
    {
        $this->isOpen = false;
        $this->cardId = null;
        $this->search = '';
    }

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function render()
    {
        $payload = $this->payload();
        $rows = collect();
        $viewAllHref = null;

        if ($payload !== null) {
            $query = clone $payload->query;
            if ($this->search !== '') {
                $query->where(function ($q) {
                    $q->where('name', 'like', '%'.$this->search.'%')
                      ->orWhere('phone', 'like', '%'.$this->search.'%');
                });
            }
            $rows = $query->with(['owner'])->paginate(20);
            $viewAllHref = $this->cardFromId()?->viewAllHref(auth()->user());
        }

        return view('livewire.student-slide-over', [
            'payload' => $payload,
            'rows' => $rows,
            'viewAllHref' => $viewAllHref,
        ]);
    }

    private function cardFromId(): ?Card
    {
        return $this->cardId ? CardRegistry::find($this->cardId) : null;
    }

    private function payload(): ?DrillDownPayload
    {
        $card = $this->cardFromId();
        return $card?->drillDown(auth()->user());
    }
}
