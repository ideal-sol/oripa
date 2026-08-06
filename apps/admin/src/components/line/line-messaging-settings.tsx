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
import { usePermissions } from "@/components/permissions/permission-provider";
import { ProtectedAdminRoute } from "@/components/permissions/protected-admin-route";
import { AdminPageHeader } from "@/components/shell/admin-page-header";
import { AdminShell } from "@/components/shell/admin-shell";
import { AdminApiClient, AdminApiError } from "@/lib/admin-api/client";
import type {
  AdminLineMessagingPreview,
  AdminLineMessagingSetting,
} from "@/lib/admin-api/generated";
import { navigationItem } from "@/lib/permissions/admin-navigation";

type Draft = {
  friend_add_url: string;
  linked_follow_message: string;
  pending_follow_message: string;
  reward_enabled: boolean;
  reward_point_amount: number;
  reward_expiration_days: number;
};

const MAX_REWARD_POINT_AMOUNT = 1_000_000;

export function LineMessagingSettings() {
  const client = useMemo(() => new AdminApiClient(), []);
  const navigation = navigationItem("line-settings");
  const { permissions } = usePermissions();
  const canManage = permissions.has("identity.line.manage");
  const [setting, setSetting] = useState<AdminLineMessagingSetting | null>(null);
  const [draft, setDraft] = useState<Draft | null>(null);
  const [preview, setPreview] = useState<AdminLineMessagingPreview | null>(null);
  const [error, setError] = useState<AdminApiError | null>(null);
  const [busy, setBusy] = useState<"load" | "preview" | "save" | null>("load");
  const [freshMfaOpen, setFreshMfaOpen] = useState(false);
  const [saved, setSaved] = useState(false);
  const pendingKey = useRef<string | null>(null);

  const dirty =
    setting !== null &&
    draft !== null &&
    ((setting.friend_add_url ?? "") !== draft.friend_add_url ||
      setting.linked_follow_message !== draft.linked_follow_message ||
      setting.pending_follow_message !== draft.pending_follow_message ||
      setting.reward_enabled !== draft.reward_enabled ||
      setting.reward_point_amount !== draft.reward_point_amount ||
      setting.reward_expiration_days !== draft.reward_expiration_days);
  const rewardInvalid =
    draft !== null &&
    ((draft.reward_enabled &&
      (draft.reward_point_amount < 1 ||
        draft.reward_point_amount > MAX_REWARD_POINT_AMOUNT)) ||
      (!draft.reward_enabled && draft.reward_point_amount !== 0) ||
      draft.reward_expiration_days < 1 ||
      draft.reward_expiration_days > 3650);
  const friendAddUrlInvalid = draft !== null && !validFriendAddUrl(draft.friend_add_url);

  const applySetting = useCallback((next: AdminLineMessagingSetting) => {
    setSetting(next);
    setDraft({
      friend_add_url: next.friend_add_url ?? "",
      linked_follow_message: next.linked_follow_message,
      pending_follow_message: next.pending_follow_message,
      reward_enabled: next.reward_enabled ?? false,
      reward_point_amount: next.reward_point_amount ?? 0,
      reward_expiration_days: next.reward_expiration_days ?? 180,
    });
    setPreview(null);
    setSaved(false);
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
    if (!draft || rewardInvalid) return;
    setBusy("preview");
    setError(null);
    try {
      setPreview(await client.previewLineMessagingSetting({
        linked_follow_message: draft.linked_follow_message,
        pending_follow_message: draft.pending_follow_message,
        reward_enabled: draft.reward_enabled,
        reward_point_amount: draft.reward_point_amount,
        reward_expiration_days: draft.reward_expiration_days,
      }));
    } catch (caught) {
      setError(asApiError(caught));
    } finally {
      setBusy(null);
    }
  }

  async function save(event?: FormEvent<HTMLFormElement>) {
    event?.preventDefault();
    if (!canManage || !draft || !setting || !dirty || rewardInvalid || friendAddUrlInvalid) return;
    setBusy("save");
    setError(null);
    pendingKey.current ??= crypto.randomUUID();
    try {
      const response = await client.updateLineMessagingSetting(
        {
          expected_revision: setting.revision,
          ...draft,
          friend_add_url: draft.friend_add_url.trim() || null,
        },
        pendingKey.current,
      );
      applySetting(response.data);
      setSaved(true);
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
      <ProtectedAdminRoute permission="identity.line.read">
        <div className="workspace">
          <Breadcrumb item={navigation} />
          <AdminPageHeader
            action={<MessageSquareText size={26} aria-hidden="true" />}
            eyebrow="LINE Settings"
            title="LINE設定"
          />
          {busy === "load" ? (
            <section className="module-state" role="status">
              <LoaderCircle className="spin" size={24} aria-hidden="true" />
              <h2>設定を読み込んでいます</h2>
            </section>
          ) : setting && draft ? (
            <form className="line-settings-form" onSubmit={save}>
              {error ? <LineSettingsError error={error} onReload={load} /> : null}
              {saved ? (
                <p className="notice notice-success" role="status">
                  LINE設定を保存しました。
                </p>
              ) : null}
              <dl className="line-settings-summary">
                <div>
                  <dt>現在の友だち数</dt>
                  <dd>{(setting.friends_count ?? 0).toLocaleString("ja-JP")}人</dd>
                </div>
                <div>
                  <dt>現在のブロック数</dt>
                  <dd>{(setting.blocked_count ?? 0).toLocaleString("ja-JP")}人</dd>
                </div>
                <div>
                  <dt>Revision</dt>
                  <dd>{setting.revision}</dd>
                </div>
                <div>
                  <dt>更新日時</dt>
                  <dd>{formatJst(setting.updated_at)}</dd>
                </div>
              </dl>
              <label>
                <span>LINE友だち追加URL</span>
                <input
                  disabled={!canManage}
                  maxLength={2048}
                  onChange={(event) =>
                    setDraft({ ...draft, friend_add_url: event.target.value })
                  }
                  placeholder="https://line.me/R/ti/p/..."
                  type="url"
                  value={draft.friend_add_url}
                />
              </label>
              {friendAddUrlInvalid ? (
                <p className="field-error" role="alert">
                  有効なHTTPまたはHTTPS URLを入力してください。
                </p>
              ) : null}
              <label>
                <span>ログイン済みユーザー向け</span>
                <textarea
                  disabled={!canManage}
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
                  disabled={!canManage}
                  maxLength={1000}
                  onChange={(event) =>
                    setDraft({ ...draft, pending_follow_message: event.target.value })
                  }
                  required
                  rows={6}
                  value={draft.pending_follow_message}
                />
              </label>
              <fieldset className="line-reward-settings">
                <legend>友だち追加ポイント</legend>
                <label className="line-reward-toggle">
                  <input
                    checked={draft.reward_enabled}
                    disabled={!canManage}
                    onChange={(event) =>
                      setDraft({
                        ...draft,
                        reward_enabled: event.target.checked,
                        reward_point_amount: event.target.checked
                          ? draft.reward_point_amount
                          : 0,
                      })
                    }
                    type="checkbox"
                  />
                  <span>ポイント付与を有効にする</span>
                </label>
                <p className="line-reward-note">付与されるポイントは無償ポイントです。</p>
                <div className="line-reward-grid">
                  <label>
                    <span>付与ポイント数</span>
                    <input
                      disabled={!canManage || !draft.reward_enabled}
                      inputMode="numeric"
                      max={MAX_REWARD_POINT_AMOUNT}
                      min={draft.reward_enabled ? 1 : 0}
                      onChange={(event) =>
                        setDraft({
                          ...draft,
                          reward_point_amount: Number(event.target.value),
                        })
                      }
                      required={draft.reward_enabled}
                      type="number"
                      value={draft.reward_point_amount}
                    />
                  </label>
                  <label>
                    <span>有効期限日数</span>
                    <input
                      disabled={!canManage}
                      inputMode="numeric"
                      max={3650}
                      min={1}
                      onChange={(event) =>
                        setDraft({
                          ...draft,
                          reward_expiration_days: Number(event.target.value),
                        })
                      }
                      required
                      type="number"
                      value={draft.reward_expiration_days}
                    />
                  </label>
                </div>
                <p aria-live="polite" className="line-reward-status">
                  現在の設定: {draft.reward_enabled
                    ? `${draft.reward_point_amount.toLocaleString("ja-JP")} Point／${draft.reward_expiration_days}日`
                    : "無効"}
                </p>
                {rewardInvalid ? (
                  <p className="field-error" role="alert">
                    有効時は1～1,000,000 Point、期限は1～3,650日で指定してください。
                  </p>
                ) : null}
              </fieldset>
              {canManage ? <div className="line-settings-actions">
                <button
                  className="secondary-button"
                  disabled={busy !== null || rewardInvalid}
                  onClick={requestPreview}
                  type="button"
                >
                  <Eye size={17} aria-hidden="true" />
                  プレビュー
                </button>
                <button
                  className="primary-button"
                  disabled={busy !== null || !dirty || rewardInvalid || friendAddUrlInvalid}
                  type="submit"
                >
                  {busy === "save" ? (
                    <LoaderCircle className="spin" size={17} aria-hidden="true" />
                  ) : (
                    <Save size={17} aria-hidden="true" />
                  )}
                  保存
                </button>
              </div> : null}
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
                  <div>
                    <strong>友だち追加ポイント</strong>
                    <p>
                      {preview.reward_enabled
                        ? `無償 ${(preview.reward_point_amount ?? 0).toLocaleString("ja-JP")} Point／有効期限 ${preview.reward_expiration_days ?? 180}日`
                        : "無効"}
                    </p>
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

function validFriendAddUrl(value: string): boolean {
  const normalized = value.trim();
  if (normalized === "") return true;
  try {
    const url = new URL(normalized);
    return (
      (url.protocol === "http:" || url.protocol === "https:") &&
      normalized.length <= 2048
    );
  } catch {
    return false;
  }
}

function formatJst(value: string): string {
  return new Intl.DateTimeFormat("ja-JP", {
    dateStyle: "medium",
    timeStyle: "short",
    timeZone: "Asia/Tokyo",
  }).format(new Date(value));
}
