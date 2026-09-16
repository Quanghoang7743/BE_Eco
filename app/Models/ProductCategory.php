<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ProductCategory extends Model
{
    use HasFactory;

    public const HOME_NAV_CACHE_KEY = 'product_categories:home_nav:v1';

    protected $fillable = [
        'name',
        'slug',
        'parent_id',
        'store_id',
        'sort_order',
        'image',
        'is_active',
        'show_on_home',
        'level',
    ];

    public function store()
    {
        return $this->belongsTo(\App\Models\Store::class, 'store_id');
    }
    public function parent()
    {
        return $this->belongsTo(ProductCategory::class, 'parent_id');
    }

    /**
     * Quan hệ với các danh mục con.
     */
    public function children()
    {
        return $this->hasMany(ProductCategory::class, 'parent_id');
    }
}
