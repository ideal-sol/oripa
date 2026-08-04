export interface ProfitSimulationPrize {
  id: string;
  costPrice: number;
  totalInventory: number;
  availableInventory: number;
}

export interface ProfitSimulationTarget {
  resultType: "prize" | "point_back";
  prizeId: string | null;
  pointAmount: number | null;
  probabilityPpm: number;
}

export interface ProfitSimulationStage {
  code: string;
  name: string;
  minDrawNumber: number;
  maxDrawNumber: number | null;
  entries: ProfitSimulationTarget[];
  minimumGuarantee: ProfitSimulationTarget | null;
}

export interface ProfitSimulationInput {
  price: number;
  totalCount: number;
  soldCount: number;
  minimumGuaranteeCost: number;
  targetMarginRate: number | null;
  prizes: ProfitSimulationPrize[];
  probabilityVersionId: string | null;
  stages: ProfitSimulationStage[];
}

export interface ProfitSimulationResult {
  sales: {
    price: number;
    totalCount: number;
    soldCount: number;
    remainingCount: number;
    totalSales: number;
    soldSales: number;
    remainingSales: number;
  };
  costs: {
    prizeInventoryCost: number;
    prizeAwardedCost: number;
    prizeRemainingCost: number;
    minimumGuaranteeMaxCost: number;
    maxCost: number;
  };
  profit: {
    projectedProfit: number;
    projectedMarginRate: number | null;
    targetMarginRate: number | null;
    targetProfit: number | null;
    gapToTargetProfit: number | null;
    meetsTarget: boolean | null;
  };
  expected: {
    available: boolean;
    probabilityVersionId: string | null;
    expectedCostPerDraw: number | null;
    expectedTotalCost: number | null;
    expectedProfit: number | null;
    expectedMarginRate: number | null;
    stages: Array<{
      stageKey: string;
      name: string;
      drawCount: number;
      expectedCostPerDraw: number;
      expectedTotalCost: number;
    }>;
  };
  warnings: string[];
}

export function simulateGachaProfit(input: ProfitSimulationInput): ProfitSimulationResult {
  const remainingCount = Math.max(0, input.totalCount - input.soldCount);
  const totalSales = input.price * input.totalCount;
  const soldSales = input.price * input.soldCount;
  const remainingSales = input.price * remainingCount;
  const prizeInventoryCost = input.prizes.reduce(
    (sum, prize) => sum + prize.costPrice * prize.totalInventory,
    0,
  );
  const prizeAwardedCost = input.prizes.reduce(
    (sum, prize) => sum + prize.costPrice * Math.max(0, prize.totalInventory - prize.availableInventory),
    0,
  );
  const prizeRemainingCost = input.prizes.reduce(
    (sum, prize) => sum + prize.costPrice * Math.max(0, prize.availableInventory),
    0,
  );
  const minimumGuaranteeMaxCost = input.minimumGuaranteeCost * input.totalCount;
  const maxCost = prizeInventoryCost + minimumGuaranteeMaxCost;
  const projectedProfit = totalSales - maxCost;
  const projectedMarginRate = totalSales > 0
    ? phpRound((projectedProfit / totalSales) * 100, 2)
    : null;
  const targetProfit = input.targetMarginRate === null
    ? null
    : phpRound(totalSales * (input.targetMarginRate / 100), 0);
  const gapToTargetProfit = targetProfit === null ? null : projectedProfit - targetProfit;
  const expected = expectedCost(input, totalSales);
  const warnings: string[] = [];
  if (input.prizes.length === 0) warnings.push("景品が登録されていません。");
  if (projectedProfit < 0) warnings.push("完売時の最大原価シナリオで赤字になります。");
  if (
    input.targetMarginRate !== null &&
    projectedMarginRate !== null &&
    projectedMarginRate < input.targetMarginRate
  ) {
    warnings.push("目標粗利率を下回っています。");
  }

  return {
    sales: {
      price: input.price,
      totalCount: input.totalCount,
      soldCount: input.soldCount,
      remainingCount,
      totalSales,
      soldSales,
      remainingSales,
    },
    costs: {
      prizeInventoryCost,
      prizeAwardedCost,
      prizeRemainingCost,
      minimumGuaranteeMaxCost,
      maxCost,
    },
    profit: {
      projectedProfit,
      projectedMarginRate,
      targetMarginRate: input.targetMarginRate,
      targetProfit,
      gapToTargetProfit,
      meetsTarget: gapToTargetProfit === null ? null : gapToTargetProfit >= 0,
    },
    expected,
    warnings,
  };
}

export function validateProfitSimulationInput(values: {
  price: string;
  totalCount: string;
  minimumGuaranteeCost: string;
  targetMarginRate: string;
}): Record<string, string> {
  const errors: Record<string, string> = {};
  const price = Number(values.price);
  const totalCount = Number(values.totalCount);
  const guarantee = Number(values.minimumGuaranteeCost);
  const target = values.targetMarginRate === "" ? null : Number(values.targetMarginRate);
  if (!Number.isSafeInteger(price) || price < 0) errors.price = "0以上の整数を入力してください。";
  if (!Number.isSafeInteger(totalCount) || totalCount < 1) {
    errors.totalCount = "1以上の整数を入力してください。";
  }
  if (!Number.isSafeInteger(guarantee) || guarantee < 0) {
    errors.minimumGuaranteeCost = "0以上の整数を入力してください。";
  }
  if (target !== null && (!Number.isFinite(target) || target < 0 || target > 9999.99)) {
    errors.targetMarginRate = "0から9999.99の範囲で入力してください。";
  }
  return errors;
}

function expectedCost(
  input: ProfitSimulationInput,
  totalSales: number,
): ProfitSimulationResult["expected"] {
  if (input.probabilityVersionId === null) {
    return {
      available: false,
      probabilityVersionId: null,
      expectedCostPerDraw: null,
      expectedTotalCost: null,
      expectedProfit: null,
      expectedMarginRate: null,
      stages: [],
    };
  }

  const prizeCosts = new Map(input.prizes.map((prize) => [prize.id, prize.costPrice]));
  let expectedTotalCost = 0;
  const stages = [...input.stages]
    .sort((left, right) => left.minDrawNumber - right.minDrawNumber)
    .map((stage) => {
      const minDraw = Math.max(1, stage.minDrawNumber);
      const maxDraw = stage.maxDrawNumber === null
        ? input.totalCount
        : Math.min(input.totalCount, stage.maxDrawNumber);
      const drawCount = Math.max(0, maxDraw - minDraw + 1);
      const targets = stage.minimumGuarantee === null
        ? stage.entries
        : [...stage.entries, stage.minimumGuarantee];
      const expectedCostPerDraw = targets.reduce((sum, target) => {
        const cost = target === stage.minimumGuarantee
          ? input.minimumGuaranteeCost
          : target.resultType === "prize"
            ? prizeCosts.get(target.prizeId ?? "") ?? 0
            : target.pointAmount ?? 0;
        return sum + (target.probabilityPpm / 1_000_000) * cost;
      }, 0);
      const stageExpectedCost = expectedCostPerDraw * drawCount;
      expectedTotalCost += stageExpectedCost;
      return {
        stageKey: stage.code,
        name: stage.name,
        drawCount,
        expectedCostPerDraw: phpRound(expectedCostPerDraw, 2),
        expectedTotalCost: phpRound(stageExpectedCost, 0),
      };
    });
  const expectedTotalCostInteger = phpRound(expectedTotalCost, 0);
  const expectedProfit = totalSales - expectedTotalCostInteger;
  return {
    available: true,
    probabilityVersionId: input.probabilityVersionId,
    expectedCostPerDraw: input.totalCount > 0
      ? phpRound(expectedTotalCost / input.totalCount, 2)
      : null,
    expectedTotalCost: expectedTotalCostInteger,
    expectedProfit,
    expectedMarginRate: totalSales > 0
      ? phpRound((expectedProfit / totalSales) * 100, 2)
      : null,
    stages,
  };
}

function phpRound(value: number, precision: number): number {
  const factor = 10 ** precision;
  return Math.sign(value) * Math.round((Math.abs(value) + Number.EPSILON) * factor) / factor;
}
