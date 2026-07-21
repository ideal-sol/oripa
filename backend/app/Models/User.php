<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Illuminate\Support\Str;

class User extends Authenticatable
{
    use HasApiTokens;
    use HasFactory;
    use Notifiable;

    protected $fillable = [
        'name',
        'email',
        'referral_code',
        'line_link_code',
        'line_user_id',
        'line_linked_at',
        'email_verified_at',
        'sms_verified_at',
        'password',
        'status',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'sms_verified_at' => 'datetime',
            'line_linked_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (User $user): void {
            if (! $user->referral_code) {
                $user->referral_code = self::generateReferralCode();
            }

            if (! $user->line_link_code) {
                $user->line_link_code = self::generateLineLinkCode();
            }
        });
    }

    public function profile()
    {
        return $this->hasOne(UserProfile::class);
    }

    public function wallet()
    {
        return $this->hasOne(Wallet::class);
    }

    public function pointLots()
    {
        return $this->hasMany(PointLot::class);
    }

    public function pointLedgers()
    {
        return $this->hasMany(PointLedger::class);
    }

    public function referralsMade()
    {
        return $this->hasMany(UserReferral::class, 'referrer_user_id');
    }

    public function referralReceived()
    {
        return $this->hasOne(UserReferral::class, 'referred_user_id');
    }

    public function smsVerificationCodes()
    {
        return $this->hasMany(SmsVerificationCode::class);
    }

    public function socialAccounts()
    {
        return $this->hasMany(SocialAccount::class);
    }

    public function lineFriendLink()
    {
        return $this->hasOne(LineFriendLink::class);
    }

    public function paymentReversals()
    {
        return $this->hasMany(PaymentReversal::class);
    }

    public function qaTestUserMode()
    {
        return $this->hasOne(QaTestUserMode::class);
    }

    public function qaDrawPlans()
    {
        return $this->hasMany(QaDrawPlan::class);
    }

    public function qaDrawExecutions()
    {
        return $this->hasMany(QaDrawExecution::class);
    }

    public static function generateReferralCode(): string
    {
        do {
            $code = 'LP'.Str::upper(Str::random(10));
        } while (self::query()->where('referral_code', $code)->exists());

        return $code;
    }

    public static function generateLineLinkCode(): string
    {
        do {
            $code = 'LN'.Str::upper(Str::random(10));
        } while (self::query()->where('line_link_code', $code)->exists());

        return $code;
    }
}
