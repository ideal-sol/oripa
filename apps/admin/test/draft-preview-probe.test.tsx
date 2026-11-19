import type { ReactNode } from "react";
import { render, screen, within } from "@testing-library/react";
import { describe, expect, it, vi } from "vitest";

vi.mock("@/components/shell/admin-shell", () => ({
  AdminShell: ({ children }: { children: ReactNode }) => (
    <main data-testid="existing-admin-shell">{children}</main>
  ),
}));

vi.mock("@/components/shell/dashboard-home", () => ({
  DashboardHome: () => <section aria-label="既存ダッシュボード">既存コンテンツ</section>,
}));

import AdminHomePage from "@/app/page";
import { DraftPreviewProbe } from "@/components/shell/draft-preview-probe";

describe("Draft Preview verification display", () => {
  it("renders the exact A marker as passive text without interactive controls", () => {
    const { container } = render(<DraftPreviewProbe />);
    expect(screen.getByTestId("draft-preview-probe")).toBeVisible();
    expect(screen.getByTestId("draft-preview-probe").textContent?.trim()).toBe("Draft Preview 検証 A");
    expect(container.querySelectorAll("a, button, input, form")).toHaveLength(0);
  });

  it("places one marker inside the existing shell and preserves the dashboard", () => {
    render(<AdminHomePage />);
    const shell = screen.getByTestId("existing-admin-shell");
    expect(within(shell).getAllByTestId("draft-preview-probe")).toHaveLength(1);
    expect(within(shell).getByRole("region", { name: "既存ダッシュボード" })).toHaveTextContent("既存コンテンツ");
    expect(shell.firstElementChild).toBe(screen.getByTestId("draft-preview-probe"));
  });
});
