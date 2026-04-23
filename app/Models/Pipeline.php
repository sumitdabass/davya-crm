<?php
// app/Models/Pipeline.php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Pipeline extends Model
{
    protected $fillable = ['name','icon','record_label','is_default'];

    protected $casts = ['is_default' => 'bool'];

    public function stages(): HasMany
    {
        return $this->hasMany(Stage::class)->orderBy('display_order');
    }

    public function transitionRules(): HasMany
    {
        return $this->hasMany(StageTransitionRule::class);
    }

    public static function default(): self
    {
        return self::where('is_default', true)->firstOrFail();
    }
}
