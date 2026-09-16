<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class article extends Model
{
    use HasFactory;
    protected $fillable = [
        'title',
        'slug',
        'summary',
        'content',
        'category_id',
        'display_area',
        'sort_order',
        'is_hot',
        'image',
        'pdf_path',
        'additional_image',
        'published_at',
        'view_count',
        'share_count',
    ];

    protected $casts = [
        'published_at' => 'datetime',
        'is_hot' => 'boolean',
    ];

    // Quan he voi chuyen muc (category)
    public function category()
    {
        return $this->belongsTo(Category::class, 'category_id');
    }

}
