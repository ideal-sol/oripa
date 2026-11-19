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
  render(<LineChatWorkspace conversationId="c" />);
  expect(await screen.findByText("取消済み")).toBeVisible(); expect(screen.queryByRole("button", { name: "添付を表示・ダウンロード" })).toBeNull();
  await waitFor(() => expect(AdminApiClient.prototype.readLineChat).toHaveBeenCalledWith("c", 1));
  expect(screen.getByRole("link", { name: "一覧へ戻る" })).toHaveAttribute("href", "/line-chat"); expect(screen.queryByRole("link", { name: "会員詳細" })).toBeNull();
});
it("retries an ambiguous reply using the same UUID and frozen text", async () => {
  const send = vi.spyOn(AdminApiClient.prototype, "replyLineChat").mockRejectedValue(new Error());
  render(<LineChatWorkspace conversationId="c" />);
  await screen.findByText("取消済み"); await waitFor(() => expect(screen.getByRole("button", { name: "返信を送信キューに登録" })).toBeEnabled());
  fireEvent.change(screen.getByLabelText("返信本文"), { target: { value: "<script>synthetic</script>" } });
  fireEvent.click(screen.getByRole("button", { name: "返信を送信キューに登録" }));
  await waitFor(() => expect(send).toHaveBeenCalledTimes(1));
  const retry = await screen.findByRole("button", { name: "同じ送信要求の結果を確認" });
  expect(screen.queryByRole("link", { name: "一覧へ戻る" })).toBeNull();
  expect(screen.getByRole("button", { name: "一覧へ戻る（送信結果を確認中）" })).toBeDisabled();
  const unload = new Event("beforeunload", { cancelable: true }); window.dispatchEvent(unload);
  expect(unload.defaultPrevented).toBe(true);
  await waitFor(() => expect(retry).toBeEnabled()); fireEvent.click(retry);
  await waitFor(() => expect(send).toHaveBeenCalledTimes(2)); expect(send.mock.calls[0]).toEqual(send.mock.calls[1]);
  expect(document.querySelector("script")).toBeNull(); expect(screen.getByLabelText("返信本文")).toBeDisabled();
});
it("lists conversation links, preview, unread count and JST without fetching history", async () => {
  render(<LineChatWorkspace />);
  const row = await screen.findByRole("link", { name: /未登録の友だち/ });
  expect(row).toHaveAttribute("href", "/line-chat/c");
  expect(row).toHaveTextContent("未読 1"); expect(row).toHaveTextContent("9:00");
  expect(row).toHaveTextContent("JST"); expect(row).toHaveTextContent("text");
  expect(AdminApiClient.prototype.getLineChat).not.toHaveBeenCalled();
  expect(screen.queryByRole("button", { name: "返信を送信キューに登録" })).toBeNull();
});
it("prepends older history, retains current messages and restores scroll position", async () => {
  vi.mocked(AdminApiClient.prototype.getLineChat).mockResolvedValueOnce({ ...detail, next_before: 1 }).mockResolvedValueOnce({ ...detail, messages: [{ ...detail.messages[0], id: "older", sequence: 0, unsent: false, body: { text: "Older text" }, received_at: "2026-11-16T00:00:00Z" }] });
  render(<LineChatWorkspace conversationId="c" />);
  await screen.findByText("取消済み");
  const region = screen.getByRole("region", { name: "メッセージ履歴" });
  let reads = 0; Object.defineProperty(region, "scrollHeight", { get: () => reads++ === 0 ? 300 : 500 }); region.scrollTop = 20;
  fireEvent.click(screen.getByRole("button", { name: "以前の履歴" }));
  expect(await screen.findByText("Older text")).toBeVisible(); expect(screen.getByText("取消済み")).toBeVisible();
  expect(region.scrollTop).toBe(220);
  expect(AdminApiClient.prototype.getLineChat).toHaveBeenLastCalledWith("c", 1);
  expect(AdminApiClient.prototype.readLineChat).toHaveBeenCalledTimes(1);
});
it("distinguishes API acceptance from delivery and renders outbound messages on their own", async () => {
  vi.mocked(AdminApiClient.prototype.getLineChat).mockResolvedValue({ ...detail, messages: [...detail.messages, { ...detail.messages[0], id: "out", direction: "outbound", unsent: false, type: "messages", batch_status: "accepted", body: [{ type: "text", text: "Hello" }, { type: "text", text: "Again" }] }] });
  render(<LineChatWorkspace conversationId="c" />);
  expect(await screen.findByText(/LINE API受付済み（配達・既読未確認）/)).toBeVisible();
  expect(screen.getByLabelText("送信メッセージ")).toHaveTextContent("Hello");
  expect(screen.getByLabelText("送信メッセージ")).toHaveTextContent("Again");
});
it("operator can read templates but cannot mutate them", async () => {
  render(<LineTemplateWorkspace />); await screen.findByText("テンプレートはありません。");
  expect(screen.getByRole("button", { name: "保存" })).toBeDisabled(); expect(screen.getByRole("button", { name: "追加" })).toBeDisabled();
});
it("keeps list category filtering separate from the selected template category", async () => {
  vi.mocked(AdminApiClient.prototype.lineTemplates).mockResolvedValue({ bot_id: "b", bots: [{ id: "b", name: "BOT", is_active: true }], categories: [{ id: "cat", name: "案内", revision: 1, sort_order: 0 }], next_page: null, data: [{ id: "t", name: "案内文", category_id: "cat", revision: 1, messages: [{ type: "text", text: "Hello" }] }] });
  render(<LineTemplateWorkspace />);
  fireEvent.click(await screen.findByRole("button", { name: "表示・編集" }));
  expect(screen.getByLabelText("保存カテゴリ")).toHaveValue("cat");
  expect(screen.getByLabelText("カテゴリ", { exact: true })).toHaveValue("");
  fireEvent.change(screen.getByLabelText("カテゴリ", { exact: true }), { target: { value: "cat" } });
  expect(screen.getByLabelText("保存カテゴリ")).toHaveValue("cat");
});
it("retains revisioned template editing, copying and confirmed deletion", async () => {
  access.manage = true;
  const template = { id: "t", name: "案内文", category_id: null, revision: 3, messages: [{ type: "text" as const, text: "Hello" }] };
  vi.mocked(AdminApiClient.prototype.lineTemplates).mockResolvedValue({ bot_id: "b", bots: [{ id: "b", name: "BOT", is_active: true }], categories: [], next_page: null, data: [template] });
  const save = vi.spyOn(AdminApiClient.prototype, "saveLineTemplate").mockResolvedValue({ id: "t", revision: 4 });
  const remove = vi.spyOn(AdminApiClient.prototype, "deleteLineTemplate").mockResolvedValue({ id: "t" });
  vi.spyOn(window, "confirm").mockReturnValue(true);
  render(<LineTemplateWorkspace />);
  fireEvent.click(await screen.findByRole("button", { name: "表示・編集" }));
  fireEvent.click(screen.getByRole("button", { name: "保存" }));
  await waitFor(() => expect(save).toHaveBeenCalledWith("templates", "t", expect.objectContaining({ expected_revision: 3, messages: template.messages })));
  await waitFor(() => expect(screen.getByRole("button", { name: "複製" })).toBeEnabled());
  fireEvent.click(screen.getByRole("button", { name: "複製" }));
  expect(screen.getByLabelText("名前")).toHaveValue("案内文（複製）");
  fireEvent.click(screen.getByRole("button", { name: "保存" }));
  await waitFor(() => expect(save).toHaveBeenLastCalledWith("templates", null, expect.objectContaining({ expected_revision: 0, name: "案内文（複製）" })));
  await waitFor(() => expect(screen.getByRole("button", { name: "削除" })).toBeEnabled());
  fireEvent.click(screen.getByRole("button", { name: "削除" }));
  await waitFor(() => expect(remove).toHaveBeenCalledWith("templates", "t", { bot_id: "b", expected_revision: 3 }));
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
