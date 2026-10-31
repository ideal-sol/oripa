import { fireEvent, render, screen, waitFor, within } from "@testing-library/react";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

import {
  AdminShippingDetail,
  AdminShippingList,
} from "@/components/shipping/admin-shipping-management";
import { AdminApiClient, AdminApiError } from "@/lib/admin-api/client";
import type {
  AdminShippingRequestCollection,
  AdminShippingRequestDetail,
} from "@/lib/admin-api/generated";

describe("Admin Shipping management", () => {
  beforeEach(() => {
    vi.spyOn(AdminApiClient.prototype, "listAdminShippingRequests")
      .mockResolvedValue(collection());
    vi.spyOn(AdminApiClient.prototype, "getAdminShippingRequest")
      .mockResolvedValue(detail());
    vi.spyOn(AdminApiClient.prototype, "updateAdminShippingRequest")
      .mockResolvedValue({ ...detail(), status: "packing" });
    vi.spyOn(AdminApiClient.prototype, "exportAdminShippingRequests")
      .mockResolvedValue({ blob: new Blob(["csv"]), filename: "selected.csv" });
    vi.spyOn(AdminApiClient.prototype, "exportAdminShippingRequestsB2")
      .mockResolvedValue({ blob: new Blob(["b2"]), filename: "b2cloud-shipping-20261101-0001.csv", warnings: [], warningCount: 0 });
    vi.spyOn(URL, "createObjectURL").mockReturnValue("blob:shipping");
    vi.spyOn(URL, "revokeObjectURL").mockImplementation(() => undefined);
    vi.spyOn(HTMLAnchorElement.prototype, "click").mockImplementation(() => undefined);
  });

  afterEach(() => {
    vi.restoreAllMocks();
  });

  it("defaults to 未配送 and submits status and created_at date filters", async () => {
    const list = vi.mocked(AdminApiClient.prototype.listAdminShippingRequests);
    render(<AdminShippingList />);

    await waitFor(() => expect(list).toHaveBeenCalled());
    expect(list).toHaveBeenCalledWith(
      expect.objectContaining({ status: "requested" }),
      expect.any(AbortSignal),
    );
    fireEvent.change(screen.getByRole("combobox", { name: "状態" }), {
      target: { value: "packing" },
    });
    fireEvent.change(screen.getByLabelText("作成日時 From"), {
      target: { value: "2026-09-01" },
    });
    fireEvent.change(screen.getByLabelText("作成日時 To"), {
      target: { value: "2026-09-03" },
    });
    fireEvent.click(screen.getByRole("button", { name: "検索" }));

    await waitFor(() => expect(list).toHaveBeenLastCalledWith(
      expect.objectContaining({
        date_from: "2026-09-01",
        date_to: "2026-09-03",
        status: "packing",
      }),
      expect.any(AbortSignal),
    ));
  });

  it("exports only checked rows and links each row to its detail", async () => {
    const exportSelected = vi.mocked(AdminApiClient.prototype.exportAdminShippingRequests);
    render(<AdminShippingList />);
    await screen.findByRole("link", { name: `配送 ${uuid("1")} の詳細` });

    const rows = screen.getAllByRole("row");
    expect(within(rows[1]).getByRole("link", { name: `配送 ${uuid("1")} の詳細` }))
      .toHaveAttribute("href", `/shipping/${uuid("1")}`);
    fireEvent.click(screen.getByRole("checkbox", { name: `配送 ${uuid("1")} を選択` }));
    fireEvent.click(screen.getByRole("button", { name: "選択した配送をCSV出力" }));

    await waitFor(() => expect(exportSelected).toHaveBeenCalledWith([uuid("1")]));
    expect(exportSelected).not.toHaveBeenCalledWith(expect.arrayContaining([uuid("2")]));
  });

  it("places B2 immediately after the existing CSV button and disables it without selection", async () => {
    render(<AdminShippingList />);
    await screen.findByRole("link", { name: `配送 ${uuid("1")} の詳細` });
    const old = screen.getByRole("button", { name: "選択した配送をCSV出力" });
    const b2 = screen.getByRole("button", { name: "B2クラウド形式でCSV出力" });
    expect(old.nextElementSibling).toBe(b2);
    expect(b2).toBeDisabled();
    fireEvent.click(screen.getByRole("checkbox", { name: `配送 ${uuid("1")} を選択` }));
    expect(b2).toBeEnabled();
  });

  it("downloads B2 with its own filename, retains selection and filters, and shows unique warning IDs and codes", async () => {
    vi.mocked(AdminApiClient.prototype.exportAdminShippingRequestsB2).mockResolvedValue({
      blob: new Blob(["b2"]), filename: "b2cloud-shipping-20261101-0001.csv", warningCount: 1,
      warnings: [{ shipping_id: uuid("1"), codes: ["NAME_TOO_LONG", "UNSUPPORTED_CHARACTER"] }],
    });
    let filename = "";
    vi.mocked(HTMLAnchorElement.prototype.click).mockImplementation(function (this: HTMLAnchorElement) { filename = this.download; });
    render(<AdminShippingList initialStatus="packing" />);
    await screen.findByRole("link", { name: `配送 ${uuid("1")} の詳細` });
    const checkbox = screen.getByRole("checkbox", { name: `配送 ${uuid("1")} を選択` });
    fireEvent.click(checkbox);
    fireEvent.click(screen.getByRole("button", { name: "B2クラウド形式でCSV出力" }));
    const warnings = await screen.findByRole("region", { name: "B2出力の警告" });
    expect(warnings).toHaveTextContent("B2クラウドで修正が必要な配送が1件あります");
    expect(warnings).toHaveTextContent(uuid("1"));
    expect(warnings).toHaveTextContent("NAME_TOO_LONG / UNSUPPORTED_CHARACTER");
    expect(warnings).not.toHaveTextContent("合成テスト受取人");
    expect(filename).toBe("b2cloud-shipping-20261101-0001.csv");
    expect(URL.revokeObjectURL).toHaveBeenCalledWith("blob:shipping");
    expect(checkbox).toBeChecked();
    expect(screen.getByRole("combobox", { name: "状態" })).toHaveValue("packing");
    expect(AdminApiClient.prototype.updateAdminShippingRequest).not.toHaveBeenCalled();
    expect(AdminApiClient.prototype.exportAdminShippingRequests).not.toHaveBeenCalled();
  });

  it("disables B2 while generating and renders the configuration error in Japanese", async () => {
    let reject!: (reason: unknown) => void;
    vi.mocked(AdminApiClient.prototype.exportAdminShippingRequestsB2).mockReturnValue(new Promise((_, fail) => { reject = fail; }));
    render(<AdminShippingList />);
    await screen.findByRole("link", { name: `配送 ${uuid("1")} の詳細` });
    fireEvent.click(screen.getByRole("checkbox", { name: `配送 ${uuid("1")} を選択` }));
    fireEvent.click(screen.getByRole("button", { name: "B2クラウド形式でCSV出力" }));
    expect(screen.getByRole("button", { name: "B2出力中" })).toBeDisabled();
    reject(new AdminApiError(503, "B2_EXPORT_NOT_CONFIGURED", null, null, false));
    expect(await screen.findByRole("alert")).toHaveTextContent("B2クラウドの出力設定が未設定です");
    expect(HTMLAnchorElement.prototype.click).not.toHaveBeenCalled();
    expect(screen.getByRole("button", { name: "B2クラウド形式でCSV出力" })).toBeEnabled();
  });

  it("renders detail and updates carrier, tracking, and a reverse status transition", async () => {
    const update = vi.mocked(AdminApiClient.prototype.updateAdminShippingRequest);
    render(<AdminShippingDetail shippingRequestId={uuid("1")} />);

    await screen.findByRole("heading", { name: "配送情報" });
    expect(screen.getByText("配送商品A")).toBeVisible();
    expect(screen.getByText("合成テスト受取人")).toBeVisible();
    fireEvent.change(screen.getByRole("combobox", { name: "配送状態" }), {
      target: { value: "packing" },
    });
    fireEvent.change(screen.getByRole("textbox", { name: "配送会社" }), {
      target: { value: "updated-carrier" },
    });
    fireEvent.change(screen.getByRole("textbox", { name: "追跡番号" }), {
      target: { value: "updated-tracking" },
    });
    fireEvent.click(screen.getByRole("button", { name: "更新する" }));

    await waitFor(() => expect(update).toHaveBeenCalledWith(
      uuid("1"),
      expect.objectContaining({
        carrier_code: "updated-carrier",
        status: "packing",
        tracking_number: "updated-tracking",
      }),
    ));
    expect(await screen.findByText("配送情報を更新しました。")).toBeVisible();
  });

  it("shows a useful error when a status update is rejected", async () => {
    vi.mocked(AdminApiClient.prototype.updateAdminShippingRequest).mockRejectedValue(
      new AdminApiError(409, "SHIPPING_TRANSITION_NOT_ALLOWED", uuid("9"), null, false),
    );
    render(<AdminShippingDetail shippingRequestId={uuid("1")} />);
    await screen.findByRole("heading", { name: "配送情報" });
    fireEvent.click(screen.getByRole("button", { name: "更新する" }));

    expect(await screen.findByRole("alert"))
      .toHaveTextContent("現在状態との整合性を確認して再実行してください。");
  });
});

function collection(): AdminShippingRequestCollection {
  return {
    items: [
      summary("1", "packing"),
      summary("2", "requested"),
    ],
    next_cursor: null,
  };
}

function summary(last: string, status: "packing" | "requested") {
  return {
    carrier_code: status === "packing" ? "fixture-carrier" : null,
    created_at: "2026-09-03T01:00:00Z",
    id: uuid(last),
    prize_count: 1,
    requested_at: "2026-09-03T01:00:00Z",
    shipped_at: null,
    status,
    user_id: uuid("8"),
  } as const;
}

function detail(): AdminShippingRequestDetail {
  return {
    ...summary("1", "packing"),
    carrier_code: "fixture-carrier",
    items: [{
      name: "配送商品A",
      product_id: uuid("3"),
      user_prize_id: uuid("4"),
    }],
    prize_ids: [uuid("4")],
    shipped_at: "2026-09-03T02:00:00Z",
    shipping_address: {
      building: "テストビル",
      city: "テスト市",
      phone_number: "000-0000-0000",
      postal_code: "000-0000",
      prefecture: "テスト県",
      recipient_name: "合成テスト受取人",
      street: "テスト町1-2-3",
    },
    status: "shipped",
    status_history: [{
      from_status: "packing",
      occurred_at: "2026-09-03T02:00:00Z",
      reason_code: "shipment_confirmed",
      to_status: "shipped",
    }],
    tracking_number: "fixture-tracking",
  };
}

function uuid(last: string): string {
  return `01910191-0191-7191-8191-01910191019${last}`;
}
