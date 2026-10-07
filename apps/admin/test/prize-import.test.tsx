import { fireEvent, render, screen, waitFor } from "@testing-library/react";
import { afterEach, beforeEach, expect, it, vi } from "vitest";
import { CatalogPrizeImport } from "@/components/catalog/catalog-prize-import";
import { AdminApiClient, AdminApiError } from "@/lib/admin-api/client";

const plan = { plan_checksum: "a".repeat(64), summary: { create: 1, update: 0, unchanged: 0 }, rows: [], warnings: [] };
const props = { gachaId: "gacha", versionId: "version", revision: 4, canManage: true, enabled: true, onApplied: vi.fn(async () => {}) };

beforeEach(() => {
  vi.spyOn(AdminApiClient.prototype, "previewPrizeImport").mockResolvedValue(plan);
  vi.spyOn(AdminApiClient.prototype, "listPrizeImports").mockResolvedValue({ items: [], next_before: null });
  HTMLDialogElement.prototype.showModal = function () { this.setAttribute("open", ""); };
  HTMLDialogElement.prototype.close = function () { this.removeAttribute("open"); };
});
afterEach(() => vi.restoreAllMocks());

it("hides import and history for catalog.read-only operators", () => {
  const view = render(<CatalogPrizeImport {...props} canManage={false} enabled={false} />);
  expect(view.container).toBeEmptyDOMElement();
  expect(AdminApiClient.prototype.listPrizeImports).not.toHaveBeenCalled();
});

it("permits managers to view history even when the draft cannot be imported", () => {
  render(<CatalogPrizeImport {...props} enabled={false} />);
  expect(screen.queryByText("CSVで取り込む")).toBeNull();
  expect(screen.getByRole("button", { name: "取込履歴" })).toBeEnabled();
});

async function selectFile() {
  const file = new File(["synthetic csv"], "prizes.csv", { type: "text/csv" });
  Object.defineProperty(file, "arrayBuffer", { value: async () => new TextEncoder().encode("synthetic csv").buffer });
  fireEvent.change(screen.getByLabelText(/景品CSV/u), { target: { files: [file] } });
  await waitFor(() => expect(screen.getByRole("button", { name: "プレビュー" })).toBeEnabled());
  fireEvent.click(screen.getByRole("button", { name: "プレビュー" }));
}

it("invalidates the plan when another file or version revision is selected", async () => {
  const view = render(<CatalogPrizeImport {...props} />);
  fireEvent.click(screen.getByText("CSVで取り込む"));
  await selectFile();
  await screen.findByRole("button", { name: "取込を確認" });
  view.rerender(<CatalogPrizeImport {...props} revision={5} />);
  expect(screen.queryByRole("button", { name: "取込を確認" })).toBeNull();
  await selectFile();
  await screen.findByRole("button", { name: "取込を確認" });
  fireEvent.change(screen.getByLabelText(/景品CSV/u), { target: { files: [] } });
  expect(screen.queryByRole("button", { name: "取込を確認" })).toBeNull();
});

it("shows structured validation and disables apply", async () => {
  vi.spyOn(AdminApiClient.prototype, "previewPrizeImport").mockRejectedValue(new AdminApiError(422, "CSV_VALIDATION_FAILED", null, null, false, {
    code: "CSV_VALIDATION_FAILED", errors: [{ row: 2, column: "枚数", code: "VALUE_INVALID", message: "半角整数で指定してください。" }], error_count: 201,
  }));
  render(<CatalogPrizeImport {...props} />);
  fireEvent.click(screen.getByText("CSVで取り込む"));
  await selectFile();
  await screen.findByText(/エラー 201件/u);
  expect(screen.getByText(/半角整数/u)).toBeInTheDocument();
  expect(screen.queryByRole("button", { name: "取込を確認" })).toBeNull();
});

it("retries a failed apply with the same idempotency key and reloads prizes and history", async () => {
  const apply = vi.spyOn(AdminApiClient.prototype, "applyPrizeImport")
    .mockRejectedValueOnce(new AdminApiError(0, "NETWORK_ERROR", null, null, true))
    .mockResolvedValueOnce({ data: { id: "import", gacha_version_id: "version", gacha_version_revision: 5, summary: plan.summary }, idempotent_replay: true });
  render(<CatalogPrizeImport {...props} />);
  fireEvent.click(screen.getByText("CSVで取り込む"));
  await selectFile();
  fireEvent.click(await screen.findByRole("button", { name: "取込を確認" }));
    fireEvent.click(screen.getByRole("button", { name: /^取り込む$/ }));
  await screen.findByRole("alert");
  fireEvent.click(screen.getByRole("button", { name: "取込を確認" }));
    fireEvent.click(screen.getByRole("button", { name: /^取り込む$/ }));
  await screen.findByText(/取込完了/u);
  expect(apply.mock.calls[0][3]).toBe(apply.mock.calls[1][3]);
  expect(props.onApplied).toHaveBeenCalled();
  expect(await screen.findByText("取込履歴はありません。")).toBeInTheDocument();
});
