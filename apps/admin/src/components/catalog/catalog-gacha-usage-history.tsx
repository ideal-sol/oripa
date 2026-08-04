"use client";

import { ArrowLeft, Eye, LoaderCircle, RotateCcw } from "lucide-react";
import Link from "next/link";
import { useCallback, useEffect, useMemo, useState } from "react";

import { PublicAssetPreview } from "@/components/catalog/public-asset-preview";
import { ProtectedAdminRoute } from "@/components/permissions/protected-admin-route";
import { AdminPageHeader } from "@/components/shell/admin-page-header";
import { AdminShell } from "@/components/shell/admin-shell";
import { AdminApiClient, AdminApiError } from "@/lib/admin-api/client";
import type {
  AdminGachaUsageHistoryCollection,
  AdminGachaUsageHistoryDetail,
  AdminGachaUsageHistoryStatusCount,
  AdminGachaUsagePrizeStatus,
} from "@/lib/admin-api/generated";

const number = new Intl.NumberFormat("ja-JP");
const tokyoDateTime = new Intl.DateTimeFormat("ja-JP", {
  dateStyle: "medium",
  timeStyle: "short",
  timeZone: "Asia/Tokyo",
});

export function CatalogGachaUsageHistory({
  drawRequestId,
  gachaId,
}: {
  drawRequestId?: string;
  gachaId: string;
}) {
  return (
    <AdminShell>
      <ProtectedAdminRoute permission="catalog.read">
        <HistoryWorkspace drawRequestId={drawRequestId} gachaId={gachaId} />
      </ProtectedAdminRoute>
    </AdminShell>
  );
}

function HistoryWorkspace({
  drawRequestId,
  gachaId,
}: {
  drawRequestId?: string;
  gachaId: string;
}) {
  const client = useMemo(() => new AdminApiClient(), []);
  const [collection, setCollection] = useState<AdminGachaUsageHistoryCollection | null>(null);
  const [detail, setDetail] = useState<AdminGachaUsageHistoryDetail | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [loading, setLoading] = useState(true);
  const [loadingMore, setLoadingMore] = useState(false);
  const [revision, setRevision] = useState(0);

  useEffect(() => {
    const controller = new AbortController();
    setLoading(true);
    setError(null);
    const request = drawRequestId
      ? client.getGachaUsageHistory(gachaId, drawRequestId, controller.signal)
          .then((response) => setDetail(response.data))
      : client.listGachaUsageHistory(gachaId, null, controller.signal)
          .then(setCollection);
    void request.catch((cause: unknown) => {
      if (!controller.signal.aborted) setError(errorMessage(cause));
    }).finally(() => {
      if (!controller.signal.aborted) setLoading(false);
    });
    return () => controller.abort();
  }, [client, drawRequestId, gachaId, revision]);

  const retry = useCallback(() => {
    setCollection(null);
    setDetail(null);
    setRevision((value) => value + 1);
  }, []);

  async function loadMore() {
    if (!collection?.next_cursor || loadingMore) return;
    setLoadingMore(true);
    setError(null);
    try {
      const next = await client.listGachaUsageHistory(gachaId, collection.next_cursor);
      setCollection({ ...next, items: [...collection.items, ...next.items] });
    } catch (cause: unknown) {
      setError(errorMessage(cause));
    } finally {
      setLoadingMore(false);
    }
  }

  const title = drawRequestId ? "ガチャ利用詳細" : "ガチャ利用履歴";
  return (
    <section className="workspace catalog-usage-history">
      <nav aria-label="パンくず" className="breadcrumb">
        <ol>
          <li><Link href="/">ダッシュボード</Link></li>
          <li><span aria-hidden="true">/</span><Link href="/catalog/gachas">ガチャ一覧</Link></li>
          <li aria-current={drawRequestId ? undefined : "page"}>
            <span aria-hidden="true">/</span>
            {drawRequestId
              ? <Link href={`/catalog/gachas/${gachaId}/history`}>ガチャ利用履歴</Link>
              : "ガチャ利用履歴"}
          </li>
          {drawRequestId ? <li aria-current="page"><span aria-hidden="true">/</span>利用詳細</li> : null}
        </ol>
      </nav>
      <AdminPageHeader
        action={(
          <Link
            className="secondary-button"
            href={drawRequestId
              ? `/catalog/gachas/${gachaId}/history`
              : `/catalog/gachas/${gachaId}`}
          >
            <ArrowLeft aria-hidden="true" size={17} />
            {drawRequestId ? "履歴一覧へ" : "ガチャ詳細へ"}
          </Link>
        )}
        eyebrow="Gacha"
        title={title}
      />
      {loading ? <HistoryState message="ガチャ利用情報を読み込んでいます。" loading /> : null}
      {!loading && error ? <HistoryState error message={error} retry={retry} /> : null}
      {!loading && !error && collection ? (
        <HistoryList collection={collection} gachaId={gachaId} />
      ) : null}
      {!loading && !error && detail ? <HistoryDetail detail={detail} /> : null}
      {!loading && !error && collection?.next_cursor ? (
        <button
          className="secondary-button"
          disabled={loadingMore}
          onClick={() => void loadMore()}
          type="button"
        >
          {loadingMore ? "読み込み中" : "次の20件を表示"}
        </button>
      ) : null}
    </section>
  );
}

function HistoryList({
  collection,
  gachaId,
}: {
  collection: AdminGachaUsageHistoryCollection;
  gachaId: string;
}) {
  if (collection.items.length === 0) {
    return <HistoryState message="完了済みの通常ガチャ利用履歴はありません。" />;
  }
  const headings = ["ガチャ利用ID", "ユーザー名", "何連ガチャ", "状態", "ガチャ利用日時", "詳細"];
  return (
    <div className="catalog-table-wrap" tabIndex={0}>
      <table className="catalog-table">
        <thead><tr>{headings.map((heading) => <th key={heading} scope="col">{heading}</th>)}</tr></thead>
        <tbody>
          {collection.items.map((item) => (
            <tr key={item.id}>
              <td><PublicId value={item.id} /></td>
              <td>{item.user.display_name ?? "未設定"}</td>
              <td>{number.format(item.executed_count)}連</td>
              <td><StatusSummary summary={item.status_summary} /></td>
              <td>{formatDateTime(item.used_at)}</td>
              <td>
                <Link
                  aria-label={`${item.id}の詳細`}
                  className="icon-button"
                  href={`/catalog/gachas/${gachaId}/history/${item.id}`}
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

function HistoryDetail({ detail }: { detail: AdminGachaUsageHistoryDetail }) {
  const headings = ["景品ID", "ランク", "景品名", "サムネイル", "交換ポイント", "現在状態", "状態更新日時"];
  return (
    <div className="catalog-history-detail-stack">
      <section className="catalog-panel" aria-labelledby="usage-summary-heading">
        <h2 id="usage-summary-heading">利用情報</h2>
        <dl className="catalog-history-definition-grid">
          <Definition label="ガチャ利用ID"><PublicId full value={detail.id} /></Definition>
          <Definition label="対象ガチャ">{detail.gacha.title}</Definition>
          <Definition label="ユーザー名">{detail.user.display_name ?? "未設定"}</Definition>
          <Definition label="何連ガチャ">{number.format(detail.executed_count)}連</Definition>
          <Definition label="消費ポイント">{number.format(detail.consumed_points)} pt</Definition>
          <Definition label="ガチャ利用日時">{formatDateTime(detail.used_at)}</Definition>
          <Definition label="状態の集計"><StatusSummary summary={detail.status_summary} /></Definition>
        </dl>
        <Link className="secondary-button" href={`/catalog/gachas/${detail.gacha.id}`}>
          対象ガチャ詳細へ
        </Link>
      </section>
      <section className="catalog-panel" aria-labelledby="winning-prizes-heading">
        <h2 id="winning-prizes-heading">当選景品一覧</h2>
        {detail.prizes.length === 0 ? (
          <HistoryState message="当選景品はありません。" />
        ) : (
          <div className="catalog-table-wrap" tabIndex={0}>
            <table className="catalog-table catalog-history-prize-table">
              <thead><tr>{headings.map((heading) => <th key={heading} scope="col">{heading}</th>)}</tr></thead>
              <tbody>{detail.prizes.map((prize) => (
                <tr key={prize.draw_result_id}>
                  <td><PublicId value={prize.prize_id} /></td>
                  <td>{prize.rank.name}</td>
                  <td>{prize.prize_name}</td>
                  <td><PublicAssetPreview asset={prize.thumbnail} /></td>
                  <td>{number.format(prize.exchange_points)} pt</td>
                  <td><span className="status-pill">{prizeStatusLabel(prize.status)}</span></td>
                  <td>{formatDateTime(prize.status_updated_at)}</td>
                </tr>
              ))}</tbody>
            </table>
          </div>
        )}
      </section>
    </div>
  );
}

function Definition({ children, label }: { children: React.ReactNode; label: string }) {
  return <div><dt>{label}</dt><dd>{children}</dd></div>;
}

function StatusSummary({ summary }: { summary: AdminGachaUsageHistoryStatusCount[] }) {
  if (summary.length === 0) return <span>対象景品なし</span>;
  return (
    <div className="catalog-history-statuses">
      {summary.map((item) => (
        <span className="status-pill" key={item.status}>
          {summaryStatusLabel(item.status)} {number.format(item.count)}
        </span>
      ))}
    </div>
  );
}

function HistoryState({
  error = false,
  loading = false,
  message,
  retry,
}: {
  error?: boolean;
  loading?: boolean;
  message: string;
  retry?: () => void;
}) {
  return (
    <section className="catalog-state" role={error ? "alert" : "status"}>
      {loading ? <LoaderCircle aria-hidden="true" size={24} /> : null}
      <p>{message}</p>
      {retry ? (
        <button className="secondary-button" onClick={retry} type="button">
          <RotateCcw aria-hidden="true" size={16} />再試行
        </button>
      ) : null}
    </section>
  );
}

function PublicId({ full = false, value }: { full?: boolean; value: string }) {
  return <code title={value}>{full ? value : `${value.slice(0, 8)}…`}</code>;
}

function formatDateTime(value: string): string {
  return tokyoDateTime.format(new Date(value));
}

function summaryStatusLabel(value: AdminGachaUsageHistoryStatusCount["status"]): string {
  return {
    selection_pending: "選択待ち",
    shipping: "配送",
    point_exchange: "ポイント交換",
    expired: "失効",
    hold: "保留",
    canceled: "取消",
  }[value];
}

function prizeStatusLabel(value: AdminGachaUsagePrizeStatus): string {
  return {
    stored: "選択待ち",
    exchange_processing: "ポイント交換処理中",
    converted: "ポイント交換",
    shipping_requested: "配送依頼",
    packing: "梱包中",
    shipped: "発送済み",
    delivered: "配送完了",
    hold: "保留",
    return_requested: "返品依頼",
    returned: "返品済み",
    expired: "失効",
    canceled: "取消",
  }[value];
}

function errorMessage(cause: unknown): string {
  if (cause instanceof AdminApiError) {
    if (cause.status === 404) return "指定されたガチャ利用履歴は存在しません。";
    if (cause.status === 403) return "ガチャ利用履歴を参照する権限がありません。";
  }
  return cause instanceof Error ? cause.message : "ガチャ利用履歴を取得できませんでした。";
}
