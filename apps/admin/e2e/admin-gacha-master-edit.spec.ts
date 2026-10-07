import { expect, test, type Page, type Route } from "@playwright/test";

import type { AdminQaGachaGuaranteeAssignment } from "../src/lib/admin-api/generated";

const gachaCode = "A7k9P2x4Qm8";
const gachaUuid = uuid("1");
const versionId = uuid("2");
const categoryId = uuid("3");
const tagId = uuid("4");
const assetId = uuid("5");
const uploadedAssetId = uuid("6");
const rankId = uuid("7");
const prizeId = uuid("8");
const testUserId = uuid("0");
const assignmentId = "01910191-0191-7191-8191-019101910190";
const csrf = "a".repeat(64);

for (const width of [1440, 1366, 390]) {
  for (const mode of ["new", "edit"]) {
    test(`Form batch ${width}px gacha ${mode} category stays standard beside media`, async ({ page }) => {
      await page.setViewportSize({ width, height: 900 });
      await page.goto(mode === "new" ? "/catalog/gachas/new/standard" : `/gachas/${gachaCode}/edit`);
      const select = page.getByRole("combobox", { name: "カテゴリ", exact: true });
      await expect(select.getByRole("option", { name: "カード", exact: true })).toHaveCount(1);
      const title = page.getByLabel("ガチャタイトル");
      for (const dimensions of [null, { width: 360, height: 640 }, { width: 640, height: 360 }]) {
        if (dimensions) {
          const data = await page.evaluate((size) => {
            const canvas = document.createElement("canvas");
            canvas.width = size.width;
            canvas.height = size.height;
            return canvas.toDataURL("image/png").split(",")[1];
          }, dimensions);
          await page.getByLabel(/サムネイル画像/u).setInputFiles({ name: "layout.png", mimeType: "image/png", buffer: Buffer.from(data, "base64") });
          await expect(page.getByRole("img", { name: "選択したサムネイルのPreview" })).toBeVisible();
        }
        expect((await select.boundingBox())!.height).toBe((await title.boundingBox())!.height);
        expect((await select.boundingBox())!.height).toBeLessThan(50);
        await select.selectOption(categoryId);
        await expect(select).toHaveValue(categoryId);
        expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
      }
    });
  }

  test(`Form batch ${width}px prize editor scroll preserves actions`, async ({ page }) => {
    await page.setViewportSize({ width, height: 700 });
    await page.route("**/banner-management/categories*", (route) => json(route, { items: [], next_cursor: null }));
    await page.route(`**/admin/api/v2/catalog/gachas/${gachaCode}`, (route) => json(route, { data: { ...gacha(), first_published_at: null } }));
    const image = { id: assetId, path: `/admin/api/v2/catalog/presentation-assets/${assetId}/content`, alt_text: "ランク画像", media_type: "image", revision_number: 1 };
    await page.route(`**/catalog/gachas/${gachaCode}/ranks`, (route) => json(route, { items: [{
      rank: { id: rankId, rank_name: "S", lineup_image: image, result_image: image, show_total_stock: false, status: "active", display_order: 0, revision: 1, revision_number: 1 },
      gacha_rank_id: null, gacha_rank_revision: null, can_unset_video: false, current_video: null,
    }] }));
    await page.route("**/catalog/rank-effects*", (route) => json(route, { items: [], next_cursor: null }));
    await page.goto(`/catalog/gachas/${gachaCode}`);
    await page.getByRole("button", { name: "景品Sを編集" }).click();
    const dialog = page.getByRole("dialog", { name: "景品編集" });
    const body = dialog.locator(".catalog-dialog-body");
    const save = dialog.getByRole("button", { name: "保存", exact: true });
    const cancel = dialog.getByRole("button", { name: "キャンセル" });
    const close = dialog.getByRole("button", { name: "閉じる", exact: true });
    await expect(body.getByLabel("変更理由")).toHaveCount(1);
    expect(await body.evaluate((element) => element.scrollHeight > element.clientHeight)).toBe(true);
    const before = await save.boundingBox();
    await body.evaluate((element) => { element.scrollTop = element.scrollHeight; });
    expect(await save.boundingBox()).toEqual(before);
    for (const control of [save, cancel, close]) await expect(control).toBeInViewport();
    const box = (await dialog.boundingBox())!;
    expect(box.y).toBeGreaterThanOrEqual(20);
    expect(box.y + box.height).toBeLessThanOrEqual(680);
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
    await cancel.click();
    await expect(dialog).toHaveCount(0);
  });
}

for (const width of [1440, 390]) {
  test(`standard ${width}px prize registration selects Banner Category then its Banner Asset`, async ({ page }, testInfo) => {
    await page.setViewportSize({ width, height: 900 });
    const errors: string[] = [];
    const saved: Record<string, unknown>[] = [];
    page.on("pageerror", (error) => errors.push(error.message));
    page.on("console", (message) => { if (message.type() === "error") errors.push(message.text()); });
    const image = { id: assetId, path: `/admin/api/v2/catalog/presentation-assets/${assetId}/content`, alt_text: "Rank", media_type: "image", revision_number: 1 };
    await page.route("**/qa-prize-video.mp4", (route) => route.fulfill({ contentType: "video/mp4", body: Buffer.alloc(0) }));
    await page.route(`**/catalog/gachas/${gachaCode}/ranks`, (route) => json(route, { items: [{
      rank: { id: rankId, rank_name: "S", lineup_image: image, result_image: image, show_total_stock: false, status: "active", display_order: 0, revision: 1, revision_number: 1 },
      gacha_rank_id: null, gacha_rank_revision: null, can_unset_video: true, current_video: { id: uploadedAssetId, path: "/qa-prize-video.mp4" },
    }] }));
    await page.route("**/catalog/rank-effects*", (route) => json(route, { items: [{ id: uploadedAssetId, media_type: "video", is_public: true, alt_text: "QA video" }], next_cursor: null }));
    await page.route("**/banner-management/categories", (route) => json(route, { items: [{ id: categoryId, name: "Cards" }, { id: tagId, name: "Other" }] }));
    await page.route("**/banner-management/banners?*", (route) => {
      const selected = new URL(route.request().url()).searchParams.get("category_id");
      expect([categoryId, tagId]).toContain(selected);
      return json(route, { items: [{ id: `banner-${selected}`, external_id: selected === categoryId ? "CARD-0001" : "CARD-0002", title: selected === categoryId ? "Card Banner" : "Other Banner",
        category: { id: selected, name: selected === categoryId ? "Cards" : "Other" },
        asset: { id: selected === categoryId ? assetId : uploadedAssetId, public_url: image.path },
      }, { id: `no-id-${selected}`, external_id: null, title: "No ID Banner",
        category: { id: selected, name: "No ID" }, asset: { id: "no-id-asset", public_url: image.path },
      }], next_cursor: null });
    });
    await page.route(`**/catalog/gachas/${gachaCode}/versions/${versionId}/ranks/${rankId}/prizes`, (route) => {
      saved.push(route.request().postDataJSON() as Record<string, unknown>);
      return json(route, { data: prize(), idempotent_replay: false });
    });
    await page.goto(`/catalog/gachas/${gachaCode}`);
    await page.getByRole("button", { name: "景品登録", exact: true }).click();
    const dialog = page.getByRole("dialog", { name: "新規景品登録" });
    await dialog.getByLabel("景品名", { exact: true }).fill("QA Banner prize");
    const category = dialog.getByRole("combobox", { name: "Banner Category", exact: true });
    await category.selectOption(categoryId);
    const first = dialog.getByRole("button", { name: "Card Banner", exact: true });
    await expect(first.locator("img")).toBeVisible();
    await expect(dialog.getByRole("button", { name: "No ID Banner", exact: true })).toHaveCount(0);
    await first.click();
    await expect(dialog.getByLabel("管理ID", { exact: true })).toHaveValue("CARD-0001");
    await category.selectOption(tagId);
    await dialog.getByRole("button", { name: "Other Banner", exact: true }).click();
    await expect(dialog.getByLabel("管理ID", { exact: true })).toHaveValue("CARD-0002");
    await category.selectOption(categoryId);
    await first.click();
    await expect(dialog.getByLabel("管理ID", { exact: true })).toHaveValue("CARD-0001");
    await dialog.getByLabel("管理ID", { exact: true }).fill("MANUAL-0001");
    await expect(first).toHaveAttribute("aria-pressed", "true");
    await category.selectOption(tagId);
    await expect(first).toHaveCount(0);
    await dialog.getByRole("button", { name: "保存", exact: true }).click();
    await expect(dialog.getByRole("alert")).toHaveText("選択したBanner CategoryからBannerを選択してください。");
    expect(saved).toHaveLength(0);
    const second = dialog.getByRole("button", { name: "Other Banner", exact: true });
    await second.click();
    await expect(second).toHaveAttribute("aria-pressed", "true");
    await dialog.screenshot({ path: testInfo.outputPath(`standard-prize-${width}.png`) });
    await dialog.getByRole("button", { name: "保存", exact: true }).click();
    await expect.poll(() => saved.length).toBe(1);
    expect(saved[0]).toMatchObject({ presentation_asset_id: uploadedAssetId, name: "QA Banner prize", external_id: "MANUAL-0001" });
    expect(errors).toEqual([]);
  });
}

test.beforeEach(async ({ page }) => {
  await page.addInitScript((token) => {
    Object.defineProperty(Document.prototype, "cookie", {
      configurable: true,
      get: () => `__Host-oripa_admin_xsrf=${token}`,
      set: () => undefined,
    });
  }, csrf);
  await installApi(page);
});

test("canonical gacha edit uploads a file without banner dependencies", async ({ page }) => {
  const requests: string[] = [];
  page.on("request", (request) => requests.push(new URL(request.url()).pathname));

  const detail = await page.goto(`/catalog/gachas/${gachaCode}`);
  expect(detail?.status()).toBe(200);
  await expect(page.getByText(gachaCode, { exact: true })).toHaveText(gachaCode);
  await expect(page.getByText(gachaUuid, { exact: true })).toHaveCount(0);
  await expect(page.getByRole("columnheader", { name: "ID", exact: true })).toHaveCount(0);

  await page.getByRole("link", { name: "基本情報を編集" }).click();
  await expect(page).toHaveURL(`/gachas/${gachaCode}/edit`);
  await expect(page.getByRole("heading", { level: 1, name: "ガチャ編集" })).toBeVisible();
  await expect(page.getByLabel("ガチャタイトル")).toHaveValue("編集対象ガチャ");
  await expect(page.getByLabel(/サムネイル画像/u)).toBeVisible();
  await expect(page.getByRole("img", { name: "現在のサムネイル" })).toBeVisible();

  await page.getByLabel(/サムネイル画像/u).setInputFiles({
    buffer: Buffer.from(
      "iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=",
      "base64",
    ),
    mimeType: "image/png",
    name: "replacement.png",
  });
  await expect(page.getByRole("img", { name: "選択したサムネイルのPreview" }))
    .toBeVisible();
  await page.getByLabel("ガチャタイトル").fill("更新後ガチャ");
  await page.getByRole("button", { name: "編集内容を保存" }).click();
  await expect(page).toHaveURL(`/catalog/gachas/${gachaCode}`);

  expect(requests.some((path) => path.endsWith("/catalog/gacha-thumbnails"))).toBe(true);
  expect(requests.some((path) => path.startsWith("/admin/api/v2/banner-management")))
    .toBe(false);
});

test("mobile master edit remains usable without horizontal overflow", async ({ page }) => {
  await page.setViewportSize({ height: 844, width: 390 });
  await page.goto(`/gachas/${gachaCode}/edit`);
  await expect(page.getByRole("heading", { level: 1, name: "ガチャ編集" })).toBeVisible();
  await page.getByLabel("ガチャタイトル").focus();
  await expect(page.getByLabel("ガチャタイトル")).toBeFocused();
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth))
    .toBe(true);
});

test("Owner adds and removes a guaranteed Prize for a Test User", async ({ page }) => {
  let assignment: AdminQaGachaGuaranteeAssignment | null = null;
  let saveBody: Record<string, unknown> | null = null;
  await page.unroute(/\/admin\/api\/v2\/.*$/u);
  await installApi(page, {
    onQaRequest: async (route) => {
      const request = route.request();
      const path = new URL(request.url()).pathname;
      if (path.endsWith(`/catalog/gachas/${gachaCode}/qa-guarantees`) && request.method() === "GET") {
        return json(route, qaCollection(assignment));
      }
      if (path.endsWith(`/catalog/gachas/${gachaCode}/qa-guarantees`) && request.method() === "PUT") {
        saveBody = request.postDataJSON() as Record<string, unknown>;
        assignment = qaAssignment();
        return json(route, { data: assignment, idempotent_replay: false, request_id: uuid("9") });
      }
      if (path.endsWith(`/catalog/gachas/${gachaCode}/qa-guarantees/${testUserId}/disable`)) {
        assignment = { ...qaAssignment(), status: "unassigned", unassigned_at: "2026-09-04T00:05:00Z" };
        return json(route, { data: assignment, idempotent_replay: false, request_id: uuid("9") });
      }
      return route.fulfill({ status: 404 });
    },
  });

  await page.goto(`/catalog/gachas/${gachaCode}`);
  await expect(page.getByRole("heading", { name: "テストユーザー設定" })).toBeVisible();
  await page.getByRole("combobox", { name: "テストユーザー", exact: true }).selectOption(testUserId);
  await page.getByRole("combobox", { name: "保証する景品", exact: true }).selectOption(prizeId);
  await page.getByRole("button", { name: "追加・更新" }).click();
  await expect(page.getByLabel("現在のパスワード")).toHaveCount(0);

  const assignmentRow = page.getByRole("row", { name: /QAテストユーザー.*S 景品S.*利用可能/u });
  await expect(assignmentRow).toBeVisible();
  expect(saveBody).toEqual({ prize_id: prizeId, user_id: testUserId });

  await page.getByRole("button", { name: "QAテストユーザーの設定を解除" }).click();
  await expect(page.getByLabel("現在のパスワード")).toHaveCount(0);
  await expect(page.getByText("設定済みのテストユーザーはありません。")).toBeVisible();
});

test("mobile Test User settings remain within the gacha detail width", async ({ page }) => {
  await page.setViewportSize({ height: 844, width: 390 });
  await page.goto(`/catalog/gachas/${gachaCode}`);
  await expect(page.getByRole("heading", { name: "テストユーザー設定" })).toBeVisible();
  const qaSection = page.getByRole("region", { name: "テストユーザー設定" });
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth))
    .toBe(true);
  expect((await qaSection.boundingBox())?.width).toBeLessThanOrEqual(362);
});

for (const viewportWidth of [1440, 1366, 390]) {
  for (const media of ["portrait", "landscape", "image", "none"] as const) {
    test(`${viewportWidth}px rank preview contains ${media} media without stretching the prize section`, async ({ page }, testInfo) => {
      await page.setViewportSize({ width: viewportWidth, height: 900 });
      await page.goto("/login");
      const dimensions = media === "portrait" ? { width: 360, height: 640 } : { width: 640, height: 360 };
      const imageData = await page.evaluate((size) => {
        const canvas = document.createElement("canvas");
        canvas.width = size.width;
        canvas.height = size.height;
        const context = canvas.getContext("2d")!;
        context.fillStyle = "#465fff";
        context.fillRect(0, 0, size.width, size.height);
        return canvas.toDataURL("image/png").split(",")[1];
      }, dimensions);
      await page.route(`**/catalog/presentation-assets/${assetId}/content`, (route) => route.fulfill({ contentType: "image/png", body: Buffer.from(imageData, "base64") }));
      if (media === "portrait" || media === "landscape") {
        const bytes = await page.evaluate(async (size) => {
          const canvas = document.createElement("canvas");
          canvas.width = size.width;
          canvas.height = size.height;
          const context = canvas.getContext("2d")!;
          context.fillStyle = "#465fff";
          context.fillRect(0, 0, size.width, size.height);
          const stream = canvas.captureStream(10);
          const recorder = new MediaRecorder(stream, { mimeType: "video/webm;codecs=vp8" });
          const chunks: Blob[] = [];
          const finished = new Promise<Blob>((resolve) => {
            recorder.ondataavailable = (event) => chunks.push(event.data);
            recorder.onstop = () => resolve(new Blob(chunks, { type: "video/webm" }));
          });
          recorder.start();
          for (let frame = 0; frame < 6; frame += 1) {
            context.fillStyle = frame % 2 === 0 ? "#465fff" : "#3641f5";
            context.fillRect(0, 0, size.width, size.height);
            await new Promise((resolve) => setTimeout(resolve, 100));
          }
          recorder.stop();
          const blob = await finished;
          stream.getTracks().forEach((track) => track.stop());
          return Array.from(new Uint8Array(await blob.arrayBuffer()));
        }, dimensions);
        expect(bytes.length).toBeGreaterThan(200);
        await page.route("**/layout-video.webm", (route) => route.fulfill({ contentType: "video/webm", body: Buffer.from(bytes) }));
      }
      const image = { id: assetId, path: `/admin/api/v2/catalog/presentation-assets/${assetId}/content`, alt_text: "ランク画像", media_type: "image", revision_number: 1 };
      await page.route(`**/catalog/gachas/${gachaCode}/ranks`, (route) => json(route, { items: [{
        rank: { id: rankId, rank_name: "S", lineup_image: image, result_image: { ...image, alt_text: "抽選結果画像" }, show_total_stock: false, status: "active", display_order: 0, revision: 1, revision_number: 1 },
        gacha_rank_id: null, gacha_rank_revision: null, can_unset_video: false,
        current_video: media === "portrait" || media === "landscape" ? { id: assetId, path: "/layout-video.webm", media_type: "video", revision_number: 1 } : null,
      }] }));
      await page.route("**/catalog/rank-effects*", (route) => json(route, { items: [], next_cursor: null }));
      await page.route(`**/catalog/gachas/${gachaCode}/versions/${versionId}/prizes`, (route) => json(route, { items: [{ ...prize(), presentation_asset: media === "image" ? { ...gacha().current_version.presentation_asset, alt_text: "景品画像" } : null }], version_revision: 4 }));
      await page.route("**/catalog/gachas?*", (route) => json(route, { items: [gacha()], next_cursor: null }));
      await page.goto("/catalog/gachas");
      const listImage = page.locator("table").getByRole("img", { name: "現在のサムネイル" });
      await expect(listImage).toBeVisible();
      await expect(listImage).toHaveCSS("object-fit", "contain");
      expect((await listImage.boundingBox())!.height).toBe(84);
      expect((await listImage.boundingBox())!.width).toBeGreaterThanOrEqual(100);
      await page.goto(`/catalog/gachas/${gachaCode}`);
      const section = page.getByRole("region", { name: "編集中のランク／景品", exact: true });
      const row = section.locator("tbody tr").first();
      await expect(row.getByText("S", { exact: true })).toBeVisible();
      if (media === "portrait" || media === "landscape") {
        const video = row.locator("video");
        await expect.poll(() => video.evaluate((element: HTMLVideoElement) => element.videoHeight)).toBe(dimensions.height);
        await expect(video).toHaveCSS("object-fit", "contain");
        await expect(video).toHaveAttribute("controls", "");
        const box = (await video.boundingBox())!;
        expect(box.height).toBe(135);
        expect(box.width).toBeLessThanOrEqual(240);
        await video.evaluate((element: HTMLVideoElement) => element.play());
        await expect.poll(() => video.evaluate((element: HTMLVideoElement) => element.currentTime)).toBeGreaterThan(0);
        await video.evaluate((element: HTMLVideoElement) => element.pause());
      } else {
        await expect(row.locator("video")).toHaveCount(0);
      }
      if (media !== "none") {
        const thumbnail = row.getByRole("img", { name: "ランク画像" });
        await expect.poll(() => thumbnail.evaluate((element: HTMLImageElement) => element.naturalWidth)).toBeGreaterThan(0);
        await expect(thumbnail).toHaveCSS("object-fit", "contain");
        expect(await thumbnail.evaluate((element: HTMLImageElement) => [element.naturalWidth, element.naturalHeight])).toEqual([dimensions.width, dimensions.height]);
        expect((await thumbnail.boundingBox())!.height).toBe(144);
        expect((await thumbnail.boundingBox())!.width).toBeGreaterThanOrEqual(120);
      }
      expect((await row.boundingBox())!.height).toBeLessThanOrEqual(220);
      const sectionBox = (await section.boundingBox())!;
      const prizeHeading = (await section.getByRole("heading", { name: "登録済み景品" }).boundingBox())!;
      expect(prizeHeading.y - sectionBox.y).toBeLessThan(400);
      if (media === "image") {
        const prizeImage = section.getByRole("img", { name: "景品画像" });
        await expect(prizeImage).toHaveCSS("object-fit", "contain");
        expect((await prizeImage.boundingBox())!.height).toBe(108);
      }
      if (media === "none") await expect(section.getByRole("img", { name: "Previewなし" })).toBeVisible();
      expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true);
      await expect(section.locator(".catalog-table-wrap").first()).toHaveCSS("overflow-x", "auto");
      await page.screenshot({ path: testInfo.outputPath("rank-preview.png"), fullPage: true });
    });
  }
}

async function installApi(
  page: Page,
  options: { onQaRequest?: (route: Route) => Promise<void> } = {},
): Promise<void> {
  await page.route(/\/admin\/api\/v2\/.*$/u, async (route) => {
    const request = route.request();
    const path = new URL(request.url()).pathname;
    if (path.endsWith("/auth/session")) {
      return json(route, {
        admin: { id: uuid("9"), mfa_verified: false, role: "owner", state: "active" },
        authenticated: true,
        mfa_required: false,
        requires_mfa_enrollment: false,
      });
    }
    if (path.endsWith("/auth/permissions")) {
      return json(route, {
        permissions: ["catalog.read", "catalog.manage", "qa.draw.manage"],
        request_id: uuid("9"),
        role: "owner",
      });
    }
    if (path.includes("/qa-guarantees")) {
      if (options.onQaRequest) return options.onQaRequest(route);
      return json(route, qaCollection(null));
    }
    if (path.endsWith(`/catalog/presentation-assets/${assetId}/content`)) {
      return route.fulfill({
        body: Buffer.from(
          "iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=",
          "base64",
        ),
        contentType: "image/png",
        status: 200,
      });
    }
    if (path.endsWith("/catalog/gacha-thumbnails") && request.method() === "POST") {
      const body = request.postDataJSON();
      expect(body.file_name).toBe("replacement.png");
      expect(body.mime_type).toBe("image/png");
      expect(body.content_base64).toBeTruthy();
      return json(route, {
        data: { id: uploadedAssetId },
        idempotent_replay: false,
        request_id: uuid("9"),
      }, 201);
    }
    if (path.endsWith(`/catalog/gachas/${gachaCode}`) && request.method() === "PUT") {
      const body = request.postDataJSON();
      expect(body.presentation_asset_id).toBe(uploadedAssetId);
      expect(body.title).toBe("更新後ガチャ");
      expect(body.expected_revision).toBe(3);
      expect(body.expected_version_revision).toBe(4);
      return json(route, {
        data: { ...gacha(), current_version: { ...gacha().current_version, title: body.title } },
        idempotent_replay: false,
        request_id: uuid("9"),
      });
    }
    if (path.endsWith("/settings/gacha-notices") && request.method() === "GET") {
      return json(route, {
        data: { standard: { default_notices: null, revision: 1 }, login: { default_notices: null, revision: 1 } },
        request_id: uuid("9"),
      });
    }
    if (path.endsWith("/catalog/categories")) {
      return json(route, { items: [category()], next_cursor: null });
    }
    if (path.endsWith("/catalog/tags")) {
      return json(route, { items: [tag()], next_cursor: null });
    }
    if (path.endsWith("/catalog/presentation-assets")) {
      return json(route, { items: [], next_cursor: null });
    }
    if (path.endsWith(`/catalog/gachas/${gachaCode}/versions/${versionId}/ranks`)) {
      return json(route, { items: [rank()], version_revision: 4 });
    }
    if (path.endsWith(`/catalog/gachas/${gachaCode}/versions/${versionId}/prizes`)) {
      return json(route, { items: [prize()], version_revision: 4 });
    }
    if (path.endsWith(`/catalog/gachas/${gachaCode}/versions/${versionId}`)) {
      return json(route, { data: gacha().current_version });
    }
    if (path.endsWith(`/catalog/gachas/${gachaCode}/versions`)) {
      return json(route, { items: [gacha().current_version], next_cursor: null });
    }
    if (path.endsWith(`/catalog/gachas/${gachaCode}`)) {
      return json(route, { data: gacha() });
    }
    return route.fulfill({ status: 404 });
  });
}

function qaCollection(assignment: AdminQaGachaGuaranteeAssignment | null) {
  return {
    gacha_id: gachaCode,
    items: assignment ? [assignment] : [],
    prizes: [{ id: prizeId, name: "景品S", rank_name: "S" }],
    test_users: [{ display_name: "QAテストユーザー", id: testUserId }],
  };
}

function qaAssignment(): AdminQaGachaGuaranteeAssignment {
  return {
    assigned_at: "2026-09-04T00:00:00Z",
    id: assignmentId,
    is_resolvable: true,
    issue_code: null,
    prize: { id: prizeId, name: "景品S", rank_name: "S" },
    revision: 1,
    status: "assigned",
    unassigned_at: null,
    updated_at: "2026-09-04T00:00:00Z",
    user: { display_name: "QAテストユーザー", id: testUserId, state: "active" },
  };
}

for (const width of [1440, 390]) {
  test(`F3 CSV ${width}px previews confirms applies reloads and displays history`, async ({ page }) => {
    await page.setViewportSize({ width, height: 850 });
    let revision = 4;
    let applyCount = 0;
    let prizeLoads = 0;
    await page.route(`**/admin/api/v2/catalog/gachas/${gachaCode}`, (route) => json(route, { data: { ...gacha(), first_published_at: null } }));
    await page.route(`**/versions/${versionId}/prizes`, (route) => { prizeLoads++; return json(route, { items: [prize()], version_revision: revision }); });
    const summary = { create: 1, update: 1, unchanged: 0 };
    await page.route("**/prize-imports/preview", (route) => {
      expect(route.request().headers()["idempotency-key"]).toBeUndefined();
      expect(route.request().postDataJSON().expected_version_revision).toBe(4);
      return json(route, { plan_checksum: "a".repeat(64), summary, rows: [{ row: 2, external_id: "CARD-001", action: "update", changes: [{ field: "exchange_points", before: 100, after: 200 }] }], warnings: [{ row: 2, column: "カード名", code: "CARD_NAME_MISMATCH", message: "ライブラリ名と異なります。" }] });
    });
    await page.route("**/prize-imports", (route) => {
      if (route.request().method() === "GET") return json(route, { items: [{ id: uuid("9"), actor_public_id: uuid("0"), occurred_at: "2026-10-07T00:00:00Z", file_name: "prizes.csv", summary }], next_before: null });
      expect(route.request().headers()["idempotency-key"]).toBeTruthy();
      expect(route.request().postDataJSON().plan_checksum).toBe("a".repeat(64));
      revision = 5; applyCount++;
      return json(route, { data: { id: uuid("9"), gacha_version_id: versionId, gacha_version_revision: revision, summary }, idempotent_replay: false });
    });
    await page.goto(`/gachas/${gachaCode}/edit`);
    await page.getByText("CSVで取り込む", { exact: true }).click();
    await page.getByLabel(/景品CSV/u).setInputFiles({ name: "prizes.csv", mimeType: "text/csv", buffer: Buffer.from("管理ID,ランク,枚数,交換ポイント,原価\nCARD-001,S,1,200,10") });
    await page.getByRole("button", { name: "プレビュー", exact: true }).click();
    await expect(page.getByText("交換ポイント：100 → 200")).toBeVisible();
    await expect(page.getByText(/ライブラリ名と異なります/u)).toBeVisible();
    await page.getByRole("button", { name: "取込を確認" }).click();
    const confirmation = page.getByRole("dialog", { name: "CSVの差分を適用しますか" });
    await expect(confirmation).toBeInViewport();
    await confirmation.getByRole("button", { name: "取り込む", exact: true }).click();
    await expect(page.getByText(/取込完了：追加 1件/u)).toBeVisible();
    await expect(page.getByText(/prizes.csv：追加 1件/u)).toBeVisible();
    expect(applyCount).toBe(1);
    expect(prizeLoads).toBeGreaterThanOrEqual(2);
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
  });
}

test("F3 Operator with catalog.read cannot see import or history operations", async ({ page }) => {
  await page.route("**/auth/permissions", (route) => json(route, { permissions: ["catalog.read"], request_id: uuid("9"), role: "operator" }));
  await page.route("**/auth/session", (route) => json(route, {
    admin: { id: uuid("9"), mfa_verified: true, role: "operator", state: "active" },
    authenticated: true, mfa_required: false, requires_mfa_enrollment: false,
  }));
  await page.goto(`/catalog/gachas/${gachaCode}`);
  await expect(page.getByRole("heading", { level: 1, name: "編集対象ガチャ", exact: true })).toBeVisible();
  await expect(page.getByText("CSVで取り込む", { exact: true })).toHaveCount(0);
  await expect(page.getByRole("button", { name: "取込履歴" })).toHaveCount(0);
  await expect(page.getByRole("button", { name: "景品Sを編集" })).toHaveCount(0);
});

test("F3 CSV validation blocks apply and replacing file invalidates preview", async ({ page }) => {
  await page.route(`**/admin/api/v2/catalog/gachas/${gachaCode}`, (route) => json(route, { data: { ...gacha(), first_published_at: null } }));
  let previews = 0;
  await page.route("**/prize-imports/preview", (route) => {
    previews++;
    return previews === 1 ? json(route, { code: "CSV_VALIDATION_FAILED", errors: [{ row: 2, column: "ランク", code: "RANK_NOT_FOUND", message: "ランクがありません。" }], error_count: 1 }, 422)
      : json(route, { plan_checksum: "a".repeat(64), summary: { create: 0, update: 1, unchanged: 0 }, rows: [], warnings: [] });
  });
  await page.goto(`/gachas/${gachaCode}/edit`);
  await page.getByText("CSVで取り込む", { exact: true }).click();
  const file = { name: "prizes.csv", mimeType: "text/csv", buffer: Buffer.from("synthetic CSV") };
  await page.getByLabel(/景品CSV/u).setInputFiles(file);
  await page.getByRole("button", { name: "プレビュー", exact: true }).click();
  await expect(page.getByText(/ランクがありません/u)).toBeVisible();
  await expect(page.getByRole("button", { name: "取込を確認" })).toHaveCount(0);
  await page.getByRole("button", { name: "プレビュー", exact: true }).click();
  await expect(page.getByRole("button", { name: "取込を確認" })).toBeVisible();
  await page.getByLabel(/景品CSV/u).setInputFiles({ ...file, name: "replacement.csv" });
  await expect(page.getByRole("button", { name: "取込を確認" })).toHaveCount(0);
});

function gacha() {
  return {
    archived_at: null,
    category: { code: "cards", id: categoryId, name: "カード" },
    code: "gacha-internal-code",
    created_at: "2026-08-01T00:00:00Z",
    current_version: {
      audience_code: "all_users",
      daily_draw_limit: 0,
      description: "説明",
      id: versionId,
      notices: "注意事項",
      presentation_asset: {
        alt_text: "現在のサムネイル",
        id: assetId,
        is_public: true,
        media_type: "image",
        mime_type: "image/png",
        public_path: `/admin/api/v2/catalog/presentation-assets/${assetId}/content`,
      },
      price_points: 100,
      publish_end_at: null,
      publish_start_at: "2026-08-20T00:00:00Z",
      revision: 4,
      status: "draft",
      title: "編集対象ガチャ",
      total_count: 100,
      version_number: 2,
    },
    has_draw_history: false,
    id: gachaUuid,
    is_archived: false,
    public_code: gachaCode,
    published_version: null,
    revision: 3,
    slug: "gacha-internal-code",
    sold_count: 0,
    state: "draft",
    tags: [{ code: "featured", id: tagId, name: "Featured" }],
    updated_at: "2026-08-01T00:00:00Z",
    version_count: 1,
  };
}

function category() {
  return {
    code: "cards", created_at: "2026-08-01T00:00:00Z", description: null,
    id: categoryId, is_archived: false, is_visible: true, name: "カード",
    revision: 1, slug: "cards", sort_order: 1, updated_at: "2026-08-01T00:00:00Z",
  };
}

function tag() {
  return {
    code: "featured", created_at: "2026-08-01T00:00:00Z", description: null,
    id: tagId, is_archived: false, is_visible: true, name: "Featured",
    revision: 1, slug: "featured", sort_order: 1, updated_at: "2026-08-01T00:00:00Z",
  };
}

function rank() {
  return {
    code: "S", created_at: "2026-08-01T00:00:00Z", description: null,
    id: rankId, image_asset: null, is_archived: false, name: "S",
    revision: 1, sort_order: 1, updated_at: "2026-08-01T00:00:00Z", video_asset: null,
  };
}

function prize() {
  return {
    available_inventory: 10, awarded_inventory: 0, code: "prize-s", cost_price: 500,
    created_at: "2026-08-01T00:00:00Z", exchange_points: 1000,
    id: prizeId, is_active: true, name: "景品S", presentation_asset: null,
    rank: { code: "S", id: rankId, name: "S", sort_order: 1 },
    inventory_revision: 0, revision: 1, total_inventory: 10,
    updated_at: "2026-08-01T00:00:00Z", withdrawn_inventory: 0,
  };
}

function uuid(seed: string): string {
  return `01910191-0191-7191-8191-01910191019${seed}`;
}

function json(route: Route, body: unknown, status = 200) {
  return route.fulfill({ body: JSON.stringify(body), contentType: "application/json", status });
}
