// Generated from openapi/bundled/admin.openapi.json.
// Contract SHA-256: 3f8fd89f1c6ec0eabd6fba0a53136953aae8e158bdd61a6707fb021e31155eec
// Do not edit manually.

export const ADMIN_API_BASE_PATH = "/admin/api/v2" as const;
export const ADMIN_PERMISSION_CODES = [
  "identity.admin.read",
  "identity.admin.manage",
  "identity.admin.session.revoke",
  "identity.line.manage",
  "point.ledger.read",
  "point.adjustment.request",
  "point.adjustment.free.approve",
  "point.adjustment.paid.approve",
  "catalog.read",
  "catalog.manage",
  "shipping.request.manage",
  "qa.draw.manage",
  "reporting.financial.read",
  "reporting.financial.export",
  "content.read",
  "content.manage",
  "content.publish",
  "contact.read",
  "contact.manage"
] as const;

export type AdminRole = "owner" | "admin" | "operator";
export type AdminPermissionCode = (typeof ADMIN_PERMISSION_CODES)[number];
export type AdminMfaMethod = "totp" | "webauthn" | "recovery_code";
export type AdminFreshMfaMethod = "totp" | "webauthn";

export interface AdminIdentity {
  id: string;
  role: AdminRole;
  state: "active";
  mfa_verified?: boolean;
}

export interface AdminEffectivePermissions {
  role: AdminRole;
  permissions: AdminPermissionCode[];
  request_id: string;
}

export interface AdminLoginRequest {
  email: string;
  password: string;
  invitation_token?: string;
}

export interface WebauthnOptions {
  challenge_token: string;
  options: Record<string, unknown>;
  expires_in: number;
}

export interface AdminPreauth {
  status: "mfa_required";
  transaction_token: string;
  expires_in: number;
  methods: AdminMfaMethod[];
  webauthn: WebauthnOptions | null;
}

export interface AdminMfaVerifyRequest {
  method: AdminMfaMethod;
  code?: string;
  challenge_token?: string;
  credential?: Record<string, unknown>;
}

export interface AdminSession {
  authenticated: boolean;
  requires_mfa_enrollment?: boolean;
  enrollment_transaction_token?: string | null;
  enrollment_transaction_expires_in?: number | null;
  admin?: AdminIdentity | null;
}

export interface TotpEnrollment {
  enrollment_token: string;
  secret: string;
  otpauth_uri: string;
  expires_in: number;
}

export interface TotpConfirmation {
  enrollment_token: string;
  code: string;
}

export interface WebauthnOptionsRequest {
  label?: string;
}

export interface WebauthnRegistration {
  challenge_token: string;
  credential: Record<string, unknown>;
}

export interface RecoveryCodes {
  recovery_codes: string[];
}

export interface AdminReauthenticationRequest {
  method: AdminFreshMfaMethod;
  code?: string;
  challenge_token?: string;
  credential?: Record<string, unknown>;
}

export interface AdminReauthenticationResponse {
  authenticated: true;
  fresh_mfa_expires_in: 300;
  admin: AdminIdentity;
}

export interface AdminLineMessagingSetting {
  id: string;
  linked_follow_message: string;
  pending_follow_message: string;
  login_relative_path: string;
  reward_enabled?: boolean;
  reward_point_amount?: number;
  reward_expiration_days?: number;
  revision: number;
  updated_at: string;
}

export interface AdminLineMessagingSettingResponse {
  data: AdminLineMessagingSetting;
  request_id: string;
}

export interface AdminLineMessagingSettingUpdate {
  expected_revision: number;
  linked_follow_message: string;
  pending_follow_message: string;
  reward_enabled?: boolean;
  reward_point_amount?: number;
  reward_expiration_days?: number;
}

export interface AdminLineMessagingPreviewRequest {
  linked_follow_message: string;
  pending_follow_message: string;
  reward_enabled?: boolean;
  reward_point_amount?: number;
  reward_expiration_days?: number;
}

export interface AdminLineMessagingPreview {
  linked_follow_message: string;
  pending_follow_message: string;
  reward_enabled?: boolean;
  reward_point_amount?: number;
  reward_expiration_days?: number;
  request_id: string;
}

export interface AdminLineMessagingMutationResult {
  data: AdminLineMessagingSetting;
  idempotent_replay: boolean;
  request_id: string;
}

export interface AdminCatalogCategory {
  id: string;
  code: string;
  slug: string;
  name: string;
  description: string | null;
  sort_order: number;
  is_visible: boolean;
  is_archived?: boolean;
  revision?: number;
  archived_at?: string | null;
  created_at: string;
  updated_at: string;
}

export interface AdminCatalogTag {
  id: string;
  code: string;
  slug: string;
  name: string;
  sort_order: number;
  is_visible: boolean;
  is_archived?: boolean;
  revision?: number;
  archived_at?: string | null;
  created_at: string;
  updated_at: string;
}

export interface AdminCatalogRank {
  id: string;
  code: string;
  name: string;
  sort_order: number;
  is_visible: boolean;
  is_archived?: boolean;
  revision?: number;
  archived_at?: string | null;
  created_at: string;
  updated_at: string;
}

export interface AdminCatalogRankReference {
  id: string;
  code: string;
  name: string;
  sort_order: number;
}

export interface AdminCatalogAssetReference {
  id: string;
  media_type: "image" | "video";
  mime_type: string;
  alt_text: string | null;
  public_path: string | null;
  is_public: boolean;
}

export interface AdminCatalogPrize {
  id: string;
  code: string;
  name: string;
  description: string | null;
  display_price: number;
  exchange_points: number;
  is_visible: boolean;
  rank: AdminCatalogRankReference;
  presentation_asset: AdminCatalogAssetReference | null;
  is_archived?: boolean;
  revision?: number;
  archived_at?: string | null;
  created_at: string;
  updated_at: string;
}

export interface AdminCatalogPresentationAsset extends AdminCatalogAssetReference {
  byte_size: number;
  checksum_sha256: string;
  is_archived?: boolean;
  revision?: number;
  archived_at?: string | null;
  created_at: string;
  updated_at: string;
}

export interface AdminCatalogCategoryCreate {
  code: string;
  slug: string;
  name: string;
  description: string | null;
  sort_order: number;
  is_visible: boolean;
}

export interface AdminCatalogCategoryUpdate {
  expected_revision: number;
  slug: string;
  name: string;
  description: string | null;
  sort_order: number;
  is_visible: boolean;
}

export interface AdminCatalogTagCreate {
  code: string;
  slug: string;
  name: string;
  sort_order: number;
  is_visible: boolean;
}

export interface AdminCatalogTagUpdate {
  expected_revision: number;
  slug: string;
  name: string;
  sort_order: number;
  is_visible: boolean;
}

export interface AdminCatalogRankCreate {
  code: string;
  name: string;
  sort_order: number;
  is_visible: boolean;
}

export interface AdminCatalogRankUpdate {
  expected_revision: number;
  name: string;
  sort_order: number;
  is_visible: boolean;
}

export interface AdminCatalogPrizeCreate {
  code: string;
  rank_id: string;
  presentation_asset_id: string | null;
  name: string;
  description: string | null;
  display_price: number;
  exchange_points: number;
  is_visible: boolean;
}

export interface AdminCatalogPrizeUpdate {
  expected_revision: number;
  rank_id: string;
  presentation_asset_id: string | null;
  name: string;
  description: string | null;
  display_price: number;
  exchange_points: number;
  is_visible: boolean;
}

export interface AdminCatalogPresentationAssetCreate {
  storage_identifier: string;
  public_path: string;
  checksum_sha256: string;
  media_type: "image" | "video";
  mime_type: string;
  byte_size: number;
  alt_text: string | null;
  is_public: boolean;
}

export interface AdminCatalogPresentationAssetUpdate {
  expected_revision: number;
  alt_text: string | null;
  is_public: boolean;
}

export interface AdminCatalogReference {
  id: string;
  code: string;
  name: string;
}

export interface AdminCatalogGachaVersionSummary {
  id: string;
  version_number: number;
  status: "draft" | "published";
  title: string;
}

export interface AdminCatalogGacha {
  id: string;
  code: string;
  slug: string;
  state: "draft" | "active" | "disabled";
  sold_count: number;
  category: AdminCatalogReference;
  tags: AdminCatalogReference[];
  published_version: AdminCatalogGachaVersionSummary | null;
  version_count: number;
  has_draw_history: boolean;
  is_archived: boolean;
  revision: number;
  archived_at: string | null;
  created_at: string;
  updated_at: string;
}

export interface AdminCatalogGachaCreate {
  code: string;
  slug: string;
  category_id: string;
  tag_ids: string[];
}

export interface AdminCatalogGachaUpdate {
  expected_revision: number;
  category_id: string;
  tag_ids: string[];
}

export interface AdminCatalogGachaVersionPrizeInput {
  prize_id: string;
  initial_inventory: number;
  sort_order: number;
}

export interface AdminCatalogGachaVersionPrize {
  prize: {
    id: string;
    code: string;
    name: string;
    rank: AdminCatalogReference;
  };
  initial_inventory: number;
  sort_order: number;
}

export interface AdminCatalogGachaVersion {
  id: string;
  version_number: number;
  status: "draft" | "published";
  title: string;
  description: string | null;
  notices: string | null;
  price_points: number;
  total_count: number;
  presentation_asset: AdminCatalogAssetReference | null;
  published_probability_version: {
    id: string;
    version_number: number;
    status: "draft" | "published";
  } | null;
  cloned_from_version: {
    id: string;
    version_number: number;
  } | null;
  publish_start_at: string;
  publish_end_at: string | null;
  published_at: string | null;
  prizes: AdminCatalogGachaVersionPrize[];
  is_archived: boolean;
  revision: number;
  archived_at: string | null;
  created_at: string;
  updated_at: string;
}

export interface AdminCatalogGachaVersionCreate {
  title: string;
  description: string | null;
  notices: string | null;
  price_points: number;
  total_count: number;
  presentation_asset_id: string | null;
  publish_start_at: string;
  publish_end_at: string | null;
  prizes: AdminCatalogGachaVersionPrizeInput[];
}

export interface AdminCatalogGachaVersionUpdate
  extends AdminCatalogGachaVersionCreate {
  expected_revision: number;
}

export interface AdminCatalogProbabilityPrizeReference {
  id: string;
  code: string;
  name: string;
  rank: AdminCatalogRankReference;
}

export interface AdminCatalogProbabilityTarget {
  result_type: "prize" | "point_back";
  prize: AdminCatalogProbabilityPrizeReference | null;
  point_amount: number | null;
  probability_ppm: number;
}

export interface AdminCatalogProbabilityEntry
  extends AdminCatalogProbabilityTarget {
  sort_order: number;
}

export interface AdminCatalogProbabilityStage {
  id: string;
  code: string;
  name: string;
  condition_type: "sold_count";
  min_draw_number: number;
  max_draw_number: number | null;
  sort_order: number;
  entries: AdminCatalogProbabilityEntry[];
  minimum_guarantee: AdminCatalogProbabilityTarget | null;
}

export interface AdminCatalogProbabilityStageValidation {
  stage_id: string | null;
  code: string;
  current_total_ppm: number;
  required_total_ppm: 1000000;
  remaining_ppm: number;
  excess_ppm: number;
  errors: string[];
}

export interface AdminCatalogProbabilityValidation {
  is_valid: boolean;
  current_total_ppm: number;
  required_total_ppm: number;
  remaining_ppm: number;
  excess_ppm: number;
  errors: string[];
  stages: AdminCatalogProbabilityStageValidation[];
}

export interface AdminCatalogProbabilityVersion {
  id: string;
  gacha_version_id: string;
  version_number: number;
  status: "draft" | "published";
  snapshot_sha256: string;
  cloned_from_version: {
    id: string;
    version_number: number;
    status: "draft" | "published";
  } | null;
  stages: AdminCatalogProbabilityStage[];
  validation: AdminCatalogProbabilityValidation;
  is_archived: boolean;
  revision: number;
  archived_at: string | null;
  published_at: string | null;
  created_at: string;
  updated_at: string;
}

export interface AdminCatalogProbabilityTargetInput {
  result_type: "prize" | "point_back";
  prize_id: string | null;
  point_amount: number | null;
  probability_ppm: number;
}

export interface AdminCatalogProbabilityStageInput {
  code: string;
  name: string;
  min_draw_number: number;
  max_draw_number: number | null;
  entries: AdminCatalogProbabilityTargetInput[];
  minimum_guarantee: AdminCatalogProbabilityTargetInput | null;
}

export interface AdminCatalogProbabilityEntriesReplace {
  expected_revision: number;
  stages: AdminCatalogProbabilityStageInput[];
}

export interface AdminCatalogArchiveRequest {
  expected_revision: number;
}

export interface AdminCatalogMutationResult<T> {
  data: T;
  idempotent_replay: boolean;
}

export interface AdminCatalogCollection<T> {
  items: T[];
  next_cursor: string | null;
}

export interface AdminCatalogDetail<T> {
  data: T;
}

export type AdminCatalogVisibility = "all" | "visible" | "hidden";
export type AdminCatalogDirection = "asc" | "desc";

export interface StatusResponse {
  status: string;
}

export interface ProblemDetails {
  type?: string;
  title?: string;
  status?: number;
  detail?: string;
  instance?: string;
  code?: string;
  retryable?: boolean;
  request_id?: string;
  retry_after?: number;
}
