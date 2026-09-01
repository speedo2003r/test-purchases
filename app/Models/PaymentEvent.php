<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymentEvent extends Model
{
    /** @use HasFactory<\Database\Factories\PaymentEventFactory> */
    use HasFactory;

    public const TYPE_SUCCESS = 'success';
    public const TYPE_FAILED = 'failed';
    public const TYPE_CANCELLED = 'cancelled';

    protected $fillable = [
        'payment_attempt_id',
        'provider_event_id',
        'event_type',
        'occurred_at',
        'processed_at',
        'raw_payload',
    ];

    protected $casts = [
        'occurred_at' => 'datetime',
        'processed_at' => 'datetime',
        'raw_payload' => 'array',
    ];

    public function attempt(): BelongsTo
    {
        return $this->belongsTo(PaymentAttempt::class, 'payment_attempt_id');
    }
}
