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
      storefront_client_version: "2.0.0-alpha.1",
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
      required_capabilities: ["auth.session.v2"],
    },
    public: {
      ...MINIMAL_SITE_MANIFEST_FIXTURE.public,
      features: {
        enabled: ["auth.session.v2"],
      },
    },
  } as const satisfies SiteManifest,
);

export const PLATFORM_COMPATIBILITY_FIXTURE = Object.freeze({
  compatibility_family: 2,
  minimum_storefront_client_version: "2.0.0-alpha.1",
  capabilities: ["auth.session.v2"],
}) satisfies PlatformRuntimeCompatibility;

export const PUBLIC_RESPONSE_METADATA_FIXTURE = Object.freeze({
  status: 200,
  request_id: "request-fixture-001",
  api_version: "2",
  idempotency_replayed: false,
}) satisfies StorefrontResponseMetadata;

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
      path: "/assets/fixture/catalog/gacha-main.txt",
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
  },
} as const satisfies PublicComponents["schemas"]["GachaDetailResponse"]);

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

export const PUBLIC_USER_PRIZE_FIXTURE = Object.freeze({
  id: "0198a001-0000-7000-8000-000000000120",
  status: "stored",
  exchange_points: 8000,
  acquired_at: "2026-07-30T00:00:00Z",
  storage_expires_at: "2026-09-28T00:00:00Z",
  draw_result_id: "0198a001-0000-7000-8000-000000000121",
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
