type JsonObject = Record<string, unknown>;

export async function getWebauthnAssertion(
  options: JsonObject,
): Promise<JsonObject> {
  requireWebauthn();
  const credential = (await navigator.credentials.get({
    publicKey: decodePublicKeyOptions(options),
  })) as PublicKeyCredential | null;
  if (!credential) {
    throw new Error("WebAuthn assertion was cancelled.");
  }
  return serializeCredential(credential);
}

export async function createWebauthnCredential(
  options: JsonObject,
): Promise<JsonObject> {
  requireWebauthn();
  const credential = (await navigator.credentials.create({
    publicKey: decodeCreationOptions(options),
  })) as PublicKeyCredential | null;
  if (!credential) {
    throw new Error("WebAuthn registration was cancelled.");
  }
  return serializeCredential(credential);
}

function requireWebauthn(): void {
  if (
    typeof window === "undefined" ||
    !window.isSecureContext ||
    !navigator.credentials
  ) {
    throw new Error("WebAuthn is unavailable in this browser.");
  }
}

function decodePublicKeyOptions(options: JsonObject): PublicKeyCredentialRequestOptions {
  const allowCredentials = Array.isArray(options.allowCredentials)
    ? options.allowCredentials.map((item) => {
        const credential = item as JsonObject;
        return {
          ...credential,
          id: decodeBase64Url(String(credential.id)),
        } as PublicKeyCredentialDescriptor;
      })
    : undefined;
  return {
    ...(options as unknown as PublicKeyCredentialRequestOptions),
    allowCredentials,
    challenge: decodeBase64Url(String(options.challenge)),
  };
}

function decodeCreationOptions(options: JsonObject): PublicKeyCredentialCreationOptions {
  const user = options.user as JsonObject;
  const excludeCredentials = Array.isArray(options.excludeCredentials)
    ? options.excludeCredentials.map((item) => {
        const credential = item as JsonObject;
        return {
          ...credential,
          id: decodeBase64Url(String(credential.id)),
        } as PublicKeyCredentialDescriptor;
      })
    : undefined;
  return {
    ...(options as unknown as PublicKeyCredentialCreationOptions),
    challenge: decodeBase64Url(String(options.challenge)),
    excludeCredentials,
    user: {
      ...(user as unknown as PublicKeyCredentialUserEntity),
      id: decodeBase64Url(String(user.id)),
    },
  };
}

function serializeCredential(credential: PublicKeyCredential): JsonObject {
  const response = credential.response;
  const serializedResponse: JsonObject = {
    clientDataJSON: encodeBase64Url(response.clientDataJSON),
  };
  if (response instanceof AuthenticatorAssertionResponse) {
    serializedResponse.authenticatorData = encodeBase64Url(response.authenticatorData);
    serializedResponse.signature = encodeBase64Url(response.signature);
    serializedResponse.userHandle = response.userHandle
      ? encodeBase64Url(response.userHandle)
      : null;
  } else if (response instanceof AuthenticatorAttestationResponse) {
    serializedResponse.attestationObject = encodeBase64Url(response.attestationObject);
    serializedResponse.transports = response.getTransports?.() ?? [];
  }
  return {
    id: credential.id,
    rawId: encodeBase64Url(credential.rawId),
    response: serializedResponse,
    type: credential.type,
  };
}

function decodeBase64Url(value: string): ArrayBuffer {
  const base64 = value.replace(/-/g, "+").replace(/_/g, "/");
  const padded = base64.padEnd(Math.ceil(base64.length / 4) * 4, "=");
  const bytes = Uint8Array.from(atob(padded), (character) => character.charCodeAt(0));
  return bytes.buffer;
}

function encodeBase64Url(value: ArrayBuffer): string {
  const bytes = new Uint8Array(value);
  let binary = "";
  for (const byte of bytes) binary += String.fromCharCode(byte);
  return btoa(binary).replace(/\+/g, "-").replace(/\//g, "_").replace(/=+$/u, "");
}
