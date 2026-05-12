<?php

namespace App\Models\Book;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class Section extends Model
{
    use HasFactory, LogsActivity, SoftDeletes;

    public const DEFAULT_COLUMNS = [
        'salary' => ['salary', 'paid', 'balance'],
        'loan' => ['loan', 'received_back', 'loan_outstanding'],
        'loans_taken' => ['loan', 'repaid', 'loan_outstanding_taken'],
        'rent' => ['paid'],
        'expense' => ['paid'],
        'asset' => ['original_value', 'this_year_dep', 'accumulated_dep', 'book_value'],
        'assets' => ['original_value', 'this_year_dep', 'accumulated_dep', 'book_value'],
    ];

    protected $table = 'book_sections';

    protected $fillable = [
        'company_id',
        'slug',
        'name',
        'kind',
        'sort_order',
        'icon',
        'visible_money_columns',
    ];

    protected $casts = [
        'visible_money_columns' => 'array',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function getVisibleMoneyColumnsAttribute($value): array
    {
        if ($value === null) {
            return self::DEFAULT_COLUMNS[$this->slug] ?? ['paid'];
        }

        return is_array($value) ? $value : json_decode($value, true);
    }

    protected static function newFactory()
    {
        return \Database\Factories\Book\SectionFactory::new();
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
