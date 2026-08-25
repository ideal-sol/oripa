import { fireEvent, render, screen, waitFor, within } from "@testing-library/react";
import { afterEach, describe, expect, it, vi } from "vitest";

import { AdminPaymentHistory } from "@/components/payments/admin-payment-history";
import { AdminApiClient, AdminApiError } from "@/lib/admin-api/client";
import type {
  AdminPayment,
  AdminPaymentCollection,
  AdminPaymentMethod,
  AdminPaymentStatus,
} from "@/lib/admin-api/generated";

const statuses: AdminPaymentStatus[] = [
  "created",
  "requires_action",
  "processing",
  "succeeded",
  "failed",
  "canceled",
  "expired",
];

const methods: AdminPaymentMethod[] = [
  "credit_card",
  "paypay",
  "konbini",
  "virtual_account",
];

describe("Admin Payment History", () => {
  afterEach(() => vi.restoreAllMocks());

  it("renders every canonical status, method, User, amount, and JST timestamp", async () => {
    vi.spyOn(AdminApiClient.prototype, "listAdminPayments")
      .mockResolvedValue(collection(statuses.map((status, index) => payment(index + 1, status))));

    render(<AdminPaymentHistory initialStatus="all" />);

    const rows = (await screen.findAllByRole("row")).slice(1);
    expect(rows).toHaveLength(7);
    expect(rows.map((row) => within(row).getByText(statusLabel(row)).textContent)).toEqual([
      "作成済み",
      "支払操作待ち",
      "未払い",
      "決済成功",
      "失敗",
      "キャンセル",
      "期限切れ",
    ]);
    for (const label of ["クレジットカード", "PayPay", "コンビニ決済", "銀行振込"]) {
      expect(screen.getAllByText(label).length).toBeGreaterThan(0);
    }
    expect(screen.getAllByText("運営確認ユーザー")).toHaveLength(7);
    expect(screen.getAllByText(/1,00[1-7]/u)).toHaveLength(7);
    expect(screen.getAllByText("2026/08/25 0:00").length).toBeGreaterThan(0);
    const userLinks = screen.getAllByRole("link", { name: /01910191/u });
    expect(userLinks).toHaveLength(7);
    for (const userLink of userLinks) {
      expect(userLink).toHaveAttribute("href", `/users/${uuid("9")}`);
    }
  });

  it("defaults the all-User list to succeeded across all methods", async () => {
    const reader = vi.spyOn(AdminApiClient.prototype, "listAdminPayments")
      .mockResolvedValue(collection([payment(4, "succeeded")]));

    render(<AdminPaymentHistory />);

    expect(await screen.findByText("決済成功")).toBeVisible();
    expect(screen.getByLabelText("決済状態")).toHaveValue("succeeded");
    expect(screen.getByLabelText("支払方法")).toHaveValue("all");
    expect(reader).toHaveBeenCalledWith(
      expect.objectContaining({ payment_method: undefined, status: "succeeded" }),
      expect.any(AbortSignal),
    );
  });

  it("exposes the canonical required status and method filters", async () => {
    const reader = vi.spyOn(AdminApiClient.prototype, "listAdminPayments")
      .mockResolvedValue(collection([]));

    render(<AdminPaymentHistory />);
    await screen.findByRole("heading", { name: "決済履歴はありません" });

    expect(within(screen.getByLabelText("決済状態")).getAllByRole("option").map((option) => ({
      label: option.textContent,
      value: (option as HTMLOptionElement).value,
    }))).toEqual([
      { label: "すべて", value: "all" },
      { label: "作成済み", value: "created" },
      { label: "支払操作待ち", value: "requires_action" },
      { label: "未払い", value: "processing" },
      { label: "決済成功", value: "succeeded" },
      { label: "失敗", value: "failed" },
      { label: "キャンセル", value: "canceled" },
      { label: "期限切れ", value: "expired" },
    ]);
    expect(within(screen.getByLabelText("支払方法")).getAllByRole("option").map((option) => ({
      label: option.textContent,
      value: (option as HTMLOptionElement).value,
    }))).toEqual([
      { label: "すべて", value: "all" },
      { label: "クレジットカード", value: "credit_card" },
      { label: "PayPay", value: "paypay" },
      { label: "コンビニ決済", value: "konbini" },
      { label: "銀行振込", value: "virtual_account" },
    ]);

    for (const status of ["processing", "expired", "failed", "canceled", "succeeded"] as const) {
      fireEvent.change(screen.getByLabelText("決済状態"), { target: { value: status } });
      await waitFor(() => expect(reader).toHaveBeenLastCalledWith(
        expect.objectContaining({ status }),
        expect.any(AbortSignal),
      ));
    }
    for (const paymentMethod of [
      "credit_card",
      "paypay",
      "konbini",
      "virtual_account",
    ] as const) {
      fireEvent.change(screen.getByLabelText("支払方法"), {
        target: { value: paymentMethod },
      });
      await waitFor(() => expect(reader).toHaveBeenLastCalledWith(
        expect.objectContaining({ payment_method: paymentMethod, status: "succeeded" }),
        expect.any(AbortSignal),
      ));
    }
  });

  it("uses explicit filters, resets to succeeded and all methods, and reports empty data", async () => {
    const reader = vi.spyOn(AdminApiClient.prototype, "listAdminPayments")
      .mockResolvedValue(collection([]));

    render(<AdminPaymentHistory initialMethod="konbini" initialStatus="processing" />);

    expect(await screen.findByRole("heading", {
      name: "検索条件に一致する決済はありません",
    })).toBeVisible();
    expect(reader).toHaveBeenLastCalledWith(
      expect.objectContaining({ payment_method: "konbini", status: "processing" }),
      expect.any(AbortSignal),
    );

    fireEvent.change(screen.getByLabelText("決済状態"), { target: { value: "succeeded" } });
    fireEvent.change(screen.getByLabelText("支払方法"), { target: { value: "credit_card" } });
    await waitFor(() => expect(reader).toHaveBeenLastCalledWith(
      expect.objectContaining({ payment_method: "credit_card", status: "succeeded" }),
      expect.any(AbortSignal),
    ));

    fireEvent.click(screen.getByRole("button", { name: "条件を解除" }));
    await waitFor(() => expect(reader).toHaveBeenLastCalledWith(
      expect.objectContaining({ payment_method: undefined, status: "succeeded" }),
      expect.any(AbortSignal),
    ));
    expect(screen.getByLabelText("決済状態")).toHaveValue("succeeded");
    expect(screen.getByLabelText("支払方法")).toHaveValue("all");
    expect(await screen.findByRole("heading", { name: "決済履歴はありません" })).toBeVisible();
  });

  it("uses canonical cursor pagination with an explicit loading state", async () => {
    const nextPage = deferred<AdminPaymentCollection>();
    const reader = vi.spyOn(AdminApiClient.prototype, "listAdminPayments")
      .mockResolvedValueOnce(collection([payment(1, "succeeded")], "next-cursor"))
      .mockReturnValueOnce(nextPage.promise);

    render(<AdminPaymentHistory initialStatus="all" />);
    fireEvent.click(await screen.findByRole("button", { name: "次へ" }));

    expect(screen.getByRole("status")).toHaveTextContent("決済履歴を読み込んでいます");
    expect(reader.mock.calls[1]?.[0]).toMatchObject({ cursor: "next-cursor" });
    nextPage.resolve(collection([payment(2, "processing")]));
    expect(await screen.findByText("未払い")).toBeVisible();
    expect(screen.getByRole("button", { name: "前へ" })).toBeEnabled();
  });

  it("does not disguise Problem Details errors as empty data and retries", async () => {
    const reader = vi.spyOn(AdminApiClient.prototype, "listAdminPayments")
      .mockRejectedValueOnce(new AdminApiError(
        503,
        "PAYMENT_HISTORY_UNAVAILABLE",
        uuid("8"),
        null,
        true,
      ))
      .mockResolvedValueOnce(collection([]));

    render(<AdminPaymentHistory />);

    expect(await screen.findByRole("alert")).toHaveTextContent(uuid("8"));
    expect(screen.queryByRole("heading", { name: "決済履歴はありません" })).toBeNull();
    fireEvent.click(screen.getByRole("button", { name: "再取得" }));
    expect(await screen.findByRole("heading", { name: "決済履歴はありません" })).toBeVisible();
    expect(reader).toHaveBeenCalledTimes(2);
  });

  it("uses only the user-specific contract and keeps shared presentation consistent", async () => {
    const item = payment(4, "succeeded");
    const allReader = vi.spyOn(AdminApiClient.prototype, "listAdminPayments")
      .mockResolvedValue(collection([item]));
    const userReader = vi.spyOn(AdminApiClient.prototype, "listAdminUserPayments")
      .mockResolvedValue(collection([item]));
    const all = render(<AdminPaymentHistory />);
    expect(await screen.findByText("決済成功")).toBeVisible();
    const allPresentation = screen.getByRole("row", { name: /決済成功/u }).textContent;
    all.unmount();

    render(<AdminPaymentHistory userPublicId={uuid("9")} />);
    const userRow = await screen.findByRole("row", { name: /決済成功/u });
    expect(userRow.textContent).toContain("銀行振込");
    expect(userRow.textContent).toContain("1,004");
    expect(userRow.textContent).toContain("2026/08/25 0:00");
    expect(allPresentation).toContain("銀行振込");
    expect(allPresentation).toContain("1,004");
    expect(allReader).toHaveBeenCalledOnce();
    expect(userReader).toHaveBeenCalledWith(
      uuid("9"),
      expect.objectContaining({ limit: 20, status: undefined }),
      expect.any(AbortSignal),
    );
    expect(screen.queryByRole("columnheader", { name: "User" })).toBeNull();
    expect(screen.queryByLabelText("決済状態")).toBeNull();
  });
});

function statusLabel(row: HTMLElement): string {
  return row.querySelector(".admin-payment-status")?.textContent ?? "";
}

function payment(
  index: number,
  status: AdminPaymentStatus,
): AdminPayment {
  const method = methods[(index - 1) % methods.length];
  return {
    amount: { amount: 1000 + index, currency: "JPY" },
    created_at: "2026-08-24T15:00:00Z",
    expires_at: status === "processing" || status === "expired"
      ? "2026-08-27T15:00:00Z"
      : null,
    grant: {
      bonus_points: status === "succeeded" ? 100 : 0,
      granted_at: status === "succeeded" ? "2026-08-24T15:05:00Z" : null,
      paid_points: status === "succeeded" ? 1000 + index : 0,
    },
    id: uuid(String(index)),
    method,
    provider: "fincode",
    provider_payment_reference: `payment-reference-${index}`,
    provider_status: null,
    status,
    succeeded_at: status === "succeeded" ? "2026-08-24T15:00:00Z" : null,
    updated_at: "2026-08-24T15:00:00Z",
    user: { display_name: "運営確認ユーザー", id: uuid("9") },
  };
}

function collection(
  data: AdminPayment[],
  nextCursor: string | null = null,
): AdminPaymentCollection {
  return {
    data,
    pagination: {
      has_more: nextCursor !== null,
      limit: 20,
      next_cursor: nextCursor,
    },
    request_id: uuid("8"),
  };
}

function deferred<T>() {
  let resolve!: (value: T) => void;
  const promise = new Promise<T>((next) => { resolve = next; });
  return { promise, resolve };
}

function uuid(last: string): string {
  return `01910191-0191-7191-8191-01910191019${last}`;
}
