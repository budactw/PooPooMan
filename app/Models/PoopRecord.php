<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PoopRecord extends Model
{
    protected $fillable = [
        'group_id',
        'user_id',
        'user_name',
        'record_date',
    ];
}
