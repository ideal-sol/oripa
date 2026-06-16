"use client";

import { FormEvent, useEffect, useState } from "react";
import { useRouter } from "next/navigation";

type AuthMode = "login" | "register";

type UserSession = {
  token_type: "Bearer";
  access_token: string;
  user: {
    id: number;
    name: string;
    email: string;
    status: string;
    wallet?: {
      paid_balance: number;
      free_balance: number;
      total_balance: number;
    } | null;
  };
};

type ApiErrorResponse = {
  message?: string;
  errors?: Record<string, string[]>;
};

type AuthClientProps = {
  initialMode: AuthMode;
  redirectTo: string;
};

const sessionStorageKey = "oripa_user_session";
const sessionChangedEvent = "oripa-session-changed";

export default function AuthClient({ initialMode, redirectTo }: AuthClientProps) {
  const router = useRouter();
  const [mode, setMode] = useState<AuthMode>(initialMode);
  const [name, setName] = useState("");
  const [lastName, setLastName] = useState("");
  const [firstName, setFirstName] = useState("");
  const [phoneNumber, setPhoneNumber] = useState("");
  const [email, setEmail] = useState("");
  const [password, setPassword] = useState("");
  const [passwordConfirmation, setPasswordConfirmation] = useState("");
  const [loading, setLoading] = useState(false);
  const [message, setMessage] = useState<string | null>(null);

  useEffect(() => {
    const rawSession = window.localStorage.getItem(sessionStorageKey);

    if (rawSession) {
      router.replace(safeRedirect(redirectTo));
    }
  }, [redirectTo, router]);

  async function handleSubmit(event: FormEvent<HTMLFormElement>): Promise<void> {
    event.preventDefault();
    setLoading(true);
    setMessage(null);

    try {
      const response = await fetch(`${getPublicApiBaseUrl()}/${mode === "register" ? "register" : "login"}`, {
        method: "POST",
        headers: {
          accept: "application/json",
          "content-type": "application/json",
        },
        body: JSON.stringify(mode === "register"
          ? {
              name,
              email,
              password,
              password_confirmation: passwordConfirmation,
              last_name: lastName,
              first_name: firstName,
              phone_number: phoneNumber,
              device_name: "public-web",
            }
          : {
              email,
              password,
              device_name: "public-web",
            }),
      });
      const payload = (await response.json()) as UserSession | ApiErrorResponse;

      if (!response.ok) {
        throw new Error(readApiError(payload, mode === "register" ? "会員登録できませんでした。" : "ログインできませんでした。"));
      }

      window.localStorage.setItem(sessionStorageKey, JSON.stringify(payload as UserSession));
      window.dispatchEvent(new Event(sessionChangedEvent));
      router.replace(safeRedirect(redirectTo));
      router.refresh();
    } catch (error) {
      setMessage(error instanceof Error ? error.message : "処理に失敗しました。");
    } finally {
      setLoading(false);
    }
  }

  return (
    <section className="auth-panel">
      <div className="auth-copy">
        <span>Account</span>
        <h1>{mode === "register" ? "会員登録" : "ログイン"}</h1>
        <p>ポイント購入、抽選、景品BOXの利用にはログインが必要です。</p>
        <div className="auth-switch" role="group" aria-label="認証モード">
          <button className={mode === "login" ? "active" : ""} type="button" onClick={() => setMode("login")}>
            ログイン
          </button>
          <button className={mode === "register" ? "active" : ""} type="button" onClick={() => setMode("register")}>
            会員登録
          </button>
        </div>
      </div>

      <form className="auth-form" onSubmit={handleSubmit}>
        {mode === "register" ? (
          <>
            <label>
              <span>表示名</span>
              <input autoComplete="name" value={name} onChange={(event) => setName(event.target.value)} required />
            </label>
            <div className="auth-form-grid">
              <label>
                <span>姓</span>
                <input value={lastName} onChange={(event) => setLastName(event.target.value)} />
              </label>
              <label>
                <span>名</span>
                <input value={firstName} onChange={(event) => setFirstName(event.target.value)} />
              </label>
            </div>
            <label>
              <span>電話番号</span>
              <input autoComplete="tel" value={phoneNumber} onChange={(event) => setPhoneNumber(event.target.value)} />
            </label>
          </>
        ) : null}

        <label>
          <span>メールアドレス</span>
          <input autoComplete="email" inputMode="email" type="email" value={email} onChange={(event) => setEmail(event.target.value)} required />
        </label>
        <label>
          <span>パスワード</span>
          <input autoComplete={mode === "register" ? "new-password" : "current-password"} type="password" value={password} onChange={(event) => setPassword(event.target.value)} required />
        </label>
        {mode === "register" ? (
          <label>
            <span>パスワード確認</span>
            <input autoComplete="new-password" type="password" value={passwordConfirmation} onChange={(event) => setPasswordConfirmation(event.target.value)} required />
          </label>
        ) : null}

        <button disabled={loading} type="submit">
          {loading ? "処理中" : mode === "register" ? "登録して開始" : "ログイン"}
        </button>
        {message ? <p className="auth-message">{message}</p> : null}
      </form>
    </section>
  );
}

function getPublicApiBaseUrl(): string {
  return process.env.NEXT_PUBLIC_API_BASE_URL ?? "/api";
}

function readApiError(payload: ApiErrorResponse | unknown, fallback: string): string {
  if (payload && typeof payload === "object" && "errors" in payload && payload.errors) {
    const firstError = Object.values(payload.errors as Record<string, string[]>).flat()[0];

    if (firstError) {
      return firstError;
    }
  }

  if (payload && typeof payload === "object" && "message" in payload && typeof payload.message === "string") {
    return payload.message;
  }

  return fallback;
}

function safeRedirect(value: string): string {
  if (!value.startsWith("/") || value.startsWith("//")) {
    return "/";
  }

  return value;
}
