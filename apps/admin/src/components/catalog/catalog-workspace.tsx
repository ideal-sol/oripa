"use client";

import { Archive, Database, LoaderCircle, Pencil, Plus } from "lucide-react";
import { useEffect, useMemo, useRef, useState } from "react";

import { CatalogApiErrorBoundary } from "@/components/catalog/catalog-api-error-boundary";
import { CatalogConfirmationDialog } from "@/components/catalog/catalog-confirmation-dialog";
import { CatalogConflictBoundary } from "@/components/catalog/catalog-conflict-boundary";
import { CatalogBreadcrumb } from "@/components/catalog/catalog-breadcrumb";
import {
  type CatalogFilters,
  FilterControl,
  SearchForm,
} from "@/components/catalog/catalog-controls";
import {
  CatalogDataTable,
  type CatalogTableRow,
} from "@/components/catalog/catalog-data-table";
import { CatalogSectionNavigation } from "@/components/catalog/catalog-section-navigation";
import {
  type CatalogMasterDraft,
  CatalogMutationForm,
} from "@/components/catalog/catalog-mutation-form";
import {
  type CatalogPrizeAssetDraft,
  CatalogPrizeAssetMutationForm,
} from "@/components/catalog/catalog-prize-asset-mutation-form";
import { CursorPagination } from "@/components/catalog/cursor-pagination";
import { PublicAssetPreview } from "@/components/catalog/public-asset-preview";
import { StatusBadge } from "@/components/catalog/status-badge";
import { useAdminAuth } from "@/components/auth/admin-auth-provider";
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
  AdminCatalogCategory,
  AdminCatalogCollection,
  AdminCatalogPresentationAsset,
  AdminCatalogPrize,
  AdminCatalogTag,
} from "@/lib/admin-api/generated";
import {
  catalogSection,
  type CatalogResource,
  type CatalogSection,
} from "@/lib/catalog/catalog-registry";

type CatalogReferenceResource = Exclude<CatalogResource, "gachas" | "ranks">;
type CatalogReferenceSection = CatalogSection & {
  resource: CatalogReferenceResource;
};

type CatalogItem =
  | AdminCatalogCategory
  | AdminCatalogTag
  | AdminCatalogPrize
  | AdminCatalogPresentationAsset;

type CatalogMasterItem =
  | AdminCatalogCategory
  | AdminCatalogTag;
type CatalogMutableItem = AdminCatalogCategory | AdminCatalogTag | AdminCatalogPresentationAsset;

type LoadState =
  | { kind: "loading" }
  | { kind: "error"; error: AdminApiError }
  | { kind: "list"; items: CatalogItem[]; nextCursor: string | null }
  | { kind: "detail"; item: CatalogItem };

export function CatalogWorkspace({
  id,
  resource,
}: {
  id?: string;
  resource: CatalogReferenceResource;
}) {
  const resolvedSection = catalogSection(resource);
  if (!resolvedSection) throw new Error("Unknown catalog resource");
  const section = resolvedSection as CatalogReferenceSection;
  const client = useMemo(() => new AdminApiClient(), []);
  const { expireSession } = useAdminAuth();
  const { hasPermission } = usePermissions();
  const [filters, setFilters] = useState<CatalogFilters>({
    direction: section.resource === "presentation-assets" ? "desc" : "asc",
    mediaType: "all",
    query: "",
    sort: section.sortOptions[0].value,
    visibility: "all",
  });
  const [cursorHistory, setCursorHistory] = useState<(string | null)[]>([null]);
  const [cursorIndex, setCursorIndex] = useState(0);
  const [reload, setReload] = useState(0);
  const [state, setState] = useState<LoadState>({ kind: "loading" });
  const [mutationMode, setMutationMode] = useState<"create" | "edit" | null>(null);
  const [archiveOpen, setArchiveOpen] = useState(false);
  const [archiveBusy, setArchiveBusy] = useState(false);
  const [mutationError, setMutationError] = useState<AdminApiError | null>(null);
  const pendingMutation = useRef<{ fingerprint: string; key: string } | null>(null);
  const cursor = cursorHistory[cursorIndex] ?? null;

  useEffect(() => {
    const controller = new AbortController();
    const query: AdminCatalogQuery = {
      cursor: cursor ?? undefined,
      direction: filters.direction,
      limit: 20,
      media_type: section.supportsMediaType ? filters.mediaType : undefined,
      q: filters.query || undefined,
      sort: filters.sort,
      visibility: filters.visibility,
    };
    loadCatalogState(client, section, query, controller.signal, id)
      .then(setState)
      .catch((cause: unknown) => {
        if (controller.signal.aborted) return;
        const error =
          cause instanceof AdminApiError
            ? cause
            : new AdminApiError(0, "NETWORK_ERROR", null, null, true);
        if (error.isSessionExpired) expireSession();
        setState({ kind: "error", error });
      });
    return () => controller.abort();
  }, [
    client,
    cursor,
    expireSession,
    filters.direction,
    filters.mediaType,
    filters.query,
    filters.sort,
    filters.visibility,
    id,
    reload,
    section,
  ]);

  function updateFilters(next: CatalogFilters) {
    setState({ kind: "loading" });
    setFilters(next);
    setCursorHistory([null]);
    setCursorIndex(0);
  }

  const title =
    state.kind === "detail" ? itemName(state.item) : section.label;
  const current =
    state.kind === "detail" && isMutableResource(section.resource)
      ? (state.item as CatalogMutableItem)
      : null;
  const canManage = hasPermission("catalog.manage") && isMutableResource(section.resource);
  const canMutateMaster = canManage && hasCatalogMutationRevision(current);

  async function submitMutation(draft: CatalogMasterDraft) {
    const fingerprint = JSON.stringify({
      draft,
      id: current?.id ?? null,
      mode: mutationMode,
      resource: section.resource,
      revision: current?.revision ?? null,
    });
    const key =
      pendingMutation.current?.fingerprint === fingerprint
        ? pendingMutation.current.key
        : crypto.randomUUID();
    pendingMutation.current = { fingerprint, key };
    try {
      const result = await mutateMaster(
        client,
        section.resource,
        mutationMode,
        current as CatalogMasterItem | null,
        draft,
        key,
      );
      pendingMutation.current = null;
      setMutationError(null);
      setMutationMode(null);
      if (state.kind === "detail") {
        setState({ kind: "detail", item: result });
      } else {
        setReload((value) => value + 1);
      }
    } catch (cause) {
      const error =
        cause instanceof AdminApiError
          ? cause
          : new AdminApiError(0, "NETWORK_ERROR", null, null, true);
      if (!error.retryable) pendingMutation.current = null;
      if (error.isSessionExpired) expireSession();
      setMutationError(error);
      if ([401, 403, 409, 412, 429].includes(error.status)) {
        setMutationMode(null);
      }
      throw error;
    }
  }

  async function submitPrizeAssetMutation(draft: CatalogPrizeAssetDraft) {
    const fingerprint = JSON.stringify({
      draft,
      id: current?.id ?? null,
      mode: mutationMode,
      resource: section.resource,
      revision: current && "revision" in current ? current.revision : null,
    });
    const key =
      pendingMutation.current?.fingerprint === fingerprint
        ? pendingMutation.current.key
        : crypto.randomUUID();
    pendingMutation.current = { fingerprint, key };
    try {
      const result = await mutatePrizeAsset(
        client,
        section.resource,
        mutationMode,
        current,
        draft,
        key,
      );
      pendingMutation.current = null;
      setMutationError(null);
      setMutationMode(null);
      if (state.kind === "detail") setState({ kind: "detail", item: result });
      else setReload((value) => value + 1);
    } catch (cause) {
      const error = cause instanceof AdminApiError
        ? cause
        : new AdminApiError(0, "NETWORK_ERROR", null, null, true);
      if (!error.retryable) pendingMutation.current = null;
      if (error.isSessionExpired) expireSession();
      setMutationError(error);
      if ([401, 403, 409, 412, 429].includes(error.status)) setMutationMode(null);
      throw error;
    }
  }

  async function archiveMaster() {
    if (!current || !isMutableResource(section.resource)) return;
    setArchiveBusy(true);
    const fingerprint = JSON.stringify({
      action: "archive",
      id: current.id,
      revision: "revision" in current ? current.revision : null,
    });
    const key =
      pendingMutation.current?.fingerprint === fingerprint
        ? pendingMutation.current.key
        : crypto.randomUUID();
    pendingMutation.current = { fingerprint, key };
    try {
      const result = await archiveCatalogMaster(
        client,
        section.resource,
        current,
        key,
      );
      pendingMutation.current = null;
      setMutationError(null);
      setArchiveOpen(false);
      setState({ kind: "detail", item: result });
    } catch (cause) {
      const error =
        cause instanceof AdminApiError
          ? cause
          : new AdminApiError(0, "NETWORK_ERROR", null, null, true);
      if (!error.retryable) pendingMutation.current = null;
      if (error.isSessionExpired) expireSession();
      setMutationError(error);
      setArchiveOpen(false);
    } finally {
      setArchiveBusy(false);
    }
  }

  return (
    <AdminShell>
      <ProtectedAdminRoute permission="catalog.read">
        <div className="workspace">
          <CatalogBreadcrumb detail={id ? title : undefined} section={section} />
          <AdminPageHeader
            description={id ? "Read-onlyのMaster詳細です。" : section.description}
            eyebrow="Catalog"
            title={title}
            action={
              canMutateMaster && !(current && "is_archived" in current && current.is_archived) ? (
                <div className="catalog-header-actions">
                  {current ? (
                    <>
                      <button
                        className="secondary-button"
                        onClick={() => setMutationMode("edit")}
                        type="button"
                      >
                        <Pencil size={16} aria-hidden="true" />
                        編集
                      </button>
                      <button
                        className="danger-button"
                        onClick={() => setArchiveOpen(true)}
                        type="button"
                      >
                        <Archive size={16} aria-hidden="true" />
                        Archive
                      </button>
                    </>
                  ) : (
                    <button
                      className="primary-button"
                      onClick={() => setMutationMode("create")}
                      type="button"
                    >
                      <Plus size={16} aria-hidden="true" />
                      新規作成
                    </button>
                  )}
                </div>
              ) : undefined
            }
          />
          <CatalogSectionNavigation active={section.resource} />
          {id ? null : (
            <div className="catalog-toolbar">
              <SearchForm
                initialValue={filters.query}
                onSubmit={(query) => updateFilters({ ...filters, query })}
              />
              <FilterControl
                filters={filters}
                onChange={updateFilters}
                section={section}
              />
            </div>
          )}
          {state.kind === "loading" ? (
            <section className="catalog-state" role="status">
              <LoaderCircle className="spin" size={28} aria-hidden="true" />
              <h2>読み込んでいます</h2>
            </section>
          ) : null}
          {state.kind === "error" ? (
            <CatalogApiErrorBoundary
              error={state.error}
              retry={() => {
                setState({ kind: "loading" });
                setReload((value) => value + 1);
              }}
            />
          ) : null}
          {mutationError && [409, 412].includes(mutationError.status) ? (
            <CatalogConflictBoundary
              error={mutationError}
              onReload={() => {
                setMutationError(null);
                setState({ kind: "loading" });
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
          {state.kind === "list" && state.items.length === 0 ? (
            <section className="catalog-state">
              <Database size={28} aria-hidden="true" />
              <h2>該当するMasterはありません</h2>
              <p>検索条件を変更してください。</p>
            </section>
          ) : null}
          {state.kind === "list" && state.items.length > 0 ? (
            <>
              <CatalogDataTable
                resource={section.resource}
                rows={state.items.map((item) => tableRow(item))}
              />
              <CursorPagination
                canGoBack={cursorIndex > 0}
                canGoNext={state.nextCursor !== null}
                onBack={() => {
                  setState({ kind: "loading" });
                  setCursorIndex((value) => Math.max(0, value - 1));
                }}
                onNext={() => {
                  if (!state.nextCursor) return;
                  setState({ kind: "loading" });
                  setCursorHistory((history) => [
                    ...history.slice(0, cursorIndex + 1),
                    state.nextCursor,
                  ]);
                  setCursorIndex((value) => value + 1);
                }}
              />
            </>
          ) : null}
          {state.kind === "detail" ? <CatalogDetail item={state.item} /> : null}
          {mutationMode && canManage && isMasterResource(section.resource) ? (
            <CatalogMutationForm
              initial={current ? masterDraft(current as CatalogMasterItem) : undefined}
              mode={mutationMode}
              onCancel={() => setMutationMode(null)}
              onSubmit={submitMutation}
              resource={section.resource as "categories" | "tags"}
            />
          ) : null}
          {mutationMode && canManage && isPrizeAssetResource(section.resource) ? (
            <CatalogPrizeAssetMutationForm
              current={
                current
                  ? (current as AdminCatalogPresentationAsset)
                  : undefined
              }
              mode={mutationMode}
              onCancel={() => setMutationMode(null)}
              onSubmit={submitPrizeAssetMutation}
              resource={section.resource}
            />
          ) : null}
          {archiveOpen && current ? (
            <CatalogConfirmationDialog
              busy={archiveBusy}
              name={itemName(current)}
              onCancel={() => setArchiveOpen(false)}
              onConfirm={archiveMaster}
            />
          ) : null}
        </div>
      </ProtectedAdminRoute>
    </AdminShell>
  );
}

async function loadCatalogState(
  client: AdminApiClient,
  section: CatalogReferenceSection,
  query: AdminCatalogQuery,
  signal: AbortSignal,
  id?: string,
): Promise<LoadState> {
  if (id) {
    return {
      kind: "detail",
      item: await getDetail(client, section.resource, id, signal),
    };
  }
  const response = await getList(client, section.resource, query, signal);
  return {
    kind: "list",
    items: response.items,
    nextCursor: response.next_cursor,
  };
}

function CatalogDetail({ item }: { item: CatalogItem }) {
  const asset = assetFrom(item);
  const entries = detailEntries(item);
  return (
    <section className="catalog-detail">
      <div className="catalog-detail-preview">
        <PublicAssetPreview asset={asset} />
      </div>
      <dl>
        {entries.map(([label, value]) => (
          <div key={label}>
            <dt>{label}</dt>
            <dd>{value}</dd>
          </div>
        ))}
        <div>
          <dt>公開状態</dt>
          <dd>
            <StatusBadge
              archived={"is_archived" in item && item.is_archived}
              visible={"is_visible" in item ? item.is_visible : item.is_public}
            />
          </dd>
        </div>
      </dl>
    </section>
  );
}

async function getList(
  client: AdminApiClient,
  resource: CatalogReferenceResource,
  query: AdminCatalogQuery,
  signal: AbortSignal,
): Promise<AdminCatalogCollection<CatalogItem>> {
  switch (resource) {
    case "categories":
      return client.listCatalogCategories(query, signal);
    case "tags":
      return client.listCatalogTags(query, signal);
    case "prizes":
      return client.listCatalogPrizes(query, signal);
    case "presentation-assets":
      return client.listCatalogPresentationAssets(query, signal);
  }
}

async function getDetail(
  client: AdminApiClient,
  resource: CatalogReferenceResource,
  id: string,
  signal: AbortSignal,
): Promise<CatalogItem> {
  switch (resource) {
    case "categories":
      return (await client.getCatalogCategory(id, signal)).data;
    case "tags":
      return (await client.getCatalogTag(id, signal)).data;
    case "prizes":
      return (await client.getCatalogPrize(id, signal)).data;
    case "presentation-assets":
      return (await client.getCatalogPresentationAsset(id, signal)).data;
  }
}

function itemName(item: CatalogItem): string {
  return "name" in item ? item.name : item.alt_text ?? "Presentation Asset";
}

function assetFrom(item: CatalogItem) {
  if ("presentation_asset" in item) return item.presentation_asset;
  if ("media_type" in item) return item;
  return null;
}

function tableRow(item: CatalogItem): CatalogTableRow {
  if ("media_type" in item) {
    return {
      id: item.id,
      code: item.media_type,
      name: item.alt_text ?? "Presentation Asset",
      secondary: item.mime_type,
      visible: item.is_public,
      archived: item.is_archived ?? false,
      asset: item,
    };
  }
  const secondary =
    "rank" in item
      ? `${item.rank.name} / ${item.exchange_points.toLocaleString()} Point交換`
      : item.slug;
  return {
    id: item.id,
    code: item.code,
    name: item.name,
    secondary,
    slug: "slug" in item ? item.slug : undefined,
    sortOrder: "sort_order" in item ? item.sort_order : undefined,
    visible: item.is_visible,
    archived: "is_archived" in item ? item.is_archived : false,
    asset: "presentation_asset" in item ? item.presentation_asset : null,
  };
}

function detailEntries(item: CatalogItem): [string, string][] {
  const common: [string, string][] = [
    ["Public ID", item.id],
    ["作成日時", item.created_at],
    ["更新日時", item.updated_at],
  ];
  if ("media_type" in item) {
    return [
      ["Media種別", item.media_type],
      ["MIME Type", item.mime_type],
      ["Byte Size", item.byte_size.toLocaleString()],
      ["Alt", item.alt_text ?? "未設定"],
      ["Checksum", item.checksum_sha256],
      ...(typeof item.revision === "number"
        ? [
            ["Revision", item.revision.toLocaleString()],
            ["Archive日時", item.archived_at ?? "未Archive"],
          ] as [string, string][]
        : []),
      ...common,
    ];
  }
  const entries: [string, string][] = [
    ["Code", item.code],
    ["名称", item.name],
  ];
  if ("slug" in item) entries.push(["Slug", item.slug]);
  if ("description" in item) entries.push(["説明", item.description ?? "未設定"]);
  if ("rank" in item) {
    entries.push(
      ["Rank", `${item.rank.name}${item.rank.code ? ` (${item.rank.code})` : ""}`],
      ["表示価格", item.display_price.toLocaleString()],
      ["交換Point", item.exchange_points.toLocaleString()],
    );
    if (typeof item.revision === "number") {
      entries.push(
        ["Revision", item.revision.toLocaleString()],
        ["Archive日時", item.archived_at ?? "未Archive"],
      );
    }
  } else {
    entries.push(["表示順", item.sort_order.toLocaleString()]);
    if ("revision" in item && typeof item.revision === "number") {
      entries.push(
        ["Revision", item.revision.toLocaleString()],
        ["Archive日時", item.archived_at ?? "未Archive"],
      );
    }
  }
  return [...entries, ...common];
}

function isMasterResource(
  resource: CatalogReferenceResource,
): resource is "categories" | "tags" {
  return ["categories", "tags"].includes(resource);
}

function isPrizeAssetResource(
  resource: CatalogReferenceResource,
): resource is "presentation-assets" {
  return resource === "presentation-assets";
}

function isMutableResource(resource: CatalogReferenceResource): boolean {
  return isMasterResource(resource) || isPrizeAssetResource(resource);
}

function masterDraft(item: CatalogMasterItem): CatalogMasterDraft {
  return {
    code: item.code,
    description: "description" in item ? item.description : null,
    isVisible: item.is_visible,
    name: item.name,
    slug: "slug" in item ? item.slug : "",
    sortOrder: item.sort_order,
  };
}

async function mutateMaster(
  client: AdminApiClient,
  resource: CatalogReferenceResource,
  mode: "create" | "edit" | null,
  current: CatalogMasterItem | null,
  draft: CatalogMasterDraft,
  key: string,
): Promise<CatalogMasterItem> {
  if (!isMasterResource(resource) || mode === null) {
    throw new Error("Unsupported Catalog mutation.");
  }
  const revision = mode === "edit" ? mutationRevision(current) : null;
  if (resource === "categories") {
    const response =
      mode === "create"
        ? await client.createCatalogCategory(
            {
              code: draft.code,
              description: draft.description,
              is_visible: draft.isVisible,
              name: draft.name,
              slug: draft.slug,
              sort_order: draft.sortOrder,
            },
            key,
          )
        : await client.updateCatalogCategory(
            current!.id,
            {
              description: draft.description,
              expected_revision: revision!,
              is_visible: draft.isVisible,
              name: draft.name,
              slug: draft.slug,
              sort_order: draft.sortOrder,
            },
            key,
          );
    return response.data;
  }
  if (resource === "tags") {
    const response =
      mode === "create"
        ? await client.createCatalogTag(
            {
              code: draft.code,
              is_visible: draft.isVisible,
              name: draft.name,
              slug: draft.slug,
              sort_order: draft.sortOrder,
            },
            key,
          )
        : await client.updateCatalogTag(
            current!.id,
            {
              expected_revision: revision!,
              is_visible: draft.isVisible,
              name: draft.name,
              slug: draft.slug,
              sort_order: draft.sortOrder,
            },
            key,
          );
    return response.data;
  }
  throw new Error("Unsupported Catalog mutation.");
}

async function archiveCatalogMaster(
  client: AdminApiClient,
  resource: CatalogReferenceResource,
  current: CatalogMutableItem,
  key: string,
): Promise<CatalogMutableItem> {
  const revision = mutationRevision(current);
  if (resource === "categories") {
    return (await client.archiveCatalogCategory(current.id, revision, key)).data;
  }
  if (resource === "tags") {
    return (await client.archiveCatalogTag(current.id, revision, key)).data;
  }
  if (resource === "presentation-assets") {
    return (
      await client.archiveCatalogPresentationAsset(current.id, revision, key)
    ).data;
  }
  throw new Error("Unsupported Catalog archive.");
}

function mutationRevision(current: CatalogMutableItem | null): number {
  if (!current || !("revision" in current) || typeof current.revision !== "number") {
    throw new Error("Catalog mutation revision is unavailable.");
  }
  return current.revision;
}

export function hasCatalogMutationRevision(
  current: CatalogMutableItem | null,
): boolean {
  return current === null || ("revision" in current && typeof current.revision === "number");
}

async function mutatePrizeAsset(
  client: AdminApiClient,
  resource: CatalogReferenceResource,
  mode: "create" | "edit" | null,
  current: CatalogMutableItem | null,
  draft: CatalogPrizeAssetDraft,
  key: string,
): Promise<CatalogMutableItem> {
  if (mode === null || !isPrizeAssetResource(resource)) {
    throw new Error("Unsupported Catalog mutation.");
  }
  const revision = mode === "edit" ? mutationRevision(current) : null;
  if (resource === "presentation-assets") {
    return mode === "create"
      ? (
          await client.createCatalogPresentationAsset(
            {
              alt_text: draft.altText,
              byte_size: draft.byteSize,
              checksum_sha256: draft.checksumSha256,
              is_public: draft.isPublic,
              media_type: draft.mediaType,
              mime_type: draft.mimeType,
              public_path: draft.publicPath,
              storage_identifier: draft.storageIdentifier,
            },
            key,
          )
        ).data
      : (
          await client.updateCatalogPresentationAsset(
            current!.id,
            {
              alt_text: draft.altText,
              expected_revision: revision!,
              is_public: draft.isPublic,
            },
            key,
          )
        ).data;
  }
  throw new Error("Catalog mutation resource mismatch.");
}
