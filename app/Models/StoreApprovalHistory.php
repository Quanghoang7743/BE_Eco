<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class StoreApprovalHistory extends Model
{
    use HasFactory;

    protected $fillable = [
        'store_id',
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

    public function store()
    {
        return $this->belongsTo(Store::class);
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
