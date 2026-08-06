"use client";

import { ArrowLeft, ChevronLeft, ChevronRight, LoaderCircle, Plus, RotateCcw, Save } from "lucide-react";
import Link from "next/link";
import { useRouter } from "next/navigation";
import { type FormEvent, useCallback, useEffect, useMemo, useRef, useState } from "react";

import { FreshMfaDialog } from "@/components/auth/fresh-mfa-dialog";
import { Breadcrumb } from "@/components/navigation/breadcrumb";
import { usePermissions } from "@/components/permissions/permission-provider";
import { ProtectedAdminRoute } from "@/components/permissions/protected-admin-route";
import { AdminPageHeader } from "@/components/shell/admin-page-header";
import { AdminShell } from "@/components/shell/admin-shell";
import { AdminApiClient, AdminApiError } from "@/lib/admin-api/client";
import type { AdminPointPurchaseAudience, AdminPointPurchasePlan, AdminPointPurchasePlanInput } from "@/lib/admin-api/generated";
import { navigationItem } from "@/lib/permissions/admin-navigation";

type Mode = "list" | "create" | "edit";
type FormDraft = {
  name: string;
  amount: string;
  paidPointAmount: string;
  freePointAmount: string;
  sortOrder: string;
  audienceCode: AdminPointPurchaseAudience;
  isActive: boolean;
  availableFrom: string;
  availableUntil: string;
};

const EMPTY_FORM: FormDraft = {
  name: "",
  amount: "",
  paidPointAmount: "",
  freePointAmount: "0",
  sortOrder: "10",
  audienceCode: "all_users",
  isActive: true,
  availableFrom: "",
  availableUntil: "",
};

export function PointPurchaseManagementWorkspace({ mode, planId }: { mode: Mode; planId?: string }) {
  const client = useMemo(() => new AdminApiClient(), []);
  const navigation = navigationItem(mode === "create" ? "purchase-plans-create" : "purchase-plans");
  const { permissions } = usePermissions();
  const canManage = permissions.has("payment.plan.manage");
  const router = useRouter();
  const [plans, setPlans] = useState<AdminPointPurchasePlan[]>([]);
  const [plan, setPlan] = useState<AdminPointPurchasePlan | null>(null);
  const [draft, setDraft] = useState<FormDraft>(EMPTY_FORM);
  const [cursor, setCursor] = useState<string | undefined>();
  const [nextCursor, setNextCursor] = useState<string | null>(null);
  const [cursorHistory, setCursorHistory] = useState<Array<string | undefined>>([]);
  const [busy, setBusy] = useState<"load" | "save" | null>("load");
  const [error, setError] = useState<AdminApiError | null>(null);
  const [freshMfaOpen, setFreshMfaOpen] = useState(false);
  const pendingKey = useRef<string | null>(null);

  const load = useCallback(async () => {
    setBusy("load");
    setError(null);
    try {
      if (mode === "list") {
        const result = await client.listPointPurchasePlans(cursor);
        setPlans(result.items);
        setNextCursor(result.next_cursor);
      } else if (mode === "edit" && planId) {
        const result = await client.getPointPurchasePlan(planId);
        setPlan(result.data);
        setDraft(toDraft(result.data));
      } else {
        setDraft(EMPTY_FORM);
      }
    } catch (caught) {
      setError(asApiError(caught));
    } finally {
      setBusy(null);
    }
  }, [client, cursor, mode, planId]);

  useEffect(() => {
    const timeout = window.setTimeout(() => void load(), 0);
    return () => window.clearTimeout(timeout);
  }, [load]);

  async function submit(event?: FormEvent<HTMLFormElement>) {
    event?.preventDefault();
    if (!canManage || invalid(draft)) return;
    setBusy("save");
    setError(null);
    pendingKey.current ??= crypto.randomUUID();
    try {
      const input = toInput(draft);
      const result = mode === "edit" && plan
        ? await client.updatePointPurchasePlan(plan.id, { ...input, expected_revision: plan.revision }, pendingKey.current)
        : await client.createPointPurchasePlan(input, pendingKey.current);
      pendingKey.current = null;
      router.push(`/purchase-plans/${result.data.id}`);
      router.refresh();
    } catch (caught) {
      const next = asApiError(caught);
      setError(next);
      if (next.requiresFreshMfa) setFreshMfaOpen(true);
      else pendingKey.current = null;
    } finally {
      setBusy(null);
    }
  }

  return (
    <AdminShell>
      <ProtectedAdminRoute permission={mode === "list" ? "payment.plan.read" : "payment.plan.manage"}>
        <div className="workspace point-purchase-workspace">
          <Breadcrumb item={navigation} />
          <AdminPageHeader
            action={mode === "list" && canManage ? <Link className="primary-button" href="/purchase-plans/new"><Plus aria-hidden="true" size={17} />新規登録</Link> : undefined}
            eyebrow="Point Purchase"
            title={mode === "list" ? "ポイント購入商品" : mode === "create" ? "ポイント商品登録" : "ポイント商品編集"}
          />
          {error ? <ErrorNotice error={error} onRetry={load} /> : null}
          {busy === "load" ? <Loading /> : mode === "list" ? (
            <PlanList plans={plans} />
          ) : (
            <PlanForm draft={draft} disabled={!canManage || busy === "save"} editing={mode === "edit"} onChange={setDraft} onSubmit={submit} />
          )}
          {mode === "list" && busy !== "load" ? (
            <nav aria-label="ポイント購入商品ページ" className="cursor-actions">
              <button className="secondary-button" disabled={cursorHistory.length === 0} onClick={() => { const history = [...cursorHistory]; setCursor(history.pop()); setCursorHistory(history); }} type="button"><ChevronLeft aria-hidden="true" size={17} />前へ</button>
              <button className="secondary-button" disabled={!nextCursor} onClick={() => { setCursorHistory((current) => [...current, cursor]); setCursor(nextCursor ?? undefined); }} type="button">次へ<ChevronRight aria-hidden="true" size={17} /></button>
            </nav>
          ) : null}
          {mode !== "list" ? <Link className="secondary-button point-purchase-back" href="/purchase-plans"><ArrowLeft aria-hidden="true" size={17} />一覧へ戻る</Link> : null}
        </div>
        <FreshMfaDialog onClose={() => setFreshMfaOpen(false)} onSuccess={async () => { setFreshMfaOpen(false); await submit(); }} open={freshMfaOpen} />
      </ProtectedAdminRoute>
    </AdminShell>
  );
}

function PlanList({ plans }: { plans: AdminPointPurchasePlan[] }) {
  if (plans.length === 0) return <section className="module-state"><h2>ポイント購入商品はありません</h2></section>;
  return <div className="table-scroll point-purchase-table"><table><thead><tr>{["ID", "商品名", "支払金額", "有償P", "無償P", "販売期間", "並び順", "対象カテゴリ", "状態", "編集"].map((label) => <th key={label} scope="col">{label}</th>)}</tr></thead><tbody>{plans.map((item) => <tr key={item.id}><td><code>{item.id}</code></td><td>{item.name}</td><td>{yen(item.amount)}</td><td>{points(item.paid_point_amount)}</td><td>{points(item.free_point_amount)}</td><td>{period(item)}</td><td>{item.sort_order}</td><td>{audience(item.audience_code)}</td><td><span className={`status-pill ${item.is_active ? "status-active" : "status-muted"}`}>{item.is_active ? "有効" : "無効"}</span></td><td><Link aria-label={`${item.name}を編集`} className="secondary-button compact-button" href={`/purchase-plans/${item.id}`}>編集</Link></td></tr>)}</tbody></table></div>;
}

function PlanForm({ draft, disabled, editing, onChange, onSubmit }: { draft: FormDraft; disabled: boolean; editing: boolean; onChange: (next: FormDraft) => void; onSubmit: (event: FormEvent<HTMLFormElement>) => void }) {
  const invalidForm = invalid(draft);
  return <form className="point-purchase-form" onSubmit={onSubmit}>
    <section className="point-purchase-section"><h2>商品内容</h2><div className="point-purchase-grid">
      <TextField label="商品名" value={draft.name} onChange={(name) => onChange({ ...draft, name })} />
      <NumberField label="支払金額" min={1} value={draft.amount} onChange={(amount) => onChange({ ...draft, amount })} />
      <NumberField label="付与有償ポイント" min={1} value={draft.paidPointAmount} onChange={(paidPointAmount) => onChange({ ...draft, paidPointAmount })} />
      <NumberField label="付与無償ポイント" min={0} value={draft.freePointAmount} onChange={(freePointAmount) => onChange({ ...draft, freePointAmount })} />
      <NumberField label="並び順" min={0} value={draft.sortOrder} onChange={(sortOrder) => onChange({ ...draft, sortOrder })} />
      <label><span>対象カテゴリ</span><select value={draft.audienceCode} onChange={(event) => onChange({ ...draft, audienceCode: event.target.value as AdminPointPurchaseAudience })}><option value="all_users">すべてのユーザー</option><option value="first_purchase_users">初回ユーザー</option></select></label>
    </div></section>
    <section className="point-purchase-section"><h2>掲載設定</h2><div className="point-purchase-grid">
      <label><span>販売開始日時</span><input type="datetime-local" value={draft.availableFrom} onChange={(event) => onChange({ ...draft, availableFrom: event.target.value })} /></label>
      <label><span>販売終了日時</span><input type="datetime-local" value={draft.availableUntil} onChange={(event) => onChange({ ...draft, availableUntil: event.target.value })} /></label>
    </div><label className="check-row"><input checked={draft.isActive} onChange={(event) => onChange({ ...draft, isActive: event.target.checked })} type="checkbox" /><span>有効</span></label></section>
    {invalidForm ? <p className="notice notice-error" role="alert">必須項目、数値、販売期間を確認してください。有償ポイントは支払金額と同額にしてください。</p> : null}
    <button className="primary-button" disabled={disabled || invalidForm} type="submit">{disabled ? <LoaderCircle aria-hidden="true" className="spin" size={17} /> : <Save aria-hidden="true" size={17} />}{editing ? "更新" : "登録"}</button>
  </form>;
}

function TextField({ label, onChange, value }: { label: string; onChange: (value: string) => void; value: string }) { return <label><span>{label}</span><input maxLength={191} onChange={(event) => onChange(event.target.value)} required value={value} /></label>; }
function NumberField({ label, min, onChange, value }: { label: string; min: number; onChange: (value: string) => void; value: string }) { return <label><span>{label}</span><input inputMode="numeric" max={1_000_000} min={min} onChange={(event) => onChange(event.target.value)} required type="number" value={value} /></label>; }
function Loading() { return <section className="module-state" role="status"><LoaderCircle aria-hidden="true" className="spin" size={24} /><h2>読み込んでいます</h2></section>; }
function ErrorNotice({ error, onRetry }: { error: AdminApiError; onRetry: () => Promise<void> }) { return <div className="notice notice-error" role="alert"><p>{error.status === 409 ? "別の操作で更新されています。最新情報を取得してください。" : error.message}</p><button className="secondary-button" onClick={() => void onRetry()} type="button"><RotateCcw aria-hidden="true" size={17} />再読み込み</button></div>; }
function asApiError(value: unknown): AdminApiError { return value instanceof AdminApiError ? value : new AdminApiError(0, "NETWORK_ERROR", null, null, true); }
function int(value: string): number { return Number(value); }
function invalid(draft: FormDraft): boolean { const amount = int(draft.amount), paid = int(draft.paidPointAmount), free = int(draft.freePointAmount), sort = int(draft.sortOrder); return draft.name.trim() === "" || ![amount, paid, free, sort].every(Number.isInteger) || amount < 1 || amount > 1_000_000 || paid !== amount || free < 0 || free > 1_000_000 || sort < 0 || sort > 1_000_000 || (!!draft.availableFrom && !!draft.availableUntil && draft.availableUntil <= draft.availableFrom); }
function toInput(draft: FormDraft): AdminPointPurchasePlanInput { return { name: draft.name.trim(), amount: int(draft.amount), paid_point_amount: int(draft.paidPointAmount), free_point_amount: int(draft.freePointAmount), sort_order: int(draft.sortOrder), audience_code: draft.audienceCode, is_active: draft.isActive, available_from: fromJst(draft.availableFrom), available_until: fromJst(draft.availableUntil) }; }
function toDraft(plan: AdminPointPurchasePlan): FormDraft { return { name: plan.name, amount: String(plan.amount), paidPointAmount: String(plan.paid_point_amount), freePointAmount: String(plan.free_point_amount), sortOrder: String(plan.sort_order), audienceCode: plan.audience_code, isActive: plan.is_active, availableFrom: toJstInput(plan.available_from), availableUntil: toJstInput(plan.available_until) }; }
function fromJst(value: string): string | null { return value ? `${value}:00+09:00` : null; }
function toJstInput(value: string | null): string { if (!value) return ""; const date = new Date(value); return new Date(date.getTime() + 9 * 60 * 60 * 1000).toISOString().slice(0, 16); }
function formatJst(value: string): string { return new Intl.DateTimeFormat("ja-JP", { dateStyle: "short", timeStyle: "short", timeZone: "Asia/Tokyo" }).format(new Date(value)); }
function period(plan: AdminPointPurchasePlan): string { if (!plan.available_from && !plan.available_until) return "無期限"; return `${plan.available_from ? formatJst(plan.available_from) : "指定なし"} - ${plan.available_until ? formatJst(plan.available_until) : "指定なし"}`; }
function audience(value: AdminPointPurchaseAudience): string { return value === "first_purchase_users" ? "初回ユーザー" : "すべてのユーザー"; }
function yen(value: number): string { return new Intl.NumberFormat("ja-JP", { style: "currency", currency: "JPY" }).format(value); }
function points(value: number): string { return `${value.toLocaleString("ja-JP")} P`; }
