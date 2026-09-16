<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class UserArticleInteraction extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'article_id',
        'is_liked',
        'liked_at',
        'is_saved',
        'saved_at',
    ];

    protected $casts = [
        'user_id' => 'integer',
        'article_id' => 'integer',
        'is_liked' => 'boolean',
        'is_saved' => 'boolean',
        'liked_at' => 'datetime',
        'saved_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function article()
    {
        return $this->belongsTo(article::class, 'article_id');
    }
}
