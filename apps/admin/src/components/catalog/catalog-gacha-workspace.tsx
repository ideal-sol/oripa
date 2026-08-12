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
import { useRouter } from "next/navigation";
import { useEffect, useMemo, useRef, useState } from "react";

import { useAdminAuth } from "@/components/auth/admin-auth-provider";
import { FreshMfaDialog } from "@/components/auth/fresh-mfa-dialog";
import { CatalogApiErrorBoundary } from "@/components/catalog/catalog-api-error-boundary";
import { CatalogBreadcrumb } from "@/components/catalog/catalog-breadcrumb";
import { CatalogConfirmationDialog } from "@/components/catalog/catalog-confirmation-dialog";
import { CatalogConflictBoundary } from "@/components/catalog/catalog-conflict-boundary";
import {
  CatalogGachaCoreForm,
  CatalogGachaVersionForm,
  type GachaCoreDraft,
  type GachaVersionDraft,
} from "@/components/catalog/catalog-gacha-forms";
import { GachaPublishPreflightPanel } from "@/components/catalog/gacha-publish-preflight-panel";
import { CatalogGachaRankPrizeManager } from "@/components/catalog/catalog-gacha-rank-prize-manager";
import { CatalogSectionNavigation } from "@/components/catalog/catalog-section-navigation";
import { CursorPagination } from "@/components/catalog/cursor-pagination";
import { PublicAssetPreview } from "@/components/catalog/public-asset-preview";
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

type FormMode = "create-version" | "edit-version";
type ConfirmMode = "archive-master" | "discard-version";

export function CatalogGachaWorkspace({
  createMode = false,
  editMode = false,
  gachaId,
  versionId,
}: {
  createMode?: boolean;
  editMode?: boolean;
  gachaId?: string;
  versionId?: string;
}) {
  const router = useRouter();
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
  const [pendingCoreDraft, setPendingCoreDraft] = useState<GachaCoreDraft | null>(null);
  const [freshMfaOpen, setFreshMfaOpen] = useState(false);
  const pendingMutation = useRef<{
    fingerprint: string;
    key: string;
    uploadKey?: string;
  } | null>(null);
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
      : currentGacha?.current_version?.title ?? currentGacha?.code ?? "ガチャ管理";

  async function submitCore(draft: GachaCoreDraft) {
    const fingerprint = JSON.stringify({
      action: editMode ? "edit-gacha-core" : "create-gacha-core",
      draft: { ...draft, thumbnailFile: draft.thumbnailFile ? {
        name: draft.thumbnailFile.name,
        size: draft.thumbnailFile.size,
        type: draft.thumbnailFile.type,
      } : null },
      id: currentGacha ? gachaIdentifier(currentGacha) : null,
      revision: currentGacha?.revision ?? null,
    });
    const key = mutationKey(fingerprint);
    const uploadKey = mutationUploadKey(fingerprint);
    try {
      let presentationAssetId = draft.presentationAssetId;
      if (draft.thumbnailFile) {
        const upload = await client.uploadGachaThumbnail(
          {
            content_base64: await fileToBase64(draft.thumbnailFile),
            file_name: draft.thumbnailFile.name,
            mime_type: draft.thumbnailFile.type as "image/gif" | "image/jpeg" | "image/png" | "image/webp",
          },
          uploadKey,
        );
        presentationAssetId = upload.data.id;
      }
      if (!presentationAssetId) throw new Error("Gacha thumbnail is required.");
      const body = {
        audience_code: draft.audienceCode,
        category_id: draft.categoryId,
        daily_draw_limit: draft.dailyDrawLimit,
        first_time_eligible_days: draft.firstTimeEligibleDays,
        description: draft.description,
        notices: draft.notices,
        presentation_asset_id: presentationAssetId,
        price_points: draft.pricePoints,
        publish_end_at: draft.publishEndAt,
        publish_start_at: draft.publishStartAt,
        tag_ids: draft.tagIds,
        title: draft.title,
        total_count: draft.totalCount,
      };
      const editBody = editMode && draft.managementStatus !== currentGacha?.publication_status
        ? { ...body, management_status: draft.managementStatus }
        : body;
      const versionRevision = editMode ? requireCoreVersionRevision(currentGacha) : null;
      const result = editMode
        ? await client.updateCatalogGacha(
            gachaIdentifier(currentGacha!),
            {
              ...editBody,
              expected_revision: currentGacha!.revision,
              expected_version_revision: versionRevision!,
            },
            key,
          )
        : await client.createCatalogGachaCore(body, key);
      pendingMutation.current = null;
      setMutationError(null);
      router.push(`/catalog/gachas/${gachaIdentifier(result.data)}`);
    } catch (cause) {
      const error = normalizeError(cause);
      if (error.requiresFreshMfa) {
        setPendingCoreDraft(draft);
        setFreshMfaOpen(true);
        setMutationError(null);
        return;
      }
      handleMutationError(cause);
      throw cause;
    }
  }

  async function submitVersion(draft: GachaVersionDraft) {
    const fingerprint = JSON.stringify({
      action: formMode,
      draft,
      gachaId: currentGacha ? gachaIdentifier(currentGacha) : undefined,
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
          ? await client.createCatalogGachaDraft(gachaIdentifier(currentGacha!), body, key)
          : await client.updateCatalogGachaDraft(
              gachaIdentifier(currentGacha!),
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
      gachaId: gachaIdentifier(currentGacha),
      versionId: version.id,
    });
    setBusy(true);
    try {
      await client.cloneCatalogGachaDraft(
        gachaIdentifier(currentGacha),
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
      gachaId: gachaIdentifier(currentGacha),
      id: target.id,
      revision: target.revision,
    });
    setBusy(true);
    try {
      if (confirmMode === "archive-master") {
        const result = await client.archiveCatalogGacha(
          gachaIdentifier(currentGacha),
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
          gachaIdentifier(currentGacha),
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

  function mutationUploadKey(fingerprint: string): string {
    mutationKey(fingerprint);
    if (!pendingMutation.current!.uploadKey) {
      pendingMutation.current!.uploadKey = crypto.randomUUID();
    }
    return pendingMutation.current!.uploadKey;
  }

  function handleMutationError(cause: unknown) {
    const error = normalizeError(cause);
    if (!error.retryable) pendingMutation.current = null;
    if (error.isSessionExpired) expireSession();
    setMutationError(error);
    if ([401, 403, 409, 412, 429].includes(error.status)) setFormMode(null);
  }

  if (createMode) {
    return (
      <AdminShell>
        <ProtectedAdminRoute permission="catalog.manage">
          <div className="workspace">
            <CatalogBreadcrumb detail="登録" section={section} />
            <AdminPageHeader eyebrow="Catalog" title="ガチャ登録" description="Gacha Masterと初期Draft Versionを一度に作成します。" />
            <CatalogGachaCoreForm onCancel={() => router.push("/catalog/gachas")} onSubmit={submitCore} />
          </div>
        </ProtectedAdminRoute>
      </AdminShell>
    );
  }

  if (editMode) {
    return (
      <AdminShell>
        <ProtectedAdminRoute permission="catalog.manage">
          <div className="workspace">
            <CatalogBreadcrumb detail="Master編集" section={section} />
            <AdminPageHeader
              description="全基本項目を編集Draftへ保存します。公開済みVersionは変更しません。"
              eyebrow="Catalog"
              title="ガチャ編集"
            />
            {state.kind === "loading" ? <LoadingState /> : null}
            {state.kind === "error" ? (
              <CatalogApiErrorBoundary
                error={state.error}
                retry={() => setReload((value) => value + 1)}
              />
            ) : null}
            {state.kind === "gacha" ? (
              <CatalogGachaCoreForm
                current={state.gacha}
                mode="edit"
                onCancel={() => router.push(`/catalog/gachas/${gachaIdentifier(state.gacha)}`)}
                onSubmit={submitCore}
              />
            ) : null}
            {mutationError ? (
              <CatalogApiErrorBoundary
                error={mutationError}
                retry={() => setMutationError(null)}
              />
            ) : null}
            <FreshMfaDialog
              onClose={() => {
                setFreshMfaOpen(false);
                setPendingCoreDraft(null);
              }}
              onSuccess={async () => {
                setFreshMfaOpen(false);
                const retryDraft = pendingCoreDraft;
                setPendingCoreDraft(null);
                if (retryDraft) await submitCore(retryDraft);
              }}
              open={freshMfaOpen}
            />
          </div>
        </ProtectedAdminRoute>
      </AdminShell>
    );
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
                  onDiscardVersion={() => setConfirmMode("discard-version")}
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
              canManage={canManage}
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
            <VersionDetail
              gachaId={gachaIdentifier(state.gacha)}
              onCanonical={(version) => setState({ ...state, version })}
              version={state.version}
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
  onDiscardVersion,
  onEditVersion,
  state,
}: {
  currentGacha: AdminCatalogGacha | null;
  currentVersion: AdminCatalogGachaVersion | null;
  disabled: boolean;
  onArchiveMaster: () => void;
  onClone: () => void;
  onDiscardVersion: () => void;
  onEditVersion: () => void;
  state: ViewState["kind"];
}) {
  if (state === "list") {
    return (
      <Link className="primary-button" href="/catalog/gachas/new">
        <Plus size={16} aria-hidden="true" />
        ガチャ登録
      </Link>
    );
  }
  if (!currentGacha || currentGacha.is_archived) return undefined;
  if (state === "gacha") {
    return (
      <div className="catalog-header-actions">
        <Link className="secondary-button" href={`/gachas/${gachaIdentifier(currentGacha)}/edit`}>
          <Pencil size={16} aria-hidden="true" />
          Master編集
        </Link>
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
              <th>ID</th>
              <th>ガチャ名</th>
              <th>サムネイル画像</th>
              <th>消費ポイント</th>
              <th>公開ステータス</th>
              <th>履歴</th>
              <th>詳細</th>
            </tr>
          </thead>
          <tbody>
            {items.map((gacha) => (
              <tr key={gacha.id}>
                <td>
                  <code>{gacha.public_code ?? "未発行"}</code>
                </td>
                <td><strong>{gacha.current_version?.title ?? "未設定"}</strong></td>
                <td><PublicAssetPreview asset={gacha.current_version?.presentation_asset ?? null} /></td>
                <td>{gacha.current_version?.price_points.toLocaleString() ?? "-"}</td>
                <td>{publicationStatusLabel(gacha.publication_status)}</td>
                <td>
                  <Link
                    aria-label={`${gacha.current_version?.title ?? gacha.code}の履歴`}
                    className="table-link"
                    href={`/catalog/gachas/${gachaIdentifier(gacha)}/history`}
                  >
                    履歴
                  </Link>
                </td>
                <td>
                  <Link
                    aria-label={`${gacha.current_version?.title ?? gacha.code}の詳細`}
                    className="table-link"
                    href={`/catalog/gachas/${gachaIdentifier(gacha)}`}
                  >
                    詳細
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
  canManage,
  canGoBack,
  gacha,
  nextCursor,
  onBack,
  onClone,
  onNext,
  versions,
}: {
  canManage: boolean;
  canGoBack: boolean;
  gacha: AdminCatalogGacha;
  nextCursor: string | null;
  onBack: () => void;
  onClone: (version: AdminCatalogGachaVersion) => void;
  onNext: () => void;
  versions: AdminCatalogGachaVersion[];
}) {
  const editableDraft = versions
    .filter(isEditableGachaVersion)
    .sort((left, right) => right.version_number - left.version_number)[0] ?? null;

  return (
    <>
      <section className="catalog-detail catalog-gacha-detail">
        <header className="catalog-detail-title-row">
          <h2>{gacha.current_version?.title ?? gacha.code}</h2>
          <nav aria-label="ガチャ設計">
            <Link className="secondary-button" href={`/catalog/gachas/${gachaIdentifier(gacha)}/profit-simulation`}>利益シミュレーション</Link>
            <Link className="secondary-button" href={`/catalog/gachas/${gachaIdentifier(gacha)}/product-design-planner`}>商品設計プランナー</Link>
          </nav>
        </header>
        <PublicAssetPreview asset={gacha.current_version?.presentation_asset ?? null} />
        <dl>
          <Detail label="Public ID" value={gacha.public_code ?? "未発行"} />
          <Detail label="ガチャタイトル" value={gacha.current_version?.title ?? "未設定"} />
          <Detail label="カテゴリ" value={gacha.category.name} />
          <Detail label="タグ" value={gacha.tags.map((tag) => tag.name).join(", ") || "なし"} />
          <Detail label="消費ポイント" value={gacha.current_version?.price_points.toLocaleString() ?? "未設定"} />
          <Detail label="総口数" value={gacha.current_version?.total_count.toLocaleString() ?? "未設定"} />
          <Detail label="1日規定回数" value={dailyLimitLabel(gacha.current_version?.daily_draw_limit)} />
          <Detail label="状態" value={publicationStatusLabel(gacha.publication_status)} />
          <Detail label="会員ランク" value={audienceLabel(gacha.current_version?.audience_code)} />
          {gacha.current_version?.audience_code === "first_time_users" ? <Detail label="初回ユーザー期間" value={`${gacha.current_version.first_time_eligible_days}日（24時間単位）`} /> : null}
          <Detail label="開始日時" value={gacha.current_version?.publish_start_at ?? "未設定"} />
          <Detail label="終了日時" value={gacha.current_version?.publish_end_at ?? "無期限"} />
          <Detail label="説明" value={gacha.current_version?.description ?? "未設定"} />
          <Detail label="注意事項" value={gacha.current_version?.notices ?? "未設定"} />
        </dl>
      </section>
      <CatalogGachaRankPrizeManager
        canManage={canManage}
        gachaId={gachaIdentifier(gacha)}
        version={editableDraft}
      />
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
                          href={`/catalog/gachas/${gachaIdentifier(gacha)}/versions/${version.id}`}
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

function gachaIdentifier(gacha: AdminCatalogGacha): string {
  return gacha.public_code ?? gacha.id;
}

function requireCoreVersionRevision(gacha: AdminCatalogGacha | null): number {
  const revision = gacha?.current_version?.revision;
  if (!Number.isSafeInteger(revision) || (revision ?? 0) < 1) {
    throw new Error("The editable Gacha version revision is unavailable.");
  }
  return revision!;
}

function publicationStatusLabel(status?: AdminCatalogGacha["publication_status"]): string {
  return ({ draft: "下書き", published: "公開", scheduled: "予約公開", sales_paused: "販売停止", unpublished: "非公開" } as const)[status ?? "draft"];
}

function audienceLabel(code?: string): string {
  return ({ all_users: "すべてのユーザー", first_time_users: "初回ユーザー", line_users: "LINEユーザー" } as Record<string, string>)[code ?? "all_users"] ?? "未設定";
}

function dailyLimitLabel(limit?: number): string {
  return limit === undefined ? "未設定" : limit === 0 ? "無制限" : `${limit.toLocaleString()}回`;
}

function VersionDetail({
  gachaId,
  onCanonical,
  version,
}: {
  gachaId: string;
  onCanonical: (version: AdminCatalogGachaVersion) => void;
  version: AdminCatalogGachaVersion;
}) {
  return (
    <div className="catalog-gacha-version-layout">
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
                ? `v${version.published_probability_version.version_number}（選択済み）`
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
      <GachaPublishPreflightPanel
        gachaId={gachaId}
        onCanonical={onCanonical}
        version={version}
      />
    </div>
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

async function fileToBase64(file: File): Promise<string> {
  return new Promise((resolve, reject) => {
    const reader = new FileReader();
    reader.addEventListener("load", () => {
      if (typeof reader.result !== "string" || !reader.result.includes(",")) {
        reject(new Error("The selected thumbnail could not be read."));
        return;
      }
      resolve(reader.result.slice(reader.result.indexOf(",") + 1));
    });
    reader.addEventListener("error", () => reject(
      reader.error ?? new Error("The selected thumbnail could not be read."),
    ));
    reader.readAsDataURL(file);
  });
}

export function isEditableGachaVersion(
  version: Pick<AdminCatalogGachaVersion, "status" | "is_archived">,
): boolean {
  return version.status === "draft" && !version.is_archived;
}
