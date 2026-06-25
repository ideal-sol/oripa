<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SmsVerificationCode extends Model
{
    protected $fillable = [
        'user_id',
        'phone_number',
        'purpose',
        'status',
        'code_hash',
        'attempts',
        'max_attempts',
        'resend_count',
        'last_sent_at',
        'expires_at',
        'verified_at',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'attempts' => 'integer',
            'max_attempts' => 'integer',
            'resend_count' => 'integer',
            'last_sent_at' => 'datetime',
            'expires_at' => 'datetime',
            'verified_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function isPending(): bool
    {
        return $this->status === 'pending' && $this->expires_at?->isFuture();
    }
}
