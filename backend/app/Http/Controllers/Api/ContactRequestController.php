<?php

namespace App\Http\Controllers\Api;

use App\Domain\Notification\Services\DiscordNotificationService;
use App\Http\Requests\StoreContactRequest;
use App\Http\Resources\ContactRequestResource;
use App\Mail\ContactReceivedMail;
use App\Models\ContactRequest;
use Illuminate\Support\Facades\Mail;
use Illuminate\Routing\Controller;

class ContactRequestController extends Controller
{
    public function store(StoreContactRequest $request, DiscordNotificationService $discordNotification): ContactRequestResource
    {
        $contactRequest = ContactRequest::query()->create($request->validated());
        Mail::to($contactRequest->email, $contactRequest->name)->send(new ContactReceivedMail($contactRequest));
        $discordNotification->notifyContactReceived($contactRequest);

        return new ContactRequestResource($contactRequest);
    }
}
