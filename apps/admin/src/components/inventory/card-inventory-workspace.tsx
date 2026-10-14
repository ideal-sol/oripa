"use client";

import { useEffect, useMemo, useState } from "react";
import { useAdminAuth } from "@/components/auth/admin-auth-provider";
import { CursorPagination } from "@/components/catalog/cursor-pagination";
import { PublicAssetPreview } from "@/components/catalog/public-asset-preview";
import { ProtectedAdminRoute } from "@/components/permissions/protected-admin-route";
import { PermissionGate } from "@/components/permissions/permission-gate";
import { AdminPageHeader } from "@/components/shell/admin-page-header";
import { AdminShell } from "@/components/shell/admin-shell";
import { AdminApiClient, AdminApiError } from "@/lib/admin-api/client";
import type { AdminInventoryCard, AdminInventoryCardCollection, AdminInventoryCardDetail, AdminInventoryUnlinkedCollection, AdminNotionStatus } from "@/lib/admin-api/generated";
import styles from "./card-inventory.module.css";

type Response = AdminInventoryCardCollection | AdminInventoryCardDetail | AdminInventoryUnlinkedCollection;
type Result = { key: string; data?: Response; error?: string };
const statusLabels: Record<string, string> = {
  stored: "保管中", exchange_processing: "交換処理中", shipping_requested: "発送依頼済み",
  packing: "梱包中", hold: "保留", converted: "コイン交換済み", expired: "期限切れ",
  shipped: "発送済み", delivered: "配達済み", return_requested: "返送依頼済み", returned: "返送", canceled: "取消",
  draft: "下書き", scheduled: "公開予約", published: "公開中", sales_paused: "販売停止", unpublished: "非公開",
};

export function CardInventoryWorkspace() {
  return <AdminShell><ProtectedAdminRoute permission="catalog.read"><InventoryContent /></ProtectedAdminRoute></AdminShell>;
}

export function InventoryContent() {
  const client = useMemo(() => new AdminApiClient(), []);
  const { expireSession } = useAdminAuth();
  const [view, setView] = useState<"cards" | "detail" | "unlinked">("cards");
  const [externalId, setExternalId] = useState("");
  const [searchId, setSearchId] = useState("");
  const [searchName, setSearchName] = useState("");
  const [filters, setFilters] = useState({ external_id: "", name: "", reserved_only: false, negative_only: false });
  const [history, setHistory] = useState<(string | null)[]>([null]);
  const [reload, setReload] = useState(0);
  const [result, setResult] = useState<Result | null>(null);
  const cursor = history[history.length - 1] ?? undefined;
  const key = JSON.stringify([view, externalId, filters, cursor, reload]);
  const current = result?.key === key ? result : null;
  const data = current?.data;

  useEffect(() => {
    const controller = new AbortController();
    const paging = { cursor, limit: 20 };
    const request = view === "detail" ? client.getInventoryCard(externalId, paging, controller.signal)
      : view === "unlinked" ? client.listInventoryUnlinkedPrizes(paging, controller.signal)
        : client.listInventoryCards({ ...filters, ...paging }, controller.signal);
    request.then((response) => {
      if (!controller.signal.aborted) setResult({ key, data: response });
    }).catch((error: unknown) => {
      if (controller.signal.aborted) return;
      if (error instanceof AdminApiError && error.status === 401) expireSession();
      setResult({ key, error: error instanceof AdminApiError && error.status === 403
        ? "この在庫を表示する権限がありません。"
        : "在庫を取得できませんでした。時間をおいて再試行してください。" });
    });
    return () => controller.abort();
  }, [client, cursor, expireSession, externalId, filters, key, view]);

  function open(next: typeof view, id = "") {
    setView(next);
    setExternalId(id);
    setHistory([null]);
  }

  return <div className={`workspace ${styles.workspace}`}>
    <AdminPageHeader title="カード別在庫" eyebrow="Inventory" description="確保必要数は企画割当（未当選）と当選保管中の合計です。QA当選は含みません。" />
    <NotionStatus client={client} onCompleted={() => setReload((value) => value + 1)} />
    {view !== "cards" && <button className="secondary-button" type="button" onClick={() => open("cards")}>カード一覧へ戻る</button>}
    {view === "cards" && <form className={styles.filters} onSubmit={(event) => {
      event.preventDefault();
      setFilters({ ...filters, external_id: searchId, name: searchName });
      setHistory([null]);
    }}>
      <label>管理ID<input value={searchId} maxLength={64} onChange={(event) => setSearchId(event.target.value)} /></label>
      <label>カード名<input value={searchName} maxLength={200} onChange={(event) => setSearchName(event.target.value)} /></label>
      <label><input type="checkbox" checked={filters.reserved_only} onChange={(event) => {
        setFilters({ ...filters, reserved_only: event.target.checked }); setHistory([null]);
      }} />確保ありのみ</label>
      <label><input type="checkbox" checked={filters.negative_only} onChange={(event) => {
        setFilters({ ...filters, negative_only: event.target.checked }); setHistory([null]);
      }} />空き在庫がマイナスのみ</label>
      <button className="primary-button" type="submit">検索</button>
    </form>}
    {!current && <p role="status">在庫を読み込んでいます…</p>}
    {current?.error && <section role="alert"><p>{current.error}</p><button className="secondary-button" onClick={() => setReload(reload + 1)}>再試行</button></section>}
    {data && "unlinked_prize_count" in data && <>
      {data.unlinked_prize_count > 0 && <section role="alert" className={styles.notice}>
        <span>管理ID未設定：{data.unlinked_prize_count.toLocaleString("ja-JP")}件（版・景品の組）。カード別集計には含まれません。</span>
        <button className="secondary-button" onClick={() => open("unlinked")}>管理ID未設定景品を確認</button>
      </section>}
      {data.items.length === 0 ? <p>該当するカードはありません。</p> : <div className={styles.tableScroll}>
        <table><caption>カード在庫一覧</caption><thead><tr>{["カード", "企画割当", "当選保管中", "確保必要数", "発送済み", "返送", "手持ち枚数", "空き在庫"].map((title) => <th scope="col" key={title}>{title}</th>)}</tr></thead>
          <tbody>{data.items.map((card) => <tr key={card.external_id}>
            <th scope="row"><div className={styles.card}><PublicAssetPreview asset={card.asset} /><button type="button" onClick={() => open("detail", card.external_id)}>{card.external_id}<br />{card.name ?? "名称未登録"}</button></div></th>
            <CardCells card={card} />
          </tr>)}</tbody>
        </table>
      </div>}
    </>}
    {data && "breakdown" in data && <>
      <h2>{data.data.external_id} {data.data.name ?? "名称未登録"}</h2>
      <div className={styles.card}><PublicAssetPreview asset={data.data.asset} /><p>確保必要数：{data.data.required_quantity.toLocaleString("ja-JP")}枚</p></div>
      <div className={styles.tableScroll}><table><caption>カード集計値</caption><thead><tr>{["企画割当", "当選保管中", "確保必要数", "発送済み", "返送", "手持ち枚数", "空き在庫"].map((title) => <th key={title}>{title}</th>)}</tr></thead><tbody><tr><CardCells card={data.data} /></tr></tbody></table></div>
      <h3>ガチャ・版別内訳</h3>
      {data.breakdown.length === 0 ? <p>割当・当選はありません。</p> : <div className={styles.tableScroll}><table><thead><tr>{["ガチャ", "管理状態", "版", "企画割当（未当選）", "当選済み（QA除外）", "当選保管中", "発送済み", "返送"].map((title) => <th key={title}>{title}</th>)}</tr></thead>
        <tbody>{data.breakdown.map((entry) => <tr key={entry.version_id}>
          <th scope="row">{entry.gacha_name}</th><td>{statusLabels[entry.management_status]}{entry.gacha_archived ? "・アーカイブ済み" : ""}</td>
          <td>{entry.version_number} {entry.is_selected_version ? "割当対象" : "保有履歴"}</td>
          {[entry.allocation, entry.won, entry.stored, entry.shipped, entry.returned].map((value, index) => <td key={index}>{value.toLocaleString("ja-JP")}</td>)}
        </tr>)}</tbody></table></div>}
      <h3>保有景品ステータス別件数（QA除外）</h3>
      {Object.keys(data.status_counts).length === 0 ? <p>保有景品はありません。</p> : <dl className={styles.statuses}>{Object.entries(data.status_counts).map(([status, count]) => <div key={status}><dt>{statusLabels[status] ?? status}</dt><dd>{count.toLocaleString("ja-JP")}件</dd></div>)}</dl>}
    </>}
    {data && "total_count" in data && <>
      <h2>管理ID未設定景品：{data.total_count.toLocaleString("ja-JP")}件</h2>
      <p>件数は版・景品の組、枚数は企画割当と当選保管中の合計です。非公開・アーカイブ後の保管景品も含みます。</p>
      {data.items.length === 0 ? <p>管理ID未設定の対象景品はありません。</p> : <div className={styles.tableScroll}><table><thead><tr>{["ガチャ", "管理状態", "版", "景品", "企画割当", "当選保管中", "枚数"].map((title) => <th key={title}>{title}</th>)}</tr></thead>
        <tbody>{data.items.map((entry) => <tr key={entry.id}><td>{entry.gacha_name}</td><td>{statusLabels[entry.management_status]}{entry.gacha_archived ? "・アーカイブ済み" : ""}</td><td>{entry.version_number}</td><th scope="row">{entry.prize_name}</th><td>{entry.allocation.toLocaleString("ja-JP")}</td><td>{entry.stored.toLocaleString("ja-JP")}</td><td>{entry.quantity.toLocaleString("ja-JP")}</td></tr>)}</tbody></table></div>}
    </>}
    <CursorPagination canGoBack={Boolean(data) && history.length > 1} canGoNext={Boolean(data?.next_cursor)} onBack={() => setHistory(history.slice(0, -1))} onNext={() => {
      if (data?.next_cursor) setHistory([...history, data.next_cursor]);
    }} />
    <p>集計はリクエストごとの時点です。別ページを取得すると集計時点が変わります。</p>
  </div>;
}

function CardCells({ card }: { card: AdminInventoryCard }) {
  return <>{[card.allocation, card.stored, card.required_quantity, card.shipped, card.returned].map((value, index) => <td key={index}>{value.toLocaleString("ja-JP")}</td>)}
    <td>{card.notion_on_hand?.toLocaleString("ja-JP") ?? "未連携・未確認"}<br /><small>最終読取：{formatReadAt(card.notion_read_at)}</small></td>
    <td className={card.notion_available !== null && card.notion_available < 0 ? styles.negative : undefined}>{card.notion_available?.toLocaleString("ja-JP") ?? "未連携・未確認"}</td></>;
}

function formatReadAt(value: string | null) {
  return value === null ? "未読取" : new Date(value).toLocaleString("ja-JP", { timeZone: "Asia/Tokyo" });
}

function NotionStatus({ client, onCompleted }: { client: AdminApiClient; onCompleted: () => void }) {
  const { expireSession } = useAdminAuth();
  const [status, setStatus] = useState<AdminNotionStatus | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [pending, setPending] = useState(false);
  const [refresh, setRefresh] = useState(0);
  useEffect(() => {
    const controller = new AbortController();
    const read = () => client.notionStatus(controller.signal).then(setStatus).catch((cause: unknown) => {
      if (controller.signal.aborted) return;
      if (cause instanceof AdminApiError && cause.status === 401) expireSession();
      setError("Notion同期状態を取得できませんでした。");
    });
    void read();
    const timer = setInterval(() => { void read(); }, 10000);
    return () => { controller.abort(); clearInterval(timer); };
  }, [client, expireSession, refresh]);

  async function synchronize() {
    setPending(true);
    setError(null);
    try {
      await client.createNotionSyncRun(crypto.randomUUID());
      setRefresh((value) => value + 1);
      onCompleted();
    } catch (cause) {
      if (cause instanceof AdminApiError && cause.status === 401) expireSession();
      setError("同期を予約できませんでした。権限と設定を確認して再試行してください。");
    } finally { setPending(false); }
  }

  return <section className={styles.notice} aria-label="Notion連携状態">
    <strong>Notion：{status === null ? "確認中" : status.enabled ? "有効" : "無効"}</strong>
    {status && <>
      <span>{status.configured ? "設定済み" : "未設定"}</span>
      <span>最終同期：{formatReadAt(status.latest?.completed_at ?? status.latest?.started_at ?? null)}</span>
      <span>最終成功：{formatReadAt(status.last_success_at)} / 最終失敗：{formatReadAt(status.last_failure_at)}</span>
      <span>更新：{status.latest?.updated_count ?? 0}件</span>
      {status.active && <span role="status">{status.active.status === "queued" ? "同期待機中" : "同期実行中"}</span>}
      {status.error_code && <span role="alert">{status.error_code}</span>}
      {Boolean(status.latest?.missing_count) && <p role="alert">Notionに存在しない管理ID：{status.latest?.missing_count}件 {status.latest?.missing_ids.join("、")}</p>}
      {Boolean(status.latest?.duplicate_count) && <p role="alert">重複管理ID：{status.latest?.duplicate_count}件 {status.latest?.duplicate_ids.join("、")}</p>}
      {status.enabled && status.configured && <PermissionGate permission="catalog.manage"><button type="button" className="primary-button" disabled={pending || status.active !== null} onClick={() => { void synchronize(); }}>今すぐ同期</button></PermissionGate>}
    </>}
    {error && <p role="alert">{error}</p>}
    <button type="button" className="secondary-button" onClick={() => { setRefresh((value) => value + 1); onCompleted(); }}>状態・在庫を再読込</button>
  </section>;
}
