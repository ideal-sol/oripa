import { fireEvent, render, screen, waitFor } from "@testing-library/react";
import { NextRequest } from "next/server";
import { beforeEach, describe, expect, it, vi } from "vitest";

const auth = {
  admin: {
    email: "owner@example.test",
    public_id: "01910191-0191-7191-8191-019101910191",
    role: "owner" as const,
  },
  clearError: vi.fn(),
  error: null,
  regenerateRecoveryCodes: vi.fn(),
  verifyRecoveryCode: vi.fn(),
  loading: false,
  logout: vi.fn(),
  mfaRequired: true,
  phase: "authenticated" as const,
};
const permission = { role: "owner" as "owner" | "admin" | "operator" };

vi.mock("@/components/auth/admin-auth-provider", () => ({
  useAdminAuth: () => auth,
}));

vi.mock("@/components/permissions/permission-provider", () => ({
  usePermissions: () => ({
    error: null,
    hasPermission: () => true,
    permissions: new Set([
      "catalog.read",
      "qa.draw.manage",
      "shipping.request.manage",
      "reporting.financial.read",
      "content.read",
      "contact.read",
    ]),
    requestId: "01910191-0191-7191-8191-019101910191",
    retry: vi.fn(),
    role: permission.role,
    status: "ready",
  }),
}));

vi.mock("next/navigation", () => ({
  usePathname: () => "/",
  useRouter: () => ({ replace: vi.fn() }),
}));

import { RecoveryPanel } from "@/components/auth/recovery-panel";
import { AdminShell } from "@/components/shell/admin-shell";
import { ModuleRoutePage } from "@/components/shell/module-route-page";
import { proxy } from "@/proxy";

describe("Admin shell and security boundaries", () => {
  beforeEach(() => {
    vi.unstubAllEnvs();
    permission.role = "owner";
    auth.regenerateRecoveryCodes.mockReset().mockResolvedValue(["synthetic-recovery-code"]);
    auth.logout.mockReset().mockResolvedValue(undefined);
  });

  it("keeps owner-only scaffold direct routes fail closed", () => {
    permission.role = "admin";
    render(<ModuleRoutePage routeId="users-history" />);

    expect(screen.getByRole("heading", { name: "アクセスできません" })).toBeVisible();
    expect(screen.queryByText("詳細画面は後続Taskで実装します。")).toBeNull();
  });

  it("shows the backend-provided role and supports logout", async () => {
    render(
      <AdminShell>
        <p>管理機能は後続Taskで実装します。</p>
      </AdminShell>,
    );

    expect(screen.getByText("Owner")).toBeInTheDocument();
    expect(screen.getByText("管理機能は後続Taskで実装します。")).toBeInTheDocument();
    fireEvent.click(screen.getByRole("button", { name: "ユーザーメニュー" }));
    fireEvent.click(screen.getByRole("menuitem", { name: "ログアウト" }));
    await waitFor(() => expect(auth.logout).toHaveBeenCalledOnce());
  });

  it("regenerates eligible recovery codes without a redundant Fresh dialog", async () => {
    render(<RecoveryPanel />);
    fireEvent.click(screen.getByRole("button", { name: "コードを再生成" }));
    await screen.findByText("synthetic-recovery-code");
    expect(auth.regenerateRecoveryCodes).toHaveBeenCalledOnce();
    expect(screen.queryByRole("dialog")).toBeNull();
    expect(screen.queryByLabelText("現在のパスワード")).toBeNull();
    fireEvent.click(screen.getByRole("button", { name: "コードを閉じる" }));
    expect(screen.queryByText("synthetic-recovery-code")).toBeNull();
  });

  it("rejects unknown hosts and hardens allowed responses", () => {
    vi.stubEnv("V2_PUBLIC_ORIGIN", "https://luxe-pack.biz/content");
    const blocked = proxy(new NextRequest("http://unknown.example/login"));
    expect(blocked.status).toBe(404);

    const allowed = proxy(new NextRequest("http://localhost/login"));
    expect(allowed.headers.get("Cache-Control")).toBe("private, no-store");
    expect(allowed.headers.get("X-Frame-Options")).toBe("DENY");
    expect(allowed.headers.get("X-Content-Type-Options")).toBe("nosniff");
    expect(allowed.headers.get("X-Robots-Tag")).toContain("noindex");
    expect(allowed.headers.get("Content-Security-Policy")).toContain(
      "frame-ancestors 'none'",
    );
    expect(allowed.headers.get("Content-Security-Policy")).toContain(
      "img-src 'self' data: https://luxe-pack.biz",
    );
  });
});
