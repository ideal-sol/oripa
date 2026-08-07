import type {
  PublicComponents,
  StorefrontResponse,
  StorefrontTransport,
} from "./types.js";

type Schemas = PublicComponents["schemas"];

export interface GachaListQuery {
  limit?: number;
  cursor?: string;
  category?: string;
  tag?: string;
}

export interface StorefrontCatalogClient {
  listGachaCategories(): Promise<
    StorefrontResponse<Schemas["GachaCategoryCollection"]>
  >;
  listGachaTags(): Promise<StorefrontResponse<Schemas["GachaTagCollection"]>>;
  listGachas(
    query?: GachaListQuery,
  ): Promise<StorefrontResponse<Schemas["GachaSummaryCollection"]>>;
  getGacha(
    gachaId: string,
  ): Promise<StorefrontResponse<Schemas["GachaDetailResponse"]>>;
  getGachaBySlug(
    slug: string,
  ): Promise<StorefrontResponse<Schemas["GachaDetailResponse"]>>;
  getGachaPresentation(
    gachaId: string,
  ): Promise<StorefrontResponse<Schemas["GachaPresentationResponse"]>>;
}

function pathSegment(value: string, name: string): string {
  if (!value || value.includes("/") || value.includes("\0")) {
    throw new TypeError(`${name} is invalid`);
  }

  return encodeURIComponent(value);
}

function queryString(query: GachaListQuery): string {
  const parameters = new URLSearchParams();
  if (query.limit !== undefined) {
    if (!Number.isSafeInteger(query.limit) || query.limit < 1 || query.limit > 100) {
      throw new TypeError("limit must be an integer from 1 through 100");
    }
    parameters.set("limit", String(query.limit));
  }
  for (const name of ["cursor", "category", "tag"] as const) {
    const value = query[name];
    if (value !== undefined) {
      parameters.set(name, value);
    }
  }
  const encoded = parameters.toString();

  return encoded === "" ? "" : `?${encoded}`;
}

export function createStorefrontCatalogClient(
  transport: StorefrontTransport,
): StorefrontCatalogClient {
  return {
    listGachaCategories: () =>
      transport.request({ path: "/gacha-categories" }),
    listGachaTags: () => transport.request({ path: "/gacha-tags" }),
    listGachas: (query = {}) =>
      transport.request({ path: `/gachas${queryString(query)}` }),
    getGacha: (gachaId) =>
      transport.request({ path: `/gachas/${pathSegment(gachaId, "gacha_id")}` }),
    getGachaBySlug: (slug) =>
      transport.request({
        path: `/gachas/by-slug/${pathSegment(slug, "slug")}`,
      }),
    getGachaPresentation: (gachaId) =>
      transport.request({
        path: `/gacha-presentations/${pathSegment(gachaId, "gacha_id")}`,
      }),
  };
}
