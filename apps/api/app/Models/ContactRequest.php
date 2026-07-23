<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ContactRequest extends Model
{
    protected $fillable = [
        'name',
        'email',
        'phone',
        'body',
        'status',
        'reply_body',
        'replied_by_admin_user_id',
        'replied_at',
    ];

    protected function casts(): array
    {
        return [
            'replied_at' => 'datetime',
        ];
    }

    public function repliedByAdminUser()
    {
        return $this->belongsTo(AdminUser::class, 'replied_by_admin_user_id');
    }
}
