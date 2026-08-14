import type {
  PlatformRuntimeCompatibility,
  SiteManifest,
} from "@oripa/site-schema";
import type { StorefrontResponseMetadata } from "@oripa/storefront-client";
import type { PublicComponents } from "@oripa/storefront-client";

export {
  PUBLIC_CONTRACT_FIXTURE,
} from "./generated/public-contract.js";

export const MINIMAL_SITE_MANIFEST_FIXTURE = Object.freeze(
  {
    schema_version: "2.0.0-alpha.1",
    site_version: "1.0.0-alpha.1",
    compatibility: {
      family: 2,
      storefront_client_version: "2.0.0-alpha.13",
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
        "prize.fulfillment-browser-mutation.v2",
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
          "prize.fulfillment-browser-mutation.v2",
          "user-prize.presentation.v2",
        ],
      },
    },
  } as const satisfies SiteManifest,
);

export const PLATFORM_COMPATIBILITY_FIXTURE = Object.freeze({
  compatibility_family: 2,
  minimum_storefront_client_version: "2.0.0-alpha.13",
  capabilities: [
    "auth.session.v2",
    "draw.browser-mutation.v2",
    "gacha.catalog-display.v2",
    "gacha.presentation.v2",
    "prize.fulfillment-browser-mutation.v2",
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
        id: "0198a001-0000-7000-8000-000000000003",
        code: "S",
        name: "Sランク",
        presentation_assets: [],
        prizes: [
          {
            id: "0198a001-0000-7000-8000-000000000009",
            name: "Fixture S景品",
            description: "公開安全なFixture景品。",
            display_price: 10000,
            exchange_points: 8000,
            presentation_asset: null,
          },
        ],
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
              code: "S",
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
            code: "A",
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
        code: "S",
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
        code: "S",
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
      code: "S",
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
    code: "S",
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
