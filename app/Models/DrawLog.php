<?php

namespace App\Models;

use App\Models\Concerns\UsesUuidPrimaryKey;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DrawLog extends Model
{
    use HasFactory, UsesUuidPrimaryKey;

    protected $table = 'draw_logs';
    public $timestamps = false;

    protected $fillable = [
        'draw_id',
        'log_level',
        'log_type',
        'message',
        'metadata',
        'created_at',
    ];

    protected $casts = [
        'metadata' => 'array',
        'created_at' => 'datetime',
    ];

    public function draw()
    {
        return $this->belongsTo(Draw::class);
    }
}
