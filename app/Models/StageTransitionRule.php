<?php
// app/Models/StageTransitionRule.php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StageTransitionRule extends Model
{
    public const SEV_HARD = 'HARD';
    public const SEV_SOFT = 'SOFT';

    protected $fillable = ['pipeline_id','name','from_stage_id','to_stage_id','severity','is_active'];

    protected $casts = ['is_active' => 'bool'];

    public function pipeline(): BelongsTo { return $this->belongsTo(Pipeline::class); }
    public function fromStage(): BelongsTo { return $this->belongsTo(Stage::class, 'from_stage_id'); }
    public function toStage(): BelongsTo   { return $this->belongsTo(Stage::class, 'to_stage_id'); }
    public function conditions(): HasMany  { return $this->hasMany(StageTransitionCondition::class, 'rule_id')->orderBy('display_order'); }
}
