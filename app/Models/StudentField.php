<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Builder;

class StudentField extends Model
{
    protected $fillable = [
        'section_id', 'key', 'label', 'type', 'is_required', 'is_built_in',
        'built_in_column', 'options', 'show_in_table', 'show_in_kanban',
        'show_in_import', 'position', 'archived_at',
    ];

    protected $casts = [
        'is_required' => 'boolean',
        'is_built_in' => 'boolean',
        'options' => 'array',
        'show_in_table' => 'boolean',
        'show_in_kanban' => 'boolean',
        'show_in_import' => 'boolean',
        'position' => 'integer',
        'archived_at' => 'datetime',
    ];

    public function section(): BelongsTo
    {
        return $this->belongsTo(StudentFieldSection::class, 'section_id');
    }

    public function scopeActive(Builder $q): Builder
    {
        return $q->whereNull('archived_at');
    }

    public function scopeArchived(Builder $q): Builder
    {
        return $q->whereNotNull('archived_at');
    }

    public function scopeBuiltIn(Builder $q): Builder
    {
        return $q->where('is_built_in', true);
    }

    public function scopeCustom(Builder $q): Builder
    {
        return $q->where('is_built_in', false);
    }
}
