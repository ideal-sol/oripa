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

export type StorefrontTransportErrorCode =
  | "ABORTED"
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
