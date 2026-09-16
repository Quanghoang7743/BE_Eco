<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ProductApprovalHistory extends Model
{
    use HasFactory;

    protected $fillable = [
        'product_id',
        'attempt_number',
        'status',
        'submitted_by',
        'reviewed_by',
        'submitted_at',
        'reviewed_at',
        'rejection_reason',
    ];

    protected $casts = [
        'attempt_number' => 'integer',
        'submitted_at' => 'datetime',
        'reviewed_at' => 'datetime',
    ];

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function submitter()
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function reviewer()
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
