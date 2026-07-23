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

type DrawResult = {
  id: number;
  draw_sequence_number: number;
  result_type: string;
  consumed_point: number;
  granted_point: number;
  rank?: {
    display_name: string;
    result_image_url?: string | null;
  } | null;
  prize?: {
    name: string;
    image_url: string | null;
    exchange_point: number | null;
  } | null;
};

type DrawRequest = {
  id: number;
  draw_count: number;
  status: string;
  consumed_point_total: number;
  results_count?: number;
  gacha?: {
    id: number;
    title: string;
    slug: string;
    price: number;
    main_image_url: string | null;
  } | null;
  results?: DrawResult[];
  created_at: string | null;
};

const sessionStorageKey = "oripa_user_session";

export default function DrawHistoryClient() {
  const [authReady, setAuthReady] = useState(false);
  const [session, setSession] = useState<UserSession | null>(null);
  const [drawRequests, setDrawRequests] = useState<DrawRequest[]>([]);
  const [message, setMessage] = useState<string | null>(null);
  const [loading, setLoading] = useState(false);

  const fetchDraws = useCallback(async (targetSession: UserSession): Promise<void> => {
    setLoading(true);
    setMessage(null);

    try {
      const response = await fetch(`${getPublicApiBaseUrl()}/me/draw-requests?per_page=50`, {
        headers: authHeaders(targetSession),
      });

      if (response.status === 401) {
        clearStoredSession();
        setSession(null);
        setDrawRequests([]);
        setMessage("ログイン状態の確認に失敗しました。再度ログインしてください。");
        return;
      }

      if (!response.ok) {
        throw new Error("抽選履歴を取得できませんでした。");
      }

      const payload = (await response.json()) as { data: DrawRequest[] };
      setDrawRequests(payload.data);
    } catch (error) {
      setMessage(error instanceof Error ? error.message : "抽選履歴を取得できませんでした。");
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
          void fetchDraws(restoredSession);
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
  }, [fetchDraws]);

  if (!authReady) {
    return <section className="mypage-panel">読み込み中...</section>;
  }

  if (!session) {
    return (
      <section className="mypage-panel">
        <div className="public-section-head">
          <div>
            <span>MY PAGE</span>
            <h1>抽選履歴</h1>
            <p>抽選履歴の確認にはログインが必要です。</p>
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
          <span>DRAW HISTORY</span>
          <h1>抽選履歴</h1>
          <p>抽選日時、消費ポイント、獲得した景品を確認できます。</p>
        </div>
        <button type="button" onClick={() => void fetchDraws(session)} disabled={loading}>
          更新
        </button>
      </div>

      {message ? <div className="mypage-message">{message}</div> : null}

      {drawRequests.length > 0 ? (
        <div className="history-list">
          {drawRequests.map((request) => (
            <article className="history-card draw-history-card" key={request.id}>
              <div className="history-thumb">
                {request.gacha?.main_image_url ? <span style={{ backgroundImage: `url(${request.gacha.main_image_url})` }} /> : "LP"}
              </div>
              <div>
                <span>{formatDateTime(request.created_at)}</span>
                <h2>{request.gacha?.title ?? `抽選 #${request.id}`}</h2>
                <p>
                  {request.draw_count}回 / {formatPoint(request.consumed_point_total)}pt / {statusLabel(request.status)}
                </p>
                <div className="history-result-list">
                  {(request.results ?? []).map((result) => (
                    <small key={result.id}>
                      #{result.draw_sequence_number} {result.rank?.display_name ?? "結果"}:{" "}
                      {result.result_type === "point_back"
                        ? `${formatPoint(result.granted_point)}pt還元`
                        : result.prize?.name ?? "景品"}
                    </small>
                  ))}
                </div>
              </div>
              {request.gacha ? (
                <Link className="public-secondary-link light" href={`/gachas/${request.gacha.id}`}>
                  もう一度引く
                </Link>
              ) : null}
            </article>
          ))}
        </div>
      ) : (
        <div className="public-empty">抽選履歴はまだありません。</div>
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

function formatPoint(value: number): string {
  return new Intl.NumberFormat("ja-JP").format(value);
}

function formatDateTime(value: string | null): string {
  return value ? new Intl.DateTimeFormat("ja-JP", { dateStyle: "medium", timeStyle: "short" }).format(new Date(value)) : "-";
}

function statusLabel(value: string): string {
  const labels: Record<string, string> = {
    completed: "完了",
    pending: "処理中",
    failed: "失敗",
  };

  return labels[value] ?? value;
}
