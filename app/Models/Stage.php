<?php
// app/Models/Stage.php
namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Stage extends Model
{
    public const TYPE_OPEN = 'OPEN';
    public const TYPE_WON  = 'CLOSED_WON';
    public const TYPE_LOST = 'CLOSED_LOST';
    public const TYPES = [self::TYPE_OPEN, self::TYPE_WON, self::TYPE_LOST];

    protected $fillable = ['pipeline_id','name','description','stage_type','display_order','color'];

    public function pipeline(): BelongsTo
    {
        return $this->belongsTo(Pipeline::class);
    }

    public function students(): HasMany
    {
        return $this->hasMany(Student::class);
    }

    public function scopeOpenStages(Builder $q): Builder  { return $q->where('stage_type', self::TYPE_OPEN); }
    public function scopeWonStages(Builder $q): Builder   { return $q->where('stage_type', self::TYPE_WON); }
    public function scopeLostStages(Builder $q): Builder  { return $q->where('stage_type', self::TYPE_LOST); }

    public function isTerminal(): bool
    {
        return in_array($this->stage_type, [self::TYPE_WON, self::TYPE_LOST], true);
    }
}
