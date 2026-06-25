<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class UpdateLineFriendSettingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'friend_add_url' => ['nullable', 'url', 'max:2048'],
            'reward_point_amount' => ['required', 'integer', 'min:0', 'max:1000000'],
            'reward_expiration_days' => ['nullable', 'integer', 'min:1', 'max:3650'],
            'is_active' => ['required', 'boolean'],
            'auto_reply_message' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
