import { fireEvent, render, screen, waitFor } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";

const replace = vi.fn();
const api = {
  getSession: vi.fn(),
  login: vi.fn(),
};

vi.mock("next/navigation", () => ({
  useRouter: () => ({ replace }),
}));

vi.mock("@/lib/admin-api/client", async () => {
  const actual = await vi.importActual<typeof import("@/lib/admin-api/client")>(
    "@/lib/admin-api/client",
  );
  return {
    ...actual,
    AdminApiClient: class {
      getSession = api.getSession;
      login = api.login;
    },
  };
});

import { AdminAuthProvider } from "@/components/auth/admin-auth-provider";
import { LoginForm } from "@/components/auth/login-form";

describe("Admin authentication components", () => {
  beforeEach(() => {
    api.getSession.mockResolvedValue({ admin: null, authenticated: false });
    api.login.mockResolvedValue({
      expires_in: 300,
      methods: ["totp"],
      status: "mfa_required",
      transaction_token: "a".repeat(64),
      webauthn: null,
    });
    replace.mockReset();
  });

  it("performs password pre-auth and clears credential fields", async () => {
    render(
      <AdminAuthProvider>
        <LoginForm />
      </AdminAuthProvider>,
    );
    await waitFor(() => expect(api.getSession).toHaveBeenCalledOnce());
    fireEvent.change(screen.getByLabelText("メールアドレス"), {
      target: { value: "owner@example.test" },
    });
    const password = screen.getByLabelText("パスワード");
    fireEvent.change(password, { target: { value: "temporary password" } });
    fireEvent.click(screen.getByRole("button", { name: "続行" }));

    await waitFor(() =>
      expect(api.login).toHaveBeenCalledWith({
        email: "owner@example.test",
        password: "temporary password",
      }),
    );
    expect(password).toHaveValue("");
    expect(replace).toHaveBeenCalledWith("/auth/mfa");
  });

  it("does not write authentication material to browser storage", async () => {
    const local = vi.spyOn(Storage.prototype, "setItem");
    render(
      <AdminAuthProvider>
        <LoginForm />
      </AdminAuthProvider>,
    );
    await waitFor(() => expect(api.getSession).toHaveBeenCalled());
    expect(local).not.toHaveBeenCalled();
  });
});
