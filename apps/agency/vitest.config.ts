import { defineConfig } from "vitest/config";
export default defineConfig({ test: { environment: "jsdom", environmentOptions: { jsdom: { url: "https://agency.example.test" } }, include: ["test/**/*.test.{ts,tsx}"], restoreMocks: true, setupFiles: ["./test/setup.ts"] } });
