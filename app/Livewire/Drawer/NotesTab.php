<?php

namespace App\Livewire\Drawer;

use App\Models\StudentNote;
use Livewire\Component;

class NotesTab extends Component
{
    public int $studentId;
    public string $body = '';

    public function save(): void
    {
        if (trim($this->body) === '') {
            return;
        }
        StudentNote::create([
            'student_id' => $this->studentId,
            'author_id'  => auth()->id(),
            'body'       => $this->body,
        ]);
        $this->body = '';
    }

    public function render()
    {
        $notes = StudentNote::where('student_id', $this->studentId)
            ->with('author')
            ->orderByDesc('created_at')
            ->get();
        return view('livewire.drawer.notes-tab', compact('notes'));
    }
}
