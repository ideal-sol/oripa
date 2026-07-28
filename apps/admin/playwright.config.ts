import { defineConfig, devices } from "@playwright/test";

const port = 3300;

export default defineConfig({
  testDir: "./e2e",
  outputDir: "/tmp/oripa-mig-060a-playwright",
  reporter: [["line"]],
  use: {
    baseURL: `http://127.0.0.1:${port}`,
    trace: "retain-on-failure",
  },
  webServer: {
    command: `pnpm build && pnpm start --hostname 127.0.0.1 --port ${port}`,
    reuseExistingServer: false,
    timeout: 120_000,
    url: `http://127.0.0.1:${port}/api/health`,
  },
  projects: [
    {
      name: "chromium",
      use: { ...devices["Desktop Chrome"] },
    },
  ],
});
