<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AffiliateProfile extends Model
{
    use HasFactory;

    public const STATUS_PENDING = 'pending';
    public const STATUS_DRAFT = 'draft';
    public const STATUS_SUBMITTED = 'submitted';
    public const STATUS_UNDER_REVIEW = 'under_review';
    public const STATUS_NEED_MORE_INFO = 'need_more_info';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_SUSPENDED = 'suspended';
    public const STATUS_LOCKED = 'locked';
    public const STATUS_TERMINATED = 'terminated';

    public const ACTIVE_STATUSES = [
        self::STATUS_APPROVED,
    ];

    public const REVIEWABLE_STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_SUBMITTED,
        self::STATUS_UNDER_REVIEW,
        self::STATUS_NEED_MORE_INFO,
    ];

    protected $fillable = [
        'user_id',
        'id_number',
        'id_front_image',
        'id_back_image',
        'id_selfie_image',
        'personal_tax_code',
        'social_media_links',
        'status',
        'rejection_reason',
        'submitted_at',
        'review_started_at',
        'approved_at',
        'approved_by',
        'rejected_at',
        'rejected_by',
        'need_more_info_reason',
        'suspended_reason',
        'terminated_reason',
        'affiliate_code',
        'affiliate_link',
        'accepted_policy',
        'accepted_no_fake_order_commitment',
        'accepted_tax_bank_accuracy_commitment',
        'accepted_communication_commitment',
        'accepted_at',
        'policy_version',
    ];

    protected $casts = [
        'social_media_links' => 'array',
        'submitted_at' => 'datetime',
        'review_started_at' => 'datetime',
        'approved_at' => 'datetime',
        'rejected_at' => 'datetime',
        'accepted_policy' => 'boolean',
        'accepted_no_fake_order_commitment' => 'boolean',
        'accepted_tax_bank_accuracy_commitment' => 'boolean',
        'accepted_communication_commitment' => 'boolean',
        'accepted_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function approvedBy()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function rejectedBy()
    {
        return $this->belongsTo(User::class, 'rejected_by');
    }
}
