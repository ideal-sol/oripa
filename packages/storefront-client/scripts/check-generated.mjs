import { mkdtemp, readFile, rm } from "node:fs/promises";
import { tmpdir } from "node:os";
import { join } from "node:path";
import { spawnSync } from "node:child_process";

const temporary = await mkdtemp(join(tmpdir(), "oripa-storefront-generated-"));
const generated = join(temporary, "public.ts");
try {
  const result = spawnSync(
    "openapi-typescript",
    ["../../openapi/bundled/public.openapi.json", "--output", generated],
    {
      encoding: "utf8",
      shell: false,
      stdio: ["ignore", "pipe", "pipe"],
    },
  );
  if (result.status !== 0) {
    process.stderr.write(result.stderr);
    process.exitCode = 1;
  } else {
    const [expected, actual] = await Promise.all([
      readFile(new URL("../src/generated/public.ts", import.meta.url), "utf8"),
      readFile(generated, "utf8"),
    ]);
    if (expected !== actual) {
      console.error(
        "Generated Public OpenAPI types differ. Run pnpm storefront:generate.",
      );
      process.exitCode = 1;
    }
  }
} finally {
  await rm(temporary, { recursive: true, force: true });
}
