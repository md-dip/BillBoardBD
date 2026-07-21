<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Billboard extends Model
{
    protected $fillable = [
    'title', 'description', 'latitude', 'longitude', 'address',
    'size', 'type', 'daily_rate', 'pricing_mode', 'monthly_rate',
    'photo', 'rating', 'status',
];
}
