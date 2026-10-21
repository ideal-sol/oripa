import { fireEvent, render, screen } from "@testing-library/react";
import type { ReactNode } from "react";
import { expect, it, vi } from "vitest";
import { AdminApiClient } from "@/lib/admin-api/client";
import { MemberRankSummary } from "@/components/member-ranks/member-rank-summary";

vi.mock("@/components/shell/admin-shell", () => ({ AdminShell: ({ children }: { children: ReactNode }) => children }));
vi.mock("@/components/permissions/protected-admin-route", () => ({ ProtectedAdminRoute: ({ children }: { children: ReactNode }) => children }));

it("keeps payment reviews separate, searchable, and linked to the original adjustment", async () => {
  vi.spyOn(AdminApiClient.prototype, "listMemberRankManualChanges").mockResolvedValue({ items: [], next_cursor: null, request_id: "test" });
  vi.spyOn(AdminApiClient.prototype, "getMemberRankSummary").mockResolvedValue({ request_id: "test", data: {
    enabled: true, month: "2026-11-01", active_total: 1, ranks: [], reviews: [],
    promotion_bonus: { count: 0, amount: 0, point_type: "free" }, purchase_bonus: { count: 0, amount: 0, point_type: "free" },
    progress: { provisional: false, remaining: 0, last_completed_month: null, status: null, lease_expired: false },
    payment_adjustment_reviews: [{ id: "review", user_id: "user", payment_id: "payment", adjustment_id: "adjustment",
      type: "chargeback", month: "2026-10-01", past_month: true, requested_points: 1000, applied_points: 0,
      purchase_bonus_shortfall: 20, occurred_at: "2026-11-01T00:00:00Z" }],
  } });
  render(<MemberRankSummary />);
  const link = await screen.findByRole("link", { name: "チャージバック adjustment" });
  expect(link).toHaveAttribute("href", "/payments/payment#adjustment-adjustment");
  expect(screen.getByText("要確認の回収不足はありません。")).toBeVisible();
  fireEvent.change(screen.getByLabelText("決済取消を検索"), { target: { value: "unmatched" } });
  expect(screen.queryByRole("link", { name: "チャージバック adjustment" })).toBeNull();
  fireEvent.change(screen.getByLabelText("決済取消を検索"), { target: { value: "2026-10" } });
  expect(screen.getByRole("link", { name: "チャージバック adjustment" })).toBeVisible();
});
