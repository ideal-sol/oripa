<?php

namespace App\Domain\Identity\Services;

use App\Domain\Identity\Exceptions\V2AgencyException;

final class V2AgencyContactFields
{
    public function rules(): array
    {
        return [
            'contact_name' => ['required', 'string', 'max:200'],
            'phone' => ['required', 'string', 'max:40', 'regex:/\A[0-9+() .-]+\z/'],
        ];
    }

    public function normalize(array $input): array
    {
        $fields = [];
        foreach (array_keys($this->rules()) as $field) {
            $fields[$field] = trim($input[$field]);
            if ($fields[$field] === '' || preg_match('/[\x00-\x1F\x7F]/u', $fields[$field])) {
                throw new V2AgencyException('AGENCY_INVALID', 422, 'Please check the Agency input.');
            }
        }

        return $fields;
    }
}
