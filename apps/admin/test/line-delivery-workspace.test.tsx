import { fireEvent, render, screen, waitFor } from "@testing-library/react";
import { afterEach, beforeEach, expect, it, vi } from "vitest";
const access = vi.hoisted(() => ({ write: true }));
vi.mock("@/components/permissions/permission-provider", () => ({ usePermissions: () => ({ permissions: new Set(access.write ? ["line.bot.read", "line.bot.manage"] : ["line.bot.read"]) }) }));
import { LineDeliveryWorkspace } from "@/components/line/line-delivery-workspace";
import { AdminApiClient, AdminApiError } from "@/lib/admin-api/client";

beforeEach(() => {
  access.write = true;
  vi.spyOn(AdminApiClient.prototype, "listLineDeliveries").mockResolvedValue({ data: [], next_cursor: null });
  vi.spyOn(AdminApiClient.prototype, "getLineDeliveryDefaults").mockResolvedValue({ revision: 2, pacing: { push_every: 10, push_wait_seconds: 5, multicast_wait_seconds: 0 } });
});
afterEach(() => vi.restoreAllMocks());
it("creates scheduled-now intent with revision, never a null draft intent", async () => {
  const save = vi.spyOn(AdminApiClient.prototype, "saveLineDelivery").mockRejectedValue(new AdminApiError(403, "FRESH_MFA_REQUIRED", null, null, false));
  vi.spyOn(window, "confirm").mockReturnValue(true);
  render(<LineDeliveryWorkspace />); await screen.findByText("配信はありません。");
  fireEvent.click(screen.getByRole("button", { name: "新規配信" }));
  fireEvent.change(screen.getByLabelText("管理用名称"), { target: { value: "D5 fixture" } });
  fireEvent.change(screen.getByLabelText("本文"), { target: { value: "<script>test</script>" } });
  fireEvent.click(screen.getByRole("button", { name: "今すぐ配信" }));
  await waitFor(() => expect(save).toHaveBeenCalledWith(null, expect.objectContaining({ mode: "now", expected_revision: 0, request_key: expect.any(String) })));
  expect(await screen.findByRole("alert")).toHaveTextContent("再認証が必要");
  expect(save).toHaveBeenCalledOnce(); expect(document.querySelector("script")).toBeNull();
});
it("keeps read-only controls disabled without D4 HOLD recovery", async () => {
  access.write = false; render(<LineDeliveryWorkspace />); await screen.findByText("配信はありません。");
  expect(screen.getByRole("button", { name: "新規配信" })).toBeDisabled();
  expect(screen.queryByRole("button", { name: /HOLD.*復旧|一括復旧/ })).toBeNull();
});
it("edits mixed formats without split imagemap regions or D6 templates", async () => {
  render(<LineDeliveryWorkspace />); await screen.findByText("配信はありません。");
  fireEvent.click(screen.getByRole("button", { name: "新規配信" }));
  fireEvent.change(screen.getByLabelText("形式"), { target: { value: "imagemap" } });
  expect(screen.getByLabelText("画像全体のリンク先")).toBeVisible();
  expect(screen.queryByText("4分割")).toBeNull();
  fireEvent.click(screen.getByRole("button", { name: "メッセージを追加" }));
  expect(screen.getAllByLabelText("形式")).toHaveLength(2);
  expect(screen.queryByRole("button", { name: "テンプレートを選択" })).toBeNull();
});
