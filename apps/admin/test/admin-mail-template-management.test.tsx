import { fireEvent, render, screen, waitFor, within } from "@testing-library/react";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

import { MailTemplateWorkspace } from "@/components/mail/mail-template-workspace";
import { AdminApiClient } from "@/lib/admin-api/client";
import type {
  AdminMailTemplate,
  AdminMailTemplateVariable,
  MailTemplateKey,
} from "@/lib/admin-api/generated";

vi.mock("next/navigation", () => ({ useRouter: () => ({ push: vi.fn(), refresh: vi.fn() }) }));
vi.mock("@/components/shell/admin-shell", () => ({ AdminShell: ({ children }: { children: React.ReactNode }) => <>{children}</> }));
vi.mock("@/components/permissions/protected-admin-route", () => ({ ProtectedAdminRoute: ({ children }: { children: React.ReactNode }) => <>{children}</> }));
vi.mock("@/components/permissions/permission-provider", () => ({ usePermissions: () => ({ permissions: new Set(["content.read", "content.manage"]), role: "admin", status: "ready" }) }));

const variables: AdminMailTemplateVariable[] = [
  ["user_name", "ユーザー名"],
  ["full_name", "氏名"],
  ["address", "住所"],
  ["phone_number", "電話番号"],
  ["gacha_names", "ガチャ名"],
  ["prize_names", "景品名"],
  ["purchase_plan", "コイン購入プラン"],
  ["purchase_amount", "購入金額"],
  ["purchase_date", "購入日"],
  ["payment_method", "お支払方法"],
  ["acquired_coins", "獲得コイン"],
  ["verification_url", "認証リンク"],
  ["reset_url", "パスワード再設定リンク"],
  ["email_change_verification_url", "メールアドレス変更認証リンク"],
  ["expires_in_minutes", "有効期限（分）"],
  ["contact_body", "お問い合わせ内容"],
].map(([key, label]) => ({ key, label, token: `{{${key}}}` }));

const keys: MailTemplateKey[] = [
  "email_verification",
  "registration_completed",
  "coin_purchase_completed",
  "shipping_requested",
  "shipping_completed",
  "user_closed",
  "contact_received",
  "password_reset",
  "email_change_verification",
  "email_change_completed",
  "password_changed",
  "phone_changed",
  "agency_account_created",
  "agency_password_changed",
  "agency_login_information_reissued",
];

beforeEach(() => {
  vi.spyOn(AdminApiClient.prototype, "listMailTemplates").mockResolvedValue({
    items: keys.map((key, index) => template(key, `メール${index + 1}`)),
  });
  vi.spyOn(AdminApiClient.prototype, "getMailTemplate")
    .mockResolvedValue(template("shipping_requested", "発送依頼時"));
});

afterEach(() => vi.restoreAllMocks());

describe("Mail Template management", () => {
  it("shows exactly fifteen fixed templates without create or delete controls", async () => {
    render(<MailTemplateWorkspace />);

    const table = await screen.findByRole("table");
    expect(within(table).getAllByRole("row")).toHaveLength(16);
    expect(within(table).getAllByRole("link", { name: /を編集$/u })).toHaveLength(15);
    expect(screen.queryByRole("button", { name: /新規|追加|削除/u })).not.toBeInTheDocument();
    expect(screen.getByRole("heading", { name: "メール設定" })).toBeVisible();
  });

  it("inserts variables at current cursors, previews unsaved body in another tab, and saves with OCC", async () => {
    const preview = vi.spyOn(AdminApiClient.prototype, "previewMailTemplate")
      .mockResolvedValue({ body_html: "<h2 onclick=\"bad()\">サンプルユーザー</h2><p>景品A</p><hr><p>景品B</p><script>bad()</script><img src=\"javascript:bad()\">" });
    const update = vi.spyOn(AdminApiClient.prototype, "updateMailTemplate")
      .mockImplementation(async (_key, input) => ({
        ...template("shipping_requested", "発送依頼時"),
        body_html: input.body_html,
        idempotent_replay: false,
        revision: 2,
        subject: input.subject,
      }));
    const previewDocument = document.implementation.createHTMLDocument("preview");
    const previewWindow = {
      close: vi.fn(),
      document: previewDocument,
      opener: window,
    } as unknown as Window;
    vi.spyOn(window, "open").mockReturnValue(previewWindow);

    render(<MailTemplateWorkspace templateKey="shipping_requested" />);

    const subject = await screen.findByLabelText("件名");
    subject.focus();
    (subject as HTMLInputElement).setSelectionRange(0, 0);
    fireEvent.change(screen.getByLabelText("件名へ変数を挿入"), { target: { value: "{{user_name}}" } });
    expect(subject).toHaveValue("{{user_name}}発送依頼を受け付けました");

    const editor = screen.getByLabelText("メール本文");
    editor.focus();
    fireEvent.change(screen.getByLabelText("本文へ変数を挿入"), { target: { value: "{{prize_names}}" } });
    await waitFor(() => expect(editor.innerHTML).toContain("{{prize_names}}"));
    expect(screen.getByRole("toolbar", { name: "メール本文の書式" })).toBeVisible();
    expect(screen.getByRole("button", { name: "画像URL" })).toBeVisible();

    fireEvent.click(screen.getByRole("button", { name: "プレビュー" }));
    await waitFor(() => expect(preview).toHaveBeenCalledOnce());
    expect(window.open).toHaveBeenCalledWith("about:blank", "_blank");
    expect(preview.mock.calls[0]?.[0]).toBe("shipping_requested");
    expect(preview.mock.calls[0]?.[1].body_html).toContain("{{prize_names}}");
    expect(previewDocument.body.innerHTML).toContain("サンプルユーザー");
    expect(previewDocument.body.innerHTML).toContain("<hr>");
    expect(previewDocument.body.innerHTML).not.toContain("onclick");
    expect(previewDocument.body.innerHTML).not.toContain("script");
    expect(previewDocument.body.innerHTML).not.toContain("javascript:");

    fireEvent.click(screen.getByRole("button", { name: "保存" }));
    await waitFor(() => expect(update).toHaveBeenCalledOnce());
    expect(update.mock.calls[0]?.[0]).toBe("shipping_requested");
    expect(update.mock.calls[0]?.[1]).toMatchObject({
      expected_revision: 1,
      subject: "{{user_name}}発送依頼を受け付けました",
    });
    expect(update.mock.calls[0]?.[2]).toMatch(/^[0-9a-f-]{36}$/u);
  });

  it("blocks semantic empty subject and body before saving", async () => {
    const update = vi.spyOn(AdminApiClient.prototype, "updateMailTemplate");
    render(<MailTemplateWorkspace templateKey="shipping_requested" />);

    const subject = await screen.findByLabelText("件名");
    fireEvent.change(subject, { target: { value: "　" } });
    const editor = screen.getByLabelText("メール本文");
    editor.innerHTML = "<p><br></p>";
    fireEvent.input(editor);
    fireEvent.click(screen.getByRole("button", { name: "保存" }));

    expect(await screen.findByRole("alert")).toHaveTextContent("件名と本文を入力してください。");
    expect(update).not.toHaveBeenCalled();
  });

  it("round trips HTML source, inserts tokens, saves, reloads and previews through the existing API", async () => {
    const update = vi.spyOn(AdminApiClient.prototype, "updateMailTemplate")
      .mockImplementation(async (_key, input) => ({
        ...template("coin_purchase_completed", "コイン購入完了時"),
        ...input, revision: 2, idempotent_replay: false,
      }));
    const preview = vi.spyOn(AdminApiClient.prototype, "previewMailTemplate")
      .mockResolvedValue({ body_html: "<p><strong>獲得コイン：</strong>10,000 コイン</p>" });
    const previewDocument = document.implementation.createHTMLDocument("preview");
    vi.spyOn(window, "open").mockReturnValue({ document: previewDocument, close: vi.fn() } as unknown as Window);
    const mounted = render(<MailTemplateWorkspace templateKey="coin_purchase_completed" />);
    const richText = await screen.findByLabelText("メール本文");
    const initialHtml = richText.innerHTML;
    fireEvent.click(screen.getByRole("button", { name: "HTML" }));
    const source = screen.getByRole("textbox", { name: "メール本文のHTMLソース" });
    expect(source).toHaveValue(initialHtml);
    const body = "<p>ご購入ありがとうございます。</p><p><strong>獲得コイン：</strong>{{acquired_coins}}</p>";
    fireEvent.change(source, { target: { value: body } });
    fireEvent.click(screen.getByRole("button", { name: "リッチテキスト" }));
    expect(screen.getByLabelText("メール本文").innerHTML).toBe(body);
    expect(screen.getByLabelText("メール本文").querySelector("strong")).toHaveTextContent("獲得コイン：");
    fireEvent.click(screen.getByRole("button", { name: "HTML" }));
    const sourceAgain = screen.getByRole("textbox", { name: "メール本文のHTMLソース" });
    expect(sourceAgain).toHaveValue(body);
    (sourceAgain as HTMLTextAreaElement).setSelectionRange(body.length, body.length);
    fireEvent.change(screen.getByLabelText("本文へ変数を挿入"), { target: { value: "{{purchase_date}}" } });
    expect(sourceAgain).toHaveValue(`${body}{{purchase_date}}`);
    fireEvent.click(screen.getByRole("button", { name: "保存" }));
    await screen.findByText("メールTemplateを保存しました。");
    expect(update.mock.calls[0]?.[1].body_html).toBe(`${body}{{purchase_date}}`);
    vi.mocked(AdminApiClient.prototype.getMailTemplate).mockResolvedValue({
      ...template("coin_purchase_completed", "コイン購入完了時"),
      body_html: `${body}{{purchase_date}}`, revision: 2,
    });
    mounted.unmount();
    render(<MailTemplateWorkspace templateKey="coin_purchase_completed" />);
    expect((await screen.findByLabelText("メール本文")).innerHTML).toContain("{{acquired_coins}}");
    fireEvent.click(screen.getByRole("button", { name: "HTML" }));
    fireEvent.click(screen.getByRole("button", { name: "プレビュー" }));
    await waitFor(() => expect(previewDocument.body.textContent).toContain("10,000 コイン"));
    expect(preview.mock.calls[0]?.[1].body_html).toContain("{{acquired_coins}}");
  });

  it("sends raw source through the same save and preview endpoints and accepts sanitized responses", async () => {
    const unsafe = '<div><script>bad()</script><p onclick="bad()">{{acquired_coins}}</p></div>';
    const safe = '<p>{{acquired_coins}}</p>';
    const update = vi.spyOn(AdminApiClient.prototype, "updateMailTemplate").mockResolvedValue({
      ...template("coin_purchase_completed", "コイン購入完了時"),
      body_html: safe, revision: 2, idempotent_replay: false,
    });
    const preview = vi.spyOn(AdminApiClient.prototype, "previewMailTemplate")
      .mockResolvedValue({ body_html: '<p onclick="bad()">10,000 コイン</p><script>bad()</script>' });
    const previewDocument = document.implementation.createHTMLDocument("preview");
    vi.spyOn(window, "open").mockReturnValue({ document: previewDocument, close: vi.fn() } as unknown as Window);
    render(<MailTemplateWorkspace templateKey="coin_purchase_completed" />);
    fireEvent.click(await screen.findByRole("button", { name: "HTML" }));
    fireEvent.change(screen.getByRole("textbox", { name: "メール本文のHTMLソース" }), { target: { value: unsafe } });
    fireEvent.click(screen.getByRole("button", { name: "プレビュー" }));
    await waitFor(() => expect(previewDocument.body.textContent).toContain("10,000 コイン"));
    expect(preview.mock.calls[0]?.[1].body_html).toBe(unsafe);
    expect(previewDocument.body.innerHTML).toBe("<p>10,000 コイン</p>");
    fireEvent.click(screen.getByRole("button", { name: "保存" }));
    await screen.findByText("メールTemplateを保存しました。");
    expect(update.mock.calls[0]?.[1].body_html).toBe(unsafe);
    expect(screen.getByRole("textbox", { name: "メール本文のHTMLソース" })).toHaveValue(safe);
  });
});

function template(key: MailTemplateKey, label: string): AdminMailTemplate {
  return {
    body_html: "<p>{{user_name}} 様</p><p>発送依頼を受け付けました。</p>",
    key,
    label,
    revision: 1,
    subject: "発送依頼を受け付けました",
    updated_at: "2026-08-24T05:00:00Z",
    variables,
  };
}
