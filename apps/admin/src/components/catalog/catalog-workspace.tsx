"use client";

import { Database, LoaderCircle } from "lucide-react";
import { useEffect, useMemo, useState } from "react";

import { CatalogApiErrorBoundary } from "@/components/catalog/catalog-api-error-boundary";
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
import { CursorPagination } from "@/components/catalog/cursor-pagination";
import { PublicAssetPreview } from "@/components/catalog/public-asset-preview";
import { StatusBadge } from "@/components/catalog/status-badge";
import { useAdminAuth } from "@/components/auth/admin-auth-provider";
import { ProtectedAdminRoute } from "@/components/permissions/protected-admin-route";
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
  AdminCatalogRank,
  AdminCatalogTag,
} from "@/lib/admin-api/generated";
import {
  catalogSection,
  type CatalogResource,
  type CatalogSection,
} from "@/lib/catalog/catalog-registry";

type CatalogItem =
  | AdminCatalogCategory
  | AdminCatalogTag
  | AdminCatalogRank
  | AdminCatalogPrize
  | AdminCatalogPresentationAsset;

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
  resource: CatalogResource;
}) {
  const section = catalogSection(resource);
  if (!section) throw new Error("Unknown catalog resource");
  const client = useMemo(() => new AdminApiClient(), []);
  const { expireSession } = useAdminAuth();
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

  return (
    <AdminShell>
      <ProtectedAdminRoute permission="catalog.read">
        <div className="workspace">
          <CatalogBreadcrumb detail={id ? title : undefined} section={section} />
          <AdminPageHeader
            description={id ? "Read-onlyのMaster詳細です。" : section.description}
            eyebrow="Catalog"
            title={title}
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
        </div>
      </ProtectedAdminRoute>
    </AdminShell>
  );
}

async function loadCatalogState(
  client: AdminApiClient,
  section: CatalogSection,
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
  resource: CatalogSection["resource"],
  query: AdminCatalogQuery,
  signal: AbortSignal,
): Promise<AdminCatalogCollection<CatalogItem>> {
  switch (resource) {
    case "categories":
      return client.listCatalogCategories(query, signal);
    case "tags":
      return client.listCatalogTags(query, signal);
    case "ranks":
      return client.listCatalogRanks(query, signal);
    case "prizes":
      return client.listCatalogPrizes(query, signal);
    case "presentation-assets":
      return client.listCatalogPresentationAssets(query, signal);
  }
}

async function getDetail(
  client: AdminApiClient,
  resource: CatalogSection["resource"],
  id: string,
  signal: AbortSignal,
): Promise<CatalogItem> {
  switch (resource) {
    case "categories":
      return (await client.getCatalogCategory(id, signal)).data;
    case "tags":
      return (await client.getCatalogTag(id, signal)).data;
    case "ranks":
      return (await client.getCatalogRank(id, signal)).data;
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
      asset: item,
    };
  }
  const secondary =
    "rank" in item
      ? `${item.rank.name} / ${item.exchange_points.toLocaleString()} Point交換`
      : "slug" in item
        ? item.slug
        : `表示順 ${item.sort_order}`;
  return {
    id: item.id,
    code: item.code,
    name: item.name,
    secondary,
    visible: item.is_visible,
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
      ["Rank", `${item.rank.name} (${item.rank.code})`],
      ["表示価格", item.display_price.toLocaleString()],
      ["交換Point", item.exchange_points.toLocaleString()],
    );
  } else {
    entries.push(["表示順", item.sort_order.toLocaleString()]);
  }
  return [...entries, ...common];
}
