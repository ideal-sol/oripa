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
import { AdminApiClient, AdminApiError } from "@/lib/admin-api/client";
import type {
  AdminCatalogGachaVersion,
  AdminGachaPublishSchedule,
  AdminGachaPublishedProbabilityCandidate,
  AdminGachaSalesState,
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
const scheduled: AdminGachaPublishSchedule = {
  attempts: 0,
  cancelled_at: null,
  completed_at: null,
  display_timezone: "Asia/Tokyo",
  failed_at: null,
  failure_code: null,
  gacha_revision: 4,
  gacha_version_id: VERSION_ID,
  gacha_version_revision: 3,
  id: "01910191-0191-7191-8191-019101910194",
  next_attempt_at: "2026-12-01T01:00:00Z",
  request_id: "01910191-0191-7191-8191-019101910195",
  revision: 1,
  scheduled_for: "2026-12-01T01:00:00Z",
  selected_probability: {
    id: PROBABILITY_ID,
    snapshot_sha256: "a".repeat(64),
  },
  server_timezone: "UTC",
  started_at: null,
  status: "scheduled",
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
    expect(screen.getByText("Unpublishは未実装")).toBeVisible();
    expect(screen.queryByRole("button", { name: "Publish Now" })).not.toBeInTheDocument();
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

  it("schedules and cancels Publish through server preflight", async () => {
    mockReads(candidate);
    vi.spyOn(
      AdminApiClient.prototype,
      "getGachaPublishSchedule",
    )
      .mockResolvedValueOnce({ data: null })
      .mockResolvedValue({ data: scheduled });
    const preflight = vi
      .spyOn(
        AdminApiClient.prototype,
        "preflightGachaVersionPublishSchedule",
      )
      .mockResolvedValue({
        data: {
          blocking_reasons: [],
          display_timezone: "Asia/Tokyo",
          gacha_version_id: VERSION_ID,
          gacha_version_revision: 2,
          publishable: true,
          request_id: GACHA_ID,
          scheduled_for: scheduled.scheduled_for,
          selected_probability: scheduled.selected_probability,
          server_timezone: "UTC",
          validation_codes: ["GACHA_SCHEDULE_PREFLIGHT_READY"],
        },
        idempotent_replay: false,
      });
    const create = vi
      .spyOn(AdminApiClient.prototype, "scheduleGachaVersionPublish")
      .mockResolvedValue({ data: scheduled, idempotent_replay: false });
    const cancelled = {
      ...scheduled,
      cancelled_at: "2026-08-13T00:01:00Z",
      gacha_revision: 5,
      gacha_version_revision: 4,
      revision: 2,
      status: "cancelled" as const,
    };
    const cancel = vi
      .spyOn(AdminApiClient.prototype, "cancelGachaVersionPublishSchedule")
      .mockResolvedValue({ data: cancelled, idempotent_replay: false });
    vi.spyOn(AdminApiClient.prototype, "getCatalogGachaVersion")
      .mockResolvedValue({ data: version({ revision: 3 }) });

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
    fireEvent.change(screen.getByLabelText(/Schedule Publish/u), {
      target: { value: "2026-12-01T10:00" },
    });
    fireEvent.click(
      screen.getByRole("button", { name: "Schedule Preflight" }),
    );
    await screen.findByText("Schedule Preflight完了");
    expect(preflight).toHaveBeenCalledOnce();
    fireEvent.click(screen.getByRole("button", { name: "Publishを予約" }));
    expect(
      screen.getByRole("alertdialog", {
        name: "このVersionのPublishを予約しますか",
      }),
    ).toBeVisible();
    fireEvent.click(
      within(screen.getByRole("alertdialog")).getByRole("button", {
        name: "Publishを予約",
      }),
    );
    await waitFor(() => expect(create).toHaveBeenCalledOnce());
    expect(await screen.findByText("scheduled")).toBeVisible();
    fireEvent.click(screen.getByRole("button", { name: "予約を取消" }));
    fireEvent.click(
      within(screen.getByRole("alertdialog")).getByRole("button", {
        name: "予約を取消",
      }),
    );
    await waitFor(() => expect(cancel).toHaveBeenCalledOnce());
    expect(cancel.mock.calls[0][3]).toEqual({
      expected_gacha_revision: 4,
      expected_schedule_revision: 1,
      expected_version_revision: 3,
    });
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
    expect(
      screen.queryByRole("button", { name: "Schedule Preflight" }),
    ).not.toBeInTheDocument();
    expect(
      screen.queryByRole("button", { name: "Pause Preflight" }),
    ).not.toBeInTheDocument();
  });

  it("pauses sales after server preflight and confirmation", async () => {
    mockReads(candidate);
    const preflight = vi
      .spyOn(AdminApiClient.prototype, "preflightGachaSalesPause")
      .mockResolvedValue({
        data: {
          allowed: true,
          blocking_reasons: [],
          operation: "pause",
          request_id: GACHA_ID,
          sales_state: salesState(),
          validation_codes: ["GACHA_SALES_PAUSE_READY"],
        },
        idempotent_replay: false,
      });
    const paused = salesState({
      gacha_revision: 4,
      paused_at: "2026-08-14T00:00:00Z",
      reason_code: "inventory_review",
      status: "paused",
    });
    const pause = vi
      .spyOn(AdminApiClient.prototype, "pauseGachaSales")
      .mockResolvedValue({ data: paused, idempotent_replay: false });

    render(
      <GachaPublishPreflightPanel
        gachaId={GACHA_ID}
        onCanonical={vi.fn()}
        version={version()}
      />,
    );
    await screen.findByText("Sales: 販売中");
    fireEvent.change(screen.getByLabelText("Pause理由"), {
      target: { value: "inventory_review" },
    });
    fireEvent.click(screen.getByRole("button", { name: "Pause Preflight" }));
    await screen.findByText("Pause可能");
    expect(preflight.mock.calls[0][1]).toEqual({
      expected_gacha_revision: 3,
      reason_code: "inventory_review",
    });
    fireEvent.click(screen.getByRole("button", { name: "最終確認" }));
    const dialog = screen.getByRole("alertdialog", {
      name: "新規販売・抽選を一時停止しますか",
    });
    fireEvent.click(within(dialog).getByRole("button", { name: "Pause" }));
    await waitFor(() => expect(pause).toHaveBeenCalledOnce());
    expect(pause.mock.calls[0][1]).toEqual({
      expected_gacha_revision: 3,
      reason_code: "inventory_review",
    });
  });

  it("shows resume blockers and requests Fresh MFA without client-side bypass", async () => {
    mockReads(candidate, salesState({
      paused_at: "2026-08-14T00:00:00Z",
      reason_code: "operations_review",
      status: "paused",
    }));
    vi.spyOn(
      AdminApiClient.prototype,
      "preflightGachaSalesResume",
    ).mockRejectedValue(
      new AdminApiError(
        403,
        "FRESH_AUTHENTICATION_REQUIRED",
        null,
        null,
        false,
      ),
    );

    render(
      <GachaPublishPreflightPanel
        gachaId={GACHA_ID}
        onCanonical={vi.fn()}
        version={version()}
      />,
    );
    await screen.findByText("Sales: 一時停止中");
    fireEvent.click(screen.getByRole("button", { name: "Resume Preflight" }));
    expect(await screen.findByText("Fresh MFA")).toBeVisible();
  });
});

function mockReads(
  selected: AdminGachaPublishedProbabilityCandidate | null,
  currentSalesState: AdminGachaSalesState = salesState(),
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
  vi.spyOn(
    AdminApiClient.prototype,
    "getGachaPublishSchedule",
  ).mockResolvedValue({ data: null });
  vi.spyOn(AdminApiClient.prototype, "getGachaSalesState").mockResolvedValue({
    data: currentSalesState,
  });
}

function salesState(
  overrides: Partial<AdminGachaSalesState> = {},
): AdminGachaSalesState {
  return {
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
    paused_at: null,
    publish_schedule: null,
    reason_code: null,
    request_id: GACHA_ID,
    resumed_at: null,
    selected_probability: {
      id: PROBABILITY_ID,
      snapshot_sha256: "a".repeat(64),
    },
    status: "selling",
    ...overrides,
  };
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
