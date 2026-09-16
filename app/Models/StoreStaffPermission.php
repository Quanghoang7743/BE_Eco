<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class StoreStaffPermission extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'store_id',
        'permission_id',
        'permission_code',
    ];

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function permission()
    {
        return $this->belongsTo(Permission::class, 'permission_code', 'code');
    }
}
