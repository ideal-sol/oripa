"use client";

import { useCallback, useEffect, useMemo, useRef, useState } from "react";

import { AdminApiClient, AdminApiError } from "@/lib/admin-api/client";
import type {
  AdminUserReferralHistoryCollection,
  AdminUserReferralHistoryItem,
} from "@/lib/admin-api/generated";

const tokyoDateTime = new Intl.DateTimeFormat("ja-JP", {
  dateStyle: "medium",
  timeStyle: "short",
  timeZone: "Asia/Tokyo",
});

interface ReferralHistoryState {
  userId: string;
  items: AdminUserReferralHistoryItem[];
  nextCursor: string | null;
  error: string | null;
  loading: boolean;
  loadingMore: boolean;
}

export function AdminUserReferralHistory({ userPublicId }: { userPublicId: string }) {
  const client = useMemo(() => new AdminApiClient(), []);
  const activeUserId = useRef(userPublicId);
  const loadMoreController = useRef<AbortController | null>(null);
  const [revision, setRevision] = useState(0);
  const [state, setState] = useState<ReferralHistoryState>(() => emptyState(userPublicId));
  activeUserId.current = userPublicId;

  useEffect(() => {
    const controller = new AbortController();
    loadMoreController.current?.abort();
    setState(emptyState(userPublicId));
    void client.listAdminUserReferralHistory(userPublicId, undefined, controller.signal)
      .then((response) => {
        if (!controller.signal.aborted && activeUserId.current === userPublicId) {
          setState(loadedState(userPublicId, response));
        }
      })
      .catch((reason: unknown) => {
        if (!controller.signal.aborted && activeUserId.current === userPublicId) {
          setState({
            ...emptyState(userPublicId),
            error: errorMessage(reason),
            loading: false,
          });
        }
      });
    return () => {
      controller.abort();
      loadMoreController.current?.abort();
    };
  }, [client, revision, userPublicId]);

  const current = state.userId === userPublicId ? state : emptyState(userPublicId);
  const loadMore = useCallback(async () => {
    if (!current.nextCursor || current.loadingMore) return;
    const cursor = current.nextCursor;
    const controller = new AbortController();
    loadMoreController.current?.abort();
    loadMoreController.current = controller;
    setState((value) => value.userId === userPublicId
      ? { ...value, error: null, loadingMore: true }
      : value);
    try {
      const response = await client.listAdminUserReferralHistory(
        userPublicId,
        cursor,
        controller.signal,
      );
      if (controller.signal.aborted || activeUserId.current !== userPublicId) return;
      setState((value) => value.userId === userPublicId ? {
        ...loadedState(userPublicId, response),
        items: [...value.items, ...response.items],
      } : value);
    } catch (reason: unknown) {
      if (controller.signal.aborted || activeUserId.current !== userPublicId) return;
      setState((value) => value.userId === userPublicId
        ? { ...value, error: errorMessage(reason), loadingMore: false }
        : value);
    }
  }, [client, current.loadingMore, current.nextCursor, userPublicId]);

  return (
    <section className="admin-user-summary" aria-labelledby="user-referral-history-heading">
      <div className="admin-user-section-heading">
        <div>
          <h2 id="user-referral-history-heading">紹介履歴</h2>
          <p>このユーザーが紹介したユーザーを新しい順に表示します。</p>
        </div>
      </div>
      {current.loading ? <State message="紹介履歴を読み込んでいます。" /> : null}
      {!current.loading && current.error ? (
        <State
          error
          message={current.error}
          retry={() => setRevision((value) => value + 1)}
        />
      ) : null}
      {!current.loading && !current.error && current.items.length === 0 ? (
        <State message="紹介履歴はありません。" />
      ) : null}
      {!current.loading && current.items.length > 0 ? (
        <>
          <ReferralTable items={current.items} />
          {current.nextCursor ? (
            <button
              className="secondary-button admin-user-load-more"
              disabled={current.loadingMore}
              onClick={() => void loadMore()}
              type="button"
            >
              {current.loadingMore ? "読み込み中" : "次の50件を表示"}
            </button>
          ) : null}
        </>
      ) : null}
    </section>
  );
}

function ReferralTable({ items }: { items: AdminUserReferralHistoryItem[] }) {
  return (
    <div className="admin-user-table-region" tabIndex={0}>
      <table className="admin-user-table">
        <thead>
          <tr>
            {[
              "紹介されたUser ID",
              "ユーザー名",
              "Referral状態",
              "紹介日時",
              "登録日時",
            ].map((label) => <th key={label} scope="col">{label}</th>)}
          </tr>
        </thead>
        <tbody>
          {items.map((item) => (
            <tr key={item.id}>
              <td><code title={item.referred_user_id}>{item.referred_user_id.slice(0, 8)}…</code></td>
              <td>{item.referred_user_display_name ?? "未設定"}</td>
              <td>
                <span className={`admin-user-status is-${item.status}`}>
                  {statusLabel(item.status)}
                </span>
              </td>
              <td>{formatDateTime(item.referred_at)}</td>
              <td>{formatDateTime(item.registered_at)}</td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}

function State({
  error = false,
  message,
  retry,
}: {
  error?: boolean;
  message: string;
  retry?: () => void;
}) {
  return (
    <div className={`admin-user-state${error ? " is-error" : ""}`} role={error ? "alert" : "status"}>
      <p>{message}</p>
      {retry ? <button className="secondary-button" onClick={retry} type="button">再試行</button> : null}
    </div>
  );
}

function emptyState(userId: string): ReferralHistoryState {
  return {
    userId,
    items: [],
    nextCursor: null,
    error: null,
    loading: true,
    loadingMore: false,
  };
}

function loadedState(
  userId: string,
  response: AdminUserReferralHistoryCollection,
): ReferralHistoryState {
  return {
    userId,
    items: response.items,
    nextCursor: response.next_cursor,
    error: null,
    loading: false,
    loadingMore: false,
  };
}

function errorMessage(reason: unknown): string {
  if (reason instanceof AdminApiError && reason.status === 404) {
    return "指定されたユーザーは存在しません。";
  }
  return reason instanceof Error ? reason.message : "紹介履歴を取得できませんでした。";
}

function formatDateTime(value: string): string {
  return tokyoDateTime.format(new Date(value));
}

function statusLabel(status: AdminUserReferralHistoryItem["status"]): string {
  return {
    pending: "未確定",
    rewarded: "付与済み",
    canceled: "取消",
  }[status];
}
