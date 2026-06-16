"use client";

import Link from "next/link";
import { useCallback, useEffect, useState } from "react";

type Wallet = {
  paid_balance: number;
  free_balance: number;
  total_balance: number;
};

type UserSession = {
  token_type: "Bearer";
  access_token: string;
  user: {
    name: string;
    email: string;
    wallet?: Wallet | null;
  };
};

type PointLot = {
  id: number;
  point_type: string;
  granted_amount: number;
  remaining_amount: number;
  source_type: string | null;
  granted_at: string | null;
  expire_at: string | null;
};

type PointLedger = {
  id: number;
  point_type: string;
  ledger_type: string;
  amount: number;
  balance_after: number;
  description: string | null;
  point_lot?: PointLot | null;
  created_at: string | null;
};

const sessionStorageKey = "oripa_user_session";

export default function PointHistoryClient() {
  const [authReady, setAuthReady] = useState(false);
  const [session, setSession] = useState<UserSession | null>(null);
  const [wallet, setWallet] = useState<Wallet | null>(null);
  const [lots, setLots] = useState<PointLot[]>([]);
  const [ledgers, setLedgers] = useState<PointLedger[]>([]);
  const [message, setMessage] = useState<string | null>(null);
  const [loading, setLoading] = useState(false);

  const fetchHistory = useCallback(async (targetSession: UserSession): Promise<void> => {
    setLoading(true);
    setMessage(null);

    try {
      const [pointsResponse, ledgersResponse] = await Promise.all([
        fetch(`${getPublicApiBaseUrl()}/me/points`, { headers: authHeaders(targetSession) }),
        fetch(`${getPublicApiBaseUrl()}/me/point-ledgers?per_page=50`, { headers: authHeaders(targetSession) }),
      ]);

      if (pointsResponse.status === 401 || ledgersResponse.status === 401) {
        clearStoredSession();
        setSession(null);
        setWallet(null);
        setLots([]);
        setLedgers([]);
        setMessage("ログイン状態の確認に失敗しました。再度ログインしてください。");
        return;
      }

      if (!pointsResponse.ok || !ledgersResponse.ok) {
        throw new Error("ポイント履歴を取得できませんでした。");
      }

      const pointsPayload = (await pointsResponse.json()) as { wallet: Wallet; lots: PointLot[] };
      const ledgersPayload = (await ledgersResponse.json()) as { data: PointLedger[] };
      setWallet(pointsPayload.wallet);
      setLots(pointsPayload.lots);
      setLedgers(ledgersPayload.data);
    } catch (error) {
      setMessage(error instanceof Error ? error.message : "ポイント履歴を取得できませんでした。");
    } finally {
      setLoading(false);
    }
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
          void fetchHistory(restoredSession);
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
  }, [fetchHistory]);

  if (!authReady) {
    return <section className="mypage-panel">読み込み中...</section>;
  }

  if (!session) {
    return (
      <section className="mypage-panel">
        <div className="public-section-head">
          <div>
            <span>MY PAGE</span>
            <h1>ポイント履歴</h1>
            <p>履歴の確認にはログインが必要です。</p>
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
          <span>POINT HISTORY</span>
          <h1>ポイント履歴</h1>
          <p>有償ポイントは期限なし、無償ポイントのみ期限があります。</p>
        </div>
        <strong>{formatPoint(wallet?.total_balance ?? 0)}pt</strong>
      </div>

      {message ? <div className="mypage-message">{message}</div> : null}

      <div className="history-metrics">
        <div>
          <span>合計残高</span>
          <strong>{formatPoint(wallet?.total_balance ?? 0)}pt</strong>
        </div>
        <div>
          <span>有償</span>
          <strong>{formatPoint(wallet?.paid_balance ?? 0)}pt</strong>
        </div>
        <div>
          <span>無償</span>
          <strong>{formatPoint(wallet?.free_balance ?? 0)}pt</strong>
        </div>
      </div>

      <div className="history-section">
        <div className="public-section-head">
          <div>
            <span>LEDGER</span>
            <h2>ポイント明細</h2>
          </div>
          <button type="button" onClick={() => void fetchHistory(session)} disabled={loading}>
            更新
          </button>
        </div>

        {ledgers.length > 0 ? (
          <div className="history-list">
            {ledgers.map((ledger) => (
              <article className="history-card" key={ledger.id}>
                <div>
                  <span>{formatDateTime(ledger.created_at)}</span>
                  <h2>{ledger.description ?? ledgerTypeLabel(ledger.ledger_type)}</h2>
                  <p>
                    {pointTypeLabel(ledger.point_type)} / 残高 {formatPoint(ledger.balance_after)}pt
                  </p>
                </div>
                <strong className={ledger.amount < 0 ? "negative" : "positive"}>
                  {ledger.amount > 0 ? "+" : ""}
                  {formatPoint(ledger.amount)}pt
                </strong>
              </article>
            ))}
          </div>
        ) : (
          <div className="public-empty">ポイント明細はまだありません。</div>
        )}
      </div>

      <div className="history-section">
        <div className="public-section-head">
          <div>
            <span>LOTS</span>
            <h2>保有ポイントロット</h2>
          </div>
        </div>
        {lots.length > 0 ? (
          <div className="history-table">
            {lots.map((lot) => (
              <div className="history-row" key={lot.id}>
                <strong>{pointTypeLabel(lot.point_type)}</strong>
                <span>{formatPoint(lot.remaining_amount)} / {formatPoint(lot.granted_amount)}pt</span>
                <span>期限 {formatDate(lot.expire_at) ?? "なし"}</span>
              </div>
            ))}
          </div>
        ) : (
          <div className="public-empty">保有ポイントはありません。</div>
        )}
      </div>
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
  window.dispatchEvent(new Event("oripa-session-changed"));
}

function getPublicApiBaseUrl(): string {
  return process.env.NEXT_PUBLIC_API_BASE_URL ?? "/api";
}

function formatPoint(value: number): string {
  return new Intl.NumberFormat("ja-JP").format(value);
}

function formatDate(value: string | null): string | null {
  return value ? new Intl.DateTimeFormat("ja-JP", { dateStyle: "medium" }).format(new Date(value)) : null;
}

function formatDateTime(value: string | null): string {
  return value ? new Intl.DateTimeFormat("ja-JP", { dateStyle: "medium", timeStyle: "short" }).format(new Date(value)) : "-";
}

function pointTypeLabel(value: string): string {
  return value === "paid" ? "有償" : "無償";
}

function ledgerTypeLabel(value: string): string {
  const labels: Record<string, string> = {
    purchase: "ポイント購入",
    consume: "ガチャ消費",
    refund: "返金",
    expire: "失効",
    exchange: "景品交換",
    adjustment: "調整",
  };

  return labels[value] ?? value;
}
