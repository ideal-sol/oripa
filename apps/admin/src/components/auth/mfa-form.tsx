"use client";

import { KeyRound, ShieldCheck, Smartphone } from "lucide-react";
import Link from "next/link";
import { type FormEvent, useState } from "react";

import { AuthError } from "./auth-status";
import { useAdminAuth } from "./admin-auth-provider";

export function MfaForm() {
  const { loading, preauth, verifyTotp, verifyWebauthn } = useAdminAuth();
  const [code, setCode] = useState("");
  const methods = preauth?.methods ?? [];

  async function submitTotp(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    const submitted = code;
    setCode("");
    try {
      await verifyTotp(submitted);
    } catch {
      // Redacted API errors are rendered by AuthError.
    }
  }

  async function submitWebauthn() {
    try {
      await verifyWebauthn();
    } catch {
      // Redacted API errors are rendered by AuthError.
    }
  }

  return (
    <div className="auth-form">
      <AuthError />
      {methods.includes("webauthn") ? (
        <button
          className="method-button"
          disabled={loading}
          onClick={submitWebauthn}
          type="button"
        >
          <ShieldCheck size={20} aria-hidden="true" />
          <span>
            <strong>セキュリティキー</strong>
            <small>WebAuthnで確認</small>
          </span>
        </button>
      ) : null}
      {methods.includes("totp") ? (
        <form className="auth-form-section" onSubmit={submitTotp}>
          <label>
            <span>認証アプリの6桁コード</span>
            <span className="input-shell">
              <Smartphone size={18} aria-hidden="true" />
              <input
                autoComplete="one-time-code"
                inputMode="numeric"
                maxLength={6}
                minLength={6}
                onChange={(event) => setCode(event.target.value.replace(/\D/gu, ""))}
                pattern="[0-9]{6}"
                required
                type="text"
                value={code}
              />
            </span>
          </label>
          <button className="primary-button" disabled={loading} type="submit">
            コードを確認
          </button>
        </form>
      ) : null}
      {methods.includes("recovery_code") ? (
        <Link className="method-button" href="/auth/recovery">
          <KeyRound size={20} aria-hidden="true" />
          <span>
            <strong>リカバリーコード</strong>
            <small>1回限りのコードを使用</small>
          </span>
        </Link>
      ) : null}
    </div>
  );
}
