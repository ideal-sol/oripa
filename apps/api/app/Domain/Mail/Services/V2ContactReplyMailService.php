<?php

namespace App\Domain\Mail\Services;

use App\Models\V2\MailTemplate;
use App\Models\V2\ShippingAddress;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use RuntimeException;

final class V2ContactReplyMailService
{
    public function __construct(
        private readonly V2TemplateVariableRenderer $renderer,
        private readonly V2IdentityMailUrlBuilder $urls
    ) {
    }

    public function render(string $contactPublicId, string $replyPublicId): array
    {
        $row = DB::table('contact_reply_requests as reply')
            ->join('contact_inquiries as contact', 'contact.id', '=', 'reply.contact_inquiry_id')
            ->join('users as user', 'user.id', '=', 'contact.user_id')
            ->where('reply.public_id', $replyPublicId)->where('contact.public_id', $contactPublicId)
            ->first(['reply.message_ciphertext', 'user.id as user_id', 'user.display_name', 'user.email_display']);
        if ($row === null || filter_var($row->email_display, FILTER_VALIDATE_EMAIL) === false) {
            throw new RuntimeException('Contact reply recipient is unavailable.');
        }
        $phone = DB::table('user_phone_numbers')->where('user_id', $row->user_id)
            ->whereNotNull('verified_at')->whereNull('revoked_at')->value('phone_ciphertext');
        $address = ShippingAddress::query()->where('user_id', $row->user_id)->orderByDesc('id')->first();
        $parts = [];
        if ($address !== null) {
            foreach (['postal_code', 'prefecture', 'city', 'street', 'building'] as $field) {
                $ciphertext = $address->{$field.'_ciphertext'};
                $parts[] = $ciphertext === null ? '' : ($field === 'postal_code' ? '〒' : '').Crypt::decryptString($ciphertext);
            }
        }
        $variables = [
            'full_name' => (string) ($row->display_name ?? ''),
            'phone_number' => $phone === null ? '' : Crypt::decryptString($phone),
            'email' => (string) $row->email_display,
            'address' => implode(' ', array_filter($parts, static fn (string $part): bool => $part !== '')),
            'inquiry_url' => $this->urls->contact($contactPublicId),
        ];
        $replyContent = $this->renderer->plainText(Crypt::decryptString($row->message_ciphertext), $variables);
        $template = MailTemplate::query()->where('template_key', 'contact_reply')->firstOrFail();
        $outer = ['full_name' => $variables['full_name'], 'reply_content' => $replyContent];
        return [
            'recipient' => (string) $row->email_display,
            'subject' => $this->renderer->subject($template->subject_template, $outer),
            'body_html' => $this->renderer->html($template->body_html, $outer, ['reply_content']),
        ];
    }

    public function send(#[\SensitiveParameter] array $mail): void
    {
        Mail::html($mail['body_html'], static function ($message) use ($mail): void {
            $message->to($mail['recipient'])->subject($mail['subject']);
        });
    }
}
