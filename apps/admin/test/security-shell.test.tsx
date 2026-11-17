import { fireEvent, render, screen, waitFor } from "@testing-library/react";
import { NextRequest } from "next/server";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

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

const origin = "https://synthetic-upload.s3.ap-northeast-1.amazonaws.com";
function policy(path: string, host = "localhost") {
  return proxy(new NextRequest(`https://${host}${path}`, { headers: { host } }))
    .headers.get("Content-Security-Policy")!;
}
function connect(path: string, host = "localhost") {
  return policy(path, host).split("; ").find((part) => part.startsWith("connect-src"));
}

afterEach(() => vi.unstubAllEnvs());

describe("LINE direct upload CSP", () => {
  it.each(["/login", "/auth/mfa", "/", "/catalog", "/contacts", "/line-deliveries", "/line-templates", "/line-chat"])("permits the same exact upload origin when the initial document is %s", (path) => {
    vi.stubEnv("V2_LINE_MEDIA_UPLOAD_ORIGIN", `${origin}/`);
    expect(connect(path)).toBe(`connect-src 'self' ${origin}`);
    expect(policy(path)).toContain("object-src 'none'");
    expect(policy(path)).toContain("frame-ancestors 'none'");
    expect(policy(path)).toContain("form-action 'self'");
    expect(policy(path)).toContain("'strict-dynamic'");
  });
  it("keeps unknown hosts denied without an upload exception", () => {
    vi.stubEnv("V2_LINE_MEDIA_UPLOAD_ORIGIN", origin);
    expect(connect("/line-deliveries", "untrusted.example.test")).toBe("connect-src 'self'");
  });
  it.each([undefined, ""])("defaults to self when unconfigured (%s)", (value) => {
    vi.stubEnv("V2_LINE_MEDIA_UPLOAD_ORIGIN", value);
    expect(connect("/line-deliveries")).toBe("connect-src 'self'");
  });
  it.each(["http://upload.example.test", "https://*.example.test", "https://upload.example.test/path", "https://upload.example.test?x=1", "https://upload.example.test#fragment", "https://user:password@upload.example.test", "https://upload.example.test; connect-src *", "https://upload.example.test https://other.example.test", "https://upload.example.test\n", "https://bad..example.test"])("fails closed for malformed origin %s", (value) => {
    vi.stubEnv("V2_LINE_MEDIA_UPLOAD_ORIGIN", value);
    expect(() => connect("/line-deliveries")).toThrow();
  });
  it("does not infer the upload origin from the CDN or request", () => {
    vi.stubEnv("V2_LINE_MEDIA_UPLOAD_ORIGIN", "");
    vi.stubEnv("V2_ASSET_PUBLIC_BASE_URL", "https://cdn.example.test");
    expect(connect("/line-deliveries")).toBe("connect-src 'self'");
  });
});
