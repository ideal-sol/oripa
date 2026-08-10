"use client";

import { Check, Pencil, Plus, RotateCcw, Tags, X } from "lucide-react";
import {
  type FormEvent,
  type KeyboardEvent,
  useCallback,
  useEffect,
  useMemo,
  useRef,
  useState,
} from "react";

import { FreshMfaDialog } from "@/components/auth/fresh-mfa-dialog";
import { usePermissions } from "@/components/permissions/permission-provider";
import { AdminPageHeader } from "@/components/shell/admin-page-header";
import { AdminApiClient, AdminApiError } from "@/lib/admin-api/client";
import type {
  AdminUserDetail,
  AdminUserTag,
} from "@/lib/admin-api/generated";

const tokyoDateTime = new Intl.DateTimeFormat("ja-JP", {
  dateStyle: "medium",
  timeStyle: "short",
  timeZone: "Asia/Tokyo",
});

export function AdminUserTagManagement() {
  const client = useMemo(() => new AdminApiClient(), []);
  const { hasPermission } = usePermissions();
  const canManage = hasPermission("user.tag.manage");
  const [tags, setTags] = useState<AdminUserTag[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [name, setName] = useState("");
  const [active, setActive] = useState(true);
  const [editing, setEditing] = useState<AdminUserTag | null>(null);
  const [pending, setPending] = useState<"create" | "update" | null>(null);
  const [submitting, setSubmitting] = useState(false);
  const [notice, setNotice] = useState<string | null>(null);
  const editPanelRef = useRef<HTMLElement>(null);
  const editReturnFocus = useRef<HTMLElement | null>(null);

  const load = useCallback(async () => {
    setLoading(true);
    setError(null);
    try {
      const response = await client.listUserTags();
      setTags(response.items);
    } catch (reason) {
      setError(errorMessage(reason));
    } finally {
      setLoading(false);
    }
  }, [client]);

  useEffect(() => {
    const controller = new AbortController();
    void client.listUserTags(undefined, controller.signal)
      .then((response) => setTags(response.items))
      .catch((reason: unknown) => {
        if (!controller.signal.aborted) setError(errorMessage(reason));
      })
      .finally(() => {
        if (!controller.signal.aborted) setLoading(false);
      });

    return () => controller.abort();
  }, [client]);

  useEffect(() => {
    if (!editing) return;
    editReturnFocus.current = document.activeElement as HTMLElement | null;
    window.setTimeout(() => focusFirst(editPanelRef.current), 0);
    return () => editReturnFocus.current?.focus();
  }, [editing]);

  async function mutate(kind: "create" | "update") {
    setSubmitting(true);
    setError(null);
    try {
      if (kind === "create") {
        await client.createUserTag(
          { name: name.trim(), is_active: active },
          crypto.randomUUID(),
        );
        setName("");
        setActive(true);
        setNotice("会員タグを作成しました。");
      } else if (editing) {
        await client.updateUserTag(
          editing.id,
          { name: name.trim(), is_active: active, expected_revision: editing.revision },
          crypto.randomUUID(),
        );
        setEditing(null);
        setNotice("会員タグを更新しました。");
      }
      await load();
    } catch (reason) {
      setError(errorMessage(reason));
    } finally {
      setSubmitting(false);
    }
  }

  function submit(event: FormEvent<HTMLFormElement>, kind: "create" | "update") {
    event.preventDefault();
    if (!name.trim() || submitting) return;
    setPending(kind);
  }

  function beginEdit(tag: AdminUserTag) {
    setEditing(tag);
    setName(tag.name);
    setActive(tag.is_active);
    setNotice(null);
  }

  function closeEdit() {
    setEditing(null);
    setName("");
    setActive(true);
  }

  return (
    <section className="workspace user-tag-workspace">
      <AdminPageHeader
        eyebrow="Users"
        title="会員タグ管理"
        description="会員管理で使用するタグを作成し、有効状態を管理します。"
      />
      {notice ? <p className="user-tag-notice" role="status">{notice}</p> : null}
      {error ? <UserTagState error message={error} retry={() => void load()} /> : null}
      {canManage ? (
        <form className="user-tag-create-form" onSubmit={(event) => submit(event, "create")}>
          <label>
            <span>タグ名</span>
            <input
              maxLength={100}
              onChange={(event) => setName(event.target.value)}
              placeholder="例: VIP"
              required
              type="text"
              value={editing ? "" : name}
            />
          </label>
          <label className="user-tag-toggle">
            <input
              checked={editing ? true : active}
              disabled={editing !== null}
              onChange={(event) => setActive(event.target.checked)}
              type="checkbox"
            />
            <span>有効として作成</span>
          </label>
          <button className="primary-button" disabled={submitting || editing !== null} type="submit">
            <Plus aria-hidden="true" size={17} />作成
          </button>
        </form>
      ) : null}
      {loading ? <UserTagState message="会員タグを読み込んでいます。" /> : null}
      {!loading && !error && tags.length === 0 ? (
        <UserTagState message="会員タグはまだ登録されていません。" />
      ) : null}
      {!loading && tags.length > 0 ? (
        <div className="user-tag-table-region" tabIndex={0}>
          <table className="user-tag-table">
            <thead><tr>
              <th scope="col">タグ名</th>
              <th scope="col">状態</th>
              <th scope="col">更新日</th>
              <th scope="col">編集</th>
            </tr></thead>
            <tbody>{tags.map((tag) => (
              <tr key={tag.id}>
                <td>{tag.name}</td>
                <td><TagStatus active={tag.is_active} /></td>
                <td>{tokyoDateTime.format(new Date(tag.updated_at))}</td>
                <td>{canManage ? (
                  <button
                    aria-label={`${tag.name}を編集`}
                    className="icon-button"
                    onClick={() => beginEdit(tag)}
                    title="編集"
                    type="button"
                  ><Pencil aria-hidden="true" size={17} /></button>
                ) : "閲覧のみ"}</td>
              </tr>
            ))}</tbody>
          </table>
        </div>
      ) : null}
      {editing ? (
        <div
          className="dialog-backdrop"
          onKeyDown={(event) => handleDialogKey(event, editPanelRef.current, closeEdit)}
          role="presentation"
        >
          <section
            aria-labelledby="user-tag-edit-title"
            aria-modal="true"
            className="dialog-panel"
            ref={editPanelRef}
            role="dialog"
          >
            <header className="dialog-header">
              <div><span className="eyebrow">User tag</span><h2 id="user-tag-edit-title">会員タグ編集</h2></div>
              <button aria-label="編集を閉じる" className="icon-button" onClick={closeEdit} type="button">
                <X aria-hidden="true" size={18} />
              </button>
            </header>
            <form className="user-tag-edit-form" onSubmit={(event) => submit(event, "update")}>
              <label><span>タグ名</span><input maxLength={100} onChange={(event) => setName(event.target.value)} required type="text" value={name} /></label>
              <label className="user-tag-toggle"><input checked={active} onChange={(event) => setActive(event.target.checked)} type="checkbox" /><span>有効</span></label>
              <div className="dialog-actions">
                <button className="secondary-button" onClick={closeEdit} type="button">キャンセル</button>
                <button className="primary-button" disabled={submitting} type="submit">更新</button>
              </div>
            </form>
          </section>
        </div>
      ) : null}
      <FreshMfaDialog
        onClose={() => setPending(null)}
        onSuccess={async () => {
          const operation = pending;
          setPending(null);
          if (operation) await mutate(operation);
        }}
        open={pending !== null}
      />
    </section>
  );
}

export function AdminUserTagSection({
  onRefresh,
  user,
}: {
  onRefresh: () => void;
  user: AdminUserDetail;
}) {
  const client = useMemo(() => new AdminApiClient(), []);
  const { hasPermission } = usePermissions();
  const canManage = hasPermission("user.tag.manage");
  const [open, setOpen] = useState(false);
  const [available, setAvailable] = useState<AdminUserTag[]>([]);
  const [error, setError] = useState<string | null>(null);
  const [pending, setPending] = useState<{ kind: "assign" | "detach"; tag: AdminUserTag } | null>(null);
  const managerPanelRef = useRef<HTMLElement>(null);
  const managerReturnFocus = useRef<HTMLElement | null>(null);
  const userTags = user.tags ?? [];
  const assigned = new Set(userTags.map((tag) => tag.id));

  useEffect(() => {
    if (!open) return;
    managerReturnFocus.current = document.activeElement as HTMLElement | null;
    window.setTimeout(() => focusFirst(managerPanelRef.current), 0);
    return () => managerReturnFocus.current?.focus();
  }, [open]);

  async function openManager() {
    setError(null);
    try {
      setAvailable((await client.listUserTags()).items);
      setOpen(true);
    } catch (reason) {
      setError(errorMessage(reason));
    }
  }

  async function change() {
    if (!pending) return;
    setError(null);
    try {
      const input = { expected_revision: user.tag_assignment_revision ?? 1 };
      if (pending.kind === "assign") {
        await client.assignUserTag(user.id, pending.tag.id, input, crypto.randomUUID());
      } else {
        await client.detachUserTag(user.id, pending.tag.id, input, crypto.randomUUID());
      }
      setPending(null);
      setOpen(false);
      onRefresh();
    } catch (reason) {
      setPending(null);
      setError(errorMessage(reason));
    }
  }

  return (
    <section className="admin-user-summary" aria-labelledby="user-tags-heading">
      <div className="admin-user-section-heading">
        <div><h2 id="user-tags-heading">会員タグ</h2><p>このユーザーへ現在付与されている管理タグです。</p></div>
        {canManage ? (
          <button className="secondary-button" onClick={() => void openManager()} type="button">
            <Tags aria-hidden="true" size={17} />タグを管理
          </button>
        ) : null}
      </div>
      {error ? <p className="user-tag-error" role="alert">{error}</p> : null}
      {userTags.length ? (
        <ul className="user-tag-chip-list">{userTags.map((tag) => (
          <li className={tag.is_active ? "" : "is-inactive"} key={tag.id}>
            {tag.name}{tag.is_active ? "" : "（無効）"}
          </li>
        ))}</ul>
      ) : <UserTagState message="付与されている会員タグはありません。" />}
      {open ? (
        <div
          className="dialog-backdrop"
          onKeyDown={(event) => handleDialogKey(event, managerPanelRef.current, () => setOpen(false))}
          role="presentation"
        >
          <section
            aria-labelledby="user-tag-assign-title"
            aria-modal="true"
            className="dialog-panel user-tag-assignment-dialog"
            ref={managerPanelRef}
            role="dialog"
          >
            <header className="dialog-header">
              <div><span className="eyebrow">User tags</span><h2 id="user-tag-assign-title">会員タグを管理</h2></div>
              <button aria-label="タグ管理を閉じる" className="icon-button" onClick={() => setOpen(false)} type="button"><X aria-hidden="true" size={18} /></button>
            </header>
            <ul className="user-tag-assignment-list">{available.map((tag) => {
              const isAssigned = assigned.has(tag.id);
              return <li key={tag.id}>
                <span><strong>{tag.name}</strong><small>{tag.is_active ? "有効" : "無効"}</small></span>
                {isAssigned ? (
                  <button className="secondary-button" onClick={() => setPending({ kind: "detach", tag })} type="button">解除</button>
                ) : (
                  <button className="primary-button" disabled={!tag.is_active} onClick={() => setPending({ kind: "assign", tag })} type="button">
                    <Check aria-hidden="true" size={16} />{tag.is_active ? "付与" : "付与不可"}
                  </button>
                )}
              </li>;
            })}</ul>
          </section>
        </div>
      ) : null}
      <FreshMfaDialog onClose={() => setPending(null)} onSuccess={change} open={pending !== null} />
    </section>
  );
}

function TagStatus({ active }: { active: boolean }) {
  return <span className={`user-tag-status ${active ? "is-active" : "is-inactive"}`}>{active ? "有効" : "無効"}</span>;
}

function UserTagState({ error = false, message, retry }: { error?: boolean; message: string; retry?: () => void }) {
  return <div className={`user-tag-state${error ? " is-error" : ""}`} role={error ? "alert" : "status"}>
    <p>{message}</p>{retry ? <button className="secondary-button" onClick={retry} type="button"><RotateCcw aria-hidden="true" size={16} />再試行</button> : null}
  </div>;
}

function errorMessage(reason: unknown): string {
  if (reason instanceof AdminApiError) {
    if (reason.code.includes("REVISION_CONFLICT")) return "別の更新が先に反映されました。再読み込みしてください。";
    if (reason.code === "USER_TAG_NAME_CONFLICT") return "同じ名前の会員タグが既に存在します。";
    if (reason.code === "USER_TAG_INACTIVE") return "無効な会員タグは新規付与できません。";
  }
  return reason instanceof Error ? reason.message : "会員タグを処理できませんでした。";
}

function focusFirst(panel: HTMLElement | null): void {
  panel?.querySelector<HTMLElement>(
    'button:not(:disabled), input:not(:disabled), [href], [tabindex]:not([tabindex="-1"])',
  )?.focus();
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
    'button:not(:disabled), input:not(:disabled), [href], [tabindex]:not([tabindex="-1"])',
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
