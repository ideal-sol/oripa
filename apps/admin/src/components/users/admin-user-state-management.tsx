"use client";

import { ShieldAlert, X } from "lucide-react";
import {
  type FormEvent,
  type KeyboardEvent,
  useEffect,
  useMemo,
  useRef,
  useState,
} from "react";

import { FreshMfaDialog } from "@/components/auth/fresh-mfa-dialog";
import { usePermissions } from "@/components/permissions/permission-provider";
import { AdminApiClient, AdminApiError } from "@/lib/admin-api/client";
import type { AdminUserDetail, AdminUserState } from "@/lib/admin-api/generated";

type MutableUserState = "active" | "suspended" | "closed";

const transitions: Partial<Record<AdminUserState, MutableUserState[]>> = {
  active: ["suspended", "closed"],
  suspended: ["active", "closed"],
};

export function AdminUserStateManagement({
  onRefresh,
  user,
}: {
  onRefresh: () => void;
  user: AdminUserDetail;
}) {
  const client = useMemo(() => new AdminApiClient(), []);
  const { hasPermission } = usePermissions();
  const canManage = hasPermission("user.state.manage");
  const options = transitions[user.status] ?? [];
  const [open, setOpen] = useState(false);
  const [nextState, setNextState] = useState<MutableUserState | "">("");
  const [reason, setReason] = useState("");
  const [error, setError] = useState<string | null>(null);
  const [submitting, setSubmitting] = useState(false);
  const [freshOpen, setFreshOpen] = useState(false);
  const panelRef = useRef<HTMLElement>(null);
  const selectRef = useRef<HTMLSelectElement>(null);
  const previousFocus = useRef<HTMLElement | null>(null);

  useEffect(() => {
    if (!open) return;
    previousFocus.current = document.activeElement as HTMLElement | null;
    window.setTimeout(() => selectRef.current?.focus(), 0);
    return () => previousFocus.current?.focus();
  }, [open]);

  function close() {
    if (submitting) return;
    setOpen(false);
    setNextState("");
    setReason("");
    setError(null);
  }

  async function mutate() {
    if (!nextState || !reason.trim() || submitting) return;
    setSubmitting(true);
    setError(null);
    try {
      await client.updateAdminUserState(
        user.id,
        {
          status: nextState,
          expected_revision: user.state_revision,
          reason: reason.trim(),
        },
        crypto.randomUUID(),
      );
      setOpen(false);
      setNextState("");
      setReason("");
      onRefresh();
    } catch (cause) {
      if (cause instanceof AdminApiError && cause.requiresFreshMfa) {
        setFreshOpen(true);
        setError("本人確認の有効期限が切れました。再認証後にもう一度実行してください。");
      } else {
        setError(errorMessage(cause));
      }
    } finally {
      setSubmitting(false);
    }
  }

  function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (!nextState) {
      setError("変更後の状態を選択してください。");
      return;
    }
    if (!reason.trim()) {
      setError("変更理由を入力してください。");
      return;
    }
    setFreshOpen(true);
  }

  return (
    <section className="admin-user-summary" aria-labelledby="user-state-heading">
      <div className="admin-user-section-heading">
        <div>
          <h2 id="user-state-heading">ユーザー状態</h2>
          <p>現在の利用状態です。停止・退会時は既存Sessionも失効します。</p>
        </div>
        {canManage && options.length > 0 ? (
          <button className="secondary-button" onClick={() => setOpen(true)} type="button">
            <ShieldAlert aria-hidden="true" size={17} />状態を変更
          </button>
        ) : null}
      </div>
      <dl className="admin-user-state-summary">
        <div><dt>現在状態</dt><dd>{stateLabel(user.status)}</dd></div>
        <div><dt>変更可否</dt><dd>{canManage && options.length > 0 ? "変更可能" : "閲覧のみ"}</dd></div>
      </dl>
      {open ? (
        <div
          className="dialog-backdrop"
          onKeyDown={(event) => handleDialogKey(event, panelRef.current, close)}
          role="presentation"
        >
          <section
            aria-labelledby="user-state-dialog-title"
            aria-modal="true"
            className="dialog-panel admin-user-state-dialog"
            ref={panelRef}
            role="dialog"
          >
            <header className="dialog-header">
              <div>
                <span className="eyebrow">User state</span>
                <h2 id="user-state-dialog-title">ユーザー状態を変更</h2>
              </div>
              <button aria-label="状態変更を閉じる" className="icon-button" onClick={close} type="button">
                <X aria-hidden="true" size={18} />
              </button>
            </header>
            <form className="admin-user-state-form" onSubmit={submit}>
              <dl className="admin-user-state-impact">
                <div><dt>対象ユーザー</dt><dd>{user.display_name ?? "未設定"}</dd></div>
                <div><dt>現在状態</dt><dd>{stateLabel(user.status)}</dd></div>
              </dl>
              <label>
                <span>変更後の状態</span>
                <select
                  onChange={(event) => setNextState(event.target.value as MutableUserState | "")}
                  ref={selectRef}
                  required
                  value={nextState}
                >
                  <option value="">選択してください</option>
                  {options.map((state) => <option key={state} value={state}>{stateLabel(state)}</option>)}
                </select>
              </label>
              <label>
                <span>変更理由</span>
                <textarea
                  maxLength={500}
                  onChange={(event) => setReason(event.target.value)}
                  required
                  rows={4}
                  value={reason}
                />
              </label>
              {error ? <p className="admin-user-state-error" role="alert">{error}</p> : null}
              <div className="dialog-actions">
                <button className="secondary-button" disabled={submitting} onClick={close} type="button">キャンセル</button>
                <button className="primary-button" disabled={submitting} type="submit">
                  {submitting ? "更新中" : "確認して変更"}
                </button>
              </div>
            </form>
          </section>
        </div>
      ) : null}
      <FreshMfaDialog
        onClose={() => setFreshOpen(false)}
        onSuccess={async () => {
          setFreshOpen(false);
          await mutate();
        }}
        open={freshOpen}
      />
    </section>
  );
}

function stateLabel(state: string): string {
  return {
    active: "有効",
    anonymized: "匿名化済み",
    closed: "退会",
    pending_verification: "確認待ち",
    restricted: "制限中",
    suspended: "停止",
  }[state] ?? state;
}

function errorMessage(cause: unknown): string {
  if (cause instanceof AdminApiError) {
    if (cause.code === "ADMIN_USER_STATE_REVISION_CONFLICT") return "別の更新が先に反映されました。最新情報を再取得してください。";
    if (cause.code === "ADMIN_USER_STATE_TRANSITION_INVALID") return "現在の状態から選択した状態へは変更できません。";
    if (cause.code === "ADMIN_USER_STATE_INVALID") return "入力内容を確認してください。";
  }
  return cause instanceof Error ? cause.message : "ユーザー状態を変更できませんでした。";
}

function handleDialogKey(
  event: KeyboardEvent<HTMLDivElement>,
  panel: HTMLElement | null,
  close: () => void,
): void {
  if (event.key === "Escape") {
    close();
    return;
  }
  if (event.key !== "Tab") return;
  const focusable = panel?.querySelectorAll<HTMLElement>(
    'button:not(:disabled), select:not(:disabled), textarea:not(:disabled), [href], [tabindex]:not([tabindex="-1"])',
  );
  if (!focusable?.length) return;
  const first = focusable[0];
  const last = focusable[focusable.length - 1];
  if (event.shiftKey && document.activeElement === first) {
    event.preventDefault();
    last.focus();
  } else if (!event.shiftKey && document.activeElement === last) {
    event.preventDefault();
    first.focus();
  }
}
