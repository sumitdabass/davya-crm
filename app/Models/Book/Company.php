<?php

namespace App\Models\Book;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Company extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'book_companies';

    protected $fillable = ['name', 'slug', 'currency', 'timezone'];

    protected $attributes = [
        'currency' => 'INR',
        'timezone' => 'Asia/Kolkata',
    ];

    protected static function booted(): void
    {
        static::saving(function (Company $c) {
            if ($c->currency !== 'INR') {
                throw new \InvalidArgumentException('Books v1 is INR-only');
            }
        });
    }

    protected static function newFactory()
    {
        return \Database\Factories\Book\CompanyFactory::new();
    }

    public function sections(): HasMany
    {
        return $this->hasMany(Section::class);
    }
}
