import { fireEvent, render, screen, waitFor, within } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { AgencyPortal } from "../src/components/agency-portal";
import { agencyApi, AgencyApiError } from "../src/lib/agency-api/client";

const { replace } = vi.hoisted(() => ({ replace: vi.fn() }));
vi.mock("next/navigation", () => { const router = { replace }; return { useRouter: () => router }; });
vi.mock("../src/lib/agency-api/client", async importOriginal => {
  const original = await importOriginal<typeof import("../src/lib/agency-api/client")>();
  return { ...original, agencyApi: { session: vi.fn(), login: vi.fn(), contact: vi.fn(), email: vi.fn(), password: vi.fn(), logout: vi.fn() } };
});

const agency = { id: "synthetic", company_name: "QA Company", contact_name: "QA Contact", phone: "03-1234-5678", email: "qa@example.test", address: "Synthetic address", login_id: "000001", advertising_code: "AD000001", status: "active" as const };
beforeEach(() => { vi.clearAllMocks(); vi.mocked(agencyApi.session).mockResolvedValue({ authenticated: true, agency }); });

describe("Agency Portal", () => {
  it("shows loading, authenticated Admin layout and own profile with aggregation navigation", async () => {
    const { container } = render(<AgencyPortal />);
    expect(screen.getByRole("status")).toHaveTextContent("読み込み中");
    expect(await screen.findByText("QA Company")).toBeVisible();
    expect(container.querySelector(".admin-shell.agency-shell .admin-sidebar")).toBeTruthy();
    expect(screen.getAllByText("代理店管理").length).toBeGreaterThan(0);
    expect(screen.getByRole("link", { name: "売上集計" })).toHaveAttribute("href", "/aggregates/sales");
    expect(screen.getByRole("link", { name: "ユーザー集計" })).toHaveAttribute("href", "/aggregates/users");
  });

  it("logs in and shows generic error", async () => {
    vi.mocked(agencyApi.session).mockResolvedValue({ authenticated: false, agency: null });
    vi.mocked(agencyApi.login).mockRejectedValueOnce(new AgencyApiError(401, "AUTHENTICATION_REQUIRED")).mockResolvedValueOnce({ data: agency });
    render(<AgencyPortal />);
    const form = await screen.findByRole("form", { name: "代理店ログイン" });
    fireEvent.change(within(form).getByLabelText("Login ID"), { target: { value: "000001" } });
    fireEvent.change(within(form).getByLabelText("パスワード"), { target: { value: "Wrong123" } });
    fireEvent.submit(form);
    expect(await screen.findByRole("alert")).toHaveTextContent("ログイン情報");
    fireEvent.change(within(form).getByLabelText("パスワード"), { target: { value: "Initial123" } });
    fireEvent.submit(form);
    expect(await screen.findByText("QA Company")).toBeVisible();
    expect(agencyApi.login).toHaveBeenLastCalledWith({ login_id: "000001", password: "Initial123" });
  });

  it("saves contact, email and password with only permitted fields and clears credentials", async () => {
    vi.mocked(agencyApi.contact).mockResolvedValue({ data: { ...agency, contact_name: "Changed" } });
    vi.mocked(agencyApi.email).mockResolvedValue({ data: { ...agency, email: "new@example.test" } });
    vi.mocked(agencyApi.password).mockResolvedValue({ data: agency });
    render(<AgencyPortal />);
    const contact = await screen.findByRole("form", { name: "担当者情報" });
    fireEvent.change(within(contact).getByLabelText("担当者名"), { target: { value: "Changed" } });
    fireEvent.submit(contact);
    await waitFor(() => expect(agencyApi.contact).toHaveBeenCalledWith({ contact_name: "Changed", phone: agency.phone }));
    await screen.findByText("Changed");
    const email = screen.getByRole("form", { name: "メールアドレス変更" });
    fireEvent.change(within(email).getByLabelText("現在のパスワード"), { target: { value: "Initial123" } });
    fireEvent.change(within(email).getByLabelText("新しいメールアドレス"), { target: { value: "new@example.test" } });
    fireEvent.submit(email);
    await waitFor(() => expect(agencyApi.email).toHaveBeenCalledWith({ current_password: "Initial123", email: "new@example.test" }));
    await screen.findByText("new@example.test");
    expect(within(email).getByLabelText("現在のパスワード")).toHaveValue("");
    const password = screen.getByRole("form", { name: "パスワード変更" });
    for (const label of ["現在のパスワード", "新しいパスワード", "新しいパスワード（確認）"]) fireEvent.change(within(password).getByLabelText(label), { target: { value: "Changed123" } });
    fireEvent.submit(password);
    await waitFor(() => expect(agencyApi.password).toHaveBeenCalledWith({ current_password: "Changed123", password: "Changed123", password_confirmation: "Changed123" }));
    await waitFor(() => expect(within(password).getByLabelText("新しいパスワード")).toHaveValue(""));
  });

  it.each([401, 403])("handles stopped or forbidden session (%s)", async status => {
    vi.mocked(agencyApi.contact).mockRejectedValue(new AgencyApiError(status, status === 401 ? "AUTHENTICATION_REQUIRED" : "AUTHORIZATION_DENIED"));
    render(<AgencyPortal />);
    fireEvent.submit(await screen.findByRole("form", { name: "担当者情報" }));
    expect(await screen.findByRole("form", { name: "代理店ログイン" })).toBeVisible();
    expect(replace).toHaveBeenLastCalledWith("/login");
  });

  it("logs out and handles network errors", async () => {
    vi.mocked(agencyApi.logout).mockRejectedValueOnce(new Error("network")).mockResolvedValueOnce({});
    render(<AgencyPortal />);
    const logout = await screen.findByRole("button", { name: "ログアウト" });
    fireEvent.click(logout);
    expect(await screen.findByRole("alert")).toHaveTextContent("ログアウトできませんでした");
    fireEvent.click(logout);
    expect(await screen.findByRole("form", { name: "代理店ログイン" })).toBeVisible();
  });
});
