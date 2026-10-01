import { fireEvent, render, screen, waitFor } from "@testing-library/react";
import { afterEach, describe, expect, it, vi } from "vitest";

import GachaCreatePage from "@/app/catalog/gachas/new/page";
import { LoginGachaWorkspace } from "@/components/catalog/login-gacha-workspace";
import { AdminApiClient } from "@/lib/admin-api/client";
import { emptyGachaComposition, fixedPercentageScale, jstInput, jstTimestamp, percentageTotal, percentageUnits } from "@/lib/catalog/login-gacha";

const callbacks = vi.hoisted(() => ({ expireSession: vi.fn(), push: vi.fn() }));
vi.mock("next/navigation", () => ({ useRouter: () => ({ push: callbacks.push }) }));
vi.mock("@/components/shell/admin-shell", () => ({ AdminShell: ({ children }: { children: React.ReactNode }) => <>{children}</> }));
vi.mock("@/components/permissions/protected-admin-route", () => ({ ProtectedAdminRoute: ({ children }: { children: React.ReactNode }) => <>{children}</> }));
vi.mock("@/components/permissions/permission-provider", () => ({ usePermissions: () => ({ hasPermission: () => true }) }));
vi.mock("@/components/auth/admin-auth-provider", () => ({ useAdminAuth: () => ({ expireSession: callbacks.expireSession }) }));

afterEach(() => { vi.restoreAllMocks(); });

function selections() {
  vi.spyOn(AdminApiClient.prototype, "listCatalogRanks").mockResolvedValue({ items: [], next_cursor: null });
  vi.spyOn(AdminApiClient.prototype, "listCatalogPresentationAssets").mockResolvedValue({ items: [], next_cursor: null });
  vi.spyOn(AdminApiClient.prototype, "listCatalogCategories").mockResolvedValue({ items: [], next_cursor: null });
  vi.spyOn(AdminApiClient.prototype, "listCatalogTags").mockResolvedValue({ items: [], next_cursor: null });
}

describe("Login Gacha composition", () => {
  it("preserves exact decimal strings without floating point or ppm conversion", () => {
    expect(percentageUnits("0.0000000001")).toBe(1n);
    expect(percentageUnits("99.9999999999")).toBe(fixedPercentageScale - 1n);
    expect(percentageTotal(["0.0000000001", "99.9999999999"])).toEqual({ valid: true, label: "100%" });
    expect(percentageTotal(["0.0000000001", "99.9999999998"]).valid).toBe(false);
    for (const value of ["0", "1e-10", "1.00000000001", "100.0000000001", "1x5", "01", " 1", "+1"]) {
      expect(percentageUnits(value)).toBeNull();
    }
    expect(percentageTotal(["0", "100"]).valid).toBe(false);
  });

  it("interprets publication inputs as JST independent of browser timezone", () => {
    expect(jstTimestamp("2026-10-01T00:00")).toBe("2026-10-01T00:00:00+09:00");
    expect(jstInput("2026-09-30T15:00:00Z")).toBe("2026-10-01T00:00");
    expect(jstTimestamp("")).toBeNull();
  });

  it("offers three distinct registration routes without a common type selector", () => {
    render(<GachaCreatePage />);
    expect(screen.getByRole("link", { name: "通常ガチャを登録" })).toHaveAttribute("href", "/catalog/gachas/new/standard");
    expect(screen.getByRole("link", { name: "新規登録限定ガチャを登録" })).toHaveAttribute("href", "/catalog/gachas/new/signup");
    expect(screen.getByRole("link", { name: "ログインガチャを登録" })).toHaveAttribute("href", "/catalog/gachas/new/login");
    expect(screen.queryByRole("combobox")).not.toBeInTheDocument();
  });

  it("shows daily price and minimum, but no total, category, shipping or audience fields", async () => {
    selections();
    render(<LoginGachaWorkspace type="login_daily" />);
    expect(await screen.findByLabelText("消費ポイント")).toHaveValue(0);
    expect(screen.getByLabelText("最低保証（交換ポイント）")).toHaveValue(0);
    for (const label of ["総口数", "カテゴリ", "タグ", "発送専用", "対象ユーザー", "ガチャ種別"]) {
      expect(screen.queryByLabelText(label)).not.toBeInTheDocument();
    }
    expect(screen.getByRole("button", { name: "構成を一括保存" })).toBeDisabled();
  });

  it("keeps signup free and omits paid configuration", async () => {
    selections();
    render(<LoginGachaWorkspace type="signup_once" />);
    expect(await screen.findByText("消費ポイント: 無料（0）")).toBeInTheDocument();
    expect(screen.queryByLabelText("消費ポイント")).not.toBeInTheDocument();
    expect(screen.queryByLabelText("最低保証（交換ポイント）")).not.toBeInTheDocument();
    expect(emptyGachaComposition("signup_once").price_points).toBe(0);
  });

  it("loads a copy into an unsaved draft without creating a Canonical or retaining periods", async () => {
    selections();
    const composition = { ...emptyGachaComposition("signup_once"), title: "Copied signup" };
    const read = vi.spyOn(AdminApiClient.prototype, "getGachaComposition").mockResolvedValue({ data: composition });
    vi.spyOn(AdminApiClient.prototype, "getCatalogGacha").mockResolvedValue({ data: {
      id: "source", code: "source", slug: "source", state: "active", category: null, tags: [], published_version: null,
      version_count: 1, sold_count: 0, has_draw_history: true, is_archived: false, revision: 1, archived_at: null, created_at: "", updated_at: "",
    } });
    const create = vi.spyOn(AdminApiClient.prototype, "createGachaComposition");
    render(<LoginGachaWorkspace sourceId="source" copy />);
    expect(await screen.findByLabelText("ガチャ名")).toHaveValue("Copied signup");
    expect(screen.getByLabelText("公開開始日時（JST）")).toHaveValue("");
    expect(read).toHaveBeenCalledWith("source", true, expect.any(AbortSignal));
    fireEvent.change(screen.getByLabelText("ガチャ名"), { target: { value: "Unsaved title" } });
    await waitFor(() => expect(create).not.toHaveBeenCalled());
  });
});
