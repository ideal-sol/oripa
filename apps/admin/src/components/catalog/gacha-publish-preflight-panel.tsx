"use client";

import {
  AlertTriangle,
  CheckCircle2,
  LoaderCircle,
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
  AdminGachaPublishedProbabilityCandidate,
} from "@/lib/admin-api/generated";

type PendingAction = "selection" | "preflight" | null;

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
  const [confirmOpen, setConfirmOpen] = useState(false);
  const [freshMfaOpen, setFreshMfaOpen] = useState(false);
  const [reload, setReload] = useState(0);
  const pendingAction = useRef<PendingAction>(null);
  const pendingMutation = useRef<{ fingerprint: string; key: string } | null>(
    null,
  );
  const confirmHeading = useRef<HTMLHeadingElement>(null);
  const currentId = version.published_probability_version?.id ?? "";
  const dirty = selectedId !== currentId;

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
    ])
      .then(([candidateResponse, selectionResponse]) => {
        setCandidates(candidateResponse.items);
        setSelectedId(selectionResponse.data.selected_probability?.id ?? "");
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
    if (confirmOpen) confirmHeading.current?.focus();
  }, [confirmOpen]);

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

  return (
    <section className="gacha-publish-preflight" aria-labelledby="gacha-preflight-title">
      <header>
        <div>
          <span className="eyebrow">Publish readiness</span>
          <h2 id="gacha-preflight-title">Probability Selection／Preflight</h2>
        </div>
        <span className="catalog-readonly-note">公開操作は未実装</span>
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
                disabled={busy || !dirty || selectedId === ""}
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
                disabled={busy || dirty || currentId === ""}
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
          </div>
        </div>
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
