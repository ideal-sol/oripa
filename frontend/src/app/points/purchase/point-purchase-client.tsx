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

type Payment = {
  id: number;
  provider: string;
  provider_payment_id: string;
  status: string;
  amount: number;
  paid_point_amount: number;
  free_point_amount: number;
  currency: string;
  paid_at: string | null;
};

type PaymentResponse = {
  data: Payment;
};

type PointPlan = {
  id: number;
  name: string;
  amount: number;
  paid_point_amount: number;
  free_point_amount: number;
  sort_order: number;
  is_active: boolean;
};

type ApiErrorResponse = {
  message?: string;
  errors?: Record<string, string[]>;
};

const sessionStorageKey = "oripa_user_session";
const sessionChangedEvent = "oripa-session-changed";

export default function PointPurchaseClient() {
  const [authReady, setAuthReady] = useState(false);
  const [session, setSession] = useState<UserSession | null>(null);
  const [wallet, setWallet] = useState<Wallet | null>(null);
  const [pointPlans, setPointPlans] = useState<PointPlan[]>([]);
  const [selectedPlanId, setSelectedPlanId] = useState<number | null>(null);
  const [payment, setPayment] = useState<Payment | null>(null);
  const [email, setEmail] = useState("");
  const [password, setPassword] = useState("");
  const [termsAccepted, setTermsAccepted] = useState(false);
  const [loading, setLoading] = useState(false);
  const [message, setMessage] = useState<string | null>(null);

  const selectedPlan = useMemo(
    () => pointPlans.find((plan) => plan.id === selectedPlanId) ?? pointPlans[0] ?? null,
    [pointPlans, selectedPlanId],
  );
  const grantPointTotal = selectedPlan ? selectedPlan.paid_point_amount + selectedPlan.free_point_amount : 0;

  const fetchPlans = useCallback(async (): Promise<void> => {
    const response = await fetch(`${getPublicApiBaseUrl()}/point-purchase-plans`, {
      headers: { accept: "application/json" },
    });

    if (!response.ok) {
      setMessage("ポイント購入プランを取得できませんでした。");
      return;
    }

    const payload = (await response.json()) as { data: PointPlan[] };
    setPointPlans(payload.data);
    setSelectedPlanId((current) => current ?? payload.data[0]?.id ?? null);
  }, []);

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
        } catch {
          clearStoredSession();
        }
      } else if (active) {
        setSession(null);
        setWallet(null);
        setPayment(null);
        setTermsAccepted(false);
      }

      if (active) {
        setAuthReady(true);
      }
    }

    function handleSessionChange(): void {
      void restoreSession();
    }

    window.addEventListener("storage", handleSessionChange);
    window.addEventListener(sessionChangedEvent, handleSessionChange);

    void fetchPlans();
    void restoreSession();

    return () => {
      active = false;
      window.removeEventListener("storage", handleSessionChange);
      window.removeEventListener(sessionChangedEvent, handleSessionChange);
    };
  }, [fetchPlans, fetchWallet]);

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
      setMessage("ログインしました。");
      await fetchWallet(nextSession);
    } catch (error) {
      setMessage(error instanceof Error ? error.message : "ログインできませんでした。");
    } finally {
      setLoading(false);
    }
  }

  async function handleCreatePayment(): Promise<void> {
    if (!session) {
      setMessage("ポイント購入にはログインが必要です。");
      return;
    }

    if (!termsAccepted) {
      setMessage("購入内容と利用条件を確認してください。");
      return;
    }

    if (!selectedPlan) {
      setMessage("ポイント購入プランを選択してください。");
      return;
    }

    setLoading(true);
    setMessage(null);

    try {
      const response = await fetch(`${getPublicApiBaseUrl()}/payments`, {
        method: "POST",
        headers: {
          ...authHeaders(session),
          "content-type": "application/json",
        },
        body: JSON.stringify({
          point_purchase_plan_id: selectedPlan.id,
          provider: "mock",
          currency: "JPY",
          terms_accepted: true,
        }),
      });
      const payload = (await response.json()) as PaymentResponse | ApiErrorResponse;

      if (!response.ok) {
        throw new Error(readApiError(payload, "購入申込を作成できませんでした。"));
      }

      setPayment((payload as PaymentResponse).data);
      setMessage("購入申込を作成しました。検証環境では次のボタンで決済成功を反映できます。");
    } catch (error) {
      setMessage(error instanceof Error ? error.message : "購入申込を作成できませんでした。");
    } finally {
      setLoading(false);
    }
  }

  async function handleMockSucceed(): Promise<void> {
    if (!session || !payment) {
      return;
    }

    setLoading(true);
    setMessage(null);

    try {
      const response = await fetch(`${getPublicApiBaseUrl()}/payments/${payment.id}/mock-succeed`, {
        method: "POST",
        headers: authHeaders(session),
      });
      const payload = (await response.json()) as PaymentResponse | ApiErrorResponse;

      if (!response.ok) {
        throw new Error(readApiError(payload, "決済成功を反映できませんでした。"));
      }

      setPayment((payload as PaymentResponse).data);
      setMessage("ポイントを付与しました。残高を更新しました。");
      await fetchWallet(session);
    } catch (error) {
      setMessage(error instanceof Error ? error.message : "決済成功を反映できませんでした。");
    } finally {
      setLoading(false);
    }
  }

  if (!authReady) {
    return <section className="purchase-panel"><p className="public-empty">ログイン状態を確認しています。</p></section>;
  }

  return (
    <section className="purchase-panel">
      <div className="purchase-head">
        <div>
          <span>Point</span>
          <h1>ポイント購入</h1>
          <p>購入するポイントを選択し、決済完了後に有償ポイントとして反映します。無償ボーナスには有効期限があります。</p>
        </div>
        <strong>{(wallet?.total_balance ?? 0).toLocaleString("ja-JP")}pt</strong>
      </div>

      {!session ? (
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
      ) : (
        <div className="purchase-user">
          <div>
            <span>ログイン中</span>
            <strong>{session.user.name}</strong>
            <p>{session.user.email}</p>
          </div>
          <Link className="public-secondary-link light" href="/mypage/prizes">景品BOX</Link>
        </div>
      )}

      {pointPlans.length === 0 ? (
        <div className="public-empty">現在購入可能なポイントプランはありません。</div>
      ) : (
        <div className="point-plan-grid">
          {pointPlans.map((plan) => (
          <button
            className={selectedPlan?.id === plan.id ? "active" : ""}
            disabled={loading}
            key={plan.id}
            onClick={() => {
              setSelectedPlanId(plan.id);
              setPayment(null);
            }}
            type="button"
          >
            <span>{plan.name}</span>
            <strong>{plan.paid_point_amount.toLocaleString("ja-JP")}pt</strong>
            <small>
              {plan.free_point_amount > 0 ? `+${plan.free_point_amount.toLocaleString("ja-JP")}pt 無償` : "ボーナスなし"}
            </small>
          </button>
          ))}
        </div>
      )}

      {selectedPlan ? <div className="purchase-summary">
        <div>
          <span>支払金額</span>
          <strong>{selectedPlan.amount.toLocaleString("ja-JP")}円</strong>
        </div>
        <div>
          <span>有償ポイント</span>
          <strong>{selectedPlan.paid_point_amount.toLocaleString("ja-JP")}pt</strong>
        </div>
        <div>
          <span>無償ポイント</span>
          <strong>{selectedPlan.free_point_amount.toLocaleString("ja-JP")}pt</strong>
        </div>
        <div>
          <span>付与合計</span>
          <strong>{grantPointTotal.toLocaleString("ja-JP")}pt</strong>
        </div>
      </div> : null}

      <label className="terms-check">
        <input checked={termsAccepted} onChange={(event) => setTermsAccepted(event.target.checked)} type="checkbox" />
        <span>購入内容、ポイントの扱い、無償ポイントの有効期限を確認しました。</span>
      </label>

      <div className="draw-actions">
        <button className="public-primary-link" disabled={!session || loading || !termsAccepted || !selectedPlan} onClick={handleCreatePayment} type="button">
          {loading ? "処理中" : "購入申込を作成"}
        </button>
        {payment?.status === "pending" ? (
          <button className="public-secondary-link light" disabled={loading} onClick={handleMockSucceed} type="button">
            検証用: 決済成功を反映
          </button>
        ) : null}
      </div>

      {message ? <p className="mypage-message">{message}</p> : null}

      {payment ? (
        <div className="payment-result">
          <div>
            <span>決済ID</span>
            <strong>#{payment.id}</strong>
          </div>
          <div>
            <span>状態</span>
            <strong>{paymentStatusLabel(payment.status)}</strong>
          </div>
          <div>
            <span>付与ポイント</span>
            <strong>{(payment.paid_point_amount + payment.free_point_amount).toLocaleString("ja-JP")}pt</strong>
          </div>
        </div>
      ) : null}
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

function paymentStatusLabel(status: string): string {
  const labels: Record<string, string> = {
    pending: "決済待ち",
    succeeded: "決済成功",
    failed: "失敗",
    canceled: "キャンセル",
    refunded: "返金済み",
    chargeback: "チャージバック",
  };

  return labels[status] ?? status;
}
