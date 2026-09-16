<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class UserStorePreference extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'store_id',
        'is_following',
        'followed_at',
        'is_favorite',
        'favorited_at',
    ];

    protected $casts = [
        'is_following' => 'boolean',
        'followed_at' => 'datetime',
        'is_favorite' => 'boolean',
        'favorited_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function store()
    {
        return $this->belongsTo(Store::class, 'store_id');
    }
}
