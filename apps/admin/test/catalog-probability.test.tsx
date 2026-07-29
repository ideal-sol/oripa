import { describe, expect, it } from "vitest";

import {
  calculateProbabilityTotals,
  isMutableProbabilityVersion,
  isValidProbabilityDraft,
} from "@/components/catalog/catalog-probability-workspace";
import type { AdminCatalogProbabilityStageInput } from "@/lib/admin-api/generated";

const PRIZE_S_ID = "01910191-0191-7191-8191-019101910193";
const PRIZE_A_ID = "01910191-0191-7191-8191-019101910194";

describe("Admin Draft Probability editor", () => {
  it("calculates integer ppm totals without rounding", () => {
    const draft = [stage(600_000, 400_000)];

    expect(calculateProbabilityTotals(draft)).toEqual({
      current: 1_000_000,
      required: 1_000_000,
    });
    expect(isValidProbabilityDraft(draft)).toBe(true);
  });

  it("rejects floats, duplicate ordinary prizes, and invalid stage ranges", () => {
    const floatDraft = [stage(600_000.5, 399_999.5)];
    const duplicateDraft = [stage(600_000, 400_000)];
    duplicateDraft[0].entries.push({
      point_amount: null,
      prize_id: PRIZE_S_ID,
      probability_ppm: 0,
      result_type: "prize",
    });
    const invalidRange = [stage(600_000, 400_000)];
    invalidRange[0].min_draw_number = 2;

    expect(isValidProbabilityDraft(floatDraft)).toBe(false);
    expect(isValidProbabilityDraft(duplicateDraft)).toBe(false);
    expect(isValidProbabilityDraft(invalidRange)).toBe(false);
  });

  it("keeps Published and archived Probability Versions read-only", () => {
    expect(
      isMutableProbabilityVersion({ is_archived: false, status: "draft" }),
    ).toBe(true);
    expect(
      isMutableProbabilityVersion({ is_archived: false, status: "published" }),
    ).toBe(false);
    expect(
      isMutableProbabilityVersion({ is_archived: true, status: "draft" }),
    ).toBe(false);
  });
});

function stage(
  entryPpm: number,
  guaranteePpm: number,
): AdminCatalogProbabilityStageInput {
  return {
    code: "stage-1",
    entries: [
      {
        point_amount: null,
        prize_id: PRIZE_S_ID,
        probability_ppm: entryPpm,
        result_type: "prize",
      },
    ],
    max_draw_number: null,
    min_draw_number: 1,
    minimum_guarantee: {
      point_amount: null,
      prize_id: PRIZE_A_ID,
      probability_ppm: guaranteePpm,
      result_type: "prize",
    },
    name: "Stage 1",
  };
}
