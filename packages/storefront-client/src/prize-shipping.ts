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
}

export interface StorefrontPrizeShippingClient {
  listPrizes(cursor?: string): Promise<StorefrontResponse<Schemas["UserPrizeCollection"]>>;
  getPrize(prizeId: string): Promise<StorefrontResponse<Schemas["UserPrizeDetail"]>>;
  exchangePrizes(
    prizeIds: string[],
    options: Required<Pick<PrizeShippingMutationOptions, "csrf_token" | "idempotency_key">>,
  ): Promise<StorefrontResponse<Schemas["PrizeExchangeResponse"]>>;
  listShippingAddresses(): Promise<
    StorefrontResponse<Schemas["ShippingAddressCollection"]>
  >;
  getShippingAddress(
    addressId: string,
  ): Promise<StorefrontResponse<Schemas["ShippingAddress"]>>;
  createShippingAddress(
    address: Schemas["ShippingAddressInput"],
    options: Pick<PrizeShippingMutationOptions, "csrf_token">,
  ): Promise<StorefrontResponse<Schemas["ShippingAddress"]>>;
  updateShippingAddress(
    addressId: string,
    address: Schemas["ShippingAddressInput"],
    options: Pick<PrizeShippingMutationOptions, "csrf_token">,
  ): Promise<StorefrontResponse<Schemas["ShippingAddress"]>>;
  deleteShippingAddress(
    addressId: string,
    options: Pick<PrizeShippingMutationOptions, "csrf_token">,
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
    options: Required<Pick<PrizeShippingMutationOptions, "csrf_token" | "idempotency_key">>,
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
        csrf: "required",
      }),
    updateShippingAddress: (id, address, options) =>
      transport.request({
        path: `/me/shipping-addresses/${segment(id, "address_id")}`,
        method: "PUT",
        body: address,
        headers: csrf(options.csrf_token),
        csrf: "required",
      }),
    deleteShippingAddress: (id, options) =>
      transport.request({
        path: `/me/shipping-addresses/${segment(id, "address_id")}`,
        method: "DELETE",
        headers: csrf(options.csrf_token),
        csrf: "required",
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
      }),
  };
}
