"use client";

import { KeyRound, LockKeyhole, Mail } from "lucide-react";
import Link from "next/link";
import { type FormEvent, useState } from "react";

import { AuthError } from "./auth-status";
import { useAdminAuth } from "./admin-auth-provider";

export function InvitationForm() {
  const { acceptInvitation, loading } = useAdminAuth();
  const [email, setEmail] = useState("");
  const [password, setPassword] = useState("");
  const [token, setToken] = useState("");

  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    const submittedPassword = password;
    const submittedToken = token;
    setPassword("");
    setToken("");
    try {
      await acceptInvitation({
        email,
        password: submittedPassword,
        invitation_token: submittedToken,
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
        <span>招待トークン</span>
        <span className="input-shell">
          <KeyRound size={18} aria-hidden="true" />
          <input
            autoComplete="off"
            maxLength={64}
            minLength={64}
            onChange={(event) => setToken(event.target.value)}
            pattern="[0-9a-f]{64}"
            required
            type="password"
            value={token}
          />
        </span>
      </label>
      <label>
        <span>初期パスワード</span>
        <span className="input-shell">
          <LockKeyhole size={18} aria-hidden="true" />
          <input
            autoComplete="new-password"
            maxLength={128}
            minLength={12}
            onChange={(event) => setPassword(event.target.value)}
            required
            type="password"
            value={password}
          />
        </span>
      </label>
      <button className="primary-button" disabled={loading} type="submit">
        {loading ? "確認中" : "招待を受け入れる"}
      </button>
      <Link className="text-button quiet-button" href="/login">
        通常ログインへ戻る
      </Link>
    </form>
  );
}
