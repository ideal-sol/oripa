"use client";

import { Check, Copy, KeyRound, Smartphone } from "lucide-react";
import { type FormEvent, useState } from "react";

import { AuthError } from "./auth-status";
import { useAdminAuth } from "./admin-auth-provider";

export function EnrollmentForm() {
  const {
    confirmTotpEnrollment,
    enrollWebauthn,
    loading,
    logout,
    startTotpEnrollment,
    totpEnrollment,
  } = useAdminAuth();
  const [code, setCode] = useState("");
  const [label, setLabel] = useState("Primary authenticator");
  const [completed, setCompleted] = useState<string | null>(null);
  const [copied, setCopied] = useState(false);

  async function beginTotp() {
    try {
      await startTotpEnrollment();
    } catch {
      // Redacted API errors are rendered by AuthError.
    }
  }

  async function confirmTotp(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    const submitted = code;
    setCode("");
    try {
      const authenticated = await confirmTotpEnrollment(submitted);
      if (!authenticated) setCompleted("TOTP");
    } catch {
      // Redacted API errors are rendered by AuthError.
    }
  }

  async function registerWebauthn() {
    try {
      const authenticated = await enrollWebauthn(label);
      if (!authenticated) setCompleted("WebAuthn");
    } catch {
      // Redacted API errors are rendered by AuthError.
    }
  }

  if (completed) {
    return (
      <div className="auth-form">
        <div className="notice notice-success" role="status">
          <Check size={19} aria-hidden="true" />
          <div>
            <strong>{completed}を登録しました。</strong>
            <p>Ownerの認証要件を満たすため、もう1つ認証器を登録してください。</p>
          </div>
        </div>
        <button className="primary-button" onClick={() => setCompleted(null)} type="button">
          登録を続ける
        </button>
        <button className="secondary-button" onClick={logout} type="button">
          ログアウト
        </button>
      </div>
    );
  }

  return (
    <div className="auth-form">
      <AuthError />
      <div className="enrollment-method">
        <div className="section-heading">
          <Smartphone size={20} aria-hidden="true" />
          <div>
            <h2>認証アプリ</h2>
            <p>6桁のワンタイムコードを登録します。</p>
          </div>
        </div>
        {!totpEnrollment ? (
          <button className="secondary-button" disabled={loading} onClick={beginTotp} type="button">
            TOTP登録を開始
          </button>
        ) : (
          <form className="auth-form-section" onSubmit={confirmTotp}>
            <div className="one-time-secret">
              <span>セットアップキー</span>
              <code>{totpEnrollment.secret}</code>
              <button
                className="icon-button"
                onClick={async () => {
                  await navigator.clipboard.writeText(totpEnrollment.secret);
                  setCopied(true);
                }}
                title="セットアップキーをコピー"
                type="button"
              >
                {copied ? <Check size={17} /> : <Copy size={17} />}
                <span className="sr-only">セットアップキーをコピー</span>
              </button>
            </div>
            <label>
              <span>認証コード</span>
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
                  value={code}
                />
              </span>
            </label>
            <button className="primary-button" disabled={loading} type="submit">
              TOTPを確認
            </button>
          </form>
        )}
      </div>
      <div className="divider" />
      <div className="enrollment-method">
        <div className="section-heading">
          <KeyRound size={20} aria-hidden="true" />
          <div>
            <h2>WebAuthn</h2>
            <p>セキュリティキーまたは端末の認証機能を登録します。</p>
          </div>
        </div>
        <label>
          <span>認証器名</span>
          <span className="input-shell">
            <KeyRound size={18} aria-hidden="true" />
            <input
              maxLength={100}
              minLength={1}
              onChange={(event) => setLabel(event.target.value)}
              value={label}
            />
          </span>
        </label>
        <button
          className="secondary-button"
          disabled={loading || !label}
          onClick={registerWebauthn}
          type="button"
        >
          WebAuthnを登録
        </button>
      </div>
    </div>
  );
}
