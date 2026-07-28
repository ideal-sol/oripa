import { afterEach, describe, expect, it, vi } from "vitest";

import {
  createWebauthnCredential,
  getWebauthnAssertion,
} from "@/lib/admin-api/webauthn";

class FakeAssertionResponse {
  authenticatorData = buffer(2);
  clientDataJSON = buffer(1);
  signature = buffer(3);
  userHandle = null;
}

class FakeAttestationResponse {
  attestationObject = buffer(4);
  clientDataJSON = buffer(1);
  getTransports = () => ["internal"];
}

describe("WebAuthn browser boundary", () => {
  afterEach(() => {
    vi.restoreAllMocks();
  });

  it("serializes an assertion without persisting raw credential material", async () => {
    setSecureContext(true);
    vi.stubGlobal("AuthenticatorAssertionResponse", FakeAssertionResponse);
    const get = vi.fn().mockResolvedValue({
      id: "credential-id",
      rawId: buffer(5),
      response: new FakeAssertionResponse(),
      type: "public-key",
    });
    Object.defineProperty(navigator, "credentials", {
      configurable: true,
      value: { get },
    });

    const result = await getWebauthnAssertion({
      allowCredentials: [{ id: "BQ", type: "public-key" }],
      challenge: "AQ",
      rpId: "admin.example.test",
      userVerification: "required",
    });

    expect(get).toHaveBeenCalledOnce();
    const publicKey = get.mock.calls[0][0].publicKey;
    expect(publicKey.challenge).toBeInstanceOf(ArrayBuffer);
    expect(publicKey.allowCredentials[0].id).toBeInstanceOf(ArrayBuffer);
    expect(result).toMatchObject({
      id: "credential-id",
      rawId: "BQ",
      response: {
        authenticatorData: "Ag",
        clientDataJSON: "AQ",
        signature: "Aw",
        userHandle: null,
      },
      type: "public-key",
    });
  });

  it("serializes an attestation and requires a secure context", async () => {
    setSecureContext(true);
    vi.stubGlobal("AuthenticatorAttestationResponse", FakeAttestationResponse);
    const create = vi.fn().mockResolvedValue({
      id: "credential-id",
      rawId: buffer(5),
      response: new FakeAttestationResponse(),
      type: "public-key",
    });
    Object.defineProperty(navigator, "credentials", {
      configurable: true,
      value: { create },
    });

    const result = await createWebauthnCredential({
      challenge: "AQ",
      pubKeyCredParams: [{ alg: -7, type: "public-key" }],
      rp: { id: "admin.example.test", name: "Oripa Admin" },
      user: { displayName: "Owner", id: "Ag", name: "owner" },
    });
    expect(result).toMatchObject({
      response: {
        attestationObject: "BA",
        clientDataJSON: "AQ",
        transports: ["internal"],
      },
    });

    setSecureContext(false);
    await expect(getWebauthnAssertion({ challenge: "AQ" })).rejects.toThrow(
      "unavailable",
    );
  });
});

function buffer(value: number): ArrayBuffer {
  return Uint8Array.of(value).buffer;
}

function setSecureContext(value: boolean): void {
  Object.defineProperty(window, "isSecureContext", {
    configurable: true,
    value,
  });
}
