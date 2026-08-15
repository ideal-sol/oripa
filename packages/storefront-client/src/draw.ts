import type {
  PublicComponents,
  StorefrontResponse,
  StorefrontTransport,
} from "./types.js";

type Schemas = PublicComponents["schemas"];

export type DrawCount = 1 | 5 | 10 | 100 | 1000;

export interface DrawHistoryQuery {
  limit?: number;
  cursor?: string;
}

export type DrawHistoryReadProblemCode = Schemas["DrawHistoryReadProblemCode"];

export interface CreateDrawOptions {
  idempotency_key: string;
  csrf_token: string;
  signal?: AbortSignal;
  timeout_ms?: number;
}

export interface BrowserCreateDrawOptions {
  idempotency_key: string;
  signal?: AbortSignal;
  timeout_ms?: number;
}

export interface StorefrontDrawClient {
  listDrawHistory(
    query?: DrawHistoryQuery,
  ): Promise<StorefrontResponse<Schemas["DrawHistoryCollection"]>>;
  createDraw(
    gachaId: string,
    drawCount: DrawCount,
    options: CreateDrawOptions,
  ): Promise<StorefrontResponse<Schemas["DrawResponse"]>>;
  getDrawRequest(
    drawRequestId: string,
    signal?: AbortSignal,
  ): Promise<StorefrontResponse<Schemas["DrawResponse"]>>;
}

export interface BrowserStorefrontDrawClient {
  listDrawHistory(
    query?: DrawHistoryQuery,
  ): Promise<StorefrontResponse<Schemas["DrawHistoryCollection"]>>;
  createDraw(
    gachaId: string,
    drawCount: DrawCount,
    options: BrowserCreateDrawOptions,
  ): Promise<StorefrontResponse<Schemas["DrawResponse"]>>;
  getDrawRequest(
    drawRequestId: string,
    signal?: AbortSignal,
  ): Promise<StorefrontResponse<Schemas["DrawResponse"]>>;
}

function pathSegment(value: string, name: string): string {
  if (!value || value.includes("/") || value.includes("\0")) {
    throw new TypeError(`${name} is invalid`);
  }

  return encodeURIComponent(value);
}

function historyQueryString(query: DrawHistoryQuery): string {
  const parameters = new URLSearchParams();
  if (query.limit !== undefined) {
    if (!Number.isSafeInteger(query.limit) || query.limit < 1 || query.limit > 100) {
      throw new TypeError("limit must be an integer from 1 through 100");
    }
    parameters.set("limit", String(query.limit));
  }
  if (query.cursor !== undefined) {
    parameters.set("cursor", query.cursor);
  }
  const encoded = parameters.toString();
  return encoded === "" ? "" : `?${encoded}`;
}

export function createStorefrontDrawClient(
  transport: StorefrontTransport,
): StorefrontDrawClient {
  return {
    listDrawHistory: (query = {}) =>
      transport.request({ path: `/me/draws${historyQueryString(query)}` }),
    createDraw: (gachaId, drawCount, options) => {
      if (![1, 5, 10, 100, 1000].includes(drawCount)) {
        throw new TypeError("draw_count is invalid");
      }
      if (!/^[0-9a-f]{64}$/.test(options.csrf_token)) {
        throw new TypeError("csrf_token is invalid");
      }

      return transport.request({
        path: `/gachas/${pathSegment(gachaId, "gacha_id")}/draws`,
        method: "POST",
        body: { draw_count: drawCount },
        headers: { "X-XSRF-TOKEN": options.csrf_token },
        idempotency_key: options.idempotency_key,
        csrf: "required",
        signal: options.signal,
        timeout_ms: options.timeout_ms,
      });
    },
    getDrawRequest: (drawRequestId, signal) =>
      transport.request({
        path: `/draw-requests/${pathSegment(drawRequestId, "draw_request_id")}`,
        signal,
      }),
  };
}

export function createCsrfManagedStorefrontDrawClient(
  transport: StorefrontTransport,
): BrowserStorefrontDrawClient {
  return {
    listDrawHistory: (query = {}) =>
      transport.request({ path: `/me/draws${historyQueryString(query)}` }),
    createDraw: (gachaId, drawCount, options) => {
      if (![1, 5, 10, 100, 1000].includes(drawCount)) {
        throw new TypeError("draw_count is invalid");
      }

      return transport.request({
        path: `/gachas/${pathSegment(gachaId, "gacha_id")}/draws`,
        method: "POST",
        body: { draw_count: drawCount },
        idempotency_key: options.idempotency_key,
        csrf: "required",
        signal: options.signal,
        timeout_ms: options.timeout_ms,
      });
    },
    getDrawRequest: (drawRequestId, signal) =>
      transport.request({
        path: `/draw-requests/${pathSegment(drawRequestId, "draw_request_id")}`,
        signal,
      }),
  };
}
