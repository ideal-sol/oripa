import { fireEvent, render, screen, waitFor } from "@testing-library/react";
import { afterEach, beforeEach, expect, it, vi } from "vitest";
const access = vi.hoisted(() => ({ read: true, manage: false }));
vi.mock("@/components/permissions/permission-provider", () => ({ usePermissions: () => ({ permissions: new Set(access.read ? ["line.chat.read", "line.chat.reply", "line.template.read", ...(access.manage ? ["line.template.manage"] : [])] : []) }) }));
import { LineChatWorkspace } from "@/components/line/line-chat-workspace";
import { LineTemplateWorkspace } from "@/components/line/line-template-workspace";
import { LineTemplatePicker } from "@/components/line/line-template-picker";
import { AdminApiClient } from "@/lib/admin-api/client";
import type { LineChatConversation, LineChatDetail } from "@/lib/admin-api/generated";
const conversation: LineChatConversation = { id: "c", bot_id: "b", member_id: null, name: "未登録の友だち", rank_level: null, points: null, status: "open", revision: 1, unread_count: 1, last_message_at: "2026-11-17T00:00:00Z", summary: "text" };
const detail: LineChatDetail = { conversation, next_before: null, messages: [{ id: "m", sequence: 1, direction: "inbound", type: "text", unsent: true, body: null, media_state: "cancelled", has_attachment: false, received_at: "2026-11-17T00:00:00Z", batch_status: null }] };
beforeEach(() => {
  access.read = true; access.manage = false;
  vi.spyOn(AdminApiClient.prototype, "listLineChat").mockResolvedValue({ data: [conversation], next_page: null });
  vi.spyOn(AdminApiClient.prototype, "getLineChat").mockResolvedValue(detail);
  vi.spyOn(AdminApiClient.prototype, "readLineChat").mockResolvedValue({ id: "c" });
  vi.spyOn(AdminApiClient.prototype, "lineTemplates").mockResolvedValue({ bot_id: "b", bots: [{ id: "b", name: "BOT", is_active: true }], categories: [], data: [], next_page: null });
});
afterEach(() => vi.restoreAllMocks());
it("shows unlinked chat and cancellation without content or download", async () => {
  render(<LineChatWorkspace />); fireEvent.click(await screen.findByRole("button", { name: /未登録の友だち/ }));
  expect(await screen.findByText("取消済み")).toBeVisible(); expect(screen.queryByRole("button", { name: "添付を表示・ダウンロード" })).toBeNull();
  await waitFor(() => expect(AdminApiClient.prototype.readLineChat).toHaveBeenCalledWith("c", 1));
  expect(screen.getByText(/送信数にカウント/)).toBeVisible(); expect(screen.queryByRole("link", { name: "会員詳細" })).toBeNull();
});
it("retries an ambiguous reply using the same UUID and frozen text", async () => {
  const send = vi.spyOn(AdminApiClient.prototype, "replyLineChat").mockRejectedValue(new Error());
  render(<LineChatWorkspace />); fireEvent.click(await screen.findByRole("button", { name: /未登録の友だち/ }));
  await screen.findByText("取消済み"); await waitFor(() => expect(screen.getByRole("button", { name: "返信を送信キューに登録" })).toBeEnabled());
  fireEvent.change(screen.getByLabelText("本文"), { target: { value: "<script>synthetic</script>" } });
  fireEvent.click(screen.getByRole("button", { name: "返信を送信キューに登録" }));
  await waitFor(() => expect(send).toHaveBeenCalledTimes(1));
  const retry = await screen.findByRole("button", { name: "同じ送信要求の結果を確認" });
  await waitFor(() => expect(retry).toBeEnabled()); fireEvent.click(retry);
  await waitFor(() => expect(send).toHaveBeenCalledTimes(2)); expect(send.mock.calls[0]).toEqual(send.mock.calls[1]);
  expect(document.querySelector("script")).toBeNull(); expect(screen.getByLabelText("本文")).toBeDisabled();
});
it("operator can read templates but cannot mutate them", async () => {
  render(<LineTemplateWorkspace />); await screen.findByText("テンプレートはありません。");
  expect(screen.getByRole("button", { name: "保存" })).toBeDisabled(); expect(screen.getByRole("button", { name: "追加" })).toBeDisabled();
});
it("denies unauthenticated navigation without fetching chat", () => {
  access.read = false; render(<LineChatWorkspace />); expect(screen.getByRole("alert")).toHaveTextContent("閲覧権限がありません"); expect(AdminApiClient.prototype.listLineChat).not.toHaveBeenCalled();
});
it("copies a template without sharing its stored message array", async () => {
  const original = [{ type: "text" as const, text: "Original" }]; const select = vi.fn();
  vi.mocked(AdminApiClient.prototype.lineTemplates).mockResolvedValue({ bot_id: "b", bots: [], categories: [], next_page: null, data: [{ id: "t", name: "Copy template", category_id: null, revision: 1, messages: original }] });
  render(<LineTemplatePicker client={new AdminApiClient()} botId="b" onSelect={select} />);
  fireEvent.click(screen.getByRole("button", { name: "テンプレートを選択" })); fireEvent.click(await screen.findByRole("button", { name: "Copy template" }));
  expect(select.mock.calls[0][0]).toEqual(original); select.mock.calls[0][0][0].text = "Edited"; expect(original[0].text).toBe("Original");
});
it("does not offer templates from a different active BOT", async () => {
  render(<LineTemplatePicker client={new AdminApiClient()} botId="old-bot" onSelect={vi.fn()} />);
  fireEvent.click(screen.getByRole("button", { name: "テンプレートを選択" })); expect(await screen.findByText("有効BOTのテンプレートを取得できませんでした。")).toBeVisible();
});
