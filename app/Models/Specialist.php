<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Specialist extends Model
{
    protected $fillable = [
        'user_id',
        'specialist_code',
        'tax_code',
        'contract_file_url',
        'id_card_front_url',
        'id_card_back_url',
        'other_documents_json',
        'credit_points',
    ];

    protected $casts = [
        'other_documents_json' => 'array',
        'credit_points' => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function creditLogs(): HasMany
    {
        return $this->hasMany(SpecialistCreditLog::class, 'specialist_id');
    }

    public function getCreditRankLabelAttribute(): string
    {
        return match (true) {
            (int) $this->credit_points >= 100 => 'Tốt',
            (int) $this->credit_points >= 80 => 'Khá',
            (int) $this->credit_points >= 60 => 'Trung bình',
            default => 'Cần cải thiện',
        };
    }
}
