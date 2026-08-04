import { fireEvent, render, screen, waitFor } from "@testing-library/react";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

import { CatalogGachaProfitSimulation } from "@/components/catalog/catalog-gacha-profit-simulation";
import { AdminApiClient } from "@/lib/admin-api/client";
import {
  simulateGachaProfit,
  validateProfitSimulationInput,
  type ProfitSimulationInput,
} from "@/lib/catalog/gacha-profit-simulation";

const GACHA_ID = uuid("1");
const VERSION_ID = uuid("2");
const PROBABILITY_ID = uuid("3");
const PRIZE_A = uuid("4");
const PRIZE_B = uuid("5");

vi.mock("@/components/shell/admin-shell", () => ({
  AdminShell: ({ children }: { children: React.ReactNode }) => <>{children}</>,
}));
vi.mock("@/components/permissions/protected-admin-route", () => ({
  ProtectedAdminRoute: ({ children }: { children: React.ReactNode }) => <>{children}</>,
}));

beforeEach(() => {
  vi.spyOn(AdminApiClient.prototype, "getCatalogGacha").mockResolvedValue({
    data: {
      code: "summer",
      current_version: { title: "夏のガチャ" },
      id: GACHA_ID,
      sold_count: 20,
    },
  } as never);
  vi.spyOn(AdminApiClient.prototype, "listCatalogGachaVersions").mockResolvedValue({
    items: [{
      id: VERSION_ID,
      is_archived: false,
      price_points: 500,
      status: "draft",
      title: "夏のガチャ",
      total_count: 100,
      version_number: 2,
    }],
    next_cursor: null,
  } as never);
  vi.spyOn(AdminApiClient.prototype, "listGachaVersionPrizes").mockResolvedValue({
    items: [prize(PRIZE_A, 1000, 5, 3), prize(PRIZE_B, 200, 10, 9)],
    version_revision: 1,
  } as never);
  vi.spyOn(AdminApiClient.prototype, "getGachaProbabilitySelection").mockResolvedValue({
    data: { selected_probability: { id: PROBABILITY_ID } },
  } as never);
  vi.spyOn(AdminApiClient.prototype, "getCatalogProbabilityVersion").mockResolvedValue({
    data: probability(),
  } as never);
});

afterEach(() => vi.restoreAllMocks());

describe("V1 profit simulation equivalence", () => {
  it("matches the canonical V1 normal-case results", () => {
    const result = simulateGachaProfit(v1Fixture());

    expect(result.sales.totalSales).toBe(50_000);
    expect(result.costs).toEqual({
      maxCost: 8_000,
      minimumGuaranteeMaxCost: 1_000,
      prizeAwardedCost: 2_200,
      prizeInventoryCost: 7_000,
      prizeRemainingCost: 4_800,
    });
    expect(result.profit).toEqual({
      gapToTargetProfit: 27_000,
      meetsTarget: true,
      projectedMarginRate: 84,
      projectedProfit: 42_000,
      targetMarginRate: 30,
      targetProfit: 15_000,
    });
    expect(result.expected.expectedCostPerDraw).toBe(147);
    expect(result.expected.expectedTotalCost).toBe(14_700);
    expect(result.expected.expectedProfit).toBe(35_300);
    expect(result.expected.expectedMarginRate).toBe(70.6);
  });

  it("matches V1 stage clipping and rounding with multiple prizes", () => {
    const input = v1Fixture();
    input.price = 333;
    input.totalCount = 3;
    input.soldCount = 1;
    input.minimumGuaranteeCost = 1;
    input.targetMarginRate = 12.5;
    input.prizes = [
      { id: PRIZE_A, costPrice: 101, totalInventory: 1, availableInventory: 1 },
      { id: PRIZE_B, costPrice: 52, totalInventory: 2, availableInventory: 1 },
    ];
    input.stages = [
      {
        code: "early",
        entries: [{ pointAmount: null, prizeId: PRIZE_A, probabilityPpm: 333_333, resultType: "prize" }],
        maxDrawNumber: 2,
        minDrawNumber: 1,
        minimumGuarantee: { pointAmount: 1, prizeId: null, probabilityPpm: 666_667, resultType: "point_back" },
        name: "前半",
      },
      {
        code: "late",
        entries: [{ pointAmount: null, prizeId: PRIZE_B, probabilityPpm: 1_000_000, resultType: "prize" }],
        maxDrawNumber: 99,
        minDrawNumber: 3,
        minimumGuarantee: null,
        name: "後半",
      },
    ];

    const result = simulateGachaProfit(input);
    expect(result.sales.totalSales).toBe(999);
    expect(result.costs.maxCost).toBe(208);
    expect(result.profit.projectedProfit).toBe(791);
    expect(result.profit.projectedMarginRate).toBe(79.18);
    expect(result.profit.targetProfit).toBe(125);
    expect(result.expected.stages.map((stage) => stage.drawCount)).toEqual([2, 1]);
    expect(result.expected.stages[0]?.expectedCostPerDraw).toBe(34.33);
    expect(result.expected.expectedTotalCost).toBe(121);
    expect(result.expected.expectedMarginRate).toBe(87.89);
  });

  it("keeps V1 zero and invalid-input boundaries", () => {
    const input = v1Fixture();
    input.price = 0;
    input.prizes = [];
    input.probabilityVersionId = null;
    input.stages = [];
    input.targetMarginRate = null;
    const result = simulateGachaProfit(input);

    expect(result.profit.projectedMarginRate).toBeNull();
    expect(result.expected.available).toBe(false);
    expect(result.warnings).toContain("景品が登録されていません。");
    expect(validateProfitSimulationInput({
      minimumGuaranteeCost: "-1",
      price: "1.5",
      targetMarginRate: "10000",
      totalCount: "0",
    })).toEqual({
      minimumGuaranteeCost: "0以上の整数を入力してください。",
      price: "0以上の整数を入力してください。",
      targetMarginRate: "0から9999.99の範囲で入力してください。",
      totalCount: "1以上の整数を入力してください。",
    });
  });
});

describe("Profit simulation screen", () => {
  it("loads V2 Draft data, preserves V1 output order, and recalculates", async () => {
    render(<CatalogGachaProfitSimulation gachaId={GACHA_ID} />);

    expect(await screen.findByRole("heading", { name: "利益シミュレーション" })).toBeVisible();
    expect(screen.getByRole("heading", { name: "夏のガチャ" })).toBeVisible();
    const terms = screen.getAllByRole("term").map((term) => term.textContent);
    expect(terms.slice(0, 8)).toEqual([
      "完売時売上", "景品原価総額", "最低保証最大原価", "最大原価合計",
      "想定利益", "想定粗利率", "目標利益", "目標差分",
    ]);
    expect(screen.getByText("¥50,000")).toBeVisible();
    expect(screen.getAllByText("¥14,700")).toHaveLength(2);
    expect(screen.getByRole("link", { name: "ガチャ詳細へ" }))
      .toHaveAttribute("href", `/catalog/gachas/${GACHA_ID}`);

    fireEvent.change(screen.getByLabelText("1口価格（pt）"), { target: { value: "600" } });
    fireEvent.change(screen.getByLabelText("目標粗利率（%・任意）"), { target: { value: "30" } });
    fireEvent.click(screen.getByRole("button", { name: "再計算" }));
    await waitFor(() => expect(screen.getByText("¥60,000")).toBeVisible());
    expect(screen.getByText("¥18,000")).toBeVisible();
  });

  it("shows field validation without replacing the prior calculation", async () => {
    render(<CatalogGachaProfitSimulation gachaId={GACHA_ID} />);
    await screen.findByRole("heading", { name: "夏のガチャ" });
    fireEvent.change(screen.getByLabelText("総口数"), { target: { value: "0" } });
    fireEvent.click(screen.getByRole("button", { name: "再計算" }));
    expect(await screen.findByText("1以上の整数を入力してください。")).toBeVisible();
    expect(screen.getByText("¥50,000")).toBeVisible();
  });
});

function v1Fixture(): ProfitSimulationInput {
  return {
    minimumGuaranteeCost: 10,
    price: 500,
    prizes: [
      { id: PRIZE_A, costPrice: 1000, totalInventory: 5, availableInventory: 3 },
      { id: PRIZE_B, costPrice: 200, totalInventory: 10, availableInventory: 9 },
    ],
    probabilityVersionId: PROBABILITY_ID,
    soldCount: 20,
    stages: [{
      code: "default",
      entries: [
        { pointAmount: null, prizeId: PRIZE_A, probabilityPpm: 100_000, resultType: "prize" },
        { pointAmount: null, prizeId: PRIZE_B, probabilityPpm: 200_000, resultType: "prize" },
      ],
      maxDrawNumber: 100,
      minDrawNumber: 1,
      minimumGuarantee: { pointAmount: 10, prizeId: null, probabilityPpm: 700_000, resultType: "point_back" },
      name: "通常",
    }],
    targetMarginRate: 30,
    totalCount: 100,
  };
}

function prize(id: string, cost: number, total: number, available: number) {
  return {
    available_inventory: available,
    cost_price: cost,
    id,
    total_inventory: total,
  };
}

function probability() {
  return {
    id: PROBABILITY_ID,
    stages: [{
      code: "default",
      entries: [target(PRIZE_A, 100_000), target(PRIZE_B, 200_000)],
      max_draw_number: 100,
      min_draw_number: 1,
      minimum_guarantee: {
        point_amount: 10,
        prize: null,
        probability_ppm: 700_000,
        result_type: "point_back",
      },
      name: "通常",
    }],
  };
}

function target(id: string, ppm: number) {
  return {
    point_amount: null,
    prize: { id },
    probability_ppm: ppm,
    result_type: "prize",
  };
}

function uuid(last: string): string {
  return `01910191-0191-7191-8191-01910191019${last}`;
}
