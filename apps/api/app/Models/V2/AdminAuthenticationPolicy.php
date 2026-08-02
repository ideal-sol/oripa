<?php

namespace App\Models\V2;

use Illuminate\Database\Eloquent\Model;

final class AdminAuthenticationPolicy extends Model
{
    protected $table = 'admin_authentication_policy';

    protected $fillable = [
        'public_id',
        'mfa_required',
        'invitation_required',
        'revision',
        'updated_by_admin_id',
        'last_mutation_request_id',
    ];

    protected function casts(): array
    {
        return [
            'mfa_required' => 'boolean',
            'invitation_required' => 'boolean',
            'revision' => 'integer',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
