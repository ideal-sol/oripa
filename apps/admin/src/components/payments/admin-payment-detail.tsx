"use client";

import Link from "next/link";
import { useEffect, useMemo, useRef, useState } from "react";
import { PermissionGate } from "@/components/permissions/permission-gate";
import { AdminPageHeader } from "@/components/shell/admin-page-header";
import { AdminApiClient, AdminApiError } from "@/lib/admin-api/client";
import type { AdminPaymentDetail as PaymentDetail, AdminPaymentAdjustment, AdminPaymentPointImpact } from "@/lib/admin-api/generated";

type Operation = "refund" | "complete" | "cancel" | "chargeback" | "reversal";
const labels: Record<Operation, string> = { refund: "返金の準備", complete: "返金済みにする", cancel: "取りやめる", chargeback: "チャージバックを記録", reversal: "チャージバック取消を記録" };
const statuses: Record<string, string> = { requested: "受付", points_reserved: "返金準備中", submitted: "送信済み", processing: "処理中", succeeded: "成立", failed: "失敗", canceled: "取りやめ", manual_review: "要確認（自動復元なし）" };
const progressLabels: Record<string, string> = {created: "申込", redirect_started: "決済画面へ移動", inline_submitted: "カード入力を送信", inline_result: "カード画面の結果", paidy_checkout_opened: "ペイディを開いた", paidy_checkout_result: "ペイディ画面の結果", provider_notified: "決済会社から通知", provider_api_called: "決済会社への照会・操作", charge_reported: "課金IDを受信", succeeded: "成立", failed: "失敗", expired: "期限切れ", returned: "戻り先に到着"};
const date = (value: string | null) => value ? new Date(value).toLocaleString("ja-JP", { timeZone: "Asia/Tokyo" }) : "—";
const message = (error: unknown) => error instanceof AdminApiError
  ? error.status === 403 ? "この操作を行う権限がありません。" : error.status === 401 ? "再ログインしてください。"
    : error.status === 404 ? "決済または調整が見つかりません。" : error.message
  : "通信できませんでした。同じ内容で再試行できます。";

export function AdminPaymentDetail({ paymentId }: { paymentId: string }) {
  const client = useMemo(() => new AdminApiClient(), []);
  const [detail, setDetail] = useState<PaymentDetail | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [reload, setReload] = useState(0);
  const [action, setAction] = useState<{ operation: Operation; adjustmentId?: string } | null>(null);
  const [result, setResult] = useState<AdminPaymentAdjustment | null>(null);
  useEffect(() => {
    const controller = new AbortController();
    client.getAdminPayment(paymentId, controller.signal).then(response => {
      if (!controller.signal.aborted) { setDetail(response.data); setError(null); }
    }).catch(error => { if (!controller.signal.aborted) { setDetail(null); setError(message(error)); } });
    return () => controller.abort();
  }, [client, paymentId, reload]);
  return <div className="workspace">
    <AdminPageHeader title="決済詳細" eyebrow="Payments" description="決済会社側の返金・チャージバックを確認し、ポイントの取消を記録します。" />
    <Link href="/payments">決済履歴へ戻る</Link>
    {error && <p role="alert">{error}</p>}
    {!detail && !error && <p role="status">読み込み中…</p>}
    <button type="button" onClick={() => setReload(value => value + 1)}>最新の状態を取得</button>
    {result && <section aria-label="操作結果" role="status"><h2>記録しました：{statuses[result.status]}</h2>{result.impact && <Impact impact={result.impact} />}</section>}
    {detail && <>
      <section aria-label="決済情報"><h2>{detail.payment.amount.amount.toLocaleString()}円</h2>
        <p style={{ overflowWrap: "anywhere" }}>決済ID：{detail.payment.id}</p>
        <section aria-label="決済の経過"><h2>決済の経過（日本時間・記録時刻）</h2><ol>{detail.progress?.map((event, index) => <li key={index} style={{overflowWrap: "anywhere"}}>{date(event.recorded_at)} — {progressLabels[event.type] ?? event.type}{event.source === "client" ? "（会員画面の参考記録）" : ""}<span> {JSON.stringify(event.detail)}</span></li>)}</ol></section>
        <p>決済会社：{detail.payment.provider_label ?? detail.payment.provider} ／ 支払方法：{detail.payment.method_label ?? detail.payment.method} ／ 状態：{detail.payment.status}</p>
        <p style={{overflowWrap: "anywhere"}}>決済会社側の取引ID：{detail.payment.provider_payment_reference ?? "未発行"}</p>
        <p>成立日時：{date(detail.payment.succeeded_at)}（日本時間）</p>
        {detail.payment.adjustments?.review_required && <p role="alert">要確認：照合・売上確定・付与・返金の確認が必要です。決済会社側の状態と決済の経過を確認してください。</p>}
        {detail.rank_adjustment_pending && <p role="alert">ランク会計の確認が必要です。機能停止・会員凍結・元付与記録の欠落や不整合がある場合は取消を記録できません。返金予約の取りやめは可能です。</p>}
        <p>円返金額に購入ボーナスは含みません。ポイントとランクポイントの取消は別々に記録します。</p>
        {!detail.refund_eligibility.allowed && <p>返金準備不可：{detail.refund_eligibility.reason}</p>}
        <PermissionGate permission="payment.adjustment.manage">
          <button type="button" disabled={!detail.refund_eligibility.allowed} onClick={() => setAction({ operation: "refund" })}>{labels.refund}</button>
          <button type="button" disabled={detail.rank_adjustment_pending || detail.payment.status !== "succeeded" || detail.payment.adjustments?.refund_pending || detail.payment.adjustments?.refunded || detail.payment.adjustments?.chargeback} onClick={() => setAction({ operation: "chargeback" })}>{labels.chargeback}</button>
        </PermissionGate>
      </section>
      <section aria-label="付与ポイント"><h2>関連ポイント</h2>
        {detail.lots.length === 0 && <p>ポイント付与はありません。</p>}
        {detail.lots.map(lot => <p key={`${lot.operation_id}:${lot.point_type}`}>
          {lot.member_rank_purchase_bonus ? "会員ランク購入ボーナス（別Lot）" : lot.point_type === "paid" ? "有償" : "通常・期間限定の合算無償"}
          ：付与 {lot.granted_amount}pt ／ 残り {lot.remaining_amount}pt ／ 予約 {lot.reserved_amount}pt ／ 期限 {date(lot.expire_at)}
        </p>)}
      </section>
      <section aria-label="調整履歴"><h2>調整履歴</h2>
        {detail.adjustments.length === 0 && <p>調整の記録はありません。</p>}
        {detail.adjustments.map(adjustment => <article key={adjustment.id} id={`adjustment-${adjustment.id}`}>
          <h3>{adjustment.type === "refund" ? "返金" : adjustment.type === "chargeback" ? "チャージバック" : "チャージバック取消"}：{statuses[adjustment.status]}</h3>
          <p style={{ overflowWrap: "anywhere" }}>{adjustment.reason} ／ {date(adjustment.requested_at)}</p>
          <p style={{ overflowWrap: "anywhere" }}>操作主体：{adjustment.requested_by_admin_id ?? adjustment.source}</p>
          {adjustment.impact && <Impact impact={adjustment.impact} />}
          {adjustment.type === "chargeback_reversal" && <p>ポイントは自動復元されません。必要な手動調整を確認してください。</p>}
          <ul>{adjustment.history.map((history, index) => <li key={index} style={{ overflowWrap: "anywhere" }}>{date(history.occurred_at)}：{statuses[history.to_status]} ／ {history.admin_id ?? history.source}</li>)}</ul>
          <PermissionGate permission="payment.adjustment.manage">
            {adjustment.type === "refund" && adjustment.status === "points_reserved" && adjustment.source === "admin" && <>
              <button type="button" disabled={detail.rank_adjustment_pending} onClick={() => setAction({ operation: "complete", adjustmentId: adjustment.id })}>{labels.complete}</button>
              <button type="button" onClick={() => setAction({ operation: "cancel", adjustmentId: adjustment.id })}>{labels.cancel}</button>
            </>}
            {adjustment.type === "chargeback" && adjustment.status === "succeeded" && !detail.adjustments.some(child => child.parent_id === adjustment.id) &&
              <button type="button" onClick={() => setAction({ operation: "reversal", adjustmentId: adjustment.id })}>{labels.reversal}</button>}
          </PermissionGate>
        </article>)}
      </section>
    </>}
    {action && <AdjustmentDialog client={client} paymentId={paymentId} action={action} close={() => setAction(null)} complete={adjustment => {
      setResult(adjustment); setAction(null); setReload(value => value + 1);
    }} />}
  </div>;
}

function Impact({ impact }: { impact: AdminPaymentPointImpact }) {
  return <div><p>取消：有償 {impact.reversed_paid_from_paid}pt ／ 無償 {impact.reversed_free_from_free + impact.reversed_paid_shortage_from_free}pt
    （有償不足への充当 {impact.reversed_paid_shortage_from_free}pt）<br />
    不足：有償 {impact.shortfall_paid_amount}pt ／ 無償 {impact.shortfall_free_amount}pt</p>
    {impact.purchase_bonus && <p>うち購入ボーナス：必要 {impact.purchase_bonus.required_amount}pt ／ 取消 {impact.purchase_bonus.reversed_amount}pt ／ 不足 {impact.purchase_bonus.shortfall_amount}pt（無償不足の内数）</p>}
    {impact.rank_adjustment && <section aria-label="ランク取消">
      <p>元月 {impact.rank_adjustment.month.slice(0, 7)}：対象 {impact.rank_adjustment.requested_points}pt ／ 実減算 {impact.rank_adjustment.applied_points}pt</p>
      <p>{impact.rank_adjustment.effect === "past_month" ? "過去月の取消：現在ランクは変更しません（要確認）。" : impact.rank_adjustment.effect === "none" ? "ランクポイント対象外です。" : `ランク ${impact.rank_adjustment.before_rank} → ${impact.rank_adjustment.after_rank}`}</p>
      {impact.rank_adjustment.recoveries.map(recovery => <p key={recovery.rank}>{recovery.rank} 昇格ボーナス：回収 {recovery.recovered_amount}pt ／ 不足 {recovery.shortfall_amount}pt</p>)}
    </section>}
  </div>;
}

function AdjustmentDialog({ client, paymentId, action, close, complete }: {
  client: AdminApiClient; paymentId: string; action: { operation: Operation; adjustmentId?: string };
  close: () => void; complete: (result: AdminPaymentAdjustment) => void;
}) {
  const dialog = useRef<HTMLDialogElement>(null);
  const busy = useRef(false);
  const replay = useRef<{ payload: string; key: string } | null>(null);
  const [reason, setReason] = useState("");
  const [reference, setReference] = useState("");
  const [confirmed, setConfirmed] = useState(false);
  const [pending, setPending] = useState(false);
  const [preview, setPreview] = useState<AdminPaymentPointImpact | null>(null);
  const [error, setError] = useState<string | null>(null);
  useEffect(() => { dialog.current?.showModal(); }, []);
  const isComplete = action.operation === "complete";
  async function submit() {
    if (busy.current) return;
    busy.current = true; setPending(true); setError(null);
    const body = isComplete ? { reason, provider_refund_confirmed: true as const, ...(reference ? { provider_adjustment_id: reference } : {}) }
      : { reason, ...(reference ? { provider_case_id: reference } : {}) };
    try {
      if (action.operation === "chargeback" && !preview) {
        setPreview((await client.previewAdminPaymentChargeback(paymentId, body)).data.impact);
      } else {
        const payload = JSON.stringify(body);
        if (!replay.current || replay.current.payload !== payload) replay.current = { payload, key: crypto.randomUUID() };
        complete((await client.writeAdminPaymentAdjustment(paymentId, action.operation, body, replay.current.key, action.adjustmentId)).data);
      }
    } catch (error) { setError(message(error)); }
    finally { busy.current = false; setPending(false); }
  }
  return <dialog ref={dialog} aria-labelledby="adjustment-title" onCancel={event => { event.preventDefault(); if (!pending) close(); }} style={{ maxWidth: "min(38rem, 94vw)", overflowWrap: "anywhere" }}>
    <form onSubmit={event => { event.preventDefault(); void submit(); }}>
      <h2 id="adjustment-title">{labels[action.operation]}</h2>
      <p>決済会社への返金依頼・自動通知は行いません。</p>
      <label>理由（必須）<textarea required maxLength={500} value={reason} disabled={pending} onChange={event => { setReason(event.target.value); setPreview(null); }} /></label>
      {(isComplete || action.operation === "chargeback" || action.operation === "reversal") &&
        <label>決済会社の参照番号（任意）<input maxLength={191} value={reference} disabled={pending} onChange={event => { setReference(event.target.value); setPreview(null); }} /></label>}
      {isComplete && <label><input type="checkbox" checked={confirmed} disabled={pending} onChange={event => setConfirmed(event.target.checked)} />決済会社側で実際の返金が完了したことを確認しました</label>}
      {preview && <section aria-label="取消予定額"><h3>取消予定額</h3><Impact impact={preview} /><p>実行時の残高で再計算されます。記録後の結果を確認してください。</p></section>}
      {action.operation === "reversal" && <p>ポイントは自動復元されません。</p>}
      {error && <p role="alert">{error}</p>}
      <button type="submit" disabled={pending || !reason.trim() || (isComplete && !confirmed)}>{pending ? "処理中…" : action.operation === "chargeback" && !preview ? "取消予定額を確認" : "確認して記録"}</button>
      <button type="button" disabled={pending} onClick={close}>閉じる</button>
    </form>
  </dialog>;
}
