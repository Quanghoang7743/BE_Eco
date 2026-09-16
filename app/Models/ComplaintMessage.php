<?php

namespace App\Models;

use App\Models\Concerns\UsesUuidPrimaryKey;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ComplaintMessage extends Model
{
    use HasFactory, UsesUuidPrimaryKey;

    protected $table = 'complaint_messages';
    public $timestamps = false;

    protected $fillable = [
        'complaint_id',
        'sender_id',
        'sender_type',
        'message',
        'attachments',
        'is_from_admin',
        'is_read',
        'created_at',
    ];

    protected $casts = [
        'attachments' => 'array',
        'is_from_admin' => 'boolean',
        'is_read' => 'boolean',
        'created_at' => 'datetime',
    ];

    public function complaint()
    {
        return $this->belongsTo(Complaint::class);
    }

    public function sender()
    {
        return $this->belongsTo(User::class, 'sender_id');
    }
}
