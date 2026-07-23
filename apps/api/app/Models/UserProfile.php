<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserProfile extends Model
{
    protected $fillable = [
        'user_id',
        'last_name',
        'first_name',
        'last_name_kana',
        'first_name_kana',
        'postal_code',
        'prefecture',
        'city',
        'address_line1',
        'address_line2',
        'phone_number',
        'normalized_phone_number',
        'birth_date',
    ];

    protected function casts(): array
    {
        return [
            'birth_date' => 'date',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    protected static function booted(): void
    {
        static::saving(function (UserProfile $profile): void {
            $profile->normalized_phone_number = self::normalizePhoneNumber($profile->phone_number);
        });
    }

    public static function normalizePhoneNumber(?string $phoneNumber): ?string
    {
        $normalized = preg_replace('/[^\d+]/', '', trim((string) $phoneNumber));

        return $normalized !== '' ? $normalized : null;
    }
}
