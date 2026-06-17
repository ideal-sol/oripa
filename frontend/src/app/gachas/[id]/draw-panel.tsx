"use client";

import Link from "next/link";
import { useRouter } from "next/navigation";
import { useCallback, useEffect, useMemo, useRef, useState } from "react";

type DrawPanelProps = {
  gachaId: number;
  price: number;
  remainingCount: number;
};

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

type DrawResult = {
  id: number;
  draw_sequence_number: number;
  result_type: "prize" | "point_back";
  rank_id: number | null;
  prize_id: number | null;
  rank?: {
    id: number;
    rank_key: string;
    display_name: string;
    image_url: string | null;
    draw_video_url: string | null;
    result_image_url: string | null;
  } | null;
  prize?: {
    id: number;
    name: string;
    image_url: string | null;
    display_price: number | null;
    exchange_point: number | null;
  } | null;
  consumed_point: number;
  granted_point: number;
};

type DrawResponse = {
  data: {
    id: number;
    draw_count: number;
    status: string;
    consumed_point_total: number;
    results: DrawResult[];
  };
};

type ApiErrorResponse = {
  message?: string;
  errors?: Record<string, string[]>;
};

const drawOptions = [1, 5, 10];
const sessionStorageKey = "oripa_user_session";
const defaultDrawMovieSrc = "/draw-videos/default.mp4";
const defaultDrawResultImageSrc = "/draw-image/gacha.png";

export default function DrawPanel({ gachaId, price, remainingCount }: DrawPanelProps) {
  const router = useRouter();
  const drawMovieRef = useRef<HTMLVideoElement | null>(null);
  const [authReady, setAuthReady] = useState(false);
  const [session, setSession] = useState<UserSession | null>(null);
  const [wallet, setWallet] = useState<Wallet | null>(null);
  const [drawCount, setDrawCount] = useState(1);
  const [loading, setLoading] = useState(false);
  const [revealing, setRevealing] = useState(false);
  const [message, setMessage] = useState<string | null>(null);
  const [drawResponse, setDrawResponse] = useState<DrawResponse["data"] | null>(null);
  const [pendingDrawResponse, setPendingDrawResponse] = useState<DrawResponse["data"] | null>(null);
  const [drawMovieNeedsGesture, setDrawMovieNeedsGesture] = useState(false);

  const totalPoint = useMemo(() => price * drawCount, [drawCount, price]);
  const availableOptions = drawOptions.filter((count) => count <= Math.max(1, remainingCount));
  const isLoggedIn = Boolean(session);
  const currentBalance = wallet?.total_balance ?? session?.user.wallet?.total_balance ?? 0;
  const hasEnoughPoints = currentBalance >= totalPoint;
  const canPressDraw = authReady && remainingCount >= drawCount && !loading && !revealing && !drawResponse && (!isLoggedIn || hasEnoughPoints);
  const drawLoginUrl = `/login?redirect=${encodeURIComponent(`/gachas/${gachaId}`)}`;
  const pointPurchaseLoginUrl = `/login?redirect=${encodeURIComponent("/points/purchase")}`;

  const fetchWallet = useCallback(async (targetSession: UserSession): Promise<void> => {
    const response = await fetch(`${getPublicApiBaseUrl()}/me/points`, {
      headers: {
        accept: "application/json",
        authorization: `${targetSession.token_type} ${targetSession.access_token}`,
      },
    });

    if (response.status === 401) {
      window.localStorage.removeItem(sessionStorageKey);
      setSession(null);
      setWallet(null);
      setMessage("ログイン状態の確認に失敗しました。再度ログインしてください。");
      return;
    }

    if (!response.ok) {
      setMessage("ポイント残高を取得できませんでした。時間をおいて再度お試しください。");
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
          window.localStorage.removeItem(sessionStorageKey);
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
  }, [fetchWallet]);

  useEffect(() => {
    if (!revealing && !drawResponse) {
      return;
    }

    const originalOverflow = document.body.style.overflow;
    document.body.style.overflow = "hidden";

    return () => {
      document.body.style.overflow = originalOverflow;
    };
  }, [drawResponse, revealing]);

  async function handleDraw(): Promise<void> {
    if (!session) {
      router.push(drawLoginUrl);
      return;
    }

    if (!hasEnoughPoints) {
      setMessage("ポイント残高が不足しています。ポイント購入後に再度お試しください。");
      return;
    }

    setLoading(true);
    setMessage(null);
    setDrawResponse(null);

    try {
      const response = await fetch(`${getPublicApiBaseUrl()}/gachas/${gachaId}/draw`, {
        method: "POST",
        headers: {
          accept: "application/json",
          authorization: `${session.token_type} ${session.access_token}`,
          "content-type": "application/json",
        },
        body: JSON.stringify({
          draw_count: drawCount,
          idempotency_key: crypto.randomUUID(),
        }),
      });

      const payload = (await response.json()) as DrawResponse | ApiErrorResponse;

      if (!response.ok) {
        throw new Error(readApiError(payload, "抽選に失敗しました。時間をおいて再度お試しください。"));
      }

      setPendingDrawResponse((payload as DrawResponse).data);
      setDrawMovieNeedsGesture(false);
      setRevealing(true);
      setMessage("抽選演出中です。動画をクリックまたはタップすると結果を表示します。");
      await fetchWallet(session);
    } catch (error) {
      setMessage(error instanceof Error ? error.message : "抽選に失敗しました。");
      await fetchWallet(session);
    } finally {
      setLoading(false);
    }
  }

  function revealDrawResult(): void {
    if (!pendingDrawResponse) {
      return;
    }

    setDrawResponse(pendingDrawResponse);
    setPendingDrawResponse(null);
    setRevealing(false);
    setDrawMovieNeedsGesture(false);
    setMessage("抽選が完了しました。ポイント残高を更新しました。");
  }

  function closeDrawResult(): void {
    setDrawResponse(null);
    setMessage(null);
    router.refresh();
  }

  function continueAfterDraw(): void {
    if (drawResponse && remainingCount - drawResponse.draw_count <= 0) {
      router.push("/#gachas");
      router.refresh();
      return;
    }

    closeDrawResult();
  }

  async function startDrawMovie(video = drawMovieRef.current): Promise<boolean> {
    if (!video) {
      setDrawMovieNeedsGesture(true);
      return false;
    }

    video.muted = false;
    video.volume = 1;

    try {
      await video.play();
      setDrawMovieNeedsGesture(false);
      return true;
    } catch {
      setDrawMovieNeedsGesture(true);
      return false;
    }
  }

  async function handleDrawMovieClick(): Promise<void> {
    const video = drawMovieRef.current;
    const hasNotStarted = Boolean(video && video.paused && video.currentTime === 0 && !video.ended);
    const shouldStartMovie = drawMovieNeedsGesture || hasNotStarted;

    if (shouldStartMovie) {
      const started = await startDrawMovie(video);

      if (!started) {
        return;
      }

      return;
    }

    revealDrawResult();
  }

  return (
    <section className="draw-panel" aria-label="抽選操作">
      <div className="draw-panel-head">
        <div>
          <span>Draw</span>
          <h2>抽選</h2>
          <p>回数と消費ポイントを確認して抽選します。</p>
        </div>
        <strong>{totalPoint.toLocaleString("ja-JP")}pt</strong>
      </div>

      <div className="draw-count-selector" role="group" aria-label="抽選回数">
        {availableOptions.map((count) => (
          <button
            className={drawCount === count ? "active" : ""}
            type="button"
            key={count}
            onClick={() => setDrawCount(count)}
          >
            {count}回
          </button>
        ))}
      </div>

      <div className="draw-summary">
        <div>
          <span>1回</span>
          <strong>{price.toLocaleString("ja-JP")}pt</strong>
        </div>
        <div>
          <span>選択回数</span>
          <strong>{drawCount.toLocaleString("ja-JP")}回</strong>
        </div>
        <div>
          <span>残高</span>
          <strong>{authReady && isLoggedIn ? `${currentBalance.toLocaleString("ja-JP")}pt` : "-"}</strong>
        </div>
      </div>

      <div className="draw-summary">
        <div>
          <span>残り口数</span>
          <strong>{remainingCount.toLocaleString("ja-JP")}口</strong>
        </div>
        <div>
          <span>消費予定</span>
          <strong>{totalPoint.toLocaleString("ja-JP")}pt</strong>
        </div>
        <div>
          <span>抽選後残高</span>
          <strong>{authReady && isLoggedIn && hasEnoughPoints ? `${(currentBalance - totalPoint).toLocaleString("ja-JP")}pt` : "-"}</strong>
        </div>
      </div>

      <div className="draw-actions">
        <button className="public-primary-link" disabled={!canPressDraw} type="button" onClick={handleDraw}>
          {loading ? "処理中" : revealing ? "抽選演出中" : `${drawCount}回抽選`}
        </button>
        {!isLoggedIn || !hasEnoughPoints ? (
          <Link className="public-secondary-link light" href={isLoggedIn ? "/points/purchase" : pointPurchaseLoginUrl}>
            ポイント購入
          </Link>
        ) : null}
      </div>

      {message ? <p className="draw-message">{message}</p> : null}

      {isLoggedIn && !hasEnoughPoints ? (
        <div className="draw-guidance warning">
          <strong>ポイントが不足しています</strong>
          <p>必要ポイントに対して残高が不足しています。ポイント購入後に再度抽選してください。</p>
        </div>
      ) : null}

      {revealing && pendingDrawResponse ? (
        <div className="draw-movie-overlay" aria-live="polite" role="dialog" aria-modal="true">
          <div className="draw-movie-panel">
            <div className="draw-movie-head">
              <span>Opening</span>
              <strong>{drawMovieLabel(pendingDrawResponse)}</strong>
            </div>
            <button className="draw-movie-stage" type="button" onClick={handleDrawMovieClick} aria-label="抽選結果を表示">
              <video
                ref={drawMovieRef}
                autoPlay
                playsInline
                preload="auto"
                src={drawMovieSrc(pendingDrawResponse)}
                onCanPlay={(event) => {
                  void startDrawMovie(event.currentTarget);
                }}
                onError={(event) => {
                  const video = event.currentTarget;

                  if (!video.src.endsWith(defaultDrawMovieSrc)) {
                    video.src = defaultDrawMovieSrc;
                    void startDrawMovie(video);
                  }
                }}
              />
              <span>{drawMovieNeedsGesture ? "タップして音声付きで再生" : "クリックまたはタップで結果表示"}</span>
            </button>
          </div>
        </div>
      ) : null}

      {drawResponse ? (
        <div className="draw-result-overlay" role="dialog" aria-modal="true" aria-live="polite">
          <div
            className="draw-result-visual"
            aria-hidden="true"
            style={{ backgroundImage: `url("${primaryDrawResultImageSrc(drawResponse)}")` }}
          />
          <div className="draw-result-bottom">
            <div className="draw-result-title">
              <span>Result</span>
              <strong>抽選結果</strong>
              <small>{drawResponse.consumed_point_total.toLocaleString("ja-JP")}pt 消費</small>
            </div>
            <ul className="draw-result-list">
              {drawResponse.results.map((result) => (
                <li key={result.id}>
                  <span
                    className="draw-result-thumb"
                    aria-hidden="true"
                    style={{ backgroundImage: `url("${drawResultImageSrc(result)}")` }}
                  />
                  <span>#{result.draw_sequence_number}</span>
                  <strong>{formatDrawResult(result)}</strong>
                </li>
              ))}
            </ul>
            <div className="draw-result-actions">
              <button className="public-primary-link" type="button" onClick={continueAfterDraw}>
                {remainingCount - drawResponse.draw_count <= 0 ? "ガチャ一覧へ戻る" : "続けてガチャを引く"}
              </button>
              <button className="public-secondary-link light" type="button" onClick={closeDrawResult}>
                戻る
              </button>
            </div>
          </div>
        </div>
      ) : null}
    </section>
  );
}

function getPublicApiBaseUrl(): string {
  return process.env.NEXT_PUBLIC_API_BASE_URL ?? "/api";
}

function readApiError(payload: UserSession | DrawResponse | ApiErrorResponse, fallback: string): string {
  if ("errors" in payload && payload.errors) {
    const firstError = Object.values(payload.errors).flat()[0];

    if (firstError) {
      return firstError;
    }
  }

  if ("message" in payload && payload.message) {
    return payload.message;
  }

  return fallback;
}

function formatDrawResult(result: DrawResult): string {
  if (result.result_type === "point_back") {
    return `${result.granted_point.toLocaleString("ja-JP")}pt還元`;
  }

  if (result.prize?.name) {
    return result.rank?.display_name ? `${result.rank.display_name}: ${result.prize.name}` : result.prize.name;
  }

  return result.prize_id ? `景品ID #${result.prize_id}` : "景品当選";
}

function drawResultImageSrc(result: DrawResult): string {
  if (result.result_type === "prize" && result.prize?.image_url) {
    return result.prize.image_url;
  }

  return defaultDrawResultImageSrc;
}

function primaryDrawResultImageSrc(drawResponse: DrawResponse["data"]): string {
  const primaryPrize = primaryPrizeResult(drawResponse);

  if (primaryPrize?.rank?.image_url) {
    return primaryPrize.rank.image_url;
  }

  if (primaryPrize) {
    return drawResultImageSrc(primaryPrize);
  }

  const firstResult = drawResponse.results[0];

  return firstResult?.rank?.image_url ?? (firstResult ? drawResultImageSrc(firstResult) : defaultDrawResultImageSrc);
}

function drawMovieSrc(drawResponse: DrawResponse["data"]): string {
  const primaryPrize = primaryPrizeResult(drawResponse);

  if (primaryPrize?.rank?.draw_video_url) {
    return primaryPrize.rank.draw_video_url;
  }

  return defaultDrawMovieSrc;
}

function drawMovieLabel(drawResponse: DrawResponse["data"]): string {
  const primaryPrize = drawResponse.results.find((result) => result.result_type === "prize" && result.rank?.display_name);

  if (primaryPrize?.rank?.display_name) {
    return `${primaryPrize.rank.display_name} 演出`;
  }

  return "ポイント還元演出";
}

function primaryPrizeResult(drawResponse: DrawResponse["data"]): DrawResult | null {
  const prizeResults = drawResponse.results.filter((result) => result.result_type === "prize" && result.rank?.rank_key);

  if (prizeResults.length === 0) {
    return null;
  }

  return [...prizeResults]
    .sort((left, right) => rankPriority(normalizeRankKey(left.rank?.rank_key)) - rankPriority(normalizeRankKey(right.rank?.rank_key)))[0] ?? null;
}

function normalizeRankKey(rankKey: string | undefined): string {
  return (rankKey ?? "default")
    .trim()
    .toLowerCase()
    .replace(/[^a-z0-9_-]/g, "_") || "default";
}

function rankPriority(rankKey: string): number {
  const priorities: Record<string, number> = {
    s: 1,
    ss: 0,
    a: 2,
    b: 3,
    c: 4,
    d: 5,
    e: 6,
  };

  return priorities[rankKey] ?? 50;
}
