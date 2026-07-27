"use client";

import Link from "next/link";
import { useRouter } from "next/navigation";
import { useCallback, useEffect, useMemo, useRef, useState } from "react";

type DrawPanelProps = {
  gachaId: number;
  price: number;
  remainingCount: number;
  dailyDrawLimit: number | null;
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
  selected_rank_image_url?: string | null;
  selected_draw_video_url?: string | null;
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

type BulkPrizeCount = {
  prize_name: string;
  prize_image_url: string | null;
  rank_key: string;
  rank_name: string;
  win_count: number;
};

type BulkRankCount = {
  rank_key: string;
  rank_name: string;
  win_count: number;
};

type BulkHighRankResult = {
  draw_sequence_number: number;
  prize_name: string;
  prize_image_url: string | null;
  rank_key: string;
  rank_name: string;
  rank_image_url: string | null;
  draw_video_url: string | null;
};

type BulkDrawResponse = {
  data: {
    bulk_request_id: string;
    requested_count: number;
    executed_count: number;
    consumed_point: number;
    prize_counts: BulkPrizeCount[];
    rank_counts: BulkRankCount[];
    high_rank_results: BulkHighRankResult[];
    high_rank_results_truncated: boolean;
    status: string;
    idempotent_replay: boolean;
    processing_duration_ms: number;
    created_at: string;
  };
};

type BulkAttempt = {
  drawCount: 100 | 1000;
  idempotencyKey: string;
  state: "unknown" | "processing";
};

type BulkResult = {
  response: BulkDrawResponse["data"];
  balanceAfter: number;
  pointBackCount: number;
};

type ApiErrorResponse = {
  message?: string;
  errors?: Record<string, string[]>;
};

const legacyDrawOptions = [1, 5, 10];
const bulkDrawOptions = [100, 1000] as const;
const drawOptions = [...legacyDrawOptions, ...bulkDrawOptions];
const sessionStorageKey = "oripa_user_session";
const defaultDrawMovieSrc = "/draw-videos/default.mp4";
const defaultDrawResultImageSrc = "/draw-image/gacha.png";
const bulkRequestTimeoutMs = 30_000;

export default function DrawPanel({ gachaId, price, remainingCount, dailyDrawLimit }: DrawPanelProps) {
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
  const [confirmationOpen, setConfirmationOpen] = useState(false);
  const [bulkAttempt, setBulkAttempt] = useState<BulkAttempt | null>(null);
  const [bulkResult, setBulkResult] = useState<BulkResult | null>(null);

  const totalPoint = useMemo(() => price * drawCount, [drawCount, price]);
  const maxSelectableDrawCount = Math.max(1, Math.min(remainingCount, dailyDrawLimit ?? remainingCount));
  const visibleDrawOptions = drawOptions.filter((count) => isBulkDrawCount(count) || count <= maxSelectableDrawCount);
  const isLoggedIn = Boolean(session);
  const isBulkDraw = isBulkDrawCount(drawCount);
  const currentBalance = wallet?.total_balance ?? session?.user.wallet?.total_balance ?? 0;
  const hasEnoughPoints = currentBalance >= totalPoint;
  const hasEnoughDraws = remainingCount >= drawCount && (dailyDrawLimit === null || dailyDrawLimit >= drawCount);
  const canPressDraw = authReady
    && hasEnoughDraws
    && !loading
    && !revealing
    && !drawResponse
    && !bulkResult
    && (!isLoggedIn || hasEnoughPoints);
  const drawLoginUrl = `/login?redirect=${encodeURIComponent(`/gachas/${gachaId}`)}`;
  const pointPurchaseLoginUrl = `/login?redirect=${encodeURIComponent("/points/purchase")}`;

  const fetchWallet = useCallback(async (targetSession: UserSession): Promise<Wallet | null> => {
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
      return null;
    }

    if (!response.ok) {
      setMessage("ポイント残高を取得できませんでした。時間をおいて再度お試しください。");
      return null;
    }

    const payload = (await response.json()) as { wallet: Wallet };
    setWallet(payload.wallet);
    return payload.wallet;
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
    if (!revealing && !drawResponse && !bulkResult && !confirmationOpen) {
      return;
    }

    const originalOverflow = document.body.style.overflow;
    document.body.style.overflow = "hidden";

    return () => {
      document.body.style.overflow = originalOverflow;
    };
  }, [bulkResult, confirmationOpen, drawResponse, revealing]);

  async function handleDraw(): Promise<void> {
    if (!session) {
      router.push(drawLoginUrl);
      return;
    }

    if (!hasEnoughPoints) {
      setMessage("ポイント残高が不足しています。ポイント購入後に再度お試しください。");
      return;
    }

    if (!hasEnoughDraws) {
      setMessage("残り口数または本日の抽選可能回数が不足しています。");
      return;
    }

    if (isBulkDraw) {
      setConfirmationOpen(true);
      setMessage(null);
      return;
    }

    await executeLegacyDraw();
  }

  async function executeLegacyDraw(): Promise<void> {
    if (!session) {
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
      // APIの抽選結果は保持しつつ、先に全画面の演出動画を表示する。
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

  async function executeBulkDraw(retryAttempt?: BulkAttempt): Promise<void> {
    const selectedBulkCount = isBulkDrawCount(drawCount) ? drawCount : null;

    if (!session || (!retryAttempt && selectedBulkCount === null)) {
      return;
    }

    const attempt: BulkAttempt = retryAttempt ?? {
      drawCount: selectedBulkCount as 100 | 1000,
      idempotencyKey: crypto.randomUUID(),
      state: "unknown",
    };
    const balanceBefore = currentBalance;
    const controller = new AbortController();
    const timeout = window.setTimeout(() => controller.abort(), bulkRequestTimeoutMs);

    setConfirmationOpen(false);
    setLoading(true);
    setMessage("Bulk抽選を処理しています。完了まで数秒かかる場合があります。");
    setBulkResult(null);
    setBulkAttempt(attempt);

    try {
      const response = await fetch(`${getPublicApiBaseUrl()}/gachas/${gachaId}/draw`, {
        method: "POST",
        headers: {
          accept: "application/json",
          authorization: `${session.token_type} ${session.access_token}`,
          "content-type": "application/json",
          "Idempotency-Key": attempt.idempotencyKey,
        },
        body: JSON.stringify({
          draw_count: attempt.drawCount,
        }),
        signal: controller.signal,
      });
      const payload = await readJsonResponse(response);

      if (!response.ok) {
        handleBulkError(response.status, payload as ApiErrorResponse, attempt);
        return;
      }

      const bulkPayload = payload as BulkDrawResponse;
      const pointBackCount = validateBulkSummary(bulkPayload.data);
      const updatedWallet = await fetchWallet(session);

      setBulkResult({
        response: bulkPayload.data,
        balanceAfter: updatedWallet?.total_balance ?? Math.max(0, balanceBefore - bulkPayload.data.consumed_point),
        pointBackCount,
      });
      setBulkAttempt(null);
      setMessage(
        bulkPayload.data.idempotent_replay
          ? "前回と同じBulk抽選結果を確認しました。ポイントや景品の二重処理はありません。"
          : "Bulk抽選が完了しました。集計結果とポイント残高を更新しました。",
      );
    } catch (error) {
      setBulkAttempt({ ...attempt, state: "unknown" });
      setMessage(
        error instanceof DOMException && error.name === "AbortError"
          ? "応答を確認できませんでした。同じIdempotency-Keyで結果を再確認してください。"
          : "通信結果を確認できませんでした。新しく引き直さず、同じ操作を再確認してください。",
      );
    } finally {
      window.clearTimeout(timeout);
      setLoading(false);
    }
  }

  function handleBulkError(status: number, payload: ApiErrorResponse, attempt: BulkAttempt): void {
    const detail = readApiError(payload, "Bulk抽選に失敗しました。");
    const normalized = detail.toLowerCase();

    if (status === 401 || status === 403) {
      window.localStorage.removeItem(sessionStorageKey);
      setSession(null);
      setWallet(null);
      setBulkAttempt(null);
      setMessage("認証の有効期限が切れました。再度ログインしてください。");
      return;
    }

    if (status === 409 && normalized.includes("already processing")) {
      setBulkAttempt({ ...attempt, state: "processing" });
      setMessage("同じBulk抽選を処理中です。少し待ってから同じ操作を再確認してください。");
      return;
    }

    if (status === 409 && normalized.includes("replay window has expired")) {
      setBulkAttempt(null);
      setMessage("結果の再確認期限が切れています。残高と抽選履歴を確認してから、新しい操作を開始してください。");
      return;
    }

    if (status === 409) {
      setBulkAttempt(null);
      setMessage("Idempotency-Keyが別の抽選内容と競合しました。抽選履歴を確認してから再度操作してください。");
      return;
    }

    if (status === 422) {
      setBulkAttempt(null);
      setMessage(classifyValidationError(payload, detail));
      return;
    }

    setBulkAttempt({ ...attempt, state: "unknown" });
    setMessage("Serverの処理結果を確認できませんでした。同じ操作を再確認してください。");
  }

  function retryBulkDraw(): void {
    if (!bulkAttempt || loading) {
      return;
    }

    void executeBulkDraw(bulkAttempt);
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

  function closeBulkResult(): void {
    setBulkResult(null);
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

    // ブラウザの自動再生制限で止まった場合だけ、初回タップを動画再生に使う。
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
          {dailyDrawLimit ? <p>このガチャは1日{dailyDrawLimit.toLocaleString("ja-JP")}回まで抽選できます。</p> : null}
        </div>
        <strong>{totalPoint.toLocaleString("ja-JP")}pt</strong>
      </div>

      <div className="draw-count-selector" role="group" aria-label="抽選回数">
        {visibleDrawOptions.map((count) => {
          const unavailableReason = drawOptionUnavailableReason(count, remainingCount, dailyDrawLimit);

          return (
          <button
            className={`${drawCount === count ? "active" : ""}${count === 1000 ? " bulk-primary-option" : ""}`}
            type="button"
            key={count}
            onClick={() => {
              setDrawCount(count);
              setMessage(null);
            }}
            disabled={Boolean(unavailableReason) || loading || revealing}
            title={unavailableReason ?? undefined}
            aria-label={unavailableReason ? `${count}回: ${unavailableReason}` : `${count}回`}
          >
            {count}回
          </button>
          );
        })}
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
          {loading ? "処理中" : revealing ? "抽選演出中" : isBulkDraw ? `${drawCount}回の内容を確認` : `${drawCount}回抽選`}
        </button>
        {!isLoggedIn || !hasEnoughPoints ? (
          <Link className="public-secondary-link light" href={isLoggedIn ? "/points/purchase" : pointPurchaseLoginUrl}>
            ポイント購入
          </Link>
        ) : null}
      </div>

      {message ? <p className="draw-message" role="status" aria-live="polite">{message}</p> : null}

      {isLoggedIn && !hasEnoughPoints ? (
        <div className="draw-guidance warning">
          <strong>ポイントが不足しています</strong>
          <p>必要ポイントに対して残高が不足しています。ポイント購入後に再度抽選してください。</p>
        </div>
      ) : null}

      {!hasEnoughDraws ? (
        <div className="draw-guidance warning">
          <strong>抽選可能回数が不足しています</strong>
          <p>残り口数または本日の抽選可能回数を超えています。Backendでも最終確認されます。</p>
        </div>
      ) : null}

      {bulkAttempt ? (
        <div className="draw-guidance bulk-retry-guidance">
          <strong>{bulkAttempt.state === "processing" ? "同じ抽選を処理中です" : "抽選結果が未確認です"}</strong>
          <p>別の抽選を開始せず、同じIdempotency-Keyで結果を再確認します。</p>
          <button className="public-secondary-link light" type="button" disabled={loading} onClick={retryBulkDraw}>
            {loading ? "再確認中" : "同じ操作を再確認"}
          </button>
        </div>
      ) : null}

      {confirmationOpen && isBulkDraw ? (
        <div className="bulk-confirm-overlay" role="presentation" onMouseDown={(event) => {
          if (event.currentTarget === event.target) {
            setConfirmationOpen(false);
          }
        }}>
          <section className="bulk-confirm-dialog" role="dialog" aria-modal="true" aria-labelledby="bulk-confirm-title">
            <div className="bulk-confirm-head">
              <span>Confirm</span>
              <h3 id="bulk-confirm-title">{drawCount.toLocaleString("ja-JP")}回抽選の確認</h3>
              <p>一度のBulk Requestで処理します。実行後は取り消せません。</p>
            </div>
            <dl className="bulk-confirm-summary">
              <div><dt>実行回数</dt><dd>{drawCount.toLocaleString("ja-JP")}回</dd></div>
              <div><dt>1回あたり</dt><dd>{price.toLocaleString("ja-JP")}pt</dd></div>
              <div><dt>合計消費</dt><dd>{totalPoint.toLocaleString("ja-JP")}pt</dd></div>
              <div><dt>現在残高</dt><dd>{currentBalance.toLocaleString("ja-JP")}pt</dd></div>
              <div><dt>実行後の予想残高</dt><dd>{Math.max(0, currentBalance - totalPoint).toLocaleString("ja-JP")}pt</dd></div>
              <div><dt>現在の残り口数</dt><dd>{remainingCount.toLocaleString("ja-JP")}口</dd></div>
            </dl>
            <div className="bulk-confirm-actions">
              <button className="public-secondary-link light" type="button" onClick={() => setConfirmationOpen(false)}>
                取り消し
              </button>
              <button className="public-primary-link" type="button" onClick={() => void executeBulkDraw()}>
                {drawCount.toLocaleString("ja-JP")}回を実行
              </button>
            </div>
          </section>
        </div>
      ) : null}

      {loading && (isBulkDraw || bulkAttempt) ? (
        <div className="bulk-loading-overlay" role="dialog" aria-modal="true" aria-live="assertive" aria-label="Bulk抽選処理中">
          <div className="bulk-loading-panel">
            <span className="bulk-loading-spinner" aria-hidden="true" />
            <strong>Bulk抽選を処理しています</strong>
            <p>Point、在庫、抽選結果を一括で確定しています。このままお待ちください。</p>
          </div>
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

      {bulkResult ? (
        <BulkResultDialog result={bulkResult} remainingCount={remainingCount} onClose={closeBulkResult} />
      ) : null}
    </section>
  );
}

function BulkResultDialog({
  result,
  remainingCount,
  onClose,
}: {
  result: BulkResult;
  remainingCount: number;
  onClose: () => void;
}) {
  const { response, balanceAfter, pointBackCount } = result;
  const prizeCounts = pointBackCount > 0
    ? [...response.prize_counts, pointBackPrizeCount(pointBackCount)]
    : response.prize_counts;
  const rankCounts = pointBackCount > 0
    ? [...response.rank_counts, pointBackRankCount(pointBackCount)]
    : response.rank_counts;

  return (
    <div className="bulk-result-overlay" role="dialog" aria-modal="true" aria-labelledby="bulk-result-title" aria-live="assertive">
      <section className="bulk-result-dialog">
        <header className="bulk-result-head">
          <div>
            <span>Bulk Result</span>
            <h3 id="bulk-result-title">{response.executed_count.toLocaleString("ja-JP")}回の抽選結果</h3>
          </div>
          <span className="bulk-result-status">{response.status}</span>
        </header>

        <div className="bulk-result-metrics">
          <div><span>実行回数</span><strong>{response.executed_count.toLocaleString("ja-JP")}回</strong></div>
          <div><span>消費Point</span><strong>{response.consumed_point.toLocaleString("ja-JP")}pt</strong></div>
          <div><span>更新後残高</span><strong>{balanceAfter.toLocaleString("ja-JP")}pt</strong></div>
          <div><span>残り口数</span><strong>{Math.max(0, remainingCount - response.executed_count).toLocaleString("ja-JP")}口</strong></div>
        </div>

        {response.idempotent_replay ? (
          <p className="bulk-replay-note">同じ操作の結果を再表示しています。Pointや景品は二重に処理されていません。</p>
        ) : null}

        <div className="bulk-result-columns">
          <section className="bulk-result-section" aria-labelledby="bulk-rank-title">
            <div className="bulk-result-section-head">
              <h4 id="bulk-rank-title">Rank別</h4>
              <span>合計 {sumCounts(rankCounts).toLocaleString("ja-JP")}件</span>
            </div>
            <ul className="bulk-count-list">
              {rankCounts.map((rank) => (
                <li key={rank.rank_key}>
                  <span>{rank.rank_name}</span>
                  <strong>{rank.win_count.toLocaleString("ja-JP")}件</strong>
                </li>
              ))}
            </ul>
          </section>

          <section className="bulk-result-section" aria-labelledby="bulk-prize-title">
            <div className="bulk-result-section-head">
              <h4 id="bulk-prize-title">景品別</h4>
              <span>合計 {sumCounts(prizeCounts).toLocaleString("ja-JP")}件</span>
            </div>
            <ul className="bulk-prize-list">
              {prizeCounts.map((prize) => (
                <li key={`${prize.rank_key}:${prize.prize_name}`}>
                  <span
                    className="bulk-prize-thumb"
                    aria-hidden="true"
                    style={prize.prize_image_url ? { backgroundImage: `url("${prize.prize_image_url}")` } : undefined}
                  />
                  <span>
                    <small>{prize.rank_name}</small>
                    <strong>{prize.prize_name}</strong>
                  </span>
                  <b>{prize.win_count.toLocaleString("ja-JP")}件</b>
                </li>
              ))}
            </ul>
          </section>
        </div>

        <section className="bulk-result-section" aria-labelledby="bulk-high-rank-title">
          <div className="bulk-result-section-head">
            <h4 id="bulk-high-rank-title">高Rank当選</h4>
            <span>{response.high_rank_results.length.toLocaleString("ja-JP")}件表示</span>
          </div>
          {response.high_rank_results.length > 0 ? (
            <ul className="bulk-high-rank-list">
              {response.high_rank_results.map((item) => (
                <li key={`${item.draw_sequence_number}:${item.prize_name}`}>
                  <span
                    className="bulk-prize-thumb"
                    aria-hidden="true"
                    style={item.prize_image_url ? { backgroundImage: `url("${item.prize_image_url}")` } : undefined}
                  />
                  <span>
                    <small>#{item.draw_sequence_number.toLocaleString("ja-JP")} / {item.rank_name}</small>
                    <strong>{item.prize_name}</strong>
                  </span>
                </li>
              ))}
            </ul>
          ) : (
            <p className="bulk-empty-result">表示対象の高Rank当選はありません。</p>
          )}
          {response.high_rank_results_truncated ? (
            <p className="bulk-truncated-note">高Rank当選はBackendの表示上限まで掲載しています。全結果は抽選履歴に保存されています。</p>
          ) : null}
        </section>

        <footer className="bulk-result-footer">
          <div>
            <span>Bulk Request</span>
            <code>{response.bulk_request_id}</code>
            <small>処理時間 {response.processing_duration_ms.toLocaleString("ja-JP")}ms</small>
          </div>
          <button className="public-primary-link" type="button" onClick={onClose}>結果を閉じる</button>
        </footer>
      </section>
    </div>
  );
}

function getPublicApiBaseUrl(): string {
  return process.env.NEXT_PUBLIC_API_BASE_URL ?? "/api";
}

async function readJsonResponse(response: Response): Promise<ApiErrorResponse | BulkDrawResponse> {
  const contentType = response.headers.get("content-type") ?? "";

  if (!contentType.includes("application/json")) {
    throw new Error("APIからJSON以外の応答が返されました。");
  }

  return response.json() as Promise<ApiErrorResponse | BulkDrawResponse>;
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

function classifyValidationError(payload: ApiErrorResponse, fallback: string): string {
  if (payload.errors?.points?.length) {
    return "ポイント残高が不足しています。残高を確認してから再度お試しください。";
  }

  if (payload.errors?.draw?.length) {
    const detail = payload.errors.draw[0]?.toLowerCase() ?? "";

    if (detail.includes("remaining draw count")) {
      return "残り口数が不足しています。最新の販売状況を確認してください。";
    }

    if (detail.includes("本日抽選可能回数")) {
      return "本日の抽選可能回数を超えています。";
    }
  }

  return fallback;
}

function isBulkDrawCount(count: number): count is 100 | 1000 {
  return count === 100 || count === 1000;
}

function drawOptionUnavailableReason(count: number, remainingCount: number, dailyDrawLimit: number | null): string | null {
  if (remainingCount < count) {
    return `残り口数が${count.toLocaleString("ja-JP")}口未満です`;
  }

  if (dailyDrawLimit !== null && dailyDrawLimit < count) {
    return `1日の上限が${count.toLocaleString("ja-JP")}回未満です`;
  }

  return null;
}

function validateBulkSummary(response: BulkDrawResponse["data"]): number {
  const prizeTotal = sumCounts(response.prize_counts);
  const rankTotal = sumCounts(response.rank_counts);

  if (response.requested_count !== response.executed_count || prizeTotal !== rankTotal || prizeTotal > response.executed_count) {
    throw new Error("Bulk抽選の集計結果が実行件数と整合しません。");
  }

  return response.executed_count - prizeTotal;
}

function sumCounts(items: { win_count: number }[]): number {
  return items.reduce((total, item) => total + item.win_count, 0);
}

function pointBackPrizeCount(count: number): BulkPrizeCount {
  return {
    prize_name: "ポイントバック",
    prize_image_url: null,
    rank_key: "point_back",
    rank_name: "ポイントバック",
    win_count: count,
  };
}

function pointBackRankCount(count: number): BulkRankCount {
  return {
    rank_key: "point_back",
    rank_name: "ポイントバック",
    win_count: count,
  };
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

  // 複数回抽選では最上位ランクのランク画像をメイン結果画像として表示する。
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
