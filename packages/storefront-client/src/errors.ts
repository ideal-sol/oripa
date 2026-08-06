import type { StorefrontResponseMetadata } from "./types.js";

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

export type AuthProblemCode =
  | "AUTH_SERVICE_UNAVAILABLE"
  | "AUTHENTICATION_REQUIRED"
  | "CSRF_TOKEN_MISMATCH"
  | "EMAIL_ALREADY_CLAIMED"
  | "EMAIL_VERIFICATION_REQUIRED"
  | "INVALID_CREDENTIALS"
  | "INVALID_REDIRECT"
  | "INVALID_REQUEST"
  | "INVALID_VERIFICATION_LINK"
  | "RATE_LIMITED"
  | "SESSION_EXPIRED"
  | "UNSUPPORTED_MEDIA_TYPE"
  | "VERIFICATION_LINK_EXPIRED";

const AUTH_PROBLEM_CODES: ReadonlySet<string> = new Set<AuthProblemCode>([
  "AUTH_SERVICE_UNAVAILABLE",
  "AUTHENTICATION_REQUIRED",
  "CSRF_TOKEN_MISMATCH",
  "EMAIL_ALREADY_CLAIMED",
  "EMAIL_VERIFICATION_REQUIRED",
  "INVALID_CREDENTIALS",
  "INVALID_REDIRECT",
  "INVALID_REQUEST",
  "INVALID_VERIFICATION_LINK",
  "RATE_LIMITED",
  "SESSION_EXPIRED",
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
