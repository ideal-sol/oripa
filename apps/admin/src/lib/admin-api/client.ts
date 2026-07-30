import {
  ADMIN_API_BASE_PATH,
  type AdminEffectivePermissions,
  type AdminCatalogCategory,
  type AdminCatalogCategoryCreate,
  type AdminCatalogCategoryUpdate,
  type AdminCatalogCollection,
  type AdminCatalogDetail,
  type AdminCatalogDirection,
  type AdminCatalogGacha,
  type AdminCatalogGachaCreate,
  type AdminCatalogGachaUpdate,
  type AdminCatalogGachaVersion,
  type AdminCatalogGachaVersionCreate,
  type AdminCatalogGachaVersionUpdate,
  type AdminCatalogProbabilityEntriesReplace,
  type AdminCatalogProbabilityVersion,
  type AdminCatalogPresentationAsset,
  type AdminCatalogPresentationAssetCreate,
  type AdminCatalogPresentationAssetUpdate,
  type AdminCatalogPrize,
  type AdminCatalogPrizeCreate,
  type AdminCatalogPrizeUpdate,
  type AdminCatalogRank,
  type AdminCatalogRankCreate,
  type AdminCatalogRankUpdate,
  type AdminCatalogTag,
  type AdminCatalogTagCreate,
  type AdminCatalogTagUpdate,
  type AdminCatalogMutationResult,
  type AdminCatalogVisibility,
  type AdminGachaProbabilitySelection,
  type AdminGachaProbabilitySelectionRequest,
  type AdminGachaPublishPreflight,
  type AdminGachaPublishPreflightRequest,
  type AdminGachaPublishState,
  type AdminGachaImmediatePublish,
  type AdminGachaImmediatePublishRequest,
  type AdminGachaPublishSchedule,
  type AdminGachaPublishScheduleCancelRequest,
  type AdminGachaPublishSchedulePreflight,
  type AdminGachaPublishScheduleRequest,
  type AdminGachaPublishedProbabilityCandidate,
  type AdminGachaSalesPauseRequest,
  type AdminGachaSalesPreflight,
  type AdminGachaSalesResumeRequest,
  type AdminGachaSalesState,
  type AdminGachaUnpublishPreflight,
  type AdminGachaUnpublishRequest,
  type AdminGachaUnpublishState,
  type AdminLoginRequest,
  type AdminLineMessagingMutationResult,
  type AdminLineMessagingPreview,
  type AdminLineMessagingPreviewRequest,
  type AdminLineMessagingSettingResponse,
  type AdminLineMessagingSettingUpdate,
  type AdminMfaVerifyRequest,
  type AdminPreauth,
  type AdminQaMutationResult,
  type AdminQaPlanCollection,
  type AdminQaPlanCreate,
  type AdminQaPlanDetail,
  type AdminQaPlanUpdate,
  type AdminQaPreflight,
  type AdminQaTestUser,
  type AdminQaTestUserCollection,
  type AdminQaTestUserSave,
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
  archive?: "all" | "active" | "archived";
  cursor?: string;
  direction?: AdminCatalogDirection;
  limit?: number;
  media_type?: "all" | "image" | "video";
  q?: string;
  rank_id?: string;
  sort?: string;
  state?: "all" | "draft" | "active" | "disabled";
  status?: "all" | "draft" | "published";
  visibility?: AdminCatalogVisibility;
}

export interface AdminQaQuery {
  cursor?: string;
  limit?: number;
  q?: string;
  status?: "all" | "active" | "paused" | "completed" | "disabled";
}

export type AdminCatalogResource =
  | "categories"
  | "gachas"
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

  getLineMessagingSetting(
    signal?: AbortSignal,
  ): Promise<AdminLineMessagingSettingResponse> {
    return this.request("GET", "/identity/line-messaging", { signal });
  }

  previewLineMessagingSetting(
    body: AdminLineMessagingPreviewRequest,
    signal?: AbortSignal,
  ): Promise<AdminLineMessagingPreview> {
    return this.request("POST", "/identity/line-messaging/preview", {
      body,
      signal,
    });
  }

  updateLineMessagingSetting(
    body: AdminLineMessagingSettingUpdate,
    idempotencyKey: string,
    signal?: AbortSignal,
  ): Promise<AdminLineMessagingMutationResult> {
    if (!isIdempotencyKey(idempotencyKey)) {
      return Promise.reject(
        new AdminApiError(
          422,
          "LINE_MESSAGING_SETTING_INVALID",
          null,
          null,
          false,
        ),
      );
    }
    return this.request("PUT", "/identity/line-messaging", {
      body,
      idempotencyKey,
      signal,
    });
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

  createCatalogPrize(
    body: AdminCatalogPrizeCreate,
    idempotencyKey: string,
    signal?: AbortSignal,
  ): Promise<AdminCatalogMutationResult<AdminCatalogPrize>> {
    return this.catalogMutation("POST", "prizes", null, body, idempotencyKey, signal);
  }

  updateCatalogPrize(
    id: string,
    body: AdminCatalogPrizeUpdate,
    idempotencyKey: string,
    signal?: AbortSignal,
  ): Promise<AdminCatalogMutationResult<AdminCatalogPrize>> {
    return this.catalogMutation("PUT", "prizes", id, body, idempotencyKey, signal);
  }

  archiveCatalogPrize(
    id: string,
    expectedRevision: number,
    idempotencyKey: string,
    signal?: AbortSignal,
  ): Promise<AdminCatalogMutationResult<AdminCatalogPrize>> {
    return this.catalogArchive("prizes", id, expectedRevision, idempotencyKey, signal);
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

  createCatalogPresentationAsset(
    body: AdminCatalogPresentationAssetCreate,
    idempotencyKey: string,
    signal?: AbortSignal,
  ): Promise<AdminCatalogMutationResult<AdminCatalogPresentationAsset>> {
    return this.catalogMutation(
      "POST",
      "presentation-assets",
      null,
      body,
      idempotencyKey,
      signal,
    );
  }

  updateCatalogPresentationAsset(
    id: string,
    body: AdminCatalogPresentationAssetUpdate,
    idempotencyKey: string,
    signal?: AbortSignal,
  ): Promise<AdminCatalogMutationResult<AdminCatalogPresentationAsset>> {
    return this.catalogMutation(
      "PUT",
      "presentation-assets",
      id,
      body,
      idempotencyKey,
      signal,
    );
  }

  archiveCatalogPresentationAsset(
    id: string,
    expectedRevision: number,
    idempotencyKey: string,
    signal?: AbortSignal,
  ): Promise<AdminCatalogMutationResult<AdminCatalogPresentationAsset>> {
    return this.catalogArchive(
      "presentation-assets",
      id,
      expectedRevision,
      idempotencyKey,
      signal,
    );
  }

  listCatalogGachas(
    query: AdminCatalogQuery,
    signal?: AbortSignal,
  ): Promise<AdminCatalogCollection<AdminCatalogGacha>> {
    return this.catalogList("gachas", query, signal);
  }

  getCatalogGacha(
    id: string,
    signal?: AbortSignal,
  ): Promise<AdminCatalogDetail<AdminCatalogGacha>> {
    return this.catalogDetail("gachas", id, signal);
  }

  createCatalogGacha(
    body: AdminCatalogGachaCreate,
    idempotencyKey: string,
    signal?: AbortSignal,
  ): Promise<AdminCatalogMutationResult<AdminCatalogGacha>> {
    return this.catalogMutation("POST", "gachas", null, body, idempotencyKey, signal);
  }

  updateCatalogGacha(
    id: string,
    body: AdminCatalogGachaUpdate,
    idempotencyKey: string,
    signal?: AbortSignal,
  ): Promise<AdminCatalogMutationResult<AdminCatalogGacha>> {
    return this.catalogMutation("PUT", "gachas", id, body, idempotencyKey, signal);
  }

  archiveCatalogGacha(
    id: string,
    expectedRevision: number,
    idempotencyKey: string,
    signal?: AbortSignal,
  ): Promise<AdminCatalogMutationResult<AdminCatalogGacha>> {
    return this.catalogArchive("gachas", id, expectedRevision, idempotencyKey, signal);
  }

  listCatalogGachaVersions(
    gachaId: string,
    query: AdminCatalogQuery,
    signal?: AbortSignal,
  ): Promise<AdminCatalogCollection<AdminCatalogGachaVersion>> {
    return this.gachaVersionList(gachaId, query, signal);
  }

  getCatalogGachaVersion(
    gachaId: string,
    versionId: string,
    signal?: AbortSignal,
  ): Promise<AdminCatalogDetail<AdminCatalogGachaVersion>> {
    if (!isOpaqueId(gachaId) || !isOpaqueId(versionId)) {
      return Promise.reject(
        new AdminApiError(404, "CATALOG_RESOURCE_NOT_FOUND", null, null, false),
      );
    }
    return this.request(
      "GET",
      `/catalog/gachas/${encodeURIComponent(gachaId)}/versions/${encodeURIComponent(versionId)}`,
      { signal },
    );
  }

  createCatalogGachaDraft(
    gachaId: string,
    body: AdminCatalogGachaVersionCreate,
    idempotencyKey: string,
    signal?: AbortSignal,
  ): Promise<AdminCatalogMutationResult<AdminCatalogGachaVersion>> {
    return this.gachaVersionMutation(
      "POST",
      gachaId,
      null,
      null,
      body,
      idempotencyKey,
      signal,
    );
  }

  cloneCatalogGachaDraft(
    gachaId: string,
    sourceVersionId: string,
    idempotencyKey: string,
    signal?: AbortSignal,
  ): Promise<AdminCatalogMutationResult<AdminCatalogGachaVersion>> {
    return this.gachaVersionMutation(
      "POST",
      gachaId,
      sourceVersionId,
      "clone",
      {},
      idempotencyKey,
      signal,
    );
  }

  updateCatalogGachaDraft(
    gachaId: string,
    versionId: string,
    body: AdminCatalogGachaVersionUpdate,
    idempotencyKey: string,
    signal?: AbortSignal,
  ): Promise<AdminCatalogMutationResult<AdminCatalogGachaVersion>> {
    return this.gachaVersionMutation(
      "PUT",
      gachaId,
      versionId,
      null,
      body,
      idempotencyKey,
      signal,
    );
  }

  discardCatalogGachaDraft(
    gachaId: string,
    versionId: string,
    expectedRevision: number,
    idempotencyKey: string,
    signal?: AbortSignal,
  ): Promise<AdminCatalogMutationResult<AdminCatalogGachaVersion>> {
    return this.gachaVersionMutation(
      "POST",
      gachaId,
      versionId,
      "archive",
      { expected_revision: expectedRevision },
      idempotencyKey,
      signal,
    );
  }

  listGachaPublishedProbabilityCandidates(
    gachaId: string,
    gachaVersionId: string,
    query: Pick<AdminCatalogQuery, "cursor" | "direction" | "limit"> = {},
    signal?: AbortSignal,
  ): Promise<AdminCatalogCollection<AdminGachaPublishedProbabilityCandidate>> {
    const path = this.gachaVersionPublishPath(gachaId, gachaVersionId);
    if (path === null) {
      return Promise.reject(
        new AdminApiError(404, "CATALOG_RESOURCE_NOT_FOUND", null, null, false),
      );
    }
    const parameters = new URLSearchParams();
    for (const [name, value] of Object.entries(query)) {
      if (value !== undefined && value !== "") parameters.set(name, String(value));
    }
    const suffix = parameters.size > 0 ? `?${parameters.toString()}` : "";
    return this.request(
      "GET",
      `${path}/published-probability-candidates${suffix}`,
      { signal },
    );
  }

  getGachaProbabilitySelection(
    gachaId: string,
    gachaVersionId: string,
    signal?: AbortSignal,
  ): Promise<AdminCatalogDetail<AdminGachaProbabilitySelection>> {
    const path = this.gachaVersionPublishPath(gachaId, gachaVersionId);
    if (path === null) {
      return Promise.reject(
        new AdminApiError(404, "CATALOG_RESOURCE_NOT_FOUND", null, null, false),
      );
    }
    return this.request("GET", `${path}/probability-selection`, { signal });
  }

  selectGachaPublishedProbability(
    gachaId: string,
    gachaVersionId: string,
    body: AdminGachaProbabilitySelectionRequest,
    idempotencyKey: string,
    signal?: AbortSignal,
  ): Promise<AdminCatalogMutationResult<AdminCatalogGachaVersion>> {
    const path = this.gachaVersionPublishPath(gachaId, gachaVersionId);
    if (path === null || !isIdempotencyKey(idempotencyKey)) {
      return Promise.reject(
        new AdminApiError(422, "CATALOG_MUTATION_INVALID", null, null, false),
      );
    }
    return this.request("PUT", `${path}/probability-selection`, {
      body,
      idempotencyKey,
      signal,
    });
  }

  preflightGachaVersionPublish(
    gachaId: string,
    gachaVersionId: string,
    body: AdminGachaPublishPreflightRequest,
    idempotencyKey: string,
    signal?: AbortSignal,
  ): Promise<AdminCatalogMutationResult<AdminGachaPublishPreflight>> {
    const path = this.gachaVersionPublishPath(gachaId, gachaVersionId);
    if (path === null || !isIdempotencyKey(idempotencyKey)) {
      return Promise.reject(
        new AdminApiError(422, "CATALOG_MUTATION_INVALID", null, null, false),
      );
    }
    return this.request("POST", `${path}/publish-preflight`, {
      body,
      idempotencyKey,
      signal,
    });
  }

  getGachaPublishState(
    gachaId: string,
    signal?: AbortSignal,
  ): Promise<AdminCatalogDetail<AdminGachaPublishState>> {
    if (!isOpaqueId(gachaId)) {
      return Promise.reject(
        new AdminApiError(404, "CATALOG_RESOURCE_NOT_FOUND", null, null, false),
      );
    }
    return this.request(
      "GET",
      `/catalog/gachas/${encodeURIComponent(gachaId)}/publish-state`,
      { signal },
    );
  }

  getGachaSalesState(
    gachaId: string,
    signal?: AbortSignal,
  ): Promise<AdminCatalogDetail<AdminGachaSalesState>> {
    if (!isOpaqueId(gachaId)) {
      return Promise.reject(
        new AdminApiError(404, "CATALOG_RESOURCE_NOT_FOUND", null, null, false),
      );
    }
    return this.request(
      "GET",
      `/catalog/gachas/${encodeURIComponent(gachaId)}/sales-state`,
      { signal },
    );
  }

  preflightGachaSalesPause(
    gachaId: string,
    body: AdminGachaSalesPauseRequest,
    idempotencyKey: string,
    signal?: AbortSignal,
  ): Promise<AdminCatalogMutationResult<AdminGachaSalesPreflight>> {
    return this.gachaSalesMutation<
      AdminGachaSalesPauseRequest,
      AdminGachaSalesPreflight
    >(
      gachaId,
      "sales-pause/preflight",
      body,
      idempotencyKey,
      signal,
    );
  }

  pauseGachaSales(
    gachaId: string,
    body: AdminGachaSalesPauseRequest,
    idempotencyKey: string,
    signal?: AbortSignal,
  ): Promise<AdminCatalogMutationResult<AdminGachaSalesState>> {
    return this.gachaSalesMutation<
      AdminGachaSalesPauseRequest,
      AdminGachaSalesState
    >(
      gachaId,
      "sales-pause",
      body,
      idempotencyKey,
      signal,
    );
  }

  preflightGachaSalesResume(
    gachaId: string,
    body: AdminGachaSalesResumeRequest,
    idempotencyKey: string,
    signal?: AbortSignal,
  ): Promise<AdminCatalogMutationResult<AdminGachaSalesPreflight>> {
    return this.gachaSalesMutation<
      AdminGachaSalesResumeRequest,
      AdminGachaSalesPreflight
    >(
      gachaId,
      "sales-resume/preflight",
      body,
      idempotencyKey,
      signal,
    );
  }

  resumeGachaSales(
    gachaId: string,
    body: AdminGachaSalesResumeRequest,
    idempotencyKey: string,
    signal?: AbortSignal,
  ): Promise<AdminCatalogMutationResult<AdminGachaSalesState>> {
    return this.gachaSalesMutation<
      AdminGachaSalesResumeRequest,
      AdminGachaSalesState
    >(
      gachaId,
      "sales-resume",
      body,
      idempotencyKey,
      signal,
    );
  }

  getGachaUnpublishState(
    gachaId: string,
    signal?: AbortSignal,
  ): Promise<AdminCatalogDetail<AdminGachaUnpublishState>> {
    if (!isOpaqueId(gachaId)) {
      return Promise.reject(
        new AdminApiError(404, "CATALOG_RESOURCE_NOT_FOUND", null, null, false),
      );
    }
    return this.request(
      "GET",
      `/catalog/gachas/${encodeURIComponent(gachaId)}/unpublish-state`,
      { signal },
    );
  }

  preflightGachaUnpublish(
    gachaId: string,
    body: AdminGachaUnpublishRequest,
    idempotencyKey: string,
    signal?: AbortSignal,
  ): Promise<AdminCatalogMutationResult<AdminGachaUnpublishPreflight>> {
    return this.gachaSalesMutation<
      AdminGachaUnpublishRequest,
      AdminGachaUnpublishPreflight
    >(gachaId, "unpublish/preflight", body, idempotencyKey, signal);
  }

  unpublishGacha(
    gachaId: string,
    body: AdminGachaUnpublishRequest,
    idempotencyKey: string,
    signal?: AbortSignal,
  ): Promise<AdminCatalogMutationResult<AdminGachaUnpublishState>> {
    return this.gachaSalesMutation<
      AdminGachaUnpublishRequest,
      AdminGachaUnpublishState
    >(gachaId, "unpublish", body, idempotencyKey, signal);
  }

  publishGachaVersionImmediately(
    gachaId: string,
    gachaVersionId: string,
    body: AdminGachaImmediatePublishRequest,
    idempotencyKey: string,
    signal?: AbortSignal,
  ): Promise<AdminCatalogMutationResult<AdminGachaImmediatePublish>> {
    const path = this.gachaVersionPublishPath(gachaId, gachaVersionId);
    if (path === null || !isIdempotencyKey(idempotencyKey)) {
      return Promise.reject(
        new AdminApiError(422, "CATALOG_MUTATION_INVALID", null, null, false),
      );
    }
    return this.request("POST", `${path}/publish`, {
      body,
      idempotencyKey,
      signal,
    });
  }

  getGachaPublishSchedule(
    gachaId: string,
    gachaVersionId: string,
    signal?: AbortSignal,
  ): Promise<AdminCatalogDetail<AdminGachaPublishSchedule | null>> {
    const path = this.gachaVersionPublishPath(gachaId, gachaVersionId);
    if (path === null) {
      return Promise.reject(
        new AdminApiError(404, "CATALOG_RESOURCE_NOT_FOUND", null, null, false),
      );
    }
    return this.request("GET", `${path}/publish-schedule`, { signal });
  }

  preflightGachaVersionPublishSchedule(
    gachaId: string,
    gachaVersionId: string,
    body: AdminGachaPublishScheduleRequest,
    idempotencyKey: string,
    signal?: AbortSignal,
  ): Promise<AdminCatalogMutationResult<AdminGachaPublishSchedulePreflight>> {
    const path = this.gachaVersionPublishPath(gachaId, gachaVersionId);
    if (path === null || !isIdempotencyKey(idempotencyKey)) {
      return Promise.reject(
        new AdminApiError(422, "CATALOG_MUTATION_INVALID", null, null, false),
      );
    }
    return this.request("POST", `${path}/publish-schedule/preflight`, {
      body,
      idempotencyKey,
      signal,
    });
  }

  scheduleGachaVersionPublish(
    gachaId: string,
    gachaVersionId: string,
    body: AdminGachaPublishScheduleRequest,
    idempotencyKey: string,
    signal?: AbortSignal,
  ): Promise<AdminCatalogMutationResult<AdminGachaPublishSchedule>> {
    const path = this.gachaVersionPublishPath(gachaId, gachaVersionId);
    if (path === null || !isIdempotencyKey(idempotencyKey)) {
      return Promise.reject(
        new AdminApiError(422, "CATALOG_MUTATION_INVALID", null, null, false),
      );
    }
    return this.request("POST", `${path}/publish-schedule`, {
      body,
      idempotencyKey,
      signal,
    });
  }

  cancelGachaVersionPublishSchedule(
    gachaId: string,
    gachaVersionId: string,
    scheduleId: string,
    body: AdminGachaPublishScheduleCancelRequest,
    idempotencyKey: string,
    signal?: AbortSignal,
  ): Promise<AdminCatalogMutationResult<AdminGachaPublishSchedule>> {
    const path = this.gachaVersionPublishPath(gachaId, gachaVersionId);
    if (
      path === null ||
      !isOpaqueId(scheduleId) ||
      !isIdempotencyKey(idempotencyKey)
    ) {
      return Promise.reject(
        new AdminApiError(422, "CATALOG_MUTATION_INVALID", null, null, false),
      );
    }
    return this.request(
      "POST",
      `${path}/publish-schedule/${encodeURIComponent(scheduleId)}/cancel`,
      { body, idempotencyKey, signal },
    );
  }

  listCatalogProbabilityVersions(
    gachaId: string,
    gachaVersionId: string,
    query: AdminCatalogQuery,
    signal?: AbortSignal,
  ): Promise<AdminCatalogCollection<AdminCatalogProbabilityVersion>> {
    const path = this.probabilityVersionPath(gachaId, gachaVersionId);
    if (path === null) {
      return Promise.reject(
        new AdminApiError(404, "CATALOG_RESOURCE_NOT_FOUND", null, null, false),
      );
    }
    const parameters = new URLSearchParams();
    for (const [name, value] of Object.entries(query)) {
      if (value !== undefined && value !== "") parameters.set(name, String(value));
    }
    const suffix = parameters.size > 0 ? `?${parameters.toString()}` : "";
    return this.request("GET", `${path}${suffix}`, { signal });
  }

  getCatalogProbabilityVersion(
    gachaId: string,
    gachaVersionId: string,
    probabilityVersionId: string,
    signal?: AbortSignal,
  ): Promise<AdminCatalogDetail<AdminCatalogProbabilityVersion>> {
    const path = this.probabilityVersionPath(
      gachaId,
      gachaVersionId,
      probabilityVersionId,
    );
    if (path === null) {
      return Promise.reject(
        new AdminApiError(404, "CATALOG_RESOURCE_NOT_FOUND", null, null, false),
      );
    }
    return this.request("GET", path, { signal });
  }

  createCatalogProbabilityDraft(
    gachaId: string,
    gachaVersionId: string,
    idempotencyKey: string,
    signal?: AbortSignal,
  ): Promise<AdminCatalogMutationResult<AdminCatalogProbabilityVersion>> {
    return this.probabilityVersionMutation(
      "POST",
      gachaId,
      gachaVersionId,
      null,
      null,
      {},
      idempotencyKey,
      signal,
    );
  }

  cloneCatalogProbabilityDraft(
    gachaId: string,
    gachaVersionId: string,
    probabilityVersionId: string,
    idempotencyKey: string,
    signal?: AbortSignal,
  ): Promise<AdminCatalogMutationResult<AdminCatalogProbabilityVersion>> {
    return this.probabilityVersionMutation(
      "POST",
      gachaId,
      gachaVersionId,
      probabilityVersionId,
      "clone",
      {},
      idempotencyKey,
      signal,
    );
  }

  replaceCatalogProbabilityEntries(
    gachaId: string,
    gachaVersionId: string,
    probabilityVersionId: string,
    body: AdminCatalogProbabilityEntriesReplace,
    idempotencyKey: string,
    signal?: AbortSignal,
  ): Promise<AdminCatalogMutationResult<AdminCatalogProbabilityVersion>> {
    return this.probabilityVersionMutation(
      "PUT",
      gachaId,
      gachaVersionId,
      probabilityVersionId,
      "entries",
      body,
      idempotencyKey,
      signal,
    );
  }

  validateCatalogProbabilityDraft(
    gachaId: string,
    gachaVersionId: string,
    probabilityVersionId: string,
    expectedRevision: number,
    idempotencyKey: string,
    signal?: AbortSignal,
  ): Promise<AdminCatalogMutationResult<AdminCatalogProbabilityVersion>> {
    return this.probabilityVersionMutation(
      "POST",
      gachaId,
      gachaVersionId,
      probabilityVersionId,
      "validate",
      { expected_revision: expectedRevision },
      idempotencyKey,
      signal,
    );
  }

  preflightCatalogProbabilityPublish(
    gachaId: string,
    gachaVersionId: string,
    probabilityVersionId: string,
    expectedRevision: number,
    idempotencyKey: string,
    signal?: AbortSignal,
  ): Promise<AdminCatalogMutationResult<AdminCatalogProbabilityVersion>> {
    return this.probabilityVersionMutation(
      "POST",
      gachaId,
      gachaVersionId,
      probabilityVersionId,
      "publish-preflight",
      { expected_revision: expectedRevision },
      idempotencyKey,
      signal,
    );
  }

  publishCatalogProbabilityDraft(
    gachaId: string,
    gachaVersionId: string,
    probabilityVersionId: string,
    expectedRevision: number,
    idempotencyKey: string,
    signal?: AbortSignal,
  ): Promise<AdminCatalogMutationResult<AdminCatalogProbabilityVersion>> {
    return this.probabilityVersionMutation(
      "POST",
      gachaId,
      gachaVersionId,
      probabilityVersionId,
      "publish",
      { expected_revision: expectedRevision },
      idempotencyKey,
      signal,
    );
  }

  discardCatalogProbabilityDraft(
    gachaId: string,
    gachaVersionId: string,
    probabilityVersionId: string,
    expectedRevision: number,
    idempotencyKey: string,
    signal?: AbortSignal,
  ): Promise<AdminCatalogMutationResult<AdminCatalogProbabilityVersion>> {
    return this.probabilityVersionMutation(
      "POST",
      gachaId,
      gachaVersionId,
      probabilityVersionId,
      "archive",
      { expected_revision: expectedRevision },
      idempotencyKey,
      signal,
    );
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

  listQaPlans(
    query: AdminQaQuery,
    signal?: AbortSignal,
  ): Promise<AdminQaPlanCollection> {
    return this.qaList<AdminQaPlanCollection>("/qa/plans", query, signal);
  }

  getQaPlan(id: string, signal?: AbortSignal): Promise<AdminQaPlanDetail> {
    if (!isOpaqueId(id)) {
      return Promise.reject(
        new AdminApiError(422, "QA_CONFIGURATION_INVALID", null, null, false),
      );
    }
    return this.request("GET", `/qa/plans/${id}`, { signal });
  }

  createQaPlan(
    body: AdminQaPlanCreate,
    idempotencyKey: string,
    signal?: AbortSignal,
  ): Promise<AdminQaMutationResult<AdminQaPlanDetail>> {
    return this.qaMutation("POST", "/qa/plans", body, idempotencyKey, signal);
  }

  updateQaPlan(
    id: string,
    body: AdminQaPlanUpdate,
    idempotencyKey: string,
    signal?: AbortSignal,
  ): Promise<AdminQaMutationResult<AdminQaPlanDetail>> {
    return this.qaMutation(
      "PUT",
      `/qa/plans/${id}`,
      body,
      idempotencyKey,
      signal,
    );
  }

  transitionQaPlan(
    id: string,
    action: "enable" | "disable" | "archive",
    revision: number,
    idempotencyKey: string,
    signal?: AbortSignal,
  ): Promise<AdminQaMutationResult<AdminQaPlanDetail>> {
    return this.qaMutation(
      "POST",
      `/qa/plans/${id}/${action}`,
      { revision },
      idempotencyKey,
      signal,
    );
  }

  preflightQaPlan(id: string, signal?: AbortSignal): Promise<AdminQaPreflight> {
    if (!isOpaqueId(id)) {
      return Promise.reject(
        new AdminApiError(422, "QA_CONFIGURATION_INVALID", null, null, false),
      );
    }
    return this.request("GET", `/qa/plans/${id}/preflight`, { signal });
  }

  assignQaTestUser(
    planId: string,
    userId: string,
    revision: number,
    idempotencyKey: string,
    signal?: AbortSignal,
  ): Promise<AdminQaMutationResult<AdminQaPlanDetail>> {
    return this.qaMutation(
      "POST",
      `/qa/plans/${planId}/assignments`,
      { revision, user_id: userId },
      idempotencyKey,
      signal,
    );
  }

  unassignQaTestUser(
    planId: string,
    userId: string,
    revision: number,
    idempotencyKey: string,
    signal?: AbortSignal,
  ): Promise<AdminQaMutationResult<AdminQaPlanDetail>> {
    return this.qaMutation(
      "POST",
      `/qa/plans/${planId}/assignments/unassign`,
      { revision, user_id: userId },
      idempotencyKey,
      signal,
    );
  }

  listQaTestUsers(
    query: AdminQaQuery,
    signal?: AbortSignal,
  ): Promise<AdminQaTestUserCollection> {
    return this.qaList<AdminQaTestUserCollection>("/qa/test-users", query, signal);
  }

  searchQaTestUserCandidates(
    query: AdminQaQuery,
    signal?: AbortSignal,
  ): Promise<AdminQaTestUserCollection> {
    return this.qaList<AdminQaTestUserCollection>(
      "/qa/test-user-candidates",
      query,
      signal,
    );
  }

  saveQaTestUser(
    userId: string,
    body: AdminQaTestUserSave,
    idempotencyKey: string,
    signal?: AbortSignal,
  ): Promise<AdminQaMutationResult<AdminQaTestUser>> {
    return this.qaMutation(
      "PUT",
      `/qa/test-users/${userId}`,
      body,
      idempotencyKey,
      signal,
    );
  }

  disableQaTestUser(
    userId: string,
    revision: number,
    idempotencyKey: string,
    signal?: AbortSignal,
  ): Promise<AdminQaMutationResult<AdminQaTestUser>> {
    return this.qaMutation(
      "POST",
      `/qa/test-users/${userId}/disable`,
      { revision },
      idempotencyKey,
      signal,
    );
  }

  private qaList<T>(
    path: "/qa/plans" | "/qa/test-users" | "/qa/test-user-candidates",
    query: AdminQaQuery,
    signal?: AbortSignal,
  ): Promise<T> {
    const parameters = new URLSearchParams();
    for (const [name, value] of Object.entries(query)) {
      if (value !== undefined && value !== "") {
        parameters.set(name, String(value));
      }
    }
    const suffix = parameters.size > 0 ? `?${parameters.toString()}` : "";
    return this.request("GET", `${path}${suffix}`, { signal });
  }

  private qaMutation<TBody, TResult>(
    method: "POST" | "PUT",
    path: `/qa/${string}`,
    body: TBody,
    idempotencyKey: string,
    signal?: AbortSignal,
  ): Promise<AdminQaMutationResult<TResult>> {
    if (!isIdempotencyKey(idempotencyKey) || path.includes("..")) {
      return Promise.reject(
        new AdminApiError(422, "QA_CONFIGURATION_INVALID", null, null, false),
      );
    }
    return this.request(method, path, { body, idempotencyKey, signal });
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
    resource: AdminCatalogResource,
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
    resource: AdminCatalogResource,
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

  private gachaVersionList<T>(
    gachaId: string,
    query: AdminCatalogQuery,
    signal?: AbortSignal,
  ): Promise<AdminCatalogCollection<T>> {
    if (!isOpaqueId(gachaId)) {
      return Promise.reject(
        new AdminApiError(404, "CATALOG_RESOURCE_NOT_FOUND", null, null, false),
      );
    }
    const parameters = new URLSearchParams();
    for (const [name, value] of Object.entries(query)) {
      if (value !== undefined && value !== "") parameters.set(name, String(value));
    }
    const suffix = parameters.size > 0 ? `?${parameters.toString()}` : "";
    return this.request(
      "GET",
      `/catalog/gachas/${encodeURIComponent(gachaId)}/versions${suffix}`,
      { signal },
    );
  }

  private gachaVersionMutation<TBody>(
    method: "POST" | "PUT",
    gachaId: string,
    versionId: string | null,
    action: "clone" | "archive" | null,
    body: TBody,
    idempotencyKey: string,
    signal?: AbortSignal,
  ): Promise<AdminCatalogMutationResult<AdminCatalogGachaVersion>> {
    if (
      !isOpaqueId(gachaId) ||
      (versionId !== null && !isOpaqueId(versionId)) ||
      !isIdempotencyKey(idempotencyKey)
    ) {
      return Promise.reject(
        new AdminApiError(422, "CATALOG_MUTATION_INVALID", null, null, false),
      );
    }
    const versionPath = versionId === null
      ? ""
      : `/${encodeURIComponent(versionId)}`;
    const actionPath = action === null ? "" : `/${action}`;
    return this.request(
      method,
      `/catalog/gachas/${encodeURIComponent(gachaId)}/versions${versionPath}${actionPath}`,
      { body, idempotencyKey, signal },
    );
  }

  private gachaSalesMutation<TBody, TResult>(
    gachaId: string,
    action:
      | "sales-pause/preflight"
      | "sales-pause"
      | "sales-resume/preflight"
      | "sales-resume"
      | "unpublish/preflight"
      | "unpublish",
    body: TBody,
    idempotencyKey: string,
    signal?: AbortSignal,
  ): Promise<AdminCatalogMutationResult<TResult>> {
    if (!isOpaqueId(gachaId) || !isIdempotencyKey(idempotencyKey)) {
      return Promise.reject(
        new AdminApiError(422, "CATALOG_MUTATION_INVALID", null, null, false),
      );
    }
    return this.request(
      "POST",
      `/catalog/gachas/${encodeURIComponent(gachaId)}/${action}`,
      { body, idempotencyKey, signal },
    );
  }

  private probabilityVersionPath(
    gachaId: string,
    gachaVersionId: string,
    probabilityVersionId?: string,
  ): `/catalog/${string}` | null {
    if (
      !isOpaqueId(gachaId) ||
      !isOpaqueId(gachaVersionId) ||
      (probabilityVersionId !== undefined && !isOpaqueId(probabilityVersionId))
    ) {
      return null;
    }
    const root =
      `/catalog/gachas/${encodeURIComponent(gachaId)}` +
      `/versions/${encodeURIComponent(gachaVersionId)}/probability-versions`;
    return (probabilityVersionId === undefined
      ? root
      : `${root}/${encodeURIComponent(probabilityVersionId)}`) as `/catalog/${string}`;
  }

  private gachaVersionPublishPath(
    gachaId: string,
    gachaVersionId: string,
  ): `/catalog/${string}` | null {
    if (!isOpaqueId(gachaId) || !isOpaqueId(gachaVersionId)) {
      return null;
    }
    return (
      `/catalog/gachas/${encodeURIComponent(gachaId)}` +
      `/versions/${encodeURIComponent(gachaVersionId)}`
    ) as `/catalog/${string}`;
  }

  private probabilityVersionMutation<TBody>(
    method: "POST" | "PUT",
    gachaId: string,
    gachaVersionId: string,
    probabilityVersionId: string | null,
    action:
      | "clone"
      | "entries"
      | "validate"
      | "publish-preflight"
      | "publish"
      | "archive"
      | null,
    body: TBody,
    idempotencyKey: string,
    signal?: AbortSignal,
  ): Promise<AdminCatalogMutationResult<AdminCatalogProbabilityVersion>> {
    const path = this.probabilityVersionPath(
      gachaId,
      gachaVersionId,
      probabilityVersionId ?? undefined,
    );
    if (path === null || !isIdempotencyKey(idempotencyKey)) {
      return Promise.reject(
        new AdminApiError(422, "CATALOG_MUTATION_INVALID", null, null, false),
      );
    }
    return this.request(
      method,
      action === null ? path : `${path}/${action}`,
      { body, idempotencyKey, signal },
    );
  }

  private async request<T>(
    method: "GET" | "POST" | "PUT",
    path:
      | `/auth/${string}`
      | `/catalog/${string}`
      | `/identity/${string}`
      | `/qa/${string}`,
    options: RequestOptions = {},
  ): Promise<T> {
    if (
      (!path.startsWith("/auth/") &&
        !path.startsWith("/catalog/") &&
        !path.startsWith("/identity/") &&
        !path.startsWith("/qa/")) ||
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
