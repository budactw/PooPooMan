<?php

namespace App\Models;

use App\Enum\PoopType;
use Illuminate\Database\Eloquent\Model;

class PoopRecord extends Model
{
    protected $fillable = [
        'group_id',
        'user_id',
        'user_name',
        'record_date',
        'poop_type',
    ];

    protected $casts = [
        'record_date' => 'datetime',
        'poop_type'   => PoopType::class,
    ];

}
