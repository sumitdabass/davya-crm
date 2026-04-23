<?php
// app/Models/StageTransitionCondition.php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StageTransitionCondition extends Model
{
    public const TYPE_FIELD_CHECK  = 'FIELD_CHECK';
    public const TYPE_HAS_RELATION = 'HAS_RELATION';

    protected $fillable = ['rule_id','condition_type','field_or_relation','operator','value','display_order'];

    protected $casts = ['value' => 'array'];

    public function rule(): BelongsTo { return $this->belongsTo(StageTransitionRule::class, 'rule_id'); }
}
