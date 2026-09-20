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
