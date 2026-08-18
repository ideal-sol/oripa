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
import type { AdminLimitedBonusCampaign, AdminLimitedBonusCampaignInput, AdminPointPurchaseAudience, AdminPointPurchasePlan, AdminPointPurchasePlanInput, AdminUserTag } from "@/lib/admin-api/generated";
import { navigationItem } from "@/lib/permissions/admin-navigation";

type Mode = "list" | "create" | "edit";
type FormDraft = {
  name: string;
  amount: string;
  paidPointAmount: string;
  freePointAmount: string;
  sortOrder: string;
  audienceCode: AdminPointPurchaseAudience;
  targetUserTagId: string;
  isActive: boolean;
  availableFrom: string;
  availableUntil: string;
};
type CampaignDraft = {
  isEnabled: boolean;
  startsAt: string;
  endsAt: string;
  bonusAmount: string;
};

const EMPTY_FORM: FormDraft = {
  name: "",
  amount: "",
  paidPointAmount: "",
  freePointAmount: "0",
  sortOrder: "10",
  audienceCode: "all_users",
  targetUserTagId: "",
  isActive: true,
  availableFrom: "",
  availableUntil: "",
};
const EMPTY_CAMPAIGN: CampaignDraft = {
  isEnabled: true,
  startsAt: "",
  endsAt: "",
  bonusAmount: "",
};

export function PointPurchaseManagementWorkspace({ mode, planId }: { mode: Mode; planId?: string }) {
  const client = useMemo(() => new AdminApiClient(), []);
  const navigation = navigationItem(mode === "create" ? "purchase-plans-create" : "purchase-plans");
  const { permissions } = usePermissions();
  const canManage = permissions.has("payment.plan.manage");
  const router = useRouter();
  const [plans, setPlans] = useState<AdminPointPurchasePlan[]>([]);
  const [plan, setPlan] = useState<AdminPointPurchasePlan | null>(null);
  const [tags, setTags] = useState<AdminUserTag[]>([]);
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
        const [result, availableTags] = await Promise.all([
          client.getPointPurchasePlan(planId),
          loadAllUserTags(client),
        ]);
        setPlan(result.data);
        setTags(availableTags);
        setDraft(toDraft(result.data));
      } else {
        setTags(await loadAllUserTags(client));
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
    if (!canManage || invalid(draft, tags)) return;
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
            <PlanList canManage={canManage} plans={plans} />
          ) : (
            <>
              <PlanForm draft={draft} disabled={!canManage || busy === "save"} editing={mode === "edit"} onChange={setDraft} onSubmit={submit} tags={tags} />
              {mode === "edit" && plan ? <CampaignManager canManage={canManage} client={client} plan={plan} /> : null}
            </>
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

function CampaignManager({ canManage, client, plan }: { canManage: boolean; client: AdminApiClient; plan: AdminPointPurchasePlan }) {
  const [campaigns, setCampaigns] = useState<AdminLimitedBonusCampaign[]>([]);
  const [draft, setDraft] = useState<CampaignDraft>(EMPTY_CAMPAIGN);
  const [editingId, setEditingId] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<AdminApiError | null>(null);
  const [freshMfaOpen, setFreshMfaOpen] = useState(false);
  const pendingKey = useRef<string | null>(null);

  const load = useCallback(async () => {
    setBusy(true);
    setError(null);
    try {
      const result = await client.listLimitedBonusCampaigns(plan.id);
      setCampaigns(result.items);
    } catch (caught) {
      setError(asApiError(caught));
    } finally {
      setBusy(false);
    }
  }, [client, plan.id]);

  useEffect(() => {
    const timeout = window.setTimeout(() => void load(), 0);
    return () => window.clearTimeout(timeout);
  }, [load]);

  async function submit(event?: FormEvent<HTMLFormElement>) {
    event?.preventDefault();
    if (!canManage || invalidCampaign(draft)) return;
    setBusy(true);
    setError(null);
    pendingKey.current ??= crypto.randomUUID();
    try {
      const input = campaignInput(draft);
      if (editingId) {
        await client.updateLimitedBonusCampaign(plan.id, editingId, input, pendingKey.current);
      } else {
        await client.createLimitedBonusCampaign(plan.id, input, pendingKey.current);
      }
      pendingKey.current = null;
      setDraft(EMPTY_CAMPAIGN);
      setEditingId(null);
      await load();
    } catch (caught) {
      const next = asApiError(caught);
      setError(next);
      if (next.requiresFreshMfa) setFreshMfaOpen(true);
      else pendingKey.current = null;
    } finally {
      setBusy(false);
    }
  }

  function edit(campaign: AdminLimitedBonusCampaign) {
    setEditingId(campaign.id);
    setDraft({
      isEnabled: campaign.is_enabled,
      startsAt: toJstInput(campaign.starts_at),
      endsAt: toJstInput(campaign.ends_at),
      bonusAmount: String(campaign.bonus_point_amount),
    });
    setError(null);
  }

  return <section className="point-purchase-section" aria-labelledby="limited-bonus-heading">
    <h2 id="limited-bonus-heading">期間限定ボーナスコイン</h2>
    <p>対象商品Version {plan.version} にだけ適用されます。時刻判定と重複判定はBackendが確定します。</p>
    {error ? <div className="notice notice-error" role="alert"><p>{campaignErrorMessage(error)}</p><button className="secondary-button" onClick={() => void load()} type="button"><RotateCcw aria-hidden="true" size={17} />再読み込み</button></div> : null}
    {busy && campaigns.length === 0 ? <Loading /> : campaigns.length === 0 ? <p>期間限定ボーナスコイン設定はありません。</p> : (
      <div className="table-scroll"><table><thead><tr>{["状態", "開始日時", "終了日時", "追加量", "編集"].map((label) => <th key={label} scope="col">{label}</th>)}</tr></thead><tbody>{campaigns.map((campaign) => <tr key={campaign.id}><td><span className={`status-pill ${campaign.is_enabled ? "status-active" : "status-muted"}`}>{campaign.is_enabled ? "ON" : "OFF"}</span></td><td>{formatJst(campaign.starts_at)}</td><td>{formatJst(campaign.ends_at)}</td><td>{campaign.bonus_point_amount.toLocaleString("ja-JP")} コイン</td><td><button className="secondary-button compact-button" disabled={!canManage || busy} onClick={() => edit(campaign)} type="button">編集</button></td></tr>)}</tbody></table></div>
    )}
    <form aria-label="期間限定ボーナスコイン設定" className="point-purchase-form" onSubmit={submit}>
      <h3>{editingId ? "設定を編集" : "設定を登録"}</h3>
      <div className="point-purchase-grid">
        <label><span>期間限定ボーナスコイン開始日時</span><input required type="datetime-local" value={draft.startsAt} onChange={(event) => setDraft({ ...draft, startsAt: event.target.value })} /></label>
        <label><span>期間限定ボーナスコイン終了日時</span><input required type="datetime-local" value={draft.endsAt} onChange={(event) => setDraft({ ...draft, endsAt: event.target.value })} /></label>
        <label><span>追加ボーナスコイン量</span><input inputMode="numeric" min={1} required type="number" value={draft.bonusAmount} onChange={(event) => setDraft({ ...draft, bonusAmount: event.target.value })} /></label>
      </div>
      <label className="check-row"><input checked={draft.isEnabled} onChange={(event) => setDraft({ ...draft, isEnabled: event.target.checked })} type="checkbox" /><span>期間限定ボーナスコインをONにする</span></label>
      {invalidCampaign(draft) ? <p className="notice notice-error" role="alert">開始日時、終了日時、追加ボーナスコイン量を確認してください。</p> : null}
      <button className="primary-button" disabled={!canManage || busy || invalidCampaign(draft)} type="submit">{busy ? <LoaderCircle aria-hidden="true" className="spin" size={17} /> : <Save aria-hidden="true" size={17} />}{editingId ? "設定を更新" : "設定を登録"}</button>
      {editingId ? <button className="secondary-button" disabled={busy} onClick={() => { setEditingId(null); setDraft(EMPTY_CAMPAIGN); }} type="button">登録へ戻す</button> : null}
    </form>
    <FreshMfaDialog onClose={() => setFreshMfaOpen(false)} onSuccess={async () => { setFreshMfaOpen(false); await submit(); }} open={freshMfaOpen} />
  </section>;
}

function PlanList({ canManage, plans }: { canManage: boolean; plans: AdminPointPurchasePlan[] }) {
  if (plans.length === 0) return <section className="module-state"><h2>ポイント購入商品はありません</h2></section>;
  return <div className="table-scroll point-purchase-table"><table><thead><tr>{["ID", "商品名", "支払金額", "有償P", "無償P", "販売期間", "並び順", "対象カテゴリ", "対象タグ", "状態", "編集"].map((label) => <th key={label} scope="col">{label}</th>)}</tr></thead><tbody>{plans.map((item) => <tr key={item.id}><td><code>{item.id}</code></td><td>{item.name}</td><td>{yen(item.amount)}</td><td>{points(item.paid_point_amount)}</td><td>{points(item.free_point_amount)}</td><td>{period(item)}</td><td>{item.sort_order}</td><td>{audience(item.audience_code)}</td><td>{targetTag(item)}</td><td><span className={`status-pill ${item.is_active ? "status-active" : "status-muted"}`}>{item.is_active ? "有効" : "無効"}</span></td><td>{canManage ? <Link aria-label={`${item.name}を編集`} className="secondary-button compact-button" href={`/purchase-plans/${item.id}`}>編集</Link> : "閲覧のみ"}</td></tr>)}</tbody></table></div>;
}

function PlanForm({ draft, disabled, editing, onChange, onSubmit, tags }: { draft: FormDraft; disabled: boolean; editing: boolean; onChange: (next: FormDraft) => void; onSubmit: (event: FormEvent<HTMLFormElement>) => void; tags: AdminUserTag[] }) {
  const invalidForm = invalid(draft, tags);
  return <form className="point-purchase-form" onSubmit={onSubmit}>
    <section className="point-purchase-section"><h2>商品内容</h2><div className="point-purchase-grid">
      <TextField label="商品名" value={draft.name} onChange={(name) => onChange({ ...draft, name })} />
      <NumberField label="支払金額" min={1} value={draft.amount} onChange={(amount) => onChange({ ...draft, amount })} />
      <NumberField label="付与有償ポイント" min={1} value={draft.paidPointAmount} onChange={(paidPointAmount) => onChange({ ...draft, paidPointAmount })} />
      <NumberField label="付与無償ポイント" min={0} value={draft.freePointAmount} onChange={(freePointAmount) => onChange({ ...draft, freePointAmount })} />
      <NumberField label="並び順" min={0} value={draft.sortOrder} onChange={(sortOrder) => onChange({ ...draft, sortOrder })} />
      <label><span>対象カテゴリ</span><select value={draft.audienceCode} onChange={(event) => onChange({ ...draft, audienceCode: event.target.value as AdminPointPurchaseAudience })}><option value="all_users">すべてのユーザー</option><option value="first_purchase_users">初回ユーザー</option></select></label>
      <label><span>対象タグ</span><select value={draft.targetUserTagId} onChange={(event) => onChange({ ...draft, targetUserTagId: event.target.value })}><option value="">指定なし</option>{tags.map((tag) => <option disabled={!tag.is_active} key={tag.id} value={tag.id}>{tag.name}{tag.is_active ? "" : "（無効）"}</option>)}</select></label>
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
function campaignErrorMessage(error: AdminApiError): string { if (error.code === "LIMITED_BONUS_CAMPAIGN_OVERLAP") return "同じ商品Versionの期間限定ボーナスコイン設定と期間が重複しています。"; if (error.code === "LIMITED_BONUS_CAMPAIGN_INVALID") return "期間限定ボーナスコインの日時または追加量が不正です。"; return error.message; }
function int(value: string): number { return Number(value); }
function invalidCampaign(draft: CampaignDraft): boolean { const amount = int(draft.bonusAmount); return !draft.startsAt || !draft.endsAt || draft.endsAt <= draft.startsAt || !Number.isSafeInteger(amount) || amount < 1; }
function campaignInput(draft: CampaignDraft): AdminLimitedBonusCampaignInput { return { is_enabled: draft.isEnabled, starts_at: `${draft.startsAt}:00+09:00`, ends_at: `${draft.endsAt}:00+09:00`, bonus_point_amount: int(draft.bonusAmount) }; }
function invalid(draft: FormDraft, tags: AdminUserTag[]): boolean { const amount = int(draft.amount), paid = int(draft.paidPointAmount), free = int(draft.freePointAmount), sort = int(draft.sortOrder); const target = draft.targetUserTagId ? tags.find((tag) => tag.id === draft.targetUserTagId) : null; return draft.name.trim() === "" || ![amount, paid, free, sort].every(Number.isInteger) || amount < 1 || amount > 1_000_000 || paid !== amount || free < 0 || free > 1_000_000 || sort < 0 || sort > 1_000_000 || (!!draft.targetUserTagId && !target?.is_active) || (!!draft.availableFrom && !!draft.availableUntil && draft.availableUntil <= draft.availableFrom); }
function toInput(draft: FormDraft): AdminPointPurchasePlanInput { return { name: draft.name.trim(), amount: int(draft.amount), paid_point_amount: int(draft.paidPointAmount), free_point_amount: int(draft.freePointAmount), sort_order: int(draft.sortOrder), audience_code: draft.audienceCode, target_user_tag_id: draft.targetUserTagId || null, is_active: draft.isActive, available_from: fromJst(draft.availableFrom), available_until: fromJst(draft.availableUntil) }; }
function toDraft(plan: AdminPointPurchasePlan): FormDraft { return { name: plan.name, amount: String(plan.amount), paidPointAmount: String(plan.paid_point_amount), freePointAmount: String(plan.free_point_amount), sortOrder: String(plan.sort_order), audienceCode: plan.audience_code, targetUserTagId: plan.target_user_tag?.id ?? "", isActive: plan.is_active, availableFrom: toJstInput(plan.available_from), availableUntil: toJstInput(plan.available_until) }; }
function fromJst(value: string): string | null { return value ? `${value}:00+09:00` : null; }
function toJstInput(value: string | null): string { if (!value) return ""; const date = new Date(value); return new Date(date.getTime() + 9 * 60 * 60 * 1000).toISOString().slice(0, 16); }
function formatJst(value: string): string { return new Intl.DateTimeFormat("ja-JP", { dateStyle: "short", timeStyle: "short", timeZone: "Asia/Tokyo" }).format(new Date(value)); }
function period(plan: AdminPointPurchasePlan): string { if (!plan.available_from && !plan.available_until) return "無期限"; return `${plan.available_from ? formatJst(plan.available_from) : "指定なし"} - ${plan.available_until ? formatJst(plan.available_until) : "指定なし"}`; }
function audience(value: AdminPointPurchaseAudience): string { return value === "first_purchase_users" ? "初回ユーザー" : "すべてのユーザー"; }
function targetTag(plan: AdminPointPurchasePlan): string { return plan.target_user_tag ? `${plan.target_user_tag.name}${plan.target_user_tag.is_active ? "" : "（無効）"}` : "指定なし"; }
async function loadAllUserTags(client: AdminApiClient): Promise<AdminUserTag[]> { const items: AdminUserTag[] = []; let cursor: string | undefined; do { const page = await client.listUserTags(cursor); items.push(...page.items); cursor = page.next_cursor ?? undefined; } while (cursor); return items; }
function yen(value: number): string { return new Intl.NumberFormat("ja-JP", { style: "currency", currency: "JPY" }).format(value); }
function points(value: number): string { return `${value.toLocaleString("ja-JP")} P`; }
