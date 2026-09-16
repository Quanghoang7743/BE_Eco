<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Notification extends Model
{
    use HasFactory;

    protected $fillable = [
        'title',
        'body',
        'type',
        'audience_scope',
        'message',
        'action_url',
        'is_read',
        'read_at',
        'user_id',
        'store_id',
        'order_id',
        'created_at',
        'updated_at',
    ];

    protected $casts = [
        'is_read' => 'boolean',
        'read_at' => 'datetime',
    ];

    public function order()
    {
        return $this->belongsTo(Order::class, 'order_id');
    }

    public function getLinkedOrderAttribute()
    {
        if (!empty($this->order_id)) {
            return $this->order;
        }

        $msg = $this->message ?: $this->body;
        if ($msg) {
            if (preg_match('/OD[0-9]{14}[A-Z0-9]+/i', $msg, $matches)) {
                return Order::where('order_code', $matches[0])->first();
            }
            if (preg_match('/từ\s+(.+)$/u', $msg, $matches)) {
                $name = trim($matches[1]);
                if ($this->created_at) {
                    return Order::whereHas('user', function ($q) use ($name) {
                            $q->where('name', $name);
                        })
                        ->whereBetween('created_at', [
                            $this->created_at->copy()->subSeconds(60),
                            $this->created_at->copy()->addSeconds(60)
                        ])
                        ->first();
                }
            }
        }

        return null;
    }
}
