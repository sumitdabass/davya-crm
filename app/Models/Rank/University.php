<?php

namespace App\Models\Rank;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class University extends Model
{
    protected $connection = 'ranks';

    protected $fillable = ['name', 'code', 'official_website', 'country', 'state'];

    public function institutes(): HasMany
    {
        return $this->hasMany(Institute::class);
    }

    public function courses(): HasMany
    {
        return $this->hasMany(Course::class);
    }
}
