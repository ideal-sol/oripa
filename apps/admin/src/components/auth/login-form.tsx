"use client";

import { KeyRound, LockKeyhole, Mail } from "lucide-react";
import { type FormEvent, useState } from "react";

import { AuthError } from "./auth-status";
import { useAdminAuth } from "./admin-auth-provider";

export function LoginForm() {
  const { loading, login } = useAdminAuth();
  const [email, setEmail] = useState("");
  const [password, setPassword] = useState("");
  const [invitationToken, setInvitationToken] = useState("");
  const [showInvitation, setShowInvitation] = useState(false);

  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    const submittedPassword = password;
    const submittedInvitation = invitationToken;
    setPassword("");
    setInvitationToken("");
    try {
      await login({
        email,
        password: submittedPassword,
        ...(submittedInvitation ? { invitation_token: submittedInvitation } : {}),
      });
    } catch {
      // The provider exposes a redacted error state.
    }
  }

  return (
    <form className="auth-form" onSubmit={submit}>
      <AuthError />
      <label>
        <span>メールアドレス</span>
        <span className="input-shell">
          <Mail size={18} aria-hidden="true" />
          <input
            autoComplete="username"
            inputMode="email"
            maxLength={320}
            onChange={(event) => setEmail(event.target.value)}
            required
            type="email"
            value={email}
          />
        </span>
      </label>
      <label>
        <span>パスワード</span>
        <span className="input-shell">
          <LockKeyhole size={18} aria-hidden="true" />
          <input
            autoComplete="current-password"
            maxLength={128}
            onChange={(event) => setPassword(event.target.value)}
            required
            type="password"
            value={password}
          />
        </span>
      </label>
      {showInvitation ? (
        <label>
          <span>招待トークン</span>
          <span className="input-shell">
            <KeyRound size={18} aria-hidden="true" />
            <input
              autoComplete="off"
              maxLength={64}
              minLength={64}
              onChange={(event) => setInvitationToken(event.target.value)}
              pattern="[0-9a-f]{64}"
              type="password"
              value={invitationToken}
            />
          </span>
        </label>
      ) : null}
      <button
        className="text-button quiet-button"
        onClick={() => setShowInvitation((visible) => !visible)}
        type="button"
      >
        {showInvitation ? "招待トークンを閉じる" : "初回招待からログイン"}
      </button>
      <button className="primary-button" disabled={loading} type="submit">
        {loading ? "確認中" : "続行"}
      </button>
    </form>
  );
}
