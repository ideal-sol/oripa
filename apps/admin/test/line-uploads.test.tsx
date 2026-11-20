import { fireEvent, render, screen } from "@testing-library/react";
import { afterEach, beforeEach, expect, it, vi } from "vitest";
const access = vi.hoisted(() => ({ owner: false }));
vi.mock("@/components/permissions/permission-provider", () => ({ usePermissions: () => ({ permissions: new Set(access.owner ? ["line.chat.read", "line.bot.manage"] : ["line.chat.read"]) }) }));
import { LineUploadWorkspace } from "@/components/line/line-upload-workspace";
import { LineMessageEditor, newLineMessage } from "@/components/line/line-message-editor";
import { AdminApiClient } from "@/lib/admin-api/client";
import { activeNavigationItem, ADMIN_NAVIGATION, navigationForPermissions, navigationGroupForRoute } from "@/lib/permissions/admin-navigation";

beforeEach(() => {
  access.owner = false;
  vi.spyOn(AdminApiClient.prototype, "lineChatMedia").mockResolvedValue({ data: [{ id:"synthetic", kind:"image", width:100, height:100, original_url:"https://cdn.example.test/original", preview_url:"https://cdn.example.test/preview" }], next_page:2 });
});
afterEach(() => vi.restoreAllMocks());
it("places five LINE links immediately after announcements and activates chat details", () => {
  const index = ADMIN_NAVIGATION.findIndex((n) => n.id === "announcements");
  const line = ADMIN_NAVIGATION[index + 1];
  expect(line.id).toBe("line");
  if (line.kind !== "group") throw new Error("Missing group");
  expect(line.children.map((c) => c.path)).toEqual(["/settings/line","/line-chat","/line-templates","/line-deliveries","/line-uploads"]);
  const visible = navigationForPermissions(new Set(["line.chat.read"]));
  expect(activeNavigationItem("/line-chat/synthetic", visible)?.id).toBe("line-chat");
  expect(navigationGroupForRoute("line-chat")?.id).toBe("line");
  expect(visible.find((n) => n.id === "line")?.kind).toBe("group");
});
it("allows library browsing and pagination without granting non-Owner uploads", async () => {
  render(<LineUploadWorkspace />);
  expect(await screen.findByAltText("画像")).toHaveAttribute("src","https://cdn.example.test/preview");
  expect(document.querySelector('input[type="file"]')).toBeNull();
  fireEvent.click(screen.getByRole("button",{name:"次のページ"}));
  await screen.findByAltText("画像");
  expect(AdminApiClient.prototype.lineChatMedia).toHaveBeenCalledWith(2);
});
it("provides Owner uploads for all four media kinds", async () => {
  access.owner = true; render(<LineUploadWorkspace />); await screen.findByAltText("画像");
  for (const kind of ["image","video","imagemap","carousel"]) {
    fireEvent.change(screen.getByLabelText("メディア種別"),{target:{value:kind}});
    expect(screen.getByLabelText("アップロードするファイル")).toBeVisible();
    expect(screen.getByRole("button",{name:"アップロードして実体検証"})).toBeDisabled();
  }
});
it("uses selection only in every message editor format", () => {
  for (const kind of ["image","video","imagemap","image_carousel"] as const) {
    const view=render(<LineMessageEditor client={new AdminApiClient()} messages={[newLineMessage(kind)]} onChange={vi.fn()} />);
    expect(document.querySelector('input[type="file"]')).toBeNull();
    expect(screen.getByRole("button",{name:"メディアを読み込む"})).toBeVisible(); view.unmount();
  }
});
