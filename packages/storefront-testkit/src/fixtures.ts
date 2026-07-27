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
