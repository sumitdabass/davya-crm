<?php

namespace App\Models\Rank;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Institute extends Model
{
    protected $connection = 'ranks';

    protected $fillable = ['university_id', 'name', 'code', 'official_website', 'city'];

    public function university(): BelongsTo
    {
        return $this->belongsTo(University::class);
    }
}
