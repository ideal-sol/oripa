import { fireEvent, render, screen, waitFor } from "@testing-library/react";
import { afterEach, beforeEach, expect, it, vi } from "vitest";
const access = vi.hoisted(() => ({ write: true }));
vi.mock("@/components/permissions/permission-provider", () => ({ usePermissions: () => ({ permissions: new Set(access.write ? ["line.bot.read", "line.bot.manage"] : ["line.bot.read"]) }) }));
import { LineDeliveryWorkspace } from "@/components/line/line-delivery-workspace";
import { AdminApiClient, AdminApiError } from "@/lib/admin-api/client";
import type { LineDeliveryDetail } from "@/lib/admin-api/generated";

beforeEach(() => {
  access.write = true;
  vi.spyOn(AdminApiClient.prototype, "listLineDeliveries").mockResolvedValue({ data: [], next_cursor: null });
  vi.spyOn(AdminApiClient.prototype, "getLineDeliveryDefaults").mockResolvedValue({ revision: 2, pacing: { push_every: 10, push_wait_seconds: 5, multicast_wait_seconds: 0 } });
});
afterEach(() => vi.restoreAllMocks());
it("shows persisted batch progress and a qualified completion estimate", async () => {
  const row: LineDeliveryDetail["data"] = {
    id: "00000000-0000-4000-8000-000000000001", revision: 1, title: "Synthetic progress", category: "promotional", recipient_group: "all_friends",
    segment: {}, messages: [{ type: "text", text: "Synthetic" }], pacing: { push_every: 10, push_wait_seconds: 5, multicast_wait_seconds: 0 },
    status: "sending", scheduled_at: null, created_by: null, created_at: "2026-11-16T00:00:00Z", recipient_count: 1000, planned_count: 999, bot_id: null, authentication_revision: 1,
    batch_counts: { accepted: 1, failed: 0, unknown: 0, skipped: 0, pending: 1, sending: 0, retrying: 0 },
    recipient_counts: { accepted: 500, failed: 0, unknown: 0, skipped: 0, pending: 500, sending: 0, retrying: 0 },
  };
  vi.mocked(AdminApiClient.prototype.listLineDeliveries).mockResolvedValue({ data: [row], next_cursor: null });
  vi.spyOn(AdminApiClient.prototype, "getLineDelivery").mockResolvedValue({ data: row, batches: [], next_batch_cursor: null, estimated_dispatch_end: "2026-11-16T01:00:00Z" });
  render(<LineDeliveryWorkspace />);
  fireEvent.click(await screen.findByRole("button", { name: "詳細・編集" }));
  const progress = await screen.findByRole("progressbar", { name: "バッチ処理進捗" });
  expect(progress).toHaveAttribute("value", "1"); expect(progress).toHaveAttribute("max", "2");
  expect(screen.getByText(/完了目安：/)).toHaveTextContent("実配達の完了時刻ではありません");
});
it("creates scheduled-now intent with revision, never a null draft intent", async () => {
  const save = vi.spyOn(AdminApiClient.prototype, "saveLineDelivery").mockRejectedValue(new AdminApiError(409, "LINE_DELIVERY_REVISION_CONFLICT", null, null, false));
  vi.spyOn(window, "confirm").mockReturnValue(true);
  render(<LineDeliveryWorkspace />); await screen.findByText("配信はありません。");
  fireEvent.click(screen.getByRole("button", { name: "新規配信" }));
  fireEvent.change(screen.getByLabelText("管理用名称"), { target: { value: "D5 fixture" } });
  fireEvent.change(screen.getByLabelText("本文"), { target: { value: "<script>test</script>" } });
  fireEvent.click(screen.getByRole("button", { name: "今すぐ配信" }));
  await waitFor(() => expect(save).toHaveBeenCalledWith(null, expect.objectContaining({ mode: "now", expected_revision: 0, request_key: expect.any(String) })));
  expect(await screen.findByRole("alert")).toHaveTextContent("同時更新");
  expect(screen.queryByText(/再認証|認証コード|セキュリティキー/)).toBeNull();
  expect(save).toHaveBeenCalledOnce(); expect(document.querySelector("script")).toBeNull();
});
it("keeps read-only controls disabled without D4 HOLD recovery", async () => {
  access.write = false; render(<LineDeliveryWorkspace />); await screen.findByText("配信はありません。");
  expect(screen.getByRole("button", { name: "新規配信" })).toBeDisabled();
  expect(screen.queryByRole("button", { name: /HOLD.*復旧|一括復旧/ })).toBeNull();
});
it("edits mixed formats with template loading and without split imagemap regions", async () => {
  render(<LineDeliveryWorkspace />); await screen.findByText("配信はありません。");
  fireEvent.click(screen.getByRole("button", { name: "新規配信" }));
  fireEvent.change(screen.getByLabelText("形式"), { target: { value: "imagemap" } });
  expect(screen.getByLabelText("画像全体のリンク先")).toBeVisible();
  expect(screen.queryByText("4分割")).toBeNull();
  fireEvent.click(screen.getByRole("button", { name: "メッセージを追加" }));
  expect(screen.getAllByLabelText("形式")).toHaveLength(2);
  expect(screen.getByRole("button", { name: "テンプレートを選択" })).toBeEnabled();
});
