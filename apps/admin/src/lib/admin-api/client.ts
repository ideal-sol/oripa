import {
  ADMIN_API_BASE_PATH,
  type AdminEffectivePermissions,
  type AdminCatalogCategory,
  type AdminCatalogCategoryCreate,
  type AdminCatalogCategoryUpdate,
  type AdminCatalogCollection,
  type AdminCatalogDetail,
  type AdminCatalogDirection,
  type AdminCatalogPresentationAsset,
  type AdminCatalogPrize,
  type AdminCatalogRank,
  type AdminCatalogRankCreate,
  type AdminCatalogRankUpdate,
  type AdminCatalogTag,
  type AdminCatalogTagCreate,
  type AdminCatalogTagUpdate,
  type AdminCatalogMutationResult,
  type AdminCatalogVisibility,
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
  idempotencyKey?: string;
  signal?: AbortSignal;
  transactionToken?: string;
}

export interface AdminCatalogQuery {
  cursor?: string;
  direction?: AdminCatalogDirection;
  limit?: number;
  media_type?: "all" | "image" | "video";
  q?: string;
  rank_id?: string;
  sort?: string;
  visibility?: AdminCatalogVisibility;
}

export type AdminCatalogResource =
  | "categories"
  | "tags"
  | "ranks"
  | "prizes"
  | "presentation-assets";

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

  getPermissions(signal?: AbortSignal): Promise<AdminEffectivePermissions> {
    return this.request("GET", "/auth/permissions", { signal });
  }

  listCatalogCategories(
    query: AdminCatalogQuery,
    signal?: AbortSignal,
  ): Promise<AdminCatalogCollection<AdminCatalogCategory>> {
    return this.catalogList("categories", query, signal);
  }

  getCatalogCategory(
    id: string,
    signal?: AbortSignal,
  ): Promise<AdminCatalogDetail<AdminCatalogCategory>> {
    return this.catalogDetail("categories", id, signal);
  }

  createCatalogCategory(
    body: AdminCatalogCategoryCreate,
    idempotencyKey: string,
    signal?: AbortSignal,
  ): Promise<AdminCatalogMutationResult<AdminCatalogCategory>> {
    return this.catalogMutation("POST", "categories", null, body, idempotencyKey, signal);
  }

  updateCatalogCategory(
    id: string,
    body: AdminCatalogCategoryUpdate,
    idempotencyKey: string,
    signal?: AbortSignal,
  ): Promise<AdminCatalogMutationResult<AdminCatalogCategory>> {
    return this.catalogMutation("PUT", "categories", id, body, idempotencyKey, signal);
  }

  archiveCatalogCategory(
    id: string,
    expectedRevision: number,
    idempotencyKey: string,
    signal?: AbortSignal,
  ): Promise<AdminCatalogMutationResult<AdminCatalogCategory>> {
    return this.catalogArchive("categories", id, expectedRevision, idempotencyKey, signal);
  }

  listCatalogTags(
    query: AdminCatalogQuery,
    signal?: AbortSignal,
  ): Promise<AdminCatalogCollection<AdminCatalogTag>> {
    return this.catalogList("tags", query, signal);
  }

  getCatalogTag(
    id: string,
    signal?: AbortSignal,
  ): Promise<AdminCatalogDetail<AdminCatalogTag>> {
    return this.catalogDetail("tags", id, signal);
  }

  createCatalogTag(
    body: AdminCatalogTagCreate,
    idempotencyKey: string,
    signal?: AbortSignal,
  ): Promise<AdminCatalogMutationResult<AdminCatalogTag>> {
    return this.catalogMutation("POST", "tags", null, body, idempotencyKey, signal);
  }

  updateCatalogTag(
    id: string,
    body: AdminCatalogTagUpdate,
    idempotencyKey: string,
    signal?: AbortSignal,
  ): Promise<AdminCatalogMutationResult<AdminCatalogTag>> {
    return this.catalogMutation("PUT", "tags", id, body, idempotencyKey, signal);
  }

  archiveCatalogTag(
    id: string,
    expectedRevision: number,
    idempotencyKey: string,
    signal?: AbortSignal,
  ): Promise<AdminCatalogMutationResult<AdminCatalogTag>> {
    return this.catalogArchive("tags", id, expectedRevision, idempotencyKey, signal);
  }

  listCatalogRanks(
    query: AdminCatalogQuery,
    signal?: AbortSignal,
  ): Promise<AdminCatalogCollection<AdminCatalogRank>> {
    return this.catalogList("ranks", query, signal);
  }

  getCatalogRank(
    id: string,
    signal?: AbortSignal,
  ): Promise<AdminCatalogDetail<AdminCatalogRank>> {
    return this.catalogDetail("ranks", id, signal);
  }

  createCatalogRank(
    body: AdminCatalogRankCreate,
    idempotencyKey: string,
    signal?: AbortSignal,
  ): Promise<AdminCatalogMutationResult<AdminCatalogRank>> {
    return this.catalogMutation("POST", "ranks", null, body, idempotencyKey, signal);
  }

  updateCatalogRank(
    id: string,
    body: AdminCatalogRankUpdate,
    idempotencyKey: string,
    signal?: AbortSignal,
  ): Promise<AdminCatalogMutationResult<AdminCatalogRank>> {
    return this.catalogMutation("PUT", "ranks", id, body, idempotencyKey, signal);
  }

  archiveCatalogRank(
    id: string,
    expectedRevision: number,
    idempotencyKey: string,
    signal?: AbortSignal,
  ): Promise<AdminCatalogMutationResult<AdminCatalogRank>> {
    return this.catalogArchive("ranks", id, expectedRevision, idempotencyKey, signal);
  }

  listCatalogPrizes(
    query: AdminCatalogQuery,
    signal?: AbortSignal,
  ): Promise<AdminCatalogCollection<AdminCatalogPrize>> {
    return this.catalogList("prizes", query, signal);
  }

  getCatalogPrize(
    id: string,
    signal?: AbortSignal,
  ): Promise<AdminCatalogDetail<AdminCatalogPrize>> {
    return this.catalogDetail("prizes", id, signal);
  }

  listCatalogPresentationAssets(
    query: AdminCatalogQuery,
    signal?: AbortSignal,
  ): Promise<AdminCatalogCollection<AdminCatalogPresentationAsset>> {
    return this.catalogList("presentation-assets", query, signal);
  }

  getCatalogPresentationAsset(
    id: string,
    signal?: AbortSignal,
  ): Promise<AdminCatalogDetail<AdminCatalogPresentationAsset>> {
    return this.catalogDetail("presentation-assets", id, signal);
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

  private catalogList<T>(
    resource: AdminCatalogResource,
    query: AdminCatalogQuery,
    signal?: AbortSignal,
  ): Promise<AdminCatalogCollection<T>> {
    const parameters = new URLSearchParams();
    for (const [name, value] of Object.entries(query)) {
      if (value !== undefined && value !== "") {
        parameters.set(name, String(value));
      }
    }
    const suffix = parameters.size > 0 ? `?${parameters.toString()}` : "";
    return this.request("GET", `/catalog/${resource}${suffix}`, { signal });
  }

  private catalogDetail<T>(
    resource: AdminCatalogResource,
    id: string,
    signal?: AbortSignal,
  ): Promise<AdminCatalogDetail<T>> {
    if (!isOpaqueId(id)) {
      return Promise.reject(
        new AdminApiError(404, "CATALOG_RESOURCE_NOT_FOUND", null, null, false),
      );
    }
    return this.request("GET", `/catalog/${resource}/${encodeURIComponent(id)}`, {
      signal,
    });
  }

  private catalogMutation<TBody, TResult>(
    method: "POST" | "PUT",
    resource: "categories" | "tags" | "ranks",
    id: string | null,
    body: TBody,
    idempotencyKey: string,
    signal?: AbortSignal,
  ): Promise<AdminCatalogMutationResult<TResult>> {
    if ((id !== null && !isOpaqueId(id)) || !isIdempotencyKey(idempotencyKey)) {
      return Promise.reject(
        new AdminApiError(422, "CATALOG_MUTATION_INVALID", null, null, false),
      );
    }
    const suffix = id === null ? "" : `/${encodeURIComponent(id)}`;
    return this.request(method, `/catalog/${resource}${suffix}`, {
      body,
      idempotencyKey,
      signal,
    });
  }

  private catalogArchive<TResult>(
    resource: "categories" | "tags" | "ranks",
    id: string,
    expectedRevision: number,
    idempotencyKey: string,
    signal?: AbortSignal,
  ): Promise<AdminCatalogMutationResult<TResult>> {
    if (
      !isOpaqueId(id) ||
      !Number.isSafeInteger(expectedRevision) ||
      expectedRevision < 1 ||
      !isIdempotencyKey(idempotencyKey)
    ) {
      return Promise.reject(
        new AdminApiError(422, "CATALOG_MUTATION_INVALID", null, null, false),
      );
    }
    return this.request("POST", `/catalog/${resource}/${encodeURIComponent(id)}/archive`, {
      body: { expected_revision: expectedRevision },
      idempotencyKey,
      signal,
    });
  }

  private async request<T>(
    method: "GET" | "POST" | "PUT",
    path: `/auth/${string}` | `/catalog/${string}`,
    options: RequestOptions = {},
  ): Promise<T> {
    if (
      (!path.startsWith("/auth/") && !path.startsWith("/catalog/")) ||
      path.includes("://") ||
      path.includes("..")
    ) {
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
    if (options.idempotencyKey) {
      headers.set("Idempotency-Key", options.idempotencyKey);
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

function isOpaqueId(value: string): boolean {
  return /^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i.test(
    value,
  );
}

function isIdempotencyKey(value: string): boolean {
  return value.length > 0 && value.length <= 255 && !/[\u0000-\u001f\u007f]/.test(value);
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
  if (status === 409 || status === 412) {
    return "別の操作で更新されています。最新状態を再取得してください。";
  }
  if (code === "REQUEST_ABORTED") return "リクエストを中止しました。";
  if (code === "NETWORK_ERROR") return "管理APIへ接続できませんでした。";
  return "管理APIで処理できませんでした。";
}
