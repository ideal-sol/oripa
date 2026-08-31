import type {
  PlatformRuntimeCompatibility,
  SiteManifest,
} from "@oripa/site-schema";
import type { StorefrontResponseMetadata } from "@oripa/storefront-client";
import type { PublicComponents } from "@oripa/storefront-client";

export {
  PUBLIC_CONTRACT_FIXTURE,
} from "./generated/public-contract.js";

export const PUBLIC_PAYMENT_GRANT_FIXTURES = Object.freeze({
  without_limited_bonus: {
    paid_points: 10000,
    bonus_points: 1000,
    limited_bonus_points: 0,
    total_points: 11000,
  },
  limited_bonus_applied: {
    paid_points: 10000,
    bonus_points: 1000,
    limited_bonus_points: 2000,
    total_points: 13000,
  },
} as const satisfies Record<
  string,
  PublicComponents["schemas"]["PaymentGrant"]
>);

export const PUBLIC_PAYMENT_CARD_UI_BOOTSTRAP_FIXTURES = Object.freeze({
  sandbox: {
    provider: "fincode",
    public_api_key: "p_test_public-safe-fixture",
    is_live_mode: false,
  },
  live: {
    provider: "fincode",
    public_api_key: "p_prod_public-safe-fixture",
    is_live_mode: true,
  },
} as const satisfies Record<
  string,
  PublicComponents["schemas"]["PaymentCardUiBootstrap"]
>);

const CARD_REGISTRATION_ID = "0198a001-0000-7000-8000-000000009801";
const SAVED_CARD_ID = "0198a001-0000-7000-8000-000000009802";

export const PUBLIC_PAYMENT_CARD_REGISTRATION_FIXTURES = Object.freeze({
  requires_action: {
    id: CARD_REGISTRATION_ID,
    status: "requires_action",
    expires_at: "2026-08-29T12:15:00Z",
    completed_at: null,
    saved_card_id: null,
    next_action: {
      type: "three_d_secure",
      url: "https://pay.test.fincode.jp/card-registration/public-safe-fixture",
    },
  },
  pending: {
    id: CARD_REGISTRATION_ID,
    status: "pending",
    expires_at: "2026-08-29T12:15:00Z",
    completed_at: null,
    saved_card_id: null,
    next_action: null,
  },
  completed: {
    id: CARD_REGISTRATION_ID,
    status: "completed",
    expires_at: "2026-08-29T12:15:00Z",
    completed_at: "2026-08-29T12:03:00Z",
    saved_card_id: SAVED_CARD_ID,
    next_action: null,
  },
  duplicate_return: {
    id: CARD_REGISTRATION_ID,
    status: "completed",
    expires_at: "2026-08-29T12:15:00Z",
    completed_at: "2026-08-29T12:03:00Z",
    saved_card_id: SAVED_CARD_ID,
    next_action: null,
  },
  duplicate_reconcile: {
    id: CARD_REGISTRATION_ID,
    status: "completed",
    expires_at: "2026-08-29T12:15:00Z",
    completed_at: "2026-08-29T12:03:00Z",
    saved_card_id: SAVED_CARD_ID,
    next_action: null,
  },
  failed: {
    id: CARD_REGISTRATION_ID,
    status: "failed",
    expires_at: "2026-08-29T12:15:00Z",
    completed_at: null,
    saved_card_id: null,
    next_action: null,
  },
  canceled: {
    id: CARD_REGISTRATION_ID,
    status: "canceled",
    expires_at: "2026-08-29T12:15:00Z",
    completed_at: null,
    saved_card_id: null,
    next_action: null,
  },
  expired: {
    id: CARD_REGISTRATION_ID,
    status: "expired",
    expires_at: "2026-08-29T12:15:00Z",
    completed_at: null,
    saved_card_id: null,
    next_action: null,
  },
} as const satisfies Record<
  string,
  PublicComponents["schemas"]["PaymentCardRegistration"]
>);

const VERIFIED_CARD_FIXTURES: PublicComponents["schemas"]["PaymentCard"][] = [
  {
    id: "0198a001-0000-7000-8000-000000009811",
    brand: "VISA",
    last4: "4242",
    expiration: { month: 12, year: 2030 },
    verification_status: "verified",
    is_expired: false,
    can_pay: true,
    last_used_at: null,
  },
  {
    id: "0198a001-0000-7000-8000-000000009812",
    brand: "MASTERCARD",
    last4: "4444",
    expiration: { month: 11, year: 2031 },
    verification_status: "verified",
    is_expired: false,
    can_pay: true,
    last_used_at: null,
  },
];

export const PUBLIC_PAYMENT_CARD_CAPACITY_FIXTURES = Object.freeze({
  saved_0_pending_0: {
    data: [],
    limits: {
      maximum: 3,
      remaining: 3,
      registration_remaining: 3,
      next_capacity_at: null,
    },
  },
  saved_2_pending_1: {
    data: VERIFIED_CARD_FIXTURES,
    limits: {
      maximum: 3,
      remaining: 1,
      registration_remaining: 0,
      next_capacity_at: "2026-08-29T12:15:00Z",
    },
  },
  pending_terminal_released: {
    data: VERIFIED_CARD_FIXTURES,
    limits: {
      maximum: 3,
      remaining: 1,
      registration_remaining: 1,
      next_capacity_at: null,
    },
  },
} as const satisfies Record<
  string,
  PublicComponents["schemas"]["PaymentCardCollection"]
>);

const cardRegistrationProblem = (
  code: PublicComponents["schemas"]["CardRegistrationProblemCode"],
  status: number,
  retryable = false,
): PublicComponents["schemas"]["CardRegistrationProblemDetails"] => ({
  type: `https://oripa.example/problems/${code.toLowerCase().replaceAll("_", "-")}`,
  title: "Public-safe card registration problem fixture.",
  status,
  code,
  request_id: "0198a001-0000-7000-8000-000000009899",
  retryable,
});

export const PUBLIC_PAYMENT_CARD_REGISTRATION_PROBLEM_FIXTURES = Object.freeze({
  legacy_rejected: cardRegistrationProblem("CARD_REGISTRATION_3DS_REQUIRED", 409),
  failed: cardRegistrationProblem("CARD_REGISTRATION_FAILED", 422),
  canceled: cardRegistrationProblem("CARD_REGISTRATION_CANCELED", 409),
  expired: cardRegistrationProblem("CARD_INTENT_EXPIRED", 409),
  capacity: cardRegistrationProblem("CARD_LIMIT_REACHED", 409),
  unavailable: cardRegistrationProblem("CARD_REGISTRATION_UNAVAILABLE", 503, true),
  ownership: cardRegistrationProblem("CARD_REGISTRATION_OWNERSHIP_INVALID", 422),
});

export const MINIMAL_SITE_MANIFEST_FIXTURE = Object.freeze(
  {
    schema_version: "2.0.0-alpha.1",
    site_version: "1.0.0-alpha.1",
    compatibility: {
      family: 2,
      storefront_client_version: "2.0.0-alpha.26",
      required_capabilities: [],
    },
    public: {
      locale: "ja-JP",
      timezone: "Asia/Tokyo",
      features: {
        enabled: [],
      },
    },
  } as const satisfies SiteManifest,
);

export const CAPABILITY_SITE_MANIFEST_FIXTURE = Object.freeze(
  {
    ...MINIMAL_SITE_MANIFEST_FIXTURE,
    compatibility: {
      ...MINIMAL_SITE_MANIFEST_FIXTURE.compatibility,
      required_capabilities: [
        "auth.session.v2",
        "draw.browser-mutation.v2",
        "gacha.catalog-display.v2",
        "gacha.presentation.v2",
        "payment.fincode.v2",
        "prize.fulfillment-browser-mutation.v2",
        "user-line-friend-state.read.v2",
        "user-draw-history.read.v2",
        "user-point.read.v2",
        "user-prize.presentation.v2",
      ],
    },
    public: {
      ...MINIMAL_SITE_MANIFEST_FIXTURE.public,
      features: {
        enabled: [
          "auth.session.v2",
          "draw.browser-mutation.v2",
          "gacha.catalog-display.v2",
          "gacha.presentation.v2",
          "payment.fincode.v2",
          "prize.fulfillment-browser-mutation.v2",
          "user-line-friend-state.read.v2",
          "user-draw-history.read.v2",
          "user-point.read.v2",
          "user-prize.presentation.v2",
        ],
      },
    },
  } as const satisfies SiteManifest,
);

export const PLATFORM_COMPATIBILITY_FIXTURE = Object.freeze({
  compatibility_family: 2,
  minimum_storefront_client_version: "2.0.0-alpha.23",
  capabilities: [
    "auth.session.v2",
    "draw.browser-mutation.v2",
    "gacha.catalog-display.v2",
    "gacha.presentation.v2",
    "payment.fincode.v2",
    "prize.fulfillment-browser-mutation.v2",
    "user-line-friend-state.read.v2",
    "user-draw-history.read.v2",
    "user-point.read.v2",
    "user-prize.presentation.v2",
  ],
}) satisfies PlatformRuntimeCompatibility;

export const PUBLIC_RESPONSE_METADATA_FIXTURE = Object.freeze({
  status: 200,
  request_id: "request-fixture-001",
  api_version: "2",
  idempotency_replayed: false,
}) satisfies StorefrontResponseMetadata;

export const PUBLIC_FOOTER_PAGES_FIXTURE = Object.freeze({
  response: {
    items: [
      {
        id: "0198a001-0000-7000-8000-000000000301",
        slug: "terms",
        title: "利用規約",
      },
    ],
  },
  excluded: {
    footer_off: {
      id: "0198a001-0000-7000-8000-000000000302",
      slug: "guide",
      title: "ご利用ガイド",
    },
    outside_publication_period: {
      id: "0198a001-0000-7000-8000-000000000303",
      slug: "future-policy",
      title: "公開前ポリシー",
    },
  },
} as const satisfies {
  response: PublicComponents["schemas"]["ContentFooterPageCollection"];
  excluded: {
    footer_off: PublicComponents["schemas"]["ContentFooterPage"];
    outside_publication_period: PublicComponents["schemas"]["ContentFooterPage"];
  };
});

export const PUBLIC_TOP_BANNERS_FIXTURE = Object.freeze({
  response: {
    items: [
      {
        id: "0198a001-0000-7000-8000-000000000311",
        title: "トップ表示バナー",
        image_url: "/api/v2/content/assets/0198a001-0000-7000-8000-000000000312",
        link_url: "/gachas",
        asset: {
          id: "0198a001-0000-7000-8000-000000000312",
          path: "/api/v2/content/assets/0198a001-0000-7000-8000-000000000312",
          checksum_sha256: "4bf5122f344554c53bde2ebb8cd2b7e3d1600ad631c385a5d7c4f0f8d160d2d0",
          alt_text: "トップ表示バナー",
        },
        publish_start_at: "2026-07-28T00:00:00Z",
        publish_end_at: null,
      },
    ],
  },
  excluded: {
    top_off: { id: "0198a001-0000-7000-8000-000000000313", title: "トップ非表示" },
    outside_publication_period: { id: "0198a001-0000-7000-8000-000000000314", title: "公開期間外" },
  },
} as const satisfies {
  response: PublicComponents["schemas"]["ContentBannerCollection"];
  excluded: {
    top_off: { id: string; title: string };
    outside_publication_period: { id: string; title: string };
  };
});

export const PUBLIC_CONTACT_FIXTURE = Object.freeze({
  input: {
    name: "Fixture User",
    email: "fixture@example.test",
    phone: null,
    subject: "Fixture inquiry",
    body: "Public-safe fixture body.",
    website: "",
  },
  receipt: {
    receipt_code: "CNT-0123456789ABCDEFGHIJ",
    status: "accepted",
    received_at: "2026-08-20T00:00:00Z",
    request_id: "request-contact-receipt-001",
  },
} as const satisfies {
  input: PublicComponents["schemas"]["CreateContactInquiryRequest"];
  receipt: PublicComponents["schemas"]["ContactInquiryReceipt"];
});

export const PUBLIC_CONTACT_PROBLEM_FIXTURES = Object.freeze({
  validation: {
    type: "https://oripa.example/problems/invalid-request",
    title: "The request is invalid.",
    status: 422,
    code: "INVALID_REQUEST",
    request_id: "request-contact-validation-001",
    retryable: false,
    errors: {
      email: ["The email field must be a valid email address."],
    },
  },
  rate_limited: {
    type: "https://oripa.example/problems/rate-limited",
    title: "Too many requests.",
    status: 429,
    code: "RATE_LIMITED",
    request_id: "request-contact-rate-001",
    retryable: true,
    retry_after_seconds: 3600,
  },
} as const satisfies Record<
  string,
  PublicComponents["schemas"]["ProblemDetails"]
>);

const allUsersPointProduct = {
  id: "0198a001-0000-7000-8000-000000000321",
  title: "スタンダード1000ポイント",
  price: { amount: 1000, currency: "JPY" },
  grant: { paid_points: 1000, bonus_points: 100, total_points: 1100 },
  limited_bonus: {
    amount: 300,
    starts_at: "2026-08-15T00:00:00Z",
    ends_at: "2026-08-16T00:00:00Z",
    state: "active",
    as_of: "2026-08-15T00:00:00Z",
    presentation: {
      is_visible: true,
      label: "期間限定ボーナスコイン",
      amount_text: "+300コイン",
    },
  },
  audience: { code: "all_users", label: "すべてのユーザー" },
  sale_state: "available",
  is_available: true,
  user_state: "authenticated",
  eligible: true,
  ineligible_reason: null,
  cta: { state: "enabled", action: "purchase", reason: null },
} as const satisfies PublicComponents["schemas"]["PointProduct"];

const firstPurchasePointProduct = {
  id: "0198a001-0000-7000-8000-000000000322",
  title: "初回限定1000ポイント",
  price: { amount: 1000, currency: "JPY" },
  grant: { paid_points: 1000, bonus_points: 500, total_points: 1500 },
  limited_bonus: {
    amount: 500,
    starts_at: "2026-08-16T00:00:00Z",
    ends_at: "2026-08-17T00:00:00Z",
    state: "upcoming",
    as_of: "2026-08-15T00:00:00Z",
    presentation: {
      is_visible: true,
      label: "期間限定ボーナスコイン",
      amount_text: "+500コイン",
    },
  },
  audience: { code: "first_purchase_users", label: "初回ユーザー" },
  sale_state: "available",
  is_available: true,
  user_state: "authenticated",
  eligible: true,
  ineligible_reason: null,
  cta: { state: "enabled", action: "purchase", reason: null },
} as const satisfies PublicComponents["schemas"]["PointProduct"];

export const PUBLIC_POINT_PRODUCT_FIXTURES = Object.freeze({
  anonymous: {
    data: [
      {
        ...allUsersPointProduct,
        user_state: "unauthenticated",
        eligible: false,
        ineligible_reason: "authentication_required",
        cta: {
          state: "enabled",
          action: "login",
          reason: "authentication_required",
        },
      },
      {
        ...firstPurchasePointProduct,
        user_state: "unauthenticated",
        eligible: false,
        ineligible_reason: "authentication_required",
        cta: {
          state: "enabled",
          action: "login",
          reason: "authentication_required",
        },
      },
    ],
  },
  anonymous_empty: { data: [] },
  authenticated_eligible: {
    data: [allUsersPointProduct, firstPurchasePointProduct],
  },
  authenticated_after_first_purchase: {
    data: [
      allUsersPointProduct,
      {
        ...firstPurchasePointProduct,
        eligible: false,
        ineligible_reason: "first_purchase_required",
        cta: {
          state: "disabled",
          action: "purchase",
          reason: "first_purchase_required",
        },
      },
    ],
  },
  unavailable: {
    data: [
      {
        ...allUsersPointProduct,
        limited_bonus: {
          amount: 0,
          starts_at: null,
          ends_at: null,
          state: "inactive",
          as_of: "2026-08-15T00:00:00Z",
          presentation: {
            is_visible: false,
            label: "期間限定ボーナスコイン",
            amount_text: null,
          },
        },
        sale_state: "ended",
        is_available: false,
        eligible: false,
        ineligible_reason: "sale_ended",
        cta: {
          state: "disabled",
          action: "purchase",
          reason: "sale_ended",
        },
      },
    ],
  },
} as const satisfies Record<
  string,
  PublicComponents["schemas"]["PointProductCollection"]
>);

export const PUBLIC_POINT_BALANCE_FIXTURES = Object.freeze({
  positive: {
    paid_points: 800,
    free_points: 200,
    total_points: 1000,
    as_of: "2026-08-15T00:00:00Z",
    expiring_within_7_days: [],
  },
  canonical_expiry: {
    paid_points: 130,
    free_points: 160,
    total_points: 290,
    as_of: "2026-08-15T00:00:00Z",
    expiring_within_7_days: [
      { expires_at: "2026-08-21T00:00:00Z", amount: 60 },
      { expires_at: "2026-08-21T01:00:00Z", amount: 10 },
      { expires_at: "2026-08-22T00:00:00Z", amount: 80 },
    ],
  },
  legacy_no_expiry: {
    paid_points: 50,
    free_points: 0,
    total_points: 50,
    as_of: "2026-08-15T00:00:00Z",
    expiring_within_7_days: [],
  },
  expired_excluded: {
    paid_points: 0,
    free_points: 0,
    total_points: 0,
    as_of: "2026-08-15T00:00:00Z",
    expiring_within_7_days: [],
  },
  reserved_excluded: {
    paid_points: 0,
    free_points: 0,
    total_points: 0,
    as_of: "2026-08-15T00:00:00Z",
    expiring_within_7_days: [],
  },
  seven_day_boundary: {
    paid_points: 0,
    free_points: 30,
    total_points: 30,
    as_of: "2026-08-15T00:00:00Z",
    expiring_within_7_days: [
      { expires_at: "2026-08-22T00:00:00Z", amount: 20 },
    ],
  },
  less_than_seven_days: {
    paid_points: 0,
    free_points: 20,
    total_points: 20,
    as_of: "2026-08-15T00:00:00Z",
    expiring_within_7_days: [
      { expires_at: "2026-08-21T23:59:59Z", amount: 20 },
    ],
  },
  over_seven_days: {
    paid_points: 0,
    free_points: 20,
    total_points: 20,
    as_of: "2026-08-15T00:00:00Z",
    expiring_within_7_days: [],
  },
  expires_at_as_of_excluded: {
    paid_points: 0,
    free_points: 0,
    total_points: 0,
    as_of: "2026-08-15T00:00:00Z",
    expiring_within_7_days: [],
  },
  same_expiry_aggregated: {
    paid_points: 0,
    free_points: 30,
    total_points: 30,
    as_of: "2026-08-15T00:00:00Z",
    expiring_within_7_days: [
      { expires_at: "2026-08-18T00:00:00Z", amount: 30 },
    ],
  },
  timestamp_separation: {
    paid_points: 0,
    free_points: 42,
    total_points: 42,
    as_of: "2026-08-15T00:00:00Z",
    expiring_within_7_days: [
      { expires_at: "2026-08-18T00:00:00Z", amount: 30 },
      { expires_at: "2026-08-18T01:00:00Z", amount: 12 },
    ],
  },
  zero: {
    paid_points: 0,
    free_points: 0,
    total_points: 0,
    as_of: "2026-08-15T00:00:00Z",
    expiring_within_7_days: [],
  },
} as const satisfies Record<
  string,
  PublicComponents["schemas"]["CurrentUserWalletBalance"]
>);

export const PUBLIC_POINT_HISTORY_FIXTURES = Object.freeze({
  multiple: {
    items: [
      {
        id: "0198a001-0000-7000-8000-000000000343",
        occurred_at: "2026-08-15T00:03:00Z",
        amount_delta: -300,
        reason: { label: "ガチャ利用" },
      },
      {
        id: "0198a001-0000-7000-8000-000000000342",
        occurred_at: "2026-08-15T00:02:00Z",
        amount_delta: 50,
        reason: { label: "景品のポイント交換" },
      },
      {
        id: "0198a001-0000-7000-8000-000000000341",
        occurred_at: "2026-08-15T00:01:00Z",
        amount_delta: 1000,
        reason: { label: "ポイント購入" },
      },
    ],
    next_cursor: null,
  },
  empty: { items: [], next_cursor: null },
  first_page: {
    items: [
      {
        id: "0198a001-0000-7000-8000-000000000343",
        occurred_at: "2026-08-15T00:03:00Z",
        amount_delta: -300,
        reason: { label: "ガチャ利用" },
      },
    ],
    next_cursor: "MDE5OGEwMDEtMDAwMC03MDAwLTgwMDAtMDAwMDAwMDAwMzQz",
  },
  continuation: {
    items: [
      {
        id: "0198a001-0000-7000-8000-000000000342",
        occurred_at: "2026-08-15T00:02:00Z",
        amount_delta: 50,
        reason: { label: "景品のポイント交換" },
      },
    ],
    next_cursor: null,
  },
} as const satisfies Record<
  string,
  PublicComponents["schemas"]["PointHistoryCollection"]
>);

export const PUBLIC_POINT_READ_PROBLEM_FIXTURES = Object.freeze({
  unauthenticated: {
    type: "https://oripa.example/problems/authentication_required",
    title: "Authentication is required.",
    status: 401,
    code: "AUTHENTICATION_REQUIRED",
    request_id: "request-point-auth-001",
    retryable: false,
  },
  session_expired: {
    type: "https://oripa.example/problems/session_expired",
    title: "The session has expired.",
    status: 401,
    code: "SESSION_EXPIRED",
    request_id: "request-point-session-001",
    retryable: false,
  },
  rate_limited: {
    type: "https://oripa.example/problems/rate_limited",
    title: "Too many requests.",
    status: 429,
    code: "RATE_LIMITED",
    request_id: "request-point-rate-001",
    retryable: true,
    retry_after_seconds: 60,
  },
} as const satisfies Record<
  string,
  PublicComponents["schemas"]["PointReadProblemDetails"]
>);

export const PUBLIC_CATALOG_FIXTURE = Object.freeze({
  data: {
    id: "0198a001-0000-7000-8000-000000000011",
    slug: "fixture-catalog",
    title: "Fixture Catalog Gacha",
    price_points: 100,
    total_count: 1000,
    remaining_count: 995,
    publish_start_at: "2026-01-01T00:00:00Z",
    publish_end_at: "2027-01-01T00:00:00Z",
    category: {
      id: "0198a001-0000-7000-8000-000000000001",
      slug: "cards",
      name: "カード",
      description: "公開用の決定的Fixtureカテゴリ。",
    },
    tags: [
      {
        id: "0198a001-0000-7000-8000-000000000002",
        slug: "featured",
        name: "注目",
      },
    ],
    presentation_asset: {
      id: "0198a001-0000-7000-8000-000000000005",
      path: "/api/v2/content/assets/0198a001-0000-7000-8000-000000000005",
      checksum_sha256:
        "0605cbbe5fcd83f57adc97efe4eb39efc5639b28f6fc48e097dc4a9ba68d86c8",
      media_type: "image",
      mime_type: "image/png",
      alt_text: "Fixtureガチャ",
    },
    description: "MIG-050の決定的Fixture。",
    notices: "Production利用不可。",
    ranks: [
      {
        rank_id: "0198a001-0000-7000-8000-000000000003",
        rank_name: "Sランク",
        lineup_image: {
          id: "0198a001-0000-7000-8000-000000000005",
          path: "/api/v2/content/assets/0198a001-0000-7000-8000-000000000005",
          checksum_sha256:
            "0605cbbe5fcd83f57adc97efe4eb39efc5639b28f6fc48e097dc4a9ba68d86c8",
          media_type: "image",
          mime_type: "image/png",
          alt_text: "Sランク景品ラインナップ",
        },
        show_total_stock: true,
        total_stock: 100,
        display_order: 10,
        current_video: {
          id: "0198a001-0000-7000-8000-000000000006",
          path: "/api/v2/content/assets/0198a001-0000-7000-8000-000000000006",
          checksum_sha256:
            "8d719a8e24354d042de0b73ee5cc4e145da4ac9c00cd0474d16386e1244ba7d1",
          media_type: "video",
          mime_type: "video/mp4",
          alt_text: "Sランク抽選演出",
        },
      },
    ],
    probability_stages: [
      {
        id: "0198a001-0000-7000-8000-000000000014",
        code: "stage-1",
        name: "Stage 1",
        condition: {
          type: "sold_count",
          min_draw_number: 1,
          max_draw_number: 500,
        },
        is_current: true,
        rank_probabilities: [
          {
            rank: {
              id: "0198a001-0000-7000-8000-000000000003",
              name: "Sランク",
            },
            total_ppm: 100000,
          },
        ],
        point_back_total_ppm: 100000,
        minimum_guarantee: {
          result_type: "prize",
          total_ppm: 800000,
          rank: {
            id: "0198a001-0000-7000-8000-000000000004",
            name: "Aランク",
          },
        },
      },
    ],
    sale_state: "on_sale",
  },
} as const satisfies PublicComponents["schemas"]["GachaDetailResponse"]);

export const PUBLIC_GACHA_PRESENTATION_FIXTURE = Object.freeze({
  data: {
    gacha_id: "0198a001-0000-7000-8000-000000000011",
    sale_state: "on_sale",
    user_state: "authenticated",
    audience: "first_time_users",
    eligible: true,
    ineligible_reason: null,
    allowed_draw_counts: [1, 5, 10],
    daily_limit: {
      limit: 10,
      unlimited: false,
      used: 0,
      remaining: 10,
      resets_at: "2026-07-29T15:00:00Z",
    },
    cta: {
      state: "enabled",
      action: "draw",
      reason: null,
    },
    display: {
      show_price_points: true,
      show_total_count: true,
      show_drawn_count: true,
    },
  },
} as const satisfies PublicComponents["schemas"]["GachaPresentationResponse"]);

const catalogFixture = {
  ...PUBLIC_CATALOG_FIXTURE.data,
  drawn_count: 5,
};
const presentationFixture = PUBLIC_GACHA_PRESENTATION_FIXTURE.data;

export const PUBLIC_GACHA_CATALOG_DISPLAY_FIXTURES = Object.freeze({
  on_sale: {
    ...catalogFixture,
    presentation: presentationFixture,
  },
  coming_soon: {
    ...catalogFixture,
    presentation: {
      ...presentationFixture,
      sale_state: "coming_soon",
      eligible: false,
      ineligible_reason: "sale_not_started",
      allowed_draw_counts: [],
      cta: {
        state: "disabled",
        action: "draw",
        reason: "sale_not_started",
      },
    },
  },
  paused: {
    ...catalogFixture,
    presentation: {
      ...presentationFixture,
      sale_state: "paused",
      eligible: false,
      ineligible_reason: "sales_paused",
      allowed_draw_counts: [],
      cta: {
        state: "disabled",
        action: "draw",
        reason: "sales_paused",
      },
    },
  },
  ended: {
    ...catalogFixture,
    presentation: {
      ...presentationFixture,
      sale_state: "ended",
      eligible: false,
      ineligible_reason: "sale_ended",
      allowed_draw_counts: [],
      cta: { state: "hidden", action: null, reason: "sale_ended" },
      display: {
        show_price_points: false,
        show_total_count: false,
        show_drawn_count: false,
      },
    },
  },
  sold_out: {
    ...catalogFixture,
    presentation: {
      ...presentationFixture,
      sale_state: "sold_out",
      eligible: false,
      ineligible_reason: "sold_out",
      allowed_draw_counts: [],
      cta: { state: "hidden", action: null, reason: "sold_out" },
      display: {
        show_price_points: false,
        show_total_count: false,
        show_drawn_count: false,
      },
    },
  },
  authenticated_eligible: {
    ...catalogFixture,
    presentation: presentationFixture,
  },
  authenticated_ineligible: {
    ...catalogFixture,
    presentation: {
      ...presentationFixture,
      eligible: false,
      ineligible_reason: "audience_not_eligible",
      allowed_draw_counts: [],
      cta: {
        state: "disabled",
        action: "draw",
        reason: "audience_not_eligible",
      },
    },
  },
  anonymous: {
    ...catalogFixture,
    presentation: {
      ...presentationFixture,
      user_state: "unauthenticated",
      eligible: false,
      ineligible_reason: "authentication_required",
      allowed_draw_counts: [],
      daily_limit: {
        ...presentationFixture.daily_limit,
        used: null,
        remaining: null,
      },
      cta: {
        state: "enabled",
        action: "login",
        reason: "authentication_required",
      },
    },
  },
} as const satisfies Record<
  string,
  PublicComponents["schemas"]["GachaSummary"]
>);

const drawHistoryGacha = {
  id: "0198a001-0000-7000-8000-000000000011",
  title: "Fixture Catalog Gacha",
  presentation_asset: {
    id: "0198a001-0000-7000-8000-000000000005",
    path: "/api/v2/content/assets/0198a001-0000-7000-8000-000000000005",
    checksum_sha256:
      "0605cbbe5fcd83f57adc97efe4eb39efc5639b28f6fc48e097dc4a9ba68d86c8",
    media_type: "image",
    mime_type: "image/png",
    alt_text: "Fixtureガチャ",
  },
} as const satisfies PublicComponents["schemas"]["DrawHistoryGachaPresentation"];

const drawHistoryItems = [
  {
    id: "0198a001-0000-7000-8000-000000000093",
    gacha: drawHistoryGacha,
    occurred_at: "2026-08-15T00:00:00Z",
    requested_count: 5,
    executed_count: 2,
    status: { code: "completed", label: "完了" },
  },
  {
    id: "0198a001-0000-7000-8000-000000000092",
    gacha: drawHistoryGacha,
    occurred_at: "2026-08-15T00:00:00Z",
    requested_count: 5,
    executed_count: 5,
    status: { code: "completed", label: "完了" },
  },
  {
    id: "0198a001-0000-7000-8000-000000000091",
    gacha: drawHistoryGacha,
    occurred_at: "2026-08-14T23:59:00Z",
    requested_count: 1,
    executed_count: 1,
    status: { code: "completed", label: "完了" },
  },
] satisfies PublicComponents["schemas"]["DrawHistoryEntry"][];

export const PUBLIC_DRAW_HISTORY_FIXTURES = Object.freeze({
  empty: { items: [], next_cursor: null },
  multiple: { items: drawHistoryItems, next_cursor: null },
  first_page: {
    items: [drawHistoryItems[0]],
    next_cursor: "MDE5OGEwMDEtMDAwMC03MDAwLTgwMDAtMDAwMDAwMDAwMDkz",
  },
  continuation: {
    items: [drawHistoryItems[1], drawHistoryItems[2]],
    next_cursor: null,
  },
} as const satisfies Record<
  string,
  PublicComponents["schemas"]["DrawHistoryCollection"]
>);

export const PUBLIC_DRAW_HISTORY_PROBLEM_FIXTURES = Object.freeze({
  unauthenticated: {
    type: "https://oripa.example/problems/authentication_required",
    title: "Authentication is required.",
    status: 401,
    code: "AUTHENTICATION_REQUIRED",
    request_id: "request-draw-history-auth-001",
    retryable: false,
  },
  invalid_cursor: {
    type: "https://oripa.example/problems/invalid_cursor",
    title: "The cursor is invalid.",
    status: 422,
    code: "INVALID_CURSOR",
    request_id: "request-draw-history-cursor-001",
    retryable: false,
  },
  rate_limited: {
    type: "https://oripa.example/problems/rate_limited",
    title: "Too many requests.",
    status: 429,
    code: "RATE_LIMITED",
    request_id: "request-draw-history-rate-001",
    retryable: true,
    retry_after_seconds: 60,
  },
} as const satisfies Record<
  string,
  PublicComponents["schemas"]["DrawHistoryReadProblemDetails"]
>);

export const PUBLIC_DRAW_FIXTURE = Object.freeze({
  id: "0198a001-0000-7000-8000-000000000099",
  gacha_id: "0198a001-0000-7000-8000-000000000011",
  status: "completed",
  requested_count: 1000,
  executed_count: 1000,
  point_cost_total: 100000,
  point_consumption: {
    paid_points: 0,
    free_points: 100000,
  },
  wallet_after: {
    paid_points: 0,
    free_points: 900000,
    total_points: 900000,
  },
  rank_counts: [
    {
      rank: {
        id: "0198a001-0000-7000-8000-000000000003",
        name: "Sランク",
      },
      count: 100,
    },
  ],
  prize_counts: [
    {
      prize: {
        id: "0198a001-0000-7000-8000-000000000009",
        name: "Fixture S景品",
        presentation_asset: null,
      },
      rank: {
        id: "0198a001-0000-7000-8000-000000000003",
        name: "Sランク",
      },
      count: 100,
    },
  ],
  point_back_total: 90000,
  high_rank_results: [],
  high_rank_results_truncated: true,
  probability_version: {
    id: "0198a001-0000-7000-8000-000000000013",
    version: 1,
  },
  idempotent_replay: false,
  request_id: "0198a001-0000-7000-8000-000000000098",
  processing_duration_ms: 583,
  created_at: "2026-07-27T00:00:00Z",
} as const satisfies PublicComponents["schemas"]["DrawResponse"]);

export const PUBLIC_PARTIAL_REMAINING_DRAW_FIXTURE = Object.freeze({
  presentation: {
    ...PUBLIC_GACHA_PRESENTATION_FIXTURE.data,
    allowed_draw_counts: [1, 100, 1000],
  },
  request: {
    requested_count: 1000,
  },
  response: {
    ...PUBLIC_DRAW_FIXTURE,
    requested_count: 1000,
    executed_count: 900,
    point_cost_total: 90000,
  },
  final_sale_state: "sold_out",
  replay: {
    requested_count: 1000,
    executed_count: 900,
    idempotent_replay: true,
  },
} as const);

export const PUBLIC_DRAW_PROBLEM_FIXTURES = Object.freeze([
  {
    type: "https://oripa.example/problems/insufficient_points",
    title: "Available points are insufficient.",
    status: 409,
    code: "INSUFFICIENT_POINTS",
    request_id: "request-fixture-draw-problem-001",
    retryable: false,
  },
  {
    type: "https://oripa.example/problems/gacha_audience_not_eligible",
    title: "The user is not eligible for this Gacha.",
    status: 403,
    code: "GACHA_AUDIENCE_NOT_ELIGIBLE",
    request_id: "request-fixture-draw-problem-002",
    retryable: false,
  },
  {
    type: "https://oripa.example/problems/daily_draw_limit_exceeded",
    title: "The daily Draw limit would be exceeded.",
    status: 409,
    code: "DAILY_DRAW_LIMIT_EXCEEDED",
    request_id: "request-fixture-draw-problem-003",
    retryable: false,
  },
] as const satisfies readonly PublicComponents["schemas"]["DrawProblemDetails"][]);

export const PUBLIC_FULFILLMENT_PROBLEM_FIXTURES = Object.freeze([
  {
    type: "https://oripa.example/problems/prize_on_payment_hold",
    title: "One or more Prizes are on Payment hold.",
    status: 409,
    code: "PRIZE_ON_PAYMENT_HOLD",
    request_id: "request-fixture-fulfillment-problem-001",
    retryable: false,
  },
  {
    type: "https://oripa.example/problems/idempotency_key_reused",
    title: "The Idempotency Key was used for a different request.",
    status: 409,
    code: "IDEMPOTENCY_KEY_REUSED",
    request_id: "request-fixture-fulfillment-problem-002",
    retryable: false,
  },
  {
    type: "https://oripa.example/problems/prize_not_shippable",
    title: "One or more Prizes cannot be shipped.",
    status: 409,
    code: "PRIZE_NOT_SHIPPABLE",
    request_id: "request-fixture-fulfillment-problem-003",
    retryable: false,
  },
] as const satisfies readonly PublicComponents["schemas"]["FulfillmentProblemDetails"][]);

export const PUBLIC_USER_PRIZE_FIXTURE = Object.freeze({
  id: "0198a001-0000-7000-8000-000000000120",
  presentation: {
    prize_id: "0198a001-0000-7000-8000-000000000009",
    name: "Fixture S景品",
    image: null,
    rank: {
      id: "0198a001-0000-7000-8000-000000000003",
      name: "Sランク",
    },
  },
  status: "stored",
  exchange_points: 8000,
  acquired_at: "2026-07-30T00:00:00Z",
  storage_expires_at: "2026-09-28T00:00:00Z",
  draw_result_id: "0198a001-0000-7000-8000-000000000121",
  allowed_actions: {
    shipping: { allowed: true, unavailable_reason: null },
    point_exchange: { allowed: true, unavailable_reason: null },
    selection: { allowed: true, unavailable_reason: null },
  },
  display: {
    id: "0198a001-0000-7000-8000-000000000009",
    name: "Fixture S景品",
    presentation_asset: null,
  },
  rank: {
    id: "0198a001-0000-7000-8000-000000000003",
    name: "Sランク",
  },
} as const satisfies PublicComponents["schemas"]["UserPrize"]);

export const PUBLIC_SHIPPING_REQUEST_FIXTURE = Object.freeze({
  id: "0198a001-0000-7000-8000-000000000130",
  status: "requested",
  prize_count: 1,
  requested_at: "2026-07-30T00:00:00Z",
  shipped_at: null,
  carrier_code: null,
  idempotent_replay: false,
} as const satisfies PublicComponents["schemas"]["ShippingRequestSummary"]);

export const PUBLIC_CONTENT_FIXTURE = Object.freeze({
  banner: {
    id: "0198a001-0000-7000-8000-000000000201",
    title: "Fixture Banner",
    image_url: "/api/v2/content/assets/0198a001-0000-7000-8000-000000000202",
    link_url: "/gachas",
    asset: {
      id: "0198a001-0000-7000-8000-000000000202",
      path: "/assets/fixture/content/banner.png",
      checksum_sha256:
        "6ff0d7ec10eb8cc7746db1bf8137ef3375bf81177d77e13d99c7fd6ddc28f89a",
      alt_text: "Fixture Banner",
    },
    publish_start_at: "2026-07-28T00:00:00Z",
    publish_end_at: null,
  },
  notice: {
    id: "0198a001-0000-7000-8000-000000000203",
    slug: "fixture-notice",
    title: "Fixture Notice",
    summary: "Public-safe fixture summary.",
    is_important: false,
    asset: null,
    publish_start_at: "2026-07-28T00:00:00Z",
    publish_end_at: null,
    body_html: "<p>Public-safe fixture notice.</p>",
    checksum_sha256:
      "9be8d4ecf0cab8a507116713604b0f47326d346a9386a1685b963086d18c406e",
  },
} as const satisfies {
  banner: PublicComponents["schemas"]["ContentBanner"];
  notice: PublicComponents["schemas"]["ContentNotice"];
});

export const PUBLIC_IDENTITY_RECOVERY_FIXTURE = Object.freeze({
  password_reset: {
    status: "accepted",
    message: "If the account is eligible, password reset instructions will be sent.",
  },
  sms_status: {
    verified: false,
    phone_masked: "+819****5678",
    challenge: {
      id: "0198a001-0000-7000-8000-000000000302",
      status: "pending",
      expires_at: "2026-07-28T10:05:00Z",
    },
  },
} as const satisfies {
  password_reset: PublicComponents["schemas"]["PasswordResetAccepted"];
  sms_status: PublicComponents["schemas"]["SmsVerificationStatus"];
});

export const PUBLIC_ACCOUNT_SECURITY_FIXTURE = Object.freeze({
  password_reset_completed: {
    status: "password_updated",
    authenticated: false,
    user: null,
    next_action: "login",
    redirect_path: "/",
  },
  email_change_pending: {
    status: "pending_verification",
    request_id: "0198a001-0000-7000-8000-000000000601",
    expires_at: "2026-08-30T11:00:00Z",
  },
  email_change_completed_same_browser: {
    status: "completed",
    authenticated: true,
    session_rotated: true,
    initiating_session_preserved: true,
    next_action: "return_to_account",
  },
  email_change_completed_cross_browser: {
    status: "completed",
    authenticated: false,
    session_rotated: false,
    initiating_session_preserved: true,
    next_action: "return_to_account",
  },
  password_changed: {
    status: "password_updated",
    authenticated: true,
    session_rotated: true,
    next_action: "return_to_account",
  },
} as const satisfies {
  password_reset_completed: PublicComponents["schemas"]["PasswordResetCompleted"];
  email_change_pending: PublicComponents["schemas"]["EmailChangePending"];
  email_change_completed_same_browser: PublicComponents["schemas"]["EmailChangeCompleted"];
  email_change_completed_cross_browser: PublicComponents["schemas"]["EmailChangeCompleted"];
  password_changed: PublicComponents["schemas"]["UserPasswordChanged"];
});

export const PUBLIC_ACCOUNT_SECURITY_PROBLEM_FIXTURES = Object.freeze({
  invalid_password_reset: {
    type: "https://oripa.example/problems/invalid_password_reset",
    title: "The password reset request is invalid or expired.",
    status: 410,
    code: "INVALID_PASSWORD_RESET",
    request_id: "request-account-security-001",
    retryable: false,
  },
  password_policy: {
    type: "https://oripa.example/problems/password_policy_violation",
    title: "The credential does not satisfy the security policy.",
    status: 422,
    code: "PASSWORD_POLICY_VIOLATION",
    request_id: "request-account-security-002",
    retryable: false,
  },
  email_unchanged: {
    type: "https://oripa.example/problems/email_unchanged",
    title: "The new email address must differ from the current email address.",
    status: 422,
    code: "EMAIL_UNCHANGED",
    request_id: "request-account-security-003",
    retryable: false,
  },
  email_claimed: {
    type: "https://oripa.example/problems/email_already_claimed",
    title: "The email address is already verified by another account.",
    status: 409,
    code: "EMAIL_ALREADY_CLAIMED",
    request_id: "request-account-security-004",
    retryable: false,
  },
  invalid_email_change: {
    type: "https://oripa.example/problems/invalid_email_change_request",
    title: "The email change request is invalid or expired.",
    status: 410,
    code: "INVALID_EMAIL_CHANGE_REQUEST",
    request_id: "request-account-security-005",
    retryable: false,
  },
  password_unchanged: {
    type: "https://oripa.example/problems/password_unchanged",
    title: "The new password must differ from the current password.",
    status: 422,
    code: "PASSWORD_UNCHANGED",
    request_id: "request-account-security-006",
    retryable: false,
  },
  wrong_current_password: {
    type: "https://oripa.example/problems/invalid_reauthentication",
    title: "The current password could not be verified.",
    status: 401,
    code: "INVALID_REAUTHENTICATION",
    request_id: "request-account-security-007",
    retryable: false,
  },
} as const satisfies Record<
  string,
  PublicComponents["schemas"]["PublicAuthProblemDetails"]
>);

export const PUBLIC_AUTH_FIXTURE = Object.freeze({
  anonymous_session: {
    authenticated: false,
    user: null,
  },
  authenticated_session: {
    authenticated: true,
    user: {
      id: "0198a001-0000-7000-8000-000000000501",
      state: "active",
      email_verified: true,
    },
  },
  pending_registration: {
    status: "pending_verification",
    user_id: "0198a001-0000-7000-8000-000000000502",
  },
  accepted: {
    status: "accepted",
  },
} as const satisfies {
  anonymous_session: PublicComponents["schemas"]["UserSession"];
  authenticated_session: PublicComponents["schemas"]["UserSession"];
  pending_registration: PublicComponents["schemas"]["PendingRegistration"];
  accepted: PublicComponents["schemas"]["Accepted"];
});

export const PUBLIC_EXTERNAL_IDENTITY_FIXTURE = Object.freeze({
  start: {
    provider: "google",
    purpose: "link",
    authorization_url:
      "https://accounts.google.com/o/oauth2/v2/auth?client_id=fixture",
    expires_at: "2026-07-28T10:10:00Z",
  },
  line_start: {
    provider: "line",
    authorization_url:
      "https://access.line.me/oauth2/v2.1/authorize?client_id=fixture",
    expires_at: "2026-07-28T10:10:00Z",
  },
  linked: {
    items: [
      {
        id: "0198a001-0000-7000-8000-000000000401",
        provider: "google",
        linked_at: "2026-07-28T10:00:00Z",
        last_authenticated_at: "2026-07-28T10:00:00Z",
      },
      {
        id: "0198a001-0000-7000-8000-000000000403",
        provider: "line",
        linked_at: "2026-07-28T10:01:00Z",
        last_authenticated_at: "2026-07-28T10:02:00Z",
      },
    ],
  },
  session: {
    authenticated: true,
    purpose: "login",
    provider: "google",
    return_path: "/",
    user: {
      id: "0198a001-0000-7000-8000-000000000402",
      state: "active",
      email_verified: true,
    },
  },
} as const satisfies {
  start: PublicComponents["schemas"]["ExternalIdentityStart"];
  line_start: PublicComponents["schemas"]["ExternalIdentityStart"];
  linked: PublicComponents["schemas"]["ExternalIdentityCollection"];
  session: PublicComponents["schemas"]["ExternalIdentitySession"];
});

export const PUBLIC_LINE_FRIEND_STATE_FIXTURES = Object.freeze({
  unlinked: {
    linked: false,
    friend_confirmed: false,
    is_line_user: false,
    status: { code: "not_linked", label: "LINE未連携" },
    primary_action: {
      code: "start_identity_link",
      label: "LINEを連携する",
      href: null,
    },
  },
  friend_add_required: {
    linked: true,
    friend_confirmed: false,
    is_line_user: false,
    status: { code: "friend_add_required", label: "友だち追加未確認" },
    primary_action: {
      code: "open_friend_add_url",
      label: "LINE公式アカウントを友だち追加する",
      href: "https://line.me/R/ti/p/synthetic",
    },
  },
  confirmed: {
    linked: true,
    friend_confirmed: true,
    is_line_user: true,
    status: { code: "confirmed", label: "LINEユーザー" },
    primary_action: { code: "none", label: null, href: null },
  },
} as const satisfies Record<
  string,
  PublicComponents["schemas"]["LineFriendStatePresentation"]
>);

export const PUBLIC_LINE_FRIEND_STATE_PROBLEM_FIXTURES = Object.freeze({
  unauthenticated: {
    type: "https://oripa.example/problems/authentication_required",
    title: "Authentication is required.",
    status: 401,
    code: "AUTHENTICATION_REQUIRED",
    request_id: "request-line-friend-state-auth-001",
    retryable: false,
  },
  session_expired: {
    type: "https://oripa.example/problems/session_expired",
    title: "The session has expired.",
    status: 401,
    code: "SESSION_EXPIRED",
    request_id: "request-line-friend-state-session-001",
    retryable: false,
  },
  rate_limited: {
    type: "https://oripa.example/problems/rate_limited",
    title: "Too many requests.",
    status: 429,
    code: "RATE_LIMITED",
    request_id: "request-line-friend-state-rate-001",
    retryable: true,
    retry_after_seconds: 60,
  },
} as const satisfies Record<
  string,
  PublicComponents["schemas"]["LineFriendStateReadProblemDetails"]
>);
