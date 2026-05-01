<?php

namespace App\Models\Rank;

use Illuminate\Database\Eloquent\Model;

class QualifyingExam extends Model
{
    protected $connection = 'ranks';

    protected $fillable = ['name', 'code'];
}
