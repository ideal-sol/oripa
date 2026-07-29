"use client";

import {
  Archive,
  CheckCircle2,
  Copy,
  LoaderCircle,
  Plus,
  Rocket,
  Save,
  ShieldAlert,
  Trash2,
} from "lucide-react";
import Link from "next/link";
import { useEffect, useMemo, useRef, useState } from "react";

import { useAdminAuth } from "@/components/auth/admin-auth-provider";
import { FreshMfaDialog } from "@/components/auth/fresh-mfa-dialog";
import { CatalogApiErrorBoundary } from "@/components/catalog/catalog-api-error-boundary";
import { CatalogBreadcrumb } from "@/components/catalog/catalog-breadcrumb";
import { CatalogConfirmationDialog } from "@/components/catalog/catalog-confirmation-dialog";
import { CatalogConflictBoundary } from "@/components/catalog/catalog-conflict-boundary";
import { CatalogSectionNavigation } from "@/components/catalog/catalog-section-navigation";
import { CursorPagination } from "@/components/catalog/cursor-pagination";
import { ProtectedAdminRoute } from "@/components/permissions/protected-admin-route";
import { usePermissions } from "@/components/permissions/permission-provider";
import { AdminPageHeader } from "@/components/shell/admin-page-header";
import { AdminShell } from "@/components/shell/admin-shell";
import {
  AdminApiClient,
  AdminApiError,
} from "@/lib/admin-api/client";
import type {
  AdminCatalogGachaVersion,
  AdminCatalogProbabilityEntriesReplace,
  AdminCatalogProbabilityStageInput,
  AdminCatalogProbabilityTargetInput,
  AdminCatalogProbabilityVersion,
} from "@/lib/admin-api/generated";
import { catalogSection } from "@/lib/catalog/catalog-registry";

type ViewState =
  | { kind: "loading" }
  | { kind: "error"; error: AdminApiError }
  | {
      kind: "list";
      gachaVersion: AdminCatalogGachaVersion;
      items: AdminCatalogProbabilityVersion[];
      nextCursor: string | null;
    }
  | {
      kind: "detail";
      gachaVersion: AdminCatalogGachaVersion;
      probability: AdminCatalogProbabilityVersion;
    };

export function CatalogProbabilityWorkspace({
  gachaId,
  gachaVersionId,
  probabilityVersionId,
}: {
  gachaId: string;
  gachaVersionId: string;
  probabilityVersionId?: string;
}) {
  const client = useMemo(() => new AdminApiClient(), []);
  const section = catalogSection("gachas");
  if (!section) throw new Error("Gacha section is unavailable.");
  const { expireSession } = useAdminAuth();
  const { hasPermission } = usePermissions();
  const canManage = hasPermission("catalog.manage");
  const canPublish = hasPermission("catalog.publish");
  const [state, setState] = useState<ViewState>({ kind: "loading" });
  const [cursorHistory, setCursorHistory] = useState<(string | null)[]>([null]);
  const [cursorIndex, setCursorIndex] = useState(0);
  const [reload, setReload] = useState(0);
  const [busy, setBusy] = useState(false);
  const [discardOpen, setDiscardOpen] = useState(false);
  const [mutationError, setMutationError] = useState<AdminApiError | null>(null);
  const pendingMutation = useRef<{ fingerprint: string; key: string } | null>(null);
  const cursor = cursorHistory[cursorIndex] ?? null;

  useEffect(() => {
    const controller = new AbortController();
    loadProbabilityState(
      client,
      gachaId,
      gachaVersionId,
      probabilityVersionId,
      cursor,
      controller.signal,
    )
      .then(setState)
      .catch((cause: unknown) => {
        if (controller.signal.aborted) return;
        const error = normalizeError(cause);
        if (error.isSessionExpired) expireSession();
        setState({ kind: "error", error });
      });
    return () => controller.abort();
  }, [
    client,
    cursor,
    expireSession,
    gachaId,
    gachaVersionId,
    probabilityVersionId,
    reload,
  ]);

  function mutationKey(fingerprint: string): string {
    if (pendingMutation.current?.fingerprint === fingerprint) {
      return pendingMutation.current.key;
    }
    const key = crypto.randomUUID();
    pendingMutation.current = { fingerprint, key };
    return key;
  }

  function handleMutationError(cause: unknown) {
    const error = normalizeError(cause);
    if (!error.retryable) pendingMutation.current = null;
    if (error.isSessionExpired) expireSession();
    setMutationError(error);
  }

  async function createDraft() {
    const fingerprint = JSON.stringify({
      action: "create-probability",
      gachaId,
      gachaVersionId,
    });
    setBusy(true);
    try {
      await client.createCatalogProbabilityDraft(
        gachaId,
        gachaVersionId,
        mutationKey(fingerprint),
      );
      pendingMutation.current = null;
      setMutationError(null);
      setReload((value) => value + 1);
    } catch (cause) {
      handleMutationError(cause);
    } finally {
      setBusy(false);
    }
  }

  async function cloneDraft(source: AdminCatalogProbabilityVersion) {
    if (!window.confirm(`Probability v${source.version_number}をCloneしますか。`)) {
      return;
    }
    const fingerprint = JSON.stringify({
      action: "clone-probability",
      source: source.id,
    });
    setBusy(true);
    try {
      await client.cloneCatalogProbabilityDraft(
        gachaId,
        gachaVersionId,
        source.id,
        mutationKey(fingerprint),
      );
      pendingMutation.current = null;
      setMutationError(null);
      setReload((value) => value + 1);
    } catch (cause) {
      handleMutationError(cause);
    } finally {
      setBusy(false);
    }
  }

  async function discardDraft() {
    if (state.kind !== "detail") return;
    const fingerprint = JSON.stringify({
      action: "discard-probability",
      id: state.probability.id,
      revision: state.probability.revision,
    });
    setBusy(true);
    try {
      const result = await client.discardCatalogProbabilityDraft(
        gachaId,
        gachaVersionId,
        state.probability.id,
        state.probability.revision,
        mutationKey(fingerprint),
      );
      pendingMutation.current = null;
      setMutationError(null);
      setState({ ...state, probability: result.data });
      setDiscardOpen(false);
    } catch (cause) {
      handleMutationError(cause);
      setDiscardOpen(false);
    } finally {
      setBusy(false);
    }
  }

  const title =
    state.kind === "detail"
      ? `Probability v${state.probability.version_number}`
      : "Probability Versions";

  return (
    <AdminShell>
      <ProtectedAdminRoute permission="catalog.read">
        <div className="workspace">
          <CatalogBreadcrumb detail={title} section={section} />
          <AdminPageHeader
            description="Draft Probabilityを整数ppmで編集し、Server Validationで公開可能性を確認します。公開操作は行いません。"
            eyebrow="Catalog / Gacha"
            title={title}
            action={
              canManage && state.kind === "list" ? (
                <button
                  className="primary-button"
                  disabled={busy}
                  onClick={createDraft}
                  type="button"
                >
                  <Plus size={16} aria-hidden="true" />
                  Draft作成
                </button>
              ) : undefined
            }
          />
          <CatalogSectionNavigation active="gachas" />
          <div className="catalog-probability-back">
            <Link
              className="table-link"
              href={`/catalog/gachas/${gachaId}/versions/${gachaVersionId}`}
            >
              Gacha Versionへ戻る
            </Link>
          </div>
          {state.kind === "loading" ? <LoadingState /> : null}
          {state.kind === "error" ? (
            <CatalogApiErrorBoundary
              error={state.error}
              retry={() => setReload((value) => value + 1)}
            />
          ) : null}
          {mutationError && [409, 412].includes(mutationError.status) ? (
            <CatalogConflictBoundary
              onReload={() => {
                setMutationError(null);
                setReload((value) => value + 1);
              }}
            />
          ) : null}
          {mutationError && ![409, 412].includes(mutationError.status) ? (
            <CatalogApiErrorBoundary
              error={mutationError}
              retry={() => setMutationError(null)}
            />
          ) : null}
          {state.kind === "list" ? (
            <ProbabilityList
              canGoBack={cursorIndex > 0}
              canManage={canManage}
              gachaId={gachaId}
              gachaVersionId={gachaVersionId}
              items={state.items}
              nextCursor={state.nextCursor}
              onBack={() => setCursorIndex((value) => Math.max(0, value - 1))}
              onClone={cloneDraft}
              onNext={() => {
                if (!state.nextCursor) return;
                setCursorHistory((history) => [
                  ...history.slice(0, cursorIndex + 1),
                  state.nextCursor,
                ]);
                setCursorIndex((value) => value + 1);
              }}
            />
          ) : null}
          {state.kind === "detail" ? (
            <ProbabilityDetail
              canManage={canManage}
              canPublish={canPublish}
              client={client}
              gachaId={gachaId}
              gachaVersion={state.gachaVersion}
              key={`${state.probability.id}:${state.probability.revision}`}
              onCanonical={(probability) => {
                pendingMutation.current = null;
                setMutationError(null);
                setState({ ...state, probability });
              }}
              onDiscard={() => setDiscardOpen(true)}
              onError={handleMutationError}
              pendingKey={mutationKey}
              probability={state.probability}
            />
          ) : null}
          {discardOpen && state.kind === "detail" ? (
            <CatalogConfirmationDialog
              busy={busy}
              name={`Probability v${state.probability.version_number}`}
              onCancel={() => setDiscardOpen(false)}
              onConfirm={discardDraft}
            />
          ) : null}
        </div>
      </ProtectedAdminRoute>
    </AdminShell>
  );
}

function ProbabilityList({
  canGoBack,
  canManage,
  gachaId,
  gachaVersionId,
  items,
  nextCursor,
  onBack,
  onClone,
  onNext,
}: {
  canGoBack: boolean;
  canManage: boolean;
  gachaId: string;
  gachaVersionId: string;
  items: AdminCatalogProbabilityVersion[];
  nextCursor: string | null;
  onBack: () => void;
  onClone: (source: AdminCatalogProbabilityVersion) => void;
  onNext: () => void;
}) {
  if (items.length === 0) {
    return (
      <section className="catalog-state">
        <ShieldAlert size={28} aria-hidden="true" />
        <h2>Probability Versionはありません</h2>
        <p>Mutation権限がある場合は空のDraftを作成できます。</p>
      </section>
    );
  }
  return (
    <>
      <div className="catalog-table-wrap">
        <table className="catalog-table">
          <thead>
            <tr>
              <th>Version</th>
              <th>Status</th>
              <th>Stage</th>
              <th>Validation</th>
              <th>Revision</th>
              <th>操作</th>
            </tr>
          </thead>
          <tbody>
            {items.map((item) => (
              <tr key={item.id}>
                <td>v{item.version_number}</td>
                <td>{item.is_archived ? "archived" : item.status}</td>
                <td>{item.stages.length}</td>
                <td>{item.validation.is_valid ? "valid" : "invalid"}</td>
                <td>{item.revision}</td>
                <td>
                  <div className="catalog-table-actions">
                    <Link
                      className="table-link"
                      href={
                        `/catalog/gachas/${gachaId}/versions/${gachaVersionId}` +
                        `/probability-versions/${item.id}`
                      }
                    >
                      開く
                    </Link>
                    {canManage && !item.is_archived ? (
                      <button
                        aria-label={`Probability v${item.version_number}をClone`}
                        className="icon-button"
                        onClick={() => onClone(item)}
                        type="button"
                      >
                        <Copy size={16} aria-hidden="true" />
                      </button>
                    ) : null}
                  </div>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
      <CursorPagination
        canGoBack={canGoBack}
        canGoNext={nextCursor !== null}
        onBack={onBack}
        onNext={onNext}
      />
    </>
  );
}

function ProbabilityDetail({
  canManage,
  canPublish,
  client,
  gachaId,
  gachaVersion,
  onCanonical,
  onDiscard,
  onError,
  pendingKey,
  probability,
}: {
  canManage: boolean;
  canPublish: boolean;
  client: AdminApiClient;
  gachaId: string;
  gachaVersion: AdminCatalogGachaVersion;
  onCanonical: (probability: AdminCatalogProbabilityVersion) => void;
  onDiscard: () => void;
  onError: (cause: unknown) => void;
  pendingKey: (fingerprint: string) => string;
  probability: AdminCatalogProbabilityVersion;
}) {
  const mutable =
    canManage && isMutableProbabilityVersion(probability);
  const [draft, setDraft] = useState<AdminCatalogProbabilityStageInput[]>(() =>
    toProbabilityDraft(probability),
  );
  const [busy, setBusy] = useState(false);
  const [freshMfaOpen, setFreshMfaOpen] = useState(false);
  const [publishConfirmOpen, setPublishConfirmOpen] = useState(false);
  const [preflightReady, setPreflightReady] = useState(false);
  const pendingPublishAction = useRef<"preflight" | "publish" | null>(null);
  const publishHeading = useRef<HTMLHeadingElement>(null);
  const initial = useMemo(
    () => JSON.stringify(toProbabilityDraft(probability)),
    [probability],
  );
  const dirty = JSON.stringify(draft) !== initial;

  useEffect(() => {
    if (!dirty) return;
    const warn = (event: BeforeUnloadEvent) => event.preventDefault();
    window.addEventListener("beforeunload", warn);
    return () => window.removeEventListener("beforeunload", warn);
  }, [dirty]);

  useEffect(() => {
    if (publishConfirmOpen) publishHeading.current?.focus();
  }, [publishConfirmOpen]);

  async function save() {
    if (!isValidProbabilityDraft(draft)) return;
    const body: AdminCatalogProbabilityEntriesReplace = {
      expected_revision: probability.revision,
      stages: draft,
    };
    const fingerprint = JSON.stringify({
      action: "replace-probability",
      body,
      id: probability.id,
    });
    setBusy(true);
    try {
      const result = await client.replaceCatalogProbabilityEntries(
        gachaId,
        gachaVersion.id,
        probability.id,
        body,
        pendingKey(fingerprint),
      );
      onCanonical(result.data);
    } catch (cause) {
      onError(cause);
    } finally {
      setBusy(false);
    }
  }

  async function validate() {
    const fingerprint = JSON.stringify({
      action: "validate-probability",
      id: probability.id,
      revision: probability.revision,
    });
    setBusy(true);
    try {
      const result = await client.validateCatalogProbabilityDraft(
        gachaId,
        gachaVersion.id,
        probability.id,
        probability.revision,
        pendingKey(fingerprint),
      );
      onCanonical(result.data);
    } catch (cause) {
      onError(cause);
    } finally {
      setBusy(false);
    }
  }

  async function preflight() {
    const fingerprint = JSON.stringify({
      action: "publish-preflight",
      id: probability.id,
      revision: probability.revision,
    });
    setBusy(true);
    try {
      const result = await client.preflightCatalogProbabilityPublish(
        gachaId,
        gachaVersion.id,
        probability.id,
        probability.revision,
        pendingKey(fingerprint),
      );
      onCanonical(result.data);
      setPreflightReady(result.data.validation.is_valid);
      pendingPublishAction.current = null;
    } catch (cause) {
      const error = normalizeError(cause);
      if (error.requiresFreshMfa) {
        pendingPublishAction.current = "preflight";
        setFreshMfaOpen(true);
      } else {
        onError(error);
      }
    } finally {
      setBusy(false);
    }
  }

  async function publish() {
    const fingerprint = JSON.stringify({
      action: "publish",
      id: probability.id,
      revision: probability.revision,
    });
    setBusy(true);
    try {
      const result = await client.publishCatalogProbabilityDraft(
        gachaId,
        gachaVersion.id,
        probability.id,
        probability.revision,
        pendingKey(fingerprint),
      );
      onCanonical(result.data);
      setPublishConfirmOpen(false);
      setPreflightReady(false);
      pendingPublishAction.current = null;
    } catch (cause) {
      const error = normalizeError(cause);
      if (error.requiresFreshMfa) {
        pendingPublishAction.current = "publish";
        setPublishConfirmOpen(false);
        setFreshMfaOpen(true);
      } else {
        onError(error);
      }
    } finally {
      setBusy(false);
    }
  }

  return (
    <div className="catalog-probability-layout">
      <section className="catalog-detail catalog-gacha-detail">
        <dl>
          <Detail label="Public ID" value={probability.id} />
          <Detail label="Status" value={probability.status} />
          <Detail label="Revision" value={String(probability.revision)} />
          <Detail
            label="Snapshot"
            value={
              probability.status === "published"
                ? probability.snapshot_sha256.slice(0, 12)
                : probability.snapshot_sha256
            }
          />
          <Detail label="Published At" value={probability.published_at ?? "未公開"} />
          <Detail
            label="Clone元"
            value={
              probability.cloned_from_version
                ? `v${probability.cloned_from_version.version_number}`
                : "なし"
            }
          />
        </dl>
      </section>
      <ValidationPanel probability={probability} />
      {mutable ? (
        <ProbabilityEditor
          draft={draft}
          prizes={gachaVersion.prizes.map((item) => item.prize)}
          setDraft={setDraft}
        />
      ) : (
        <section className="catalog-state">
          <CheckCircle2 size={28} aria-hidden="true" />
          <h2>Read-only Probability</h2>
          <p>PublishedまたはArchived Versionは変更できません。</p>
        </section>
      )}
      {mutable ? (
        <div className="catalog-probability-actions">
          <button
            className="primary-button"
            disabled={busy || !dirty || !isValidProbabilityDraft(draft)}
            onClick={save}
            type="button"
          >
            <Save size={16} aria-hidden="true" />
            Draft保存
          </button>
          <button
            className="secondary-button"
            disabled={busy || dirty}
            onClick={validate}
            type="button"
          >
            <CheckCircle2 size={16} aria-hidden="true" />
            Server Validation
          </button>
          {canPublish ? (
            <>
              <button
                className="secondary-button"
                disabled={busy || dirty}
                onClick={preflight}
                type="button"
              >
                <ShieldAlert size={16} aria-hidden="true" />
                Publish Preflight
              </button>
              <button
                className="primary-button"
                disabled={busy || dirty || !preflightReady}
                onClick={() => setPublishConfirmOpen(true)}
                type="button"
              >
                <Rocket size={16} aria-hidden="true" />
                Probability Publish
              </button>
            </>
          ) : null}
          <button
            className="danger-button"
            disabled={busy}
            onClick={onDiscard}
            type="button"
          >
            <Archive size={16} aria-hidden="true" />
            Discard
          </button>
        </div>
      ) : null}
      {publishConfirmOpen ? (
        <div className="dialog-backdrop" role="presentation">
          <section
            aria-labelledby="probability-publish-heading"
            aria-modal="true"
            className="dialog-panel"
            role="alertdialog"
          >
            <Rocket size={24} aria-hidden="true" />
            <h2
              id="probability-publish-heading"
              ref={publishHeading}
              tabIndex={-1}
            >
              Probabilityを公開しますか
            </h2>
            <p>
              公開後はVersion、Stage、Entry、Minimum Guaranteeを変更できません。
              Gacha Version自体は公開されません。
            </p>
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
                onClick={publish}
                type="button"
              >
                <Rocket size={16} aria-hidden="true" />
                公開
              </button>
            </div>
          </section>
        </div>
      ) : null}
      <FreshMfaDialog
        onClose={() => {
          pendingPublishAction.current = null;
          setFreshMfaOpen(false);
        }}
        onSuccess={async () => {
          setFreshMfaOpen(false);
          if (pendingPublishAction.current === "preflight") {
            await preflight();
          } else if (pendingPublishAction.current === "publish") {
            await publish();
          }
        }}
        open={freshMfaOpen}
      />
    </div>
  );
}

function ProbabilityEditor({
  draft,
  prizes,
  setDraft,
}: {
  draft: AdminCatalogProbabilityStageInput[];
  prizes: AdminCatalogGachaVersion["prizes"][number]["prize"][];
  setDraft: (draft: AdminCatalogProbabilityStageInput[]) => void;
}) {
  const updateStage = (
    stageIndex: number,
    patch: Partial<AdminCatalogProbabilityStageInput>,
  ) =>
    setDraft(
      draft.map((stage, index) =>
        index === stageIndex ? { ...stage, ...patch } : stage,
      ),
    );
  const { current: total, required } = calculateProbabilityTotals(draft);

  return (
    <section className="catalog-mutation-panel is-wide">
      <header className="catalog-probability-heading">
        <div>
          <span className="eyebrow">Draft Editor</span>
          <h2>Probability Stage／Entry</h2>
        </div>
        <button
          className="secondary-button"
          onClick={() =>
            setDraft([
              ...draft,
              {
                code: `stage-${draft.length + 1}`,
                entries: [],
                max_draw_number: null,
                min_draw_number:
                  draft.at(-1)?.max_draw_number === null
                    ? 1
                    : (draft.at(-1)?.max_draw_number ?? 0) + 1,
                minimum_guarantee: null,
                name: `Stage ${draft.length + 1}`,
              },
            ])
          }
          type="button"
        >
          <Plus size={16} aria-hidden="true" />
          Stage追加
        </button>
      </header>
      <div className="catalog-probability-total" aria-live="polite">
        <span>Current {total.toLocaleString()} ppm</span>
        <span>Required {required.toLocaleString()} ppm</span>
        <span>
          {total <= required
            ? `Remaining ${(required - total).toLocaleString()}`
            : `Excess ${(total - required).toLocaleString()}`}
        </span>
      </div>
      {draft.map((stage, stageIndex) => (
        <fieldset className="catalog-probability-stage" key={`${stage.code}:${stageIndex}`}>
          <legend>Stage {stageIndex + 1}</legend>
          <div className="catalog-form-grid">
            <label>
              Code
              <input
                maxLength={64}
                onChange={(event) =>
                  updateStage(stageIndex, { code: event.target.value })
                }
                value={stage.code}
              />
            </label>
            <label>
              Name
              <input
                maxLength={128}
                onChange={(event) =>
                  updateStage(stageIndex, { name: event.target.value })
                }
                value={stage.name}
              />
            </label>
            <IntegerField
              label="開始Draw"
              min={1}
              onChange={(value) =>
                updateStage(stageIndex, { min_draw_number: value })
              }
              value={stage.min_draw_number}
            />
            <IntegerField
              label="終了Draw（0は無期限）"
              min={0}
              onChange={(value) =>
                updateStage(stageIndex, {
                  max_draw_number: value === 0 ? null : value,
                })
              }
              value={stage.max_draw_number ?? 0}
            />
          </div>
          <div className="catalog-probability-entry-list">
            {stage.entries.map((entry, entryIndex) => (
              <TargetRow
                key={`${stageIndex}:${entryIndex}`}
                onChange={(next) =>
                  updateStage(stageIndex, {
                    entries: stage.entries.map((item, index) =>
                      index === entryIndex ? next : item,
                    ),
                  })
                }
                onRemove={() =>
                  updateStage(stageIndex, {
                    entries: stage.entries.filter((_, index) => index !== entryIndex),
                  })
                }
                prizes={prizes}
                target={entry}
              />
            ))}
          </div>
          <div className="catalog-probability-stage-actions">
            <button
              className="secondary-button"
              onClick={() =>
                updateStage(stageIndex, {
                  entries: [...stage.entries, emptyPrizeTarget(prizes[0]?.id ?? null)],
                })
              }
              type="button"
            >
              <Plus size={16} aria-hidden="true" />
              Entry追加
            </button>
            <button
              className="secondary-button"
              onClick={() =>
                updateStage(stageIndex, {
                  minimum_guarantee:
                    stage.minimum_guarantee ??
                    emptyPrizeTarget(prizes[0]?.id ?? null),
                })
              }
              type="button"
            >
              Minimum Guarantee
            </button>
            <button
              aria-label={`Stage ${stageIndex + 1}を削除`}
              className="icon-button danger-icon"
              onClick={() =>
                setDraft(draft.filter((_, index) => index !== stageIndex))
              }
              type="button"
            >
              <Trash2 size={16} aria-hidden="true" />
            </button>
          </div>
          {stage.minimum_guarantee ? (
            <div className="catalog-probability-guarantee">
              <strong>Minimum Guarantee</strong>
              <TargetRow
                onChange={(next) =>
                  updateStage(stageIndex, { minimum_guarantee: next })
                }
                onRemove={() =>
                  updateStage(stageIndex, { minimum_guarantee: null })
                }
                prizes={prizes}
                target={stage.minimum_guarantee}
              />
            </div>
          ) : null}
        </fieldset>
      ))}
    </section>
  );
}

function TargetRow({
  onChange,
  onRemove,
  prizes,
  target,
}: {
  onChange: (target: AdminCatalogProbabilityTargetInput) => void;
  onRemove: () => void;
  prizes: AdminCatalogGachaVersion["prizes"][number]["prize"][];
  target: AdminCatalogProbabilityTargetInput;
}) {
  return (
    <div className="catalog-probability-entry">
      <label>
        Result
        <select
          onChange={(event) =>
            onChange(
              event.target.value === "prize"
                ? emptyPrizeTarget(prizes[0]?.id ?? null)
                : {
                    point_amount: 0,
                    prize_id: null,
                    probability_ppm: target.probability_ppm,
                    result_type: "point_back",
                  },
            )
          }
          value={target.result_type}
        >
          <option value="prize">Prize</option>
          <option value="point_back">Point Back</option>
        </select>
      </label>
      {target.result_type === "prize" ? (
        <label>
          Prize
          <select
            onChange={(event) => onChange({ ...target, prize_id: event.target.value })}
            value={target.prize_id ?? ""}
          >
            <option value="">選択してください</option>
            {prizes.map((prize) => (
              <option key={prize.id} value={prize.id}>
                {prize.rank.code} / {prize.name}
              </option>
            ))}
          </select>
        </label>
      ) : (
        <IntegerField
          label="Point Back"
          min={0}
          onChange={(value) => onChange({ ...target, point_amount: value })}
          value={target.point_amount ?? 0}
        />
      )}
      <IntegerField
        label="ppm"
        max={1_000_000}
        min={0}
        onChange={(value) => onChange({ ...target, probability_ppm: value })}
        value={target.probability_ppm}
      />
      <button
        aria-label="Entryを削除"
        className="icon-button danger-icon"
        onClick={onRemove}
        type="button"
      >
        <Trash2 size={16} aria-hidden="true" />
      </button>
    </div>
  );
}

function IntegerField({
  label,
  max,
  min,
  onChange,
  value,
}: {
  label: string;
  max?: number;
  min: number;
  onChange: (value: number) => void;
  value: number;
}) {
  return (
    <label>
      {label}
      <input
        inputMode="numeric"
        max={max}
        min={min}
        onChange={(event) => {
          const parsed = Number(event.target.value);
          if (Number.isSafeInteger(parsed)) onChange(parsed);
        }}
        step={1}
        type="number"
        value={value}
      />
    </label>
  );
}

function ValidationPanel({
  probability,
}: {
  probability: AdminCatalogProbabilityVersion;
}) {
  const validation = probability.validation;
  return (
    <section
      className={`catalog-probability-validation ${validation.is_valid ? "is-valid" : "is-invalid"}`}
      role="status"
    >
      <div>
        {validation.is_valid ? (
          <CheckCircle2 size={20} aria-hidden="true" />
        ) : (
          <ShieldAlert size={20} aria-hidden="true" />
        )}
        <strong>{validation.is_valid ? "Validation passed" : "Validation required"}</strong>
      </div>
      <p>
        Current {validation.current_total_ppm.toLocaleString()} / Required{" "}
        {validation.required_total_ppm.toLocaleString()} ppm
      </p>
      {validation.errors.length > 0 ? (
        <ul>
          {validation.errors.map((error) => (
            <li key={error}>{error}</li>
          ))}
        </ul>
      ) : null}
    </section>
  );
}

function Detail({ label, value }: { label: string; value: string }) {
  return (
    <div>
      <dt>{label}</dt>
      <dd>{value}</dd>
    </div>
  );
}

function LoadingState() {
  return (
    <section className="catalog-state" role="status">
      <LoaderCircle className="spin" size={28} aria-hidden="true" />
      <h2>読み込んでいます</h2>
    </section>
  );
}

async function loadProbabilityState(
  client: AdminApiClient,
  gachaId: string,
  gachaVersionId: string,
  probabilityVersionId: string | undefined,
  cursor: string | null,
  signal: AbortSignal,
): Promise<ViewState> {
  const gachaVersion = (
    await client.getCatalogGachaVersion(gachaId, gachaVersionId, signal)
  ).data;
  if (probabilityVersionId) {
    const probability = (
      await client.getCatalogProbabilityVersion(
        gachaId,
        gachaVersionId,
        probabilityVersionId,
        signal,
      )
    ).data;
    return { gachaVersion, kind: "detail", probability };
  }
  const response = await client.listCatalogProbabilityVersions(
    gachaId,
    gachaVersionId,
    {
      archive: "all",
      cursor: cursor ?? undefined,
      direction: "desc",
      limit: 20,
      status: "all",
    },
    signal,
  );
  return {
    gachaVersion,
    items: response.items,
    kind: "list",
    nextCursor: response.next_cursor,
  };
}

export function toProbabilityDraft(
  probability: AdminCatalogProbabilityVersion,
): AdminCatalogProbabilityStageInput[] {
  const target = (
    value: AdminCatalogProbabilityVersion["stages"][number]["entries"][number],
  ): AdminCatalogProbabilityTargetInput => ({
    point_amount: value.point_amount,
    prize_id: value.prize?.id ?? null,
    probability_ppm: value.probability_ppm,
    result_type: value.result_type,
  });
  return probability.stages.map((stage) => ({
    code: stage.code,
    entries: stage.entries.map(target),
    max_draw_number: stage.max_draw_number,
    min_draw_number: stage.min_draw_number,
    minimum_guarantee: stage.minimum_guarantee
      ? target({ ...stage.minimum_guarantee, sort_order: 0 })
      : null,
    name: stage.name,
  }));
}

export function isValidProbabilityDraft(
  draft: AdminCatalogProbabilityStageInput[],
): boolean {
  const codes = new Set<string>();
  return draft.every((stage, stageIndex) => {
    if (
      !/^[a-z][a-z0-9_-]{0,63}$/.test(stage.code) ||
      stage.name.trim() === "" ||
      codes.has(stage.code) ||
      !Number.isSafeInteger(stage.min_draw_number) ||
      stage.min_draw_number < 1 ||
      (stage.max_draw_number !== null &&
        (!Number.isSafeInteger(stage.max_draw_number) ||
          stage.max_draw_number < stage.min_draw_number)) ||
      (stageIndex === 0 && stage.min_draw_number !== 1)
    ) {
      return false;
    }
    codes.add(stage.code);
    const prizeIds = new Set<string>();
    return [...stage.entries, ...(stage.minimum_guarantee ? [stage.minimum_guarantee] : [])]
      .every((target, index) => {
        const validPpm =
          Number.isSafeInteger(target.probability_ppm) &&
          target.probability_ppm >= 0 &&
          target.probability_ppm <= 1_000_000;
        if (!validPpm) return false;
        if (target.result_type === "point_back") {
          return (
            target.prize_id === null &&
            Number.isSafeInteger(target.point_amount) &&
            (target.point_amount ?? -1) >= 0
          );
        }
        if (!target.prize_id || target.point_amount !== null) return false;
        if (index < stage.entries.length && prizeIds.has(target.prize_id)) return false;
        if (index < stage.entries.length) prizeIds.add(target.prize_id);
        return true;
      });
  });
}

export function calculateProbabilityTotals(
  draft: AdminCatalogProbabilityStageInput[],
): { current: number; required: number } {
  return {
    current: draft.reduce(
      (sum, stage) =>
        sum +
        stage.entries.reduce(
          (value, entry) => value + entry.probability_ppm,
          0,
        ) +
        (stage.minimum_guarantee?.probability_ppm ?? 0),
      0,
    ),
    required: draft.length * 1_000_000,
  };
}

export function isMutableProbabilityVersion(
  probability: Pick<
    AdminCatalogProbabilityVersion,
    "is_archived" | "status"
  >,
): boolean {
  return probability.status === "draft" && !probability.is_archived;
}

function emptyPrizeTarget(
  prizeId: string | null,
): AdminCatalogProbabilityTargetInput {
  return {
    point_amount: null,
    prize_id: prizeId,
    probability_ppm: 0,
    result_type: "prize",
  };
}

function normalizeError(cause: unknown): AdminApiError {
  return cause instanceof AdminApiError
    ? cause
    : new AdminApiError(0, "NETWORK_ERROR", null, null, true);
}
