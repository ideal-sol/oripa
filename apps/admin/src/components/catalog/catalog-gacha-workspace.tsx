"use client";

import {
  Archive,
  Copy,
  Database,
  LoaderCircle,
  Pencil,
  Plus,
} from "lucide-react";
import Link from "next/link";
import { useEffect, useMemo, useRef, useState } from "react";

import { useAdminAuth } from "@/components/auth/admin-auth-provider";
import { CatalogApiErrorBoundary } from "@/components/catalog/catalog-api-error-boundary";
import { CatalogBreadcrumb } from "@/components/catalog/catalog-breadcrumb";
import { CatalogConfirmationDialog } from "@/components/catalog/catalog-confirmation-dialog";
import { CatalogConflictBoundary } from "@/components/catalog/catalog-conflict-boundary";
import {
  CatalogGachaMasterForm,
  CatalogGachaVersionForm,
  type GachaMasterDraft,
  type GachaVersionDraft,
} from "@/components/catalog/catalog-gacha-forms";
import { CatalogSectionNavigation } from "@/components/catalog/catalog-section-navigation";
import { CursorPagination } from "@/components/catalog/cursor-pagination";
import { StatusBadge } from "@/components/catalog/status-badge";
import { ProtectedAdminRoute } from "@/components/permissions/protected-admin-route";
import { usePermissions } from "@/components/permissions/permission-provider";
import { AdminPageHeader } from "@/components/shell/admin-page-header";
import { AdminShell } from "@/components/shell/admin-shell";
import {
  AdminApiClient,
  AdminApiError,
  type AdminCatalogQuery,
} from "@/lib/admin-api/client";
import type {
  AdminCatalogGacha,
  AdminCatalogGachaVersion,
} from "@/lib/admin-api/generated";
import { catalogSection } from "@/lib/catalog/catalog-registry";

type ViewState =
  | { kind: "loading" }
  | { kind: "error"; error: AdminApiError }
  | {
      kind: "list";
      items: AdminCatalogGacha[];
      nextCursor: string | null;
    }
  | {
      kind: "gacha";
      gacha: AdminCatalogGacha;
      versions: AdminCatalogGachaVersion[];
      versionsNextCursor: string | null;
    }
  | {
      kind: "version";
      gacha: AdminCatalogGacha;
      version: AdminCatalogGachaVersion;
    };

type FormMode = "create-master" | "edit-master" | "create-version" | "edit-version";
type ConfirmMode = "archive-master" | "discard-version";

export function CatalogGachaWorkspace({
  gachaId,
  versionId,
}: {
  gachaId?: string;
  versionId?: string;
}) {
  const client = useMemo(() => new AdminApiClient(), []);
  const section = catalogSection("gachas");
  if (!section) throw new Error("Gacha section is unavailable.");
  const { expireSession } = useAdminAuth();
  const { hasPermission } = usePermissions();
  const [state, setState] = useState<ViewState>({ kind: "loading" });
  const [query, setQuery] = useState("");
  const [cursorHistory, setCursorHistory] = useState<(string | null)[]>([null]);
  const [cursorIndex, setCursorIndex] = useState(0);
  const [versionCursorHistory, setVersionCursorHistory] = useState<(string | null)[]>([
    null,
  ]);
  const [versionCursorIndex, setVersionCursorIndex] = useState(0);
  const [reload, setReload] = useState(0);
  const [formMode, setFormMode] = useState<FormMode | null>(null);
  const [confirmMode, setConfirmMode] = useState<ConfirmMode | null>(null);
  const [busy, setBusy] = useState(false);
  const [mutationError, setMutationError] = useState<AdminApiError | null>(null);
  const pendingMutation = useRef<{ fingerprint: string; key: string } | null>(null);
  const canManage = hasPermission("catalog.manage");
  const cursor = cursorHistory[cursorIndex] ?? null;
  const versionCursor = versionCursorHistory[versionCursorIndex] ?? null;

  useEffect(() => {
    const controller = new AbortController();
    loadState(
      client,
      { cursor: cursor ?? undefined, direction: "desc", limit: 20, q: query || undefined },
      controller.signal,
      gachaId,
      versionId,
      versionCursor,
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
    query,
    reload,
    versionCursor,
    versionId,
  ]);

  const currentGacha =
    state.kind === "gacha" || state.kind === "version" ? state.gacha : null;
  const currentVersion = state.kind === "version" ? state.version : null;
  const title =
    state.kind === "version"
      ? `${state.gacha.code} / Version ${state.version.version_number}`
      : currentGacha?.code ?? "Gacha";

  async function submitMaster(draft: GachaMasterDraft) {
    const fingerprint = JSON.stringify({
      action: formMode,
      draft,
      id: currentGacha?.id ?? null,
      revision: currentGacha?.revision ?? null,
    });
    const key = mutationKey(fingerprint);
    try {
      const result =
        formMode === "create-master"
          ? await client.createCatalogGacha(
              {
                category_id: draft.categoryId,
                code: draft.code,
                slug: draft.slug,
                tag_ids: draft.tagIds,
              },
              key,
            )
          : await client.updateCatalogGacha(
              currentGacha!.id,
              {
                category_id: draft.categoryId,
                expected_revision: currentGacha!.revision,
                tag_ids: draft.tagIds,
              },
              key,
            );
      pendingMutation.current = null;
      setMutationError(null);
      setFormMode(null);
      if (state.kind === "gacha" || state.kind === "version") {
        setState({ ...state, gacha: result.data });
      } else {
        setReload((value) => value + 1);
      }
    } catch (cause) {
      handleMutationError(cause);
      throw cause;
    }
  }

  async function submitVersion(draft: GachaVersionDraft) {
    const fingerprint = JSON.stringify({
      action: formMode,
      draft,
      gachaId: currentGacha?.id,
      revision: currentVersion?.revision ?? null,
      versionId: currentVersion?.id ?? null,
    });
    const key = mutationKey(fingerprint);
    const body = {
      description: draft.description,
      notices: draft.notices,
      presentation_asset_id: draft.presentationAssetId,
      price_points: draft.pricePoints,
      prizes: draft.prizes.map((item) => ({
        initial_inventory: item.initialInventory,
        prize_id: item.prizeId,
        sort_order: item.sortOrder,
      })),
      publish_end_at: draft.publishEndAt,
      publish_start_at: draft.publishStartAt,
      title: draft.title,
      total_count: draft.totalCount,
    };
    try {
      const result =
        formMode === "create-version"
          ? await client.createCatalogGachaDraft(currentGacha!.id, body, key)
          : await client.updateCatalogGachaDraft(
              currentGacha!.id,
              currentVersion!.id,
              { expected_revision: currentVersion!.revision, ...body },
              key,
            );
      pendingMutation.current = null;
      setMutationError(null);
      setFormMode(null);
      if (state.kind === "version") {
        setState({ ...state, version: result.data });
      } else {
        setReload((value) => value + 1);
      }
    } catch (cause) {
      handleMutationError(cause);
      throw cause;
    }
  }

  async function cloneVersion(version: AdminCatalogGachaVersion) {
    if (!currentGacha || !window.confirm(`Version ${version.version_number}をDraft Cloneしますか。`)) {
      return;
    }
    const fingerprint = JSON.stringify({
      action: "clone-version",
      gachaId: currentGacha.id,
      versionId: version.id,
    });
    setBusy(true);
    try {
      await client.cloneCatalogGachaDraft(
        currentGacha.id,
        version.id,
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

  async function confirmArchive() {
    if (!currentGacha || !confirmMode) return;
    const target = confirmMode === "discard-version" ? currentVersion : currentGacha;
    if (!target) return;
    const fingerprint = JSON.stringify({
      action: confirmMode,
      gachaId: currentGacha.id,
      id: target.id,
      revision: target.revision,
    });
    setBusy(true);
    try {
      if (confirmMode === "archive-master") {
        const result = await client.archiveCatalogGacha(
          currentGacha.id,
          currentGacha.revision,
          mutationKey(fingerprint),
        );
        setState(
          state.kind === "version"
            ? { ...state, gacha: result.data }
            : {
                kind: "gacha",
                gacha: result.data,
                versions: state.kind === "gacha" ? state.versions : [],
                versionsNextCursor:
                  state.kind === "gacha" ? state.versionsNextCursor : null,
              },
        );
      } else {
        const result = await client.discardCatalogGachaDraft(
          currentGacha.id,
          currentVersion!.id,
          currentVersion!.revision,
          mutationKey(fingerprint),
        );
        if (state.kind === "version") setState({ ...state, version: result.data });
      }
      pendingMutation.current = null;
      setMutationError(null);
      setConfirmMode(null);
    } catch (cause) {
      handleMutationError(cause);
      setConfirmMode(null);
    } finally {
      setBusy(false);
    }
  }

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
    if ([401, 403, 409, 412, 429].includes(error.status)) setFormMode(null);
  }

  return (
    <AdminShell>
      <ProtectedAdminRoute permission="catalog.read">
        <div className="workspace">
          <CatalogBreadcrumb detail={gachaId ? title : undefined} section={section} />
          <AdminPageHeader
            description={
              state.kind === "version"
                ? "Published Versionは参照専用です。Probabilityや公開操作は行いません。"
                : "Gacha MasterとDraft Versionを管理します。"
            }
            eyebrow="Catalog"
            title={title}
            action={
              canManage ? (
                <HeaderActions
                  currentGacha={currentGacha}
                  currentVersion={currentVersion}
                  disabled={busy}
                  onArchiveMaster={() => setConfirmMode("archive-master")}
                  onClone={() => currentVersion && cloneVersion(currentVersion)}
                  onCreateMaster={() => setFormMode("create-master")}
                  onCreateVersion={() => setFormMode("create-version")}
                  onDiscardVersion={() => setConfirmMode("discard-version")}
                  onEditMaster={() => setFormMode("edit-master")}
                  onEditVersion={() => setFormMode("edit-version")}
                  state={state.kind}
                />
              ) : undefined
            }
          />
          <CatalogSectionNavigation active="gachas" />
          {!gachaId ? (
            <form
              className="catalog-toolbar"
              onSubmit={(event) => {
                event.preventDefault();
                setCursorHistory([null]);
                setCursorIndex(0);
                setReload((value) => value + 1);
              }}
            >
              <label className="catalog-gacha-search">
                Gacha検索
                <input
                  maxLength={191}
                  onChange={(event) => setQuery(event.target.value)}
                  placeholder="Code、Slug、Category"
                  value={query}
                />
              </label>
              <button className="secondary-button" type="submit">
                検索
              </button>
            </form>
          ) : null}
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
            <GachaList
              canGoBack={cursorIndex > 0}
              items={state.items}
              nextCursor={state.nextCursor}
              onBack={() => setCursorIndex((value) => Math.max(0, value - 1))}
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
          {state.kind === "gacha" ? (
            <GachaDetail
              canGoBack={versionCursorIndex > 0}
              gacha={state.gacha}
              nextCursor={state.versionsNextCursor}
              onBack={() =>
                setVersionCursorIndex((value) => Math.max(0, value - 1))
              }
              onClone={cloneVersion}
              onNext={() => {
                if (!state.versionsNextCursor) return;
                setVersionCursorHistory((history) => [
                  ...history.slice(0, versionCursorIndex + 1),
                  state.versionsNextCursor,
                ]);
                setVersionCursorIndex((value) => value + 1);
              }}
              versions={state.versions}
            />
          ) : null}
          {state.kind === "version" ? (
            <VersionDetail gachaId={state.gacha.id} version={state.version} />
          ) : null}
          {formMode === "create-master" || formMode === "edit-master" ? (
            <CatalogGachaMasterForm
              current={formMode === "edit-master" ? currentGacha ?? undefined : undefined}
              mode={formMode === "create-master" ? "create" : "edit"}
              onCancel={() => setFormMode(null)}
              onSubmit={submitMaster}
            />
          ) : null}
          {formMode === "create-version" || formMode === "edit-version" ? (
            <CatalogGachaVersionForm
              current={formMode === "edit-version" ? currentVersion ?? undefined : undefined}
              mode={formMode === "create-version" ? "create" : "edit"}
              onCancel={() => setFormMode(null)}
              onSubmit={submitVersion}
            />
          ) : null}
          {confirmMode && currentGacha ? (
            <CatalogConfirmationDialog
              busy={busy}
              name={
                confirmMode === "archive-master"
                  ? currentGacha.code
                  : `Version ${currentVersion?.version_number ?? ""}`
              }
              onCancel={() => setConfirmMode(null)}
              onConfirm={confirmArchive}
            />
          ) : null}
        </div>
      </ProtectedAdminRoute>
    </AdminShell>
  );
}

function HeaderActions({
  currentGacha,
  currentVersion,
  disabled,
  onArchiveMaster,
  onClone,
  onCreateMaster,
  onCreateVersion,
  onDiscardVersion,
  onEditMaster,
  onEditVersion,
  state,
}: {
  currentGacha: AdminCatalogGacha | null;
  currentVersion: AdminCatalogGachaVersion | null;
  disabled: boolean;
  onArchiveMaster: () => void;
  onClone: () => void;
  onCreateMaster: () => void;
  onCreateVersion: () => void;
  onDiscardVersion: () => void;
  onEditMaster: () => void;
  onEditVersion: () => void;
  state: ViewState["kind"];
}) {
  if (state === "list") {
    return (
      <button className="primary-button" onClick={onCreateMaster} type="button">
        <Plus size={16} aria-hidden="true" />
        新規作成
      </button>
    );
  }
  if (!currentGacha || currentGacha.is_archived) return undefined;
  if (state === "gacha") {
    return (
      <div className="catalog-header-actions">
        <button className="secondary-button" onClick={onEditMaster} type="button">
          <Pencil size={16} aria-hidden="true" />
          Master編集
        </button>
        <button className="primary-button" onClick={onCreateVersion} type="button">
          <Plus size={16} aria-hidden="true" />
          Draft作成
        </button>
        <button className="danger-button" onClick={onArchiveMaster} type="button">
          <Archive size={16} aria-hidden="true" />
          Archive
        </button>
      </div>
    );
  }
  if (!currentVersion) return undefined;
  const mutable = isEditableGachaVersion(currentVersion);
  return (
    <div className="catalog-header-actions">
      {mutable ? (
        <button className="secondary-button" onClick={onEditVersion} type="button">
          <Pencil size={16} aria-hidden="true" />
          Draft編集
        </button>
      ) : null}
      <button className="secondary-button" disabled={disabled} onClick={onClone} type="button">
        <Copy size={16} aria-hidden="true" />
        Clone
      </button>
      {mutable ? (
        <button className="danger-button" onClick={onDiscardVersion} type="button">
          <Archive size={16} aria-hidden="true" />
          Discard
        </button>
      ) : null}
    </div>
  );
}

function GachaList({
  canGoBack,
  items,
  nextCursor,
  onBack,
  onNext,
}: {
  canGoBack: boolean;
  items: AdminCatalogGacha[];
  nextCursor: string | null;
  onBack: () => void;
  onNext: () => void;
}) {
  if (items.length === 0) {
    return (
      <section className="catalog-state">
        <Database size={28} aria-hidden="true" />
        <h2>該当するGachaはありません</h2>
        <p>検索条件を変更してください。</p>
      </section>
    );
  }
  return (
    <>
      <div className="catalog-table-wrap">
        <table className="catalog-table">
          <thead>
            <tr>
              <th>Code</th>
              <th>Category／Tag</th>
              <th>状態</th>
              <th>Version</th>
              <th>販売口数</th>
              <th>詳細</th>
            </tr>
          </thead>
          <tbody>
            {items.map((gacha) => (
              <tr key={gacha.id}>
                <td>
                  <strong>{gacha.code}</strong>
                  <small>{gacha.slug}</small>
                </td>
                <td>
                  <strong>{gacha.category.name}</strong>
                  <small>{gacha.tags.map((tag) => tag.name).join(", ") || "Tagなし"}</small>
                </td>
                <td>
                  <StatusBadge
                    archived={gacha.is_archived}
                    visible={gacha.state === "active"}
                  />
                </td>
                <td>{gacha.version_count}</td>
                <td>{gacha.sold_count.toLocaleString()}</td>
                <td>
                  <Link className="table-link" href={`/catalog/gachas/${gacha.id}`}>
                    開く
                  </Link>
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

function GachaDetail({
  canGoBack,
  gacha,
  nextCursor,
  onBack,
  onClone,
  onNext,
  versions,
}: {
  canGoBack: boolean;
  gacha: AdminCatalogGacha;
  nextCursor: string | null;
  onBack: () => void;
  onClone: (version: AdminCatalogGachaVersion) => void;
  onNext: () => void;
  versions: AdminCatalogGachaVersion[];
}) {
  return (
    <>
      <section className="catalog-detail catalog-gacha-detail">
        <dl>
          <Detail label="Public ID" value={gacha.id} />
          <Detail label="Code" value={gacha.code} />
          <Detail label="Slug" value={gacha.slug} />
          <Detail label="Category" value={gacha.category.name} />
          <Detail label="Tag" value={gacha.tags.map((tag) => tag.name).join(", ") || "なし"} />
          <Detail label="State" value={gacha.state} />
          <Detail label="Revision" value={String(gacha.revision)} />
          <Detail label="Draw履歴" value={gacha.has_draw_history ? "あり" : "なし"} />
          <Detail label="Archive日時" value={gacha.archived_at ?? "未Archive"} />
        </dl>
      </section>
      <section className="catalog-version-section">
        <header>
          <div>
            <span className="eyebrow">Versions</span>
            <h2>Draft／Published Version</h2>
          </div>
        </header>
        {versions.length === 0 ? (
          <p className="catalog-version-empty">Versionはありません。</p>
        ) : (
          <div className="catalog-table-wrap">
            <table className="catalog-table">
              <thead>
                <tr>
                  <th>Version</th>
                  <th>Title</th>
                  <th>Status</th>
                  <th>販売Point／口数</th>
                  <th>操作</th>
                </tr>
              </thead>
              <tbody>
                {versions.map((version) => (
                  <tr key={version.id}>
                    <td>v{version.version_number}</td>
                    <td>{version.title}</td>
                    <td>{version.is_archived ? "archived" : version.status}</td>
                    <td>
                      {version.price_points.toLocaleString()} /{" "}
                      {version.total_count.toLocaleString()}
                    </td>
                    <td>
                      <div className="catalog-table-actions">
                        <Link
                          className="table-link"
                          href={`/catalog/gachas/${gacha.id}/versions/${version.id}`}
                        >
                          詳細
                        </Link>
                        <button
                          className="icon-button"
                          onClick={() => onClone(version)}
                          title="Draft Clone"
                          type="button"
                        >
                          <Copy size={16} aria-hidden="true" />
                        </button>
                      </div>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
        <CursorPagination
          canGoBack={canGoBack}
          canGoNext={nextCursor !== null}
          onBack={onBack}
          onNext={onNext}
        />
      </section>
    </>
  );
}

function VersionDetail({
  gachaId,
  version,
}: {
  gachaId: string;
  version: AdminCatalogGachaVersion;
}) {
  return (
    <section className="catalog-detail catalog-gacha-detail">
      <dl>
        <Detail label="Public ID" value={version.id} />
        <Detail label="Version" value={String(version.version_number)} />
        <Detail label="Status" value={version.is_archived ? "archived" : version.status} />
        <Detail label="Title" value={version.title} />
        <Detail label="Description" value={version.description ?? "未設定"} />
        <Detail label="Notice" value={version.notices ?? "未設定"} />
        <Detail label="販売Point" value={version.price_points.toLocaleString()} />
        <Detail label="販売口数" value={version.total_count.toLocaleString()} />
        <Detail label="公開開始" value={version.publish_start_at} />
        <Detail label="公開終了" value={version.publish_end_at ?? "無期限"} />
        <Detail
          label="Presentation Asset"
          value={version.presentation_asset?.alt_text ?? "未設定"}
        />
        <Detail
          label="Probability"
          value={
            version.published_probability_version
              ? `v${version.published_probability_version.version_number}（参照専用）`
              : "未設定"
          }
        />
        <Detail
          label="Prize"
          value={version.prizes
            .map(
              (item) =>
                `${item.prize.rank.code} ${item.prize.name} × ${item.initial_inventory}`,
            )
            .join(" / ")}
        />
        <Detail label="Revision" value={String(version.revision)} />
      </dl>
      <Link
        className="secondary-button catalog-probability-link"
        href={`/catalog/gachas/${gachaId}/versions/${version.id}/probability-versions`}
      >
        Probability Editor
      </Link>
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

async function loadState(
  client: AdminApiClient,
  query: AdminCatalogQuery,
  signal: AbortSignal,
  gachaId?: string,
  versionId?: string,
  versionCursor?: string | null,
): Promise<ViewState> {
  if (!gachaId) {
    const response = await client.listCatalogGachas(query, signal);
    return { kind: "list", items: response.items, nextCursor: response.next_cursor };
  }
  const gacha = (await client.getCatalogGacha(gachaId, signal)).data;
  if (versionId) {
    const version = (
      await client.getCatalogGachaVersion(gachaId, versionId, signal)
    ).data;
    return { kind: "version", gacha, version };
  }
  const versions = await client.listCatalogGachaVersions(
    gachaId,
    {
      archive: "all",
      cursor: versionCursor ?? undefined,
      direction: "desc",
      limit: 20,
      status: "all",
    },
    signal,
  );
  return {
    kind: "gacha",
    gacha,
    versions: versions.items,
    versionsNextCursor: versions.next_cursor,
  };
}

function normalizeError(cause: unknown): AdminApiError {
  return cause instanceof AdminApiError
    ? cause
    : new AdminApiError(0, "NETWORK_ERROR", null, null, true);
}

export function isEditableGachaVersion(
  version: Pick<AdminCatalogGachaVersion, "status" | "is_archived">,
): boolean {
  return version.status === "draft" && !version.is_archived;
}
