<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AffiliateAuditLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'actor_id',
        'actor_role',
        'action',
        'object_type',
        'object_id',
        'old_value_json',
        'new_value_json',
        'reason',
        'ip_address',
        'user_agent',
    ];

    protected $casts = [
        'old_value_json' => 'array',
        'new_value_json' => 'array',
    ];

    public function actor()
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
