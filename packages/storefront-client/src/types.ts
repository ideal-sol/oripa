export type {
  components as PublicComponents,
  operations as PublicOperations,
  paths as PublicPaths,
} from "./generated/public.js";

export type StorefrontHttpMethod =
  | "GET"
  | "HEAD"
  | "POST"
  | "PUT"
  | "PATCH"
  | "DELETE";

export interface StorefrontRequestOptions {
  path: string;
  method?: StorefrontHttpMethod;
  headers?: HeadersInit;
  body?: unknown;
  signal?: AbortSignal;
  timeout_ms?: number;
  idempotency_key?: string;
  csrf?: "none" | "required";
  retry?: boolean;
}

export interface StorefrontResponseMetadata {
  status: number;
  request_id?: string;
  api_version?: string;
  idempotency_replayed: boolean;
  retry_after_seconds?: number;
}

export interface StorefrontResponse<T> {
  data: T;
  metadata: StorefrontResponseMetadata;
}

export interface CsrfInitializationContext {
  signal: AbortSignal;
}

export type CsrfInitializer = (
  context: CsrfInitializationContext,
) => Promise<void>;

export interface StorefrontClientConfig {
  base_url: string;
  site_version: string;
  default_timeout_ms: number;
  client_version?: string;
  fetch?: typeof globalThis.fetch;
}

export interface BrowserStorefrontClientConfig extends StorefrontClientConfig {
  csrf_initializer?: CsrfInitializer;
}

export interface ServerStorefrontClientConfig extends StorefrontClientConfig {
  cookie_header?: string;
}

export interface StorefrontTransport {
  request<T = unknown>(
    options: StorefrontRequestOptions,
  ): Promise<StorefrontResponse<T>>;
}
