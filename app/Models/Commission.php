<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Commission extends Model
{
    use HasFactory;
    protected $fillable = [
        'category_id',
        'level',
        'percentage'
    ];
    public function category()
    {
        return $this->belongsTo(ProductCategory::class);
    }
}
