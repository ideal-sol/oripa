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
  listAdminCatalogCategories: ["get", "/catalog/categories"],
  getAdminCatalogCategory: ["get", "/catalog/categories/{catalog_resource_id}"],
  listAdminCatalogTags: ["get", "/catalog/tags"],
  getAdminCatalogTag: ["get", "/catalog/tags/{catalog_resource_id}"],
  listAdminCatalogRanks: ["get", "/catalog/ranks"],
  getAdminCatalogRank: ["get", "/catalog/ranks/{catalog_resource_id}"],
  listAdminCatalogPrizes: ["get", "/catalog/prizes"],
  getAdminCatalogPrize: ["get", "/catalog/prizes/{catalog_resource_id}"],
  listAdminCatalogPresentationAssets: ["get", "/catalog/presentation-assets"],
  getAdminCatalogPresentationAsset: [
    "get",
    "/catalog/presentation-assets/{catalog_resource_id}",
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
  "AdminCatalogCategory",
  "AdminCatalogCategoryCollection",
  "AdminCatalogPresentationAsset",
  "AdminCatalogPresentationAssetCollection",
  "AdminCatalogPrize",
  "AdminCatalogPrizeCollection",
  "AdminCatalogRank",
  "AdminCatalogRankCollection",
  "AdminCatalogTag",
  "AdminCatalogTagCollection",
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

export interface AdminCatalogCategory {
  id: string;
  code: string;
  slug: string;
  name: string;
  description: string | null;
  sort_order: number;
  is_visible: boolean;
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
  created_at: string;
  updated_at: string;
}

export interface AdminCatalogRank {
  id: string;
  code: string;
  name: string;
  sort_order: number;
  is_visible: boolean;
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
  created_at: string;
  updated_at: string;
}

export interface AdminCatalogPresentationAsset extends AdminCatalogAssetReference {
  byte_size: number;
  checksum_sha256: string;
  created_at: string;
  updated_at: string;
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
