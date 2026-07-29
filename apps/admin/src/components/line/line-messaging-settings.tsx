"use client";

import {
  Eye,
  LoaderCircle,
  MessageSquareText,
  RotateCcw,
  Save,
} from "lucide-react";
import {
  type FormEvent,
  useCallback,
  useEffect,
  useMemo,
  useRef,
  useState,
} from "react";

import { FreshMfaDialog } from "@/components/auth/fresh-mfa-dialog";
import { Breadcrumb } from "@/components/navigation/breadcrumb";
import { ProtectedAdminRoute } from "@/components/permissions/protected-admin-route";
import { AdminPageHeader } from "@/components/shell/admin-page-header";
import { AdminShell } from "@/components/shell/admin-shell";
import { AdminApiClient, AdminApiError } from "@/lib/admin-api/client";
import type {
  AdminLineMessagingPreview,
  AdminLineMessagingSetting,
} from "@/lib/admin-api/generated";
import { navigationItem } from "@/lib/permissions/admin-navigation";

type Draft = Pick<
  AdminLineMessagingSetting,
  "linked_follow_message" | "pending_follow_message"
>;

export function LineMessagingSettings() {
  const client = useMemo(() => new AdminApiClient(), []);
  const navigation = navigationItem("line-settings");
  const [setting, setSetting] = useState<AdminLineMessagingSetting | null>(null);
  const [draft, setDraft] = useState<Draft | null>(null);
  const [preview, setPreview] = useState<AdminLineMessagingPreview | null>(null);
  const [error, setError] = useState<AdminApiError | null>(null);
  const [busy, setBusy] = useState<"load" | "preview" | "save" | null>("load");
  const [freshMfaOpen, setFreshMfaOpen] = useState(false);
  const pendingKey = useRef<string | null>(null);

  const dirty =
    setting !== null &&
    draft !== null &&
    (setting.linked_follow_message !== draft.linked_follow_message ||
      setting.pending_follow_message !== draft.pending_follow_message);

  const applySetting = useCallback((next: AdminLineMessagingSetting) => {
    setSetting(next);
    setDraft({
      linked_follow_message: next.linked_follow_message,
      pending_follow_message: next.pending_follow_message,
    });
    setPreview(null);
    pendingKey.current = null;
  }, []);

  const load = useCallback(async () => {
    setBusy("load");
    setError(null);
    try {
      const response = await client.getLineMessagingSetting();
      applySetting(response.data);
    } catch (caught) {
      setError(asApiError(caught));
    } finally {
      setBusy(null);
    }
  }, [applySetting, client]);

  useEffect(() => {
    const controller = new AbortController();
    client
      .getLineMessagingSetting(controller.signal)
      .then((response) => applySetting(response.data))
      .catch((caught: unknown) => {
        if (!controller.signal.aborted) setError(asApiError(caught));
      })
      .finally(() => {
        if (!controller.signal.aborted) setBusy(null);
      });
    return () => controller.abort();
  }, [applySetting, client]);
  useEffect(() => {
    if (!dirty) return;
    const guard = (event: BeforeUnloadEvent) => event.preventDefault();
    window.addEventListener("beforeunload", guard);
    return () => window.removeEventListener("beforeunload", guard);
  }, [dirty]);

  async function requestPreview() {
    if (!draft) return;
    setBusy("preview");
    setError(null);
    try {
      setPreview(await client.previewLineMessagingSetting(draft));
    } catch (caught) {
      setError(asApiError(caught));
    } finally {
      setBusy(null);
    }
  }

  async function save(event?: FormEvent<HTMLFormElement>) {
    event?.preventDefault();
    if (!draft || !setting || !dirty) return;
    setBusy("save");
    setError(null);
    pendingKey.current ??= crypto.randomUUID();
    try {
      const response = await client.updateLineMessagingSetting(
        {
          expected_revision: setting.revision,
          ...draft,
        },
        pendingKey.current,
      );
      applySetting(response.data);
    } catch (caught) {
      const next = asApiError(caught);
      setError(next);
      if (next.requiresFreshMfa) {
        setFreshMfaOpen(true);
      } else if (next.status !== 0) {
        pendingKey.current = null;
      }
    } finally {
      setBusy(null);
    }
  }

  return (
    <AdminShell>
      <ProtectedAdminRoute permission="identity.line.manage">
        <div className="workspace">
          <Breadcrumb item={navigation} />
          <AdminPageHeader
            action={<MessageSquareText size={26} aria-hidden="true" />}
            eyebrow="LINE Messaging"
            title="自動応答メッセージ"
          />
          {busy === "load" ? (
            <section className="module-state" role="status">
              <LoaderCircle className="spin" size={24} aria-hidden="true" />
              <h2>設定を読み込んでいます</h2>
            </section>
          ) : setting && draft ? (
            <form className="line-settings-form" onSubmit={save}>
              {error ? <LineSettingsError error={error} onReload={load} /> : null}
              <label>
                <span>ログイン済みユーザー向け</span>
                <textarea
                  maxLength={1000}
                  onChange={(event) =>
                    setDraft({ ...draft, linked_follow_message: event.target.value })
                  }
                  required
                  rows={6}
                  value={draft.linked_follow_message}
                />
              </label>
              <label>
                <span>ログイン前ユーザー向け</span>
                <textarea
                  maxLength={1000}
                  onChange={(event) =>
                    setDraft({ ...draft, pending_follow_message: event.target.value })
                  }
                  required
                  rows={6}
                  value={draft.pending_follow_message}
                />
              </label>
              <div className="line-settings-actions">
                <button
                  className="secondary-button"
                  disabled={busy !== null}
                  onClick={requestPreview}
                  type="button"
                >
                  <Eye size={17} aria-hidden="true" />
                  プレビュー
                </button>
                <button
                  className="primary-button"
                  disabled={busy !== null || !dirty}
                  type="submit"
                >
                  {busy === "save" ? (
                    <LoaderCircle className="spin" size={17} aria-hidden="true" />
                  ) : (
                    <Save size={17} aria-hidden="true" />
                  )}
                  保存
                </button>
              </div>
              {preview ? (
                <section aria-live="polite" className="line-message-preview">
                  <h2>プレビュー</h2>
                  <div>
                    <strong>ログイン済み</strong>
                    <p>{preview.linked_follow_message}</p>
                  </div>
                  <div>
                    <strong>ログイン前</strong>
                    <p>{preview.pending_follow_message}</p>
                  </div>
                </section>
              ) : null}
            </form>
          ) : (
            <section className="module-state" role="alert">
              <h2>設定を取得できませんでした</h2>
              <button className="secondary-button" onClick={load} type="button">
                <RotateCcw size={17} aria-hidden="true" />
                再読み込み
              </button>
            </section>
          )}
        </div>
        <FreshMfaDialog
          onClose={() => setFreshMfaOpen(false)}
          onSuccess={async () => {
            setFreshMfaOpen(false);
            await save();
          }}
          open={freshMfaOpen}
        />
      </ProtectedAdminRoute>
    </AdminShell>
  );
}

function LineSettingsError({
  error,
  onReload,
}: {
  error: AdminApiError;
  onReload: () => Promise<void>;
}) {
  const conflict = error.status === 409;
  return (
    <div aria-live="assertive" className="notice notice-error" role="alert">
      <p>
        {conflict
          ? "設定が更新されています。最新内容を再取得してください。"
          : error.message}
      </p>
      {conflict ? (
        <button className="secondary-button" onClick={() => void onReload()} type="button">
          <RotateCcw size={17} aria-hidden="true" />
          再読み込み
        </button>
      ) : null}
    </div>
  );
}

function asApiError(value: unknown): AdminApiError {
  return value instanceof AdminApiError
    ? value
    : new AdminApiError(0, "NETWORK_ERROR", null, null, true);
}
