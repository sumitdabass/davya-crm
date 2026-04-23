<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StudentFieldSection extends Model
{
    protected $fillable = ['name', 'position'];
    protected $casts = ['position' => 'integer'];
}
