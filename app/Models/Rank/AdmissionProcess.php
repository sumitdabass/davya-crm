<?php

namespace App\Models\Rank;

use Illuminate\Database\Eloquent\Model;

class AdmissionProcess extends Model
{
    protected $connection = 'ranks';

    protected $fillable = ['name', 'code'];
}
