"use client";

import Link from "next/link";
import { ChangeEvent, FormEvent, useCallback, useEffect, useState } from "react";

type Wallet = {
  paid_balance: number;
  free_balance: number;
  total_balance: number;
};

type Profile = {
  last_name: string | null;
  first_name: string | null;
  last_name_kana: string | null;
  first_name_kana: string | null;
  postal_code: string | null;
  prefecture: string | null;
  city: string | null;
  address_line1: string | null;
  address_line2: string | null;
  phone_number: string | null;
  birth_date: string | null;
};

type User = {
  id: number;
  name: string;
  email: string;
  status: string;
  wallet?: Wallet | null;
  profile?: Profile | null;
};

type UserSession = {
  token_type: "Bearer";
  access_token: string;
  user: User;
};

type UserResponse = {
  data: User;
};

type ApiErrorResponse = {
  message?: string;
  errors?: Record<string, string[]>;
};

type ProfileForm = {
  name: string;
  last_name: string;
  first_name: string;
  last_name_kana: string;
  first_name_kana: string;
  postal_code: string;
  prefecture: string;
  city: string;
  address_line1: string;
  address_line2: string;
  phone_number: string;
  birth_date: string;
};

const sessionStorageKey = "oripa_user_session";
const sessionChangedEvent = "oripa-session-changed";

const emptyForm: ProfileForm = {
  name: "",
  last_name: "",
  first_name: "",
  last_name_kana: "",
  first_name_kana: "",
  postal_code: "",
  prefecture: "",
  city: "",
  address_line1: "",
  address_line2: "",
  phone_number: "",
  birth_date: "",
};

export default function ProfileClient() {
  const [authReady, setAuthReady] = useState(false);
  const [session, setSession] = useState<UserSession | null>(null);
  const [form, setForm] = useState<ProfileForm>(emptyForm);
  const [message, setMessage] = useState<string | null>(null);
  const [loading, setLoading] = useState(false);

  const applyUser = useCallback((targetSession: UserSession, user: User): void => {
    const nextSession = {
      ...targetSession,
      user,
    };

    setSession(nextSession);
    setForm(userToForm(user));
    window.localStorage.setItem(sessionStorageKey, JSON.stringify(nextSession));
    window.dispatchEvent(new Event(sessionChangedEvent));
  }, []);

  const fetchProfile = useCallback(async (targetSession: UserSession): Promise<void> => {
    setLoading(true);
    setMessage(null);

    try {
      const response = await fetch(`${getPublicApiBaseUrl()}/me`, {
        headers: authHeaders(targetSession),
      });
      const payload = (await response.json()) as UserResponse | ApiErrorResponse;

      if (response.status === 401) {
        clearStoredSession();
        setSession(null);
        setMessage("ログイン状態の確認に失敗しました。再度ログインしてください。");
        return;
      }

      if (!response.ok) {
        throw new Error(readApiError(payload, "プロフィールを取得できませんでした。"));
      }

      applyUser(targetSession, (payload as UserResponse).data);
    } catch (error) {
      setMessage(error instanceof Error ? error.message : "プロフィールを取得できませんでした。");
    } finally {
      setLoading(false);
    }
  }, [applyUser]);

  useEffect(() => {
    let active = true;

    async function restoreSession(): Promise<void> {
      const rawSession = window.localStorage.getItem(sessionStorageKey);

      if (rawSession) {
        try {
          const restoredSession = JSON.parse(rawSession) as UserSession;
          await Promise.resolve();

          if (!active) {
            return;
          }

          setSession(restoredSession);
          setForm(userToForm(restoredSession.user));
          void fetchProfile(restoredSession);
        } catch {
          clearStoredSession();
        }
      }

      if (active) {
        setAuthReady(true);
      }
    }

    void restoreSession();

    return () => {
      active = false;
    };
  }, [fetchProfile]);

  function handleChange(event: ChangeEvent<HTMLInputElement>): void {
    setForm((current) => ({
      ...current,
      [event.target.name]: event.target.value,
    }));
  }

  async function handleSubmit(event: FormEvent<HTMLFormElement>): Promise<void> {
    event.preventDefault();

    if (!session) {
      setMessage("ログインしてください。");
      return;
    }

    setLoading(true);
    setMessage(null);

    try {
      const response = await fetch(`${getPublicApiBaseUrl()}/me/profile`, {
        method: "PUT",
        headers: {
          ...authHeaders(session),
          "content-type": "application/json",
        },
        body: JSON.stringify(normalizeForm(form)),
      });
      const payload = (await response.json()) as UserResponse | ApiErrorResponse;

      if (!response.ok) {
        throw new Error(readApiError(payload, "プロフィールを保存できませんでした。"));
      }

      applyUser(session, (payload as UserResponse).data);
      setMessage("プロフィールを保存しました。");
    } catch (error) {
      setMessage(error instanceof Error ? error.message : "プロフィールを保存できませんでした。");
    } finally {
      setLoading(false);
    }
  }

  if (!authReady) {
    return <section className="mypage-panel">読み込み中...</section>;
  }

  if (!session) {
    return (
      <section className="mypage-panel">
        <div className="public-section-head">
          <div>
            <span>MY PAGE</span>
            <h1>プロフィール</h1>
            <p>プロフィールと配送先住所の管理にはログインが必要です。</p>
          </div>
        </div>
        <Link className="public-primary-link dark" href="/login">
          ログインする
        </Link>
      </section>
    );
  }

  return (
    <section className="mypage-panel">
      <div className="public-section-head">
        <div>
          <span>PROFILE</span>
          <h1>プロフィール</h1>
          <p>配送申請で使用する氏名、住所、電話番号を管理できます。</p>
        </div>
        <button type="button" onClick={() => void fetchProfile(session)} disabled={loading}>
          再読み込み
        </button>
      </div>

      {message ? <div className="mypage-message">{message}</div> : null}

      <form className="profile-form" onSubmit={(event) => void handleSubmit(event)}>
        <section>
          <div>
            <span>ACCOUNT</span>
            <h2>アカウント情報</h2>
          </div>
          <label>
            <span>表示名</span>
            <input name="name" value={form.name} onChange={handleChange} required />
          </label>
          <label>
            <span>メールアドレス</span>
            <input value={session.user.email} disabled />
          </label>
        </section>

        <section>
          <div>
            <span>RECIPIENT</span>
            <h2>配送先氏名</h2>
          </div>
          <div className="profile-grid two">
            <label>
              <span>姓</span>
              <input name="last_name" value={form.last_name} onChange={handleChange} />
            </label>
            <label>
              <span>名</span>
              <input name="first_name" value={form.first_name} onChange={handleChange} />
            </label>
            <label>
              <span>姓 カナ</span>
              <input name="last_name_kana" value={form.last_name_kana} onChange={handleChange} />
            </label>
            <label>
              <span>名 カナ</span>
              <input name="first_name_kana" value={form.first_name_kana} onChange={handleChange} />
            </label>
          </div>
        </section>

        <section>
          <div>
            <span>ADDRESS</span>
            <h2>住所・連絡先</h2>
          </div>
          <div className="profile-grid two">
            <label>
              <span>郵便番号</span>
              <input name="postal_code" value={form.postal_code} onChange={handleChange} inputMode="numeric" />
            </label>
            <label>
              <span>都道府県</span>
              <input name="prefecture" value={form.prefecture} onChange={handleChange} />
            </label>
            <label>
              <span>市区町村</span>
              <input name="city" value={form.city} onChange={handleChange} />
            </label>
            <label>
              <span>番地・建物名</span>
              <input name="address_line1" value={form.address_line1} onChange={handleChange} />
            </label>
            <label>
              <span>部屋番号など</span>
              <input name="address_line2" value={form.address_line2} onChange={handleChange} />
            </label>
            <label>
              <span>電話番号</span>
              <input name="phone_number" value={form.phone_number} onChange={handleChange} inputMode="tel" />
            </label>
            <label>
              <span>生年月日</span>
              <input name="birth_date" type="date" value={form.birth_date} onChange={handleChange} />
            </label>
          </div>
        </section>

        <div className="profile-actions">
          <Link className="public-secondary-link light" href="/mypage/prizes">
            景品BOXへ
          </Link>
          <button type="submit" disabled={loading}>
            保存する
          </button>
        </div>
      </form>
    </section>
  );
}

function authHeaders(session: UserSession): HeadersInit {
  return {
    accept: "application/json",
    authorization: `${session.token_type} ${session.access_token}`,
  };
}

function clearStoredSession(): void {
  window.localStorage.removeItem(sessionStorageKey);
  window.dispatchEvent(new Event(sessionChangedEvent));
}

function getPublicApiBaseUrl(): string {
  return process.env.NEXT_PUBLIC_API_BASE_URL ?? "/api";
}

function userToForm(user: User): ProfileForm {
  const profile = user.profile;

  return {
    name: user.name ?? "",
    last_name: profile?.last_name ?? "",
    first_name: profile?.first_name ?? "",
    last_name_kana: profile?.last_name_kana ?? "",
    first_name_kana: profile?.first_name_kana ?? "",
    postal_code: profile?.postal_code ?? "",
    prefecture: profile?.prefecture ?? "",
    city: profile?.city ?? "",
    address_line1: profile?.address_line1 ?? "",
    address_line2: profile?.address_line2 ?? "",
    phone_number: profile?.phone_number ?? "",
    birth_date: profile?.birth_date ?? "",
  };
}

function normalizeForm(form: ProfileForm): ProfileForm {
  return Object.fromEntries(
    Object.entries(form).map(([key, value]) => [key, value.trim()]),
  ) as ProfileForm;
}

function readApiError(payload: UserResponse | ApiErrorResponse, fallback: string): string {
  if ("errors" in payload && payload.errors) {
    return Object.values(payload.errors).flat().join("\n");
  }

  if ("message" in payload && payload.message) {
    return payload.message;
  }

  return fallback;
}
