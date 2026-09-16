<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Setting extends Model
{
    protected $fillable = ['type', 'value'];

    public static function getValue($key, $default = null)
    {
        return self::where('type', $key)->value('value') ?? $default;
    }
}
