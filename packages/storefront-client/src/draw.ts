import type {
  PublicComponents,
  StorefrontResponse,
  StorefrontTransport,
} from "./types.js";

type Schemas = PublicComponents["schemas"];

export type DrawCount = 1 | 5 | 10 | 100 | 1000;

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

export function createStorefrontDrawClient(
  transport: StorefrontTransport,
): StorefrontDrawClient {
  return {
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
