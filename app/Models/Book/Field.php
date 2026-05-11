<?php

namespace App\Models\Book;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class Field extends Model
{
    use HasFactory, LogsActivity;

    public const TYPES = ['text', 'textarea', 'number', 'date', 'email', 'dropdown', 'checkbox', 'multiselect', 'file'];

    protected $table = 'book_fields';

    protected $fillable = [
        'company_id',
        'section_id',
        'key',
        'label',
        'type',
        'options_json',
        'is_required',
        'show_in_table',
        'is_built_in',
        'sort_order',
        'archived_at',
    ];

    protected $casts = [
        'options_json' => 'array',
        'is_required' => 'bool',
        'show_in_table' => 'bool',
        'is_built_in' => 'bool',
        'archived_at' => 'datetime',
    ];

    public function section(): BelongsTo
    {
        return $this->belongsTo(Section::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    protected static function newFactory()
    {
        return \Database\Factories\Book\FieldFactory::new();
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logFillable()
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('books');
    }
}
