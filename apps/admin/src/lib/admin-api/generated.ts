// Generated from openapi/bundled/admin.openapi.json.
// Contract SHA-256: 9a90aa5e1f06edfb575c3402acdf4bf7c20e019a6f41b04906d45f6a6a5cd3cb
// Do not edit manually.

export const ADMIN_API_BASE_PATH = "/admin/api/v2" as const;
export const ADMIN_PERMISSION_CODES = [
  "identity.admin.read",
  "identity.admin.manage",
  "identity.admin.session.revoke",
  "identity.line.read",
  "identity.line.manage",
  "user.tag.read",
  "user.tag.manage",
  "user.state.manage",
  "point.ledger.read",
  "point.adjustment.request",
  "point.adjustment.free.approve",
  "point.adjustment.paid.approve",
  "point.adjustment.manage",
  "referral.settings.read",
  "referral.settings.manage",
  "payment.plan.read",
  "payment.plan.manage",
  "catalog.read",
  "catalog.manage",
  "catalog.publish",
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
export type AdminFreshAuthenticationMethod = "password" | "totp" | "webauthn";

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
}

export interface AdminInvitationAcceptanceRequest {
  email: string;
  password: string;
  invitation_token: string;
}

export interface WebauthnOptions {
  challenge_token: string;
  options: Record<string, unknown>;
  expires_in: number;
}

export interface AdminLoginResult {
  status: "authenticated" | "mfa_required" | "enrollment_required";
  authenticated: boolean;
  requires_mfa_enrollment: boolean;
  transaction_token: string | null;
  expires_in: number | null;
  methods: AdminMfaMethod[];
  webauthn: WebauthnOptions | null;
  mfa_required: boolean;
  admin: AdminIdentity | null;
}

export type AdminPreauth = AdminLoginResult & {
  status: "mfa_required";
  transaction_token: string;
  expires_in: number;
};

export interface AdminMfaVerifyRequest {
  method: AdminMfaMethod;
  code?: string;
  challenge_token?: string;
  credential?: Record<string, unknown>;
}

export interface AdminSession {
  authenticated: boolean;
  mfa_required?: boolean;
  requires_mfa_enrollment?: boolean;
  enrollment_transaction_token?: string | null;
  enrollment_transaction_expires_in?: number | null;
  admin?: AdminIdentity | null;
}

export type AdminUserState =
  | "pending_verification"
  | "active"
  | "restricted"
  | "suspended"
  | "closed"
  | "anonymized";

export interface AdminUserPointBalance {
  total_balance: number;
  paid_balance: number;
  free_balance: number;
  next_expiring_amount?: number;
  next_expires_at?: string | null;
}

export interface AdminUserSummary {
  id: string;
  display_name: string | null;
  status: AdminUserState;
  point_balance: AdminUserPointBalance | null;
  created_at: string;
}

export interface AdminUserCollection {
  items: AdminUserSummary[];
  next_cursor: string | null;
  request_id: string;
}

export interface AdminUserDetail extends AdminUserSummary {
  email: string;
  email_verified_at: string | null;
  state_revision: number;
  tag_assignment_revision?: number;
  tags?: AdminUserTagAssignment[];
  updated_at: string;
}

export interface AdminUserDetailResponse {
  data: AdminUserDetail;
  request_id: string;
}

export interface AdminUserStateUpdate {
  status: "active" | "suspended" | "closed";
  expected_revision: number;
  reason: string;
}

export interface AdminUserStateMutation {
  user_id: string;
  status: "active" | "suspended" | "closed";
  state_revision: number;
  updated_at: string;
}

export interface AdminUserStateMutationResult {
  data: AdminUserStateMutation;
  idempotent_replay: boolean;
  request_id: string;
}

export interface AdminUserTag {
  id: string;
  name: string;
  is_active: boolean;
  revision: number;
  created_at: string;
  updated_at: string;
}

export interface AdminUserTagAssignment {
  id: string;
  name: string;
  is_active: boolean;
  assigned_at: string;
}

export interface AdminUserTagInput {
  name: string;
  is_active: boolean;
}

export interface AdminUserTagUpdate extends AdminUserTagInput {
  expected_revision: number;
}

export interface AdminUserTagCollection {
  items: AdminUserTag[];
  next_cursor: string | null;
  request_id: string;
}

export interface AdminUserTagMutationResult {
  data: AdminUserTag;
  idempotent_replay: boolean;
  request_id: string;
}

export interface AdminUserTagSet {
  user_id: string;
  revision: number;
  tags: AdminUserTagAssignment[];
}

export interface AdminUserTagSetResponse {
  data: AdminUserTagSet;
  request_id: string;
}

export interface AdminUserTagAssignmentChange {
  expected_revision: number;
}

export interface AdminUserTagSetMutationResult {
  data: AdminUserTagSet;
  idempotent_replay: boolean;
  request_id: string;
}

export interface AdminUserGachaHistoryItem {
  id: string;
  draw_result_id: string;
  gacha_id: string;
  gacha_version_id: string;
  gacha_title: string;
  prize_id: string;
  prize_name: string;
  rank_id: string;
  rank_name: string;
  status: string;
  exchange_point_snapshot: number;
  exchanged_point_amount: number | null;
  acquired_at: string;
  storage_expires_at: string;
  terminal_at: string | null;
}

export interface AdminUserGachaHistoryCollection {
  user_id: string;
  items: AdminUserGachaHistoryItem[];
  next_cursor: string | null;
  request_id: string;
}

export type AdminUserPrizeStatus =
  | "stored"
  | "exchange_processing"
  | "converted"
  | "shipping_requested"
  | "packing"
  | "shipped"
  | "delivered"
  | "hold"
  | "return_requested"
  | "returned"
  | "expired"
  | "canceled";

export interface AdminUserPrizeActionState {
  allowed: boolean;
  unavailable_reason:
    | "payment_hold"
    | "status_not_actionable"
    | "storage_expired"
    | "exchange_points_unavailable"
    | null;
}

export interface AdminUserPrizeSummary {
  id: string;
  user: { id: string; display_name: string | null };
  prize: {
    id: string;
    name: string;
    image: AdminCatalogAssetReference | null;
    rank: { id: string; code: string; name: string };
  };
  gacha: { id: string; version_id: string; title: string };
  status: AdminUserPrizeStatus;
  fulfillment: {
    shipping_status: string | null;
    point_exchange_status: "processing" | "completed" | null;
  };
  exchange_points: number;
  exchanged_points: number | null;
  acquired_at: string;
  storage_expires_at: string;
  terminal_at: string | null;
  status_updated_at: string;
  allowed_actions: {
    shipping: AdminUserPrizeActionState;
    point_exchange: AdminUserPrizeActionState;
    selection: AdminUserPrizeActionState;
  };
}

export interface AdminUserPrizeCollection {
  items: AdminUserPrizeSummary[];
  next_cursor: string | null;
  request_id: string;
}

export interface AdminUserPrizeDetail extends AdminUserPrizeSummary {
  draw: {
    request_id: string;
    result_id: string;
    requested_count: number;
    executed_count: number;
    consumed_points: number;
    completed_at: string;
  };
  status_history: Array<{
    from_status: string | null;
    to_status: AdminUserPrizeStatus;
    reason_code: string;
    occurred_at: string;
  }>;
  shipping: {
    id: string;
    status: string;
    prize_count: number;
    requested_at: string;
    shipped_at: string | null;
    carrier_code: string | null;
    prize_ids: string[];
    tracking_number: string | null;
    shipping_address: {
      recipient_name: string;
      postal_code: string;
      prefecture: string;
      city: string;
      street: string;
      building: string | null;
      phone_number: string;
    };
    status_history: Array<{
      from_status: string | null;
      to_status: string;
      reason_code: string;
      occurred_at: string;
    }>;
  } | null;
  point_exchange: {
    id: string;
    status: "processing" | "completed";
    exchange_points: number;
    completed_at: string | null;
  } | null;
}

export interface AdminUserPrizeDetailResponse {
  data: AdminUserPrizeDetail;
  request_id: string;
}

export interface AdminPointAdjustmentRequest {
  point_type: "paid" | "free";
  direction: "grant" | "deduct";
  amount: number;
  reason: string;
  current_password: string;
}

export interface AdminPointAdjustment {
  adjustment_public_id: string;
  user_public_id: string;
  operation_public_id: string;
  point_type: "paid" | "free";
  direction: "grant" | "deduct";
  amount: number;
  reason: string;
  paid_balance_before: number;
  paid_balance_after: number;
  free_balance_before: number;
  free_balance_after: number;
  executed_at: string;
}

export interface AdminPointAdjustmentMutationResult {
  data: AdminPointAdjustment;
  idempotent_replay: boolean;
  request_id: string;
}

export interface AdminDashboardSalesSummary {
  payment_count: number;
  gross_sales_amount: number;
  refund_count: number;
  refund_amount: number;
  chargeback_count: number;
  chargeback_amount: number;
  net_sales_amount: number;
}

export interface AdminDashboardMonthlySales {
  month: string;
  timezone: "Asia/Tokyo";
  currency: "JPY";
  basis: "operational_event_aggregation_not_accounting_recognition";
  summary: AdminDashboardSalesSummary;
  days: Array<{ date: string; summary: AdminDashboardSalesSummary }>;
}

export interface AdminDashboardPayment {
  payment_id: string;
  user_id: string;
  amount: number;
  currency: "JPY";
  plan_name: string;
  provider: string;
  status: "succeeded";
  succeeded_at: string;
}

export interface AdminDashboardDailySales {
  date: string;
  timezone: "Asia/Tokyo";
  currency: "JPY";
  basis: "operational_event_aggregation_not_accounting_recognition";
  summary: AdminDashboardSalesSummary;
  items: AdminDashboardPayment[];
  next_cursor: string | null;
}

export interface AdminDashboardPointSummary {
  paid_consumed: number;
  free_consumed: number;
}

export interface AdminDashboardMonthlyPoints {
  month: string;
  timezone: "Asia/Tokyo";
  qa_excluded: true;
  summary: AdminDashboardPointSummary;
  days: Array<{ date: string; summary: AdminDashboardPointSummary }>;
}

export interface AdminDashboardPointConsumption {
  operation_id: string;
  user_id: string;
  source_type: string;
  draw_request_id: string | null;
  gacha_version_id: string | null;
  gacha_title: string | null;
  draw_count: number | null;
  paid_consumed: number;
  free_consumed: number;
  occurred_at: string;
}

export interface AdminDashboardDailyPoints {
  date: string;
  timezone: "Asia/Tokyo";
  qa_excluded: true;
  summary: AdminDashboardPointSummary;
  items: AdminDashboardPointConsumption[];
  next_cursor: string | null;
}

export interface AdminDashboardPaymentReversal {
  adjustment_id: string;
  payment_id: string;
  type: "refund" | "chargeback" | "chargeback_reversal";
  status:
    | "requested"
    | "points_reserved"
    | "submitted"
    | "processing"
    | "succeeded"
    | "failed"
    | "canceled"
    | "manual_review";
  amount: number;
  currency: "JPY";
  occurred_at: string;
  succeeded_at: string | null;
}

export interface AdminDashboardReversalHistory {
  start_date: string;
  end_date: string;
  timezone: "Asia/Tokyo";
  items: AdminDashboardPaymentReversal[];
  next_cursor: string | null;
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

export interface AdminEnrollmentResult {
  status: "confirmed" | "registered" | "authenticated";
  authenticated: boolean;
  admin: AdminIdentity | null;
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
  method: AdminFreshAuthenticationMethod;
  password?: string;
  code?: string;
  challenge_token?: string;
  credential?: Record<string, unknown>;
}

export interface AdminAuthenticationPolicy {
  id: string;
  mfa_required: boolean;
  invitation_required: boolean;
  mfa_enrolled_admin_count: number;
  active_owner_count: number;
  revision: number;
  updated_at: string;
}

export interface AdminAuthenticationPolicyResponse {
  data: AdminAuthenticationPolicy;
  request_id: string;
}

export interface AdminAuthenticationPolicyUpdate {
  expected_revision: number;
  mfa_required: boolean;
  invitation_required: boolean;
  current_password: string;
}

export interface AdminAuthenticationPolicyMutationResult {
  data: AdminAuthenticationPolicy;
  idempotent_replay: boolean;
  request_id: string;
}

export interface AdminAccountCreate {
  email: string;
  role: "admin" | "operator";
  temporary_password?: string;
}

export interface AdminAccountCreateResponse {
  data: {
    admin: { id: string; role: "admin" | "operator"; state: "active" | "invited" };
    invitation_token: string | null;
    invitation_expires_at: string | null;
  };
  request_id: string;
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
  friend_add_url?: string | null;
  friends_count?: number;
  blocked_count?: number;
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
  friend_add_url?: string | null;
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

export interface AdminReferralPointSetting {
  id: string;
  is_enabled: boolean;
  referrer_point_amount: number;
  referred_user_point_amount: number;
  reward_expiration_days: number;
  grant_condition: "referred_user_sms_verified";
  grant_timing: "on_sms_verification_completion";
  applies_to: "future_referrals_only";
  revision: number;
  updated_at: string;
}

export interface AdminReferralPointSettingResponse {
  data: AdminReferralPointSetting;
  request_id: string;
}

export interface AdminReferralPointSettingUpdate {
  expected_revision: number;
  is_enabled: boolean;
  referrer_point_amount: number;
  referred_user_point_amount: number;
  reward_expiration_days: number;
}

export interface AdminReferralPointSettingMutationResult {
  data: AdminReferralPointSetting;
  idempotent_replay: boolean;
  request_id: string;
}

export type AdminPointPurchaseAudience = "all_users" | "first_purchase_users";

export interface AdminPointPurchaseTargetTag {
  id: string;
  name: string;
  is_active: boolean;
}

export interface AdminPointPurchasePlan {
  id: string;
  name: string;
  amount: number;
  paid_point_amount: number;
  free_point_amount: number;
  sort_order: number;
  audience_code: AdminPointPurchaseAudience;
  target_user_tag?: AdminPointPurchaseTargetTag | null;
  is_active: boolean;
  status: "draft" | "published" | "retired";
  available_from: string | null;
  available_until: string | null;
  version: number;
  revision: number;
  created_at: string;
  updated_at: string;
}

export interface AdminPointPurchasePlanInput {
  name: string;
  amount: number;
  paid_point_amount: number;
  free_point_amount: number;
  sort_order: number;
  audience_code: AdminPointPurchaseAudience;
  target_user_tag_id?: string | null;
  is_active: boolean;
  available_from: string | null;
  available_until: string | null;
}

export interface AdminPointPurchasePlanUpdate extends AdminPointPurchasePlanInput {
  expected_revision: number;
}

export interface AdminPointPurchasePlanCollection {
  items: AdminPointPurchasePlan[];
  next_cursor: string | null;
  request_id: string;
}

export interface AdminPointPurchasePlanResponse {
  data: AdminPointPurchasePlan;
  request_id: string;
}

export interface AdminPointPurchasePlanMutationResult {
  data: AdminPointPurchasePlan;
  idempotent_replay: boolean;
  request_id: string;
}

export interface AdminLimitedBonusCampaign {
  id: string;
  point_purchase_plan_id: string;
  point_purchase_plan_version: number;
  is_enabled: boolean;
  starts_at: string;
  ends_at: string;
  bonus_point_amount: number;
  created_at: string;
  updated_at: string;
}

export interface AdminLimitedBonusCampaignInput {
  is_enabled: boolean;
  starts_at: string;
  ends_at: string;
  bonus_point_amount: number;
}

export interface AdminLimitedBonusCampaignCollection {
  items: AdminLimitedBonusCampaign[];
  request_id: string;
}

export interface AdminLimitedBonusCampaignMutationResult {
  data: AdminLimitedBonusCampaign;
  idempotent_replay: boolean;
  request_id: string;
}

export type AdminQaPlanStatus =
  | "active"
  | "paused"
  | "completed"
  | "disabled";

export interface AdminQaPlanItemInput {
  prize_id: string;
  quantity: number;
  sort_order: number;
  fixed_image_asset_id: string | null;
  fixed_video_asset_id: string | null;
}

export interface AdminQaPlanItem extends AdminQaPlanItemInput {
  id: string;
  consumed_count: number;
}

export interface AdminQaPlanCreate {
  user_id: string;
  gacha_id: string;
  title: string;
  reason: string;
  starts_at: string | null;
  ends_at: string | null;
  items: AdminQaPlanItemInput[];
}

export interface AdminQaPlanUpdate {
  revision: number;
  title: string;
  reason: string;
  starts_at: string | null;
  ends_at: string | null;
}

export interface AdminQaPlanSummary {
  id: string;
  code: string;
  revision: number;
  user_id: string;
  gacha_id: string;
  status: AdminQaPlanStatus;
  title: string;
  starts_at: string | null;
  ends_at: string | null;
  archived_at: string | null;
}

export interface AdminQaPlanAssignment {
  id: string;
  user_id: string;
  status: "assigned" | "unassigned";
  revision: number;
  assigned_at: string;
  unassigned_at: string | null;
}

export interface AdminQaPlanDetail extends AdminQaPlanSummary {
  reason: string;
  items: AdminQaPlanItem[];
  assignments: AdminQaPlanAssignment[];
  execution_count: number;
}

export interface AdminQaPlanCollection {
  items: AdminQaPlanSummary[];
  next_cursor: string | null;
}

export interface AdminQaTestUser {
  user_id: string;
  user_state: string;
  mode_id: string | null;
  revision: number | null;
  is_enabled: boolean;
  is_active: boolean;
  reason: string | null;
  starts_at: string | null;
  ends_at: string | null;
  updated_at: string | null;
}

export interface AdminQaTestUserCollection {
  items: AdminQaTestUser[];
  next_cursor: string | null;
}

export interface AdminQaTestUserSave {
  revision?: number;
  reason: string;
}

export interface AdminQaTestUserMode {
  id: string;
  revision: number;
  is_enabled: boolean;
  is_active: boolean;
  reason: string;
  starts_at: string | null;
  ends_at: string | null;
  disabled_at: string | null;
  updated_at: string;
}

export interface AdminQaTestUserModeEnvelope {
  user_id: string;
  mode: AdminQaTestUserMode | null;
}

export interface AdminQaGachaGuaranteeUser {
  id: string;
  display_name: string | null;
  state: string;
}

export interface AdminQaGachaGuaranteePrize {
  id: string;
  name: string;
  rank_name: string | null;
}

export interface AdminQaGachaGuaranteeAssignment {
  id: string;
  revision: number;
  status: "assigned" | "unassigned";
  user: AdminQaGachaGuaranteeUser;
  prize: AdminQaGachaGuaranteePrize;
  is_resolvable: boolean;
  issue_code: "PUBLISHED_PRIZE_UNAVAILABLE" | null;
  assigned_at: string;
  unassigned_at: string | null;
  updated_at: string;
}

export interface AdminQaGachaGuaranteeUserOption {
  id: string;
  display_name: string | null;
}

export interface AdminQaGachaGuaranteePrizeOption {
  id: string;
  name: string;
  rank_name: string | null;
}

export interface AdminQaGachaGuaranteeCollection {
  gacha_id: string;
  items: AdminQaGachaGuaranteeAssignment[];
  test_users: AdminQaGachaGuaranteeUserOption[];
  prizes: AdminQaGachaGuaranteePrizeOption[];
}

export interface AdminQaGachaGuaranteeSave {
  revision?: number;
  user_id: string;
  prize_id: string;
}

export interface AdminQaPreflight {
  plan_id: string;
  revision: number;
  valid: boolean;
  validation_codes: string[];
  assigned_test_user_count: number;
  remaining_draw_count: number;
  gacha_version_id: string | null;
  probability_version_id: string | null;
}

export interface AdminQaMutationResult<T> {
  data: T;
  idempotent_replay: boolean;
}

export type AdminQaDrawCount = 1 | 5 | 10 | 100 | 1000;

export interface AdminQaExecutionRequest {
  assignment_id: string;
  plan_revision: number;
  assignment_revision: number;
  draw_count: AdminQaDrawCount;
}

export interface AdminQaExecutionPreflight extends AdminQaExecutionRequest {
  plan_id: string;
  user_id: string | null;
  gacha_id: string | null;
  valid: boolean;
  validation_codes: string[];
  required_points: number;
  available_points: number;
  remaining_sales_count: number;
  remaining_plan_count: number;
  gacha_version_id: string | null;
  probability_version_id: string | null;
}

export interface AdminQaExecutionSummary {
  id: string;
  plan_id: string | null;
  assignment_id: string | null;
  guarantee_assignment_id: string | null;
  user_id: string;
  gacha_id: string;
  gacha_version_id: string;
  draw_request_id: string;
  executed_count: number;
  status: "completed";
  executed_at: string;
}

export interface AdminQaCountRow {
  count: number;
  rank?: { id: string; code: string; name: string };
  prize?: { id: string; name: string };
}

export interface AdminQaExecutionDetail extends AdminQaExecutionSummary {
  requested_count: AdminQaDrawCount;
  point_cost_total: number;
  consumed_paid_points: number;
  consumed_free_points: number;
  point_back_total: number;
  sales_count_delta: number;
  inventory_prize_delta_total: number;
  rank_counts: AdminQaCountRow[];
  prize_counts: AdminQaCountRow[];
  probability_version: { id: string; version: number } | null;
  processing_duration_ms: number;
  failure_reason: null;
  metadata: {
    qa_mode_public_id: string;
    qa_kind: "legacy_plan" | "persistent_guarantee";
    qa_plan_public_id: string | null;
    qa_guarantee_assignment_public_id: string | null;
    guaranteed_prize_public_id: string | null;
    plan_item_public_ids: string[];
  };
}

export interface AdminQaExecutionCollection {
  items: AdminQaExecutionSummary[];
  next_cursor: string | null;
}

export interface AdminQaExecutionMutationResult {
  data: AdminQaExecutionDetail;
  idempotent_replay: boolean;
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
  description: string | null;
  sort_order: number;
  is_visible: boolean;
  image_asset?: AdminCatalogAssetReference | null;
  video_asset?: AdminCatalogAssetReference | null;
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

export interface AdminRankEffectRankAssignment {
  rank: AdminCatalogReference;
  sort_order: number;
}

export interface AdminRankEffect extends AdminCatalogPresentationAsset {
  content_path: string;
  rank_assignments: AdminRankEffectRankAssignment[];
}

export interface AdminRankEffectRankAssignmentInput {
  rank_id: string;
  sort_order: number;
}

export interface AdminRankEffectCreate {
  title: string;
  asset_type: "image" | "video";
  rank_assignments: AdminRankEffectRankAssignmentInput[];
  is_active: boolean;
  file_name: string;
  mime_type: string;
  content_base64: string;
}

export interface AdminRankEffectUpdate {
  expected_revision: number;
  title: string;
  asset_type: "image" | "video";
  rank_assignments: AdminRankEffectRankAssignmentInput[];
  is_active: boolean;
  file_name?: string;
  mime_type?: string;
  content_base64?: string;
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

export interface AdminGachaVersionRankCreate {
  code: string;
  name: string;
  description: string | null;
  image_asset_id: string | null;
  video_asset_id: string | null;
  expected_version_revision: number;
}

export interface AdminGachaVersionRankUpdate {
  name: string;
  description: string | null;
  image_asset_id: string | null;
  video_asset_id: string | null;
  expected_revision: number;
  expected_version_revision: number;
}

export interface AdminGachaVersionRankCollection {
  items: AdminCatalogRank[];
  version_revision: number;
}

export interface AdminGachaVersionPrize extends AdminCatalogPrize {
  cost_price: number;
  total_inventory: number;
  available_inventory: number;
  awarded_inventory?: number;
  withdrawn_inventory?: number;
  inventory_revision?: number;
  version_sort_order: number;
  revision: number;
}

export interface AdminGachaVersionPrizeCreate {
  rank_id: string;
  presentation_asset_id: string | null;
  name: string;
  total_inventory: number;
  exchange_points: number;
  cost_price: number;
  is_active: boolean;
  expected_version_revision: number;
}

export interface AdminGachaVersionPrizeUpdate extends AdminGachaVersionPrizeCreate {
  available_inventory?: number;
  expected_revision: number;
  expected_inventory_revision?: number;
  inventory_reason?: string;
}

export interface AdminGachaVersionPrizeCollection {
  items: AdminGachaVersionPrize[];
  version_revision: number;
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

export type AdminGachaAllowedDrawCount = 1 | 5 | 10 | 100 | 1000;

export interface AdminCatalogGachaCoreVersion {
  id: string;
  version_number: number;
  status: "draft" | "published";
  title: string;
  description: string | null;
  notices: string | null;
  price_points: number;
  total_count: number;
  daily_draw_limit: number;
  audience_code: "all_users" | "first_time_users" | "line_users";
  first_time_eligible_days?: number;
  allowed_draw_counts?: AdminGachaAllowedDrawCount[];
  presentation_asset: AdminCatalogAssetReference | null;
  publish_start_at: string;
  publish_end_at: string | null;
  revision?: number;
}

export interface AdminCatalogGacha {
  id: string;
  public_code?: string;
  code: string;
  slug: string;
  state: "draft" | "active" | "disabled";
  sold_count: number;
  category: AdminCatalogReference;
  tags: AdminCatalogReference[];
  published_version: AdminCatalogGachaVersionSummary | null;
  current_version?: AdminCatalogGachaCoreVersion | null;
  publication_status?: "draft" | "published" | "scheduled" | "sales_paused" | "unpublished";
  first_published_at?: string | null;
  version_count: number;
  has_draw_history: boolean;
  is_archived: boolean;
  revision: number;
  archived_at: string | null;
  created_at: string;
  updated_at: string;
}

export type AdminGachaUsageHistoryStatus =
  | "selection_pending"
  | "shipping"
  | "point_exchange"
  | "expired"
  | "hold"
  | "canceled";

export interface AdminGachaUsageHistoryStatusCount {
  status: AdminGachaUsageHistoryStatus;
  count: number;
}

export interface AdminGachaUsageHistoryUser {
  id: string;
  display_name: string | null;
}

export interface AdminGachaUsageHistoryItem {
  id: string;
  user: AdminGachaUsageHistoryUser;
  executed_count: 1 | 5 | 10 | 100 | 1000;
  status_summary: AdminGachaUsageHistoryStatusCount[];
  used_at: string;
}

export interface AdminGachaUsageHistoryCollection {
  gacha_id: string;
  items: AdminGachaUsageHistoryItem[];
  next_cursor: string | null;
  request_id: string;
}

export type AdminGachaUsagePrizeStatus =
  | "stored"
  | "exchange_processing"
  | "converted"
  | "shipping_requested"
  | "packing"
  | "shipped"
  | "delivered"
  | "hold"
  | "return_requested"
  | "returned"
  | "expired"
  | "canceled";

export interface AdminGachaUsageHistoryPrize {
  draw_result_id: string;
  sequence: number;
  prize_id: string;
  rank: { id: string; name: string };
  prize_name: string;
  thumbnail: AdminCatalogAssetReference | null;
  exchange_points: number;
  status: AdminGachaUsagePrizeStatus;
  status_updated_at: string;
}

export interface AdminGachaUsageHistoryDetail {
  id: string;
  gacha: { id: string; version_id: string; title: string };
  user: AdminGachaUsageHistoryUser;
  executed_count: 1 | 5 | 10 | 100 | 1000;
  consumed_points: number;
  used_at: string;
  status_summary: AdminGachaUsageHistoryStatusCount[];
  prizes: AdminGachaUsageHistoryPrize[];
}

export interface AdminGachaUsageHistoryDetailResponse {
  data: AdminGachaUsageHistoryDetail;
  request_id: string;
}

export interface AdminCatalogGachaCreate {
  code: string;
  slug: string;
  category_id: string;
  tag_ids: string[];
}

export interface AdminCatalogGachaCoreCreate {
  title: string;
  category_id: string;
  tag_ids: string[];
  price_points: number;
  total_count: number;
  daily_draw_limit: number;
  audience_code: "all_users" | "first_time_users" | "line_users";
  first_time_eligible_days?: number;
  allowed_draw_counts?: AdminGachaAllowedDrawCount[];
  presentation_asset_id: string;
  publish_start_at: string;
  publish_end_at: string | null;
  description: string | null;
  notices: string | null;
}

export interface AdminCatalogGachaUpdate {
  expected_revision: number;
  expected_version_revision?: number;
  title?: string;
  category_id: string;
  tag_ids: string[];
  price_points?: number;
  total_count?: number;
  daily_draw_limit?: number;
  audience_code?: "all_users" | "first_time_users" | "line_users";
  first_time_eligible_days?: number;
  allowed_draw_counts?: AdminGachaAllowedDrawCount[];
  management_status?: "draft" | "scheduled" | "published" | "sales_paused" | "unpublished";
  presentation_asset_id?: string;
  publish_start_at?: string;
  publish_end_at?: string | null;
  description?: string | null;
  notices?: string | null;
}

export interface AdminGachaThumbnailUpload {
  file_name: string;
  mime_type: "image/gif" | "image/jpeg" | "image/png" | "image/webp";
  content_base64: string;
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
  daily_draw_limit?: number;
  audience_code?: "all_users" | "first_time_users" | "line_users";
  first_time_eligible_days?: number;
  allowed_draw_counts?: AdminGachaAllowedDrawCount[];
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

export interface AdminGachaPublishedProbabilityCandidate {
  id: string;
  version_number: number;
  published_at: string | null;
  snapshot_sha256: string;
  stage_count: number;
  validation_status: "valid" | "invalid";
}

export interface AdminGachaProbabilitySelection {
  gacha_version_id: string;
  gacha_version_revision: number;
  selected_probability: AdminGachaPublishedProbabilityCandidate | null;
}

export interface AdminGachaProbabilitySelectionRequest {
  expected_revision: number;
  probability_version_id: string;
}

export interface AdminGachaPublishBlockingReason {
  code: string;
  message: string;
}

export interface AdminGachaPublishPreflight {
  gacha_version_id: string;
  publishable: boolean;
  selected_probability: {
    id: string;
    snapshot_sha256: string;
  } | null;
  validation_codes: string[];
  blocking_reasons: AdminGachaPublishBlockingReason[];
  gacha_version_revision: number;
  request_id: string;
}

export interface AdminGachaPublishPreflightRequest {
  expected_revision: number;
}

export interface AdminGachaImmediatePublishRequest {
  expected_revision: number;
  expected_gacha_revision: number;
}

export interface AdminGachaPublishScheduleRequest {
  scheduled_for: string;
  expected_revision: number;
  expected_gacha_revision: number;
}

export interface AdminGachaPublishScheduleCancelRequest {
  expected_schedule_revision: number;
  expected_gacha_revision: number;
  expected_version_revision: number;
}

export interface AdminGachaPublishedVersionState {
  id: string;
  version_number: number;
  status?: "published";
  published_at?: string | null;
}

export interface AdminGachaDrawStateSummary {
  status: "selling" | "paused" | "sold_out";
  sold_count: number;
  total_count: number;
}

export interface AdminGachaPublishState {
  gacha_id: string;
  gacha_revision: number;
  current_published_version: AdminGachaPublishedVersionState | null;
  selected_probability: {
    id: string;
    snapshot_sha256: string;
  } | null;
  draw_state: AdminGachaDrawStateSummary | null;
  publish_schedule?: AdminGachaPublishSchedule | null;
}

export type AdminGachaSalesPauseReason =
  | "operations_review"
  | "inventory_review"
  | "incident_response";

export interface AdminGachaSalesPauseRequest {
  expected_gacha_revision: number;
  reason_code: AdminGachaSalesPauseReason;
}

export interface AdminGachaSalesResumeRequest {
  expected_gacha_revision: number;
}

export interface AdminGachaSalesState {
  gacha_id: string;
  status: "selling" | "paused";
  gacha_revision: number;
  paused_at: string | null;
  resumed_at: string | null;
  reason_code: AdminGachaSalesPauseReason | null;
  current_published_version: AdminGachaPublishedVersionState | null;
  selected_probability: {
    id: string;
    snapshot_sha256: string;
  } | null;
  draw_state: AdminGachaDrawStateSummary | null;
  publish_schedule: AdminGachaPublishSchedule | null;
  request_id: string;
}

export interface AdminGachaSalesPreflight {
  operation: "pause" | "resume";
  allowed: boolean;
  validation_codes: string[];
  blocking_reasons: AdminGachaPublishBlockingReason[];
  sales_state: AdminGachaSalesState;
  request_id: string;
}

export interface AdminGachaUnpublishRequest {
  expected_gacha_revision: number;
}

export interface AdminGachaUnpublishState {
  gacha_id: string;
  status: "published" | "unpublished";
  gacha_revision: number;
  sales_status: "selling" | "paused";
  deactivated_at: string | null;
  current_published_version: AdminGachaPublishedVersionState | null;
  selected_probability: {
    id: string;
    snapshot_sha256: string;
  } | null;
  draw_state: AdminGachaDrawStateSummary | null;
  publish_schedule: AdminGachaPublishSchedule | null;
  request_id: string;
}

export interface AdminGachaUnpublishPreflight {
  allowed: boolean;
  validation_codes: string[];
  blocking_reasons: AdminGachaPublishBlockingReason[];
  state: AdminGachaUnpublishState;
  request_id: string;
}

export interface AdminGachaImmediatePublish {
  gacha_version_id: string;
  status: "published";
  published_at: string;
  gacha_version_revision: number;
  gacha_revision: number;
  selected_probability: {
    id: string;
    snapshot_sha256: string;
  };
  previous_published_version: AdminGachaPublishedVersionState | null;
  current_published_version: AdminGachaPublishedVersionState;
  draw_state: AdminGachaDrawStateSummary;
  request_id: string;
}

export interface AdminGachaPublishSchedulePreflight
  extends AdminGachaPublishPreflight {
  scheduled_for: string;
  server_timezone: "UTC";
  display_timezone: "Asia/Tokyo";
}

export interface AdminGachaPublishSchedule {
  id: string;
  status: "scheduled" | "processing" | "completed" | "cancelled" | "failed";
  scheduled_for: string;
  next_attempt_at: string;
  server_timezone: "UTC";
  display_timezone: "Asia/Tokyo";
  gacha_version_id: string;
  selected_probability: {
    id: string;
    snapshot_sha256: string;
  };
  attempts: number;
  failure_code: string | null;
  revision: number;
  gacha_revision: number;
  gacha_version_revision: number;
  started_at: string | null;
  completed_at: string | null;
  cancelled_at: string | null;
  failed_at: string | null;
  request_id: string;
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

export interface AdminBannerCategory {
  id: string;
  name: string;
  created_at?: string;
}

export type AdminPageVisibility = "visible" | "hidden";

export interface AdminPageCategory {
  id: string;
  name: string;
  visibility: AdminPageVisibility;
  created_at: string;
}

export interface AdminPageCategoryCollection {
  items: AdminPageCategory[];
}

export interface AdminPageCategoryCreate {
  name: string;
  visibility: AdminPageVisibility;
}

export interface AdminPageCategoryMutationResult extends AdminPageCategory {
  idempotent_replay: boolean;
}

export interface AdminManagedPageInput {
  category_id: string;
  title: string;
  body_html: string;
  slug: string;
  visibility: AdminPageVisibility;
  show_in_footer?: boolean;
  footer_sort_order?: number;
}

export interface AdminManagedPagePreviewInput {
  title: string;
  body_html: string;
}

export interface AdminManagedPagePreview {
  title: string;
  body_html: string;
}

export interface AdminManagedPage {
  id: string;
  slug: string;
  title: string;
  body_html: string;
  visibility: AdminPageVisibility;
  show_in_footer: boolean;
  footer_sort_order: number;
  category: AdminPageCategory | null;
  version_id: string;
  version_number: number;
  created_at: string;
  updated_at: string;
}

export interface AdminManagedPageMutationResult extends AdminManagedPage {
  idempotent_replay: boolean;
}

export interface AdminManagedPageCollection {
  items: AdminManagedPage[];
  next_cursor: string | null;
}

export interface AdminBannerCategoryCollection {
  items: AdminBannerCategory[];
}

export interface AdminBannerCategoryCreate {
  name: string;
}

export interface AdminBannerCategoryMutationResult extends AdminBannerCategory {
  idempotent_replay: boolean;
}

export interface AdminBannerAssetUpload {
  file_name: string;
  mime_type: "image/gif" | "image/jpeg" | "image/png" | "image/webp";
  content_base64: string;
}

export interface AdminBannerAssetMutationResult {
  id: string;
  public_url: string;
  mime_type: string;
  byte_size: number;
  idempotent_replay: boolean;
}

export interface AdminManagedBannerInput {
  category_id: string;
  title: string;
  asset_id?: string | null;
  show_on_top: boolean;
  link_url?: string | null;
}

export interface AdminManagedBannerCreate extends AdminManagedBannerInput {
  asset_id: string;
}

export type AdminManagedBannerUpdate = AdminManagedBannerInput;

export interface AdminManagedBanner {
  id: string;
  title: string;
  status: "draft" | "published";
  show_on_top: boolean;
  link_url: string | null;
  category: AdminBannerCategory;
  asset: { id: string; public_url: string };
  version_id: string;
  version_number: number;
  created_at: string;
  updated_at: string;
}

export interface AdminManagedBannerMutationResult extends AdminManagedBanner {
  idempotent_replay: boolean;
}

export interface AdminManagedBannerCollection {
  items: AdminManagedBanner[];
  next_cursor: string | null;
}

export interface AdminManagedBannerDeleteResult {
  id: string;
  deleted: true;
  asset_retained: true;
  idempotent_replay: boolean;
}

export interface AdminContentVersionInput {
  title: string;
  summary?: string | null;
  body_html: string;
  sort_order?: number;
  is_important?: boolean;
  asset_id?: string | null;
  publish_start_at: string;
  publish_end_at?: string | null;
}

export interface AdminContentNoticeCreate extends AdminContentVersionInput {
  slug: string;
}

export interface AdminContentVersion {
  id: string;
  version_number: number;
  status: "draft" | "published";
  title: string;
  summary: string | null;
  body_html: string | null;
  link_url: string | null;
  sort_order: number;
  is_important: boolean;
  publish_start_at: string;
  publish_end_at: string | null;
  checksum_sha256: string;
  asset_id: string | null;
  published_at: string | null;
  idempotent_replay?: boolean;
}

export interface AdminContentSummary {
  id: string;
  identifier: string;
  status: "draft" | "published" | "archived";
  is_legal: boolean;
  published_version_id: string | null;
  latest_version?: AdminContentVersion | null;
  created_at: string;
  updated_at: string;
}

export interface AdminContentCollection {
  items: AdminContentSummary[];
  next_cursor: string | null;
}

export interface AdminContentDetail {
  id: string;
  identifier: string;
  status: "draft" | "published" | "archived";
  is_legal: boolean;
  versions: AdminContentVersion[];
  idempotent_replay?: boolean;
}

export interface AdminContentPreview {
  title: string;
  summary: string | null;
  body_html: string;
  is_important: boolean;
  asset_id: string | null;
  publish_start_at: string;
  publish_end_at: string | null;
}

export type AdminContactStatus = "new" | "in_progress" | "replied" | "closed";

export interface AdminContactSummary {
  id: string;
  receipt_code: string;
  status: AdminContactStatus;
  authenticated: boolean;
  received_at: string;
  name?: string;
  email?: string;
  phone?: string | null;
  body_excerpt?: string;
  updated_at?: string;
}

export interface AdminContactCollection {
  items: AdminContactSummary[];
  next_cursor: string | null;
}

export interface AdminContactStatusHistory {
  from_status: AdminContactStatus | null;
  to_status: AdminContactStatus;
  reason_code: string;
  occurred_at: string;
}

export interface AdminContactInternalNote {
  note: string;
  created_at: string;
}

export interface AdminContactReplyRequest {
  id: string;
  message: string;
  created_at: string;
}

export interface AdminContactDetail extends AdminContactSummary {
  name: string;
  email: string;
  phone: string | null;
  subject: string;
  body: string;
  closed_at: string | null;
  updated_at: string;
  status_history: AdminContactStatusHistory[];
  internal_notes: AdminContactInternalNote[];
  reply_requests?: AdminContactReplyRequest[];
}

export interface AdminContactStatusUpdate {
  status: AdminContactStatus;
  reason_code: string;
}

export interface AdminContactStatusResult {
  id: string;
  status: AdminContactStatus;
  updated_at: string;
  idempotent_replay?: boolean;
}

export interface AdminContactReplyInput {
  message: string;
}

export interface AdminContactReplyResult {
  id: string;
  status: "queued";
  idempotent_replay?: boolean;
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
