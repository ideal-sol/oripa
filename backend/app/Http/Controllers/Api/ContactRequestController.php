<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\StoreContactRequest;
use App\Http\Resources\ContactRequestResource;
use App\Models\ContactRequest;
use Illuminate\Routing\Controller;

class ContactRequestController extends Controller
{
    public function store(StoreContactRequest $request): ContactRequestResource
    {
        $contactRequest = ContactRequest::query()->create($request->validated());

        return new ContactRequestResource($contactRequest);
    }
}
