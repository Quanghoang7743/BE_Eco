<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SpecialistStoreCareLog extends Model
{
    protected $table = 'specialist_store_care_logs';

    protected $fillable = [
        'specialist_id',
        'store_id',
        'care_type',
        'content',
        'care_content',
        'problem_detected',
        'proposed_solution',
        'result_note',
        'attachment_file_id',
        'attachment_original_name',
        'attachment_mime_type',
        'attachment_size',
        'next_follow_up_at',
        'created_by',
    ];

    protected $casts = [
        'next_follow_up_at' => 'datetime',
        'attachment_size' => 'integer',
    ];

    public function specialist(): BelongsTo
    {
        return $this->belongsTo(User::class, 'specialist_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class, 'store_id');
    }
}
