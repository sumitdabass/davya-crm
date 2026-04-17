<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LedgerEntry extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'delta_amount' => 'decimal:2',
        'created_at'   => 'datetime',
    ];

    public static function balanceFor(string $account): string
    {
        return (string) (self::where('account', $account)->sum('delta_amount') ?: '0.00');
    }
}
