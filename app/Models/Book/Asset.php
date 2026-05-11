<?php

namespace App\Models\Book;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Asset extends Model
{
    use HasFactory;

    public const METHODS = ['straight_line', 'wdv'];

    protected $table = 'book_assets';

    protected $fillable = [
        'entry_id',
        'original_value',
        'dep_percent',
        'dep_years',
        'dep_started_at',
        'method',
    ];

    protected $casts = [
        'dep_started_at' => 'date',
        'original_value' => 'decimal:2',
        'dep_percent' => 'decimal:2',
        'dep_years' => 'int',
    ];

    protected static function booted(): void
    {
        static::saving(function (Asset $a) {
            if (! in_array($a->method, self::METHODS, true)) {
                throw new \InvalidArgumentException("Invalid method: {$a->method}");
            }
        });
    }

    public function entry(): BelongsTo
    {
        return $this->belongsTo(Entry::class);
    }

    protected static function newFactory()
    {
        return \Database\Factories\Book\AssetFactory::new();
    }
}
