<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Investment extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected $casts = [
        'amount'        => 'decimal:2',
        'transacted_at' => 'datetime',
    ];

    public function getDisplayIdAttribute(): string
    {
        return $this->slack_message_id === null ? "D{$this->id}" : "#{$this->id}";
    }
}
