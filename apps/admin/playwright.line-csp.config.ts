import { defineConfig, devices } from "@playwright/test";

// Compile the Admin locally once before running. A real production-mode server
// enforces the complete proxy CSP; no bypass or development policy replacement.
export default defineConfig({
  testDir: "./e2e", testMatch: "admin-line-ux.spec.ts", grep: /CSP enforced/,
  workers: 1, timeout: 90_000, expect: { timeout: 15_000 },
  webServer: { command: "pnpm exec next start --hostname 127.0.0.1 --port 3400", url: "http://127.0.0.1:3400/api/health", reuseExistingServer: false },
  outputDir: "/tmp/oripa-lineux-csp-playwright", reporter: [["line"]],
  use: { baseURL: "http://127.0.0.1:3400", ...devices["Desktop Chrome"], bypassCSP: false, trace: "retain-on-failure" },
});
