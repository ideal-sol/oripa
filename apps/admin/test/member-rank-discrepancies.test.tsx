import { fireEvent, render, screen, waitFor } from "@testing-library/react";
import type { ReactNode } from "react";
import { beforeEach, expect, it, vi } from "vitest";
import { AdminApiClient } from "@/lib/admin-api/client";
const access = vi.hoisted(() => ({ role: "owner", status: "ready" }));
vi.mock("@/components/permissions/permission-provider", () => ({ usePermissions: () => ({ ...access, retry: vi.fn() }) }));
vi.mock("@/components/shell/admin-shell", () => ({ AdminShell: ({ children }: { children: ReactNode }) => children }));
import { MemberRankDiscrepancies } from "@/components/member-ranks/member-rank-discrepancies";

beforeEach(() => { access.role = "owner"; access.status = "ready"; });
it.each(["admin", "operator"])("does not fetch owner-only discrepancies as %s", role => {
  access.role = role;
  const read = vi.spyOn(AdminApiClient.prototype, "getMemberRankDiscrepancies");
  render(<MemberRankDiscrepancies />);
  expect(screen.getByRole("alert")).toHaveTextContent("Ownerのみ");
  expect(read).not.toHaveBeenCalled();
});
it("shows authoritative shortfall and member link and pages through active members", async () => {
  const read = vi.spyOn(AdminApiClient.prototype, "getMemberRankDiscrepancies")
    .mockResolvedValueOnce({ items: [{ user_id: "synthetic-member", rank: "gold", rank_points: 20000, reach_line: 100000, shortfall: 80000 }], next_cursor: "next", request_id: "test" })
    .mockResolvedValueOnce({ items: [], next_cursor: null, request_id: "test" });
  render(<MemberRankDiscrepancies />);
  expect(await screen.findByRole("link", { name: "synthetic-member" })).toHaveAttribute("href", "/users/synthetic-member");
  expect(screen.getByText("80,000")).toBeVisible();
  fireEvent.click(screen.getByRole("button", { name: "次の会員を表示" }));
  await screen.findByText("条件に該当する有効会員はいません。");
  await waitFor(() => expect(read.mock.calls[1][0]).toBe("next"));
});
