import type {
  PublicComponents,
  StorefrontResponseMetadata,
} from "./types.js";

export interface ApiProblem {
  type: string;
  title: string;
  status: number;
  code: string;
  request_id: string;
  retryable: boolean;
  detail?: string;
  instance?: string;
  errors?: Record<string, string[]>;
  retry_after_seconds?: number;
}

export class ApiProblemError extends Error {
  readonly status: number;
  readonly code: string;
  readonly type: string;
  readonly title: string;
  readonly detail?: string;
  readonly instance?: string;
  readonly request_id: string;
  readonly retryable: boolean;
  readonly retry_after_seconds?: number;
  readonly errors?: Record<string, string[]>;

  constructor(problem: ApiProblem) {
    super(problem.title);
    this.name = "ApiProblemError";
    this.status = problem.status;
    this.code = problem.code;
    this.type = problem.type;
    this.title = problem.title;
    this.detail = problem.detail;
    this.instance = problem.instance;
    this.request_id = problem.request_id;
    this.retryable = problem.retryable;
    this.retry_after_seconds = problem.retry_after_seconds;
    this.errors = problem.errors;
  }
}

export type AuthProblemCode = PublicComponents["schemas"]["PublicAuthProblemCode"];

const AUTH_PROBLEM_CODES: ReadonlySet<string> = new Set<AuthProblemCode>([
  "AUTH_SERVICE_UNAVAILABLE",
  "AUTHENTICATION_REQUIRED",
  "CSRF_TOKEN_MISMATCH",
  "EMAIL_ALREADY_CLAIMED",
  "EMAIL_UNCHANGED",
  "EMAIL_VERIFICATION_REQUIRED",
  "INVALID_CREDENTIALS",
  "INVALID_EMAIL_CHANGE_REQUEST",
  "INVALID_PASSWORD_RESET",
  "INVALID_SMS_VERIFICATION",
  "INVALID_REDIRECT",
  "INVALID_REAUTHENTICATION",
  "INVALID_REQUEST",
  "INVALID_VERIFICATION_LINK",
  "PASSWORD_POLICY_VIOLATION",
  "PASSWORD_UNCHANGED",
  "PHONE_ALREADY_VERIFIED",
  "PHONE_NUMBER_UNAVAILABLE",
  "RATE_LIMITED",
  "SESSION_EXPIRED",
  "SMS_DELIVERY_PENDING",
  "SMS_DELIVERY_UNAVAILABLE",
  "FRESH_AUTHENTICATION_REQUIRED",
  "UNSUPPORTED_MEDIA_TYPE",
  "VERIFICATION_LINK_EXPIRED",
]);

export function isAuthProblemError(
  error: unknown,
  code?: AuthProblemCode,
): error is ApiProblemError & { readonly code: AuthProblemCode } {
  return (
    error instanceof ApiProblemError
    && AUTH_PROBLEM_CODES.has(error.code)
    && (code === undefined || error.code === code)
  );
}

export type DrawProblemCode = PublicComponents["schemas"]["DrawProblemCode"];

const DRAW_PROBLEM_CODES: ReadonlySet<string> = new Set<DrawProblemCode>([
  "AUTHENTICATION_REQUIRED",
  "CSRF_TOKEN_MISMATCH",
  "DAILY_DRAW_LIMIT_EXCEEDED",
  "DRAW_COUNT_INSUFFICIENT",
  "GACHA_AUDIENCE_NOT_ELIGIBLE",
  "GACHA_NOT_DRAWABLE",
  "GACHA_SALES_PAUSED",
  "IDEMPOTENCY_KEY_REUSED",
  "IDEMPOTENCY_REQUEST_IN_PROGRESS",
  "INSUFFICIENT_POINTS",
  "INVALID_DRAW_REQUEST",
  "RATE_LIMITED",
]);

export function isDrawProblemError(
  error: unknown,
  code?: DrawProblemCode,
): error is ApiProblemError & { readonly code: DrawProblemCode } {
  return (
    error instanceof ApiProblemError
    && DRAW_PROBLEM_CODES.has(error.code)
    && (code === undefined || error.code === code)
  );
}

export type FulfillmentProblemCode =
  PublicComponents["schemas"]["FulfillmentProblemCode"];

const FULFILLMENT_PROBLEM_CODES: ReadonlySet<string> =
  new Set<FulfillmentProblemCode>([
    "AUTHENTICATION_REQUIRED",
    "CONCURRENT_OPERATION_RETRY_EXHAUSTED",
    "CSRF_TOKEN_MISMATCH",
    "IDEMPOTENCY_FAILURE",
    "IDEMPOTENCY_KEY_REUSED",
    "IDEMPOTENCY_REQUEST_IN_PROGRESS",
    "INVALID_EXCHANGE_REQUEST",
    "INVALID_IDEMPOTENCY_KEY",
    "INVALID_PRIZE_SELECTION",
    "INVALID_SHIPPING_ADDRESS",
    "INVALID_SHIPPING_REQUEST",
    "PII_PROTECTION_UNAVAILABLE",
    "PRIZE_NOT_EXCHANGEABLE",
    "PRIZE_NOT_SHIPPABLE",
    "PRIZE_ON_PAYMENT_HOLD",
    "RATE_LIMITED",
    "SESSION_EXPIRED",
    "SHIPPING_ADDRESS_NOT_FOUND",
    "SHIPPING_REQUEST_NOT_FOUND",
    "SMS_VERIFICATION_REQUIRED",
    "UNSUPPORTED_MEDIA_TYPE",
    "USER_PRIZE_NOT_FOUND",
  ]);

export function isFulfillmentProblemError(
  error: unknown,
  code?: FulfillmentProblemCode,
): error is ApiProblemError & { readonly code: FulfillmentProblemCode } {
  return (
    error instanceof ApiProblemError
    && FULFILLMENT_PROBLEM_CODES.has(error.code)
    && (code === undefined || error.code === code)
  );
}

export type CardRegistrationProblemCode =
  PublicComponents["schemas"]["CardRegistrationProblemCode"];

const CARD_REGISTRATION_PROBLEM_CODES: ReadonlySet<string> =
  new Set<CardRegistrationProblemCode>([
    "AUTHENTICATION_REQUIRED",
    "CARD_INTENT_EXPIRED",
    "CARD_LIMIT_REACHED",
    "CARD_REFERENCE_INVALID",
    "CARD_REGISTRATION_3DS_REQUIRED",
    "CARD_REGISTRATION_CANCELED",
    "CARD_REGISTRATION_CONFLICT",
    "CARD_REGISTRATION_FAILED",
    "CARD_REGISTRATION_NOT_FOUND",
    "CARD_REGISTRATION_OWNERSHIP_INVALID",
    "CARD_REGISTRATION_REQUEST_INVALID",
    "CARD_REGISTRATION_RETURN_OVERRIDE_FORBIDDEN",
    "CARD_REGISTRATION_UNAVAILABLE",
    "CSRF_TOKEN_MISMATCH",
    "IDEMPOTENCY_KEY_REQUIRED",
    "IDEMPOTENCY_KEY_REUSED",
  ]);

export function isCardRegistrationProblemError(
  error: unknown,
  code?: CardRegistrationProblemCode,
): error is ApiProblemError & { readonly code: CardRegistrationProblemCode } {
  return (
    error instanceof ApiProblemError
    && CARD_REGISTRATION_PROBLEM_CODES.has(error.code)
    && (code === undefined || error.code === code)
  );
}

export type StorefrontTransportErrorCode =
  | "ABORTED"
  | "CSRF_INITIALIZATION_FAILED"
  | "HTTP_ERROR"
  | "NETWORK_ERROR"
  | "TIMEOUT";

export class StorefrontTransportError extends Error {
  readonly code: StorefrontTransportErrorCode;
  readonly metadata?: StorefrontResponseMetadata;
  readonly cause?: unknown;

  constructor(
    code: StorefrontTransportErrorCode,
    message: string,
    options: {
      metadata?: StorefrontResponseMetadata;
      cause?: unknown;
    } = {},
  ) {
    super(message);
    this.name = "StorefrontTransportError";
    this.code = code;
    this.metadata = options.metadata;
    this.cause = options.cause;
  }
}
