import { fireEvent, render, screen, waitFor, within } from "@testing-library/react";
import { afterEach, beforeEach, expect, it, vi } from "vitest";
import type { LineAutomatedSetting } from "@/lib/admin-api/generated";
const access = vi.hoisted(() => ({ writable: true }));
vi.mock("@/components/permissions/permission-provider", () => ({ usePermissions: () => ({ permissions: new Set(access.writable ? ["line.bot.read", "line.bot.manage"] : ["line.bot.read"]) }) }));
import { LineAutomatedSettings } from "@/components/line/line-automated-settings";
import { AdminApiClient } from "@/lib/admin-api/client";
const rows: LineAutomatedSetting[] = (["shipping", "payment", "first_purchase", "rank_promotion"] as const).map((event) => ({event, revision: 0, enabled: false, category: event === "shipping" || event === "payment" ? "transactional" : "promotional", messages: [{ type: "text", text: "通知" }], effective_at: null}));
beforeEach(() => { access.writable = true; vi.spyOn(AdminApiClient.prototype, "getLineAutomatedSettings").mockResolvedValue({ data: structuredClone(rows) }); });
afterEach(() => vi.restoreAllMocks());
it("edits four events with revision and reports unsupported shipping URL without inventing a link", async () => {
  const save = vi.spyOn(AdminApiClient.prototype, "updateLineAutomatedSettings").mockResolvedValue({ data: rows });
  render(<LineAutomatedSettings />);
  const shipping = (await screen.findByRole("heading", { name: "発送完了" })).closest("form")!;
  fireEvent.change(within(shipping).getByLabelText("本文 1"), { target: { value: "%shipping_url% <script>example</script>" } });
  expect(within(shipping).getByRole("alert")).toHaveTextContent("未対応");
  expect(shipping.querySelector("script")).toBeNull();
  expect(shipping.querySelector("a")).toBeNull();
  fireEvent.click(within(shipping).getByRole("button", { name: "通知設定を保存" }));
  await waitFor(() => expect(save).toHaveBeenCalledWith("shipping", expect.objectContaining({ expected_revision: 0, category: "transactional" })));
});
it("enforces read only and fixed transactional categories", async () => {
  access.writable = false;
  render(<LineAutomatedSettings />);
  await screen.findByRole("heading", { name: "決済完了" });
  for (const button of screen.getAllByRole("button", { name: "通知設定を保存" })) expect(button).toBeDisabled();
  for (const input of screen.getAllByRole("checkbox")) expect(input).toBeDisabled();
});
it("saves without an additional authentication form", async () => {
  const save = vi.spyOn(AdminApiClient.prototype, "updateLineAutomatedSettings").mockResolvedValue({ data: rows });
  const reauthenticate = vi.spyOn(AdminApiClient.prototype, "reauthenticate");
  render(<LineAutomatedSettings />);
  await screen.findByRole("heading", { name: "決済完了" });
  fireEvent.click(screen.getAllByRole("button", { name: "通知設定を保存" })[0]);
  expect(await screen.findByText("発送完了を保存しました。")).toBeVisible();
  expect(save).toHaveBeenCalledOnce();
  expect(reauthenticate).not.toHaveBeenCalled();
  expect(screen.queryByText(/再認証|認証コード|セキュリティキー/)).toBeNull();
});
