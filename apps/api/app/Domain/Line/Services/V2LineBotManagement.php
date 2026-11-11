<?php

namespace App\Domain\Line\Services;

use App\Domain\Audit\V2\Services\V2AuditLogService;
use App\Domain\Identity\Contracts\V2AdminAuthorizationContext;
use App\Domain\Identity\Enums\V2Permission;
use App\Domain\Identity\Exceptions\V2AuthenticationException;
use App\Domain\Identity\Services\V2AdminFreshMfaAuthorizer;
use App\Domain\Line\Exceptions\V2LineMessagingException;
use App\Domain\Point\Services\V2PointIdempotencyService;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use SensitiveParameter;

final class V2LineBotManagement
{
    public function __construct(
        private readonly V2AdminFreshMfaAuthorizer $authorization,
        private readonly V2LineBotTransport $transport,
        private readonly V2PointIdempotencyService $idempotency,
        private readonly V2AuditLogService $audit,
    ) {}

    public function read(V2AdminAuthorizationContext $context, bool $login): array
    {
        $this->authorization->authorizePermission($context, V2Permission::ReadLineBot);
        if ($login) return $this->loginPresentation(DB::table('line_login_settings')->where('id', 1)->first());
        return ['items' => DB::table('line_bots')->orderBy('id')->get()->map(fn ($bot) => $this->botPresentation($bot))->all()];
    }

    public function mutate(V2AdminAuthorizationContext $context, string $operation, ?string $id, string $key, #[SensitiveParameter] array $input): array
    {
        $admin = $this->authorization->authorizePermission($context, V2Permission::ManageLineBot);
        if (! $this->authorization->isFresh($this->authorization->validSessionForReauthentication($context))) {
            throw new V2AuthenticationException('FRESH_MFA_REQUIRED', 403, 'Fresh Admin authentication is required.');
        }
        $allowed = match ($operation) {
            'login' => ['channel_id', 'channel_secret', 'expected_revision'],
            'create' => ['name', 'channel_id', 'channel_secret'],
            'update' => ['name', 'channel_id', 'channel_secret', 'expected_revision'],
            'connection', 'activation', 'quota', 'followers' => ['expected_revision'],
            default => throw $this->problem('LINE_CONFIGURATION_INVALID', 422),
        };
        if (array_diff(array_keys($input), $allowed)) throw $this->problem('LINE_CONFIGURATION_INVALID', 422);
        if ($operation !== 'create' && (! is_int($input['expected_revision'] ?? null) || $input['expected_revision'] < 1)) throw $this->problem('LINE_CONFIGURATION_INVALID', 422);
        if (in_array($operation, ['login', 'create', 'update'], true)) {
            if (! is_string($input['channel_id'] ?? null) || ! preg_match('/\A[0-9]{1,32}\z/', $input['channel_id'])) throw $this->problem('LINE_CONFIGURATION_INVALID', 422);
            if ($operation !== 'login' && (! is_string($input['name'] ?? null) || trim($input['name']) === '' || mb_strlen($input['name']) > 100)) throw $this->problem('LINE_CONFIGURATION_INVALID', 422);
            if ($operation !== 'update' || array_key_exists('channel_secret', $input)) {
                if (! is_string($input['channel_secret'] ?? null) || $input['channel_secret'] === '' || strlen($input['channel_secret']) > 255) throw $this->problem('LINE_CONFIGURATION_INVALID', 422);
            }
        }
        return DB::transaction(function () use ($context, $operation, $id, $key, $input, $admin): array {
            // Shared serialization point with follow/reward and OAuth completion.
            app(V2LineMessagingSettingService::class)->setting(true);
            $claim = $this->idempotency->claim('line.bot.'.$operation, 'admin', $admin->public_id, $key, ['id' => $id, 'input' => $input]);
            if ($claim->replay) return $claim->record->response_data;
            $table = $operation === 'login' ? 'line_login_settings' : 'line_bots';
            $row = $operation === 'create' ? null : DB::table($table)
                ->where($operation === 'login' ? 'id' : 'public_id', $operation === 'login' ? 1 : $id)->lockForUpdate()->first();
            if ($operation !== 'create' && $row === null) throw $this->problem('LINE_CONFIGURATION_NOT_FOUND', 404);
            if ($row !== null && (int) $row->revision !== $input['expected_revision']) throw $this->problem('LINE_CONFIGURATION_REVISION_CONFLICT', 409);
            $values = ['revision' => ($row?->revision ?? 0) + 1, 'updated_by_admin_id' => $admin->id, 'updated_at' => now()];
            if (in_array($operation, ['login', 'create', 'update'], true)) {
                $values['channel_id'] = $input['channel_id'];
                if (isset($input['channel_secret'])) $values['channel_secret_ciphertext'] = Crypt::encryptString($input['channel_secret']);
                if ($operation !== 'login') {
                    if ($row?->channel_id !== null && $row->channel_id !== $input['channel_id']) throw $this->problem('LINE_BOT_CHANNEL_IMMUTABLE', 409);
                    if (DB::table('line_bots')->where('channel_id', $input['channel_id'])->when($row !== null, fn ($q) => $q->where('id', '<>', $row->id))->exists()) throw $this->problem('LINE_CHANNEL_ALREADY_REGISTERED', 409);
                    $values['name'] = trim($input['name']);
                    $changed = $row === null || $row->channel_id !== $input['channel_id'] || isset($input['channel_secret']);
                    if ($changed) {
                        $values['authentication_revision'] = ($row?->authentication_revision ?? 0) + 1;
                        // Active credential replacement must be verified before commit.
                        $values += ['connection_status' => 'pending', 'connected_at' => null, 'basic_id' => null, 'display_name' => null];
                        if ($row?->is_active) {
                            $candidate = (object) array_merge((array) $row, $values);
                            $values = array_merge($values, $this->connection($candidate));
                        }
                    }
                }
            } elseif ($operation === 'connection') {
                try {
                    $values = array_merge($values, $this->connection($row));
                } catch (V2LineMessagingException) {
                    // Persist a failed check; activation always performs a fresh check too.
                    // An active bot remains selected on a transient provider outage.
                    if ($row->is_active) throw $this->problem('LINE_PROVIDER_UNAVAILABLE', 503);
                    $values += ['connection_status' => 'failed', 'connected_at' => null];
                }
            } elseif ($operation === 'activation') {
                if ($row->connection_status !== 'succeeded' || $row->channel_id === null || $row->channel_secret_ciphertext === null) throw $this->problem('LINE_BOT_CONNECTION_REQUIRED', 409);
                $values = array_merge($values, $this->connection($row));
                if (Schema::hasTable('line_deliveries') && DB::table('line_deliveries')->where('status', 'sending')->exists()) throw $this->problem('LINE_DELIVERY_SENDING', 409);
                DB::table('line_bots')->where('is_active', true)->where('id', '<>', $row->id)->update(['is_active' => false, 'revision' => DB::raw('revision + 1'), 'authentication_revision' => DB::raw('authentication_revision + 1'), 'updated_by_admin_id' => $admin->id, 'updated_at' => now()]);
                if (! $row->is_active) $values['authentication_revision'] = $row->authentication_revision + 1;
                $values['is_active'] = true;
            } elseif ($operation === 'quota') {
                $limit = $this->transport->get($row, '/v2/bot/message/quota');
                $used = $this->transport->get($row, '/v2/bot/message/quota/consumption');
                if (! in_array($limit['type'] ?? null, ['none', 'limited'], true) || ! is_int($used['totalUsage'] ?? null) || ($limit['type'] === 'limited' && ! is_int($limit['value'] ?? null))) throw $this->problem('LINE_PROVIDER_RESPONSE_INVALID', 503);
                $values += ['quota_snapshot' => json_encode(['type' => $limit['type'], 'limit' => $limit['value'] ?? null, 'used' => $used['totalUsage']], JSON_THROW_ON_ERROR), 'quota_fetched_at' => now()];
            } elseif ($operation === 'followers') {
                $date = now()->timezone('Asia/Tokyo')->subDay()->format('Ymd');
                $snapshot = $this->transport->get($row, '/v2/bot/insight/followers', ['date' => $date]);
                if (! in_array($snapshot['status'] ?? null, ['ready', 'unready', 'out_of_service'], true)) throw $this->problem('LINE_PROVIDER_RESPONSE_INVALID', 503);
                $safe = ['date' => $date, 'status' => $snapshot['status']];
                foreach (['followers', 'targetedReaches', 'blocks'] as $field) {
                    if (isset($snapshot[$field]) && (! is_int($snapshot[$field]) || $snapshot[$field] < 0)) throw $this->problem('LINE_PROVIDER_RESPONSE_INVALID', 503);
                    $safe[$field] = $snapshot[$field] ?? null;
                }
                $values += ['followers_snapshot' => json_encode($safe, JSON_THROW_ON_ERROR), 'followers_fetched_at' => now()];
            }
            if ($operation === 'create') {
                $id = (string) Str::uuid7();
                $rowId = DB::table($table)->insertGetId($values + ['public_id' => $id, 'created_by_admin_id' => $admin->id, 'created_at' => now()]);
            } else {
                $rowId = $row->id;
                DB::table($table)->where('id', $rowId)->update($values);
            }
            $fresh = DB::table($table)->where('id', $rowId)->first();
            $result = $operation === 'login' ? $this->loginPresentation($fresh) : $this->botPresentation($fresh);
            $target = $operation === 'login' ? $admin->public_id : $fresh->public_id;
            $this->audit->record('line.configuration.'.$operation, [
                'request_id' => $context->requestId, 'actor_type' => 'admin', 'actor_public_id' => $admin->public_id,
                'actor_role' => $admin->role->value, 'auth_realm' => 'admin', 'target_type' => $table,
                'target_public_id' => $target, 'outcome' => $operation === 'connection' && $fresh->connection_status === 'failed' ? 'failure' : 'success',
                'metadata' => ['revision' => $fresh->revision, 'credential_replaced' => isset($input['channel_secret'])],
            ]);
            $this->idempotency->complete($claim->record, $table, $target, $result);
            return $result;
        }, 1);
    }

    private function connection(object $bot): array
    {
        $info = $this->transport->get($bot, '/v2/bot/info');
        if (! is_string($info['basicId'] ?? null) || ! is_string($info['displayName'] ?? null)
            || strlen($info['basicId']) > 255 || strlen($info['displayName']) > 255) throw $this->problem('LINE_PROVIDER_RESPONSE_INVALID', 503);
        return ['basic_id' => $info['basicId'], 'display_name' => $info['displayName'], 'connection_status' => 'succeeded', 'connected_at' => now()];
    }

    private function loginPresentation(object $row): array
    {
        $stored = $row->channel_id !== null;
        $channel = $stored ? $row->channel_id : config('v2_identity.external_identity.line.client_id');
        $secret = $stored ? $row->channel_secret_ciphertext : config('v2_identity.external_identity.line.client_secret');
        $ready = is_string($channel) && $channel !== '' && is_string($secret) && $secret !== '';
        return ['channel_id' => is_string($channel) && $channel !== '' ? $channel : null, 'secret_configured' => is_string($secret) && $secret !== '', 'source' => $stored ? 'database' : ($ready ? 'environment' : 'unconfigured'), 'revision' => (int) $row->revision];
    }

    private function botPresentation(object $row): array
    {
        $result = array_intersect_key((array) $row, array_flip(['name', 'channel_id', 'basic_id', 'display_name', 'is_active', 'legacy', 'connection_status', 'connected_at', 'quota_fetched_at', 'followers_fetched_at', 'revision']));
        $result['id'] = $row->public_id;
        $result['secret_configured'] = $row->channel_secret_ciphertext !== null;
        $result['webhook_path'] = '/webhooks/v2/line/'.$row->public_id;
        $result['webhook_url'] = $this->webhookUrl($result['webhook_path']);
        foreach (['connected_at', 'quota_fetched_at', 'followers_fetched_at'] as $field) {
            $result[$field] = $row->$field === null ? null : \Carbon\CarbonImmutable::parse($row->$field)->toIso8601String();
        }
        foreach (['quota_snapshot', 'followers_snapshot'] as $field) $result[$field] = $row->$field === null ? null : json_decode($row->$field, true, 32, JSON_THROW_ON_ERROR);
        return $result;
    }

    private function webhookUrl(string $path): ?string
    {
        // Never derive provider destinations from request Host/Forwarded headers or Admin origin.
        $origin = config('v2_identity.origins.user');
        if (! is_string($origin) || preg_match('/[\x00-\x20\x7f\\\\]/', $origin)
            || filter_var($origin, FILTER_VALIDATE_URL) === false) return null;
        $parts = parse_url($origin);
        if ($parts === false || ($parts['scheme'] ?? null) !== 'https' || empty($parts['host'])
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])
            || ! in_array($parts['path'] ?? '', ['', '/'], true)) return null;
        return rtrim($origin, '/').$path;
    }

    private function problem(string $code, int $status): V2LineMessagingException
    {
        return new V2LineMessagingException($code, $status, $code);
    }
}
