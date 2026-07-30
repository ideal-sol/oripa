"use client";

import {
  AlertTriangle,
  CalendarClock,
  CheckCircle2,
  LoaderCircle,
  PauseCircle,
  PlayCircle,
  RefreshCw,
  ShieldCheck,
} from "lucide-react";
import { useEffect, useMemo, useRef, useState } from "react";

import { FreshMfaDialog } from "@/components/auth/fresh-mfa-dialog";
import { CatalogApiErrorBoundary } from "@/components/catalog/catalog-api-error-boundary";
import { usePermissions } from "@/components/permissions/permission-provider";
import {
  AdminApiClient,
  AdminApiError,
} from "@/lib/admin-api/client";
import type {
  AdminCatalogGachaVersion,
  AdminGachaPublishPreflight,
  AdminGachaPublishSchedule,
  AdminGachaPublishSchedulePreflight,
  AdminGachaPublishState,
  AdminGachaPublishedProbabilityCandidate,
  AdminGachaSalesPauseReason,
  AdminGachaSalesPreflight,
  AdminGachaSalesState,
} from "@/lib/admin-api/generated";

type PendingAction =
  | "selection"
  | "preflight"
  | "publish"
  | "schedule-preflight"
  | "schedule"
  | "schedule-cancel"
  | "sales-pause-preflight"
  | "sales-pause"
  | "sales-resume-preflight"
  | "sales-resume"
  | null;

const ADMIN_DISPLAY_TIME_ZONE = "Asia/Tokyo";

function formatAdminDateTime(value: string): string {
  return new Date(value).toLocaleString("ja-JP", {
    timeZone: ADMIN_DISPLAY_TIME_ZONE,
  });
}

export function GachaPublishPreflightPanel({
  gachaId,
  onCanonical,
  version,
}: {
  gachaId: string;
  onCanonical: (version: AdminCatalogGachaVersion) => void;
  version: AdminCatalogGachaVersion;
}) {
  const client = useMemo(() => new AdminApiClient(), []);
  const { hasPermission } = usePermissions();
  const canPublish = hasPermission("catalog.publish");
  const mutable = version.status === "draft" && !version.is_archived;
  const [candidates, setCandidates] = useState<
    AdminGachaPublishedProbabilityCandidate[]
  >([]);
  const [selectedId, setSelectedId] = useState(
    version.published_probability_version?.id ?? "",
  );
  const [loading, setLoading] = useState(true);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<AdminApiError | null>(null);
  const [preflight, setPreflight] = useState<AdminGachaPublishPreflight | null>(
    null,
  );
  const [publishState, setPublishState] = useState<AdminGachaPublishState | null>(
    null,
  );
  const [salesState, setSalesState] = useState<AdminGachaSalesState | null>(
    null,
  );
  const [salesPreflight, setSalesPreflight] =
    useState<AdminGachaSalesPreflight | null>(null);
  const [pauseReason, setPauseReason] =
    useState<AdminGachaSalesPauseReason>("operations_review");
  const [schedule, setSchedule] = useState<AdminGachaPublishSchedule | null>(
    null,
  );
  const [scheduledFor, setScheduledFor] = useState("");
  const [schedulePreflight, setSchedulePreflight] =
    useState<AdminGachaPublishSchedulePreflight | null>(null);
  const [confirmOpen, setConfirmOpen] = useState(false);
  const [publishConfirmOpen, setPublishConfirmOpen] = useState(false);
  const [scheduleConfirmOpen, setScheduleConfirmOpen] = useState(false);
  const [cancelConfirmOpen, setCancelConfirmOpen] = useState(false);
  const [salesConfirmOpen, setSalesConfirmOpen] = useState(false);
  const [freshMfaOpen, setFreshMfaOpen] = useState(false);
  const [reload, setReload] = useState(0);
  const pendingAction = useRef<PendingAction>(null);
  const pendingMutation = useRef<{ fingerprint: string; key: string } | null>(
    null,
  );
  const confirmHeading = useRef<HTMLHeadingElement>(null);
  const currentId = version.published_probability_version?.id ?? "";
  const selectionDirty = selectedId !== currentId;
  const dirty = selectionDirty || scheduledFor !== "";
  const hasActiveSchedule =
    schedule?.status === "scheduled" || schedule?.status === "processing";

  useEffect(() => {
    const controller = new AbortController();
    Promise.all([
      client.listGachaPublishedProbabilityCandidates(
        gachaId,
        version.id,
        { direction: "desc", limit: 100 },
        controller.signal,
      ),
      client.getGachaProbabilitySelection(
        gachaId,
        version.id,
        controller.signal,
      ),
      client.getGachaPublishState(gachaId, controller.signal),
      client.getGachaSalesState(gachaId, controller.signal),
      client.getGachaPublishSchedule(gachaId, version.id, controller.signal),
    ])
      .then(([
        candidateResponse,
        selectionResponse,
        publishStateResponse,
        salesStateResponse,
        scheduleResponse,
      ]) => {
        setCandidates(candidateResponse.items);
        setSelectedId(selectionResponse.data.selected_probability?.id ?? "");
        setPublishState(publishStateResponse.data);
        setSalesState(salesStateResponse.data);
        setSchedule(scheduleResponse.data);
        setError(null);
      })
      .catch((cause: unknown) => {
        if (!controller.signal.aborted) setError(normalizeError(cause));
      })
      .finally(() => {
        if (!controller.signal.aborted) setLoading(false);
      });
    return () => controller.abort();
  }, [client, gachaId, reload, version.id]);

  useEffect(() => {
    if (!dirty) return;
    const warn = (event: BeforeUnloadEvent) => event.preventDefault();
    window.addEventListener("beforeunload", warn);
    return () => window.removeEventListener("beforeunload", warn);
  }, [dirty]);

  useEffect(() => {
    if (
      confirmOpen ||
      publishConfirmOpen ||
      scheduleConfirmOpen ||
      cancelConfirmOpen
      || salesConfirmOpen
    ) {
      confirmHeading.current?.focus();
    }
  }, [
    cancelConfirmOpen,
    confirmOpen,
    publishConfirmOpen,
    salesConfirmOpen,
    scheduleConfirmOpen,
  ]);

  function mutationKey(fingerprint: string): string {
    if (pendingMutation.current?.fingerprint === fingerprint) {
      return pendingMutation.current.key;
    }
    const key = crypto.randomUUID();
    pendingMutation.current = { fingerprint, key };
    return key;
  }

  async function selectProbability() {
    if (!selectedId || !mutable || !canPublish) return;
    const body = {
      expected_revision: version.revision,
      probability_version_id: selectedId,
    };
    const fingerprint = JSON.stringify({
      action: "gacha-probability-selection",
      body,
      gachaId,
      versionId: version.id,
    });
    setBusy(true);
    try {
      const result = await client.selectGachaPublishedProbability(
        gachaId,
        version.id,
        body,
        mutationKey(fingerprint),
      );
      pendingMutation.current = null;
      pendingAction.current = null;
      setConfirmOpen(false);
      setPreflight(null);
      setError(null);
      onCanonical(result.data);
    } catch (cause) {
      const next = normalizeError(cause);
      if (next.requiresFreshMfa) {
        pendingAction.current = "selection";
        setConfirmOpen(false);
        setFreshMfaOpen(true);
      } else {
        if (!next.retryable) pendingMutation.current = null;
        setError(next);
      }
    } finally {
      setBusy(false);
    }
  }

  async function runPreflight() {
    if (!mutable || !canPublish) return;
    const body = { expected_revision: version.revision };
    const fingerprint = JSON.stringify({
      action: "gacha-publish-preflight",
      body,
      gachaId,
      versionId: version.id,
    });
    setBusy(true);
    try {
      const result = await client.preflightGachaVersionPublish(
        gachaId,
        version.id,
        body,
        mutationKey(fingerprint),
      );
      pendingMutation.current = null;
      pendingAction.current = null;
      setError(null);
      setPreflight(result.data);
    } catch (cause) {
      const next = normalizeError(cause);
      if (next.requiresFreshMfa) {
        pendingAction.current = "preflight";
        setFreshMfaOpen(true);
      } else {
        if (!next.retryable) pendingMutation.current = null;
        setError(next);
      }
    } finally {
      setBusy(false);
    }
  }

  async function publishImmediately() {
    if (
      !mutable ||
      !canPublish ||
      !preflight?.publishable ||
      !publishState
    ) {
      return;
    }
    const body = {
      expected_revision: version.revision,
      expected_gacha_revision: publishState.gacha_revision,
    };
    const fingerprint = JSON.stringify({
      action: "gacha-immediate-publish",
      body,
      gachaId,
      versionId: version.id,
    });
    setBusy(true);
    try {
      const result = await client.publishGachaVersionImmediately(
        gachaId,
        version.id,
        body,
        mutationKey(fingerprint),
      );
      const canonical = await client.getCatalogGachaVersion(
        gachaId,
        version.id,
      );
      pendingMutation.current = null;
      pendingAction.current = null;
      setPublishConfirmOpen(false);
      setError(null);
      setPublishState({
        gacha_id: gachaId,
        gacha_revision: result.data.gacha_revision,
        current_published_version: result.data.current_published_version,
        selected_probability: result.data.selected_probability,
        draw_state: result.data.draw_state,
      });
      onCanonical(canonical.data);
      setReload((value) => value + 1);
    } catch (cause) {
      const next = normalizeError(cause);
      if (next.requiresFreshMfa) {
        pendingAction.current = "publish";
        setPublishConfirmOpen(false);
        setFreshMfaOpen(true);
      } else {
        if (!next.retryable) pendingMutation.current = null;
        setError(next);
      }
    } finally {
      setBusy(false);
    }
  }

  function scheduleRequestBody() {
    if (!publishState || scheduledFor === "") return null;
    const parsed = new Date(scheduledFor);
    if (Number.isNaN(parsed.getTime())) return null;
    return {
      scheduled_for: parsed.toISOString(),
      expected_revision: version.revision,
      expected_gacha_revision: publishState.gacha_revision,
    };
  }

  async function runSchedulePreflight() {
    if (!mutable || !canPublish) return;
    const body = scheduleRequestBody();
    if (body === null) return;
    const fingerprint = JSON.stringify({
      action: "gacha-publish-schedule-preflight",
      body,
      gachaId,
      versionId: version.id,
    });
    setBusy(true);
    try {
      const result = await client.preflightGachaVersionPublishSchedule(
        gachaId,
        version.id,
        body,
        mutationKey(fingerprint),
      );
      pendingMutation.current = null;
      pendingAction.current = null;
      setError(null);
      setSchedulePreflight(result.data);
    } catch (cause) {
      const next = normalizeError(cause);
      if (next.requiresFreshMfa) {
        pendingAction.current = "schedule-preflight";
        setFreshMfaOpen(true);
      } else {
        if (!next.retryable) pendingMutation.current = null;
        setError(next);
      }
    } finally {
      setBusy(false);
    }
  }

  async function createSchedule() {
    if (!schedulePreflight?.publishable || !mutable || !canPublish) return;
    const body = scheduleRequestBody();
    if (body === null) return;
    const fingerprint = JSON.stringify({
      action: "gacha-publish-schedule",
      body,
      gachaId,
      versionId: version.id,
    });
    setBusy(true);
    try {
      const result = await client.scheduleGachaVersionPublish(
        gachaId,
        version.id,
        body,
        mutationKey(fingerprint),
      );
      const canonical = await client.getCatalogGachaVersion(
        gachaId,
        version.id,
      );
      pendingMutation.current = null;
      pendingAction.current = null;
      setSchedule(result.data);
      setScheduledFor("");
      setSchedulePreflight(null);
      setScheduleConfirmOpen(false);
      setError(null);
      onCanonical(canonical.data);
      setReload((value) => value + 1);
    } catch (cause) {
      const next = normalizeError(cause);
      if (next.requiresFreshMfa) {
        pendingAction.current = "schedule";
        setScheduleConfirmOpen(false);
        setFreshMfaOpen(true);
      } else {
        if (!next.retryable) pendingMutation.current = null;
        setError(next);
      }
    } finally {
      setBusy(false);
    }
  }

  async function cancelSchedule() {
    if (!schedule || schedule.status !== "scheduled" || !canPublish) return;
    const body = {
      expected_schedule_revision: schedule.revision,
      expected_gacha_revision: schedule.gacha_revision,
      expected_version_revision: schedule.gacha_version_revision,
    };
    const fingerprint = JSON.stringify({
      action: "gacha-publish-schedule-cancel",
      body,
      gachaId,
      scheduleId: schedule.id,
      versionId: version.id,
    });
    setBusy(true);
    try {
      const result = await client.cancelGachaVersionPublishSchedule(
        gachaId,
        version.id,
        schedule.id,
        body,
        mutationKey(fingerprint),
      );
      const canonical = await client.getCatalogGachaVersion(
        gachaId,
        version.id,
      );
      pendingMutation.current = null;
      pendingAction.current = null;
      setSchedule(result.data);
      setCancelConfirmOpen(false);
      setError(null);
      onCanonical(canonical.data);
      setReload((value) => value + 1);
    } catch (cause) {
      const next = normalizeError(cause);
      if (next.requiresFreshMfa) {
        pendingAction.current = "schedule-cancel";
        setCancelConfirmOpen(false);
        setFreshMfaOpen(true);
      } else {
        if (!next.retryable) pendingMutation.current = null;
        setError(next);
      }
    } finally {
      setBusy(false);
    }
  }

  async function runSalesPreflight(operation: "pause" | "resume") {
    if (!canPublish || !salesState) return;
    const body = operation === "pause"
      ? {
          expected_gacha_revision: salesState.gacha_revision,
          reason_code: pauseReason,
        }
      : { expected_gacha_revision: salesState.gacha_revision };
    const fingerprint = JSON.stringify({
      action: `gacha-sales-${operation}-preflight`,
      body,
      gachaId,
    });
    setBusy(true);
    try {
      const result = operation === "pause"
        ? await client.preflightGachaSalesPause(
            gachaId,
            body as {
              expected_gacha_revision: number;
              reason_code: AdminGachaSalesPauseReason;
            },
            mutationKey(fingerprint),
          )
        : await client.preflightGachaSalesResume(
            gachaId,
            body,
            mutationKey(fingerprint),
          );
      pendingMutation.current = null;
      pendingAction.current = null;
      setSalesPreflight(result.data);
      setError(null);
    } catch (cause) {
      const next = normalizeError(cause);
      if (next.requiresFreshMfa) {
        pendingAction.current = `sales-${operation}-preflight`;
        setFreshMfaOpen(true);
      } else {
        if (!next.retryable) pendingMutation.current = null;
        setError(next);
      }
    } finally {
      setBusy(false);
    }
  }

  async function mutateSales(operation: "pause" | "resume") {
    if (
      !canPublish ||
      !salesState ||
      !salesPreflight?.allowed ||
      salesPreflight.operation !== operation
    ) {
      return;
    }
    const body = operation === "pause"
      ? {
          expected_gacha_revision: salesState.gacha_revision,
          reason_code: pauseReason,
        }
      : { expected_gacha_revision: salesState.gacha_revision };
    const fingerprint = JSON.stringify({
      action: `gacha-sales-${operation}`,
      body,
      gachaId,
    });
    setBusy(true);
    try {
      const result = operation === "pause"
        ? await client.pauseGachaSales(
            gachaId,
            body as {
              expected_gacha_revision: number;
              reason_code: AdminGachaSalesPauseReason;
            },
            mutationKey(fingerprint),
          )
        : await client.resumeGachaSales(
            gachaId,
            body,
            mutationKey(fingerprint),
          );
      pendingMutation.current = null;
      pendingAction.current = null;
      setSalesState(result.data);
      setSalesPreflight(null);
      setSalesConfirmOpen(false);
      setError(null);
      setReload((value) => value + 1);
    } catch (cause) {
      const next = normalizeError(cause);
      if (next.requiresFreshMfa) {
        pendingAction.current = `sales-${operation}`;
        setSalesConfirmOpen(false);
        setFreshMfaOpen(true);
      } else {
        if (!next.retryable) pendingMutation.current = null;
        setError(next);
      }
    } finally {
      setBusy(false);
    }
  }

  return (
    <section className="gacha-publish-preflight" aria-labelledby="gacha-preflight-title">
      <header>
        <div>
          <span className="eyebrow">Publish readiness</span>
          <h2 id="gacha-preflight-title">Probability Selection／Preflight</h2>
        </div>
        <span className="catalog-readonly-note">
          Unpublishは未実装
        </span>
      </header>
      {loading ? (
        <div className="catalog-inline-status" role="status">
          <LoaderCircle className="spin" size={18} aria-hidden="true" />
          Published Snapshotを確認しています
        </div>
      ) : null}
      {error ? (
        <CatalogApiErrorBoundary
          error={error}
          retry={() => {
            setError(null);
            setLoading(true);
            setReload((value) => value + 1);
          }}
        />
      ) : null}
      {!loading ? (
        <div className="gacha-publish-selection-grid">
          <label>
            Published Probability
            <select
              disabled={!canPublish || !mutable || busy}
              onChange={(event) => {
                setSelectedId(event.target.value);
                setPreflight(null);
              }}
              value={selectedId}
            >
              <option value="">未選択</option>
              {candidates.map((candidate) => (
                <option
                  disabled={candidate.validation_status !== "valid"}
                  key={candidate.id}
                  value={candidate.id}
                >
                  v{candidate.version_number} / {candidate.stage_count} stages /{" "}
                  {candidate.snapshot_sha256.slice(0, 12)}
                </option>
              ))}
            </select>
          </label>
          <div className="gacha-publish-selection-actions">
            {canPublish && mutable ? (
              <button
                className="secondary-button"
                disabled={busy || !selectionDirty || selectedId === ""}
                onClick={() => setConfirmOpen(true)}
                type="button"
              >
                <ShieldCheck size={16} aria-hidden="true" />
                選択を確定
              </button>
            ) : null}
            {canPublish && mutable ? (
              <button
                className="primary-button"
                disabled={busy || selectionDirty || currentId === ""}
                onClick={runPreflight}
                type="button"
              >
                <CheckCircle2 size={16} aria-hidden="true" />
                Publish Preflight
              </button>
            ) : null}
          </div>
        </div>
      ) : null}
      {currentId ? (
        <dl className="gacha-publish-current">
          <div>
            <dt>現在の選択</dt>
            <dd>
              v{version.published_probability_version?.version_number} /{" "}
              {currentId}
            </dd>
          </div>
          <div>
            <dt>Snapshot</dt>
            <dd>
              {candidates
                .find((candidate) => candidate.id === currentId)
                ?.snapshot_sha256.slice(0, 12) ?? "再取得中"}
            </dd>
          </div>
        </dl>
      ) : null}
      {preflight ? (
        <div
          className={`gacha-preflight-result ${
            preflight.publishable ? "is-ready" : "is-blocked"
          }`}
          role="status"
        >
          {preflight.publishable ? (
            <CheckCircle2 size={22} aria-hidden="true" />
          ) : (
            <AlertTriangle size={22} aria-hidden="true" />
          )}
          <div>
            <h3>
              {preflight.publishable
                ? "Server Preflight完了"
                : "公開前の未達項目があります"}
            </h3>
            {preflight.blocking_reasons.length > 0 ? (
              <ul>
                {preflight.blocking_reasons.map((reason) => (
                  <li key={reason.code}>
                    <strong>{reason.code}</strong>
                    <span>{reason.message}</span>
                  </li>
                ))}
              </ul>
            ) : (
              <p>公開可否の判定だけを完了しました。Gachaは公開されていません。</p>
            )}
            <small>Request ID: {preflight.request_id}</small>
            {preflight.publishable && canPublish && mutable ? (
              <button
                className="primary-button"
                disabled={busy}
                onClick={() => setPublishConfirmOpen(true)}
                type="button"
              >
                <ShieldCheck size={16} aria-hidden="true" />
                Publish Now
              </button>
            ) : null}
          </div>
        </div>
      ) : null}
      {publishState?.current_published_version ? (
        <dl className="gacha-publish-current">
          <div>
            <dt>現在公開中</dt>
            <dd>
              v{publishState.current_published_version.version_number} /{" "}
              {publishState.current_published_version.id}
            </dd>
          </div>
          <div>
            <dt>販売状況</dt>
            <dd>
              {publishState.draw_state?.sold_count ?? 0} /{" "}
              {publishState.draw_state?.total_count ?? 0}
            </dd>
          </div>
        </dl>
      ) : null}
      {salesState ? (
        <div className="gacha-preflight-result" aria-live="polite">
          {salesState.status === "paused" ? (
            <PauseCircle size={22} aria-hidden="true" />
          ) : (
            <PlayCircle size={22} aria-hidden="true" />
          )}
          <div>
            <h3>
              Sales: {salesState.status === "paused" ? "一時停止中" : "販売中"}
            </h3>
            <p>
              公開Version v
              {salesState.current_published_version?.version_number ?? "未設定"} /{" "}
              {salesState.draw_state?.sold_count ?? 0} of{" "}
              {salesState.draw_state?.total_count ?? 0}
            </p>
            {salesState.status === "paused" ? (
              <p>
                理由: {salesState.reason_code ?? "未設定"} /{" "}
                {salesState.paused_at
                  ? formatAdminDateTime(salesState.paused_at)
                  : "時刻未設定"}
              </p>
            ) : null}
            {canPublish && salesState.current_published_version ? (
              <div className="gacha-publish-selection-actions">
                {salesState.status === "selling" ? (
                  <>
                    <label>
                      Pause理由
                      <select
                        disabled={busy}
                        onChange={(event) => {
                          setPauseReason(
                            event.target.value as AdminGachaSalesPauseReason,
                          );
                          setSalesPreflight(null);
                        }}
                        value={pauseReason}
                      >
                        <option value="operations_review">運用確認</option>
                        <option value="inventory_review">在庫確認</option>
                        <option value="incident_response">障害対応</option>
                      </select>
                    </label>
                    <button
                      className="secondary-button"
                      disabled={busy}
                      onClick={() => runSalesPreflight("pause")}
                      type="button"
                    >
                      <PauseCircle size={16} aria-hidden="true" />
                      Pause Preflight
                    </button>
                  </>
                ) : (
                  <button
                    className="primary-button"
                    disabled={busy}
                    onClick={() => runSalesPreflight("resume")}
                    type="button"
                  >
                    <PlayCircle size={16} aria-hidden="true" />
                    Resume Preflight
                  </button>
                )}
              </div>
            ) : null}
          </div>
        </div>
      ) : null}
      {salesPreflight ? (
        <div
          className={`gacha-preflight-result ${
            salesPreflight.allowed ? "is-ready" : "is-blocked"
          }`}
          role="status"
        >
          {salesPreflight.allowed ? (
            <CheckCircle2 size={22} aria-hidden="true" />
          ) : (
            <AlertTriangle size={22} aria-hidden="true" />
          )}
          <div>
            <h3>
              {salesPreflight.allowed
                ? `${salesPreflight.operation === "pause" ? "Pause" : "Resume"}可能`
                : "Sales状態を変更できません"}
            </h3>
            {salesPreflight.blocking_reasons.length > 0 ? (
              <ul>
                {salesPreflight.blocking_reasons.map((reason) => (
                  <li key={reason.code}>
                    <strong>{reason.code}</strong>
                    <span>{reason.message}</span>
                  </li>
                ))}
              </ul>
            ) : (
              <p>Server側で公開Version、Probability、在庫、期間を確認しました。</p>
            )}
            {salesPreflight.allowed && canPublish ? (
              <button
                className="primary-button"
                disabled={busy}
                onClick={() => setSalesConfirmOpen(true)}
                type="button"
              >
                {salesPreflight.operation === "pause" ? (
                  <PauseCircle size={16} aria-hidden="true" />
                ) : (
                  <PlayCircle size={16} aria-hidden="true" />
                )}
                最終確認
              </button>
            ) : null}
          </div>
        </div>
      ) : null}
      {canPublish && mutable && !hasActiveSchedule ? (
        <div className="gacha-publish-selection-grid">
          <label>
            Schedule Publish
            <input
              disabled={busy}
              onChange={(event) => {
                setScheduledFor(event.target.value);
                setSchedulePreflight(null);
              }}
              type="datetime-local"
              value={scheduledFor}
            />
            <small>
              入力は端末の表示Timezone、保存とWorker判定はUTCのDB Server時刻です。
            </small>
          </label>
          <div className="gacha-publish-selection-actions">
            <button
              className="secondary-button"
              disabled={
                busy ||
                selectedId !== currentId ||
                scheduledFor === "" ||
                currentId === ""
              }
              onClick={runSchedulePreflight}
              type="button"
            >
              <CalendarClock size={16} aria-hidden="true" />
              Schedule Preflight
            </button>
          </div>
        </div>
      ) : null}
      {schedulePreflight ? (
        <div
          className={`gacha-preflight-result ${
            schedulePreflight.publishable ? "is-ready" : "is-blocked"
          }`}
          role="status"
        >
          {schedulePreflight.publishable ? (
            <CheckCircle2 size={22} aria-hidden="true" />
          ) : (
            <AlertTriangle size={22} aria-hidden="true" />
          )}
          <div>
            <h3>
              {schedulePreflight.publishable
                ? "Schedule Preflight完了"
                : "予約前の未達項目があります"}
            </h3>
            <p>
              {formatAdminDateTime(schedulePreflight.scheduled_for)}
              {" "}（保存: {schedulePreflight.server_timezone}）
            </p>
            {schedulePreflight.blocking_reasons.length > 0 ? (
              <ul>
                {schedulePreflight.blocking_reasons.map((reason) => (
                  <li key={reason.code}>
                    <strong>{reason.code}</strong>
                    <span>{reason.message}</span>
                  </li>
                ))}
              </ul>
            ) : null}
            {schedulePreflight.publishable ? (
              <button
                className="primary-button"
                disabled={busy}
                onClick={() => setScheduleConfirmOpen(true)}
                type="button"
              >
                <CalendarClock size={16} aria-hidden="true" />
                Publishを予約
              </button>
            ) : null}
          </div>
        </div>
      ) : null}
      {schedule ? (
        <dl className="gacha-publish-current" aria-label="Publish予約状態">
          <div>
            <dt>予約状態</dt>
            <dd>{schedule.status}</dd>
          </div>
          <div>
            <dt>予約日時</dt>
            <dd>{formatAdminDateTime(schedule.scheduled_for)}</dd>
          </div>
          <div>
            <dt>Worker試行</dt>
            <dd>{schedule.attempts} / 3</dd>
          </div>
          <div>
            <dt>結果</dt>
            <dd>{schedule.failure_code ?? schedule.completed_at ?? "処理待ち"}</dd>
          </div>
          {canPublish && schedule.status === "scheduled" ? (
            <div>
              <dt>操作</dt>
              <dd>
                <button
                  className="secondary-button"
                  disabled={busy}
                  onClick={() => setCancelConfirmOpen(true)}
                  type="button"
                >
                  予約を取消
                </button>
              </dd>
            </div>
          ) : null}
        </dl>
      ) : null}
      {!canPublish ? (
        <p className="catalog-readonly-note">
          `catalog.publish`権限がないため参照専用です。
        </p>
      ) : null}
      {confirmOpen ? (
        <div className="dialog-backdrop" role="presentation">
          <section
            aria-labelledby="gacha-selection-confirm-title"
            aria-modal="true"
            className="dialog-panel"
            role="alertdialog"
          >
            <RefreshCw size={24} aria-hidden="true" />
            <h2
              id="gacha-selection-confirm-title"
              ref={confirmHeading}
              tabIndex={-1}
            >
              Probability選択を変更しますか
            </h2>
            <p>
              Published Snapshot自体は変更しません。Draft Gacha VersionのRevisionを更新します。
            </p>
            <div className="catalog-dialog-actions">
              <button
                className="secondary-button"
                disabled={busy}
                onClick={() => setConfirmOpen(false)}
                type="button"
              >
                取り消し
              </button>
              <button
                className="primary-button"
                disabled={busy}
                onClick={selectProbability}
                type="button"
              >
                選択を確定
              </button>
            </div>
          </section>
        </div>
      ) : null}
      {publishConfirmOpen ? (
        <div className="dialog-backdrop" role="presentation">
          <section
            aria-labelledby="gacha-publish-confirm-title"
            aria-modal="true"
            className="dialog-panel"
            role="alertdialog"
          >
            <ShieldCheck size={24} aria-hidden="true" />
            <h2
              id="gacha-publish-confirm-title"
              ref={confirmHeading}
              tabIndex={-1}
            >
              このVersionへ即時切り替えますか
            </h2>
            <p>
              v{version.version_number}、{version.price_points} Point、全
              {version.total_count}口を公開し、Public CatalogとDraw参照を同時に切り替えます。
            </p>
            {publishState?.current_published_version ? (
              <p>
                現在のv{publishState.current_published_version.version_number}は
                履歴として保持されます。
              </p>
            ) : null}
            <div className="catalog-dialog-actions">
              <button
                className="secondary-button"
                disabled={busy}
                onClick={() => setPublishConfirmOpen(false)}
                type="button"
              >
                取り消し
              </button>
              <button
                className="primary-button"
                disabled={busy}
                onClick={publishImmediately}
                type="button"
              >
                Publish Now
              </button>
            </div>
          </section>
        </div>
      ) : null}
      {scheduleConfirmOpen && schedulePreflight ? (
        <div className="dialog-backdrop" role="presentation">
          <section
            aria-labelledby="gacha-schedule-confirm-title"
            aria-modal="true"
            className="dialog-panel"
            role="alertdialog"
          >
            <CalendarClock size={24} aria-hidden="true" />
            <h2
              id="gacha-schedule-confirm-title"
              ref={confirmHeading}
              tabIndex={-1}
            >
              このVersionのPublishを予約しますか
            </h2>
            <p>
              v{version.version_number}を
              {formatAdminDateTime(schedulePreflight.scheduled_for)}
              にActivationします。実行直前にもServer Preflightを再実行します。
            </p>
            <div className="catalog-dialog-actions">
              <button
                className="secondary-button"
                disabled={busy}
                onClick={() => setScheduleConfirmOpen(false)}
                type="button"
              >
                取り消し
              </button>
              <button
                className="primary-button"
                disabled={busy}
                onClick={createSchedule}
                type="button"
              >
                Publishを予約
              </button>
            </div>
          </section>
        </div>
      ) : null}
      {cancelConfirmOpen && schedule ? (
        <div className="dialog-backdrop" role="presentation">
          <section
            aria-labelledby="gacha-schedule-cancel-title"
            aria-modal="true"
            className="dialog-panel"
            role="alertdialog"
          >
            <AlertTriangle size={24} aria-hidden="true" />
            <h2
              id="gacha-schedule-cancel-title"
              ref={confirmHeading}
              tabIndex={-1}
            >
              Publish予約を取消しますか
            </h2>
            <p>取消後、このDraft Versionは再編集できます。</p>
            <div className="catalog-dialog-actions">
              <button
                className="secondary-button"
                disabled={busy}
                onClick={() => setCancelConfirmOpen(false)}
                type="button"
              >
                戻る
              </button>
              <button
                className="primary-button"
                disabled={busy}
                onClick={cancelSchedule}
                type="button"
              >
                予約を取消
              </button>
            </div>
          </section>
        </div>
      ) : null}
      {salesConfirmOpen && salesPreflight ? (
        <div className="dialog-backdrop" role="presentation">
          <section
            aria-labelledby="gacha-sales-confirm-title"
            aria-modal="true"
            className="dialog-panel"
            role="alertdialog"
          >
            {salesPreflight.operation === "pause" ? (
              <PauseCircle size={24} aria-hidden="true" />
            ) : (
              <PlayCircle size={24} aria-hidden="true" />
            )}
            <h2
              id="gacha-sales-confirm-title"
              ref={confirmHeading}
              tabIndex={-1}
            >
              {salesPreflight.operation === "pause"
                ? "新規販売・抽選を一時停止しますか"
                : "新規販売・抽選を再開しますか"}
            </h2>
            <p>
              公開VersionとProbability Snapshotは変更しません。操作直前に
              Server側の状態を再確認します。
            </p>
            <div className="catalog-dialog-actions">
              <button
                className="secondary-button"
                disabled={busy}
                onClick={() => setSalesConfirmOpen(false)}
                type="button"
              >
                取り消し
              </button>
              <button
                className="primary-button"
                disabled={busy}
                onClick={() => mutateSales(salesPreflight.operation)}
                type="button"
              >
                {salesPreflight.operation === "pause" ? "Pause" : "Resume"}
              </button>
            </div>
          </section>
        </div>
      ) : null}
      <FreshMfaDialog
        onClose={() => {
          pendingAction.current = null;
          setFreshMfaOpen(false);
        }}
        onSuccess={async () => {
          setFreshMfaOpen(false);
          if (pendingAction.current === "selection") {
            await selectProbability();
          } else if (pendingAction.current === "preflight") {
            await runPreflight();
          } else if (pendingAction.current === "publish") {
            await publishImmediately();
          } else if (pendingAction.current === "schedule-preflight") {
            await runSchedulePreflight();
          } else if (pendingAction.current === "schedule") {
            await createSchedule();
          } else if (pendingAction.current === "schedule-cancel") {
            await cancelSchedule();
          } else if (pendingAction.current === "sales-pause-preflight") {
            await runSalesPreflight("pause");
          } else if (pendingAction.current === "sales-pause") {
            await mutateSales("pause");
          } else if (pendingAction.current === "sales-resume-preflight") {
            await runSalesPreflight("resume");
          } else if (pendingAction.current === "sales-resume") {
            await mutateSales("resume");
          }
        }}
        open={freshMfaOpen}
      />
    </section>
  );
}

function normalizeError(cause: unknown): AdminApiError {
  return cause instanceof AdminApiError
    ? cause
    : new AdminApiError(0, "NETWORK_ERROR", null, null, true);
}
