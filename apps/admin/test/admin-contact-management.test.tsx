import { fireEvent, render, screen, waitFor } from "@testing-library/react";
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

    fireEvent.change(screen.getByLabelText("状態"), { target: { value: "new" } });
    fireEvent.change(screen.getByLabelText("メール"), { target: { value: "user@example.test" } });
    fireEvent.click(screen.getByRole("button", { name: "検索" }));
    await waitFor(() => expect(list).toHaveBeenLastCalledWith(
      { cursor: undefined, email: "user@example.test", status: "new" },
      expect.any(AbortSignal),
    ));
  });

  it("queues one reply request and refreshes canonical detail", async () => {
    const get = vi.spyOn(AdminApiClient.prototype, "getContactInquiry")
      .mockResolvedValue(detail());
    const reply = vi.spyOn(AdminApiClient.prototype, "requestContactInquiryReply")
      .mockResolvedValue({ id: "01910191-0191-7191-8191-019101910192", status: "queued" });
    render(<ContactManagementWorkspace contactId={contactId} mode="detail" />);

    expect(await screen.findByText("お問い合わせ内容です。")).toBeVisible();
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

  it("keeps operator read-only and hides mutation controls", async () => {
    effectivePermissions = new Set(["contact.read"]);
    vi.spyOn(AdminApiClient.prototype, "getContactInquiry").mockResolvedValue(detail());
    render(<ContactManagementWorkspace contactId={contactId} mode="detail" />);

    expect(await screen.findByText("このアカウントは参照のみです。")).toBeVisible();
    expect(screen.queryByRole("button", { name: "返信要求を保存" })).not.toBeInTheDocument();
    expect(screen.queryByRole("button", { name: "状態を更新" })).not.toBeInTheDocument();
  });
});

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
