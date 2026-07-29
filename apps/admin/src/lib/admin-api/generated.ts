// Generated from openapi/bundled/admin.openapi.json.
// Contract SHA-256: 232d9e75e73c12030dd2db56e24ef6b3bf02ed3ba0437e617e8ec6fcd04c5669
// Do not edit manually.

export const ADMIN_API_BASE_PATH = "/admin/api/v2" as const;
export const ADMIN_PERMISSION_CODES = [
  "identity.admin.read",
  "identity.admin.manage",
  "identity.admin.session.revoke",
  "point.ledger.read",
  "point.adjustment.request",
  "point.adjustment.free.approve",
  "point.adjustment.paid.approve",
  "catalog.read",
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
