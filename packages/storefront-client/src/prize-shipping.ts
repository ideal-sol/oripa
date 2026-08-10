import type {
  PublicComponents,
  StorefrontResponse,
  StorefrontTransport,
} from "./types.js";

type Schemas = PublicComponents["schemas"];

export interface PrizeShippingMutationOptions {
  csrf_token: string;
  idempotency_key?: string;
  signal?: AbortSignal;
  timeout_ms?: number;
}

export interface BrowserPrizeShippingMutationOptions {
  idempotency_key: string;
  signal?: AbortSignal;
  timeout_ms?: number;
}

export interface BrowserPrizeShippingNonRetryableMutationOptions {
  signal?: AbortSignal;
  timeout_ms?: number;
}

type IdempotentPrizeShippingMutationOptions =
  Required<Pick<PrizeShippingMutationOptions, "csrf_token" | "idempotency_key">>
  & Pick<PrizeShippingMutationOptions, "signal" | "timeout_ms">;

type NonRetryablePrizeShippingMutationOptions =
  Pick<PrizeShippingMutationOptions, "csrf_token" | "signal" | "timeout_ms">;

type OptionalIdempotentPrizeShippingMutationOptions =
  Pick<PrizeShippingMutationOptions, "csrf_token" | "idempotency_key" | "signal" | "timeout_ms">;

export const FULFILLMENT_MUTATION_RETRY_SEMANTICS = Object.freeze({
  exchangePrizes: "same-idempotency-key",
  createShippingAddress: "same-idempotency-key",
  updateShippingAddress: "reconcile-before-retry",
  deleteShippingAddress: "reconcile-before-retry",
  createShippingRequest: "same-idempotency-key",
} as const);

export type FulfillmentMutationRetrySemantics =
  (typeof FULFILLMENT_MUTATION_RETRY_SEMANTICS)[keyof typeof FULFILLMENT_MUTATION_RETRY_SEMANTICS];

export interface StorefrontPrizeShippingClient {
  listPrizes(cursor?: string): Promise<StorefrontResponse<Schemas["UserPrizeCollection"]>>;
  getPrize(prizeId: string): Promise<StorefrontResponse<Schemas["UserPrizeDetail"]>>;
  exchangePrizes(
    prizeIds: string[],
    options: IdempotentPrizeShippingMutationOptions,
  ): Promise<StorefrontResponse<Schemas["PrizeExchangeResponse"]>>;
  listShippingAddresses(): Promise<
    StorefrontResponse<Schemas["ShippingAddressCollection"]>
  >;
  getShippingAddress(
    addressId: string,
  ): Promise<StorefrontResponse<Schemas["ShippingAddress"]>>;
  createShippingAddress(
    address: Schemas["ShippingAddressInput"],
    options: OptionalIdempotentPrizeShippingMutationOptions,
  ): Promise<StorefrontResponse<Schemas["ShippingAddress"]>>;
  updateShippingAddress(
    addressId: string,
    address: Schemas["ShippingAddressInput"],
    options: NonRetryablePrizeShippingMutationOptions,
  ): Promise<StorefrontResponse<Schemas["ShippingAddress"]>>;
  deleteShippingAddress(
    addressId: string,
    options: NonRetryablePrizeShippingMutationOptions,
  ): Promise<StorefrontResponse<{ deleted: true }>>;
  listShippingRequests(
    cursor?: string,
  ): Promise<StorefrontResponse<Schemas["ShippingRequestCollection"]>>;
  getShippingRequest(
    requestId: string,
  ): Promise<StorefrontResponse<Schemas["ShippingRequestDetail"]>>;
  createShippingRequest(
    addressId: string,
    prizeIds: string[],
    options: IdempotentPrizeShippingMutationOptions,
  ): Promise<StorefrontResponse<Schemas["ShippingRequestSummary"]>>;
}

export interface BrowserStorefrontPrizeShippingClient {
  listPrizes(cursor?: string): Promise<StorefrontResponse<Schemas["UserPrizeCollection"]>>;
  getPrize(prizeId: string): Promise<StorefrontResponse<Schemas["UserPrizeDetail"]>>;
  exchangePrizes(
    prizeIds: string[],
    options: BrowserPrizeShippingMutationOptions,
  ): Promise<StorefrontResponse<Schemas["PrizeExchangeResponse"]>>;
  listShippingAddresses(): Promise<
    StorefrontResponse<Schemas["ShippingAddressCollection"]>
  >;
  getShippingAddress(
    addressId: string,
  ): Promise<StorefrontResponse<Schemas["ShippingAddress"]>>;
  createShippingAddress(
    address: Schemas["ShippingAddressInput"],
    options: BrowserPrizeShippingMutationOptions,
  ): Promise<StorefrontResponse<Schemas["ShippingAddress"]>>;
  updateShippingAddress(
    addressId: string,
    address: Schemas["ShippingAddressInput"],
    options?: BrowserPrizeShippingNonRetryableMutationOptions,
  ): Promise<StorefrontResponse<Schemas["ShippingAddress"]>>;
  deleteShippingAddress(
    addressId: string,
    options?: BrowserPrizeShippingNonRetryableMutationOptions,
  ): Promise<StorefrontResponse<{ deleted: true }>>;
  listShippingRequests(
    cursor?: string,
  ): Promise<StorefrontResponse<Schemas["ShippingRequestCollection"]>>;
  getShippingRequest(
    requestId: string,
  ): Promise<StorefrontResponse<Schemas["ShippingRequestDetail"]>>;
  createShippingRequest(
    addressId: string,
    prizeIds: string[],
    options: BrowserPrizeShippingMutationOptions,
  ): Promise<StorefrontResponse<Schemas["ShippingRequestSummary"]>>;
}

function segment(value: string, name: string): string {
  if (!value || value.includes("/") || value.includes("\0")) {
    throw new TypeError(`${name} is invalid`);
  }
  return encodeURIComponent(value);
}

function csrf(value: string): Record<string, string> {
  if (!/^[0-9a-f]{64}$/.test(value)) {
    throw new TypeError("csrf_token is invalid");
  }
  return { "X-XSRF-TOKEN": value };
}

function cursor(value?: string): string {
  return value === undefined ? "" : `?cursor=${encodeURIComponent(value)}`;
}

export function createStorefrontPrizeShippingClient(
  transport: StorefrontTransport,
): StorefrontPrizeShippingClient {
  return {
    listPrizes: (value) =>
      transport.request({ path: `/me/prizes${cursor(value)}` }),
    getPrize: (id) =>
      transport.request({ path: `/me/prizes/${segment(id, "prize_id")}` }),
    exchangePrizes: (prizeIds, options) =>
      transport.request({
        path: "/me/prizes/exchange",
        method: "POST",
        body: { prize_ids: prizeIds },
        headers: csrf(options.csrf_token),
        idempotency_key: options.idempotency_key,
        csrf: "required",
        signal: options.signal,
        timeout_ms: options.timeout_ms,
      }),
    listShippingAddresses: () =>
      transport.request({ path: "/me/shipping-addresses" }),
    getShippingAddress: (id) =>
      transport.request({
        path: `/me/shipping-addresses/${segment(id, "address_id")}`,
      }),
    createShippingAddress: (address, options) =>
      transport.request({
        path: "/me/shipping-addresses",
        method: "POST",
        body: address,
        headers: csrf(options.csrf_token),
        idempotency_key: options.idempotency_key,
        csrf: "required",
        signal: options.signal,
        timeout_ms: options.timeout_ms,
      }),
    updateShippingAddress: (id, address, options) =>
      transport.request({
        path: `/me/shipping-addresses/${segment(id, "address_id")}`,
        method: "PUT",
        body: address,
        headers: csrf(options.csrf_token),
        csrf: "required",
        signal: options.signal,
        timeout_ms: options.timeout_ms,
        retry: false,
      }),
    deleteShippingAddress: (id, options) =>
      transport.request({
        path: `/me/shipping-addresses/${segment(id, "address_id")}`,
        method: "DELETE",
        headers: csrf(options.csrf_token),
        csrf: "required",
        signal: options.signal,
        timeout_ms: options.timeout_ms,
        retry: false,
      }),
    listShippingRequests: (value) =>
      transport.request({ path: `/me/shipping-requests${cursor(value)}` }),
    getShippingRequest: (id) =>
      transport.request({
        path: `/me/shipping-requests/${segment(id, "shipping_request_id")}`,
      }),
    createShippingRequest: (addressId, prizeIds, options) =>
      transport.request({
        path: "/me/shipping-requests",
        method: "POST",
        body: { shipping_address_id: addressId, prize_ids: prizeIds },
        headers: csrf(options.csrf_token),
        idempotency_key: options.idempotency_key,
        csrf: "required",
        signal: options.signal,
        timeout_ms: options.timeout_ms,
      }),
  };
}

export function createCsrfManagedStorefrontPrizeShippingClient(
  transport: StorefrontTransport,
): BrowserStorefrontPrizeShippingClient {
  return {
    listPrizes: (value) =>
      transport.request({ path: `/me/prizes${cursor(value)}` }),
    getPrize: (id) =>
      transport.request({ path: `/me/prizes/${segment(id, "prize_id")}` }),
    exchangePrizes: (prizeIds, options) =>
      transport.request({
        path: "/me/prizes/exchange",
        method: "POST",
        body: { prize_ids: prizeIds },
        idempotency_key: options.idempotency_key,
        csrf: "required",
        signal: options.signal,
        timeout_ms: options.timeout_ms,
      }),
    listShippingAddresses: () =>
      transport.request({ path: "/me/shipping-addresses" }),
    getShippingAddress: (id) =>
      transport.request({
        path: `/me/shipping-addresses/${segment(id, "address_id")}`,
      }),
    createShippingAddress: (address, options) =>
      transport.request({
        path: "/me/shipping-addresses",
        method: "POST",
        body: address,
        idempotency_key: options.idempotency_key,
        csrf: "required",
        signal: options.signal,
        timeout_ms: options.timeout_ms,
      }),
    updateShippingAddress: (id, address, options = {}) =>
      transport.request({
        path: `/me/shipping-addresses/${segment(id, "address_id")}`,
        method: "PUT",
        body: address,
        csrf: "required",
        signal: options.signal,
        timeout_ms: options.timeout_ms,
        retry: false,
      }),
    deleteShippingAddress: (id, options = {}) =>
      transport.request({
        path: `/me/shipping-addresses/${segment(id, "address_id")}`,
        method: "DELETE",
        csrf: "required",
        signal: options.signal,
        timeout_ms: options.timeout_ms,
        retry: false,
      }),
    listShippingRequests: (value) =>
      transport.request({ path: `/me/shipping-requests${cursor(value)}` }),
    getShippingRequest: (id) =>
      transport.request({
        path: `/me/shipping-requests/${segment(id, "shipping_request_id")}`,
      }),
    createShippingRequest: (addressId, prizeIds, options) =>
      transport.request({
        path: "/me/shipping-requests",
        method: "POST",
        body: { shipping_address_id: addressId, prize_ids: prizeIds },
        idempotency_key: options.idempotency_key,
        csrf: "required",
        signal: options.signal,
        timeout_ms: options.timeout_ms,
      }),
  };
}
