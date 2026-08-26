import { expect, test, type Page, type Route } from "@playwright/test";

const userId = "01910191-0191-7191-8191-019101910191";
const csrf = "b".repeat(64);

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

test("Operator reads User list, detail, and acquired-prize history", async ({ page }) => {
  const consoleErrors: string[] = [];
  const pageErrors: string[] = [];
  const gatewayErrors: number[] = [];
  page.on("console", (message) => {
    if (message.type() === "error") consoleErrors.push(message.text());
  });
  page.on("pageerror", (error) => pageErrors.push(error.message));
  page.on("response", (response) => {
    if ([500, 502, 504].includes(response.status())) gatewayErrors.push(response.status());
  });

  const response = await page.goto("/users");
  expect(response?.status()).toBe(200);
  await expect(page.getByRole("heading", { name: "ユーザー一覧" })).toBeVisible();
  await expect(page.getByRole("columnheader")).toHaveText([
    "ID", "ユーザー名", "状態", "合計残高", "有償P", "無償P", "登録日", "詳細",
  ]);
  await expect(page.getByText("テストユーザー")).toBeVisible();
  await expect(page.getByText("300 pt")).toBeVisible();
  await expect(page.getByRole("button", { name: "ユーザー", exact: true }))
    .toHaveAttribute("aria-expanded", "true");
  await expect(page.locator("#admin-nav-users").getByRole("link", { name: "一覧" }))
    .toBeVisible();
  await expect(page.locator("#admin-nav-users").getByRole("link", { name: "履歴" }))
    .toHaveCount(0);

  await page.getByRole("link", { name: "テストユーザーの詳細" }).click();
  await expect(page).toHaveURL(new RegExp(`/users/${userId}$`, "u"));
  await expect(page.getByRole("heading", { name: "基本情報" })).toBeVisible();
  await expect(page.getByText("user@example.test")).toBeVisible();
  await expect(page.getByText("ユーザー保有景品")).toHaveCount(0);
  await expect(page.getByRole("heading", { name: "テストユーザー" })).toHaveCount(0);
  await page.getByRole("link", { name: "ガチャ履歴を表示" }).click();
  await expect(page).toHaveURL(new RegExp(`/users/${userId}/gacha-history$`, "u"));
  await expect(page.getByRole("heading", { name: "ユーザーガチャ履歴" })).toBeVisible();
  await expect(page.getByText("テストガチャ")).toBeVisible();
  await expect(page.getByText("景品A")).toBeVisible();
  await expect(page.getByRole("link", { name: "ユーザー詳細へ" })).toBeVisible();

  expect(consoleErrors).toEqual([]);
  expect(pageErrors).toEqual([]);
  expect(gatewayErrors).toEqual([]);
});

test("mobile User tables remain inside a keyboard-scrollable region", async ({ page }) => {
  await page.setViewportSize({ height: 844, width: 390 });
  await page.goto("/users");
  const region = page.locator(".admin-user-table-region");
  await expect(region).toHaveAttribute("tabindex", "0");
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth))
    .toBe(true);

  const trigger = page.getByRole("button", { name: "ナビゲーションを開く" });
  await trigger.click();
  await page.keyboard.press("Escape");
  await expect(trigger).toBeFocused();
});

test("User filters honor explicit query, cursor conditions, reset, and route defaults", async ({ page }) => {
  await page.unroute(/\/admin\/api\/v2\/.*$/u);
  const queries: URLSearchParams[] = [];
  await page.route(/\/admin\/api\/v2\/.*$/u, async (route) => {
    const url = new URL(route.request().url());
    if (url.pathname.endsWith("/auth/session")) return json(route, adminSession());
    if (url.pathname.endsWith("/auth/permissions")) {
      return json(route, { permissions: [], request_id: uuid("9"), role: "operator" });
    }
    if (url.pathname.endsWith("/users")) {
      queries.push(new URLSearchParams(url.searchParams));
      const failed = url.searchParams.get("status") === "verification_failed";
      const hasCursor = url.searchParams.has("cursor");
      return json(route, {
        items: hasCursor ? [] : [{
          ...userSummary(),
          display_name: failed ? "認証失敗ユーザー" : "有効ユーザー",
          status: failed ? "verification_failed" : "active",
        }],
        next_cursor: failed && !hasCursor ? "djE6MTA=" : null,
        request_id: uuid("9"),
      });
    }
    return route.fulfill({ status: 404 });
  });

  await page.goto(`/users?status=verification_failed&user_id=${userId}&date_from=2026-08-01&date_to=2026-08-31`);
  await expect(page.getByLabel("状態")).toHaveValue("verification_failed");
  await expect(page.getByLabel("User ID")).toHaveValue(userId);
  await expect(page.getByText("認証失敗ユーザー")).toBeVisible();
  await page.getByRole("button", { name: "次の50件を表示" }).click();
  await expect.poll(() => queries.length).toBe(2);
  for (const query of queries.slice(0, 2)) {
    expect(query.get("status")).toBe("verification_failed");
    expect(query.get("user_id")).toBe(userId);
    expect(query.get("date_from")).toBe("2026-08-01");
    expect(query.get("date_to")).toBe("2026-08-31");
  }
  expect(queries[1].get("cursor")).toBe("djE6MTA=");

  await page.getByRole("button", { name: "条件を解除" }).click();
  await expect(page.getByLabel("状態")).toHaveValue("active");
  await expect(page.getByLabel("User ID")).toHaveValue("");
  await expect(page.getByText("有効ユーザー")).toBeVisible();
  expect(queries.at(-1)?.get("status")).toBe("active");
  expect(queries.at(-1)?.has("user_id")).toBe(false);

  await page.goto("/");
  await page.goto("/users");
  await expect(page.getByLabel("状態")).toHaveValue("active");
  expect(queries.at(-1)?.get("status")).toBe("active");
});

test("Admin changes User state and the detail refetches canonical state", async ({ page }) => {
  await page.unroute(/\/admin\/api\/v2\/.*$/u);
  let state = "active";
  let revision = 1;
  let mutationBody: Record<string, unknown> | null = null;
  await page.route(/\/admin\/api\/v2\/.*$/u, async (route) => {
    const path = new URL(route.request().url()).pathname;
    if (path.endsWith("/auth/session")) return json(route, adminSession());
    if (path.endsWith("/auth/permissions")) {
      return json(route, { permissions: ["user.state.manage"], request_id: uuid("9"), role: "admin" });
    }
    if (path.endsWith("/auth/reauthenticate")) {
      return json(route, { admin: adminSession().admin, authenticated: true });
    }
    if (path.endsWith(`/users/${userId}/state`)) {
      mutationBody = route.request().postDataJSON() as Record<string, unknown>;
      state = "suspended";
      revision = 2;
      return json(route, {
        data: { user_id: userId, status: state, state_revision: revision, updated_at: "2026-09-02T00:00:00Z" },
        idempotent_replay: false,
        request_id: uuid("8"),
      });
    }
    if (path.endsWith(`/users/${userId}`)) {
      return json(route, { data: userDetail(state, revision), request_id: uuid("9") });
    }
    return route.fulfill({ status: 404 });
  });

  await page.goto(`/users/${userId}`);
  await page.getByRole("button", { name: "状態を変更" }).click();
  await page.getByLabel("変更後の状態").selectOption("suspended");
  await page.getByLabel("変更理由").fill("Preview support review.");
  await page.getByRole("button", { name: "確認して変更" }).click();
  await page.getByLabel("現在のパスワード").fill("not-persisted");
  await page.getByRole("button", { name: "再認証", exact: true }).click();

  await expect(page.locator(".admin-user-state-summary").getByText("停止", { exact: true }))
    .toBeVisible();
  expect(mutationBody).toEqual({
    expected_revision: 1,
    reason: "Preview support review.",
    status: "suspended",
  });
});

test("Owner enables an indefinite Test User from User detail", async ({ page }) => {
  await page.unroute(/\/admin\/api\/v2\/.*$/u);
  let mode: ReturnType<typeof qaMode> | null = null;
  let mutationBody: Record<string, unknown> | null = null;
  await page.route(/\/admin\/api\/v2\/.*$/u, async (route) => {
    const request = route.request();
    const path = new URL(request.url()).pathname;
    if (path.endsWith("/auth/session")) return json(route, ownerSession());
    if (path.endsWith("/auth/permissions")) {
      return json(route, { permissions: ["qa.draw.manage"], request_id: uuid("9"), role: "owner" });
    }
    if (path.endsWith("/auth/reauthenticate")) {
      return json(route, { admin: ownerSession().admin, authenticated: true });
    }
    if (path.endsWith(`/users/${userId}/qa-mode`)) {
      return json(route, { mode, user_id: userId });
    }
    if (path.endsWith(`/qa/test-users/${userId}`) && request.method() === "PUT") {
      mutationBody = request.postDataJSON() as Record<string, unknown>;
      mode = qaMode();
      return json(route, { data: mode, idempotent_replay: false, request_id: uuid("8") });
    }
    if (path.endsWith(`/users/${userId}`)) {
      return json(route, { data: userDetail("active", 1), request_id: uuid("9") });
    }
    return route.fulfill({ status: 404 });
  });

  await page.goto(`/users/${userId}`);
  await expect(page.getByRole("heading", { name: "テストユーザー" })).toBeVisible();
  await expect(page.locator(".admin-user-qa-status")).toHaveText("OFF");
  await page.getByLabel("設定理由").fill("演出確認用のPreview QA User");
  await page.getByRole("button", { name: "ONにする" }).click();
  await page.getByLabel("認証アプリの6桁コード").fill("123456");
  await page.getByRole("button", { name: "再認証", exact: true }).click();

  await expect(page.getByText("ON（無期限）", { exact: true })).toBeVisible();
  await expect(page.getByRole("region", { name: "テストユーザー" }).getByRole("status"))
    .toContainText("手動でOFFにするまで有効");
  expect(mutationBody).toEqual({ reason: "演出確認用のPreview QA User" });
});

async function installApi(page: Page): Promise<void> {
  await page.route(/\/admin\/api\/v2\/.*$/u, async (route) => {
    const url = new URL(route.request().url());
    const path = url.pathname;
    if (path.endsWith("/auth/session")) {
      return json(route, {
        admin: { id: userId, mfa_verified: false, role: "operator", state: "active" },
        authenticated: true,
        mfa_required: false,
        requires_mfa_enrollment: false,
      });
    }
    if (path.endsWith("/auth/permissions")) {
      return json(route, { permissions: [], request_id: uuid("9"), role: "operator" });
    }
    if (path.endsWith(`/users/${userId}/gacha-history`)) {
      return json(route, {
        items: [historyItem()], next_cursor: null, request_id: uuid("9"), user_id: userId,
      });
    }
    if (path.endsWith(`/users/${userId}`)) {
      return json(route, {
        data: {
          ...userSummary(),
          email: "user@example.test",
          email_verified_at: "2026-08-03T00:00:00Z",
          state_revision: 1,
          updated_at: "2026-08-03T01:00:00Z",
        },
        request_id: uuid("9"),
      });
    }
    if (path.endsWith("/users")) {
      return json(route, { items: [userSummary()], next_cursor: null, request_id: uuid("9") });
    }
    return route.fulfill({ status: 404 });
  });
}

function adminSession() {
  return {
    admin: { id: userId, mfa_verified: false, role: "admin", state: "active" },
    authenticated: true,
    mfa_required: false,
    requires_mfa_enrollment: false,
  };
}

function ownerSession() {
  return {
    admin: { id: userId, mfa_verified: true, role: "owner", state: "active" },
    authenticated: true,
    mfa_required: true,
    requires_mfa_enrollment: false,
  };
}

function qaMode() {
  return {
    disabled_at: null,
    ends_at: null,
    id: uuid("8"),
    is_active: true,
    is_enabled: true,
    reason: "演出確認用のPreview QA User",
    revision: 1,
    starts_at: null,
    updated_at: "2026-09-04T00:00:00Z",
  };
}

function userDetail(state: string, stateRevision: number) {
  return {
    ...userSummary(),
    email: "user@example.test",
    email_verified_at: "2026-08-03T00:00:00Z",
    state_revision: stateRevision,
    status: state,
    tag_assignment_revision: 1,
    tags: [],
    updated_at: "2026-09-02T00:00:00Z",
  };
}

function userSummary() {
  return {
    created_at: "2026-08-03T00:00:00Z",
    display_name: "テストユーザー",
    id: userId,
    point_balance: { free_balance: 200, paid_balance: 100, total_balance: 300 },
    status: "active",
  };
}

function historyItem() {
  return {
    acquired_at: "2026-08-03T00:00:00Z",
    draw_result_id: uuid("2"),
    exchange_point_snapshot: 500,
    exchanged_point_amount: null,
    gacha_id: uuid("3"),
    gacha_title: "テストガチャ",
    gacha_version_id: uuid("4"),
    id: uuid("5"),
    prize_id: uuid("6"),
    prize_name: "景品A",
    rank_id: uuid("7"),
    rank_name: "Sランク",
    status: "stored",
    storage_expires_at: "2026-10-02T00:00:00Z",
    terminal_at: null,
  };
}

function uuid(last: string): string {
  return `01910191-0191-7191-8191-01910191019${last}`;
}

async function json(route: Route, body: unknown): Promise<void> {
  await route.fulfill({
    body: JSON.stringify(body),
    headers: { "Cache-Control": "private, no-store", "Content-Type": "application/json" },
    status: 200,
  });
}
