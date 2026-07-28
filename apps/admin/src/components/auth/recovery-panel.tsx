"use client";

import { Check, Download, KeyRound } from "lucide-react";
import { type FormEvent, useState } from "react";

import { FreshMfaDialog } from "./fresh-mfa-dialog";
import { AuthError } from "./auth-status";
import { useAdminAuth } from "./admin-auth-provider";

export function RecoveryPanel() {
  const { loading, phase, regenerateRecoveryCodes, verifyRecoveryCode } =
    useAdminAuth();
  const [code, setCode] = useState("");
  const [codes, setCodes] = useState<string[] | null>(null);
  const [freshOpen, setFreshOpen] = useState(false);

  async function verify(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    const submitted = code;
    setCode("");
    try {
      await verifyRecoveryCode(submitted);
    } catch {
      // Redacted API errors are rendered by AuthError.
    }
  }

  if (phase === "authenticated") {
    return (
      <div className="auth-form">
        <AuthError />
        {codes ? (
          <>
            <div className="notice notice-success" role="status">
              <Check size={18} aria-hidden="true" />
              <div>
                <strong>新しいリカバリーコードを発行しました。</strong>
                <p>この画面を閉じると再表示できません。</p>
              </div>
            </div>
            <ol className="recovery-code-list" aria-label="リカバリーコード">
              {codes.map((item) => (
                <li key={item}>
                  <code>{item}</code>
                </li>
              ))}
            </ol>
            <button className="secondary-button" onClick={() => setCodes(null)} type="button">
              コードを閉じる
            </button>
          </>
        ) : (
          <button className="primary-button" onClick={() => setFreshOpen(true)} type="button">
            <Download size={18} aria-hidden="true" />
            コードを再生成
          </button>
        )}
        <FreshMfaDialog
          onClose={() => setFreshOpen(false)}
          onSuccess={async () => {
            const generated = await regenerateRecoveryCodes();
            setCodes(generated);
            setFreshOpen(false);
          }}
          open={freshOpen}
        />
      </div>
    );
  }

  return (
    <form className="auth-form" onSubmit={verify}>
      <AuthError />
      <label>
        <span>リカバリーコード</span>
        <span className="input-shell">
          <KeyRound size={18} aria-hidden="true" />
          <input
            autoComplete="one-time-code"
            maxLength={128}
            onChange={(event) => setCode(event.target.value)}
            required
            type="password"
            value={code}
          />
        </span>
      </label>
      <button className="primary-button" disabled={loading} type="submit">
        コードを使用
      </button>
    </form>
  );
}
