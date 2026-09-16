<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class UserBalance extends Model
{
    use HasFactory;

    protected $table = 'user_balances';
    protected $primaryKey = 'user_id';
    public $incrementing = false;
    protected $keyType = 'string';

    const CREATED_AT = null;
    const UPDATED_AT = null;

    protected $fillable = [
        'user_id',
        'available_balance',
        'locked_balance',
        'used_balance',
        'expired_balance',
        'total_lifetime_purchased',
        'total_lifetime_used',
        'last_updated',
        'version',
    ];

    protected $casts = [
        'last_updated' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
