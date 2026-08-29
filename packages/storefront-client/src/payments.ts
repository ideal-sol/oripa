import type {
  PublicComponents,
  StorefrontResponse,
  StorefrontTransport,
} from "./types.js";

type Schemas = PublicComponents["schemas"];

export interface PaymentHistoryQuery {
  view: "succeeded" | "unpaid";
  limit?: number;
  cursor?: string;
}

export interface PaymentMutationOptions {
  csrf_token: string;
  idempotency_key: string;
  signal?: AbortSignal;
  timeout_ms?: number;
}

export interface PaymentCsrfOptions {
  csrf_token: string;
  signal?: AbortSignal;
  timeout_ms?: number;
}

export interface BrowserPaymentMutationOptions {
  idempotency_key: string;
  signal?: AbortSignal;
  timeout_ms?: number;
}

export interface BrowserPaymentCsrfOptions {
  signal?: AbortSignal;
  timeout_ms?: number;
}

export const CARD_REGISTRATION_TERMINAL_STATUSES = [
  "completed",
  "failed",
  "canceled",
  "expired",
] as const satisfies readonly Schemas["PaymentCardRegistrationStatus"][];

export const CARD_REGISTRATION_INCOMPLETE_STATUSES = [
  "pending",
  "requires_action",
] as const satisfies readonly Schemas["PaymentCardRegistrationStatus"][];

export interface StorefrontPaymentClient {
  getPaymentCardUiBootstrap(): Promise<
    StorefrontResponse<Schemas["PaymentCardUiBootstrap"]>
  >;
  startPayment(
    input: Schemas["PaymentCreateRequest"],
    options: PaymentMutationOptions,
  ): Promise<StorefrontResponse<Schemas["Payment"]>>;
  getPayment(paymentId: string): Promise<StorefrontResponse<Schemas["Payment"]>>;
  listPayments(
    query: PaymentHistoryQuery,
  ): Promise<StorefrontResponse<Schemas["PaymentCollection"]>>;
  resumeUnpaidPayment(
    paymentId: string,
    options: PaymentCsrfOptions,
  ): Promise<StorefrontResponse<Schemas["PaymentResume"]>>;
  listCards(): Promise<StorefrontResponse<Schemas["PaymentCardCollection"]>>;
  startCardRegistration(
    input: Schemas["PaymentCardRegistrationStartRequest"],
    options: PaymentMutationOptions,
  ): Promise<StorefrontResponse<Schemas["PaymentCardRegistration"]>>;
  getCardRegistration(
    registrationId: string,
  ): Promise<StorefrontResponse<Schemas["PaymentCardRegistration"]>>;
  reconcileCardRegistration(
    registrationId: string,
    options: PaymentCsrfOptions,
  ): Promise<StorefrontResponse<Schemas["PaymentCardRegistration"]>>;
  cancelCardRegistration(
    registrationId: string,
    options: PaymentCsrfOptions,
  ): Promise<StorefrontResponse<Schemas["PaymentCardRegistration"]>>;
  /** @deprecated Use startCardRegistration. Legacy Browser registerCard() cannot prove Registration 3DS2. */
  createCardRegistrationIntent(
    options: PaymentMutationOptions,
  ): Promise<StorefrontResponse<Schemas["PaymentCardRegistrationIntent"]>>;
  /** @deprecated Always fails closed with CARD_REGISTRATION_3DS_REQUIRED after canonical activation. */
  completeCardRegistration(
    registrationIntentId: string,
    input: Schemas["PaymentCardRegistrationCompleteRequest"],
    options: PaymentCsrfOptions,
  ): Promise<StorefrontResponse<Schemas["PaymentCard"]>>;
  deleteCard(cardId: string, options: PaymentCsrfOptions): Promise<StorefrontResponse<void>>;
}

export interface BrowserStorefrontPaymentClient {
  getPaymentCardUiBootstrap(): Promise<
    StorefrontResponse<Schemas["PaymentCardUiBootstrap"]>
  >;
  startPayment(
    input: Schemas["PaymentCreateRequest"],
    options: BrowserPaymentMutationOptions,
  ): Promise<StorefrontResponse<Schemas["Payment"]>>;
  getPayment(paymentId: string): Promise<StorefrontResponse<Schemas["Payment"]>>;
  listPayments(
    query: PaymentHistoryQuery,
  ): Promise<StorefrontResponse<Schemas["PaymentCollection"]>>;
  resumeUnpaidPayment(
    paymentId: string,
    options?: BrowserPaymentCsrfOptions,
  ): Promise<StorefrontResponse<Schemas["PaymentResume"]>>;
  listCards(): Promise<StorefrontResponse<Schemas["PaymentCardCollection"]>>;
  startCardRegistration(
    input: Schemas["PaymentCardRegistrationStartRequest"],
    options: BrowserPaymentMutationOptions,
  ): Promise<StorefrontResponse<Schemas["PaymentCardRegistration"]>>;
  getCardRegistration(
    registrationId: string,
  ): Promise<StorefrontResponse<Schemas["PaymentCardRegistration"]>>;
  reconcileCardRegistration(
    registrationId: string,
    options?: BrowserPaymentCsrfOptions,
  ): Promise<StorefrontResponse<Schemas["PaymentCardRegistration"]>>;
  cancelCardRegistration(
    registrationId: string,
    options?: BrowserPaymentCsrfOptions,
  ): Promise<StorefrontResponse<Schemas["PaymentCardRegistration"]>>;
  /** @deprecated Use startCardRegistration. Legacy Browser registerCard() cannot prove Registration 3DS2. */
  createCardRegistrationIntent(
    options: BrowserPaymentMutationOptions,
  ): Promise<StorefrontResponse<Schemas["PaymentCardRegistrationIntent"]>>;
  /** @deprecated Always fails closed with CARD_REGISTRATION_3DS_REQUIRED after canonical activation. */
  completeCardRegistration(
    registrationIntentId: string,
    input: Schemas["PaymentCardRegistrationCompleteRequest"],
    options?: BrowserPaymentCsrfOptions,
  ): Promise<StorefrontResponse<Schemas["PaymentCard"]>>;
  deleteCard(
    cardId: string,
    options?: BrowserPaymentCsrfOptions,
  ): Promise<StorefrontResponse<void>>;
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

function historyQuery(query: PaymentHistoryQuery): string {
  const parameters = new URLSearchParams({ view: query.view });
  if (!(["succeeded", "unpaid"] as const).includes(query.view)) {
    throw new TypeError("view is invalid");
  }
  if (query.limit !== undefined) {
    if (!Number.isSafeInteger(query.limit) || query.limit < 1 || query.limit > 100) {
      throw new TypeError("limit must be an integer from 1 through 100");
    }
    parameters.set("limit", String(query.limit));
  }
  if (query.cursor !== undefined) {
    parameters.set("cursor", query.cursor);
  }
  return `?${parameters.toString()}`;
}

export function createStorefrontPaymentClient(
  transport: StorefrontTransport,
): StorefrontPaymentClient {
  return {
    getPaymentCardUiBootstrap: () => transport.request({
      path: "/me/payment-card-ui-bootstrap",
    }),
    startPayment: (input, options) => transport.request({
      path: "/payments",
      method: "POST",
      body: input,
      headers: csrf(options.csrf_token),
      idempotency_key: options.idempotency_key,
      csrf: "required",
      signal: options.signal,
      timeout_ms: options.timeout_ms,
    }),
    getPayment: (id) => transport.request({
      path: `/payments/${segment(id, "payment_id")}`,
    }),
    listPayments: (query) => transport.request({
      path: `/me/payments${historyQuery(query)}`,
    }),
    resumeUnpaidPayment: (id, options) => transport.request({
      path: `/payments/${segment(id, "payment_id")}/resume`,
      method: "POST",
      body: {},
      headers: csrf(options.csrf_token),
      csrf: "required",
      retry: false,
      signal: options.signal,
      timeout_ms: options.timeout_ms,
    }),
    listCards: () => transport.request({ path: "/me/payment-cards" }),
    startCardRegistration: (input, options) => transport.request({
      path: "/me/payment-card-registrations",
      method: "POST",
      body: input,
      headers: csrf(options.csrf_token),
      idempotency_key: options.idempotency_key,
      csrf: "required",
      signal: options.signal,
      timeout_ms: options.timeout_ms,
    }),
    getCardRegistration: (id) => transport.request({
      path: `/me/payment-card-registrations/${segment(id, "registration_id")}`,
    }),
    reconcileCardRegistration: (id, options) => transport.request({
      path: `/me/payment-card-registrations/${segment(id, "registration_id")}/reconcile`,
      method: "POST",
      body: {},
      headers: csrf(options.csrf_token),
      csrf: "required",
      retry: false,
      signal: options.signal,
      timeout_ms: options.timeout_ms,
    }),
    cancelCardRegistration: (id, options) => transport.request({
      path: `/me/payment-card-registrations/${segment(id, "registration_id")}/cancel`,
      method: "POST",
      body: {},
      headers: csrf(options.csrf_token),
      csrf: "required",
      retry: false,
      signal: options.signal,
      timeout_ms: options.timeout_ms,
    }),
    createCardRegistrationIntent: (options) => transport.request({
      path: "/me/payment-card-registration-intents",
      method: "POST",
      body: {},
      headers: csrf(options.csrf_token),
      idempotency_key: options.idempotency_key,
      csrf: "required",
      signal: options.signal,
      timeout_ms: options.timeout_ms,
    }),
    completeCardRegistration: (id, input, options) => transport.request({
      path: `/me/payment-card-registration-intents/${segment(id, "registration_intent_id")}/complete`,
      method: "POST",
      body: input,
      headers: csrf(options.csrf_token),
      csrf: "required",
      retry: false,
      signal: options.signal,
      timeout_ms: options.timeout_ms,
    }),
    deleteCard: (id, options) => transport.request({
      path: `/me/payment-cards/${segment(id, "card_id")}`,
      method: "DELETE",
      headers: csrf(options.csrf_token),
      csrf: "required",
      retry: false,
      signal: options.signal,
      timeout_ms: options.timeout_ms,
    }),
  };
}

export function createCsrfManagedStorefrontPaymentClient(
  transport: StorefrontTransport,
): BrowserStorefrontPaymentClient {
  return {
    getPaymentCardUiBootstrap: () => transport.request({
      path: "/me/payment-card-ui-bootstrap",
    }),
    startPayment: (input, options) => transport.request({
      path: "/payments",
      method: "POST",
      body: input,
      idempotency_key: options.idempotency_key,
      csrf: "required",
      signal: options.signal,
      timeout_ms: options.timeout_ms,
    }),
    getPayment: (id) => transport.request({
      path: `/payments/${segment(id, "payment_id")}`,
    }),
    listPayments: (query) => transport.request({
      path: `/me/payments${historyQuery(query)}`,
    }),
    resumeUnpaidPayment: (id, options = {}) => transport.request({
      path: `/payments/${segment(id, "payment_id")}/resume`,
      method: "POST",
      body: {},
      csrf: "required",
      retry: false,
      signal: options.signal,
      timeout_ms: options.timeout_ms,
    }),
    listCards: () => transport.request({ path: "/me/payment-cards" }),
    startCardRegistration: (input, options) => transport.request({
      path: "/me/payment-card-registrations",
      method: "POST",
      body: input,
      idempotency_key: options.idempotency_key,
      csrf: "required",
      signal: options.signal,
      timeout_ms: options.timeout_ms,
    }),
    getCardRegistration: (id) => transport.request({
      path: `/me/payment-card-registrations/${segment(id, "registration_id")}`,
    }),
    reconcileCardRegistration: (id, options = {}) => transport.request({
      path: `/me/payment-card-registrations/${segment(id, "registration_id")}/reconcile`,
      method: "POST",
      body: {},
      csrf: "required",
      retry: false,
      signal: options.signal,
      timeout_ms: options.timeout_ms,
    }),
    cancelCardRegistration: (id, options = {}) => transport.request({
      path: `/me/payment-card-registrations/${segment(id, "registration_id")}/cancel`,
      method: "POST",
      body: {},
      csrf: "required",
      retry: false,
      signal: options.signal,
      timeout_ms: options.timeout_ms,
    }),
    createCardRegistrationIntent: (options) => transport.request({
      path: "/me/payment-card-registration-intents",
      method: "POST",
      body: {},
      idempotency_key: options.idempotency_key,
      csrf: "required",
      signal: options.signal,
      timeout_ms: options.timeout_ms,
    }),
    completeCardRegistration: (id, input, options = {}) => transport.request({
      path: `/me/payment-card-registration-intents/${segment(id, "registration_intent_id")}/complete`,
      method: "POST",
      body: input,
      csrf: "required",
      retry: false,
      signal: options.signal,
      timeout_ms: options.timeout_ms,
    }),
    deleteCard: (id, options = {}) => transport.request({
      path: `/me/payment-cards/${segment(id, "card_id")}`,
      method: "DELETE",
      csrf: "required",
      retry: false,
      signal: options.signal,
      timeout_ms: options.timeout_ms,
    }),
  };
}
