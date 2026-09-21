import { fireEvent, render, screen, waitFor, within } from "@testing-library/react";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

import { ContactManagementWorkspace } from "@/components/contacts/contact-management-workspace";
import { AdminApiClient } from "@/lib/admin-api/client";
import type { AdminContactDetail, AdminContactSummary } from "@/lib/admin-api/generated";

const contactId = "01910191-0191-7191-8191-019101910191";
let effectivePermissions = new Set(["contact.read", "contact.manage"]);

vi.mock("@/components/shell/admin-shell", () => ({
  AdminShell: ({ children }: { children: React.ReactNode }) => <>{children}</>,
}));
vi.mock("@/components/permissions/protected-admin-route", () => ({
  ProtectedAdminRoute: ({ children }: { children: React.ReactNode }) => <>{children}</>,
}));
vi.mock("@/components/permissions/permission-provider", () => ({
  usePermissions: () => ({ permissions: effectivePermissions, role: "admin", status: "ready" }),
}));

beforeEach(() => {
  effectivePermissions = new Set(["contact.read", "contact.manage"]);
  vi.spyOn(globalThis.crypto, "randomUUID").mockReturnValue(
    "01910191-0191-7191-8191-019101910199",
  );
});

afterEach(() => vi.restoreAllMocks());

describe("Contact management", () => {
  it("renders the V1 list columns, exact-email filter, status, and detail route", async () => {
    const list = vi.spyOn(AdminApiClient.prototype, "listContactInquiries")
      .mockResolvedValue({ items: [summary()], next_cursor: null });
    render(<ContactManagementWorkspace mode="list" />);

    expect(await screen.findByRole("heading", { name: "お問い合わせ一覧" })).toBeVisible();
    expect(screen.getAllByRole("columnheader").map((cell) => cell.textContent)).toEqual([
      "ID", "氏名", "メール", "電話番号", "状態", "受付日時", "詳細",
    ]);
    expect(screen.getByText("CNT-ABCDEFGHIJKLMNOPQRST")).toBeVisible();
    expect(screen.getByLabelText("状態: 未対応")).toBeVisible();
    expect(screen.getByRole("link", { name: "詳細" }))
      .toHaveAttribute("href", `/contacts/${contactId}`);
    expect(screen.getByLabelText("状態")).toHaveValue("new");
    expect(list).toHaveBeenCalledWith(
      { cursor: undefined, email: undefined, status: "new" },
      expect.any(AbortSignal),
    );

    fireEvent.change(screen.getByLabelText("状態"), { target: { value: "new" } });
    fireEvent.change(screen.getByLabelText("メール"), { target: { value: "user@example.test" } });
    fireEvent.click(screen.getByRole("button", { name: "検索" }));
    await waitFor(() => expect(list).toHaveBeenLastCalledWith(
      { cursor: undefined, email: "user@example.test", status: "new" },
      expect.any(AbortSignal),
    ));
  });

  it("honors an explicit status override and keeps manual changes local", async () => {
    const list = vi.spyOn(AdminApiClient.prototype, "listContactInquiries")
      .mockResolvedValue({ items: [summary()], next_cursor: null });
    const { unmount } = render(<ContactManagementWorkspace initialStatus="replied" mode="list" />);
    expect(await screen.findByLabelText("状態")).toHaveValue("replied");
    expect(list).toHaveBeenCalledWith(
      { cursor: undefined, email: undefined, status: "replied" },
      expect.any(AbortSignal),
    );
    fireEvent.change(screen.getByLabelText("状態"), { target: { value: "closed" } });
    await waitFor(() => expect(list).toHaveBeenLastCalledWith(
      { cursor: undefined, email: undefined, status: "closed" },
      expect.any(AbortSignal),
    ));
    unmount();
    render(<ContactManagementWorkspace mode="list" />);
    expect(await screen.findByLabelText("状態")).toHaveValue("new");
  });

  it("queues one reply request and refreshes canonical detail", async () => {
    const get = vi.spyOn(AdminApiClient.prototype, "getContactInquiry")
      .mockResolvedValue(detail());
    const reply = vi.spyOn(AdminApiClient.prototype, "requestContactInquiryReply")
      .mockResolvedValue({ id: "01910191-0191-7191-8191-019101910192", status: "queued" });
    render(<ContactManagementWorkspace contactId={contactId} mode="detail" />);

    expect(await screen.findAllByText("お問い合わせ内容です。")).toHaveLength(2);
    expect(screen.getByText("お問い合わせ詳細・返信")).toBeVisible();
    fireEvent.change(screen.getByLabelText("返信内容"), { target: { value: "確認してご連絡します。" } });
    fireEvent.click(screen.getByRole("button", { name: "返信要求を保存" }));

    await waitFor(() => expect(reply).toHaveBeenCalledWith(
      contactId,
      { message: "確認してご連絡します。" },
      "01910191-0191-7191-8191-019101910199",
    ));
    await waitFor(() => expect(get.mock.calls.length).toBeGreaterThanOrEqual(2));
    expect(await screen.findByText("返信要求を記録しました。")).toBeVisible();
  });

  it("distinguishes user follow-ups and admin replies in chronological history", async () => {
    vi.spyOn(AdminApiClient.prototype, "getContactInquiry").mockResolvedValue({
      ...detail(),
      reply_requests: [
        { id: "reply-two", message: "再返信", created_at: "2026-08-05T03:00:00Z" },
        { id: "reply-one", message: "初回返信", created_at: "2026-08-05T10:00:00+09:00" },
      ],
      user_messages: [{ id: "follow-up", message: "追加本文", created_at: "2026-08-05T02:00:00Z" }],
      status_history: statusHistory(),
      internal_notes: [
        { note: "後のメモ {{inquiry_url}}", created_at: "2026-08-05T03:00:00Z" },
        { note: "先のメモ", created_at: "2026-08-05T01:00:00Z" },
      ],
    });
    render(<ContactManagementWorkspace contactId={contactId} mode="detail" />);
    const history = await screen.findByRole("region", { name: "対応履歴" });
    expect(within(history).getAllByRole("listitem").map((item) => item.querySelector("strong")?.textContent)).toEqual([
      "ユーザー：初回問い合わせ", "管理者：返信要求", "ユーザー：追加問い合わせ", "管理者：返信要求",
    ]);
    expect(within(history).getByText("追加本文")).toBeVisible();
    expect(within(history).queryByText("先のメモ")).not.toBeInTheDocument();
    const notes = screen.getByRole("region", { name: "内部メモ" });
    expect(within(notes).getAllByRole("listitem").map((item) => item.querySelector("p")?.textContent)).toEqual(["先のメモ", "後のメモ {{inquiry_url}}"]);
    const input = screen.getByLabelText<HTMLTextAreaElement>("返信内容");
    for (const key of ["full_name", "phone_number", "email", "address", "inquiry_url"]) {
      input.setSelectionRange(input.value.length, input.value.length);
      fireEvent.change(screen.getByLabelText("返信内容へ変数を挿入"), { target: { value: `{{${key}}}` } });
      expect(input.value).toContain(`{{${key}}}`);
    }
    expect(input.tagName).toBe("TEXTAREA");
  });

  it("labels only known reply tokens without changing user text, notes, saved data or submission", async () => {
    const message = "{{full_name}} / {{phone_number}} / {{email}} / {{address}} / {{inquiry_url}}\n{{ inquiry_url }} / {{inquiry_url  }} / {{\tfull_name\n}} / {{unknown_variable}} / {{ unknown_variable }} / <script>alert(1)</script>";
    const fixture = { ...detail(), reply_requests: [{ id: "reply", message, created_at: "2026-08-05T01:00:00Z" }], user_messages: [{ id: "user", message: "{{inquiry_url}}", created_at: "2026-08-05T02:00:00Z" }] };
    vi.spyOn(AdminApiClient.prototype, "getContactInquiry").mockResolvedValue(fixture);
    const reply = vi.spyOn(AdminApiClient.prototype, "requestContactInquiryReply").mockResolvedValue({ id: "reply", status: "queued" });
    render(<ContactManagementWorkspace contactId={contactId} mode="detail" />);
    const history = await screen.findByRole("region", { name: "対応履歴" });
    expect(within(history).getAllByRole("listitem")[1].querySelector("p")?.textContent).toBe("氏名 / 電話番号 / メールアドレス / 住所 / お問い合わせリンク\nお問い合わせリンク / お問い合わせリンク / 氏名 / {{unknown_variable}} / {{ unknown_variable }} / <script>alert(1)</script>");
    expect(within(history).getByText("{{inquiry_url}}")).toBeVisible();
    expect(history.querySelector("script")).toBeNull();
    expect(fixture.reply_requests[0].message).toBe(message);
    fireEvent.change(screen.getByLabelText("返信内容"), { target: { value: message } });
    fireEvent.click(screen.getByRole("button", { name: "返信要求を保存" }));
    await waitFor(() => expect(reply).toHaveBeenCalledWith(contactId, { message }, expect.any(String)));
    await screen.findByText("返信要求を記録しました。");
  });

  it.each([0, 1, 2, 3])("shows all %i status entries without expansion", async (count) => {
    vi.spyOn(AdminApiClient.prototype, "getContactInquiry").mockResolvedValue({ ...detail(), status_history: statusHistory().slice(0, count) });
    render(<ContactManagementWorkspace contactId={contactId} mode="detail" />);
    const history = await screen.findByRole("region", { name: "対応状況履歴" });
    expect(within(history).queryAllByRole("listitem")).toHaveLength(count);
    expect(screen.queryByRole("button", { name: "さらに表示" })).not.toBeInTheDocument();
    if (!count) expect(within(history).getByText("対応状況履歴はありません。")).toBeVisible();
  });

  it("shows the latest three status entries and all entries in a closable modal, using Japanese labels and JST", async () => {
    const fixture = { ...detail(), status_history: statusHistory() };
    vi.spyOn(AdminApiClient.prototype, "getContactInquiry").mockResolvedValue(fixture);
    render(<ContactManagementWorkspace contactId={contactId} mode="detail" />);
    const history = await screen.findByRole("region", { name: "対応状況履歴" });
    expect(within(history).getAllByRole("listitem").map((item) => item.querySelector("strong")?.textContent)).toEqual(["完了", "返信済み", "対応中"]);
    expect(history.querySelector("time")?.textContent).toBe("2026/08/05 12:00");
    const more = screen.getByRole("button", { name: "さらに表示" });
    more.focus();
    fireEvent.click(more);
    const dialog = screen.getByRole("dialog", { name: "対応状況履歴" });
    expect(dialog).toHaveAttribute("aria-modal", "true");
    expect(within(dialog).getAllByRole("listitem").map((item) => item.querySelector("strong")?.textContent)).toEqual(["完了", "返信済み", "対応中", "未対応"]);
    const close = within(dialog).getByRole("button", { name: "対応状況履歴を閉じる" });
    expect(close).toHaveFocus();
    fireEvent.keyDown(close, { key: "Tab" });
    expect(close).toHaveFocus();
    fireEvent.keyDown(close, { key: "Escape" });
    expect(screen.queryByRole("dialog")).not.toBeInTheDocument();
    expect(more).toHaveFocus();
    fireEvent.click(more);
    fireEvent.click(screen.getByRole("button", { name: "対応状況履歴を閉じる" }));
    expect(screen.queryByRole("dialog")).not.toBeInTheDocument();
    expect(fixture.status_history).toEqual(statusHistory());
  });

  it("preserves status selection and canonical status update", async () => {
    vi.spyOn(AdminApiClient.prototype, "getContactInquiry").mockResolvedValue(detail());
    const update = vi.spyOn(AdminApiClient.prototype, "updateContactInquiryStatus").mockResolvedValue({ id: contactId, status: "replied", updated_at: "2026-08-05T02:00:00Z" });
    render(<ContactManagementWorkspace contactId={contactId} mode="detail" />);
    fireEvent.change(await screen.findByLabelText("次の状態"), { target: { value: "replied" } });
    fireEvent.click(screen.getByRole("button", { name: "状態を更新" }));
    await waitFor(() => expect(update).toHaveBeenCalledWith(contactId, { reason_code: "admin_marked_replied", status: "replied" }, expect.any(String)));
    expect(await screen.findByText("対応状態を更新しました。")).toBeVisible();
  });

  it("keeps operator read-only and hides mutation controls", async () => {
    effectivePermissions = new Set(["contact.read"]);
    vi.spyOn(AdminApiClient.prototype, "getContactInquiry").mockResolvedValue(detail());
    render(<ContactManagementWorkspace contactId={contactId} mode="detail" />);

    expect(await screen.findByText("このアカウントは参照のみです。")).toBeVisible();
    expect(screen.queryByRole("button", { name: "返信要求を保存" })).not.toBeInTheDocument();
    expect(screen.queryByRole("button", { name: "状態を更新" })).not.toBeInTheDocument();
    expect(screen.getByRole("region", { name: "対応状況履歴" })).toBeVisible();
    expect(screen.getByRole("region", { name: "内部メモ" })).toBeVisible();
  });
});

function statusHistory(): AdminContactDetail["status_history"] {
  return [
    { from_status: "new", to_status: "in_progress", occurred_at: "2026-08-05T10:00:00+09:00", reason_code: "progress" },
    { from_status: null, to_status: "new", occurred_at: "2026-08-05T00:00:00Z", reason_code: "received" },
    { from_status: "replied", to_status: "closed", occurred_at: "2026-08-05T03:00:00Z", reason_code: "closed" },
    { from_status: "in_progress", to_status: "replied", occurred_at: "2026-08-05T02:00:00Z", reason_code: "replied" },
  ];
}

function summary(): AdminContactSummary {
  return {
    authenticated: true,
    body_excerpt: "お問い合わせ内容です。",
    email: "user@example.test",
    id: contactId,
    name: "山田 太郎",
    phone: "09000000000",
    receipt_code: "CNT-ABCDEFGHIJKLMNOPQRST",
    received_at: "2026-08-05T00:00:00Z",
    status: "new",
    updated_at: "2026-08-05T00:00:00Z",
  };
}

function detail(): AdminContactDetail {
  return {
    authenticated: true,
    body: "お問い合わせ内容です。",
    closed_at: null,
    email: "user@example.test",
    id: contactId,
    internal_notes: [],
    name: "山田 太郎",
    phone: "09000000000",
    receipt_code: "CNT-ABCDEFGHIJKLMNOPQRST",
    received_at: "2026-08-05T00:00:00Z",
    reply_requests: [],
    status: "new",
    status_history: [{
      from_status: null,
      occurred_at: "2026-08-05T00:00:00Z",
      reason_code: "contact_received",
      to_status: "new",
    }],
    subject: "お問い合わせ件名",
    updated_at: "2026-08-05T00:00:00Z",
  };
}
