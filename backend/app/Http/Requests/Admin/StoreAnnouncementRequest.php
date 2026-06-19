<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAnnouncementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'body' => ['required', 'string'],
            'thumbnail_url' => ['nullable', 'string', 'max:2048'],
            'show_on_top_slider' => ['sometimes', 'boolean'],
            'status' => ['required', 'string', Rule::in(['draft', 'published', 'hidden'])],
            'published_at' => ['nullable', 'date'],
        ];
    }
}
