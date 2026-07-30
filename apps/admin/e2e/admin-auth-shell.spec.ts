import { expect, test, type Page, type Route } from "@playwright/test";

const csrf = "a".repeat(64);
const transaction = "b".repeat(64);

test.beforeEach(async ({ page }) => {
  await page.addInitScript((token) => {
    Object.defineProperty(Document.prototype, "cookie", {
      configurable: true,
      get: () => `__Host-oripa_admin_xsrf=${token}`,
      set: () => undefined,
    });
  }, csrf);
});

test("password pre-auth, TOTP, Fresh MFA, and logout stay in the Admin realm", async ({
  page,
}) => {
  let authenticated = false;
  let loginHeaders: Record<string, string> | null = null;
  let mfaHeaders: Record<string, string> | null = null;
  await installAdminApi(page, async (route) => {
    const request = route.request();
    const path = new URL(request.url()).pathname;
    if (path.endsWith("/auth/session")) {
      return json(route, authenticated ? adminSession() : {
        admin: null,
        authenticated: false,
      });
    }
    if (path.endsWith("/auth/permissions")) {
      return json(route, permissionResponse("owner"));
    }
    if (path.endsWith("/auth/login")) {
      loginHeaders = request.headers();
      return json(route, {
        expires_in: 300,
        methods: ["totp", "recovery_code"],
        status: "mfa_required",
        transaction_token: transaction,
        webauthn: null,
      }, 202);
    }
    if (path.endsWith("/auth/mfa/verify")) {
      mfaHeaders = request.headers();
      authenticated = true;
      return json(route, adminSession());
    }
    if (path.endsWith("/auth/reauthenticate")) {
      return json(route, {
        admin: adminIdentity(),
        authenticated: true,
        fresh_mfa_expires_in: 300,
      });
    }
    if (path.endsWith("/auth/logout")) {
      authenticated = false;
      return route.fulfill({ status: 204 });
    }
    return route.fulfill({ status: 404 });
  });

  await page.goto("/");
  await expect(page).toHaveURL(/\/login$/u);
  await page.getByLabel("メールアドレス").fill("owner@example.test");
  await page.getByLabel("パスワード").fill("temporary password");
  await page.getByRole("button", { name: "続行" }).click();
  await expect.poll(() => loginHeaders).not.toBeNull();
  expect(loginHeaders?.["x-xsrf-token"]).toBe(csrf);
  expect(
    (loginHeaders as Record<string, string> | null)?.authorization,
  ).toBeUndefined();
  await expect(page).toHaveURL(/\/auth\/mfa$/u);
  await page.getByLabel("認証アプリの6桁コード").fill("123456");
  await page.getByRole("button", { name: "コードを確認" }).click();

  await expect(page).toHaveURL(/\/$/u);
  expect(mfaHeaders?.["x-oripa-auth-transaction"]).toBe(transaction);
  await expect(page.getByText("Owner", { exact: true })).toBeVisible();
  await expect(page.getByRole("link", { name: "QA Draw" }).first()).toBeVisible();
  await page.screenshot({
    fullPage: true,
    path: "/tmp/oripa-mig-060b-admin-desktop.png",
  });

  await page.getByRole("button", { name: "再確認" }).click();
  await expect(page.getByRole("dialog")).toBeVisible();
  await page.getByLabel("認証アプリの6桁コード").fill("654321");
  await page.getByRole("button", { name: "再認証", exact: true }).click();
  await expect(page.getByText("Fresh", { exact: true })).toBeVisible();

  await page.getByRole("button", { name: "ログアウト" }).click();
  await expect(page).toHaveURL(/\/login$/u);
});

test("renders a generic rate-limit error and keeps credential fields ephemeral", async ({
  page,
}) => {
  await installAdminApi(page, async (route) => {
    const path = new URL(route.request().url()).pathname;
    if (path.endsWith("/auth/session")) {
      return json(route, { admin: null, authenticated: false });
    }
    return json(route, {
      code: "RATE_LIMITED",
      detail: "internal limiter detail",
      request_id: "01910191-0191-7191-8191-019101910191",
      retry_after: 60,
      retryable: true,
      status: 429,
      title: "Too Many Requests",
      type: "about:blank",
    }, 429, { "Content-Type": "application/problem+json", "Retry-After": "60" });
  });

  await page.goto("/login");
  await page.getByLabel("メールアドレス").fill("owner@example.test");
  const password = page.getByLabel("パスワード");
  await password.fill("not retained");
  await page.getByRole("button", { name: "続行" }).click();
  await expect(password).toHaveValue("");
  await expect(
    page.getByText("試行回数が上限に達しました。時間をおいてください。"),
  ).toBeVisible();
  await expect(page.getByText("internal limiter detail")).toHaveCount(0);

  const storageKeys = await page.evaluate(() => ({
    local: Object.keys(localStorage),
    session: Object.keys(sessionStorage),
  }));
  expect(storageKeys).toEqual({ local: [], session: [] });
});

test("mobile shell remains keyboard operable without horizontal overflow", async ({
  page,
}) => {
  await page.setViewportSize({ height: 844, width: 390 });
  await installAdminApi(page, async (route) => {
    const path = new URL(route.request().url()).pathname;
    if (path.endsWith("/auth/session")) return json(route, adminSession());
    if (path.endsWith("/auth/permissions")) {
      return json(route, permissionResponse("owner"));
    }
    return route.fulfill({ status: 404 });
  });

  await page.goto("/");
  await expect(page.getByRole("link", { name: "QA Draw" }).first()).toBeVisible();
  await page.getByRole("button", { name: "ナビゲーションを開く" }).focus();
  await page.keyboard.press("Enter");
  await expect(page.getByRole("navigation", { name: "管理ナビゲーション" })).toBeVisible();
  const sidebar = page.locator(".admin-sidebar");
  await expect
    .poll(() => sidebar.evaluate((element) => getComputedStyle(element).transform))
    .toBe("matrix(1, 0, 0, 1, 0, 0)");
  expect(
    await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth),
  ).toBe(true);
  await page.screenshot({
    fullPage: true,
    path: "/tmp/oripa-mig-060b-admin-mobile.png",
  });
});

for (const role of ["owner", "admin", "operator"] as const) {
  test(`${role} navigation follows backend effective permissions`, async ({ page }) => {
    await installAdminApi(page, async (route) => {
      const path = new URL(route.request().url()).pathname;
      if (path.endsWith("/auth/session")) {
        return json(route, adminSession(role));
      }
      if (path.endsWith("/auth/permissions")) {
        return json(route, permissionResponse(role));
      }
      return route.fulfill({ status: 404 });
    });

    await page.goto("/");
    await expect(page.getByRole("link", { name: "カタログ" }).first()).toBeVisible();
    await expect(page.getByRole("link", { name: "景品・配送" }).first()).toBeVisible();
    if (role === "owner") {
      await expect(page.getByRole("link", { name: "QA Draw" }).first()).toBeVisible();
    } else {
      await expect(page.getByRole("link", { name: "QA Draw" })).toHaveCount(0);
    }
    if (role === "operator") {
      await expect(page.getByRole("link", { name: "レポート" })).toHaveCount(0);
      await page.goto("/qa");
      await expect(
        page.getByRole("heading", { name: "アクセスできません" }),
      ).toBeVisible();
    } else {
      await expect(page.getByRole("link", { name: "レポート" }).first()).toBeVisible();
    }
  });
}

test("Catalog list, search, detail, and mobile view use the Admin read contract", async ({
  page,
}) => {
  await installAdminApi(page, async (route) => {
    const url = new URL(route.request().url());
    if (url.pathname.endsWith("/auth/session")) return json(route, adminSession("operator"));
    if (url.pathname.endsWith("/auth/permissions")) {
      return json(route, permissionResponse("operator"));
    }
    if (url.pathname.endsWith("/catalog/prizes")) {
      return json(route, {
        items: [
          {
            code: "fixture-s",
            created_at: "2026-07-28T00:00:00Z",
            description: "Read-only fixture",
            display_price: 10000,
            exchange_points: 8000,
            id: "01910191-0191-7191-8191-019101910194",
            is_visible: true,
            name: "Fixture S景品",
            presentation_asset: {
              alt_text: "Fixture S景品",
              id: "01910191-0191-7191-8191-019101910195",
              is_public: true,
              media_type: "image",
              mime_type: "image/png",
              public_path: "/fixture-prize.png",
            },
            rank: {
              code: "S",
              id: "01910191-0191-7191-8191-019101910196",
              name: "Sランク",
              sort_order: 10,
            },
            updated_at: "2026-07-28T00:00:00Z",
          },
        ],
        next_cursor: null,
      });
    }
    if (url.pathname.endsWith("/catalog/prizes/01910191-0191-7191-8191-019101910194")) {
      return json(route, {
        data: {
          code: "fixture-s",
          created_at: "2026-07-28T00:00:00Z",
          description: "Read-only fixture",
          display_price: 10000,
          exchange_points: 8000,
          id: "01910191-0191-7191-8191-019101910194",
          is_visible: true,
          name: "Fixture S景品",
          presentation_asset: null,
          rank: {
            code: "S",
            id: "01910191-0191-7191-8191-019101910196",
            name: "Sランク",
            sort_order: 10,
          },
          updated_at: "2026-07-28T00:00:00Z",
        },
      });
    }
    return route.fulfill({ status: 404 });
  });

  await page.goto("/catalog/prizes");
  await expect(page.getByRole("heading", { name: "Prize" })).toBeVisible();
  await expect(page.getByText("Fixture S景品")).toBeVisible();
  await page.getByPlaceholder("名称・Codeで検索").fill("Fixture");
  await page.getByRole("button", { name: "検索" }).click();
  await expect(page.getByText("Fixture S景品")).toBeVisible();
  await page.getByRole("link", { name: "Fixture S景品の詳細" }).click();
  await expect(page.getByText("Read-only fixture")).toBeVisible();

  await page.setViewportSize({ height: 844, width: 390 });
  await page.goto("/catalog/prizes");
  expect(
    await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth),
  ).toBe(true);
  await page.getByPlaceholder("名称・Codeで検索").focus();
  await page.keyboard.press("Tab");
  await expect(page.getByRole("button", { name: "検索" })).toBeFocused();
});

test("Catalog master mutation sends CSRF and idempotency headers then reloads canonical data", async ({
  page,
}) => {
  let categories: Array<Record<string, unknown>> = [];
  let mutationHeaders: Record<string, string> | null = null;
  let mutationCount = 0;
  await installAdminApi(page, async (route) => {
    const request = route.request();
    const url = new URL(request.url());
    if (url.pathname.endsWith("/auth/session")) return json(route, adminSession("owner"));
    if (url.pathname.endsWith("/auth/permissions")) {
      return json(route, permissionResponse("owner"));
    }
    if (url.pathname.endsWith("/catalog/categories") && request.method() === "GET") {
      return json(route, { items: categories, next_cursor: null });
    }
    if (url.pathname.endsWith("/catalog/categories") && request.method() === "POST") {
      mutationCount += 1;
      mutationHeaders = request.headers();
      const input = request.postDataJSON() as {
        code: string;
        description: string | null;
        is_visible: boolean;
        name: string;
        slug: string;
        sort_order: number;
      };
      const created = {
        archived_at: null,
        code: input.code,
        created_at: "2026-07-29T00:00:00Z",
        description: input.description,
        id: "01910191-0191-7191-8191-019101910199",
        is_archived: false,
        is_visible: input.is_visible,
        name: input.name,
        revision: 1,
        slug: input.slug,
        sort_order: input.sort_order,
        updated_at: "2026-07-29T00:00:00Z",
      };
      categories = [created];
      return json(route, { data: created, idempotent_replay: false }, 201);
    }
    return route.fulfill({ status: 404 });
  });

  await page.goto("/catalog/categories");
  await page.getByRole("button", { name: "新規作成" }).click();
  const dialog = page.getByRole("dialog");
  await dialog.getByRole("textbox", { name: "Code", exact: true }).fill("e2e-category");
  await dialog.getByRole("textbox", { name: "Slug", exact: true }).fill("e2e-category");
  await dialog.getByRole("textbox", { name: "名称", exact: true }).fill("E2E Category");
  await dialog
    .getByRole("textbox", { name: "説明", exact: true })
    .fill("Browser mutation fixture");
  await dialog.getByRole("button", { name: "保存" }).click();
  await expect(page.getByText("E2E Category")).toBeVisible();
  expect(mutationCount).toBe(1);
  expect(mutationHeaders?.["x-xsrf-token"]).toBe(csrf);
  expect(mutationHeaders?.["idempotency-key"]).toMatch(/^[0-9a-f-]{36}$/u);
  expect(
    (mutationHeaders as Record<string, string> | null)?.authorization,
  ).toBeUndefined();

  await page.screenshot({
    fullPage: true,
    path: "/tmp/oripa-mig-060d-catalog-mutation.png",
  });
});

test("Operator Catalog remains read-only even on a direct module URL", async ({
  page,
}) => {
  await installAdminApi(page, async (route) => {
    const request = route.request();
    const url = new URL(request.url());
    if (url.pathname.endsWith("/auth/session")) {
      return json(route, adminSession("operator"));
    }
    if (url.pathname.endsWith("/auth/permissions")) {
      return json(route, permissionResponse("operator"));
    }
    if (url.pathname.endsWith("/catalog/categories") && request.method() === "GET") {
      return json(route, { items: [], next_cursor: null });
    }
    return route.fulfill({ status: 404 });
  });

  await page.goto("/catalog/categories");
  await expect(page.getByRole("heading", { name: "Category" })).toBeVisible();
  await expect(page.getByRole("button", { name: "新規作成" })).toHaveCount(0);
});

test("Prize and Presentation Asset mutation reuse selection and canonical reload", async ({
  page,
}) => {
  const rankId = "01910191-0191-7191-8191-019101910196";
  const assetId = "01910191-0191-7191-8191-019101910197";
  let createdPrize: Record<string, unknown> | null = null;
  await installAdminApi(page, async (route) => {
    const request = route.request();
    const url = new URL(request.url());
    if (url.pathname.endsWith("/auth/session")) return json(route, adminSession("owner"));
    if (url.pathname.endsWith("/auth/permissions")) {
      return json(route, permissionResponse("owner"));
    }
    if (url.pathname.endsWith("/catalog/ranks")) {
      return json(route, {
        items: [{
          archived_at: null, code: "A", created_at: "2026-07-29T00:00:00Z",
          id: rankId, is_archived: false, is_visible: true, name: "Aランク",
          revision: 1, sort_order: 10, updated_at: "2026-07-29T00:00:00Z",
        }],
        next_cursor: null,
      });
    }
    if (url.pathname.endsWith("/catalog/presentation-assets")) {
      return json(route, {
        items: [{
          alt_text: "A景品画像", archived_at: null, byte_size: 128,
          checksum_sha256: "a".repeat(64), created_at: "2026-07-29T00:00:00Z",
          id: assetId, is_archived: false, is_public: true, media_type: "image",
          mime_type: "image/png", public_path: "/assets/a.png", revision: 1,
          updated_at: "2026-07-29T00:00:00Z",
        }],
        next_cursor: null,
      });
    }
    if (url.pathname.endsWith("/catalog/prizes") && request.method() === "GET") {
      return json(route, { items: createdPrize ? [createdPrize] : [], next_cursor: null });
    }
    if (url.pathname.endsWith("/catalog/prizes") && request.method() === "POST") {
      const input = request.postDataJSON() as Record<string, unknown>;
      createdPrize = {
        archived_at: null, code: input.code, created_at: "2026-07-29T00:00:00Z",
        description: input.description, display_price: input.display_price,
        exchange_points: input.exchange_points,
        id: "01910191-0191-7191-8191-019101910198",
        is_archived: false, is_visible: true, name: input.name,
        presentation_asset: {
          alt_text: "A景品画像", id: assetId, is_public: true,
          media_type: "image", mime_type: "image/png", public_path: "/assets/a.png",
        },
        rank: { code: "A", id: rankId, name: "Aランク", sort_order: 10 },
        revision: 1, updated_at: "2026-07-29T00:00:00Z",
      };
      return json(route, { data: createdPrize, idempotent_replay: false }, 201);
    }
    return route.fulfill({ status: 404 });
  });

  await page.goto("/catalog/prizes");
  await page.getByRole("button", { name: "新規作成" }).click();
  const dialog = page.getByRole("dialog");
  await dialog.getByRole("textbox", { name: "Code", exact: true }).fill("e2e-prize");
  await dialog.getByLabel("Rank").selectOption(rankId);
  await dialog.getByLabel("Presentation Asset").selectOption(assetId);
  await dialog.getByRole("textbox", { name: "名称", exact: true }).fill("E2E Prize");
  await dialog.getByLabel("表示価格").fill("3000");
  await dialog.getByLabel("交換Point").fill("2000");
  await dialog.getByRole("button", { name: "保存" }).click();
  await expect(page.getByText("E2E Prize")).toBeVisible();
});

test("Gacha master and Draft Version remain navigable and permission-aware", async ({
  page,
}) => {
  const gachaId = "01910191-0191-7191-8191-019101910210";
  const versionId = "01910191-0191-7191-8191-019101910211";
  const nextVersionId = "01910191-0191-7191-8191-019101910213";
  const category = {
    code: "cards",
    id: "01910191-0191-7191-8191-019101910212",
    name: "Cards",
    slug: "cards",
    sort_order: 10,
  };
  const gacha = {
    archived_at: null,
    category,
    code: "e2e-gacha",
    created_at: "2026-07-29T00:00:00Z",
    has_draw_history: false,
    id: gachaId,
    is_archived: false,
    published_version: null,
    revision: 1,
    slug: "e2e-gacha",
    sold_count: 0,
    state: "active",
    tags: [],
    updated_at: "2026-07-29T00:00:00Z",
    version_count: 1,
  };
  const version = {
    archived_at: null,
    cloned_from_version_id: null,
    created_at: "2026-07-29T00:00:00Z",
    description: "Browser draft fixture",
    id: versionId,
    is_archived: false,
    notices: null,
    presentation_asset: null,
    price_points: 100,
    prizes: [],
    publish_end_at: null,
    publish_start_at: "2026-07-29T00:00:00Z",
    published_probability_version: null,
    revision: 1,
    status: "draft",
    title: "E2E Draft",
    total_count: 1000,
    updated_at: "2026-07-29T00:00:00Z",
    version_number: 1,
  };
  const nextVersion = {
    ...version,
    id: nextVersionId,
    title: "E2E Draft Page 2",
    version_number: 2,
  };
  const probabilityId = "01910191-0191-7191-8191-019101910214";
  const probability = {
    id: probabilityId,
    published_at: "2026-07-29T00:00:00Z",
    snapshot_sha256: "a".repeat(64),
    stage_count: 2,
    validation_status: "valid",
    version_number: 3,
  };
  let selected = false;

  await installAdminApi(page, async (route) => {
    const request = route.request();
    const url = new URL(request.url());
    if (url.pathname.endsWith("/auth/session")) return json(route, adminSession("owner"));
    if (url.pathname.endsWith("/auth/permissions")) {
      return json(route, permissionResponse("owner"));
    }
    if (url.pathname.endsWith("/catalog/gachas") && request.method() === "GET") {
      return json(route, { items: [gacha], next_cursor: null });
    }
    if (url.pathname.endsWith(`/catalog/gachas/${gachaId}`)) {
      return json(route, { data: gacha });
    }
    if (url.pathname.endsWith(`/catalog/gachas/${gachaId}/versions`)) {
      return url.searchParams.get("cursor") === "next-version-page"
        ? json(route, { items: [nextVersion], next_cursor: null })
        : json(route, { items: [version], next_cursor: "next-version-page" });
    }
    if (
      url.pathname.endsWith(
        `/catalog/gachas/${gachaId}/versions/${versionId}/published-probability-candidates`,
      )
    ) {
      return json(route, { items: [probability], next_cursor: null });
    }
    if (
      url.pathname.endsWith(
        `/catalog/gachas/${gachaId}/versions/${versionId}/probability-selection`,
      )
    ) {
      if (request.method() === "PUT") {
        expect(request.headers()["idempotency-key"]).toMatch(/^[0-9a-f-]{36}$/u);
        selected = true;
        return json(route, {
          data: {
            ...version,
            published_probability_version: {
              id: probabilityId,
              status: "published",
              version_number: 3,
            },
            revision: 2,
          },
          idempotent_replay: false,
        });
      }
      return json(route, {
        data: {
          gacha_version_id: versionId,
          gacha_version_revision: selected ? 2 : 1,
          selected_probability: selected ? probability : null,
        },
      });
    }
    if (
      url.pathname.endsWith(
        `/catalog/gachas/${gachaId}/versions/${versionId}/publish-preflight`,
      )
    ) {
      expect(request.method()).toBe("POST");
      return json(route, {
        data: {
          blocking_reasons: [],
          gacha_version_id: versionId,
          gacha_version_revision: 2,
          publishable: true,
          request_id: "01910191-0191-7191-8191-019101910215",
          selected_probability: {
            id: probabilityId,
            snapshot_sha256: "a".repeat(64),
          },
          validation_codes: ["GACHA_PUBLISH_PREFLIGHT_READY"],
        },
        idempotent_replay: false,
      });
    }
    if (url.pathname.endsWith(`/catalog/gachas/${gachaId}/versions/${versionId}`)) {
      return json(route, { data: version });
    }
    return route.fulfill({ status: 404 });
  });

  await page.goto("/catalog/gachas");
  await expect(page.getByRole("heading", { name: "Gacha" })).toBeVisible();
  await expect(page.getByText("e2e-gacha").first()).toBeVisible();
  await page.getByRole("link", { name: "開く" }).click();
  await expect(page.getByRole("heading", { name: "e2e-gacha" })).toBeVisible();
  await expect(page.getByRole("button", { name: "Draft作成" })).toBeVisible();
  await page.getByRole("button", { name: "次へ" }).last().click();
  await expect(page.getByText("E2E Draft Page 2")).toBeVisible();
  await page.getByRole("button", { name: "前へ" }).last().click();
  await page.getByRole("link", { name: "詳細" }).click();
  await expect(page.getByRole("heading", { name: "e2e-gacha / Version 1" })).toBeVisible();
  await expect(page.getByRole("button", { name: "Draft編集" })).toBeVisible();
  await expect(page.getByText("Browser draft fixture")).toBeVisible();
  await page.getByLabel("Published Probability").selectOption(probabilityId);
  await page.getByRole("button", { name: "選択を確定" }).click();
  await page.getByRole("alertdialog").getByRole("button", {
    name: "選択を確定",
  }).click();
  await expect(page.getByText(/v3.*01910191/u)).toBeVisible();
  await page.getByRole("button", { name: "Publish Preflight" }).click();
  await expect(page.getByText("Server Preflight完了")).toBeVisible();
  await expect(page.getByText("公開操作は未実装")).toBeVisible();
  await expect(page.getByRole("button", { name: "Publish", exact: true })).toHaveCount(0);
  await expect(page.getByRole("button", { name: "Schedule", exact: true })).toHaveCount(0);

  await page.setViewportSize({ height: 844, width: 390 });
  expect(
    await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth),
  ).toBe(true);
});

test("Draft Probability editor saves integer ppm and reloads canonical validation", async ({
  page,
}) => {
  const gachaId = "01910191-0191-7191-8191-019101910220";
  const versionId = "01910191-0191-7191-8191-019101910221";
  const probabilityId = "01910191-0191-7191-8191-019101910222";
  const prizeSId = "01910191-0191-7191-8191-019101910223";
  const prizeAId = "01910191-0191-7191-8191-019101910224";
  let revision = 1;
  let firstPpm = 500_000;
  let probabilityStatus: "draft" | "published" = "draft";
  let publishedAt: string | null = null;
  let preflightAttempts = 0;
  const preflightKeys: string[] = [];
  let mutationHeaders: Record<string, string> | null = null;
  const prize = (id: string, code: string) => ({
    code: `prize-${code.toLowerCase()}`,
    id,
    name: `${code} Prize`,
    rank: {
      code,
      id: `${id.slice(0, -1)}9`,
      name: `${code} Rank`,
      sort_order: code === "S" ? 10 : 20,
    },
  });
  const gachaVersion = {
    archived_at: null,
    cloned_from_version_id: null,
    created_at: "2026-07-29T00:00:00Z",
    description: null,
    id: versionId,
    is_archived: false,
    notices: null,
    presentation_asset: null,
    price_points: 100,
    prizes: [
      { initial_inventory: 1000, prize: prize(prizeSId, "S"), sort_order: 10 },
      { initial_inventory: 1000, prize: prize(prizeAId, "A"), sort_order: 20 },
    ],
    publish_end_at: null,
    publish_start_at: "2026-07-29T00:00:00Z",
    published_probability_version: null,
    revision: 1,
    status: "draft",
    title: "Probability E2E",
    total_count: 1000,
    updated_at: "2026-07-29T00:00:00Z",
    version_number: 1,
  };
  const probability = () => ({
    archived_at: null,
    cloned_from_version: null,
    created_at: "2026-07-29T00:00:00Z",
    gacha_version_id: versionId,
    id: probabilityId,
    is_archived: false,
    published_at: publishedAt,
    revision,
    snapshot_sha256: "a".repeat(64),
    stages: [{
      code: "stage-1",
      condition_type: "sold_count",
      entries: [{
        point_amount: null,
        prize: prize(prizeSId, "S"),
        probability_ppm: firstPpm,
        result_type: "prize",
        sort_order: 10,
      }],
      id: "01910191-0191-7191-8191-019101910225",
      max_draw_number: null,
      min_draw_number: 1,
      minimum_guarantee: {
        point_amount: null,
        prize: prize(prizeAId, "A"),
        probability_ppm: 400_000,
        result_type: "prize",
      },
      name: "Stage 1",
      sort_order: 10,
    }],
    status: probabilityStatus,
    updated_at: "2026-07-29T00:00:00Z",
    validation: {
      current_total_ppm: firstPpm + 400_000,
      errors: firstPpm === 600_000 ? [] : [
        "stage-1:PROBABILITY_TOTAL_INCOMPLETE",
      ],
      excess_ppm: 0,
      is_valid: firstPpm === 600_000,
      remaining_ppm: 600_000 - firstPpm,
      required_total_ppm: 1_000_000,
      stages: [{
        code: "stage-1",
        current_total_ppm: firstPpm + 400_000,
        errors: firstPpm === 600_000 ? [] : [
          "PROBABILITY_TOTAL_INCOMPLETE",
        ],
        excess_ppm: 0,
        remaining_ppm: 600_000 - firstPpm,
        required_total_ppm: 1_000_000,
        stage_id: "01910191-0191-7191-8191-019101910225",
      }],
    },
    version_number: 2,
  });

  await installAdminApi(page, async (route) => {
    const request = route.request();
    const url = new URL(request.url());
    if (url.pathname.endsWith("/auth/session")) return json(route, adminSession("owner"));
    if (url.pathname.endsWith("/auth/permissions")) {
      return json(route, permissionResponse("owner"));
    }
    if (url.pathname.endsWith("/auth/reauthenticate")) {
      return json(route, {
        admin: adminIdentity(),
        authenticated: true,
        fresh_mfa_expires_in: 300,
      });
    }
    if (url.pathname.endsWith(`/catalog/gachas/${gachaId}/versions/${versionId}`)) {
      return json(route, { data: gachaVersion });
    }
    if (
      url.pathname.endsWith(
        `/catalog/gachas/${gachaId}/versions/${versionId}/probability-versions/${probabilityId}`,
      ) &&
      request.method() === "GET"
    ) {
      return json(route, { data: probability() });
    }
    if (
      url.pathname.endsWith(
        `/catalog/gachas/${gachaId}/versions/${versionId}/probability-versions/${probabilityId}/entries`,
      ) &&
      request.method() === "PUT"
    ) {
      mutationHeaders = request.headers();
      const input = request.postDataJSON() as {
        expected_revision: number;
        stages: Array<{ entries: Array<{ probability_ppm: number }> }>;
      };
      expect(input.expected_revision).toBe(1);
      expect(input.stages[0].entries[0].probability_ppm).toBe(600_000);
      firstPpm = 600_000;
      revision = 2;
      return json(route, {
        data: probability(),
        idempotent_replay: false,
      });
    }
    if (
      url.pathname.endsWith(
        `/catalog/gachas/${gachaId}/versions/${versionId}/probability-versions/${probabilityId}/publish-preflight`,
      )
    ) {
      preflightAttempts += 1;
      preflightKeys.push(request.headers()["idempotency-key"] ?? "");
      if (preflightAttempts === 1) {
        return json(route, {
          code: "FRESH_AUTHENTICATION_REQUIRED",
          request_id: "01910191-0191-7191-8191-019101910193",
          retryable: false,
          status: 403,
          title: "Fresh authentication is required.",
          type: "about:blank",
        }, 403, { "Content-Type": "application/problem+json" });
      }
      return json(route, {
        data: probability(),
        idempotent_replay: false,
      });
    }
    if (
      url.pathname.endsWith(
        `/catalog/gachas/${gachaId}/versions/${versionId}/probability-versions/${probabilityId}/publish`,
      )
    ) {
      probabilityStatus = "published";
      publishedAt = "2026-08-10T03:04:05Z";
      revision += 1;
      return json(route, {
        data: probability(),
        idempotent_replay: false,
      });
    }
    return route.fulfill({ status: 404 });
  });

  await page.goto(
    `/catalog/gachas/${gachaId}/versions/${versionId}/probability-versions/${probabilityId}`,
  );
  await expect(page.getByRole("heading", { name: "Probability v2" })).toBeVisible();
  await expect(page.getByText("Remaining 100,000")).toBeVisible();
  await page.getByLabel("ppm").first().fill("600000");
  await expect(page.getByText("Current 1,000,000 ppm")).toBeVisible();
  await page.getByRole("button", { name: "Draft保存" }).click();
  await expect(page.getByText("Validation passed")).toBeVisible();
  expect(mutationHeaders?.["x-xsrf-token"]).toBe(csrf);
  expect(mutationHeaders?.["idempotency-key"]).toMatch(/^[0-9a-f-]{36}$/u);
  await page.getByRole("button", { name: "Publish Preflight" }).click();
  await expect(page.getByRole("dialog")).toBeVisible();
  await page.getByLabel("認証アプリの6桁コード").fill("654321");
  await page.getByRole("button", { name: "再認証", exact: true }).click();
  await expect.poll(() => preflightAttempts).toBe(2);
  expect(preflightKeys[0]).toMatch(/^[0-9a-f-]{36}$/u);
  expect(preflightKeys[1]).toBe(preflightKeys[0]);
  await page.getByRole("button", { name: "Probability Publish" }).click();
  await expect(
    page.getByRole("heading", { name: "Probabilityを公開しますか" }),
  ).toBeFocused();
  await expect(page.getByText("Gacha Version自体は公開されません。")).toBeVisible();
  await page.getByRole("button", { name: "公開", exact: true }).click();
  await expect(page.getByText("published", { exact: true })).toBeVisible();
  await expect(page.getByRole("button", { name: "Probability Publish" })).toHaveCount(0);
  await expect(page.getByText("2026-08-10T03:04:05Z")).toBeVisible();

  await page.setViewportSize({ height: 844, width: 390 });
  expect(
    await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth),
  ).toBe(true);
});

test("Owner updates LINE reply messages through preview and Fresh MFA retry", async ({
  page,
}) => {
  let revision = 1;
  let updateAttempts = 0;
  const idempotencyKeys: string[] = [];
  await installAdminApi(page, async (route) => {
    const request = route.request();
    const url = new URL(request.url());
    if (url.pathname.endsWith("/auth/session")) return json(route, adminSession("owner"));
    if (url.pathname.endsWith("/auth/permissions")) {
      return json(route, permissionResponse("owner"));
    }
    if (url.pathname.endsWith("/auth/reauthenticate")) {
      return json(route, {
        admin: adminIdentity(),
        authenticated: true,
        fresh_mfa_expires_in: 300,
      });
    }
    if (
      url.pathname.endsWith("/identity/line-messaging") &&
      request.method() === "GET"
    ) {
      return json(route, {
        data: {
          id: "01910191-0191-7191-8191-019101910199",
          linked_follow_message: "友だち追加が完了しました。",
          login_relative_path: "/login",
          pending_follow_message: "{login_url} からログインしてください。",
          reward_enabled: false,
          reward_expiration_days: 180,
          reward_point_amount: 0,
          revision,
          updated_at: "2026-07-29T00:00:00Z",
        },
        request_id: "01910191-0191-7191-8191-019101910193",
      });
    }
    if (url.pathname.endsWith("/identity/line-messaging/preview")) {
      const input = request.postDataJSON() as {
        linked_follow_message: string;
        pending_follow_message: string;
        reward_enabled: boolean;
        reward_expiration_days: number;
        reward_point_amount: number;
      };
      return json(route, {
        linked_follow_message: input.linked_follow_message,
        pending_follow_message: input.pending_follow_message.replace(
          "{login_url}",
          "/login",
        ),
        reward_enabled: input.reward_enabled,
        reward_expiration_days: input.reward_expiration_days,
        reward_point_amount: input.reward_point_amount,
        request_id: "01910191-0191-7191-8191-019101910193",
      });
    }
    if (
      url.pathname.endsWith("/identity/line-messaging") &&
      request.method() === "PUT"
    ) {
      updateAttempts += 1;
      idempotencyKeys.push(request.headers()["idempotency-key"] ?? "");
      if (updateAttempts === 1) {
        return json(route, {
          code: "FRESH_AUTHENTICATION_REQUIRED",
          request_id: "01910191-0191-7191-8191-019101910193",
          retryable: false,
          status: 403,
          title: "Fresh authentication is required.",
          type: "about:blank",
        }, 403, { "Content-Type": "application/problem+json" });
      }
      const input = request.postDataJSON() as {
        linked_follow_message: string;
        pending_follow_message: string;
        reward_enabled: boolean;
        reward_expiration_days: number;
        reward_point_amount: number;
      };
      revision = 2;
      return json(route, {
        data: {
          id: "01910191-0191-7191-8191-019101910199",
          linked_follow_message: input.linked_follow_message,
          login_relative_path: "/login",
          pending_follow_message: input.pending_follow_message,
          reward_enabled: input.reward_enabled,
          reward_expiration_days: input.reward_expiration_days,
          reward_point_amount: input.reward_point_amount,
          revision,
          updated_at: "2026-07-29T00:01:00Z",
        },
        idempotent_replay: false,
        request_id: "01910191-0191-7191-8191-019101910193",
      });
    }
    return route.fulfill({ status: 404 });
  });

  await page.goto("/settings/line");
  await expect(page.getByRole("heading", { name: "自動応答メッセージ" })).toBeVisible();
  await page
    .getByLabel("ログイン前ユーザー向け")
    .fill("{login_url} からLINEログインを完了してください。");
  await page.getByRole("checkbox", {
    name: "ポイント付与を有効にする",
  }).check();
  await page.getByLabel("付与ポイント数").fill("500");
  await page.getByLabel("有効期限日数").fill("365");
  await page.getByRole("button", { name: "プレビュー" }).click();
  await expect(
    page.getByText("/login からLINEログインを完了してください。"),
  ).toBeVisible();
  await expect(page.getByText("無償 500 Point／有効期限 365日")).toBeVisible();
  await page.getByRole("button", { name: "保存" }).click();
  await expect(page.getByRole("dialog")).toBeVisible();
  await page.getByLabel("認証アプリの6桁コード").fill("654321");
  await page.getByRole("button", { name: "再認証", exact: true }).click();
  await expect.poll(() => updateAttempts).toBe(2);
  expect(idempotencyKeys[0]).toMatch(/^[0-9a-f-]{36}$/u);
  expect(idempotencyKeys[1]).toBe(idempotencyKeys[0]);
});

async function installAdminApi(
  page: Page,
  handler: (route: Route) => Promise<unknown>,
): Promise<void> {
  await page.route(
    /\/admin\/api\/v2\/(?:auth|catalog|identity)\/[^?]+(?:\?.*)?$/u,
    handler,
  );
}

function adminIdentity(role: "owner" | "admin" | "operator" = "owner") {
  return {
    id: "01910191-0191-7191-8191-019101910191",
    mfa_verified: true,
    role,
    state: "active",
  };
}

function adminSession(role: "owner" | "admin" | "operator" = "owner") {
  return {
    admin: adminIdentity(role),
    authenticated: true,
    requires_mfa_enrollment: false,
  };
}

function permissionResponse(role: "owner" | "admin" | "operator") {
  const common = [
    "catalog.read",
    "shipping.request.manage",
    "content.read",
    "contact.read",
  ];
  return {
    permissions: [
      ...common,
      ...(role === "owner" ? ["qa.draw.manage"] : []),
      ...(role === "owner" ? ["identity.line.manage"] : []),
      ...(role !== "operator" ? ["catalog.manage"] : []),
      ...(role !== "operator" ? ["catalog.publish"] : []),
      ...(role !== "operator" ? ["reporting.financial.read"] : []),
    ],
    request_id: "01910191-0191-7191-8191-019101910193",
    role,
  };
}

async function json(
  route: Route,
  body: unknown,
  status = 200,
  headers: Record<string, string> = {},
): Promise<void> {
  await route.fulfill({
    body: JSON.stringify(body),
    headers: {
      "Cache-Control": "private, no-store",
      "Content-Type": "application/json",
      ...headers,
    },
    status,
  });
}
