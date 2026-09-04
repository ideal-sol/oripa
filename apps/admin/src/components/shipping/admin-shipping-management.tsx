"use client";

import {
  ChevronLeft,
  ChevronRight,
  Download,
  Eye,
  LoaderCircle,
  RefreshCw,
  Save,
  Search,
} from "lucide-react";
import Link from "next/link";
import { type FormEvent, useEffect, useMemo, useState } from "react";

import { AdminPageHeader } from "@/components/shell/admin-page-header";
import {
  AdminApiClient,
  AdminApiError,
  type AdminShippingQuery,
  type AdminShippingStatusFilter,
} from "@/lib/admin-api/client";
import type {
  AdminShippingRequestDetail,
  AdminShippingRequestSummary,
  AdminShippingStatus,
} from "@/lib/admin-api/generated";

const statusOptions: Array<{ label: string; value: AdminShippingStatusFilter }> = [
  { label: "すべて", value: "all" },
  { label: "未配送", value: "requested" },
  { label: "配送手配済み", value: "packing" },
  { label: "配送済み", value: "shipped" },
  { label: "配送完了", value: "delivered" },
  { label: "保留", value: "hold" },
  { label: "返送依頼中", value: "return_requested" },
  { label: "返送済み", value: "returned" },
  { label: "取消", value: "canceled" },
];

const canonicalStatusOptions: Array<{ label: string; value: AdminShippingStatus }> = [
  { label: "未配送", value: "requested" },
  { label: "配送手配済み", value: "packing" },
  { label: "配送済み", value: "shipped" },
  { label: "配送完了", value: "delivered" },
];

interface ShippingFilters {
  dateFrom: string;
  dateTo: string;
  status: AdminShippingStatusFilter;
}

export function AdminShippingList({
  initialDateFrom = "",
  initialDateTo = "",
  initialStatus = "requested",
}: {
  initialDateFrom?: string;
  initialDateTo?: string;
  initialStatus?: AdminShippingStatusFilter;
}) {
  const client = useMemo(() => new AdminApiClient(), []);
  const initialFilters = useMemo(() => ({
    dateFrom: initialDateFrom,
    dateTo: initialDateTo,
    status: initialStatus,
  }), [initialDateFrom, initialDateTo, initialStatus]);
  const [draft, setDraft] = useState<ShippingFilters>(initialFilters);
  const [filters, setFilters] = useState<ShippingFilters>(initialFilters);
  const [items, setItems] = useState<AdminShippingRequestSummary[]>([]);
  const [selected, setSelected] = useState<Set<string>>(new Set());
  const [cursor, setCursor] = useState<string | undefined>();
  const [cursorStack, setCursorStack] = useState<Array<string | undefined>>([]);
  const [nextCursor, setNextCursor] = useState<string | null>(null);
  const [loading, setLoading] = useState(true);
  const [exporting, setExporting] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [reload, setReload] = useState(0);

  useEffect(() => {
    const controller = new AbortController();
    const query: AdminShippingQuery = {
      cursor,
      date_from: filters.dateFrom || undefined,
      date_to: filters.dateTo || undefined,
      limit: 20,
      status: filters.status,
    };
    client.listAdminShippingRequests(query, controller.signal)
      .then((response) => {
        if (controller.signal.aborted) return;
        setItems(response.items);
        setNextCursor(response.next_cursor);
        setError(null);
      })
      .catch((reason: unknown) => {
        if (!controller.signal.aborted) setError(shippingError(reason));
      })
      .finally(() => {
        if (!controller.signal.aborted) setLoading(false);
      });
    return () => controller.abort();
  }, [client, cursor, filters, reload]);

  function beginLoad() {
    setLoading(true);
    setError(null);
  }

  function applyFilters(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    beginLoad();
    setCursor(undefined);
    setCursorStack([]);
    setSelected(new Set());
    setFilters(draft);
    setReload((value) => value + 1);
  }

  async function exportSelected() {
    if (selected.size === 0) return;
    setExporting(true);
    setError(null);
    try {
      const result = await client.exportAdminShippingRequests([...selected]);
      const url = URL.createObjectURL(result.blob);
      const anchor = document.createElement("a");
      anchor.href = url;
      anchor.download = result.filename;
      anchor.click();
      URL.revokeObjectURL(url);
    } catch (reason) {
      setError(shippingError(reason));
    } finally {
      setExporting(false);
    }
  }

  const allVisibleSelected = items.length > 0 && items.every((item) => selected.has(item.id));

  return (
    <main className="workspace admin-user-prize-workspace">
      <AdminPageHeader
        eyebrow="Shipping"
        title="配送一覧"
        description="配送依頼を絞り込み、配送状態と送り状情報を管理します。"
      />
      <form className="admin-user-prize-filters shipping-filters" onSubmit={applyFilters}>
        <label>
          <span>状態</span>
          <select
            aria-label="状態"
            value={draft.status}
            onChange={(event) => setDraft({
              ...draft,
              status: event.target.value as AdminShippingStatusFilter,
            })}
          >
            {statusOptions.map((option) => (
              <option key={option.value} value={option.value}>{option.label}</option>
            ))}
          </select>
        </label>
        <label>
          <span>作成日時 From</span>
          <input
            aria-label="作成日時 From"
            type="date"
            value={draft.dateFrom}
            onChange={(event) => setDraft({ ...draft, dateFrom: event.target.value })}
          />
        </label>
        <label>
          <span>作成日時 To</span>
          <input
            aria-label="作成日時 To"
            type="date"
            value={draft.dateTo}
            onChange={(event) => setDraft({ ...draft, dateTo: event.target.value })}
          />
        </label>
        <div className="admin-user-prize-filter-actions">
          <button className="secondary-button" type="submit">
            <Search aria-hidden="true" size={16} />検索
          </button>
          <button
            className="text-button"
            onClick={() => {
              const reset: ShippingFilters = { dateFrom: "", dateTo: "", status: "requested" };
              setDraft(reset);
              setFilters(reset);
              setSelected(new Set());
              setCursor(undefined);
              setCursorStack([]);
              beginLoad();
              setReload((value) => value + 1);
            }}
            type="button"
          >
            条件を解除
          </button>
        </div>
      </form>

      <div className="shipping-list-actions">
        <span>{selected.size}件選択中</span>
        <button
          className="primary-button"
          disabled={selected.size === 0 || exporting}
          onClick={() => void exportSelected()}
          type="button"
        >
          <Download aria-hidden="true" size={16} />{exporting ? "出力中" : "選択した配送をCSV出力"}
        </button>
      </div>

      {error ? (
        <section className="module-state is-error" role="alert">
          <h2>配送情報を処理できませんでした</h2>
          <p>{error}</p>
          <button className="secondary-button" onClick={() => { beginLoad(); setReload((value) => value + 1); }} type="button">
            <RefreshCw aria-hidden="true" size={16} />再取得
          </button>
        </section>
      ) : loading ? (
        <section aria-live="polite" className="module-state" role="status">
          <LoaderCircle aria-hidden="true" className="spin" size={22} />
          <p>配送一覧を読み込んでいます。</p>
        </section>
      ) : items.length === 0 ? (
        <section className="module-state">
          <h2>該当する配送依頼はありません</h2>
          <p>検索条件を変更して再確認してください。</p>
        </section>
      ) : (
        <section aria-label="配送一覧" className="admin-user-prize-list-section">
          <div className="admin-user-prize-table-region" tabIndex={0}>
            <table className="admin-user-prize-table shipping-table">
              <thead>
                <tr>
                  <th scope="col">
                    <input
                      aria-label="表示中の配送をすべて選択"
                      checked={allVisibleSelected}
                      onChange={(event) => setSelected((current) => {
                        const next = new Set(current);
                        for (const item of items) {
                          if (event.target.checked) next.add(item.id);
                          else next.delete(item.id);
                        }
                        return next;
                      })}
                      type="checkbox"
                    />
                  </th>
                  <th scope="col">配送ID</th>
                  <th scope="col">User Public ID</th>
                  <th scope="col">商品数</th>
                  <th scope="col">状態</th>
                  <th scope="col">作成日時</th>
                  <th scope="col">配送会社</th>
                  <th scope="col">詳細</th>
                </tr>
              </thead>
              <tbody>
                {items.map((item) => (
                  <tr key={item.id}>
                    <td>
                      <input
                        aria-label={`配送 ${item.id} を選択`}
                        checked={selected.has(item.id)}
                        onChange={(event) => setSelected((current) => {
                          const next = new Set(current);
                          if (event.target.checked) next.add(item.id);
                          else next.delete(item.id);
                          return next;
                        })}
                        type="checkbox"
                      />
                    </td>
                    <td><code>{item.id}</code></td>
                    <td><code>{item.user_id ?? "-"}</code></td>
                    <td>{item.prize_count}</td>
                    <td><ShippingStatusBadge status={item.status} /></td>
                    <td>{formatJst(item.created_at)}</td>
                    <td>{item.carrier_code ?? "未設定"}</td>
                    <td>
                      <Link aria-label={`配送 ${item.id} の詳細`} className="icon-button" href={`/shipping/${item.id}`} title="詳細">
                        <Eye aria-hidden="true" size={18} />
                      </Link>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
          <nav aria-label="配送一覧ページ" className="admin-user-prize-pagination">
            <button
              className="secondary-button"
              disabled={cursorStack.length === 0}
              onClick={() => {
                beginLoad();
                setSelected(new Set());
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
                beginLoad();
                setSelected(new Set());
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

export function AdminShippingDetail({ shippingRequestId }: { shippingRequestId: string }) {
  const client = useMemo(() => new AdminApiClient(), []);
  const [data, setData] = useState<AdminShippingRequestDetail | null>(null);
  const [status, setStatus] = useState<AdminShippingStatus>("requested");
  const [carrierCode, setCarrierCode] = useState("");
  const [trackingNumber, setTrackingNumber] = useState("");
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [saved, setSaved] = useState(false);

  useEffect(() => {
    const controller = new AbortController();
    client.getAdminShippingRequest(shippingRequestId, controller.signal)
      .then((response) => {
        if (controller.signal.aborted) return;
        setData(response);
        if (canonicalStatusOptions.some((option) => option.value === response.status)) {
          setStatus(response.status);
        }
        setCarrierCode(response.carrier_code ?? "");
        setTrackingNumber(response.tracking_number ?? "");
      })
      .catch((reason: unknown) => {
        if (!controller.signal.aborted) setError(shippingError(reason));
      })
      .finally(() => {
        if (!controller.signal.aborted) setLoading(false);
      });
    return () => controller.abort();
  }, [client, shippingRequestId]);

  async function save(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setSaving(true);
    setSaved(false);
    setError(null);
    try {
      const response = await client.updateAdminShippingRequest(shippingRequestId, {
        carrier_code: carrierCode,
        reason: "admin_shipping_management",
        status,
        tracking_number: trackingNumber,
      });
      setData(response);
      setCarrierCode(response.carrier_code ?? "");
      setTrackingNumber(response.tracking_number ?? "");
      setSaved(true);
    } catch (reason) {
      setError(shippingError(reason));
    } finally {
      setSaving(false);
    }
  }

  if (loading) {
    return <main className="workspace"><section aria-live="polite" className="module-state" role="status"><LoaderCircle aria-hidden="true" className="spin" size={22} /><p>配送詳細を読み込んでいます。</p></section></main>;
  }
  if (!data) {
    return <main className="workspace"><section className="module-state is-error" role="alert"><h1>配送詳細を表示できません</h1><p>{error ?? "配送依頼が見つかりません。"}</p><Link className="secondary-button" href="/shipping">一覧へ戻る</Link></section></main>;
  }

  const trackingRequired = status === "shipped" || status === "delivered";
  const address = data.shipping_address;
  const items = data.items ?? [];

  return (
    <main className="workspace admin-user-prize-detail-stack">
      <AdminPageHeader eyebrow="Shipping" title="配送詳細" description="配送先Snapshot、対象商品、状態、送り状情報を確認・更新します。" />
      <section className="admin-user-prize-detail-section" aria-labelledby="shipping-overview-heading">
        <div className="admin-user-prize-detail-heading">
          <div><h2 id="shipping-overview-heading">配送情報</h2><p>配送ID <code>{data.id}</code></p></div>
          <Link className="secondary-button" href="/shipping">一覧へ戻る</Link>
        </div>
        <dl className="admin-user-prize-definition-grid">
          <Definition label="配送ID"><code>{data.id}</code></Definition>
          <Definition label="User Public ID"><code>{data.user_id ?? "-"}</code></Definition>
          <Definition label="現在状態"><ShippingStatusBadge status={data.status} /></Definition>
          <Definition label="作成日時">{formatJst(data.created_at)}</Definition>
          <Definition label="配送依頼日時">{formatJst(data.requested_at)}</Definition>
          <Definition label="発送日時">{formatJst(data.shipped_at)}</Definition>
          <Definition label="配送先氏名">{address.recipient_name}</Definition>
          <Definition label="郵便番号">{address.postal_code}</Definition>
          <Definition label="住所"><address>{address.prefecture}{address.city}{address.street}{address.building ? ` ${address.building}` : ""}</address></Definition>
          <Definition label="電話番号">{address.phone_number}</Definition>
        </dl>
      </section>

      <section className="admin-user-prize-detail-section" aria-labelledby="shipping-items-heading">
        <div className="admin-user-prize-detail-heading"><div><h2 id="shipping-items-heading">配送対象商品</h2><p>{items.length}商品を同一配送で処理します。</p></div></div>
        <div className="admin-user-prize-table-region" tabIndex={0}>
          <table className="admin-user-prize-history-table">
            <thead><tr><th>商品名</th><th>商品ID</th><th>保有景品ID</th></tr></thead>
            <tbody>{items.map((item) => <tr key={item.user_prize_id}><td>{item.name}</td><td><code>{item.product_id}</code></td><td><code>{item.user_prize_id}</code></td></tr>)}</tbody>
          </table>
        </div>
      </section>

      <section className="admin-user-prize-detail-section" aria-labelledby="shipping-update-heading">
        <div className="admin-user-prize-detail-heading"><div><h2 id="shipping-update-heading">配送状態・送り状情報</h2><p>通常4状態は前後どちらにも訂正できます。登録済みの送り状情報は保持されます。</p></div></div>
        <form className="announcement-form shipping-update-form" onSubmit={save}>
          {error ? <div className="form-error" role="alert">{error}</div> : null}
          {saved ? <p className="admin-user-adjustment-success" role="status">配送情報を更新しました。</p> : null}
          <label>状態<select aria-label="配送状態" value={status} onChange={(event) => setStatus(event.target.value as AdminShippingStatus)}>{canonicalStatusOptions.map((option) => <option key={option.value} value={option.value}>{option.label}</option>)}</select></label>
          <label>配送会社<input aria-label="配送会社" maxLength={64} required={trackingRequired} value={carrierCode} onChange={(event) => setCarrierCode(event.target.value)} /></label>
          <label>追跡番号<input aria-label="追跡番号" maxLength={191} required={trackingRequired} value={trackingNumber} onChange={(event) => setTrackingNumber(event.target.value)} /></label>
          <div className="announcement-form-actions"><button className="primary-button" disabled={saving} type="submit"><Save aria-hidden="true" size={16} />{saving ? "保存中" : "更新する"}</button></div>
        </form>
      </section>

      {data.status_history.length > 0 ? (
        <section className="admin-user-prize-detail-section" aria-labelledby="shipping-history-heading">
          <div className="admin-user-prize-detail-heading"><div><h2 id="shipping-history-heading">状態履歴</h2><p>既存のappend-only履歴を表示します。</p></div></div>
          <div className="admin-user-prize-table-region" tabIndex={0}>
            <table className="admin-user-prize-history-table"><thead><tr><th>日時</th><th>変更前</th><th>変更後</th></tr></thead><tbody>{data.status_history.map((history, index) => <tr key={`${history.occurred_at}-${index}`}><td>{formatJst(history.occurred_at)}</td><td>{history.from_status ? shippingStatusLabel(history.from_status) : "-"}</td><td>{shippingStatusLabel(history.to_status)}</td></tr>)}</tbody></table>
          </div>
        </section>
      ) : null}
    </main>
  );
}

function Definition({ children, label }: { children: React.ReactNode; label: string }) {
  return <div><dt>{label}</dt><dd>{children}</dd></div>;
}

function ShippingStatusBadge({ status }: { status: string }) {
  return <span className={`admin-user-prize-status is-${status}`}>{shippingStatusLabel(status)}</span>;
}

function shippingStatusLabel(status: string): string {
  return statusOptions.find((option) => option.value === status)?.label ?? status;
}

function formatJst(value: string | null | undefined): string {
  if (!value) return "未設定";
  return new Intl.DateTimeFormat("ja-JP", { dateStyle: "medium", timeStyle: "short", timeZone: "Asia/Tokyo" }).format(new Date(value));
}

function shippingError(reason: unknown): string {
  if (reason instanceof AdminApiError) {
    if (reason.status === 404) return "配送依頼が見つかりません。";
    if (reason.status === 409) return "現在状態との整合性を確認して再実行してください。";
    if (reason.status === 422) return "状態、期間、配送会社、追跡番号を確認してください。";
    if (reason.status === 403) return "この操作を行う権限がありません。";
  }
  return "配送情報を処理できませんでした。再試行してください。";
}
