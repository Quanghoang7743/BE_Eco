<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Menu extends Model
{
    use HasFactory;

    protected $fillable = [
        'title',
        'url',
        'parent_id',
        'order',
        'active',
        'type',
        'reference_id'
    ];


    public function children()
    {
        return $this->hasMany(Menu::class, 'parent_id')->with('children')->orderBy('order');
    }

    public function parent()
    {
        return $this->belongsTo(Menu::class, 'parent_id');
    }

    public function category()
    {
        return $this->belongsTo(Category::class, 'reference_id');
    }

    public function article()
    {
        return $this->belongsTo(article::class, 'reference_id');
    }

    /**
     * Generate link for the menu item.
     */
    public function link()
    {
        if ($this->type === 'category') {
            if ($this->category) {
                return '/category/' . $this->category->slug;
            }
        } elseif ($this->type === 'post') {
            if ($this->article) {
                return '/article/' . $this->article->slug;
            }
        }
        return $this->url; // custom link
    }
}
