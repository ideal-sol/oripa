<?php

namespace App\Domain\Identity\Services;

use App\Domain\Identity\Exceptions\V2AuthenticationException;
use App\Models\V2\Admin;
use App\Models\V2\AdminWebauthnMethod;
use SensitiveParameter;
use Symfony\Component\Serializer\SerializerInterface;
use Webauthn\AttestationStatement\AttestationStatementSupportManager;
use Webauthn\AttestationStatement\NoneAttestationStatementSupport;
use Webauthn\AuthenticatorAssertionResponse;
use Webauthn\AuthenticatorAssertionResponseValidator;
use Webauthn\AuthenticatorAttestationResponse;
use Webauthn\AuthenticatorAttestationResponseValidator;
use Webauthn\AuthenticatorSelectionCriteria;
use Webauthn\CeremonyStep\CeremonyStepManagerFactory;
use Webauthn\CredentialRecord;
use Webauthn\Denormalizer\WebauthnSerializerFactory;
use Webauthn\PublicKeyCredential;
use Webauthn\PublicKeyCredentialCreationOptions;
use Webauthn\PublicKeyCredentialDescriptor;
use Webauthn\PublicKeyCredentialParameters;
use Webauthn\PublicKeyCredentialRequestOptions;
use Webauthn\PublicKeyCredentialRpEntity;
use Webauthn\PublicKeyCredentialUserEntity;

final class V2WebauthnService
{
    private SerializerInterface $serializer;
    private CeremonyStepManagerFactory $ceremonies;

    public function __construct(private readonly V2AuthTransactionStore $transactions)
    {
        $attestations = new AttestationStatementSupportManager([
            new NoneAttestationStatementSupport(),
        ]);
        $this->serializer = (new WebauthnSerializerFactory($attestations))->create();
        $this->ceremonies = new CeremonyStepManagerFactory();
        $origin = $this->origin();
        $this->ceremonies->setAllowedOrigins([$origin], false);
        $this->ceremonies->setAttestationStatementSupportManager($attestations);
    }

    /**
     * @return array{challenge_token: string, options: array<string, mixed>, expires_in: int}
     */
    public function beginRegistration(Admin $admin, string $label): array
    {
        $this->assertConfiguration();
        $options = PublicKeyCredentialCreationOptions::create(
            PublicKeyCredentialRpEntity::create(
                (string) config('v2_identity.webauthn.rp_name'),
                $this->rpId()
            ),
            PublicKeyCredentialUserEntity::create(
                $admin->public_id,
                $admin->public_id,
                'Oripa Admin'
            ),
            random_bytes(32),
            [
                PublicKeyCredentialParameters::create('public-key', -7),
                PublicKeyCredentialParameters::create('public-key', -257),
            ],
            AuthenticatorSelectionCriteria::create(
                userVerification: AuthenticatorSelectionCriteria::USER_VERIFICATION_REQUIREMENT_REQUIRED
            ),
            PublicKeyCredentialCreationOptions::ATTESTATION_CONVEYANCE_PREFERENCE_NONE,
            $this->descriptors($admin),
            300000
        );
        $transaction = $this->transactions->issue(
            'admin_webauthn_registration',
            [
                'admin_id' => (int) $admin->getKey(),
                'label' => $label,
                'options' => $this->serializer->serialize($options, 'json'),
            ],
            (int) config('v2_identity.transactions.webauthn_ttl_seconds')
        );

        return [
            'challenge_token' => $transaction['token'],
            'options' => $this->normalize($options),
            'expires_in' => $transaction['expires_in'],
        ];
    }

    /**
     * @param array<string, mixed> $credential
     */
    public function completeRegistration(
        Admin $admin,
        #[SensitiveParameter] string $challengeToken,
        array $credential
    ): AdminWebauthnMethod {
        try {
            $payload = $this->transactions->consume(
                $challengeToken,
                'admin_webauthn_registration'
            );
        } catch (\Throwable) {
            throw $this->invalidCredential();
        }
        if ((int) ($payload['admin_id'] ?? 0) !== (int) $admin->getKey()) {
            throw $this->invalidCredential();
        }
        $options = $this->serializer->deserialize(
            (string) ($payload['options'] ?? ''),
            PublicKeyCredentialCreationOptions::class,
            'json'
        );
        $publicKeyCredential = $this->deserializeCredential($credential);
        if (! $publicKeyCredential->response instanceof AuthenticatorAttestationResponse) {
            throw $this->invalidCredential();
        }
        try {
            $record = AuthenticatorAttestationResponseValidator::create(
                $this->ceremonies->creationCeremony()
            )->check($publicKeyCredential->response, $options, $this->rpId());
        } catch (\Throwable) {
            throw $this->invalidCredential();
        }
        $normalized = $this->normalize($record);

        return AdminWebauthnMethod::query()->create([
            'admin_id' => $admin->getKey(),
            'credential_id' => (string) $normalized['publicKeyCredentialId'],
            'public_key' => $this->serializer->serialize($record, 'json'),
            'sign_count' => $record->counter,
            'label' => (string) ($payload['label'] ?? 'Authenticator'),
            'transports' => $record->transports,
        ]);
    }

    /**
     * @return array{challenge_token: string, options: array<string, mixed>, expires_in: int}|null
     */
    public function beginAssertion(Admin $admin): ?array
    {
        $methods = AdminWebauthnMethod::query()
            ->where('admin_id', $admin->getKey())
            ->whereNull('revoked_at')
            ->get();
        if ($methods->isEmpty()) {
            return null;
        }
        $options = PublicKeyCredentialRequestOptions::create(
            random_bytes(32),
            $this->rpId(),
            $methods->map(function (AdminWebauthnMethod $method): PublicKeyCredentialDescriptor {
                $record = $this->serializer->deserialize(
                    $method->public_key,
                    CredentialRecord::class,
                    'json'
                );

                return $record->getPublicKeyCredentialDescriptor();
            })->all(),
            PublicKeyCredentialRequestOptions::USER_VERIFICATION_REQUIREMENT_REQUIRED,
            300000
        );
        $transaction = $this->transactions->issue(
            'admin_webauthn_assertion',
            [
                'admin_id' => (int) $admin->getKey(),
                'options' => $this->serializer->serialize($options, 'json'),
            ],
            (int) config('v2_identity.transactions.webauthn_ttl_seconds')
        );

        return [
            'challenge_token' => $transaction['token'],
            'options' => $this->normalize($options),
            'expires_in' => $transaction['expires_in'],
        ];
    }

    /**
     * @param array<string, mixed> $credential
     */
    public function verifyAssertion(
        Admin $admin,
        #[SensitiveParameter] string $challengeToken,
        array $credential
    ): bool {
        try {
            $payload = $this->transactions->consume(
                $challengeToken,
                'admin_webauthn_assertion'
            );
        } catch (\Throwable) {
            return false;
        }
        if ((int) ($payload['admin_id'] ?? 0) !== (int) $admin->getKey()) {
            return false;
        }
        $publicKeyCredential = $this->deserializeCredential($credential);
        if (! $publicKeyCredential->response instanceof AuthenticatorAssertionResponse) {
            return false;
        }
        $normalizedCredential = $this->normalize($publicKeyCredential);
        $credentialId = $normalizedCredential['id'] ?? null;
        if (! is_string($credentialId)) {
            return false;
        }
        $method = AdminWebauthnMethod::query()
            ->where('admin_id', $admin->getKey())
            ->where('credential_id', $credentialId)
            ->whereNull('revoked_at')
            ->first();
        if ($method === null) {
            return false;
        }
        $record = $this->serializer->deserialize(
            $method->public_key,
            CredentialRecord::class,
            'json'
        );
        $options = $this->serializer->deserialize(
            (string) ($payload['options'] ?? ''),
            PublicKeyCredentialRequestOptions::class,
            'json'
        );
        try {
            $updated = AuthenticatorAssertionResponseValidator::create(
                $this->ceremonies->requestCeremony()
            )->check(
                $record,
                $publicKeyCredential->response,
                $options,
                $this->rpId(),
                $admin->public_id
            );
        } catch (\Throwable) {
            return false;
        }
        $method->forceFill([
            'public_key' => $this->serializer->serialize($updated, 'json'),
            'sign_count' => $updated->counter,
            'last_used_at' => now(),
        ])->save();

        return true;
    }

    /**
     * @param array<string, mixed> $credential
     */
    private function deserializeCredential(array $credential): PublicKeyCredential
    {
        try {
            $result = $this->serializer->deserialize(
                json_encode($credential, JSON_THROW_ON_ERROR),
                PublicKeyCredential::class,
                'json'
            );
        } catch (\Throwable) {
            throw $this->invalidCredential();
        }
        if (! $result instanceof PublicKeyCredential) {
            throw $this->invalidCredential();
        }

        return $result;
    }

    /**
     * @return list<PublicKeyCredentialDescriptor>
     */
    private function descriptors(Admin $admin): array
    {
        return AdminWebauthnMethod::query()
            ->where('admin_id', $admin->getKey())
            ->whereNull('revoked_at')
            ->get()
            ->map(function (AdminWebauthnMethod $method): PublicKeyCredentialDescriptor {
                $record = $this->serializer->deserialize(
                    $method->public_key,
                    CredentialRecord::class,
                    'json'
                );

                return $record->getPublicKeyCredentialDescriptor();
            })
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function normalize(object $value): array
    {
        $normalized = $this->serializer->normalize($value, 'json');
        if (! is_array($normalized)) {
            throw $this->invalidCredential();
        }

        return $normalized;
    }

    private function assertConfiguration(): void
    {
        $this->rpId();
        $this->origin();
    }

    private function rpId(): string
    {
        $value = config('v2_identity.webauthn.rp_id');
        if (! is_string($value) || $value === '') {
            throw new V2AuthenticationException(
                'MFA_CONFIGURATION_UNAVAILABLE',
                503,
                'WebAuthn is not configured.'
            );
        }

        return $value;
    }

    private function origin(): string
    {
        $value = config('v2_identity.webauthn.origin');
        if (! is_string($value) || ! str_starts_with($value, 'https://')) {
            throw new V2AuthenticationException(
                'MFA_CONFIGURATION_UNAVAILABLE',
                503,
                'WebAuthn is not configured.'
            );
        }

        return $value;
    }

    private function invalidCredential(): V2AuthenticationException
    {
        return new V2AuthenticationException(
            'INVALID_MFA_CREDENTIAL',
            401,
            'The MFA verification could not be completed.'
        );
    }
}
