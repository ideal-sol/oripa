"use client";

import { ArrowLeft, LoaderCircle, RefreshCw } from "lucide-react";
import Link from "next/link";
import { type ReactNode, useEffect, useMemo, useState } from "react";

import { PublicAssetPreview } from "@/components/catalog/public-asset-preview";
import { AdminPageHeader } from "@/components/shell/admin-page-header";
import { AdminApiClient } from "@/lib/admin-api/client";
import type {
  AdminUserPrizeActionState,
  AdminUserPrizeDetail as AdminUserPrizeDetailModel,
} from "@/lib/admin-api/generated";
import {
  formatJst,
  fulfillmentLabel,
  StatusBadge,
  statusLabel,
  userPrizeError,
} from "@/components/user-prizes/admin-user-prize-list";

const number = new Intl.NumberFormat("ja-JP");

export function AdminUserPrizeDetail({ userPrizeId }: { userPrizeId: string }) {
  const client = useMemo(() => new AdminApiClient(), []);
  const [data, setData] = useState<AdminUserPrizeDetailModel | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [reload, setReload] = useState(0);

  useEffect(() => {
    const controller = new AbortController();
    setLoading(true);
    setError(null);
    client.getAdminUserPrize(userPrizeId, controller.signal)
      .then((response) => setData(response.data))
      .catch((reason: unknown) => {
        if (!controller.signal.aborted) setError(userPrizeError(reason));
      })
      .finally(() => {
        if (!controller.signal.aborted) setLoading(false);
      });
    return () => controller.abort();
  }, [client, reload, userPrizeId]);

  if (loading) {
    return (
      <main className="workspace">
        <section aria-live="polite" className="module-state">
          <LoaderCircle aria-hidden="true" className="spin" size={22} />
          <p>保有景品詳細を読み込んでいます。</p>
        </section>
      </main>
    );
  }
  if (!data) {
    return (
      <main className="workspace">
        <section className="module-state is-error" role="alert">
          <h1>保有景品を表示できません</h1>
          <p>{error ?? "対象データが見つかりません。"}</p>
          <div className="module-state-actions">
            <button className="secondary-button" onClick={() => setReload((value) => value + 1)} type="button">
              <RefreshCw aria-hidden="true" size={16} />再取得
            </button>
            <Link className="secondary-button" href="/user-prizes">一覧へ戻る</Link>
          </div>
        </section>
      </main>
    );
  }

  return (
    <main className="workspace admin-user-prize-workspace">
      <AdminPageHeader
        eyebrow="Prize ownership"
        title="保有景品詳細"
        description="取得時Snapshotと現在のFulfillment状態を表示しています。"
        action={(
          <Link className="secondary-button" href="/user-prizes">
            <ArrowLeft aria-hidden="true" size={16} />一覧へ戻る
          </Link>
        )}
      />
      <div className="admin-user-prize-detail-stack">
        <section aria-labelledby="prize-overview-heading" className="admin-user-prize-detail-section">
          <div className="admin-user-prize-detail-heading">
            <div>
              <h2 id="prize-overview-heading">景品情報</h2>
              <p>抽選結果に保存された取得時点の表示Snapshotです。</p>
            </div>
            <StatusBadge status={data.status} />
          </div>
          <div className="admin-user-prize-overview">
            <div className="admin-user-prize-detail-image"><PublicAssetPreview asset={data.prize.image} /></div>
            <dl className="admin-user-prize-definition-grid">
              <Definition label="保有景品ID"><code>{data.id}</code></Definition>
              <Definition label="景品名">{data.prize.name}</Definition>
              <Definition label="景品Public ID"><code>{data.prize.id}</code></Definition>
              <Definition label="ランク">{data.prize.rank.name} ({data.prize.rank.code})</Definition>
              <Definition label="取得時交換ポイント">{number.format(data.exchange_points)} pt</Definition>
              <Definition label="現在状態"><StatusBadge status={data.status} /></Definition>
              <Definition label="取得日時">{formatJst(data.acquired_at)}</Definition>
              <Definition label="保管期限">{formatJst(data.storage_expires_at)}</Definition>
              <Definition label="状態更新日時">{formatJst(data.status_updated_at)}</Definition>
              <Definition label="終了日時">{formatJst(data.terminal_at)}</Definition>
            </dl>
          </div>
        </section>

        <section aria-labelledby="owner-source-heading" className="admin-user-prize-detail-section">
          <div className="admin-user-prize-detail-heading">
            <div><h2 id="owner-source-heading">User／取得元</h2><p>Canonical Draw RequestとGachaの識別情報です。</p></div>
          </div>
          <dl className="admin-user-prize-definition-grid">
            <Definition label="User">
              <Link href={`/users/${data.user.id}`}>{data.user.display_name ?? "未設定"}</Link>
            </Definition>
            <Definition label="User Public ID"><code>{data.user.id}</code></Definition>
            <Definition label="Gacha">{data.gacha.title}</Definition>
            <Definition label="Gacha公開ID"><code>{data.gacha.id}</code></Definition>
            <Definition label="Gacha Version ID"><code>{data.gacha.version_id}</code></Definition>
            <Definition label="Draw Request ID"><code>{data.draw.request_id}</code></Definition>
            <Definition label="Draw Result ID"><code>{data.draw.result_id}</code></Definition>
            <Definition label="抽選回数">要求 {number.format(data.draw.requested_count)}／実行 {number.format(data.draw.executed_count)}</Definition>
            <Definition label="消費ポイント">{number.format(data.draw.consumed_points)} pt</Definition>
            <Definition label="抽選完了日時">{formatJst(data.draw.completed_at)}</Definition>
          </dl>
        </section>

        <section aria-labelledby="allowed-actions-heading" className="admin-user-prize-detail-section">
          <div className="admin-user-prize-detail-heading">
            <div><h2 id="allowed-actions-heading">現在可能な操作</h2><p>既存Fulfillment Domainの判定結果です。この画面から操作は実行できません。</p></div>
          </div>
          <div className="admin-user-prize-action-grid">
            <ActionState label="配送" state={data.allowed_actions.shipping} />
            <ActionState label="ポイント交換" state={data.allowed_actions.point_exchange} />
            <ActionState label="選択" state={data.allowed_actions.selection} />
          </div>
        </section>

        <section aria-labelledby="fulfillment-heading" className="admin-user-prize-detail-section">
          <div className="admin-user-prize-detail-heading">
            <div><h2 id="fulfillment-heading">配送／ポイント交換</h2><p>現在の申請と処理状態です。</p></div>
          </div>
          <div className="admin-user-prize-fulfillment-grid">
            <FulfillmentPanel title="配送">
              {data.shipping ? (
                <dl>
                  <Definition label="配送依頼ID"><code>{data.shipping.id}</code></Definition>
                  <Definition label="状態">{fulfillmentLabel(data.shipping.status)}</Definition>
                  <Definition label="依頼日時">{formatJst(data.shipping.requested_at)}</Definition>
                  <Definition label="発送日時">{formatJst(data.shipping.shipped_at)}</Definition>
                  <Definition label="配送会社">{data.shipping.carrier_code ?? "未設定"}</Definition>
                  <Definition label="追跡番号">{data.shipping.tracking_number ?? "未設定"}</Definition>
                  <Definition label="配送先">
                    <Address address={data.shipping.shipping_address} />
                  </Definition>
                </dl>
              ) : <p className="muted-text">配送依頼はありません。</p>}
            </FulfillmentPanel>
            <FulfillmentPanel title="ポイント交換">
              {data.point_exchange ? (
                <dl>
                  <Definition label="交換依頼ID"><code>{data.point_exchange.id}</code></Definition>
                  <Definition label="状態">{fulfillmentLabel(data.point_exchange.status)}</Definition>
                  <Definition label="交換ポイント">{number.format(data.point_exchange.exchange_points)} pt</Definition>
                  <Definition label="完了日時">{formatJst(data.point_exchange.completed_at)}</Definition>
                </dl>
              ) : <p className="muted-text">ポイント交換依頼はありません。</p>}
            </FulfillmentPanel>
          </div>
        </section>

        <section aria-labelledby="status-history-heading" className="admin-user-prize-detail-section">
          <div className="admin-user-prize-detail-heading">
            <div><h2 id="status-history-heading">状態履歴</h2><p>保有景品のCanonical状態遷移です。</p></div>
          </div>
          {data.status_history.length === 0 ? <p className="muted-text">状態履歴はありません。</p> : (
            <div className="admin-user-prize-table-region" tabIndex={0}>
              <table className="admin-user-prize-history-table">
                <thead><tr><th scope="col">変更前</th><th scope="col">変更後</th><th scope="col">理由</th><th scope="col">日時</th></tr></thead>
                <tbody>{data.status_history.map((history, index) => (
                  <tr key={`${history.occurred_at}-${index}`}>
                    <td>{history.from_status ? statusLabel(history.from_status) : "初期状態"}</td>
                    <td>{statusLabel(history.to_status)}</td>
                    <td><code>{history.reason_code}</code></td>
                    <td>{formatJst(history.occurred_at)}</td>
                  </tr>
                ))}</tbody>
              </table>
            </div>
          )}
        </section>
      </div>
    </main>
  );
}

function Definition({ children, label }: { children: ReactNode; label: string }) {
  return <div><dt>{label}</dt><dd>{children}</dd></div>;
}

function ActionState({ label, state }: { label: string; state: AdminUserPrizeActionState }) {
  return (
    <div className={state.allowed ? "is-allowed" : "is-unavailable"}>
      <span>{label}</span>
      <strong>{state.allowed ? "可能" : "不可"}</strong>
      {!state.allowed ? <small>{unavailableReason(state.unavailable_reason)}</small> : null}
    </div>
  );
}

function unavailableReason(reason: AdminUserPrizeActionState["unavailable_reason"]): string {
  const labels = {
    exchange_points_unavailable: "交換ポイントが設定されていません",
    payment_hold: "決済保留中です",
    status_not_actionable: "現在状態では操作できません",
    storage_expired: "保管期限を過ぎています",
  } as const;
  return reason ? labels[reason] : "理由なし";
}

function FulfillmentPanel({ children, title }: { children: ReactNode; title: string }) {
  return <section><h3>{title}</h3>{children}</section>;
}

function Address({ address }: { address: AdminUserPrizeDetailModel["shipping"] extends infer T ? NonNullable<T> extends { shipping_address: infer A } ? A : never : never }) {
  return (
    <address>
      〒{address.postal_code} {address.prefecture}{address.city}{address.street}{address.building ?? ""}<br />
      {address.recipient_name}／{address.phone_number}
    </address>
  );
}
