import { act, fireEvent, render as renderUi, screen, waitFor } from "@testing-library/react";
import { Activity, useState, type ReactNode } from "react";
import { afterEach, beforeEach, expect, it, vi } from "vitest";
const access = vi.hoisted(() => ({ read: true, manage: false }));
const auth = vi.hoisted(() => ({ admin: { id: "admin-a" }, phase: "authenticated" }));
vi.mock("@/components/auth/admin-auth-provider", () => ({ useAdminAuth: () => auth }));
vi.mock("@/components/permissions/permission-provider", () => ({ usePermissions: () => ({ permissions: new Set(access.read ? ["line.chat.read", "line.chat.reply", "line.template.read", ...(access.manage ? ["line.template.manage"] : [])] : []) }) }));
import { LineChatWorkspace } from "@/components/line/line-chat-workspace";
import { LineChatReplyProvider } from "@/components/line/line-chat-reply-provider";
import { LineTemplateWorkspace } from "@/components/line/line-template-workspace";
import { LineTemplatePicker } from "@/components/line/line-template-picker";
import { AdminApiClient } from "@/lib/admin-api/client";
import { AdminApiError } from "@/lib/admin-api/client";
import type { LineChatConversation, LineChatDetail, LineChatTemplateList } from "@/lib/admin-api/generated";
const conversation: LineChatConversation = { id: "c", bot_id: "b", member_id: null, name: "未登録の友だち", rank_level: null, points: null, status: "open", revision: 1, unread_count: 1, last_message_at: "2026-11-17T00:00:00Z", summary: "text" };
const detail: LineChatDetail = { conversation, next_before: null, messages: [{ id: "m", sequence: 1, direction: "inbound", type: "text", unsent: true, body: null, media_state: "cancelled", has_attachment: false, received_at: "2026-11-17T00:00:00Z", batch_status: null }] };
function render(ui: ReactNode) { return renderUi(<LineChatReplyProvider>{ui}</LineChatReplyProvider>); }
function deferred<T>() { let resolve!: (v: T) => void; let reject!: (e: unknown) => void; const promise = new Promise<T>((yes, no) => { resolve = yes; reject = no; }); return { promise, resolve, reject }; }
function Navigation({ keepMounted = false }: { keepMounted?: boolean }) {
  const [chat, setChat] = useState(true);
  return <><button onClick={() => setChat(!chat)}>{chat ? "別画面へ" : "会話に戻る"}</button>{keepMounted ? <Activity mode={chat ? "visible" : "hidden"}><LineChatWorkspace conversationId="c" /></Activity> : chat ? <LineChatWorkspace conversationId="c" /> : <p>別画面</p>}</>;
}
beforeEach(() => {
  auth.admin = { id: "admin-a" }; auth.phase = "authenticated";
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
it("retains an uncertain request across route unmounts and only confirms the same payload", async () => {
  const send = vi.spyOn(AdminApiClient.prototype, "replyLineChat").mockRejectedValue(new AdminApiError(0, "NETWORK_ERROR", null, null, true));
  const uuid = vi.spyOn(crypto, "randomUUID");
  const storage = vi.spyOn(Storage.prototype, "setItem");
  render(<Navigation />); await screen.findByText("取消済み");
  fireEvent.change(screen.getByLabelText("返信本文"), { target: { value: "Frozen navigation reply" } });
  fireEvent.click(screen.getByRole("button", { name: "返信を送信キューに登録" }));
  await screen.findByRole("button", { name: "同じ送信要求の結果を確認" });
  fireEvent.click(screen.getByRole("button", { name: "別画面へ" }));
  const unload = new Event("beforeunload", { cancelable: true }); window.dispatchEvent(unload); expect(unload.defaultPrevented).toBe(true);
  fireEvent.click(screen.getByRole("button", { name: "会話に戻る" }));
  await waitFor(() => expect(screen.getByRole("button", { name: "同じ送信要求の結果を確認" })).toBeEnabled());
  expect(screen.getByLabelText("返信本文")).toHaveValue("Frozen navigation reply"); expect(screen.getByLabelText("返信本文")).toBeDisabled();
  fireEvent.click(screen.getByRole("button", { name: "同じ送信要求の結果を確認" }));
  await waitFor(() => expect(send).toHaveBeenCalledTimes(2)); expect(send.mock.calls[0]).toEqual(send.mock.calls[1]);
  expect(uuid).toHaveBeenCalledTimes(1); expect(storage).not.toHaveBeenCalled();
});
it("locks rapid clicks and a remount while registration is still in flight", async () => {
  const response = deferred<{ id: string }>(); const send = vi.spyOn(AdminApiClient.prototype, "replyLineChat").mockReturnValue(response.promise);
  render(<Navigation />); await screen.findByText("取消済み");
  const button = screen.getByRole("button", { name: "返信を送信キューに登録" }); fireEvent.click(button); fireEvent.click(button);
  fireEvent.click(screen.getByRole("button", { name: "別画面へ" })); fireEvent.click(screen.getByRole("button", { name: "会話に戻る" }));
  await screen.findByText("取消済み"); expect(screen.getByRole("button", { name: "同じ送信要求の結果を確認" })).toBeDisabled(); expect(send).toHaveBeenCalledTimes(1);
  await act(async () => response.resolve({ id: "queued" }));
  await waitFor(() => expect(screen.getByRole("button", { name: "最新履歴を取得" })).toBeEnabled());
  fireEvent.click(screen.getByRole("button", { name: "最新履歴を取得" }));
  expect(await screen.findByText("送信キューに登録しました。")).toBeVisible(); expect(send).toHaveBeenCalledTimes(1);
});
it("keeps confirmed registration separate from failed history and recovers without another POST", async () => {
  const send = vi.spyOn(AdminApiClient.prototype, "replyLineChat").mockResolvedValue({ id: "queued" });
  render(<Navigation />); await screen.findByText("取消済み");
  vi.mocked(AdminApiClient.prototype.getLineChat).mockRejectedValue(new Error("history unavailable"));
  fireEvent.click(screen.getByRole("button", { name: "返信を送信キューに登録" }));
  expect(await screen.findByRole("alert")).toHaveTextContent("返信は送信キューに登録されましたが、履歴の更新に失敗しました");
  expect(screen.queryByRole("button", { name: "返信を送信キューに登録" })).toBeNull(); expect(screen.getByLabelText("返信本文")).toBeDisabled();
  fireEvent.click(screen.getByRole("button", { name: "別画面へ" })); fireEvent.click(screen.getByRole("button", { name: "会話に戻る" }));
  await screen.findByRole("button", { name: "最新履歴を取得" });
  expect(screen.queryByRole("button", { name: "返信を送信キューに登録" })).toBeNull();
  vi.mocked(AdminApiClient.prototype.getLineChat).mockResolvedValue(detail);
  fireEvent.click(screen.getByRole("button", { name: "最新履歴を取得" }));
  expect(await screen.findByText("送信キューに登録しました。")).toBeVisible();
  expect(screen.getByRole("button", { name: "返信を送信キューに登録" })).toBeEnabled(); expect(send).toHaveBeenCalledTimes(1);
});
it("retains confirmation completed while away and clears memory between authenticated admins", async () => {
  const response = deferred<{ id: string }>(); vi.spyOn(AdminApiClient.prototype, "replyLineChat").mockReturnValue(response.promise);
  const view = render(<Navigation />); await screen.findByText("取消済み");
  fireEvent.change(screen.getByLabelText("返信本文"), { target: { value: "Admin A only" } });
  fireEvent.click(screen.getByRole("button", { name: "返信を送信キューに登録" })); fireEvent.click(screen.getByRole("button", { name: "別画面へ" }));
  await act(async () => response.resolve({ id: "queued" }));
  expect(screen.getByRole("status")).toHaveTextContent("履歴更新待ち");
  auth.admin = { id: "admin-b" }; view.rerender(<LineChatReplyProvider><Navigation /></LineChatReplyProvider>);
  await screen.findByText("取消済み"); expect(screen.getByLabelText("返信本文")).toHaveValue("");
  expect(screen.queryByText("送信キューに登録しました。")).toBeNull();
});
it("recovers a cached hidden route after a late POST without retaining a busy flag or sent draft", async () => {
  const response = deferred<{ id: string }>(); const send = vi.spyOn(AdminApiClient.prototype, "replyLineChat").mockReturnValue(response.promise);
  render(<Navigation keepMounted />); await screen.findByText("取消済み");
  fireEvent.change(screen.getByLabelText("返信本文"), { target: { value: "Already registered while away" } });
  fireEvent.click(screen.getByRole("button", { name: "返信を送信キューに登録" })); fireEvent.click(screen.getByRole("button", { name: "別画面へ" }));
  await act(async () => response.resolve({ id: "queued" }));
  fireEvent.click(screen.getByRole("button", { name: "会話に戻る" }));
  expect(await screen.findByText("送信キューに登録しました。")).toBeVisible();
  expect(screen.getByRole("button", { name: "返信を送信キューに登録" })).toBeEnabled(); expect(screen.getByLabelText("返信本文")).toHaveValue(""); expect(send).toHaveBeenCalledTimes(1);
});
it("distinguishes a definite initial validation rejection from an uncertain response", async () => {
  vi.spyOn(AdminApiClient.prototype, "replyLineChat").mockRejectedValue(new AdminApiError(422, "LINE_CHAT_REQUEST_REJECTED", null, null, false));
  render(<LineChatWorkspace conversationId="c" />); await screen.findByText("取消済み");
  fireEvent.click(screen.getByRole("button", { name: "返信を送信キューに登録" }));
  expect(await screen.findByRole("alert")).toHaveTextContent("返信登録APIが要求を拒否しました");
  expect(screen.getByLabelText("返信本文")).toBeEnabled(); expect(screen.queryByRole("button", { name: "同じ送信要求の結果を確認" })).toBeNull();
});
it("does not unlock a registered reply with history requested before registration succeeded", async () => {
  const response = deferred<{ id: string }>(); const history = deferred<LineChatDetail>();
  const send = vi.spyOn(AdminApiClient.prototype, "replyLineChat").mockReturnValue(response.promise);
  render(<Navigation />); await screen.findByText("取消済み");
  fireEvent.click(screen.getByRole("button", { name: "返信を送信キューに登録" }));
  fireEvent.click(screen.getByRole("button", { name: "別画面へ" }));
  vi.mocked(AdminApiClient.prototype.getLineChat).mockReturnValueOnce(history.promise);
  fireEvent.click(screen.getByRole("button", { name: "会話に戻る" }));
  await waitFor(() => expect(AdminApiClient.prototype.getLineChat).toHaveBeenCalledTimes(2));
  await act(async () => response.resolve({ id: "queued" }));
  await act(async () => history.resolve(detail));
  expect(screen.queryByRole("button", { name: "返信を送信キューに登録" })).toBeNull();
  expect(screen.getByText("返信は送信キューに登録されています。履歴の再取得のみ実行できます。")).toBeVisible();
  fireEvent.click(screen.getByRole("button", { name: "最新履歴を取得" }));
  expect(await screen.findByText("送信キューに登録しました。")).toBeVisible(); expect(send).toHaveBeenCalledTimes(1);
});
it("isolates each conversation's frozen request and resets local drafts on conversation changes", async () => {
  const send = vi.spyOn(AdminApiClient.prototype, "replyLineChat").mockRejectedValue(new Error());
  vi.mocked(AdminApiClient.prototype.getLineChat).mockImplementation(async (id) => ({ ...detail, conversation: { ...conversation, id } }));
  const view = render(<LineChatWorkspace conversationId="c" />); await screen.findByText("取消済み");
  fireEvent.change(screen.getByLabelText("返信本文"), { target: { value: "Conversation C only" } });
  fireEvent.click(screen.getByRole("button", { name: "返信を送信キューに登録" })); await screen.findByRole("button", { name: "同じ送信要求の結果を確認" });
  view.rerender(<LineChatReplyProvider><LineChatWorkspace conversationId="d" /></LineChatReplyProvider>);
  await screen.findByText("取消済み"); expect(screen.getByLabelText("返信本文")).toHaveValue("");
  fireEvent.change(screen.getByLabelText("返信本文"), { target: { value: "Unsaved D draft" } });
  view.rerender(<LineChatReplyProvider><LineChatWorkspace conversationId="c" /></LineChatReplyProvider>);
  await waitFor(() => expect(screen.getByRole("button", { name: "同じ送信要求の結果を確認" })).toBeEnabled());
  expect(screen.getByLabelText("返信本文")).toHaveValue("Conversation C only");
  fireEvent.click(screen.getByRole("button", { name: "同じ送信要求の結果を確認" }));
  await waitFor(() => expect(send).toHaveBeenCalledTimes(2)); expect(send.mock.calls[1]).toEqual(send.mock.calls[0]);
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
const bots = [{ id: "a", name: "BOT A", is_active: true }, { id: "b", name: "BOT B", is_active: false }];
function templates(bot: string, title: string): LineChatTemplateList {
  return { bot_id: bot, bots, next_page: null, categories: [{ id: `cat-${bot}`, name: `Category ${bot}`, revision: 2, sort_order: 0 }], data: [{ id: `template-${title}`, name: title, category_id: `cat-${bot}`, revision: 7, messages: [{ type: "text", text: title }] }] };
}
it("invalidates the old editor immediately and ignores late A/B responses after A→B→A", async () => {
  access.manage = true;
  const oldA = deferred<LineChatTemplateList>(); const oldB = deferred<LineChatTemplateList>(); const latestA = deferred<LineChatTemplateList>();
  vi.mocked(AdminApiClient.prototype.lineTemplates).mockResolvedValueOnce(templates("a", "Initial A")).mockReturnValueOnce(oldA.promise).mockReturnValueOnce(oldB.promise).mockReturnValueOnce(latestA.promise);
  const save = vi.spyOn(AdminApiClient.prototype, "saveLineTemplate"); const remove = vi.spyOn(AdminApiClient.prototype, "deleteLineTemplate");
  render(<LineTemplateWorkspace />); fireEvent.click(await screen.findByRole("button", { name: "表示・編集" }));
  fireEvent.change(screen.getByLabelText("カテゴリ", { exact: true }), { target: { value: "cat-a" } });
  fireEvent.click(screen.getByRole("button", { name: "表示" }));
  expect(screen.getByRole("button", { name: "削除" })).toBeDisabled();
  fireEvent.change(screen.getByLabelText("BOT", { exact: true }), { target: { value: "b" } });
  expect(screen.queryByText("Initial A")).toBeNull(); expect(screen.getByLabelText("名前")).toHaveValue("");
  expect(screen.getByLabelText("カテゴリ", { exact: true })).toHaveValue(""); expect(screen.getByLabelText("保存カテゴリ")).toHaveValue("");
  expect(screen.getByRole("button", { name: "保存" })).toBeDisabled(); expect(screen.getByRole("button", { name: "追加" })).toBeDisabled();
  fireEvent.click(screen.getByRole("button", { name: "保存" }));
  fireEvent.change(screen.getByLabelText("BOT", { exact: true }), { target: { value: "a" } });
  await act(async () => latestA.resolve(templates("a", "Current A")));
  expect(await screen.findByText("Current A")).toBeVisible();
  await act(async () => { oldB.resolve(templates("b", "Stale B")); oldA.resolve(templates("a", "Stale A")); });
  expect(screen.queryByText("Stale B")).toBeNull(); expect(screen.queryByText("Stale A")).toBeNull();
  expect(screen.getByLabelText("BOT", { exact: true })).toHaveValue("a"); expect(save).not.toHaveBeenCalled(); expect(remove).not.toHaveBeenCalled();
});
it("cancels a confirmed deletion if the BOT changes during confirmation", async () => {
  access.manage = true; vi.mocked(AdminApiClient.prototype.lineTemplates).mockResolvedValueOnce(templates("a", "Delete A")).mockResolvedValue(templates("b", "Keep B"));
  const remove = vi.spyOn(AdminApiClient.prototype, "deleteLineTemplate");
  vi.spyOn(window, "confirm").mockImplementation((prompt) => {
    expect(prompt).toContain("Delete A"); expect(prompt).toContain("BOT: a");
    fireEvent.change(screen.getByLabelText("BOT", { exact: true }), { target: { value: "b" } }); return true;
  });
  render(<LineTemplateWorkspace />); fireEvent.click(await screen.findByRole("button", { name: "削除" }));
  expect(await screen.findByText("Keep B")).toBeVisible(); expect(remove).not.toHaveBeenCalled();
});
it("can retry a failed BOT fetch without exposing the previous BOT's editor", async () => {
  access.manage = true; vi.mocked(AdminApiClient.prototype.lineTemplates).mockResolvedValueOnce(templates("a", "Initial A")).mockRejectedValueOnce(new Error()).mockResolvedValue(templates("b", "Recovered B"));
  render(<LineTemplateWorkspace />); await screen.findByText("Initial A");
  fireEvent.change(screen.getByLabelText("BOT", { exact: true }), { target: { value: "b" } });
  await waitFor(() => expect(screen.getByRole("alert")).toHaveTextContent("テンプレートを取得できませんでした"));
  expect(screen.getByRole("button", { name: "保存" })).toBeDisabled();
  fireEvent.click(screen.getByRole("button", { name: "表示" }));
  expect(await screen.findByText("Recovered B")).toBeVisible(); expect(screen.getByRole("button", { name: "保存" })).toBeEnabled();
});
it("keeps revisioned category create, update and delete scoped to the loaded BOT", async () => {
  access.manage = true; vi.mocked(AdminApiClient.prototype.lineTemplates).mockResolvedValue(templates("a", "Template A"));
  const save = vi.spyOn(AdminApiClient.prototype, "saveLineTemplate").mockResolvedValue({ id: "cat-a", revision: 3 });
  const remove = vi.spyOn(AdminApiClient.prototype, "deleteLineTemplate").mockResolvedValue({ id: "cat-a" }); vi.spyOn(window, "confirm").mockReturnValue(true);
  render(<LineTemplateWorkspace />); await screen.findByText("Template A");
  fireEvent.change(screen.getByLabelText("新しいカテゴリ"), { target: { value: "New category" } }); fireEvent.click(screen.getByRole("button", { name: "追加" }));
  await waitFor(() => expect(save).toHaveBeenCalledWith("template-categories", null, { bot_id: "a", expected_revision: 0, name: "New category", sort_order: 0 }));
  await waitFor(() => expect(screen.getByRole("button", { name: "カテゴリ更新" })).toBeEnabled());
  fireEvent.change(screen.getByLabelText("カテゴリ名"), { target: { value: "Updated category" } }); fireEvent.click(screen.getByRole("button", { name: "カテゴリ更新" }));
  await waitFor(() => expect(save).toHaveBeenCalledWith("template-categories", "cat-a", { bot_id: "a", expected_revision: 2, name: "Updated category", sort_order: 0 }));
  await waitFor(() => expect(screen.getByRole("button", { name: "カテゴリ削除" })).toBeEnabled()); fireEvent.click(screen.getByRole("button", { name: "カテゴリ削除" }));
  await waitFor(() => expect(remove).toHaveBeenCalledWith("template-categories", "cat-a", { bot_id: "a", expected_revision: 2 }));
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
