"use client";

import { Gift, LoaderCircle, RotateCcw, Save } from "lucide-react";
import { type FormEvent, useCallback, useEffect, useMemo, useRef, useState } from "react";

import { FreshMfaDialog } from "@/components/auth/fresh-mfa-dialog";
import { Breadcrumb } from "@/components/navigation/breadcrumb";
import { usePermissions } from "@/components/permissions/permission-provider";
import { ProtectedAdminRoute } from "@/components/permissions/protected-admin-route";
import { AdminPageHeader } from "@/components/shell/admin-page-header";
import { AdminShell } from "@/components/shell/admin-shell";
import { AdminApiClient, AdminApiError } from "@/lib/admin-api/client";
import type { AdminReferralPointSetting } from "@/lib/admin-api/generated";
import { navigationItem } from "@/lib/permissions/admin-navigation";

type Draft = Pick<
  AdminReferralPointSetting,
  | "is_enabled"
  | "referrer_point_amount"
  | "referred_user_point_amount"
  | "reward_expiration_days"
>;

const MAX_REWARD = 1_000_000;

export function ReferralPointSettings() {
  const client = useMemo(() => new AdminApiClient(), []);
  const navigation = navigationItem("referral-settings");
  const { permissions } = usePermissions();
  const canManage = permissions.has("referral.settings.manage");
  const [setting, setSetting] = useState<AdminReferralPointSetting | null>(null);
  const [draft, setDraft] = useState<Draft | null>(null);
  const [busy, setBusy] = useState<"load" | "save" | null>("load");
  const [error, setError] = useState<AdminApiError | null>(null);
  const [saved, setSaved] = useState(false);
  const [freshMfaOpen, setFreshMfaOpen] = useState(false);
  const pendingKey = useRef<string | null>(null);

  const apply = useCallback((next: AdminReferralPointSetting) => {
    setSetting(next);
    setDraft({
      is_enabled: next.is_enabled,
      referrer_point_amount: next.referrer_point_amount,
      referred_user_point_amount: next.referred_user_point_amount,
      reward_expiration_days: next.reward_expiration_days,
    });
    pendingKey.current = null;
  }, []);
  const load = useCallback(async () => {
    setBusy("load");
    setError(null);
    try {
      apply((await client.getReferralPointSetting()).data);
    } catch (caught) {
      setError(asApiError(caught));
    } finally {
      setBusy(null);
    }
  }, [apply, client]);

  useEffect(() => {
    const controller = new AbortController();
    client.getReferralPointSetting(controller.signal)
      .then((response) => apply(response.data))
      .catch((caught: unknown) => {
        if (!controller.signal.aborted) setError(asApiError(caught));
      })
      .finally(() => {
        if (!controller.signal.aborted) setBusy(null);
      });
    return () => controller.abort();
  }, [apply, client]);

  const dirty = setting !== null && draft !== null && (
    setting.is_enabled !== draft.is_enabled
    || setting.referrer_point_amount !== draft.referrer_point_amount
    || setting.referred_user_point_amount !== draft.referred_user_point_amount
    || setting.reward_expiration_days !== draft.reward_expiration_days
  );
  const invalid = draft !== null && (
    !Number.isInteger(draft.referrer_point_amount)
    || draft.referrer_point_amount < 0
    || draft.referrer_point_amount > MAX_REWARD
    || !Number.isInteger(draft.referred_user_point_amount)
    || draft.referred_user_point_amount < 0
    || draft.referred_user_point_amount > MAX_REWARD
    || !Number.isInteger(draft.reward_expiration_days)
    || draft.reward_expiration_days < 1
    || draft.reward_expiration_days > 3650
  );

  async function save(event?: FormEvent<HTMLFormElement>) {
    event?.preventDefault();
    if (!canManage || !setting || !draft || !dirty || invalid) return;
    setBusy("save");
    setError(null);
    setSaved(false);
    pendingKey.current ??= crypto.randomUUID();
    try {
      const response = await client.updateReferralPointSetting({
        expected_revision: setting.revision,
        ...draft,
      }, pendingKey.current);
      apply(response.data);
      setSaved(true);
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
      <ProtectedAdminRoute permission="referral.settings.read">
        <div className="workspace">
          <Breadcrumb item={navigation} />
          <AdminPageHeader
            action={<Gift aria-hidden="true" size={26} />}
            eyebrow="Point Settings"
            title="紹介ポイント設定"
          />
          {busy === "load" ? (
            <section className="module-state" role="status">
              <LoaderCircle aria-hidden="true" className="spin" size={24} />
              <h2>設定を読み込んでいます</h2>
            </section>
          ) : setting && draft ? (
            <form className="referral-settings-form" onSubmit={save}>
              {error ? <SettingsError error={error} onReload={load} /> : null}
              {saved ? <p className="notice notice-success" role="status">設定を保存しました。</p> : null}
              <section className="referral-settings-section">
                <div className="referral-settings-heading">
                  <div>
                    <h2>ポイント付与</h2>
                    <p>付与ポイントは無償ポイントとして記録されます。</p>
                  </div>
                  <label className="referral-settings-toggle">
                    <input
                      checked={draft.is_enabled}
                      disabled={!canManage}
                      onChange={(event) => setDraft({ ...draft, is_enabled: event.target.checked })}
                      type="checkbox"
                    />
                    <span>{draft.is_enabled ? "有効" : "無効"}</span>
                  </label>
                </div>
                <div className="referral-settings-grid">
                  <NumberField disabled={!canManage} label="紹介者へ付与するポイント" max={MAX_REWARD} min={0} onChange={(value) => setDraft({ ...draft, referrer_point_amount: value })} value={draft.referrer_point_amount} />
                  <NumberField disabled={!canManage} label="紹介されたユーザーへ付与するポイント" max={MAX_REWARD} min={0} onChange={(value) => setDraft({ ...draft, referred_user_point_amount: value })} value={draft.referred_user_point_amount} />
                  <NumberField disabled={!canManage} label="無償ポイント有効期限（日数）" max={3650} min={1} onChange={(value) => setDraft({ ...draft, reward_expiration_days: value })} value={draft.reward_expiration_days} />
                </div>
                {invalid ? <p className="field-error" role="alert">ポイントは0～1,000,000、期限は1～3,650日の整数で指定してください。</p> : null}
              </section>
              <section className="referral-settings-section">
                <h2>適用条件</h2>
                <dl className="referral-settings-summary">
                  <div><dt>付与条件</dt><dd>紹介されたユーザーのSMS認証完了</dd></div>
                  <div><dt>付与タイミング</dt><dd>SMS認証完了時</dd></div>
                  <div><dt>設定の適用範囲</dt><dd>変更後に成立する紹介</dd></div>
                  <div><dt>Revision</dt><dd>{setting.revision}</dd></div>
                  <div><dt>更新日時</dt><dd>{formatJst(setting.updated_at)}</dd></div>
                </dl>
              </section>
              {canManage ? (
                <div className="referral-settings-actions">
                  <button className="secondary-button" disabled={busy !== null || !dirty} onClick={() => apply(setting)} type="button">
                    <RotateCcw aria-hidden="true" size={17} />元に戻す
                  </button>
                  <button className="primary-button" disabled={busy !== null || !dirty || invalid} type="submit">
                    {busy === "save" ? <LoaderCircle aria-hidden="true" className="spin" size={17} /> : <Save aria-hidden="true" size={17} />}
                    保存
                  </button>
                </div>
              ) : null}
            </form>
          ) : (
            <section className="module-state" role="alert">
              <h2>設定を取得できませんでした</h2>
              <button className="secondary-button" onClick={load} type="button"><RotateCcw aria-hidden="true" size={17} />再読み込み</button>
            </section>
          )}
        </div>
        <FreshMfaDialog onClose={() => setFreshMfaOpen(false)} onSuccess={async () => { setFreshMfaOpen(false); await save(); }} open={freshMfaOpen} />
      </ProtectedAdminRoute>
    </AdminShell>
  );
}

function NumberField({ disabled, label, max, min, onChange, value }: { disabled: boolean; label: string; max: number; min: number; onChange: (value: number) => void; value: number }) {
  return <label><span>{label}</span><input disabled={disabled} inputMode="numeric" max={max} min={min} onChange={(event) => onChange(Number(event.target.value))} required type="number" value={value} /></label>;
}

function SettingsError({ error, onReload }: { error: AdminApiError; onReload: () => Promise<void> }) {
  const conflict = error.status === 409;
  return <div aria-live="assertive" className="notice notice-error" role="alert"><p>{conflict ? "設定が更新されています。最新内容を再取得してください。" : error.message}</p>{conflict ? <button className="secondary-button" onClick={() => void onReload()} type="button"><RotateCcw aria-hidden="true" size={17} />再読み込み</button> : null}</div>;
}

function asApiError(value: unknown): AdminApiError {
  return value instanceof AdminApiError ? value : new AdminApiError(0, "NETWORK_ERROR", null, null, true);
}

function formatJst(value: string): string {
  return new Intl.DateTimeFormat("ja-JP", { dateStyle: "medium", timeStyle: "short", timeZone: "Asia/Tokyo" }).format(new Date(value));
}
