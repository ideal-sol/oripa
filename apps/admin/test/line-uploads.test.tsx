import { act, fireEvent, render, screen, waitFor } from "@testing-library/react";
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
afterEach(() => { vi.restoreAllMocks(); vi.unstubAllGlobals(); });
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
  expect(AdminApiClient.prototype.lineChatMedia).toHaveBeenCalledWith(2, "image");
});
it("resets type paging, shows request errors separately from empty and reloads the newest page", async () => {
  render(<LineUploadWorkspace />); await screen.findByAltText("画像");
  fireEvent.click(screen.getByRole("button", { name: "次のページ" })); await screen.findByAltText("画像");
  vi.mocked(AdminApiClient.prototype.lineChatMedia).mockRejectedValueOnce(new Error("synthetic"));
  fireEvent.change(screen.getByLabelText("メディア種別"), { target: { value: "video" } });
  expect(await screen.findByRole("alert")).toHaveTextContent("メディア一覧を取得できませんでした");
  expect(AdminApiClient.prototype.lineChatMedia).toHaveBeenLastCalledWith(1, "video");
  expect(screen.queryByText("この種類のメディアはまだ登録されていません。")).toBeNull();
  vi.mocked(AdminApiClient.prototype.lineChatMedia).mockResolvedValueOnce({ data: [], next_page: null });
  fireEvent.click(screen.getByRole("button", { name: "一覧を再読込" }));
  expect(await screen.findByText("この種類のメディアはまだ登録されていません。")).toBeVisible();
});
it("resets selection candidates on type changes and refuses a stale BOT at selection", async () => {
  const onChange=vi.fn(); const client=new AdminApiClient();
  const asset={ id:"old", kind:"image" as const, width:100, height:100, original_url:"https://cdn.example.test/o", preview_url:"https://cdn.example.test/p" };
  vi.mocked(AdminApiClient.prototype.lineChatMedia).mockResolvedValue({data:[asset],next_page:2,bot_id:"bot-one",authentication_revision:1});
  const view=render(<LineMessageEditor client={client} messages={[newLineMessage("image")]} onChange={onChange} />);
  fireEvent.click(screen.getByRole("button",{name:"メディアを読み込む"})); await screen.findByRole("button",{name:/100×100 を選択/});
  vi.mocked(AdminApiClient.prototype.lineChatMedia).mockResolvedValueOnce({data:[],next_page:null,bot_id:"bot-two",authentication_revision:1});
  fireEvent.click(screen.getByRole("button",{name:/100×100 を選択/}));
  expect(await screen.findByRole("alert")).toHaveTextContent("BOTが変更されました"); expect(onChange).not.toHaveBeenCalled();
  view.rerender(<LineMessageEditor client={client} messages={[newLineMessage("video")]} onChange={onChange} />);
  expect(screen.queryByRole("button",{name:/100×100 を選択/})).toBeNull();
  vi.mocked(AdminApiClient.prototype.lineChatMedia).mockResolvedValueOnce({data:[],next_page:null});
  fireEvent.click(screen.getByRole("button",{name:"メディアを読み込む"}));
  await waitFor(()=>expect(AdminApiClient.prototype.lineChatMedia).toHaveBeenLastCalledWith(1,"video"));
});
it("keeps loading instead of showing an empty library while a changed BOT resets paging", async () => {
  const asset={id:"old",kind:"image" as const,width:100,height:100,original_url:"https://cdn.example.test/o",preview_url:"https://cdn.example.test/p"};
  let finish!: (value: Awaited<ReturnType<AdminApiClient["lineChatMedia"]>>) => void;
  vi.mocked(AdminApiClient.prototype.lineChatMedia)
    .mockResolvedValueOnce({data:[asset],next_page:2,bot_id:"bot-one",authentication_revision:1})
    .mockResolvedValueOnce({data:[],next_page:null,bot_id:"bot-two",authentication_revision:1})
    .mockImplementationOnce(()=>new Promise(resolve=>{finish=resolve;}));
  render(<LineUploadWorkspace />); await screen.findByAltText("画像");
  fireEvent.click(screen.getByRole("button",{name:"次のページ"}));
  await waitFor(()=>expect(AdminApiClient.prototype.lineChatMedia).toHaveBeenCalledTimes(3));
  try {
    expect(screen.getByRole("status")).toHaveTextContent("読み込み中");
    expect(screen.queryByText("この種類のメディアはまだ登録されていません。")).toBeNull();
    expect(screen.getByRole("button",{name:"一覧を再読込"})).toBeDisabled();
  } finally {
    await act(async()=>finish({data:[],next_page:null,bot_id:"bot-two",authentication_revision:1}));
  }
  expect(await screen.findByText("この種類のメディアはまだ登録されていません。")).toBeVisible();
});
it("provides Owner uploads for all four media kinds", async () => {
  access.owner = true; render(<LineUploadWorkspace />); await screen.findByAltText("画像");
  for (const kind of ["image","video","imagemap","carousel"]) {
    fireEvent.change(screen.getByLabelText("メディア種別"),{target:{value:kind}});
    expect(screen.getByLabelText("アップロードするファイル")).toBeVisible();
    expect(screen.getByRole("button",{name:"アップロードして実体検証"})).toBeDisabled();
  }
});
it("returns to the first page after successful upload and shows the new asset first", async () => {
  access.owner=true;
  const uploaded={id:"new",kind:"image" as const,width:200,height:200,original_url:"https://cdn.example.test/new",preview_url:"https://cdn.example.test/new-preview"};
  vi.spyOn(AdminApiClient.prototype,"createLineMedia").mockResolvedValue({id:"intent",expires_at:"2026-11-20T00:05:00Z",uploads:[{slot:"original",url:"https://upload.example.test/fixture",headers:{}}]});
  vi.spyOn(AdminApiClient.prototype,"finalizeLineMedia").mockResolvedValue(uploaded);
  vi.stubGlobal("crypto",{subtle:{digest:vi.fn().mockResolvedValue(new ArrayBuffer(32))}});
  vi.stubGlobal("fetch",vi.fn().mockResolvedValue({ok:true}));
  render(<LineUploadWorkspace />); await screen.findByAltText("画像");
  fireEvent.click(screen.getByRole("button",{name:"次のページ"})); await screen.findByAltText("画像");
  vi.mocked(AdminApiClient.prototype.lineChatMedia).mockResolvedValueOnce({data:[uploaded],next_page:null});
  const file=new File(["synthetic"],"fixture.png",{type:"image/png"}); Object.defineProperty(file,"arrayBuffer",{value:async()=>new ArrayBuffer(9)});
  fireEvent.change(screen.getByLabelText("アップロードするファイル"),{target:{files:[file]}});
  fireEvent.click(screen.getByRole("button",{name:"アップロードして実体検証"}));
  await waitFor(()=>expect(screen.getByAltText("画像")).toHaveAttribute("src",uploaded.preview_url));
  expect(AdminApiClient.prototype.lineChatMedia).toHaveBeenLastCalledWith(1,"image"); expect(screen.getByText("1ページ")).toBeVisible();
});
it("uses selection only in every message editor format", () => {
  for (const kind of ["image","video","imagemap","image_carousel"] as const) {
    const view=render(<LineMessageEditor client={new AdminApiClient()} messages={[newLineMessage(kind)]} onChange={vi.fn()} />);
    expect(document.querySelector('input[type="file"]')).toBeNull();
    expect(screen.getByRole("button",{name:"メディアを読み込む"})).toBeVisible(); view.unmount();
  }
});
