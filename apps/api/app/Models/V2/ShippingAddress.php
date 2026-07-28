<?php

namespace App\Models\V2;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

final class ShippingAddress extends Model
{
    use SoftDeletes;

    protected $table = 'shipping_addresses';

    protected $guarded = ['*'];

    protected static function booted(): void
    {
        static::creating(function (self $address): void {
            $address->public_id ??= (string) Str::uuid7();
        });
    }
}
