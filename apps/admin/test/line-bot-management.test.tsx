import { fireEvent, render, screen, waitFor } from "@testing-library/react";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import type { LineBot } from "@/lib/admin-api/generated";

const access = vi.hoisted(() => ({ role: "owner" }));
vi.mock("@/components/permissions/permission-provider", () => ({
  usePermissions: () => ({ permissions: new Set(access.role === "owner" ? ["line.bot.read", "line.bot.manage"] : access.role === "admin" ? ["line.bot.read"] : []) }),
}));
import { LineBotManagement } from "@/components/line/line-bot-management";
import { AdminApiClient, AdminApiError } from "@/lib/admin-api/client";

const bot: LineBot = {
  id: "01910191-0191-7191-8191-019101910191", name: "検証BOT", channel_id: null,
  secret_configured: false, basic_id: null, display_name: null, is_active: false,
  legacy: true, connection_status: "unconfigured", connected_at: null,
  quota_snapshot: null, quota_fetched_at: null, followers_snapshot: null,
  followers_fetched_at: null, revision: 1, webhook_path: "/webhooks/v2/line/01910191-0191-7191-8191-019101910191",
  webhook_url: "https://public.example.test/webhooks/v2/line/01910191-0191-7191-8191-019101910191",
};
describe("LINE BOT configuration", () => {
  beforeEach(() => {
    access.role = "owner";
    vi.spyOn(AdminApiClient.prototype, "getLineBots").mockResolvedValue({items: [bot]});
    vi.spyOn(AdminApiClient.prototype, "getLineLoginSetting").mockResolvedValue({
      channel_id: "1234567", secret_configured: true, source: "database", revision: 3,
    });
  });
  afterEach(() => vi.restoreAllMocks());

  it("displays and copies the complete HTTPS webhook URL", async () => {
    const writeText = vi.fn().mockResolvedValue(undefined);
    Object.defineProperty(navigator, "clipboard", {configurable: true, value: {writeText}});
    render(<LineBotManagement />);
    expect(await screen.findByLabelText("Webhook URL")).toHaveValue(bot.webhook_url);
    fireEvent.click(screen.getByRole("button", {name: "URLをコピー"}));
    await waitFor(() => expect(writeText).toHaveBeenCalledWith(bot.webhook_url));
    expect(await screen.findByText(/LINE Developersに貼り付けてください/)).toBeVisible();
  });

  it("does not invent a URL when the public Origin is unavailable", async () => {
    vi.mocked(AdminApiClient.prototype.getLineBots).mockResolvedValue({items: [{...bot, webhook_url: null}]});
    render(<LineBotManagement />);
    expect(await screen.findByLabelText("Webhook URL")).toHaveValue("");
    expect(screen.getByRole("button", {name: "URLをコピー"})).toBeDisabled();
    expect(screen.getByText(/Webhook URLを表示できません/)).toBeVisible();
  });

  it("shows missing configuration and never fetches provider snapshots automatically", async () => {
    const action = vi.spyOn(AdminApiClient.prototype, "lineBotAction");
    render(<LineBotManagement />);
    expect(await screen.findByText(/未設定・Human入力待ち/)).toBeVisible();
    expect(screen.getByRole("button", {name: "このBOTを有効化"})).toBeDisabled();
    expect(screen.queryByLabelText(/アクセストークン/)).toBeNull();
    expect(screen.getByLabelText("新しいログインChannel secret")).toHaveValue("");
    expect(action).not.toHaveBeenCalled();
    expect(screen.getByText(/自動変更されません/)).toBeVisible();
  });

  it("submits secret and revision once then clears the field on a conflict", async () => {
    const update = vi.spyOn(AdminApiClient.prototype, "updateLineLoginSetting").mockRejectedValue(
      new AdminApiError(409, "LINE_CONFIGURATION_REVISION_CONFLICT", bot.id, null, false),
    );
    render(<LineBotManagement />);
    await screen.findByDisplayValue("1234567");
    fireEvent.change(screen.getByLabelText("新しいログインChannel secret"), {target: {value: "synthetic-ui-secret"}});
    fireEvent.click(screen.getByRole("button", {name: "ログイン設定を保存"}));
    await waitFor(() => expect(update).toHaveBeenCalledWith({
      channel_id: "1234567", channel_secret: "synthetic-ui-secret", expected_revision: 3,
    }, expect.any(String)));
    expect(await screen.findByRole("alert")).toHaveTextContent("再読込");
    expect(screen.getByLabelText("新しいログインChannel secret")).toHaveValue("");
    expect(document.body.textContent).not.toContain("synthetic-ui-secret");
  });

  it("offers existing MFA reauthentication without replaying a secret mutation", async () => {
    const update = vi.spyOn(AdminApiClient.prototype, "updateLineLoginSetting").mockRejectedValue(
      new AdminApiError(403, "FRESH_MFA_REQUIRED", bot.id, null, false),
    );
    const reauthenticate = vi.spyOn(AdminApiClient.prototype, "reauthenticate").mockResolvedValue({} as never);
    render(<LineBotManagement />);
    await screen.findByDisplayValue("1234567");
    fireEvent.change(screen.getByLabelText("新しいログインChannel secret"), {target: {value: "synthetic-ui-secret"}});
    fireEvent.click(screen.getByRole("button", {name: "ログイン設定を保存"}));
    fireEvent.change(await screen.findByLabelText("認証アプリのコード"), {target: {value: "123456"}});
    fireEvent.click(screen.getByRole("button", {name: "認証アプリで再認証"}));
    await waitFor(() => expect(reauthenticate).toHaveBeenCalledWith({method: "totp", code: "123456"}));
    expect(update).toHaveBeenCalledOnce();
    await waitFor(() => expect(screen.queryByLabelText("認証アプリのコード")).toBeNull());
  });

  it("keeps admin read-only and hides the panel from operators", async () => {
    access.role = "admin";
    const view = render(<LineBotManagement />);
    await screen.findByDisplayValue("1234567");
    expect(screen.getByLabelText("新しいログインChannel secret")).toBeDisabled();
    expect(screen.getByRole("button", {name: "接続を確認"})).toBeDisabled();
    view.unmount();
    access.role = "operator";
    render(<LineBotManagement />);
    expect(screen.queryByRole("region", {name: "LINEログインとBOT管理"})).toBeNull();
  });
});
