<?php

namespace App\Domain\Mail\Services;

use App\Domain\Audit\V2\Services\V2AuditLogService;
use App\Models\V2\MailDelivery;
use App\Models\V2\MailTemplate;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

final class V2TemplateMailDeliveryService
{
    public function __construct(
        private readonly V2TemplateVariableRenderer $renderer,
        private readonly V2AuditLogService $audit
    ) {
    }

    /** @param array<string, string|list<string>|null> $values */
    public function sendVerification(string $recipient, array $values): void
    {
        $template = $this->template('email_verification');
        $this->send(
            $recipient,
            $this->renderer->subject($template->subject_template, $this->variables($values)),
            $this->renderer->html($template->body_html, $this->variables($values))
        );
    }

    public function schedule(
        string $templateKey,
        string $eventKey,
        string $sourceType,
        string $sourcePublicId
    ): void {
        if (
            DB::transactionLevel() < 1
            || $templateKey === 'email_verification'
            || ! isset($this->catalog()[$templateKey])
            || ! preg_match('/\A[a-z][a-z0-9_.:-]{0,254}\z/', $eventKey)
            || ! in_array($sourceType, ['user', 'payment', 'shipping_request', 'contact_inquiry'], true)
            || ! Str::isUuid($sourcePublicId)
        ) {
            throw new RuntimeException('Template Mail scheduling input is invalid.');
        }
        $template = $this->template($templateKey);
        $publicId = (string) Str::uuid7();
        $now = now()->startOfSecond();
        $inserted = DB::table('mail_deliveries')->insertOrIgnore([
            'public_id' => $publicId,
            'mail_template_id' => $template->id,
            'event_key' => $eventKey,
            'source_type' => $sourceType,
            'source_public_id' => $sourcePublicId,
            'status' => 'pending',
            'attempts' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        if ($inserted !== 1) {
            return;
        }
        DB::afterCommit(static function () use ($publicId): void {
            try {
                app(self::class)->deliver($publicId);
            } catch (Throwable) {
            }
        });
    }

    public function deliver(string $publicId): void
    {
        $delivery = DB::transaction(function () use ($publicId): ?MailDelivery {
            $record = MailDelivery::query()->where('public_id', $publicId)->lockForUpdate()->first();
            if (! $record instanceof MailDelivery || $record->status !== 'pending') {
                return null;
            }
            $record->forceFill([
                'status' => 'sending',
                'attempts' => 1,
                'updated_at' => now()->startOfSecond(),
            ])->save();

            return $record->refresh();
        }, 3);
        if (! $delivery instanceof MailDelivery) {
            return;
        }

        try {
            [$recipient, $values] = $this->resolve($delivery);
            $template = MailTemplate::query()->findOrFail($delivery->mail_template_id);
            $this->send(
                $recipient,
                $this->renderer->subject($template->subject_template, $values),
                $this->renderer->html($template->body_html, $values)
            );
            MailDelivery::query()->whereKey($delivery->id)->where('status', 'sending')->update([
                'status' => 'sent',
                'sent_at' => now()->startOfSecond(),
                'updated_at' => now()->startOfSecond(),
            ]);
            $this->safeAudit('mail.template.sent', $delivery, 'success');
        } catch (Throwable) {
            MailDelivery::query()->whereKey($delivery->id)->where('status', 'sending')->update([
                'status' => 'failed',
                'failure_code' => 'delivery_failed',
                'updated_at' => now()->startOfSecond(),
            ]);
            $this->safeAudit('mail.template.failed', $delivery, 'failure', 'delivery_failed');
        }
    }

    /** @return array{0: string, 1: array<string, string|list<string>|null>} */
    private function resolve(MailDelivery $delivery): array
    {
        return match ($delivery->source_type) {
            'user' => $this->userValues($delivery->source_public_id),
            'payment' => $this->paymentValues($delivery->source_public_id),
            'shipping_request' => $this->shippingValues($delivery->source_public_id),
            'contact_inquiry' => $this->contactValues($delivery->source_public_id),
            default => throw new RuntimeException('Template Mail source is invalid.'),
        };
    }

    /** @return array{0: string, 1: array<string, string|list<string>|null>} */
    private function userValues(string $publicId): array
    {
        $user = DB::table('users')->where('public_id', $publicId)
            ->first(['email_display', 'display_name']);
        if ($user === null) {
            throw new RuntimeException('Template Mail User is unavailable.');
        }
        $name = is_string($user->display_name) ? $user->display_name : '';

        return [(string) $user->email_display, $this->variables([
            'user_name' => $name,
            'full_name' => $name,
        ])];
    }

    /** @return array{0: string, 1: array<string, string|list<string>|null>} */
    private function paymentValues(string $publicId): array
    {
        $row = DB::table('payments as payment')
            ->join('users as user', 'user.id', '=', 'payment.user_id')
            ->where('payment.public_id', $publicId)
            ->first([
                'user.email_display', 'user.display_name', 'payment.plan_name_snapshot',
                'payment.amount', 'payment.currency',
            ]);
        if ($row === null) {
            throw new RuntimeException('Template Mail Payment is unavailable.');
        }
        $amount = number_format((int) $row->amount).($row->currency === 'JPY' ? '円' : ' '.$row->currency);

        return [(string) $row->email_display, $this->variables([
            'user_name' => (string) ($row->display_name ?? ''),
            'purchase_plan' => (string) $row->plan_name_snapshot,
            'purchase_amount' => $amount,
        ])];
    }

    /** @return array{0: string, 1: array<string, string|list<string>|null>} */
    private function shippingValues(string $publicId): array
    {
        $shipping = DB::table('shipping_requests as shipping')
            ->join('users as user', 'user.id', '=', 'shipping.user_id')
            ->where('shipping.public_id', $publicId)
            ->first([
                'shipping.id', 'shipping.recipient_name_ciphertext',
                'shipping.postal_code_ciphertext', 'shipping.prefecture_ciphertext',
                'shipping.city_ciphertext', 'shipping.street_ciphertext',
                'shipping.building_ciphertext', 'shipping.phone_number_ciphertext',
                'user.email_display', 'user.display_name',
            ]);
        if ($shipping === null) {
            throw new RuntimeException('Template Mail Shipping Request is unavailable.');
        }
        $items = DB::table('shipping_request_items as item')
            ->join('user_prizes as ownership', 'ownership.id', '=', 'item.user_prize_id')
            ->join('draw_results as result', 'result.id', '=', 'ownership.draw_result_id')
            ->join('draw_requests as request', 'request.id', '=', 'result.draw_request_id')
            ->join('catalog_gacha_versions as version', 'version.id', '=', 'request.gacha_version_id')
            ->where('item.shipping_request_id', $shipping->id)
            ->orderBy('item.id')
            ->get(['version.title as gacha_name', 'result.display_snapshot']);
        $gachas = [];
        $prizes = [];
        foreach ($items as $item) {
            $snapshot = is_string($item->display_snapshot)
                ? json_decode($item->display_snapshot, true, 512, JSON_THROW_ON_ERROR)
                : (array) $item->display_snapshot;
            $gachas[] = (string) $item->gacha_name;
            $prizes[] = (string) data_get($snapshot, 'prize.name', '');
        }
        $address = implode('', array_filter([
            '〒'.$this->decrypt($shipping->postal_code_ciphertext),
            $this->decrypt($shipping->prefecture_ciphertext),
            $this->decrypt($shipping->city_ciphertext),
            $this->decrypt($shipping->street_ciphertext),
            $shipping->building_ciphertext === null ? '' : ' '.$this->decrypt($shipping->building_ciphertext),
        ], static fn (string $part): bool => $part !== ''));

        return [(string) $shipping->email_display, $this->variables([
            'user_name' => (string) ($shipping->display_name ?? ''),
            'full_name' => $this->decrypt($shipping->recipient_name_ciphertext),
            'address' => $address,
            'phone_number' => $this->decrypt($shipping->phone_number_ciphertext),
            'gacha_names' => $gachas,
            'prize_names' => $prizes,
        ])];
    }

    /** @return array{0: string, 1: array<string, string|list<string>|null>} */
    private function contactValues(string $publicId): array
    {
        $row = DB::table('contact_inquiries as contact')
            ->leftJoin('users as user', 'user.id', '=', 'contact.user_id')
            ->where('contact.public_id', $publicId)
            ->first([
                'contact.name_ciphertext', 'contact.email_ciphertext',
                'contact.phone_ciphertext', 'contact.body_ciphertext', 'user.display_name',
            ]);
        if ($row === null) {
            throw new RuntimeException('Template Mail Contact is unavailable.');
        }
        $name = $this->decrypt($row->name_ciphertext);

        return [$this->decrypt($row->email_ciphertext), $this->variables([
            'user_name' => (string) ($row->display_name ?? $name),
            'full_name' => $name,
            'phone_number' => $row->phone_ciphertext === null ? '' : $this->decrypt($row->phone_ciphertext),
            'contact_body' => $this->decrypt($row->body_ciphertext),
        ])];
    }

    private function send(string $recipient, string $subject, string $bodyHtml): void
    {
        if (filter_var($recipient, FILTER_VALIDATE_EMAIL) === false) {
            throw new RuntimeException('Template Mail rendering is invalid.');
        }
        Mail::html($bodyHtml, static function ($message) use ($recipient, $subject): void {
            $message->to($recipient)->subject($subject);
        });
    }

    private function decrypt(string $ciphertext): string
    {
        return Crypt::decryptString($ciphertext);
    }

    /** @param array<string, string|list<string>|null> $values @return array<string, string|list<string>|null> */
    private function variables(array $values): array
    {
        $base = array_fill_keys(array_keys(config('v2_mail_templates.variables', [])), '');

        return [...$base, ...$values];
    }

    /** @return array<string, string> */
    private function catalog(): array
    {
        $catalog = config('v2_mail_templates.templates', []);

        return is_array($catalog) ? $catalog : [];
    }

    private function template(string $key): MailTemplate
    {
        $template = MailTemplate::query()->where('template_key', $key)->first();
        if (! $template instanceof MailTemplate || ! isset($this->catalog()[$key])) {
            throw new RuntimeException('Template Mail configuration is unavailable.');
        }

        return $template;
    }

    private function safeAudit(
        string $action,
        MailDelivery $delivery,
        string $outcome,
        ?string $reason = null
    ): void {
        try {
            $this->audit->record($action, [
                'actor_type' => 'system',
                'target_type' => 'mail_delivery',
                'target_public_id' => $delivery->public_id,
                'outcome' => $outcome,
                'reason_code' => $reason,
                'metadata' => [
                    'source_type' => $delivery->source_type,
                    'template_key' => MailTemplate::query()
                        ->whereKey($delivery->mail_template_id)->value('template_key'),
                ],
            ]);
        } catch (Throwable) {
        }
    }
}
