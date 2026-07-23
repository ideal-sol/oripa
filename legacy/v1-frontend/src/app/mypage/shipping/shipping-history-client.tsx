"use client";

import Link from "next/link";
import { useCallback, useEffect, useState } from "react";

type UserSession = {
  token_type: "Bearer";
  access_token: string;
  user: {
    name: string;
    email: string;
  };
};

type ShippingItem = {
  id: number;
  status: string;
  tracking_number: string | null;
  shipped_at: string | null;
  user_prize?: {
    id: number;
    prize?: {
      name: string;
      image_url: string | null;
      rank?: {
        display_name: string;
      } | null;
    } | null;
    gacha?: {
      title: string;
    } | null;
  } | null;
};

type ShippingRequest = {
  id: number;
  status: string;
  recipient_name: string;
  postal_code: string;
  prefecture: string;
  city: string;
  address_line1: string;
  address_line2: string | null;
  tracking_number: string | null;
  requested_at: string | null;
  shipped_at: string | null;
  items_count?: number;
  items?: ShippingItem[];
};

const sessionStorageKey = "oripa_user_session";

export default function ShippingHistoryClient() {
  const [authReady, setAuthReady] = useState(false);
  const [session, setSession] = useState<UserSession | null>(null);
  const [shippingRequests, setShippingRequests] = useState<ShippingRequest[]>([]);
  const [message, setMessage] = useState<string | null>(null);
  const [loading, setLoading] = useState(false);

  const fetchShippingRequests = useCallback(async (targetSession: UserSession): Promise<void> => {
    setLoading(true);
    setMessage(null);

    try {
      const response = await fetch(`${getPublicApiBaseUrl()}/me/shipping-requests?per_page=50`, {
        headers: authHeaders(targetSession),
      });

      if (response.status === 401) {
        clearStoredSession();
        setSession(null);
        setShippingRequests([]);
        setMessage("ログイン状態の確認に失敗しました。再度ログインしてください。");
        return;
      }

      if (!response.ok) {
        throw new Error("配送履歴を取得できませんでした。");
      }

      const payload = (await response.json()) as { data: ShippingRequest[] };
      setShippingRequests(payload.data);
    } catch (error) {
      setMessage(error instanceof Error ? error.message : "配送履歴を取得できませんでした。");
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
          void fetchShippingRequests(restoredSession);
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
  }, [fetchShippingRequests]);

  if (!authReady) {
    return <section className="mypage-panel">読み込み中...</section>;
  }

  if (!session) {
    return (
      <section className="mypage-panel">
        <div className="public-section-head">
          <div>
            <span>MY PAGE</span>
            <h1>配送履歴</h1>
            <p>配送履歴の確認にはログインが必要です。</p>
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
          <span>SHIPPING HISTORY</span>
          <h1>配送履歴</h1>
          <p>配送申請の状況、送り先、追跡番号を確認できます。</p>
        </div>
        <button type="button" onClick={() => void fetchShippingRequests(session)} disabled={loading}>
          更新
        </button>
      </div>

      {message ? <div className="mypage-message">{message}</div> : null}

      {shippingRequests.length > 0 ? (
        <div className="history-list">
          {shippingRequests.map((request) => (
            <article className="history-card shipping-history-card" key={request.id}>
              {(() => {
                const item = request.items?.[0];

                return (
                  <>
              <div>
                <span>{formatDateTime(request.requested_at)}</span>
                <h2>{item?.user_prize?.prize?.name ?? `配送申請 #${request.id}`}</h2>
                <p>
                  {shippingStatusLabel(item?.status ?? request.status)} / 配送ID #{request.id} / {request.recipient_name}
                </p>
                <p>
                  〒{request.postal_code} {request.prefecture}
                  {request.city}
                  {request.address_line1}
                  {request.address_line2 ?? ""}
                </p>
              </div>
              <div className="history-shipping-meta">
                <strong>{item?.tracking_number ?? request.tracking_number ?? "追跡番号未登録"}</strong>
                <span>発送日 {formatDateTime(item?.shipped_at ?? request.shipped_at)}</span>
              </div>
              <div className="history-result-list full">
                {(request.items ?? []).map((item) => (
                  <small key={item.id}>
                    {item.user_prize?.prize?.rank?.display_name ?? "景品"}: {item.user_prize?.prize?.name ?? "景品名未設定"}
                  </small>
                ))}
              </div>
                  </>
                );
              })()}
            </article>
          ))}
        </div>
      ) : (
        <div className="public-empty">配送履歴はまだありません。</div>
      )}
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

function formatDateTime(value: string | null): string {
  return value ? new Intl.DateTimeFormat("ja-JP", { dateStyle: "medium", timeStyle: "short" }).format(new Date(value)) : "-";
}

function shippingStatusLabel(value: string): string {
  const labels: Record<string, string> = {
    requested: "申請済み",
    packing: "梱包中",
    preparing: "準備中",
    shipped: "発送済み",
    delivered: "配達完了",
    returned: "返送",
    canceled: "キャンセル",
  };

  return labels[value] ?? value;
}
