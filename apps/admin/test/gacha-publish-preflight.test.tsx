import {
  fireEvent,
  render,
  screen,
  waitFor,
  within,
} from "@testing-library/react";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

const permissionState = vi.hoisted(() => ({ canPublish: true }));

vi.mock("@/components/permissions/permission-provider", () => ({
  usePermissions: () => ({
    hasPermission: (permission: string) =>
      permission === "catalog.publish" && permissionState.canPublish,
  }),
}));
vi.mock("@/components/auth/fresh-mfa-dialog", () => ({
  FreshMfaDialog: ({ open }: { open: boolean }) =>
    open ? <div role="dialog">Fresh MFA</div> : null,
}));

import { GachaPublishPreflightPanel } from "@/components/catalog/gacha-publish-preflight-panel";
import { AdminApiClient } from "@/lib/admin-api/client";
import type {
  AdminCatalogGachaVersion,
  AdminGachaPublishedProbabilityCandidate,
} from "@/lib/admin-api/generated";

const GACHA_ID = "01910191-0191-7191-8191-019101910191";
const VERSION_ID = "01910191-0191-7191-8191-019101910192";
const PROBABILITY_ID = "01910191-0191-7191-8191-019101910193";

const candidate: AdminGachaPublishedProbabilityCandidate = {
  id: PROBABILITY_ID,
  published_at: "2026-08-11T00:00:00Z",
  snapshot_sha256: "a".repeat(64),
  stage_count: 2,
  validation_status: "valid",
  version_number: 3,
};

describe("Gacha Publish Preflight", () => {
  beforeEach(() => {
    permissionState.canPublish = true;
  });
  afterEach(() => vi.restoreAllMocks());

  it("selects a Published Probability through confirmation and returns canonical data", async () => {
    mockReads(null);
    const canonical = version({
      published_probability_version: {
        id: PROBABILITY_ID,
        status: "published",
        version_number: 3,
      },
      revision: 3,
    });
    const select = vi
      .spyOn(AdminApiClient.prototype, "selectGachaPublishedProbability")
      .mockResolvedValue({
        data: canonical,
        idempotent_replay: false,
      });
    const onCanonical = vi.fn();

    render(
      <GachaPublishPreflightPanel
        gachaId={GACHA_ID}
        onCanonical={onCanonical}
        version={version()}
      />,
    );
    await screen.findByRole("option", { name: /v3.*2 stages/u });
    fireEvent.change(screen.getByLabelText("Published Probability"), {
      target: { value: PROBABILITY_ID },
    });
    fireEvent.click(screen.getByRole("button", { name: "選択を確定" }));
    expect(
      screen.getByRole("alertdialog", {
        name: "Probability選択を変更しますか",
      }),
    ).toBeVisible();
    fireEvent.click(
      screen.getAllByRole("button", { name: "選択を確定" }).at(-1)!,
    );

    await waitFor(() => expect(select).toHaveBeenCalledOnce());
    expect(select.mock.calls[0][2]).toEqual({
      expected_revision: 2,
      probability_version_id: PROBABILITY_ID,
    });
    expect(select.mock.calls[0][3]).toMatch(/^[0-9a-f-]{36}$/u);
    expect(onCanonical).toHaveBeenCalledWith(canonical);
  });

  it("renders canonical server blockers and keeps publish unavailable", async () => {
    mockReads(candidate);
    vi.spyOn(
      AdminApiClient.prototype,
      "preflightGachaVersionPublish",
    ).mockResolvedValue({
      data: {
        blocking_reasons: [
          {
            code: "GACHA_PRESENTATION_ASSET_REQUIRED",
            message: "A public presentation asset is required.",
          },
        ],
        gacha_version_id: VERSION_ID,
        gacha_version_revision: 2,
        publishable: false,
        request_id: GACHA_ID,
        selected_probability: {
          id: PROBABILITY_ID,
          snapshot_sha256: "a".repeat(64),
        },
        validation_codes: ["GACHA_PRESENTATION_ASSET_REQUIRED"],
      },
      idempotent_replay: false,
    });

    render(
      <GachaPublishPreflightPanel
        gachaId={GACHA_ID}
        onCanonical={vi.fn()}
        version={version({
          published_probability_version: {
            id: PROBABILITY_ID,
            status: "published",
            version_number: 3,
          },
        })}
      />,
    );
    await screen.findByText("aaaaaaaaaaaa");
    fireEvent.click(
      screen.getByRole("button", { name: "Publish Preflight" }),
    );
    expect(
      await screen.findByText("GACHA_PRESENTATION_ASSET_REQUIRED"),
    ).toBeVisible();
    expect(screen.getByText("Schedule／Unpublishは未実装")).toBeVisible();
    expect(screen.queryByRole("button", { name: "Publish Now" })).not.toBeInTheDocument();
    expect(screen.queryByRole("button", { name: "Schedule" })).not.toBeInTheDocument();
  });

  it("publishes after server preflight and refreshes canonical state", async () => {
    mockReads(candidate);
    vi.spyOn(
      AdminApiClient.prototype,
      "preflightGachaVersionPublish",
    ).mockResolvedValue({
      data: {
        blocking_reasons: [],
        gacha_version_id: VERSION_ID,
        gacha_version_revision: 2,
        publishable: true,
        request_id: GACHA_ID,
        selected_probability: {
          id: PROBABILITY_ID,
          snapshot_sha256: "a".repeat(64),
        },
        validation_codes: ["GACHA_PUBLISH_PREFLIGHT_READY"],
      },
      idempotent_replay: false,
    });
    const publish = vi
      .spyOn(AdminApiClient.prototype, "publishGachaVersionImmediately")
      .mockResolvedValue({
        data: {
          current_published_version: { id: VERSION_ID, version_number: 2 },
          draw_state: { sold_count: 0, status: "selling", total_count: 1_000 },
          gacha_revision: 4,
          gacha_version_id: VERSION_ID,
          gacha_version_revision: 3,
          previous_published_version: {
            id: GACHA_ID,
            version_number: 1,
          },
          published_at: "2026-08-12T00:00:00Z",
          request_id: GACHA_ID,
          selected_probability: {
            id: PROBABILITY_ID,
            snapshot_sha256: "a".repeat(64),
          },
          status: "published",
        },
        idempotent_replay: false,
      });
    const canonical = version({
      published_at: "2026-08-12T00:00:00Z",
      status: "published",
    });
    vi.spyOn(AdminApiClient.prototype, "getCatalogGachaVersion")
      .mockResolvedValue({ data: canonical });
    const onCanonical = vi.fn();

    render(
      <GachaPublishPreflightPanel
        gachaId={GACHA_ID}
        onCanonical={onCanonical}
        version={version({
          published_probability_version: {
            id: PROBABILITY_ID,
            status: "published",
            version_number: 3,
          },
        })}
      />,
    );
    await screen.findByText("aaaaaaaaaaaa");
    fireEvent.click(screen.getByRole("button", { name: "Publish Preflight" }));
    await screen.findByText("Server Preflight完了");
    fireEvent.click(screen.getByRole("button", { name: "Publish Now" }));
    expect(
      screen.getByRole("alertdialog", {
        name: "このVersionへ即時切り替えますか",
      }),
    ).toBeVisible();
    fireEvent.click(
      within(screen.getByRole("alertdialog")).getByRole("button", {
        name: "Publish Now",
      }),
    );

    await waitFor(() => expect(publish).toHaveBeenCalledOnce());
    expect(publish.mock.calls[0][2]).toEqual({
      expected_gacha_revision: 3,
      expected_revision: 2,
    });
    expect(onCanonical).toHaveBeenCalledWith(canonical);
  });

  it("keeps Operator access read-only", async () => {
    permissionState.canPublish = false;
    mockReads(candidate);

    render(
      <GachaPublishPreflightPanel
        gachaId={GACHA_ID}
        onCanonical={vi.fn()}
        version={version()}
      />,
    );
    expect(
      await screen.findByText(/catalog\.publish.*参照専用/u),
    ).toBeVisible();
    expect(
      screen.queryByRole("button", { name: "選択を確定" }),
    ).not.toBeInTheDocument();
    expect(
      screen.queryByRole("button", { name: "Publish Preflight" }),
    ).not.toBeInTheDocument();
  });
});

function mockReads(
  selected: AdminGachaPublishedProbabilityCandidate | null,
): void {
  vi.spyOn(
    AdminApiClient.prototype,
    "listGachaPublishedProbabilityCandidates",
  ).mockResolvedValue({ items: [candidate], next_cursor: null });
  vi.spyOn(
    AdminApiClient.prototype,
    "getGachaProbabilitySelection",
  ).mockResolvedValue({
    data: {
      gacha_version_id: VERSION_ID,
      gacha_version_revision: 2,
      selected_probability: selected,
    },
  });
  vi.spyOn(AdminApiClient.prototype, "getGachaPublishState").mockResolvedValue({
    data: {
      current_published_version: {
        id: GACHA_ID,
        published_at: "2026-08-11T00:00:00Z",
        status: "published",
        version_number: 1,
      },
      draw_state: {
        sold_count: 0,
        status: "selling",
        total_count: 1_000,
      },
      gacha_id: GACHA_ID,
      gacha_revision: 3,
      selected_probability: {
        id: PROBABILITY_ID,
        snapshot_sha256: "a".repeat(64),
      },
    },
  });
}

function version(
  overrides: Partial<AdminCatalogGachaVersion> = {},
): AdminCatalogGachaVersion {
  return {
    archived_at: null,
    cloned_from_version: null,
    created_at: "2026-08-11T00:00:00Z",
    description: null,
    id: VERSION_ID,
    is_archived: false,
    notices: null,
    presentation_asset: null,
    price_points: 100,
    prizes: [],
    publish_end_at: "2027-08-11T00:00:00Z",
    publish_start_at: "2026-08-11T00:00:00Z",
    published_at: null,
    published_probability_version: null,
    revision: 2,
    status: "draft",
    title: "Draft Gacha",
    total_count: 1_000,
    updated_at: "2026-08-11T00:00:00Z",
    version_number: 2,
    ...overrides,
  };
}
