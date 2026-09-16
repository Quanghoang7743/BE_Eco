<?php

namespace App\Models;

use App\Models\Concerns\UsesUuidPrimaryKey;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Ticket extends Model
{
    use HasFactory, UsesUuidPrimaryKey;

    protected $table = 'tickets';

    protected $fillable = [
        'ticket_code',
        'user_id',
        'status',
        'ticket_type_code',
        'value_amount',
        'purchased_price',
        'purchased_at',
        'expired_at',
        'source_type',
        'source_id',
        'source_order_item_id',
        'locked_at',
        'locked_by',
        'lock_expires_at',
        'used_in_room_id',
        'used_in_order_id',
        'used_at',
        'batch_id',
        'qr_code',
    ];

    protected $casts = [
        'value_amount' => 'decimal:2',
        'purchased_price' => 'decimal:2',
        'purchased_at' => 'datetime',
        'expired_at' => 'datetime',
        'locked_at' => 'datetime',
        'lock_expires_at' => 'datetime',
        'used_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $ticket): void {
            $purchasedAt = $ticket->purchased_at ? \Carbon\Carbon::parse((string) $ticket->purchased_at) : now();
            $ticket->purchased_at = $purchasedAt;

            if (!$ticket->expired_at) {
                $expiryDays = (int) (appSetting::query()->value('ticket_expiry_days') ?? appSetting::DEFAULT_TICKET_EXPIRY_DAYS);
                if ($expiryDays < 1) {
                    $expiryDays = appSetting::DEFAULT_TICKET_EXPIRY_DAYS;
                }

                $ticket->expired_at = $purchasedAt->copy()->addDays($expiryDays);
            }
        });
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function ledgerEntries()
    {
        return $this->hasMany(TicketLedger::class);
    }

    public function sourceOrderItem()
    {
        return $this->belongsTo(OrderItem::class, 'source_order_item_id');
    }
}
