<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Reconciliation extends Model
{
    use HasFactory;

    protected $fillable = [
        'store_id',
        'status',
        'reconciliation_date',
        'gross_revenue',
        'net_revenue',
        'notes',
        'date_confirmed',
        'approved_by',
        'approved_at',
        'email_sent_at',
    ];

    protected $casts = [
        'reconciliation_date' => 'date',
        'date_confirmed' => 'datetime',
        'approved_at' => 'datetime',
        'email_sent_at' => 'datetime',
        'gross_revenue' => 'decimal:2',
        'net_revenue' => 'decimal:2',
    ];

    // Relationships
    public function store()
    {
        return $this->belongsTo(Store::class);
    }

    public function approvedBy()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
