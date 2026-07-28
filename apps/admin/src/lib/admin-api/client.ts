import {
  ADMIN_API_BASE_PATH,
  type AdminLoginRequest,
  type AdminMfaVerifyRequest,
  type AdminPreauth,
  type AdminReauthenticationRequest,
  type AdminReauthenticationResponse,
  type AdminSession,
  type ProblemDetails,
  type RecoveryCodes,
  type StatusResponse,
  type TotpConfirmation,
  type TotpEnrollment,
  type WebauthnOptions,
  type WebauthnOptionsRequest,
  type WebauthnRegistration,
} from "./generated";

const ADMIN_CSRF_COOKIE = "__Host-oripa_admin_xsrf";
const REQUEST_TIMEOUT_MS = 10_000;

type FetchImplementation = typeof fetch;

interface RequestOptions {
  body?: unknown;
  signal?: AbortSignal;
  transactionToken?: string;
}

export class AdminApiError extends Error {
  constructor(
    readonly status: number,
    readonly code: string,
    readonly requestId: string | null,
    readonly retryAfter: number | null,
    readonly retryable: boolean,
  ) {
    super(publicErrorMessage(status, code));
    this.name = "AdminApiError";
  }

  get isSessionExpired(): boolean {
    return this.status === 401;
  }

  get requiresFreshMfa(): boolean {
    return this.status === 403 && this.code === "FRESH_AUTHENTICATION_REQUIRED";
  }
}

export class AdminApiClient {
  constructor(
    private readonly fetchImplementation: FetchImplementation = fetch,
    private readonly csrfToken: () => string | null = readAdminCsrfCookie,
  ) {}

  getSession(signal?: AbortSignal): Promise<AdminSession> {
    return this.request("GET", "/auth/session", { signal });
  }

  login(body: AdminLoginRequest, signal?: AbortSignal): Promise<AdminPreauth> {
    return this.request("POST", "/auth/login", { body, signal });
  }

  verifyMfa(
    transactionToken: string,
    body: AdminMfaVerifyRequest,
    signal?: AbortSignal,
  ): Promise<AdminSession> {
    return this.request("POST", "/auth/mfa/verify", {
      body,
      signal,
      transactionToken,
    });
  }

  beginTotp(
    transactionToken: string,
    signal?: AbortSignal,
  ): Promise<TotpEnrollment> {
    return this.request("POST", "/auth/mfa/totp", {
      body: {},
      signal,
      transactionToken,
    });
  }

  confirmTotp(
    transactionToken: string,
    body: TotpConfirmation,
    signal?: AbortSignal,
  ): Promise<StatusResponse> {
    return this.request("POST", "/auth/mfa/totp/confirm", {
      body,
      signal,
      transactionToken,
    });
  }

  createWebauthnOptions(
    transactionToken: string,
    body: WebauthnOptionsRequest,
    signal?: AbortSignal,
  ): Promise<WebauthnOptions> {
    return this.request("POST", "/auth/mfa/webauthn/options", {
      body,
      signal,
      transactionToken,
    });
  }

  registerWebauthn(
    transactionToken: string,
    body: WebauthnRegistration,
    signal?: AbortSignal,
  ): Promise<StatusResponse> {
    return this.request("POST", "/auth/mfa/webauthn", {
      body,
      signal,
      transactionToken,
    });
  }

  regenerateRecoveryCodes(signal?: AbortSignal): Promise<RecoveryCodes> {
    return this.request("POST", "/auth/mfa/recovery-codes/regenerate", {
      body: {},
      signal,
    });
  }

  createReauthenticationWebauthnOptions(
    signal?: AbortSignal,
  ): Promise<WebauthnOptions> {
    return this.request("POST", "/auth/reauthenticate/webauthn/options", {
      body: {},
      signal,
    });
  }

  reauthenticate(
    body: AdminReauthenticationRequest,
    signal?: AbortSignal,
  ): Promise<AdminReauthenticationResponse> {
    return this.request("POST", "/auth/reauthenticate", { body, signal });
  }

  async logout(signal?: AbortSignal): Promise<void> {
    await this.request("POST", "/auth/logout", { body: {}, signal });
  }

  private async request<T>(
    method: "GET" | "POST",
    path: `/auth/${string}`,
    options: RequestOptions = {},
  ): Promise<T> {
    if (!path.startsWith("/auth/") || path.includes("://") || path.includes("..")) {
      throw new Error("Admin API path is outside the approved surface.");
    }
    const requestId = crypto.randomUUID();
    const headers = new Headers({
      Accept: "application/json, application/problem+json",
      "X-Request-Id": requestId,
    });
    if (method !== "GET") {
      const csrf = this.csrfToken();
      if (!csrf) {
        throw new AdminApiError(403, "CSRF_TOKEN_MISSING", requestId, null, false);
      }
      headers.set("Content-Type", "application/json");
      headers.set("X-XSRF-TOKEN", csrf);
    }
    if (options.transactionToken) {
      headers.set("X-Oripa-Auth-Transaction", options.transactionToken);
    }

    const timeout = new AbortController();
    const timeoutId = window.setTimeout(() => timeout.abort("timeout"), REQUEST_TIMEOUT_MS);
    const abort = () => timeout.abort(options.signal?.reason);
    if (options.signal?.aborted) {
      abort();
    } else {
      options.signal?.addEventListener("abort", abort, { once: true });
    }
    try {
      timeout.signal.throwIfAborted();
      const response = await this.fetchImplementation.call(
        globalThis,
        `${ADMIN_API_BASE_PATH}${path}`,
        {
          body: method === "GET" ? undefined : JSON.stringify(options.body ?? {}),
          cache: "no-store",
          credentials: "include",
          headers,
          method,
          redirect: "error",
          signal: timeout.signal,
        },
      );
      const responseRequestId = response.headers.get("X-Request-Id") ?? requestId;
      if (!response.ok) {
        throw await toAdminApiError(response, responseRequestId);
      }
      if (response.status === 204) {
        return undefined as T;
      }
      return (await response.json()) as T;
    } catch (error) {
      if (error instanceof AdminApiError) {
        throw error;
      }
      if (timeout.signal.aborted) {
        throw new AdminApiError(0, "REQUEST_ABORTED", requestId, null, true);
      }
      throw new AdminApiError(0, "NETWORK_ERROR", requestId, null, true);
    } finally {
      window.clearTimeout(timeoutId);
      options.signal?.removeEventListener("abort", abort);
    }
  }
}

export function readAdminCsrfCookie(): string | null {
  if (typeof document === "undefined") {
    return null;
  }
  for (const item of document.cookie.split(";")) {
    const [name, ...value] = item.trim().split("=");
    if (name === ADMIN_CSRF_COOKIE) {
      const token = decodeURIComponent(value.join("="));
      return /^[0-9a-f]{64}$/.test(token) ? token : null;
    }
  }
  return null;
}

async function toAdminApiError(
  response: Response,
  requestId: string,
): Promise<AdminApiError> {
  let problem: ProblemDetails = {};
  const contentType = response.headers.get("Content-Type") ?? "";
  if (contentType.includes("application/problem+json") || contentType.includes("application/json")) {
    problem = (await response.json().catch(() => ({}))) as ProblemDetails;
  }
  const retryHeader = response.headers.get("Retry-After");
  const retryAfter =
    problem.retry_after ??
    (retryHeader && /^\d+$/.test(retryHeader) ? Number(retryHeader) : null);
  return new AdminApiError(
    response.status,
    problem.code ?? statusCode(response.status),
    problem.request_id ?? requestId,
    retryAfter,
    problem.retryable === true,
  );
}

function statusCode(status: number): string {
  if (status === 401) return "AUTHENTICATION_REQUIRED";
  if (status === 403) return "AUTHORIZATION_DENIED";
  if (status === 429) return "RATE_LIMITED";
  return "ADMIN_API_ERROR";
}

function publicErrorMessage(status: number, code: string): string {
  if (status === 401) return "管理セッションの有効期限が切れました。";
  if (status === 403 && code === "FRESH_AUTHENTICATION_REQUIRED") {
    return "続行するには多要素認証を再確認してください。";
  }
  if (status === 403) return "この操作を実行する権限がありません。";
  if (status === 429) return "試行回数が上限に達しました。時間をおいてください。";
  if (code === "REQUEST_ABORTED") return "リクエストを中止しました。";
  if (code === "NETWORK_ERROR") return "管理APIへ接続できませんでした。";
  return "管理APIで処理できませんでした。";
}
