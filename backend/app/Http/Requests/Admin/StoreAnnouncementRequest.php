<?php

namespace App\Http\Requests\Admin;

use App\Domain\Content\Enums\AnnouncementCategory;
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
            'category' => ['required', Rule::enum(AnnouncementCategory::class)],
            'thumbnail_url' => ['nullable', 'string', 'max:2048'],
            'show_on_top_slider' => ['sometimes', 'boolean'],
            'status' => ['required', 'string', Rule::in(['draft', 'published', 'hidden'])],
            'published_at' => ['nullable', 'date', 'required_with:published_until'],
            'published_until' => ['nullable', 'date', 'after:published_at'],
        ];
    }
}
