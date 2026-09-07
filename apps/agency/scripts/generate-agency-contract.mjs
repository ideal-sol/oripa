import { readFile, writeFile, mkdir } from "node:fs/promises";
import { createHash } from "node:crypto";
const source = await readFile(new URL("../../../openapi/bundled/agency.openapi.json", import.meta.url));
const contract = JSON.parse(source);
function type(schema) {
  if (schema.$ref) return schema.$ref.split("/").at(-1);
  if (schema.const !== undefined) return JSON.stringify(schema.const);
  if (schema.enum) return schema.enum.map(value => JSON.stringify(value)).join(" | ");
  if (schema.anyOf) return schema.anyOf.map(type).join(" | ");
  if (Array.isArray(schema.type)) return schema.type.map(value => type({ ...schema, type: value })).join(" | ");
  if (schema.type === "object") return "{ " + Object.entries(schema.properties ?? {}).map(([key, value]) => JSON.stringify(key) + (schema.required?.includes(key) ? "" : "?") + ": " + type(value)).join("; ") + " }";
  if (schema.type === "array") return "(" + type(schema.items) + ")[]";
  if (schema.type === "integer" || schema.type === "number") return "number";
  if (["string", "boolean", "null"].includes(schema.type)) return schema.type;
  throw new Error("Unsupported Agency schema");
}
const operations = Object.fromEntries(Object.entries(contract.paths).flatMap(([path, item]) => Object.entries(item).map(([method, operation]) => [operation.operationId, { path: contract.servers[0].url + path, method: method.toUpperCase() }])));
const generated = "export const contractSha256 = " + JSON.stringify(createHash("sha256").update(source).digest("hex")) + ";\n" + Object.entries(contract.components.schemas).map(([name, schema]) => "export type " + name + " = " + type(schema) + ";").join("\n") + "\nexport const operations = " + JSON.stringify(operations, null, 2) + " as const;\n";
const output = new URL("../src/lib/agency-api/generated.ts", import.meta.url);
if (process.argv.includes("--check")) {
  if (await readFile(output, "utf8") !== generated) throw new Error("Agency generated contract drift");
} else {
  await mkdir(new URL("../src/lib/agency-api/", import.meta.url), { recursive: true });
  await writeFile(output, generated);
}
