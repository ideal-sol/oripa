import { fireEvent, render, screen, waitFor, within } from "@testing-library/react";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

import { AnnouncementManagementWorkspace } from "@/components/announcements/announcement-management-workspace";
import { AdminApiClient } from "@/lib/admin-api/client";
import type {
  AdminContentDetail,
  AdminContentPreview,
  AdminContentSummary,
} from "@/lib/admin-api/generated";

const push = vi.fn();
const refresh = vi.fn();
const noticeId = uuid("1");
const versionId = uuid("2");

vi.mock("next/navigation", () => ({
  useRouter: () => ({ push, refresh }),
}));
vi.mock("@/components/shell/admin-shell", () => ({
  AdminShell: ({ children }: { children: React.ReactNode }) => <>{children}</>,
}));
vi.mock("@/components/permissions/protected-admin-route", () => ({
  ProtectedAdminRoute: ({ children }: { children: React.ReactNode }) => <>{children}</>,
}));
vi.mock("@/components/permissions/permission-provider", () => ({
  usePermissions: () => ({
    hasPermission: () => true,
    permissions: new Set(["content.read", "content.manage"]),
    role: "admin",
    status: "ready",
  }),
}));

beforeEach(() => {
  push.mockReset();
  refresh.mockReset();
  vi.spyOn(AdminApiClient.prototype, "listCatalogPresentationAssets")
    .mockResolvedValue({ items: [], next_cursor: null });
});

afterEach(() => vi.restoreAllMocks());

describe("Announcement management", () => {
  it("renders the V1-derived list order, publication state, preview, and cursor", async () => {
    const list = vi.spyOn(AdminApiClient.prototype, "listContentNotices")
      .mockResolvedValueOnce({ items: [summary()], next_cursor: "opaque-next" })
      .mockResolvedValueOnce({ items: [], next_cursor: null });

    render(<AnnouncementManagementWorkspace mode="list" />);

    expect(await screen.findByRole("heading", { name: "お知らせ一覧" })).toBeVisible();
    expect(screen.getAllByRole("columnheader").map((cell) => cell.textContent)).toEqual([
      "ID", "サムネイル", "カテゴリ", "タイトル", "公開状態",
      "公開開始日時", "公開終了日時", "更新日時", "プレビュー", "編集",
    ]);
    expect(screen.getByText(noticeId)).toBeVisible();
    expect(screen.getAllByText("公開")).toHaveLength(2);
    expect(screen.getByText("お知らせ")).toBeVisible();
    expect(screen.getByRole("link", { name: "運用のお知らせを編集" }))
      .toHaveAttribute("href", `/announcements/${noticeId}`);
    expect(screen.getByLabelText("公開状態")).toHaveValue("published,draft");
    expect(list).toHaveBeenCalledWith(
      { cursor: undefined, status: "published,draft" },
      expect.any(AbortSignal),
    );

    fireEvent.click(screen.getByRole("button", { name: "運用のお知らせをプレビュー" }));
    const dialog = screen.getByRole("dialog", { name: "運用のお知らせ" });
    expect(within(dialog).getByText("安全な本文")).toBeVisible();
    fireEvent.click(within(dialog).getByRole("button", { name: "プレビューを閉じる" }));

    fireEvent.click(screen.getByRole("button", { name: "次へ" }));
    await waitFor(() => expect(list).toHaveBeenLastCalledWith(
      { cursor: "opaque-next", status: "published,draft" },
      expect.any(AbortSignal),
    ));

    fireEvent.change(screen.getByLabelText("公開状態"), { target: { value: "archived" } });
    await waitFor(() => expect(list).toHaveBeenLastCalledWith(
      { cursor: undefined, status: "archived" },
      expect.any(AbortSignal),
    ));
  });

  it("previews sanitized server output and creates a published notice once", async () => {
    const preview = vi.spyOn(AdminApiClient.prototype, "previewContentNotice")
      .mockResolvedValue(previewResponse());
    const create = vi.spyOn(AdminApiClient.prototype, "createContentNotice")
      .mockResolvedValue(detail());
    const publish = vi.spyOn(AdminApiClient.prototype, "publishContentNotice")
      .mockResolvedValue({ ...detail(), status: "published" });

    render(<AnnouncementManagementWorkspace mode="create" />);
    await screen.findByRole("heading", { name: "お知らせ登録" });
    fireEvent.change(screen.getByLabelText("タイトル"), {
      target: { value: "運用のお知らせ" },
    });
    fireEvent.change(screen.getByLabelText("本文（HTML）"), {
      target: { value: "<p>安全な本文</p><script>alert(1)</script>" },
    });
    fireEvent.change(screen.getByLabelText("公開状態"), {
      target: { value: "published" },
    });

    fireEvent.click(screen.getByRole("button", { name: "プレビュー" }));
    await waitFor(() => expect(preview).toHaveBeenCalledOnce());
    expect(screen.getByRole("dialog", { name: "運用のお知らせ" })).toHaveTextContent("安全な本文");
    expect(screen.queryByText("alert(1)")).not.toBeInTheDocument();
    fireEvent.click(screen.getByRole("button", { name: "プレビューを閉じる" }));

    fireEvent.click(screen.getByRole("button", { name: "登録" }));
    await waitFor(() => expect(create).toHaveBeenCalledOnce());
    expect(create.mock.calls[0]?.[1]).toMatch(/^[0-9a-f-]{36}$/u);
    await waitFor(() => expect(publish).toHaveBeenCalledWith(noticeId, versionId));
    expect(push).toHaveBeenCalledWith(`/announcements/${noticeId}`);
  });

  it("rejects an inverted publication period before contacting the API", async () => {
    const preview = vi.spyOn(AdminApiClient.prototype, "previewContentNotice");
    render(<AnnouncementManagementWorkspace mode="create" />);
    await screen.findByRole("heading", { name: "お知らせ登録" });
    fireEvent.change(screen.getByLabelText("タイトル"), { target: { value: "期間確認" } });
    fireEvent.change(screen.getByLabelText("本文（HTML）"), { target: { value: "<p>本文</p>" } });
    fireEvent.change(screen.getByLabelText("公開終了日時（Asia/Tokyo）"), {
      target: { value: "2020-01-01T00:00" },
    });
    fireEvent.click(screen.getByRole("button", { name: "プレビュー" }));

    expect(await screen.findByText("公開終了日時は公開開始日時より後にしてください。")).toBeVisible();
    expect(preview).not.toHaveBeenCalled();
  });
});

function summary(): AdminContentSummary {
  return {
    created_at: "2026-08-01T00:00:00Z",
    id: noticeId,
    identifier: "notice-fixture",
    is_legal: false,
    latest_version: version(),
    published_version_id: versionId,
    status: "published",
    updated_at: "2026-08-04T00:00:00Z",
  };
}

function detail(): AdminContentDetail {
  return {
    id: noticeId,
    identifier: "notice-fixture",
    is_legal: false,
    status: "draft",
    versions: [version()],
  };
}

function version() {
  return {
    asset_id: null,
    body_html: "<p>安全な本文</p>",
    checksum_sha256: "a".repeat(64),
    id: versionId,
    is_important: true,
    link_url: null,
    publish_end_at: "2026-08-31T14:59:59Z",
    publish_start_at: "2026-08-01T00:00:00Z",
    published_at: "2026-08-01T00:00:00Z",
    sort_order: 0,
    status: "published" as const,
    summary: null,
    title: "運用のお知らせ",
    version_number: 1,
  };
}

function previewResponse(): AdminContentPreview {
  return {
    asset_id: null,
    body_html: "<p>安全な本文</p>",
    is_important: false,
    publish_end_at: null,
    publish_start_at: "2026-08-04T00:00:00Z",
    summary: null,
    title: "運用のお知らせ",
  };
}

function uuid(last: string): string {
  return `01910191-0191-7191-8191-01910191019${last}`;
}
