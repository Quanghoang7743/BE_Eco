<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Attribute extends Model
{
    use HasFactory;
    protected $fillable = ['store_id', 'name'];

    public function store()
    {
        return $this->belongsTo(Store::class, 'store_id');
    }

    public function values()
    {
        return $this->hasMany(AttributeValue::class);
    }

}
