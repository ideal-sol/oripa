<?php

namespace App\Domain\Line\Services;

use App\Domain\Identity\Exceptions\V2OidcProtocolException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

final class V2LineConfiguration
{
    /** Explicit migration operation: never invoked by a request or scheduler. */
    public function importEnvironment(): void
    {
        DB::transaction(function (): void {
            app(V2LineMessagingSettingService::class)->setting(true);
            $login = DB::table('line_login_settings')->where('id', 1)->lockForUpdate()->first();
            $id = config('v2_identity.external_identity.line.client_id');
            $secret = config('v2_identity.external_identity.line.client_secret');
            if ($login->channel_id === null && is_string($id) && preg_match('/\A[0-9]{1,32}\z/', $id) && is_string($secret) && $secret !== '') {
                DB::table('line_login_settings')->where('id', 1)->update([
                    'channel_id' => $id, 'channel_secret_ciphertext' => Crypt::encryptString($secret),
                    'revision' => DB::raw('revision + 1'), 'updated_at' => now(),
                ]);
            }
            $bot = DB::table('line_bots')->where('legacy', true)->lockForUpdate()->first();
            $secret = config('v2_line.messaging.channel_secret');
            if ($bot !== null && $bot->channel_secret_ciphertext === null && is_string($secret) && $secret !== '') {
                DB::table('line_bots')->where('id', $bot->id)->update([
                    'channel_secret_ciphertext' => Crypt::encryptString($secret),
                    'revision' => DB::raw('revision + 1'), 'updated_at' => now(),
                ]);
            }
            // No Channel ID inference, activation or access-token persistence.
        }, 1);
    }

    public function login(string $key): string
    {
        try {
            $setting = Schema::hasTable('line_login_settings')
                ? DB::table('line_login_settings')->where('id', 1)->first() : null;
            $value = $setting?->channel_id !== null && in_array($key, ['client_id', 'client_secret'], true)
                ? ($key === 'client_id' ? $setting->channel_id : Crypt::decryptString($setting->channel_secret_ciphertext))
                : config('v2_identity.external_identity.line.'.$key);
            if (! is_string($value) || $value === '') throw new V2OidcProtocolException('provider_configuration_unavailable');
            return $value;
        } catch (Throwable) {
            throw new V2OidcProtocolException('provider_configuration_unavailable');
        }
    }

    public function currentBot(): ?object
    {
        if (! Schema::hasTable('line_bots')) return null;
        return DB::table('line_bots')->where('is_active', true)->first()
            ?? DB::table('line_bots')->where('legacy', true)->first();
    }

    public function botId(): int
    {
        return (int) $this->currentBot()?->id;
    }

    public function loginRevision(): int
    {
        return Schema::hasTable('line_login_settings')
            ? (int) DB::table('line_login_settings')->where('id', 1)->value('revision') : 0;
    }
}
