"use client";

import { FlaskConical, RotateCcw } from "lucide-react";
import { type FormEvent, useCallback, useEffect, useMemo, useState } from "react";

import { FreshMfaDialog } from "@/components/auth/fresh-mfa-dialog";
import { usePermissions } from "@/components/permissions/permission-provider";
import { AdminApiClient, AdminApiError } from "@/lib/admin-api/client";
import type {
  AdminQaTestUserMode,
  AdminUserDetail,
} from "@/lib/admin-api/generated";

const tokyoDateTime = new Intl.DateTimeFormat("ja-JP", {
  dateStyle: "medium",
  timeStyle: "short",
  timeZone: "Asia/Tokyo",
});

type PendingAction = "enable" | "disable" | "load" | null;

export function AdminUserQaTestMode({ user }: { user: AdminUserDetail }) {
  const client = useMemo(() => new AdminApiClient(), []);
  const { hasPermission } = usePermissions();
  const canManage = hasPermission("qa.draw.manage");
  const [mode, setMode] = useState<AdminQaTestUserMode | null>(null);
  const [reason, setReason] = useState("");
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
      const response = await client.getQaTestUserMode(user.id);
      setMode(response.mode);
      setReason(response.mode?.reason ?? "");
    } catch (cause) {
      if (cause instanceof AdminApiError && cause.requiresFreshMfa) {
        setError("テストユーザー設定の表示には再認証が必要です。");
      } else {
        setError(errorMessage(cause));
      }
    } finally {
      setLoading(false);
    }
  }, [client, user.id]);

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

  async function mutate(action: "enable" | "disable") {
    if (submitting) return;
    setSubmitting(true);
    setError(null);
    setNotice(null);
    try {
      if (action === "enable") {
        await client.saveQaTestUser(
          user.id,
          { reason: reason.trim(), revision: mode?.revision },
          crypto.randomUUID(),
        );
        setNotice("テストユーザーをONにしました。手動でOFFにするまで有効です。");
      } else if (mode) {
        await client.disableQaTestUser(
          user.id,
          mode.revision,
          crypto.randomUUID(),
        );
        setNotice("テストユーザーをOFFにしました。");
      }
      await load();
    } catch (cause) {
      setError(errorMessage(cause));
    } finally {
      setSubmitting(false);
    }
  }

  function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (!reason.trim()) {
      setError("設定理由を入力してください。");
      return;
    }
    requestFresh("enable");
  }

  return (
    <section className="admin-user-summary admin-user-qa-mode" aria-labelledby="user-qa-heading">
      <div className="admin-user-section-heading">
        <div>
          <h2 id="user-qa-heading">テストユーザー</h2>
          <p>ONの場合、Gachaごとの保証景品設定を通常抽選へ適用します。</p>
        </div>
        <span className={`admin-user-qa-status ${mode?.is_active ? "is-on" : "is-off"}`}>
          {mode?.is_active ? "ON" : "OFF"}
        </span>
      </div>
      {loading ? <p role="status">設定を読み込んでいます。</p> : null}
      {error ? (
        <div className="admin-user-qa-error" role="alert">
          <p>{error}</p>
          <button
            className="secondary-button"
            onClick={() => requestFresh("load")}
            type="button"
          >
            <RotateCcw aria-hidden="true" size={17} />再認証して再取得
          </button>
        </div>
      ) : null}
      {!loading && !error ? (
        <>
          <dl className="admin-user-state-summary">
            <div><dt>現在状態</dt><dd>{mode?.is_active ? "ON（無期限）" : "OFF"}</dd></div>
            <div><dt>最終更新</dt><dd>{mode?.updated_at ? tokyoDateTime.format(new Date(mode.updated_at)) : "未設定"}</dd></div>
            <div><dt>設定理由</dt><dd>{mode?.reason ?? "未設定"}</dd></div>
          </dl>
          <form className="admin-user-qa-form" onSubmit={submit}>
            <label>
              <span>設定理由</span>
              <textarea
                maxLength={500}
                onChange={(event) => setReason(event.target.value)}
                required
                rows={3}
                value={reason}
              />
            </label>
            <div className="admin-user-qa-actions">
              {mode?.is_active ? (
                <button
                  className="danger-button"
                  disabled={submitting}
                  onClick={() => requestFresh("disable")}
                  type="button"
                >
                  OFFにする
                </button>
              ) : null}
              <button
                className="primary-button"
                disabled={submitting || user.status !== "active" || !reason.trim()}
                type="submit"
              >
                <FlaskConical aria-hidden="true" size={17} />
                {mode?.is_active ? "理由を更新" : "ONにする"}
              </button>
            </div>
            {user.status !== "active" ? <p className="admin-user-qa-note">有効なUserだけをONにできます。</p> : null}
          </form>
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
          if (action === "enable" || action === "disable") await mutate(action);
        }}
        open={freshOpen}
      />
    </section>
  );
}

function errorMessage(cause: unknown): string {
  if (cause instanceof AdminApiError) {
    if (cause.code === "QA_REVISION_CONFLICT") return "別の更新が先に反映されました。再取得してください。";
    if (cause.code === "QA_CONFIGURATION_INVALID") return "入力またはUser状態を確認してください。";
  }
  return cause instanceof Error ? cause.message : "テストユーザー設定を更新できませんでした。";
}
