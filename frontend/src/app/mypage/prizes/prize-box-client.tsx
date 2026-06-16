"use client";

import Link from "next/link";
import { FormEvent, useCallback, useEffect, useMemo, useState } from "react";

type Wallet = {
  paid_balance: number;
  free_balance: number;
  total_balance: number;
};

type User = {
  id: number;
  name: string;
  email: string;
  status: string;
  wallet?: Wallet | null;
};

type UserSession = {
  token_type: "Bearer";
  access_token: string;
  user: User;
};

type UserPrize = {
  id: number;
  status: string;
  converted_point: number | null;
  acquired_at: string | null;
  storage_expire_at: string | null;
  gacha?: {
    id: number;
    title: string;
    slug: string;
    main_image_url: string | null;
  };
  prize?: {
    id: number;
    name: string;
    image_url: string | null;
    display_price: number | null;
    exchange_point: number | null;
    condition: string;
    rank?: {
      display_name: string;
    } | null;
  };
};

type PrizeCollection = {
  data: UserPrize[];
};

type ShippingForm = {
  recipient_name: string;
  postal_code: string;
  prefecture: string;
  city: string;
  address_line1: string;
  address_line2: string;
  phone_number: string;
};

type ApiErrorResponse = {
  message?: string;
  errors?: Record<string, string[]>;
};

const sessionStorageKey = "oripa_user_session";
const sessionChangedEvent = "oripa-session-changed";
const statuses = ["all", "stored", "shipping_requested", "shipped", "converted"] as const;

const initialShippingForm: ShippingForm = {
  recipient_name: "",
  postal_code: "",
  prefecture: "",
  city: "",
  address_line1: "",
  address_line2: "",
  phone_number: "",
};

export default function PrizeBoxClient() {
  const [authReady, setAuthReady] = useState(false);
  const [session, setSession] = useState<UserSession | null>(null);
  const [wallet, setWallet] = useState<Wallet | null>(null);
  const [prizes, setPrizes] = useState<UserPrize[]>([]);
  const [selectedStatus, setSelectedStatus] = useState<(typeof statuses)[number]>("stored");
  const [selectedPrizeIds, setSelectedPrizeIds] = useState<number[]>([]);
  const [shippingForm, setShippingForm] = useState<ShippingForm>(initialShippingForm);
  const [email, setEmail] = useState("");
  const [password, setPassword] = useState("");
  const [loading, setLoading] = useState(false);
  const [message, setMessage] = useState<string | null>(null);

  const storedPrizes = useMemo(() => prizes.filter((prize) => prize.status === "stored"), [prizes]);
  const selectedStoredCount = selectedPrizeIds.filter((id) => storedPrizes.some((prize) => prize.id === id)).length;

  const fetchWallet = useCallback(async (targetSession: UserSession): Promise<void> => {
    const response = await fetch(`${getPublicApiBaseUrl()}/me/points`, {
      headers: authHeaders(targetSession),
    });

    if (response.status === 401) {
      clearStoredSession();
      setSession(null);
      setWallet(null);
      setMessage("ログイン状態の確認に失敗しました。再度ログインしてください。");
      return;
    }

    if (!response.ok) {
      setMessage("ポイント残高を取得できませんでした。");
      return;
    }

    const payload = (await response.json()) as { wallet: Wallet };
    setWallet(payload.wallet);
  }, []);

  const fetchPrizes = useCallback(async (targetSession: UserSession, status = selectedStatus): Promise<void> => {
    const params = new URLSearchParams({ per_page: "50" });

    if (status !== "all") {
      params.set("status", status);
    }

    const response = await fetch(`${getPublicApiBaseUrl()}/me/prizes?${params.toString()}`, {
      headers: authHeaders(targetSession),
    });

    if (response.status === 401) {
      clearStoredSession();
      setSession(null);
      setWallet(null);
      setPrizes([]);
      setMessage("ログイン状態の確認に失敗しました。再度ログインしてください。");
      return;
    }

    if (!response.ok) {
      setMessage("景品BOXを取得できませんでした。");
      return;
    }

    const payload = (await response.json()) as PrizeCollection;
    setPrizes(payload.data);
    setSelectedPrizeIds((ids) => ids.filter((id) => payload.data.some((prize) => prize.id === id && prize.status === "stored")));
  }, [selectedStatus]);

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
          setWallet(restoredSession.user.wallet ?? null);
          void fetchWallet(restoredSession);
          void fetchPrizes(restoredSession);
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
  }, [fetchPrizes, fetchWallet]);

  async function handleLogin(event: FormEvent<HTMLFormElement>): Promise<void> {
    event.preventDefault();
    setLoading(true);
    setMessage(null);

    try {
      const response = await fetch(`${getPublicApiBaseUrl()}/login`, {
        method: "POST",
        headers: {
          accept: "application/json",
          "content-type": "application/json",
        },
        body: JSON.stringify({
          email,
          password,
          device_name: "public-web",
        }),
      });
      const payload = (await response.json()) as UserSession | ApiErrorResponse;

      if (!response.ok) {
        throw new Error(readApiError(payload, "ログインできませんでした。"));
      }

      const nextSession = payload as UserSession;
      window.localStorage.setItem(sessionStorageKey, JSON.stringify(nextSession));
      window.dispatchEvent(new Event(sessionChangedEvent));
      setSession(nextSession);
      setWallet(nextSession.user.wallet ?? null);
      setPassword("");
      setMessage("ログインしました。景品BOXを取得しました。");
      await Promise.all([fetchWallet(nextSession), fetchPrizes(nextSession)]);
    } catch (error) {
      setMessage(error instanceof Error ? error.message : "ログインできませんでした。");
    } finally {
      setLoading(false);
    }
  }

  async function handleStatusChange(status: (typeof statuses)[number]): Promise<void> {
    setSelectedStatus(status);

    if (session) {
      await fetchPrizes(session, status);
    }
  }

  async function handleExchange(userPrizeId: number): Promise<void> {
    if (!session) {
      setMessage("ログインしてください。");
      return;
    }

    setLoading(true);
    setMessage(null);

    try {
      const response = await fetch(`${getPublicApiBaseUrl()}/me/prizes/${userPrizeId}/exchange`, {
        method: "POST",
        headers: authHeaders(session),
      });
      const payload = (await response.json()) as ApiErrorResponse;

      if (!response.ok) {
        throw new Error(readApiError(payload, "ポイント交換に失敗しました。"));
      }

      setMessage("景品をポイントに交換しました。");
      await Promise.all([fetchWallet(session), fetchPrizes(session)]);
    } catch (error) {
      setMessage(error instanceof Error ? error.message : "ポイント交換に失敗しました。");
    } finally {
      setLoading(false);
    }
  }

  async function handleShippingRequest(event: FormEvent<HTMLFormElement>): Promise<void> {
    event.preventDefault();

    if (!session) {
      setMessage("ログインしてください。");
      return;
    }

    if (selectedStoredCount === 0) {
      setMessage("配送申請する景品を選択してください。");
      return;
    }

    setLoading(true);
    setMessage(null);

    try {
      const response = await fetch(`${getPublicApiBaseUrl()}/me/shipping-requests`, {
        method: "POST",
        headers: {
          ...authHeaders(session),
          "content-type": "application/json",
        },
        body: JSON.stringify({
          ...shippingForm,
          user_prize_ids: selectedPrizeIds,
        }),
      });
      const payload = (await response.json()) as ApiErrorResponse;

      if (!response.ok) {
        throw new Error(readApiError(payload, "配送申請に失敗しました。"));
      }

      setMessage("配送申請を受け付けました。");
      setSelectedPrizeIds([]);
      setShippingForm(initialShippingForm);
      await fetchPrizes(session);
    } catch (error) {
      setMessage(error instanceof Error ? error.message : "配送申請に失敗しました。");
    } finally {
      setLoading(false);
    }
  }

  function togglePrize(id: number): void {
    setSelectedPrizeIds((ids) => (
      ids.includes(id) ? ids.filter((selectedId) => selectedId !== id) : [...ids, id]
    ));
  }

  if (!authReady) {
    return <section className="mypage-panel"><p className="public-empty">ログイン状態を確認しています。</p></section>;
  }

  if (!session) {
    return (
      <section className="mypage-panel">
        <div className="public-section-head">
          <h1>景品BOX</h1>
          <span>Login</span>
        </div>
        <form className="draw-login-form mypage-login" onSubmit={handleLogin}>
          <label>
            <span>メールアドレス</span>
            <input autoComplete="email" onChange={(event) => setEmail(event.target.value)} type="email" value={email} required />
          </label>
          <label>
            <span>パスワード</span>
            <input autoComplete="current-password" onChange={(event) => setPassword(event.target.value)} type="password" value={password} required />
          </label>
          <button className="public-primary-link" disabled={loading} type="submit">{loading ? "ログイン中" : "ログイン"}</button>
        </form>
        {message ? <p className="mypage-message">{message}</p> : null}
      </section>
    );
  }

  return (
    <section className="mypage-panel">
      <div className="public-section-head">
        <div>
          <h1>景品BOX</h1>
          <p>{session.user.name} / {session.user.email}</p>
        </div>
        <strong>{(wallet?.total_balance ?? 0).toLocaleString("ja-JP")}pt</strong>
      </div>

      <div className="mypage-tabs" role="group" aria-label="景品ステータス">
        {statuses.map((status) => (
          <button
            className={selectedStatus === status ? "active" : ""}
            key={status}
            onClick={() => void handleStatusChange(status)}
            type="button"
          >
            {statusLabel(status)}
          </button>
        ))}
      </div>

      {message ? <p className="mypage-message">{message}</p> : null}

      <div className="prize-box-layout">
        <div className="user-prize-list">
          {prizes.length === 0 ? (
            <div className="public-empty">
              表示できる景品はありません。<Link href="/#gachas">ガチャ一覧へ</Link>
            </div>
          ) : prizes.map((userPrize) => (
            <article className="user-prize-card" key={userPrize.id}>
              <label className="user-prize-check">
                <input
                  checked={selectedPrizeIds.includes(userPrize.id)}
                  disabled={userPrize.status !== "stored" || loading}
                  onChange={() => togglePrize(userPrize.id)}
                  type="checkbox"
                />
              </label>
              <div className="user-prize-image">
                {userPrize.prize?.image_url ? (
                  <span className="image-fill" role="img" aria-label={userPrize.prize.name} style={{ backgroundImage: `url(${userPrize.prize.image_url})` }} />
                ) : (
                  <span>LP</span>
                )}
              </div>
              <div className="user-prize-body">
                <span>{userPrize.prize?.rank?.display_name ?? "Prize"}</span>
                <h2>{userPrize.prize?.name ?? "景品"}</h2>
                <p>{userPrize.gacha?.title ?? "ガチャ"} / {formatDate(userPrize.acquired_at)}</p>
                <div className="user-prize-meta">
                  <strong>{statusLabel(userPrize.status)}</strong>
                  <span>交換 {pointLabel(userPrize.prize?.exchange_point)}</span>
                  <span>期限 {formatDate(userPrize.storage_expire_at)}</span>
                </div>
              </div>
              <div className="user-prize-actions">
                <button
                  disabled={loading || userPrize.status !== "stored" || !userPrize.prize?.exchange_point}
                  onClick={() => void handleExchange(userPrize.id)}
                  type="button"
                >
                  ポイント交換
                </button>
              </div>
            </article>
          ))}
        </div>

        <form className="shipping-form" onSubmit={handleShippingRequest}>
          <div>
            <span>Shipping</span>
            <h2>配送申請</h2>
            <p>{selectedStoredCount.toLocaleString("ja-JP")}点選択中</p>
          </div>
          <label>
            <span>宛名</span>
            <input value={shippingForm.recipient_name} onChange={(event) => setShippingForm({ ...shippingForm, recipient_name: event.target.value })} required />
          </label>
          <label>
            <span>郵便番号</span>
            <input value={shippingForm.postal_code} onChange={(event) => setShippingForm({ ...shippingForm, postal_code: event.target.value })} required />
          </label>
          <label>
            <span>都道府県</span>
            <input value={shippingForm.prefecture} onChange={(event) => setShippingForm({ ...shippingForm, prefecture: event.target.value })} required />
          </label>
          <label>
            <span>市区町村</span>
            <input value={shippingForm.city} onChange={(event) => setShippingForm({ ...shippingForm, city: event.target.value })} required />
          </label>
          <label>
            <span>住所1</span>
            <input value={shippingForm.address_line1} onChange={(event) => setShippingForm({ ...shippingForm, address_line1: event.target.value })} required />
          </label>
          <label>
            <span>住所2</span>
            <input value={shippingForm.address_line2} onChange={(event) => setShippingForm({ ...shippingForm, address_line2: event.target.value })} />
          </label>
          <label>
            <span>電話番号</span>
            <input value={shippingForm.phone_number} onChange={(event) => setShippingForm({ ...shippingForm, phone_number: event.target.value })} required />
          </label>
          <button disabled={loading || selectedStoredCount === 0} type="submit">配送申請する</button>
        </form>
      </div>
    </section>
  );
}

function getPublicApiBaseUrl(): string {
  return process.env.NEXT_PUBLIC_API_BASE_URL ?? "/api";
}

function authHeaders(session: UserSession): Record<string, string> {
  return {
    accept: "application/json",
    authorization: `${session.token_type} ${session.access_token}`,
  };
}

function clearStoredSession(): void {
  window.localStorage.removeItem(sessionStorageKey);
  window.dispatchEvent(new Event(sessionChangedEvent));
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

function statusLabel(status: string): string {
  const labels: Record<string, string> = {
    all: "すべて",
    stored: "保管中",
    shipping_requested: "配送申請中",
    shipped: "発送済み",
    converted: "交換済み",
  };

  return labels[status] ?? status;
}

function formatDate(value: string | null): string {
  if (!value) {
    return "-";
  }

  return new Intl.DateTimeFormat("ja-JP", { month: "2-digit", day: "2-digit" }).format(new Date(value));
}

function pointLabel(value: number | null | undefined): string {
  return typeof value === "number" ? `${value.toLocaleString("ja-JP")}pt` : "-";
}
