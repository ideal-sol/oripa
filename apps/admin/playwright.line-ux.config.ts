import { defineConfig, devices } from "@playwright/test";
// Uses a separately started local development server; no application image build.
export default defineConfig({
  testDir: "./e2e", testMatch: "admin-line-ux.spec.ts", workers: 1,
  webServer: { command: "pnpm exec next dev --webpack --hostname 127.0.0.1 --port 3398 || pnpm exec next dev --webpack --hostname 127.0.0.1 --port 3398", url: "http://127.0.0.1:3398/api/health", reuseExistingServer: true, timeout: 120_000 },
  outputDir: "/tmp/oripa-lineux-playwright", reporter: [["line"]],
  expect: { timeout: 30_000 },
  // Next webpack development scripts use eval. This synthetic UI harness does
  // not assert runtime CSP; line-chat-csp.test.ts checks actual proxy headers.
  use: { baseURL: "http://127.0.0.1:3398", ...devices["Desktop Chrome"], bypassCSP: true, trace: "retain-on-failure" },
});
