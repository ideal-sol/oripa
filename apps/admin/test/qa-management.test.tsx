import { fireEvent, render, screen, waitFor } from "@testing-library/react";
import { afterEach, describe, expect, it, vi } from "vitest";

vi.mock("@/components/shell/admin-shell", () => ({
  AdminShell: ({ children }: { children: React.ReactNode }) => <>{children}</>,
}));
vi.mock("@/components/permissions/protected-admin-route", () => ({
  ProtectedAdminRoute: ({ children }: { children: React.ReactNode }) => <>{children}</>,
}));
vi.mock("@/components/navigation/breadcrumb", () => ({
  Breadcrumb: () => <nav aria-label="パンくず" />,
}));
vi.mock("@/components/shell/admin-page-header", () => ({
  AdminPageHeader: ({ title }: { title: string }) => <h1>{title}</h1>,
}));
vi.mock("@/components/auth/fresh-mfa-dialog", () => ({
  FreshMfaDialog: ({ open }: { open: boolean }) =>
    open ? <div role="dialog">Fresh MFA</div> : null,
}));
vi.mock("@/components/auth/admin-auth-provider", () => ({
  useAdminAuth: () => ({ expireSession: vi.fn() }),
}));

import { QaManagementWorkspace } from "@/components/qa/qa-management-workspace";
import { AdminApiClient, AdminApiError } from "@/lib/admin-api/client";
import type {
  AdminQaExecutionDetail,
  AdminQaPlanDetail,
  AdminQaPlanSummary,
  AdminQaTestUser,
} from "@/lib/admin-api/generated";

const PLAN_ID = "01910191-0191-7191-8191-019101910191";
const USER_ID = "01910191-0191-7191-8191-019101910192";
const GACHA_ID = "01910191-0191-7191-8191-019101910193";
const PRIZE_ID = "01910191-0191-7191-8191-019101910194";
const EXECUTION_ID = "01910191-0191-7191-8191-019101910190";

const summary: AdminQaPlanSummary = {
  archived_at: null,
  code: "QA-PLAN001",
  ends_at: "2026-08-01T02:00:00Z",
  gacha_id: GACHA_ID,
  id: PLAN_ID,
  revision: 1,
  starts_at: "2026-08-01T00:00:00Z",
  status: "active",
  title: "1000回QA",
  user_id: USER_ID,
};
const detail: AdminQaPlanDetail = {
  ...summary,
  assignments: [
    {
      assigned_at: "2026-08-01T00:00:00Z",
      id: "01910191-0191-7191-8191-019101910195",
      revision: 1,
      status: "assigned",
      unassigned_at: null,
      user_id: USER_ID,
    },
  ],
  execution_count: 0,
  items: [
    {
      consumed_count: 0,
      fixed_image_asset_id: null,
      fixed_video_asset_id: null,
      id: "01910191-0191-7191-8191-019101910196",
      prize_id: PRIZE_ID,
      quantity: 1000,
      sort_order: 1,
    },
  ],
  reason: "Release verification",
};
const user: AdminQaTestUser = {
  ends_at: "2026-08-01T02:00:00Z",
  is_active: true,
  is_enabled: true,
  mode_id: "01910191-0191-7191-8191-019101910197",
  revision: 1,
  starts_at: null,
  user_id: USER_ID,
  user_state: "active",
};

describe("QA Plan management", () => {
  afterEach(() => vi.restoreAllMocks());

  it("lists, reviews, and preflights a QA Plan with an explicit real-impact execution boundary", async () => {
    mockPlanReads();
    vi.spyOn(AdminApiClient.prototype, "preflightQaPlan").mockResolvedValue({
      assigned_test_user_count: 1,
      gacha_version_id: "01910191-0191-7191-8191-019101910198",
      plan_id: PLAN_ID,
      probability_version_id: "01910191-0191-7191-8191-019101910199",
      remaining_draw_count: 1000,
      revision: 1,
      valid: true,
      validation_codes: [],
    });

    render(<QaManagementWorkspace />);
    await screen.findByText("1000回QA");
    fireEvent.click(screen.getByRole("button", { name: "1000回QAの詳細" }));
    await screen.findByText("Release verification");
    fireEvent.click(screen.getByRole("button", { name: "検証" }));

    expect(await screen.findByText("実行設定は有効です")).toBeVisible();
    expect(screen.getByText("Test User 1人 / 残り 1000回")).toBeVisible();
    expect(screen.getByText(/Mockではありません/u)).toBeVisible();
    expect(screen.getByRole("button", { name: "QA Drawを実行" })).toBeDisabled();
  });

  it("preflights, confirms, and reviews a canonical QA Draw result", async () => {
    mockPlanReads();
    vi.spyOn(AdminApiClient.prototype, "preflightQaExecution").mockResolvedValue({
      assignment_id: detail.assignments[0].id,
      assignment_revision: 1,
      available_points: 100000,
      draw_count: 10,
      gacha_id: GACHA_ID,
      gacha_version_id: "01910191-0191-7191-8191-019101910198",
      plan_id: PLAN_ID,
      plan_revision: 1,
      probability_version_id: "01910191-0191-7191-8191-019101910199",
      remaining_plan_count: 1000,
      remaining_sales_count: 5000,
      required_points: 1000,
      user_id: USER_ID,
      valid: true,
      validation_codes: [],
    });
    const execution = executionDetail();
    const execute = vi.spyOn(AdminApiClient.prototype, "executeQaDraw")
      .mockResolvedValue({ data: execution, idempotent_replay: false });
    vi.spyOn(window, "confirm").mockReturnValue(true);

    render(<QaManagementWorkspace />);
    await screen.findByText("1000回QA");
    fireEvent.click(screen.getByRole("button", { name: "1000回QAの詳細" }));
    await screen.findByRole("heading", { name: "QA Draw実行" });
    fireEvent.change(screen.getByLabelText("実行回数"), { target: { value: "10" } });
    fireEvent.click(screen.getByRole("button", { name: "実行前検証" }));
    expect(await screen.findByText("実行可能です")).toBeVisible();
    fireEvent.click(screen.getByRole("button", { name: "QA Drawを実行" }));

    await waitFor(() => expect(execute).toHaveBeenCalledOnce());
    expect(await screen.findByText("販売口数差分")).toBeVisible();
    expect(screen.getByText("Fixture Prize")).toBeVisible();
  });

  it("creates a Plan with typed Public IDs and a single bulk item request", async () => {
    mockPlanReads([]);
    const create = vi
      .spyOn(AdminApiClient.prototype, "createQaPlan")
      .mockResolvedValue({ data: detail, idempotent_replay: false });

    render(<QaManagementWorkspace />);
    await screen.findByText("条件に一致するQA Planはありません。");
    fireEvent.click(screen.getByRole("button", { name: "新規Plan" }));
    fireEvent.change(screen.getByLabelText("Test User Public ID"), {
      target: { value: USER_ID },
    });
    fireEvent.change(screen.getByLabelText("Gacha Public ID"), {
      target: { value: GACHA_ID },
    });
    fireEvent.change(screen.getByLabelText("Title"), {
      target: { value: "1000回QA" },
    });
    fireEvent.change(screen.getByLabelText("Reason"), {
      target: { value: "Release verification" },
    });
    fireEvent.change(screen.getByLabelText("Prize Public ID 1"), {
      target: { value: PRIZE_ID },
    });
    fireEvent.change(screen.getByLabelText("Quantity"), {
      target: { value: "1000" },
    });
    fireEvent.click(screen.getByRole("button", { name: "保存" }));

    await waitFor(() => expect(create).toHaveBeenCalledOnce());
    expect(create.mock.calls[0][0]).toMatchObject({
      gacha_id: GACHA_ID,
      items: [{ prize_id: PRIZE_ID, quantity: 1000, sort_order: 1 }],
      title: "1000回QA",
      user_id: USER_ID,
    });
    expect(create.mock.calls[0][1]).toMatch(/^[0-9a-f-]{36}$/u);
  });

  it("searches and configures a Test User through the shared Fresh MFA boundary", async () => {
    mockPlanReads([]);
    vi.spyOn(AdminApiClient.prototype, "listQaTestUsers").mockResolvedValue({
      items: [],
      next_cursor: null,
    });
    vi.spyOn(AdminApiClient.prototype, "searchQaTestUserCandidates").mockResolvedValue({
      items: [{ ...user, is_active: false, is_enabled: false, mode_id: null, revision: null }],
      next_cursor: null,
    });
    vi.spyOn(AdminApiClient.prototype, "saveQaTestUser").mockRejectedValue(
      new AdminApiError(
        403,
        "FRESH_AUTHENTICATION_REQUIRED",
        PLAN_ID,
        null,
        false,
      ),
    );

    render(<QaManagementWorkspace />);
    fireEvent.click(screen.getByRole("tab", { name: "Test User" }));
    fireEvent.change(screen.getByPlaceholderText("User Public IDで候補検索"), {
      target: { value: USER_ID },
    });
    fireEvent.click(screen.getByRole("button", { name: "検索" }));
    await screen.findByText(USER_ID);
    fireEvent.click(screen.getByText(USER_ID));
    fireEvent.change(screen.getByLabelText("Reason"), {
      target: { value: "Release verification" },
    });
    fireEvent.change(screen.getByLabelText("終了日時（Asia/Tokyo）"), {
      target: { value: "2026-08-01T10:00" },
    });
    fireEvent.click(screen.getByRole("button", { name: "有効化" }));

    expect(await screen.findByRole("dialog")).toHaveTextContent("Fresh MFA");
  });
});

function mockPlanReads(items: AdminQaPlanSummary[] = [summary]) {
  vi.spyOn(AdminApiClient.prototype, "listQaPlans").mockResolvedValue({
    items,
    next_cursor: null,
  });
  vi.spyOn(AdminApiClient.prototype, "getQaPlan").mockResolvedValue(detail);
}

function executionDetail(): AdminQaExecutionDetail {
  return {
    assignment_id: detail.assignments[0].id,
    consumed_free_points: 1000,
    consumed_paid_points: 0,
    draw_request_id: "01910191-0191-7191-8191-019101910189",
    executed_at: "2026-08-01T00:30:00Z",
    executed_count: 10,
    failure_reason: null,
    gacha_id: GACHA_ID,
    gacha_version_id: "01910191-0191-7191-8191-019101910198",
    id: EXECUTION_ID,
    inventory_prize_delta_total: 10,
    metadata: {
      plan_item_public_ids: [detail.items[0].id],
      qa_mode_public_id: user.mode_id!,
      qa_plan_public_id: PLAN_ID,
    },
    plan_id: PLAN_ID,
    point_back_total: 0,
    point_cost_total: 1000,
    prize_counts: [{
      count: 10,
      prize: { id: PRIZE_ID, name: "Fixture Prize" },
      rank: { code: "A", id: PRIZE_ID, name: "A" },
    }],
    probability_version: {
      id: "01910191-0191-7191-8191-019101910199",
      version: 1,
    },
    processing_duration_ms: 500,
    rank_counts: [],
    requested_count: 10,
    sales_count_delta: 10,
    status: "completed",
    user_id: USER_ID,
  };
}
