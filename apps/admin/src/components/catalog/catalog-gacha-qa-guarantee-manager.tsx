"use client";

import { FlaskConical, RotateCcw, Trash2 } from "lucide-react";
import { type FormEvent, useCallback, useEffect, useMemo, useState } from "react";

import { FreshMfaDialog } from "@/components/auth/fresh-mfa-dialog";
import { usePermissions } from "@/components/permissions/permission-provider";
import { AdminApiClient, AdminApiError } from "@/lib/admin-api/client";
import type {
  AdminQaGachaGuaranteeAssignment,
  AdminQaGachaGuaranteeCollection,
} from "@/lib/admin-api/generated";

type PendingAction = "load" | "save" | { assignment: AdminQaGachaGuaranteeAssignment } | null;

export function CatalogGachaQaGuaranteeManager({ gachaId }: { gachaId: string }) {
  const client = useMemo(() => new AdminApiClient(), []);
  const { hasPermission } = usePermissions();
  const canManage = hasPermission("qa.draw.manage");
  const [data, setData] = useState<AdminQaGachaGuaranteeCollection | null>(null);
  const [userId, setUserId] = useState("");
  const [prizeId, setPrizeId] = useState("");
  const [loading, setLoading] = useState(true);
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [notice, setNotice] = useState<string | null>(null);
  const [pending, setPending] = useState<PendingAction>(null);
  const [freshOpen, setFreshOpen] = useState(false);

  const load = useCallback(async () => {
    setLoading(true);
    setError(null);
    try {
      const response = await client.getQaGachaGuarantees(gachaId);
      setData(response);
      setUserId((current) => current || response.test_users[0]?.id || "");
      setPrizeId((current) => current || response.prizes[0]?.id || "");
    } catch (cause) {
      if (cause instanceof AdminApiError && cause.requiresFreshMfa) {
        setError("テストユーザー設定の表示には再認証が必要です。");
      } else {
        setError(errorMessage(cause));
      }
    } finally {
      setLoading(false);
    }
  }, [client, gachaId]);

  useEffect(() => {
    if (!canManage) return;
    const timeout = window.setTimeout(() => void load(), 0);
    return () => window.clearTimeout(timeout);
  }, [canManage, load]);

  if (!canManage) return null;

  function requestFresh(action: Exclude<PendingAction, null>) {
    setPending(action);
    setFreshOpen(true);
  }

  async function save() {
    if (!data || !userId || !prizeId || submitting) return;
    setSubmitting(true);
    setError(null);
    setNotice(null);
    try {
      const current = data.items.find((item) => item.user.id === userId);
      await client.saveQaGachaGuarantee(
        gachaId,
        { prize_id: prizeId, revision: current?.revision, user_id: userId },
        crypto.randomUUID(),
      );
      setNotice("テストユーザーの保証景品を保存しました。");
      await load();
    } catch (cause) {
      setError(errorMessage(cause));
    } finally {
      setSubmitting(false);
    }
  }

  async function disable(assignment: AdminQaGachaGuaranteeAssignment) {
    if (submitting) return;
    setSubmitting(true);
    setError(null);
    setNotice(null);
    try {
      await client.disableQaGachaGuarantee(
        gachaId,
        assignment.user.id,
        assignment.revision,
        crypto.randomUUID(),
      );
      setNotice("テストユーザー設定を解除しました。");
      await load();
    } catch (cause) {
      setError(errorMessage(cause));
    } finally {
      setSubmitting(false);
    }
  }

  function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    requestFresh("save");
  }

  const active = data?.items.filter((item) => item.status === "assigned") ?? [];

  return (
    <section className="catalog-rank-prize-section catalog-gacha-qa-guarantees" aria-labelledby="gacha-qa-heading">
      <header>
        <div>
          <span className="eyebrow">QA抽選</span>
          <h2 id="gacha-qa-heading">テストユーザー設定</h2>
          <p>通常抽選の先頭1件として保証する景品をUserごとに設定します。</p>
        </div>
      </header>
      {loading ? <p role="status">テストユーザー設定を読み込んでいます。</p> : null}
      {error ? (
        <div className="catalog-gacha-qa-error" role="alert">
          <p>{error}</p>
          <button className="secondary-button" onClick={() => requestFresh("load")} type="button">
            <RotateCcw aria-hidden="true" size={17} />再認証して再取得
          </button>
        </div>
      ) : null}
      {!loading && !error && data ? (
        <>
          {active.length === 0 ? (
            <p className="catalog-version-empty">設定済みのテストユーザーはありません。</p>
          ) : (
            <div className="catalog-table-wrap" tabIndex={0}>
              <table className="catalog-table">
                <thead><tr><th>テストユーザー</th><th>保証する景品</th><th>状態</th><th>操作</th></tr></thead>
                <tbody>{active.map((item) => (
                  <tr key={item.id}>
                    <td>{item.user.display_name ?? "未設定"}<br /><code>{item.user.id}</code></td>
                    <td>{item.prize.rank_name ? `${item.prize.rank_name} ` : ""}{item.prize.name}</td>
                    <td>{item.is_resolvable ? "利用可能" : "公開中景品と不整合"}</td>
                    <td>
                      <button
                        aria-label={`${item.user.display_name ?? "未設定"}の設定を解除`}
                        className="icon-button"
                        disabled={submitting}
                        onClick={() => requestFresh({ assignment: item })}
                        title="解除"
                        type="button"
                      ><Trash2 aria-hidden="true" size={17} /></button>
                    </td>
                  </tr>
                ))}</tbody>
              </table>
            </div>
          )}
          <form className="catalog-gacha-qa-form" onSubmit={submit}>
            <label>
              <span>テストユーザー</span>
              <select onChange={(event) => setUserId(event.target.value)} required value={userId}>
                <option value="">選択してください</option>
                {data.test_users.map((user) => (
                  <option key={user.id} value={user.id}>{user.display_name ?? "未設定"} / {user.id}</option>
                ))}
              </select>
            </label>
            <label>
              <span>保証する景品</span>
              <select onChange={(event) => setPrizeId(event.target.value)} required value={prizeId}>
                <option value="">選択してください</option>
                {data.prizes.map((prize) => (
                  <option key={prize.id} value={prize.id}>{prize.rank_name ? `${prize.rank_name} / ` : ""}{prize.name}</option>
                ))}
              </select>
            </label>
            <button className="primary-button" disabled={submitting || !userId || !prizeId} type="submit">
              <FlaskConical aria-hidden="true" size={17} />追加・更新
            </button>
          </form>
          {data.test_users.length === 0 ? <p className="catalog-gacha-qa-note">User管理でActive UserをテストユーザーONにしてください。</p> : null}
          {data.prizes.length === 0 ? <p className="catalog-gacha-qa-note">現在公開中で在庫のある抽選対象景品がありません。</p> : null}
          {notice ? <p className="admin-user-adjustment-success" role="status">{notice}</p> : null}
        </>
      ) : null}
      <FreshMfaDialog
        onClose={() => {
          setFreshOpen(false);
          setPending(null);
        }}
        onSuccess={async () => {
          const action = pending;
          setFreshOpen(false);
          setPending(null);
          if (action === "load") await load();
          if (action === "save") await save();
          if (action && typeof action === "object") await disable(action.assignment);
        }}
        open={freshOpen}
      />
    </section>
  );
}

function errorMessage(cause: unknown): string {
  if (cause instanceof AdminApiError) {
    if (cause.code === "QA_ACTIVE_PLAN_CONFLICT") return "同じUserとGachaに旧QA Planが設定されています。";
    if (cause.code === "QA_REVISION_CONFLICT") return "別の更新が先に反映されました。再取得してください。";
    if (cause.code === "QA_CONFIGURATION_INVALID") return "User、景品、公開中Versionまたは在庫を確認してください。";
  }
  return cause instanceof Error ? cause.message : "テストユーザー設定を更新できませんでした。";
}
