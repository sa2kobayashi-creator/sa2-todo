<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class HolidayEntry extends Model
{
    protected $fillable = ['date', 'name', 'source'];

    protected function casts(): array
    {
        return ['date' => 'date:Y-m-d'];
    }
}
