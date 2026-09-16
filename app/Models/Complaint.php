<?php

namespace App\Models;

use App\Models\Concerns\UsesUuidPrimaryKey;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Complaint extends Model
{
    use HasFactory, UsesUuidPrimaryKey;

    protected $table = 'complaints';

    protected $fillable = [
        'complaint_code',
        'user_id',
        'store_id',
        'order_id',
        'complaint_type',
        'severity',
        'title',
        'description',
        'images',
        'status',
        'assigned_to',
        'resolved_by',
        'resolution_note',
        'resolution_type',
        'refund_requested',
        'refund_bank_info',
        'refund_id',
        'resolved_at',
        'cancelled_at',
        'cancelled_by',
        'cancel_reason',
    ];

    protected $casts = [
        'images' => 'array',
        'refund_bank_info' => 'array',
        'refund_requested' => 'boolean',
        'resolved_at' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function store()
    {
        return $this->belongsTo(Store::class);
    }

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function assignee()
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function resolver()
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    public function refund()
    {
        return $this->belongsTo(Refund::class);
    }

    public function messages()
    {
        return $this->hasMany(ComplaintMessage::class);
    }

    public function orderReturn()
    {
        return $this->hasOne(OrderReturn::class);
    }
}
