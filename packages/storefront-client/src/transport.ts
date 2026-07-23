import {
  API_VERSION_HEADER,
  CLIENT_VERSION_HEADER,
  IDEMPOTENCY_KEY_HEADER,
  IDEMPOTENCY_REPLAYED_HEADER,
  REQUEST_ID_HEADER,
  RETRY_AFTER_HEADER,
  SITE_VERSION_HEADER,
  STOREFRONT_CLIENT_VERSION,
} from "./constants.js";
import {
  ApiProblemError,
  StorefrontTransportError,
  type ApiProblem,
} from "./errors.js";
import type {
  CsrfInitializer,
  StorefrontClientConfig,
  StorefrontHttpMethod,
  StorefrontRequestOptions,
  StorefrontResponse,
  StorefrontResponseMetadata,
  StorefrontTransport,
} from "./types.js";

const RETRYABLE_STATUS = new Set([502, 503, 504]);
const SAFE_METHODS = new Set<StorefrontHttpMethod>(["GET", "HEAD"]);

interface TransportConfiguration extends StorefrontClientConfig {
  credentials: RequestCredentials;
  cookie_header?: string;
  csrf_initializer?: CsrfInitializer;
  server_safe_only: boolean;
}

interface RequestSignal {
  signal: AbortSignal;
  cleanup: () => void;
  timed_out: () => boolean;
  externally_aborted: () => boolean;
}

function positiveInteger(value: number, name: string): number {
  if (!Number.isSafeInteger(value) || value <= 0) {
    throw new TypeError(`${name} must be a positive integer`);
  }
  return value;
}

function validateVersion(value: string, name: string): string {
  if (!/^[0-9]+\.[0-9]+\.[0-9]+(?:-[0-9A-Za-z]+(?:\.[0-9A-Za-z]+)*)?$/.test(value)) {
    throw new TypeError(`${name} must be a semantic version`);
  }
  return value;
}

function normalizeBaseUrl(value: string): string {
  if (!value || value.endsWith("/")) {
    throw new TypeError("base_url must be non-empty and must not end with /");
  }
  if (/^[a-z][a-z0-9+.-]*:/i.test(value)) {
    const url = new URL(value);
    if (!["http:", "https:"].includes(url.protocol)) {
      throw new TypeError("base_url protocol must be http or https");
    }
  } else if (!value.startsWith("/") || value.startsWith("//")) {
    throw new TypeError("relative base_url must start with one /");
  }
  return value;
}

function requestUrl(baseUrl: string, path: string): string {
  if (!path.startsWith("/") || path.startsWith("//") || /^[a-z][a-z0-9+.-]*:/i.test(path)) {
    throw new TypeError("request path must be a relative absolute-path");
  }
  return `${baseUrl}${path}`;
}

function requestSignal(
  external: AbortSignal | undefined,
  timeoutMs: number,
): RequestSignal {
  const controller = new AbortController();
  let timedOut = false;
  let externallyAborted = false;
  const onAbort = (): void => {
    externallyAborted = true;
    controller.abort(external?.reason);
  };
  if (external?.aborted) {
    onAbort();
  } else {
    external?.addEventListener("abort", onAbort, { once: true });
  }
  const timer = setTimeout(() => {
    timedOut = true;
    controller.abort(new DOMException("Request timeout", "TimeoutError"));
  }, timeoutMs);
  return {
    signal: controller.signal,
    cleanup: () => {
      clearTimeout(timer);
      external?.removeEventListener("abort", onAbort);
    },
    timed_out: () => timedOut,
    externally_aborted: () => externallyAborted,
  };
}

function retryLimit(
  method: StorefrontHttpMethod,
  idempotencyKey: string | undefined,
  enabled: boolean,
): number {
  if (!enabled) {
    return 0;
  }
  if (SAFE_METHODS.has(method)) {
    return 2;
  }
  return idempotencyKey ? 1 : 0;
}

function jitter(maximum: number): number {
  const values = new Uint32Array(1);
  globalThis.crypto.getRandomValues(values);
  return values[0] % (maximum + 1);
}

async function waitBeforeRetry(retryIndex: number): Promise<void> {
  const delay = 100 * 2 ** retryIndex + jitter(50);
  await new Promise((resolve) => setTimeout(resolve, delay));
}

function retryAfterSeconds(headers: Headers): number | undefined {
  const value = headers.get(RETRY_AFTER_HEADER);
  if (value === null || !/^[0-9]+$/.test(value)) {
    return undefined;
  }
  return Number(value);
}

function responseMetadata(response: Response): StorefrontResponseMetadata {
  return {
    status: response.status,
    request_id: response.headers.get(REQUEST_ID_HEADER) ?? undefined,
    api_version: response.headers.get(API_VERSION_HEADER) ?? undefined,
    idempotency_replayed:
      response.headers.get(IDEMPOTENCY_REPLAYED_HEADER)?.toLowerCase() === "true",
    retry_after_seconds: retryAfterSeconds(response.headers),
  };
}

function isProblem(value: unknown): value is ApiProblem {
  if (typeof value !== "object" || value === null) {
    return false;
  }
  const problem = value as Record<string, unknown>;
  return (
    typeof problem.type === "string" &&
    typeof problem.title === "string" &&
    typeof problem.status === "number" &&
    typeof problem.code === "string" &&
    typeof problem.request_id === "string" &&
    typeof problem.retryable === "boolean"
  );
}

async function parseResponse<T>(
  response: Response,
  method: StorefrontHttpMethod,
): Promise<StorefrontResponse<T>> {
  const metadata = responseMetadata(response);
  if (!response.ok) {
    const contentType = response.headers.get("Content-Type")?.split(";", 1)[0];
    if (contentType === "application/problem+json") {
      try {
        const problem: unknown = await response.json();
        if (isProblem(problem)) {
          throw new ApiProblemError(problem);
        }
      } catch (error) {
        if (error instanceof ApiProblemError) {
          throw error;
        }
      }
    }
    throw new StorefrontTransportError(
      "HTTP_ERROR",
      `HTTP request failed with status ${response.status}`,
      { metadata },
    );
  }
  const data =
    method === "HEAD" || response.status === 204
      ? (undefined as T)
      : ((await response.json()) as T);
  return { data, metadata };
}

function requestBody(body: unknown): BodyInit | undefined {
  if (body === undefined) {
    return undefined;
  }
  return JSON.stringify(body);
}

function requestHeaders(
  configuration: TransportConfiguration,
  options: StorefrontRequestOptions,
  hasBody: boolean,
): Headers {
  const headers = new Headers(options.headers);
  if (headers.has("Authorization")) {
    throw new TypeError("Authorization header is not accepted by Storefront Client");
  }
  headers.set("Accept", "application/json");
  headers.set(
    CLIENT_VERSION_HEADER,
    validateVersion(
      configuration.client_version ?? STOREFRONT_CLIENT_VERSION,
      "client_version",
    ),
  );
  headers.set(
    SITE_VERSION_HEADER,
    validateVersion(configuration.site_version, "site_version"),
  );
  if (hasBody) {
    headers.set("Content-Type", "application/json");
  }
  if (options.idempotency_key) {
    if (
      options.idempotency_key.length < 16 ||
      options.idempotency_key.length > 128
    ) {
      throw new TypeError("idempotency_key must contain 16 through 128 characters");
    }
    headers.set(IDEMPOTENCY_KEY_HEADER, options.idempotency_key);
  }
  if (configuration.cookie_header) {
    headers.set("Cookie", configuration.cookie_header);
  }
  return headers;
}

export function createTransport(
  input: TransportConfiguration,
): StorefrontTransport {
  const configuration = {
    ...input,
    base_url: normalizeBaseUrl(input.base_url),
    default_timeout_ms: positiveInteger(
      input.default_timeout_ms,
      "default_timeout_ms",
    ),
    fetch: input.fetch ?? globalThis.fetch,
  };
  if (typeof configuration.fetch !== "function") {
    throw new TypeError("fetch implementation is unavailable");
  }

  let csrfInitialization: Promise<void> | undefined;
  const initializeCsrf = (signal: AbortSignal): Promise<void> => {
    if (!configuration.csrf_initializer) {
      throw new TypeError(
        "csrf_initializer is required when csrf is marked as required",
      );
    }
    csrfInitialization ??= configuration
      .csrf_initializer({ signal })
      .catch((error: unknown) => {
        csrfInitialization = undefined;
        throw error;
      });
    return csrfInitialization;
  };

  return {
    async request<T>(
      options: StorefrontRequestOptions,
    ): Promise<StorefrontResponse<T>> {
      const method = options.method ?? "GET";
      if (configuration.server_safe_only && !SAFE_METHODS.has(method)) {
        throw new TypeError("Server Storefront Client allows only GET and HEAD");
      }
      const timeoutMs = positiveInteger(
        options.timeout_ms ?? configuration.default_timeout_ms,
        "timeout_ms",
      );
      const body = requestBody(options.body);
      const headers = requestHeaders(configuration, options, body !== undefined);
      const url = requestUrl(configuration.base_url, options.path);
      const retries = retryLimit(
        method,
        options.idempotency_key,
        options.retry !== false,
      );

      if (options.csrf === "required") {
        if (SAFE_METHODS.has(method)) {
          throw new TypeError("CSRF initialization is only valid for mutations");
        }
        const csrfSignal = requestSignal(options.signal, timeoutMs);
        try {
          await initializeCsrf(csrfSignal.signal);
        } catch (error) {
          if (csrfSignal.externally_aborted()) {
            throw new StorefrontTransportError(
              "ABORTED",
              "CSRF initialization was aborted by the caller",
              { cause: error },
            );
          }
          if (csrfSignal.timed_out()) {
            throw new StorefrontTransportError(
              "TIMEOUT",
              "CSRF initialization exceeded timeout_ms",
              { cause: error },
            );
          }
          throw error;
        } finally {
          csrfSignal.cleanup();
        }
      }

      for (let attempt = 0; ; attempt += 1) {
        const attemptSignal = requestSignal(options.signal, timeoutMs);
        try {
          const response = await configuration.fetch(
            url,
            {
              method,
              headers,
              body,
              credentials: configuration.credentials,
              signal: attemptSignal.signal,
            },
          );
          if (attempt < retries && RETRYABLE_STATUS.has(response.status)) {
            await response.body?.cancel();
            await waitBeforeRetry(attempt);
            continue;
          }
          return await parseResponse<T>(response, method);
        } catch (error) {
          if (
            error instanceof ApiProblemError ||
            error instanceof StorefrontTransportError
          ) {
            throw error;
          }
          if (attemptSignal.externally_aborted()) {
            throw new StorefrontTransportError(
              "ABORTED",
              "Request was aborted by the caller",
              { cause: error },
            );
          }
          if (attemptSignal.timed_out()) {
            if (attempt < retries) {
              await waitBeforeRetry(attempt);
              continue;
            }
            throw new StorefrontTransportError(
              "TIMEOUT",
              "Request exceeded timeout_ms",
              { cause: error },
            );
          }
          if (attempt < retries) {
            await waitBeforeRetry(attempt);
            continue;
          }
          throw new StorefrontTransportError(
            "NETWORK_ERROR",
            "Network request failed",
            { cause: error },
          );
        } finally {
          attemptSignal.cleanup();
        }
      }
    },
  };
}

export function createIdempotencyKey(): string {
  return globalThis.crypto.randomUUID();
}
