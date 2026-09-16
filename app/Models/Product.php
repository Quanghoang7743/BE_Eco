<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Product extends Model
{
    use HasFactory;

    public const APPROVED_STATUSES = ['published', 'approved', 'active'];

    protected $fillable = [
        'name',
        'summary',
        'content',
        'thumbnail',
        'images',
        'price',
        'sale_price',
        'slug',
        'sku',
        'store_id',
        'category_id',
        'group_id',
        'stock',
        'view_count',
        'commission_rate',
        'commission_rate_mode',
        'ticket_reward_quantity',
        'ticket_reward_enabled',
        'ticket_type_code',
        'base_ranking_score',
        'status',
        'published_at',
        'approved_by',
        'approved_at',
        'rejection_reason',
    ];

    // Ép kiểu 'images' từ JSON -> array
    protected $casts = [
        'images' => 'array',
        'view_count' => 'integer',
        'ticket_reward_quantity' => 'integer',
        'ticket_reward_enabled' => 'boolean',
        'commission_rate' => 'decimal:2',
        'commission_rate_mode' => 'string',
        'base_ranking_score' => 'decimal:2',
        'published_at' => 'datetime',
        'approved_at' => 'datetime',
    ];

    // Quan hệ với danh mục sản phẩm
    public function category()
    {
        return $this->belongsTo(ProductCategory::class, 'category_id');
    }

    // Quan hệ với nhóm sản phẩm
    public function group()
    {
        return $this->belongsTo(ProductGroup::class, 'group_id');
    }

    public function store()
    {
        return $this->belongsTo(Store::class, 'store_id');
    }

    public function variants()
    {
        return $this->hasMany(ProductVariant::class);
    }

    public function combos()
    {
        return $this->hasMany(ProductCombo::class, 'product_id');
    }

    public function bonusCombos()
    {
        return $this->hasMany(ProductCombo::class, 'bonus_product_id');
    }

    public function reviews()
    {
        return $this->hasMany(StoreReview::class, 'product_id')->where('is_hidden', false);
    }

    public function approvalHistories()
    {
        return $this->hasMany(ProductApprovalHistory::class)->orderBy('attempt_number');
    }

    public function latestApprovalHistory()
    {
        return $this->hasOne(ProductApprovalHistory::class)->latestOfMany('attempt_number');
    }


    public function favorites()
    {
        return $this->hasMany(UserProductFavorite::class, 'product_id');
    }

    public function favoritedByUsers()
    {
        return $this->belongsToMany(User::class, 'user_product_favorites', 'product_id', 'user_id')
            ->withTimestamps();
    }
    public function toppings()
    {
        return $this->belongsToMany(Topping::class, 'product_toppings', 'product_id', 'topping_id')->withTimestamps();

    }

    public function hasApprovedStatus(): bool
    {
        $status = mb_strtolower(trim((string) ($this->status ?? '')));

        return in_array($status, self::APPROVED_STATUSES, true);
    }
}
