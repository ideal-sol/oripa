import { fireEvent, render, screen, waitFor } from "@testing-library/react";
import { beforeEach, expect, it, vi } from "vitest";
import { AgencyPortal } from "../src/components/agency-portal";
import { agencyApi } from "../src/lib/agency-api/client";

const { replace } = vi.hoisted(() => ({ replace: vi.fn() }));
vi.mock("next/navigation", () => { const router = { replace }; return { useRouter: () => router }; });
const period = { start_date: "2026-09-01", end_date: "2026-09-30", timezone: "Asia/Tokyo" as const };
beforeEach(() => {
  vi.spyOn(agencyApi, "session").mockResolvedValue({ authenticated: true, agency: { id: "synthetic", company_name: "QA Company", contact_name: "QA Contact", phone: "03-1234-5678", email: "qa@example.test", address: "Synthetic", login_id: "000001", advertising_code: "AD000001", status: "active" } });
  replace.mockClear();
});

it("shows own codes, both user classifications and canonical date defaults without company duplication", async () => {
  const load = vi.spyOn(agencyApi, "userAggregate").mockResolvedValue({ period, next_cursor: null, items: [
    { advertising_code: "AD000001", temporary_users: 2, full_users: 0 },
    { advertising_code: "AD000002", temporary_users: 0, full_users: 0 },
  ] });
  render(<AgencyPortal view="users" />);
  expect(await screen.findByText("AD000001")).toBeVisible();
  expect(screen.getByText("AD000002")).toBeVisible();
  expect(screen.getByText("2人")).toBeVisible();
  expect(screen.queryByRole("columnheader", { name: "代理店名" })).toBeNull();
  expect(screen.queryByText("QA Company")).toBeNull();
  expect(screen.getByLabelText("開始日")).toHaveValue("2026-09-01");
  expect(load).toHaveBeenCalledWith({}, expect.any(AbortSignal));
  expect(replace).not.toHaveBeenCalled();
  fireEvent.change(screen.getByLabelText("開始日"), { target: { value: "2026-08-01" } });
  fireEvent.submit(screen.getByRole("form", { name: "集計期間" }));
  await waitFor(() => expect(load).toHaveBeenLastCalledWith({ start_date: "2026-08-01", end_date: "2026-09-30" }, expect.any(AbortSignal)));
});

it("shows own sales payer counts and net JPY with account navigation", async () => {
  vi.spyOn(agencyApi, "salesAggregate").mockResolvedValue({ period, next_cursor: null, items: [{ advertising_code: "AD000001", temporary_paying_users: 1, temporary_amount: 4000, full_paying_users: 1, full_amount: 4000 }] });
  render(<AgencyPortal view="sales" />);
  expect(await screen.findByRole("table")).toBeVisible();
  expect(screen.getAllByText("￥4,000")).toHaveLength(2);
  expect(screen.getAllByText("1人")).toHaveLength(2);
  expect(screen.getByRole("link", { name: "アカウント設定" })).toHaveAttribute("href", "/");
});

it("does not fetch aggregates before authenticating", async () => {
  vi.mocked(agencyApi.session).mockResolvedValue({ authenticated: false, agency: null });
  const load = vi.spyOn(agencyApi, "salesAggregate");
  render(<AgencyPortal view="sales" />);
  expect(await screen.findByRole("form", { name: "代理店ログイン" })).toBeVisible();
  expect(load).not.toHaveBeenCalled();
  expect(replace).toHaveBeenCalledWith("/login");
});
