"use client";

import { ArrowLeft, Coins, Eye, RotateCcw } from "lucide-react";
import Link from "next/link";
import { type ReactNode, useState } from "react";

import { usePermissions } from "@/components/permissions/permission-provider";
import { AdminPageHeader } from "@/components/shell/admin-page-header";
import { AdminUserPointAdjustmentModal } from "@/components/users/admin-user-point-adjustment-modal";
import {
  type AdminUserReadMode,
  useAdminUserReadModel,
} from "@/components/users/use-admin-user-read-model";
import type {
  AdminUserDetail,
  AdminUserGachaHistoryItem,
  AdminUserSummary,
} from "@/lib/admin-api/generated";

const number = new Intl.NumberFormat("ja-JP");
const tokyoDateTime = new Intl.DateTimeFormat("ja-JP", {
  dateStyle: "medium",
  timeStyle: "short",
  timeZone: "Asia/Tokyo",
});

export function AdminUserReadWorkspace({
  mode,
  userPublicId,
}: {
  mode: AdminUserReadMode;
  userPublicId?: string;
}) {
  const state = useAdminUserReadModel({ mode, userPublicId });
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
          onRefresh={() => {
            setNotice("ポイント調整を反映し、最新残高を再取得しました。");
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

function UserDetail({ onRefresh, user }: { onRefresh: () => void; user: AdminUserDetail }) {
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
          <Definition label="登録日">{formatDateTime(user.created_at)}</Definition>
          <Definition label="更新日">{formatDateTime(user.updated_at)}</Definition>
        </dl>
      </section>
      <section className="admin-user-summary" aria-labelledby="user-balance-heading">
        <div className="admin-user-section-heading">
          <div>
            <h2 id="user-balance-heading">ポイント残高</h2>
            <p>Canonical Walletの現在残高です。</p>
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
            <Metric label="合計残高" value={user.point_balance.total_balance} />
            <Metric label="有償P" value={user.point_balance.paid_balance} />
            <Metric label="無償P" value={user.point_balance.free_balance} />
          </div>
        ) : <State message="Walletはまだ作成されていません。" />}
      </section>
      {canAdjustPoints ? (
        <AdminUserPointAdjustmentModal
          displayName={user.display_name}
          freeBalance={freeBalance}
          onClose={() => setAdjustmentOpen(false)}
          onSuccess={() => {
            onRefresh();
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
  return <div><span>{label}</span><strong>{number.format(value)} pt</strong></div>;
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

function statusLabel(value: string): string {
  const labels: Record<string, string> = {
    active: "有効",
    anonymized: "匿名化済み",
    canceled: "取消",
    closed: "閉鎖",
    converted: "ポイント交換済み",
    delivered: "配送完了",
    exchange_processing: "交換処理中",
    expired: "期限切れ",
    hold: "保留",
    packing: "梱包中",
    pending_verification: "確認待ち",
    restricted: "制限中",
    return_requested: "返送依頼中",
    returned: "返送済み",
    shipped: "発送済み",
    shipping_requested: "配送依頼中",
    stored: "保管中",
    suspended: "停止中",
  };
  return labels[value] ?? value;
}
