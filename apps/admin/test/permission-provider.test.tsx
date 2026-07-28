import { render, screen, waitFor } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";

const expireSession = vi.fn();
const getPermissions = vi.fn();
const auth = {
  admin: {
    id: "01910191-0191-7191-8191-019101910191",
    role: "owner" as const,
    state: "active" as const,
  },
  expireSession,
  phase: "authenticated" as const,
};

vi.mock("@/components/auth/admin-auth-provider", () => ({
  useAdminAuth: () => auth,
}));

vi.mock("@/lib/admin-api/client", async () => {
  const actual = await vi.importActual<typeof import("@/lib/admin-api/client")>(
    "@/lib/admin-api/client",
  );
  return {
    ...actual,
    AdminApiClient: class {
      getPermissions = getPermissions;
    },
  };
});

import {
  PermissionProvider,
  usePermissions,
} from "@/components/permissions/permission-provider";
import { AdminApiError } from "@/lib/admin-api/client";

describe("PermissionProvider", () => {
  beforeEach(() => {
    expireSession.mockReset();
    getPermissions.mockReset();
  });

  it("accepts only a role-matched, unique permission response", async () => {
    getPermissions.mockResolvedValue({
      permissions: ["catalog.read", "qa.draw.manage"],
      request_id: "01910191-0191-7191-8191-019101910192",
      role: "owner",
    });
    render(
      <PermissionProvider>
        <PermissionProbe />
      </PermissionProvider>,
    );

    await waitFor(() => expect(screen.getByTestId("status")).toHaveTextContent("ready"));
    expect(screen.getByTestId("catalog")).toHaveTextContent("yes");
  });

  it("fails closed on a role mismatch or unknown permission", async () => {
    getPermissions.mockResolvedValue({
      permissions: ["catalog.read", "unknown.permission"],
      request_id: "01910191-0191-7191-8191-019101910192",
      role: "admin",
    });
    render(
      <PermissionProvider>
        <PermissionProbe />
      </PermissionProvider>,
    );

    await waitFor(() => expect(screen.getByTestId("status")).toHaveTextContent("error"));
    expect(screen.getByTestId("catalog")).toHaveTextContent("no");
  });

  it("expires the local shell after an API 401", async () => {
    getPermissions.mockRejectedValue(
      new AdminApiError(401, "AUTHENTICATION_REQUIRED", null, null, false),
    );
    render(
      <PermissionProvider>
        <PermissionProbe />
      </PermissionProvider>,
    );

    await waitFor(() => expect(expireSession).toHaveBeenCalledOnce());
  });
});

function PermissionProbe() {
  const { hasPermission, status } = usePermissions();
  return (
    <>
      <span data-testid="status">{status}</span>
      <span data-testid="catalog">
        {hasPermission("catalog.read") ? "yes" : "no"}
      </span>
    </>
  );
}
