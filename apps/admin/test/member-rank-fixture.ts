import type { AdminMemberRankSettings, AdminMemberRankValue } from "@/lib/admin-api/generated";

export function memberRankFixture(): AdminMemberRankSettings {
  const codes: AdminMemberRankValue["code"][] = ["beginner", "bronze", "silver", "gold", "platinum", "diamond", "vip"];
  return {
    revision: 1, enabled: false, activated_at: null, scheduled: null,
    next_effective_at: "2026-10-31T15:00:00Z",
    current: {
      revision: 1, action: "initial", effective_at: "1969-12-31T15:00:00Z",
      saved_at: "2026-10-16T00:00:00Z", saved_by: null, default_skip_count: 1,
      ranks: codes.map((code, index) => ({
        code, reach_line: [null, 10000, 30000, 100000, 300000, 700000, 1500000][index],
        maintain_line: [null, 5000, 20000, 70000, 200000, 500000, 1000000][index],
        promotion_bonus: [0, 500, 2000, 5000, 20000, 50000, 100000][index],
        purchase_bonus_rate_bp: [0, 50, 100, 150, 200, 300, 400][index],
      })),
    },
  };
}
