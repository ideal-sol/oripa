"use client";

import { KeyRound, LockKeyhole, ShieldCheck, X } from "lucide-react";
import {
  type FormEvent,
  useEffect,
  useRef,
  useState,
} from "react";

import { AuthError } from "./auth-status";
import { useAdminAuth } from "./admin-auth-provider";

export function FreshMfaDialog({
  onClose,
  onSuccess,
  open,
}: {
  onClose: () => void;
  onSuccess?: () => Promise<void> | void;
  open: boolean;
}) {
  const { freshPassword, freshTotp, freshWebauthn, loading, mfaRequired } = useAdminAuth();
  const [method, setMethod] = useState<"totp" | "webauthn">("totp");
  const [code, setCode] = useState("");
  const [password, setPassword] = useState("");
  const inputRef = useRef<HTMLInputElement>(null);
  const panelRef = useRef<HTMLElement>(null);
  const previousFocus = useRef<HTMLElement | null>(null);

  useEffect(() => {
    if (!open) return;
    previousFocus.current = document.activeElement as HTMLElement | null;
    window.setTimeout(() => inputRef.current?.focus(), 0);
    return () => previousFocus.current?.focus();
  }, [open]);

  const effectiveMethod = mfaRequired ? method : "password";

  if (!open) return null;

  async function complete(operation: () => Promise<void>) {
    try {
      await operation();
      await onSuccess?.();
    } catch {
      // Redacted API errors are rendered by AuthError.
    }
  }

  async function submitTotp(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    const submitted = code;
    setCode("");
    await complete(() => freshTotp(submitted));
  }

  async function submitPassword(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    const submitted = password;
    setPassword("");
    await complete(() => freshPassword(submitted));
  }

  return (
    <div
      className="dialog-backdrop"
      onKeyDown={(event) => {
        if (event.key === "Escape" && !loading) onClose();
        if (event.key === "Tab") {
          const focusable = panelRef.current?.querySelectorAll<HTMLElement>(
            'button:not(:disabled), input:not(:disabled), [href], [tabindex]:not([tabindex="-1"])',
          );
          if (!focusable?.length) return;
          const first = focusable[0];
          const last = focusable[focusable.length - 1];
          if (event.shiftKey && document.activeElement === first) {
            event.preventDefault();
            last.focus();
          } else if (!event.shiftKey && document.activeElement === last) {
            event.preventDefault();
            first.focus();
          }
        }
      }}
      role="presentation"
    >
      <section
        aria-labelledby="fresh-mfa-title"
        aria-modal="true"
        className="dialog-panel"
        ref={panelRef}
        role="dialog"
      >
        <header className="dialog-header">
          <div>
            <span className="eyebrow">Security check</span>
            <h2 id="fresh-mfa-title">本人確認</h2>
          </div>
          <button
            aria-label="再認証を閉じる"
            className="icon-button"
            disabled={loading}
            onClick={onClose}
            type="button"
          >
            <X size={19} />
          </button>
        </header>
        {mfaRequired ? (
          <div className="segmented-control" aria-label="再認証方法">
            <button
              aria-pressed={effectiveMethod === "totp"}
              onClick={() => setMethod("totp")}
              type="button"
            >
              TOTP
            </button>
            <button
              aria-pressed={effectiveMethod === "webauthn"}
              onClick={() => setMethod("webauthn")}
              type="button"
            >
              WebAuthn
            </button>
          </div>
        ) : null}
        <AuthError />
        {effectiveMethod === "password" ? (
          <form className="auth-form-section" onSubmit={submitPassword}>
            <label>
              <span>現在のパスワード</span>
              <span className="input-shell">
                <LockKeyhole size={18} aria-hidden="true" />
                <input
                  autoComplete="current-password"
                  maxLength={128}
                  onChange={(event) => setPassword(event.target.value)}
                  ref={inputRef}
                  required
                  type="password"
                  value={password}
                />
              </span>
            </label>
            <button className="primary-button" disabled={loading} type="submit">
              再認証
            </button>
          </form>
        ) : effectiveMethod === "totp" ? (
          <form className="auth-form-section" onSubmit={submitTotp}>
            <label>
              <span>認証アプリの6桁コード</span>
              <span className="input-shell">
                <KeyRound size={18} aria-hidden="true" />
                <input
                  autoComplete="one-time-code"
                  inputMode="numeric"
                  maxLength={6}
                  minLength={6}
                  onChange={(event) => setCode(event.target.value.replace(/\D/gu, ""))}
                  pattern="[0-9]{6}"
                  ref={inputRef}
                  required
                  value={code}
                />
              </span>
            </label>
            <button className="primary-button" disabled={loading} type="submit">
              再認証
            </button>
          </form>
        ) : (
          <button
            className="method-button"
            disabled={loading}
            onClick={() => complete(freshWebauthn)}
            type="button"
          >
            <ShieldCheck size={20} aria-hidden="true" />
            <span>
              <strong>WebAuthnで再認証</strong>
              <small>登録済みの認証器を使用</small>
            </span>
          </button>
        )}
      </section>
    </div>
  );
}
