import assert from "node:assert/strict";
import { readdir, readFile } from "node:fs/promises";

const sourceRoot = new URL("../src/", import.meta.url);
const prohibited = [
  "globalThis.fetch(",
  "node:http",
  "node:https",
  "node:net",
  "node:tls",
  "undici",
  "XMLHttpRequest",
  "WebSocket",
];

async function sourceFiles(directory) {
  const entries = await readdir(directory, { withFileTypes: true });
  const result = [];
  for (const entry of entries) {
    const target = new URL(entry.name + (entry.isDirectory() ? "/" : ""), directory);
    if (entry.isDirectory()) {
      result.push(...(await sourceFiles(target)));
    } else if (entry.name.endsWith(".ts")) {
      result.push(target);
    }
  }
  return result;
}

for (const file of await sourceFiles(sourceRoot)) {
  const source = await readFile(file, "utf8");
  for (const value of prohibited) {
    assert.equal(source.includes(value), false, "Testkit source must not access real network");
  }
}
