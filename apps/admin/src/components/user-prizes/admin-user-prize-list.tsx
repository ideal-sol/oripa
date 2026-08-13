"use client";

import {
  ChevronLeft,
  ChevronRight,
  Eye,
  LoaderCircle,
  RefreshCw,
  Search,
} from "lucide-react";
import Link from "next/link";
import { type FormEvent, useEffect, useMemo, useState } from "react";

import { PublicAssetPreview } from "@/components/catalog/public-asset-preview";
import { AdminPageHeader } from "@/components/shell/admin-page-header";
import {
  AdminApiClient,
  AdminApiError,
  type AdminUserPrizeQuery,
} from "@/lib/admin-api/client";
import type {
  AdminUserPrizeStatus,
  AdminUserPrizeSummary,
} from "@/lib/admin-api/generated";

const STATUS_OPTIONS: Array<{ label: string; value: AdminUserPrizeStatus | "" }> = [
  { label: "すべて", value: "" },
  { label: "保管中", value: "stored" },
  { label: "交換処理中", value: "exchange_processing" },
  { label: "ポイント交換済み", value: "converted" },
  { label: "配送依頼中", value: "shipping_requested" },
  { label: "梱包中", value: "packing" },
  { label: "発送済み", value: "shipped" },
  { label: "配送完了", value: "delivered" },
  { label: "保留", value: "hold" },
  { label: "返送依頼中", value: "return_requested" },
  { label: "返送済み", value: "returned" },
  { label: "期限切れ", value: "expired" },
  { label: "取消", value: "canceled" },
];

interface Filters {
  user: string;
  prizeName: string;
  gacha: string;
  status: AdminUserPrizeStatus | "";
}

const EMPTY_FILTERS: Filters = { user: "", prizeName: "", gacha: "", status: "" };

export function AdminUserPrizeList() {
  const client = useMemo(() => new AdminApiClient(), []);
  const [draft, setDraft] = useState<Filters>(EMPTY_FILTERS);
  const [filters, setFilters] = useState<Filters>(EMPTY_FILTERS);
  const [items, setItems] = useState<AdminUserPrizeSummary[]>([]);
  const [cursor, setCursor] = useState<string | undefined>();
  const [cursorStack, setCursorStack] = useState<(string | undefined)[]>([]);
  const [nextCursor, setNextCursor] = useState<string | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [reload, setReload] = useState(0);

  useEffect(() => {
    const controller = new AbortController();
    setLoading(true);
    setError(null);
    const query: AdminUserPrizeQuery = {
      cursor,
      gacha: filters.gacha || undefined,
      limit: 20,
      prize_name: filters.prizeName || undefined,
      status: filters.status || undefined,
      user: filters.user || undefined,
    };
    client.listAdminUserPrizes(query, controller.signal)
      .then((response) => {
        setItems(response.items);
        setNextCursor(response.next_cursor);
      })
      .catch((reason: unknown) => {
        if (!controller.signal.aborted) setError(userPrizeError(reason));
      })
      .finally(() => {
        if (!controller.signal.aborted) setLoading(false);
      });
    return () => controller.abort();
  }, [client, cursor, filters, reload]);

  function applyFilters(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setCursor(undefined);
    setCursorStack([]);
    setFilters({
      gacha: draft.gacha.trim(),
      prizeName: draft.prizeName.trim(),
      status: draft.status,
      user: draft.user.trim(),
    });
  }

  function resetFilters() {
    setDraft(EMPTY_FILTERS);
    setFilters(EMPTY_FILTERS);
    setCursor(undefined);
    setCursorStack([]);
  }

  return (
    <main className="workspace admin-user-prize-workspace">
      <AdminPageHeader
        eyebrow="Prize ownership"
        title="保有景品一覧"
        description="全ユーザーの取得景品と現在の配送・ポイント交換状態を確認します。"
      />
      <form className="admin-user-prize-filters" onSubmit={applyFilters}>
        <label>
          <span>ユーザー</span>
          <input
            aria-label="ユーザー"
            placeholder="表示名またはPublic ID"
            value={draft.user}
            onChange={(event) => setDraft({ ...draft, user: event.target.value })}
          />
        </label>
        <label>
          <span>景品名</span>
          <input
            aria-label="景品名"
            value={draft.prizeName}
            onChange={(event) => setDraft({ ...draft, prizeName: event.target.value })}
          />
        </label>
        <label>
          <span>ガチャ</span>
          <input
            aria-label="ガチャ"
            placeholder="タイトルまたは公開ID"
            value={draft.gacha}
            onChange={(event) => setDraft({ ...draft, gacha: event.target.value })}
          />
        </label>
        <label>
          <span>状態</span>
          <select
            aria-label="状態"
            value={draft.status}
            onChange={(event) => setDraft({
              ...draft,
              status: event.target.value as AdminUserPrizeStatus | "",
            })}
          >
            {STATUS_OPTIONS.map((option) => (
              <option key={option.value} value={option.value}>{option.label}</option>
            ))}
          </select>
        </label>
        <div className="admin-user-prize-filter-actions">
          <button className="secondary-button" type="submit">
            <Search aria-hidden="true" size={16} />検索
          </button>
          <button className="text-button" onClick={resetFilters} type="button">条件を解除</button>
        </div>
      </form>

      {error ? (
        <section className="module-state is-error" role="alert">
          <h2>保有景品を取得できませんでした</h2>
          <p>{error}</p>
          <button className="secondary-button" onClick={() => setReload((value) => value + 1)} type="button">
            <RefreshCw aria-hidden="true" size={16} />再取得
          </button>
        </section>
      ) : loading ? (
        <section aria-live="polite" className="module-state">
          <LoaderCircle aria-hidden="true" className="spin" size={22} />
          <p>保有景品を読み込んでいます。</p>
        </section>
      ) : items.length === 0 ? (
        <section className="module-state">
          <h2>該当する保有景品はありません</h2>
          <p>検索条件を変更して再確認してください。</p>
        </section>
      ) : (
        <section aria-label="保有景品一覧" className="admin-user-prize-list-section">
          <div className="admin-user-prize-table-region" tabIndex={0}>
            <table className="admin-user-prize-table">
              <thead>
                <tr>
                  <th scope="col">User</th>
                  <th scope="col">景品</th>
                  <th scope="col">ランク</th>
                  <th scope="col">取得元Gacha</th>
                  <th scope="col">取得日時</th>
                  <th scope="col">現在状態</th>
                  <th scope="col">Fulfillment</th>
                  <th scope="col">詳細</th>
                </tr>
              </thead>
              <tbody>
                {items.map((item) => <PrizeRow item={item} key={item.id} />)}
              </tbody>
            </table>
          </div>
          <nav aria-label="ページ操作" className="admin-user-prize-pagination">
            <button
              className="secondary-button"
              disabled={cursorStack.length === 0}
              onClick={() => {
                const previous = [...cursorStack];
                setCursor(previous.pop());
                setCursorStack(previous);
              }}
              type="button"
            >
              <ChevronLeft aria-hidden="true" size={16} />前へ
            </button>
            <button
              className="secondary-button"
              disabled={!nextCursor}
              onClick={() => {
                setCursorStack((current) => [...current, cursor]);
                setCursor(nextCursor ?? undefined);
              }}
              type="button"
            >
              次へ<ChevronRight aria-hidden="true" size={16} />
            </button>
          </nav>
        </section>
      )}
    </main>
  );
}

function PrizeRow({ item }: { item: AdminUserPrizeSummary }) {
  return (
    <tr>
      <td>
        <strong>{item.user.display_name ?? "未設定"}</strong>
        <Link href={`/users/${item.user.id}`}><code>{compactId(item.user.id)}</code></Link>
      </td>
      <td>
        <div className="admin-user-prize-cell">
          <div className="admin-user-prize-thumbnail"><PublicAssetPreview asset={item.prize.image} /></div>
          <div><strong>{item.prize.name}</strong><code>{compactId(item.prize.id)}</code></div>
        </div>
      </td>
      <td><span className="admin-user-prize-rank">{item.prize.rank.name}</span></td>
      <td><strong>{item.gacha.title}</strong><code>{item.gacha.id}</code></td>
      <td>{formatJst(item.acquired_at)}</td>
      <td><StatusBadge status={item.status} /></td>
      <td><FulfillmentSummary item={item} /></td>
      <td>
        <Link aria-label={`${item.prize.name}の詳細`} className="icon-button" href={`/user-prizes/${item.id}`} title="詳細">
          <Eye aria-hidden="true" size={18} />
        </Link>
      </td>
    </tr>
  );
}

function FulfillmentSummary({ item }: { item: AdminUserPrizeSummary }) {
  if (item.fulfillment.shipping_status) {
    return <span>配送: {fulfillmentLabel(item.fulfillment.shipping_status)}</span>;
  }
  if (item.fulfillment.point_exchange_status) {
    return <span>ポイント交換: {fulfillmentLabel(item.fulfillment.point_exchange_status)}</span>;
  }
  return <span className="muted-text">未申請</span>;
}

export function StatusBadge({ status }: { status: AdminUserPrizeStatus }) {
  return <span className={`admin-user-prize-status is-${status}`}>{statusLabel(status)}</span>;
}

export function statusLabel(status: string): string {
  return STATUS_OPTIONS.find((option) => option.value === status)?.label ?? status;
}

export function fulfillmentLabel(status: string): string {
  const labels: Record<string, string> = {
    accepted: "受付済み",
    canceled: "取消",
    completed: "完了",
    delivered: "配送完了",
    packing: "梱包中",
    processing: "処理中",
    requested: "依頼済み",
    shipped: "発送済み",
  };
  return labels[status] ?? status;
}

export function formatJst(value: string | null): string {
  if (!value) return "未設定";
  return new Intl.DateTimeFormat("ja-JP", {
    dateStyle: "medium",
    timeStyle: "short",
    timeZone: "Asia/Tokyo",
  }).format(new Date(value));
}

function compactId(value: string): string {
  return value.length > 14 ? `${value.slice(0, 8)}…` : value;
}

function userPrizeError(reason: unknown): string {
  if (reason instanceof AdminApiError) {
    if (reason.status === 404) return "対象の保有景品が見つかりません。";
    if (reason.status === 422) return "検索条件が正しくありません。";
    if (reason.status === 403) return "保有景品を表示する権限がありません。";
    return `${reason.message}${reason.requestId ? ` (Request ID: ${reason.requestId})` : ""}`;
  }
  return "通信に失敗しました。時間をおいて再試行してください。";
}

export { userPrizeError };
