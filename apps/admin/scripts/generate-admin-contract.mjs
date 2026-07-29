import { createHash } from "node:crypto";
import { mkdir, readFile, writeFile } from "node:fs/promises";
import { dirname, resolve } from "node:path";
import { fileURLToPath } from "node:url";

const directory = dirname(fileURLToPath(import.meta.url));
const repository = resolve(directory, "../../..");
const contractPath = resolve(repository, "openapi/bundled/admin.openapi.json");
const outputPath = resolve(directory, "../src/lib/admin-api/generated.ts");
const check = process.argv.includes("--check");
const source = await readFile(contractPath, "utf8");
const contract = JSON.parse(source);

const operations = {
  beginAdminLogin: ["post", "/auth/login"],
  verifyAdminMfa: ["post", "/auth/mfa/verify"],
  logoutAdmin: ["post", "/auth/logout"],
  getAdminEffectivePermissions: ["get", "/auth/permissions"],
  getAdminSession: ["get", "/auth/session"],
  beginAdminTotpEnrollment: ["post", "/auth/mfa/totp"],
  confirmAdminTotpEnrollment: ["post", "/auth/mfa/totp/confirm"],
  createAdminWebauthnOptions: ["post", "/auth/mfa/webauthn/options"],
  registerAdminWebauthn: ["post", "/auth/mfa/webauthn"],
  regenerateAdminRecoveryCodes: ["post", "/auth/mfa/recovery-codes/regenerate"],
  createAdminReauthenticationWebauthnOptions: [
    "post",
    "/auth/reauthenticate/webauthn/options",
  ],
  reauthenticateAdmin: ["post", "/auth/reauthenticate"],
  getAdminLineMessagingSetting: ["get", "/identity/line-messaging"],
  updateAdminLineMessagingSetting: ["put", "/identity/line-messaging"],
  previewAdminLineMessagingSetting: [
    "post",
    "/identity/line-messaging/preview",
  ],
  listAdminCatalogCategories: ["get", "/catalog/categories"],
  createAdminCatalogCategory: ["post", "/catalog/categories"],
  getAdminCatalogCategory: ["get", "/catalog/categories/{catalog_resource_id}"],
  updateAdminCatalogCategory: ["put", "/catalog/categories/{catalog_resource_id}"],
  archiveAdminCatalogCategory: [
    "post",
    "/catalog/categories/{catalog_resource_id}/archive",
  ],
  listAdminCatalogTags: ["get", "/catalog/tags"],
  createAdminCatalogTag: ["post", "/catalog/tags"],
  getAdminCatalogTag: ["get", "/catalog/tags/{catalog_resource_id}"],
  updateAdminCatalogTag: ["put", "/catalog/tags/{catalog_resource_id}"],
  archiveAdminCatalogTag: [
    "post",
    "/catalog/tags/{catalog_resource_id}/archive",
  ],
  listAdminCatalogRanks: ["get", "/catalog/ranks"],
  createAdminCatalogRank: ["post", "/catalog/ranks"],
  getAdminCatalogRank: ["get", "/catalog/ranks/{catalog_resource_id}"],
  updateAdminCatalogRank: ["put", "/catalog/ranks/{catalog_resource_id}"],
  archiveAdminCatalogRank: [
    "post",
    "/catalog/ranks/{catalog_resource_id}/archive",
  ],
  listAdminCatalogPrizes: ["get", "/catalog/prizes"],
  createAdminCatalogPrize: ["post", "/catalog/prizes"],
  getAdminCatalogPrize: ["get", "/catalog/prizes/{catalog_resource_id}"],
  updateAdminCatalogPrize: ["put", "/catalog/prizes/{catalog_resource_id}"],
  archiveAdminCatalogPrize: [
    "post",
    "/catalog/prizes/{catalog_resource_id}/archive",
  ],
  listAdminCatalogPresentationAssets: ["get", "/catalog/presentation-assets"],
  createAdminCatalogPresentationAsset: ["post", "/catalog/presentation-assets"],
  getAdminCatalogPresentationAsset: [
    "get",
    "/catalog/presentation-assets/{catalog_resource_id}",
  ],
  updateAdminCatalogPresentationAsset: [
    "put",
    "/catalog/presentation-assets/{catalog_resource_id}",
  ],
  archiveAdminCatalogPresentationAsset: [
    "post",
    "/catalog/presentation-assets/{catalog_resource_id}/archive",
  ],
  listAdminCatalogGachas: ["get", "/catalog/gachas"],
  createAdminCatalogGacha: ["post", "/catalog/gachas"],
  getAdminCatalogGacha: ["get", "/catalog/gachas/{gacha_id}"],
  updateAdminCatalogGacha: ["put", "/catalog/gachas/{gacha_id}"],
  archiveAdminCatalogGacha: ["post", "/catalog/gachas/{gacha_id}/archive"],
  listAdminCatalogGachaVersions: ["get", "/catalog/gachas/{gacha_id}/versions"],
  createAdminCatalogGachaDraft: ["post", "/catalog/gachas/{gacha_id}/versions"],
  getAdminCatalogGachaVersion: [
    "get",
    "/catalog/gachas/{gacha_id}/versions/{gacha_version_id}",
  ],
  updateAdminCatalogGachaDraft: [
    "put",
    "/catalog/gachas/{gacha_id}/versions/{gacha_version_id}",
  ],
  cloneAdminCatalogGachaDraft: [
    "post",
    "/catalog/gachas/{gacha_id}/versions/{gacha_version_id}/clone",
  ],
  archiveAdminCatalogGachaDraft: [
    "post",
    "/catalog/gachas/{gacha_id}/versions/{gacha_version_id}/archive",
  ],
};

if (contract.servers?.[0]?.url !== "/admin/api/v2") {
  throw new Error("Admin OpenAPI server boundary changed.");
}
for (const [operationId, [method, path]] of Object.entries(operations)) {
  if (contract.paths?.[path]?.[method]?.operationId !== operationId) {
    throw new Error(`Admin OpenAPI operation mismatch: ${operationId}`);
  }
}

const schemas = contract.components?.schemas ?? {};
const requiredSchemas = [
  "AdminIdentity",
  "AdminEffectivePermissions",
  "AdminLoginRequest",
  "AdminMfaVerifyRequest",
  "AdminPreauth",
  "AdminReauthenticationRequest",
  "AdminReauthenticationResponse",
  "AdminSession",
  "RecoveryCodes",
  "TotpConfirmation",
  "TotpEnrollment",
  "WebauthnOptions",
  "WebauthnOptionsRequest",
  "WebauthnRegistration",
  "AdminPermissionCode",
  "AdminLineMessagingSetting",
  "AdminLineMessagingSettingResponse",
  "AdminLineMessagingSettingUpdate",
  "AdminLineMessagingPreviewRequest",
  "AdminLineMessagingPreview",
  "AdminLineMessagingMutationResult",
  "AdminCatalogCategory",
  "AdminCatalogCategoryCreate",
  "AdminCatalogCategoryMutationResult",
  "AdminCatalogCategoryUpdate",
  "AdminCatalogCategoryCollection",
  "AdminCatalogPresentationAsset",
  "AdminCatalogPresentationAssetCreate",
  "AdminCatalogPresentationAssetMutationResult",
  "AdminCatalogPresentationAssetUpdate",
  "AdminCatalogPresentationAssetCollection",
  "AdminCatalogPrize",
  "AdminCatalogPrizeCreate",
  "AdminCatalogPrizeMutationResult",
  "AdminCatalogPrizeUpdate",
  "AdminCatalogPrizeCollection",
  "AdminCatalogRank",
  "AdminCatalogRankCreate",
  "AdminCatalogRankMutationResult",
  "AdminCatalogRankUpdate",
  "AdminCatalogRankCollection",
  "AdminCatalogTag",
  "AdminCatalogTagCreate",
  "AdminCatalogTagMutationResult",
  "AdminCatalogTagUpdate",
  "AdminCatalogTagCollection",
  "AdminCatalogGacha",
  "AdminCatalogGachaCreate",
  "AdminCatalogGachaUpdate",
  "AdminCatalogGachaMutationResult",
  "AdminCatalogGachaCollection",
  "AdminCatalogGachaVersion",
  "AdminCatalogGachaVersionCreate",
  "AdminCatalogGachaVersionUpdate",
  "AdminCatalogGachaVersionMutationResult",
  "AdminCatalogGachaVersionCollection",
];
for (const name of requiredSchemas) {
  if (!schemas[name]) {
    throw new Error(`Admin OpenAPI schema missing: ${name}`);
  }
}
const roles = schemas.AdminIdentity.properties.role.enum;
const permissions = schemas.AdminPermissionCode.enum;
const methods = schemas.AdminMfaVerifyRequest.properties.method.enum;
const reauthenticationMethods =
  schemas.AdminReauthenticationRequest.properties.method.enum;
if (
  JSON.stringify(roles) !== JSON.stringify(["owner", "admin", "operator"]) ||
  JSON.stringify(methods) !==
    JSON.stringify(["totp", "webauthn", "recovery_code"]) ||
  JSON.stringify(reauthenticationMethods) !==
    JSON.stringify(["totp", "webauthn"])
) {
  throw new Error("Admin OpenAPI authentication enums changed.");
}

const sha256 = createHash("sha256").update(source).digest("hex");
const generated = `// Generated from openapi/bundled/admin.openapi.json.
// Contract SHA-256: ${sha256}
// Do not edit manually.

export const ADMIN_API_BASE_PATH = "/admin/api/v2" as const;
export const ADMIN_PERMISSION_CODES = ${JSON.stringify(permissions, null, 2)} as const;

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
`;

if (check) {
  const current = await readFile(outputPath, "utf8").catch(() => "");
  if (current !== generated) {
    throw new Error("Generated Admin OpenAPI types are stale.");
  }
} else {
  await mkdir(dirname(outputPath), { recursive: true });
  await writeFile(outputPath, generated);
}
