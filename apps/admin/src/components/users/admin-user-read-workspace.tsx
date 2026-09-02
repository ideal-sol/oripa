"use client";

import { ArrowLeft, Coins, Eye, RotateCcw, Search } from "lucide-react";
import Link from "next/link";
import {
  type FormEvent,
  type ReactNode,
  useCallback,
  useEffect,
  useMemo,
  useRef,
  useState,
} from "react";

import { usePermissions } from "@/components/permissions/permission-provider";
import { AdminPaymentHistory } from "@/components/payments/admin-payment-history";
import { AdminPageHeader } from "@/components/shell/admin-page-header";
import { AdminUserPointAdjustmentModal } from "@/components/users/admin-user-point-adjustment-modal";
import { AdminUserQaTestMode } from "@/components/users/admin-user-qa-test-mode";
import { AdminUserStateManagement } from "@/components/users/admin-user-state-management";
import { AdminUserTagSection } from "@/components/users/admin-user-tag-management";
import {
  type AdminUserReadMode,
  useAdminUserReadModel,
} from "@/components/users/use-admin-user-read-model";
import type {
  AdminUserDetail,
  AdminUserGachaHistoryItem,
  AdminUserReferralHistoryCollection,
  AdminUserReferralHistoryItem,
  AdminUserSummary,
} from "@/lib/admin-api/generated";
import { AdminApiClient, AdminApiError } from "@/lib/admin-api/client";
import {
  ADMIN_USER_STATUS_FILTERS,
  type AdminUserQuery,
  type AdminUserStatusFilter,
} from "@/lib/admin-api/client";

const number = new Intl.NumberFormat("ja-JP");
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

export function AdminUserReadWorkspace({
  initialFilters,
  mode,
  userPublicId,
}: {
  initialFilters?: AdminUserQuery;
  mode: AdminUserReadMode;
  userPublicId?: string;
}) {
  const initial = useMemo(() => listFilters(initialFilters), [initialFilters]);
  const [draftFilters, setDraftFilters] = useState(initial);
  const [appliedFilters, setAppliedFilters] = useState(initial);
  const [filterError, setFilterError] = useState<string | null>(null);
  const state = useAdminUserReadModel({ listFilters: appliedFilters, mode, userPublicId });
  const [notice, setNotice] = useState<string | null>(null);
  const title = mode === "list"
    ? "ユーザー一覧"
    : mode === "detail"
      ? "ユーザー詳細"
      : "ユーザーガチャ履歴";

  return (
    <section className="workspace admin-user-workspace">
      <AdminPageHeader
        eyebrow="Users"
        title={title}
        description={mode === "history"
          ? "過去を含む取得景品の状態を確認できます。"
          : undefined}
        action={mode !== "list" && userPublicId ? (
          <Link
            className="secondary-button"
            href={mode === "history" ? `/users/${userPublicId}` : "/users"}
          >
            <ArrowLeft aria-hidden="true" size={17} />
            {mode === "history" ? "ユーザー詳細へ" : "一覧へ"}
          </Link>
        ) : undefined}
      />
      {mode === "list" ? (
        <UserFilters
          draft={draftFilters}
          error={filterError}
          onChange={setDraftFilters}
          onReset={() => {
            const reset = listFilters();
            setDraftFilters(reset);
            setAppliedFilters(reset);
            setFilterError(null);
          }}
          onSubmit={(event) => {
            event.preventDefault();
            if (draftFilters.date_from
              && draftFilters.date_to
              && draftFilters.date_to < draftFilters.date_from) {
              setFilterError("登録日の開始日は終了日以前を指定してください。");
              return;
            }
            setFilterError(null);
            setAppliedFilters({
              ...draftFilters,
              user_id: draftFilters.user_id?.trim() || undefined,
            });
          }}
        />
      ) : null}
      {notice ? <p className="admin-user-adjustment-success" role="status">{notice}</p> : null}
      {state.loading ? (
        <State message="ユーザー情報を読み込んでいます。" />
      ) : null}
      {!state.loading && state.error ? (
        <State error message={state.error} retry={state.retry} />
      ) : null}
      {!state.loading && !state.error && state.data?.kind === "list" ? (
        <UserList items={state.data.value.items} />
      ) : null}
      {!state.loading && !state.error && state.data?.kind === "detail" ? (
        <UserDetail
          onRefresh={(message) => {
            setNotice(message);
            state.retry();
          }}
          user={state.data.value}
        />
      ) : null}
      {!state.loading && !state.error && state.data?.kind === "history" ? (
        <GachaHistory items={state.data.value.items} />
      ) : null}
      {!state.loading
        && state.data?.kind !== "detail"
        && state.data?.value.next_cursor ? (
          <button
            className="secondary-button admin-user-load-more"
            disabled={state.loadingMore}
            onClick={() => void state.loadMore()}
            type="button"
          >
            {state.loadingMore ? "読み込み中" : "次の50件を表示"}
          </button>
        ) : null}
    </section>
  );
}

function UserFilters({
  draft,
  error,
  onChange,
  onReset,
  onSubmit,
}: {
  draft: AdminUserQuery;
  error: string | null;
  onChange: (filters: AdminUserQuery) => void;
  onReset: () => void;
  onSubmit: (event: FormEvent<HTMLFormElement>) => void;
}) {
  return (
    <form aria-label="ユーザー検索フィルター" className="admin-user-filters" onSubmit={onSubmit}>
      <label>
        <span>User ID</span>
        <input
          aria-label="User ID"
          placeholder="xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx"
          value={draft.user_id ?? ""}
          onChange={(event) => onChange({ ...draft, user_id: event.target.value })}
        />
      </label>
      <label>
        <span>状態</span>
        <select
          aria-label="状態"
          value={draft.status ?? "active"}
          onChange={(event) => onChange({
            ...draft,
            status: event.target.value as AdminUserStatusFilter,
          })}
        >
          {ADMIN_USER_STATUS_FILTERS.map((status) => (
            <option key={status} value={status}>{userStatusLabel(status)}</option>
          ))}
        </select>
      </label>
      <label>
        <span>登録日（開始）</span>
        <input
          aria-label="登録日（開始）"
          type="date"
          value={draft.date_from ?? ""}
          onChange={(event) => onChange({ ...draft, date_from: event.target.value })}
        />
      </label>
      <label>
        <span>登録日（終了）</span>
        <input
          aria-label="登録日（終了）"
          type="date"
          value={draft.date_to ?? ""}
          onChange={(event) => onChange({ ...draft, date_to: event.target.value })}
        />
      </label>
      <div className="admin-user-filter-actions">
        <button className="secondary-button" type="submit">
          <Search aria-hidden="true" size={16} />検索
        </button>
        <button className="text-button" onClick={onReset} type="button">条件を解除</button>
      </div>
      {error ? <p className="admin-user-filter-error" role="alert">{error}</p> : null}
    </form>
  );
}

function listFilters(filters?: AdminUserQuery): AdminUserQuery {
  return {
    date_from: filters?.date_from || undefined,
    date_to: filters?.date_to || undefined,
    status: filters?.status ?? "active",
    user_id: filters?.user_id || undefined,
  };
}

function userStatusLabel(value: AdminUserStatusFilter): string {
  return value === "all" ? "すべて" : statusLabel(value);
}

function UserList({ items }: { items: AdminUserSummary[] }) {
  if (items.length === 0) {
    return <State message="表示できるユーザーはいません。" />;
  }
  const headings = [
    "ID", "ユーザー名", "状態", "合計残高", "有償P", "無償P", "登録日", "詳細",
  ];
  return (
    <div className="admin-user-table-region" tabIndex={0}>
      <table className="admin-user-table">
        <thead>
          <tr>{headings.map((label) => <th key={label} scope="col">{label}</th>)}</tr>
        </thead>
        <tbody>
          {items.map((user) => (
            <tr key={user.id}>
              <td><PublicId value={user.id} /></td>
              <td>{user.display_name ?? "未設定"}</td>
              <td><Status value={user.status} /></td>
              <td>{balance(user.point_balance?.total_balance)}</td>
              <td>{balance(user.point_balance?.paid_balance)}</td>
              <td>{balance(user.point_balance?.free_balance)}</td>
              <td>{formatDateTime(user.created_at)}</td>
              <td>
                <Link
                  aria-label={`${user.display_name ?? "未設定"}の詳細`}
                  className="icon-button"
                  href={`/users/${user.id}`}
                  title="詳細"
                >
                  <Eye aria-hidden="true" size={18} />
                </Link>
              </td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}

function UserDetail({ onRefresh, user }: { onRefresh: (message: string) => void; user: AdminUserDetail }) {
  const { hasPermission } = usePermissions();
  const [adjustmentOpen, setAdjustmentOpen] = useState(false);
  const canAdjustPoints = hasPermission("point.adjustment.manage");
  const paidBalance = user.point_balance?.paid_balance ?? 0;
  const freeBalance = user.point_balance?.free_balance ?? 0;

  return (
    <div className="admin-user-detail-stack">
      <section className="admin-user-summary" aria-labelledby="user-basic-heading">
        <div className="admin-user-section-heading">
          <div>
            <h2 id="user-basic-heading">基本情報</h2>
            <p>ユーザーの識別情報と現在状態です。</p>
          </div>
          <Status value={user.status} />
        </div>
        <dl className="admin-user-definition-grid">
          <Definition label="ID"><PublicId full value={user.id} /></Definition>
          <Definition label="ユーザー名">{user.display_name ?? "未設定"}</Definition>
          <Definition label="メールアドレス">{user.email}</Definition>
          <Definition label="メール確認">
            {user.email_verified_at ? formatDateTime(user.email_verified_at) : "未確認"}
          </Definition>
          <Definition label="SMS認証">{user.sms_verified ? "認証済み" : "未認証"}</Definition>
          <Definition label="電話番号">{user.phone ?? "未登録"}</Definition>
          <Definition label="SMS認証日時">
            {user.verified_at ? formatDateTime(user.verified_at) : "未認証"}
          </Definition>
          <Definition label="登録日">{formatDateTime(user.created_at)}</Definition>
          <Definition label="更新日">{formatDateTime(user.updated_at)}</Definition>
        </dl>
      </section>
      {hasPermission("reporting.financial.read") ? (
        <AdminPaymentHistory userPublicId={user.id} />
      ) : null}
      <AdminUserReferralHistory userPublicId={user.id} />
      <AdminUserStateManagement
        onRefresh={() => onRefresh("ユーザー状態を更新し、最新情報を再取得しました。")}
        user={user}
      />
      <AdminUserQaTestMode user={user} />
      <section className="admin-user-summary" aria-labelledby="user-balance-heading">
        <div className="admin-user-section-heading">
          <div>
            <h2 id="user-balance-heading">コイン残高</h2>
            <p>Canonical Walletの現在利用可能なコインです。</p>
          </div>
          {canAdjustPoints ? (
            <button className="primary-button" onClick={() => setAdjustmentOpen(true)} type="button">
              <Coins aria-hidden="true" size={17} />
              ポイント調整
            </button>
          ) : null}
        </div>
        {user.point_balance ? (
          <div className="admin-user-balance-grid">
            <Metric label="合計コイン" value={user.point_balance.total_balance} />
            <Metric label="有償コイン" value={user.point_balance.paid_balance} />
            <Metric label="ボーナスコイン" value={user.point_balance.free_balance} />
            <Metric label="次回失効コイン数" value={user.point_balance.next_expiring_amount ?? 0} />
            <Definition label="次回失効日時">
              {user.point_balance.next_expires_at
                ? formatDateTime(user.point_balance.next_expires_at)
                : "失効予定なし"}
            </Definition>
          </div>
        ) : <State message="Walletはまだ作成されていません。" />}
      </section>
      <AdminUserTagSection
        onRefresh={() => onRefresh("会員タグを更新し、最新情報を再取得しました。")}
        user={user}
      />
      {canAdjustPoints ? (
        <AdminUserPointAdjustmentModal
          displayName={user.display_name}
          freeBalance={freeBalance}
          onClose={() => setAdjustmentOpen(false)}
          onSuccess={() => {
            onRefresh("ポイント調整を反映し、最新残高を再取得しました。");
          }}
          open={adjustmentOpen}
          paidBalance={paidBalance}
          userPublicId={user.id}
        />
      ) : null}
      <section
        className="admin-user-summary admin-user-history-link"
        aria-labelledby="user-history-heading"
      >
        <div>
          <h2 id="user-history-heading">ユーザーガチャ履歴</h2>
          <p>取得した景品と現在の状態を別ページで確認します。</p>
        </div>
        <Link className="primary-button" href={`/users/${user.id}/gacha-history`}>
          ガチャ履歴を表示
        </Link>
      </section>
    </div>
  );
}

export function AdminUserReferralHistory({ userPublicId }: { userPublicId: string }) {
  const client = useMemo(() => new AdminApiClient(), []);
  const activeUserId = useRef(userPublicId);
  const loadMoreController = useRef<AbortController | null>(null);
  const [revision, setRevision] = useState(0);
  const [state, setState] = useState<ReferralHistoryState>(() => emptyReferralState(userPublicId));
  activeUserId.current = userPublicId;

  useEffect(() => {
    const controller = new AbortController();
    loadMoreController.current?.abort();
    setState(emptyReferralState(userPublicId));
    void client.listAdminUserReferralHistory(userPublicId, undefined, controller.signal)
      .then((response) => {
        if (!controller.signal.aborted && activeUserId.current === userPublicId) {
          setState(loadedReferralState(userPublicId, response));
        }
      })
      .catch((reason: unknown) => {
        if (!controller.signal.aborted && activeUserId.current === userPublicId) {
          setState({
            ...emptyReferralState(userPublicId),
            error: referralErrorMessage(reason),
            loading: false,
          });
        }
      });
    return () => {
      controller.abort();
      loadMoreController.current?.abort();
    };
  }, [client, revision, userPublicId]);

  const current = state.userId === userPublicId ? state : emptyReferralState(userPublicId);
  const loadMore = useCallback(async () => {
    if (!current.nextCursor || current.loadingMore) return;
    const controller = new AbortController();
    loadMoreController.current?.abort();
    loadMoreController.current = controller;
    setState((value) => value.userId === userPublicId
      ? { ...value, error: null, loadingMore: true }
      : value);
    try {
      const response = await client.listAdminUserReferralHistory(
        userPublicId,
        current.nextCursor,
        controller.signal,
      );
      if (controller.signal.aborted || activeUserId.current !== userPublicId) return;
      setState((value) => value.userId === userPublicId ? {
        ...loadedReferralState(userPublicId, response),
        items: [...value.items, ...response.items],
      } : value);
    } catch (reason: unknown) {
      if (controller.signal.aborted || activeUserId.current !== userPublicId) return;
      setState((value) => value.userId === userPublicId
        ? { ...value, error: referralErrorMessage(reason), loadingMore: false }
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
                {current.items.map((item) => (
                  <tr key={item.id}>
                    <td><PublicId value={item.referred_user_id} /></td>
                    <td>{item.referred_user_display_name ?? "未設定"}</td>
                    <td><Status value={item.status} /></td>
                    <td>{formatDateTime(item.referred_at)}</td>
                    <td>{formatDateTime(item.registered_at)}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
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

function GachaHistory({ items }: { items: AdminUserGachaHistoryItem[] }) {
  if (items.length === 0) {
    return <State message="取得景品履歴はありません。" />;
  }
  const headings = [
    "ID", "ガチャ", "景品", "ランク", "状態", "交換P", "取得日", "保管期限",
  ];
  return (
    <div className="admin-user-table-region" tabIndex={0}>
      <table className="admin-user-table">
        <thead>
          <tr>{headings.map((label) => <th key={label} scope="col">{label}</th>)}</tr>
        </thead>
        <tbody>
          {items.map((item) => (
            <tr key={item.id}>
              <td><PublicId value={item.id} /></td>
              <td>{item.gacha_title}</td>
              <td>{item.prize_name}</td>
              <td>{item.rank_name}</td>
              <td><Status value={item.status} /></td>
              <td>{number.format(item.exchange_point_snapshot)} pt</td>
              <td>{formatDateTime(item.acquired_at)}</td>
              <td>{formatDateTime(item.storage_expires_at)}</td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}

function Definition({ children, label }: { children: ReactNode; label: string }) {
  return <div><dt>{label}</dt><dd>{children}</dd></div>;
}

function Metric({ label, value }: { label: string; value: number }) {
  return <div><span>{label}</span><strong>{number.format(value)} コイン</strong></div>;
}

function Status({ value }: { value: string }) {
  return <span className={`admin-user-status is-${value}`}>{statusLabel(value)}</span>;
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
    <div
      className={`admin-user-state${error ? " is-error" : ""}`}
      role={error ? "alert" : "status"}
    >
      <p>{message}</p>
      {retry ? (
        <button className="secondary-button" onClick={retry} type="button">
          <RotateCcw aria-hidden="true" size={17} />再試行
        </button>
      ) : null}
    </div>
  );
}

function PublicId({ full = false, value }: { full?: boolean; value: string }) {
  return <code title={value}>{full ? value : `${value.slice(0, 8)}…`}</code>;
}

function balance(value: number | undefined): string {
  return value === undefined ? "未作成" : `${number.format(value)} pt`;
}

function formatDateTime(value: string): string {
  return tokyoDateTime.format(new Date(value));
}

function emptyReferralState(userId: string): ReferralHistoryState {
  return {
    userId,
    items: [],
    nextCursor: null,
    error: null,
    loading: true,
    loadingMore: false,
  };
}

function loadedReferralState(
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

function referralErrorMessage(reason: unknown): string {
  if (reason instanceof AdminApiError && reason.status === 404) {
    return "指定されたユーザーは存在しません。";
  }
  return reason instanceof Error ? reason.message : "紹介履歴を取得できませんでした。";
}

function statusLabel(value: string): string {
  const labels: Record<string, string> = {
    active: "有効",
    anonymized: "匿名化済み",
    canceled: "取消",
    closed: "退会",
    converted: "ポイント交換済み",
    delivered: "配送完了",
    exchange_processing: "交換処理中",
    expired: "期限切れ",
    hold: "保留",
    pending: "未確定",
    packing: "梱包中",
    pending_verification: "確認待ち",
    verification_failed: "認証失敗",
    restricted: "制限中",
    rewarded: "付与済み",
    return_requested: "返送依頼中",
    returned: "返送済み",
    shipped: "発送済み",
    shipping_requested: "配送依頼中",
    stored: "保管中",
    suspended: "停止中",
  };
  return labels[value] ?? value;
}
