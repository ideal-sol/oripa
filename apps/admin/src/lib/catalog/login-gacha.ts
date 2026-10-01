import type { AdminGachaComposition, AdminGachaType } from "@/lib/admin-api/generated";

export const fixedPercentageScale = 1_000_000_000_000n;

export function percentageUnits(value: string): bigint | null {
  const match = /^(0|[1-9][0-9]{0,2})(?:\.([0-9]{1,10}))?$/.exec(value);
  if (!match || match[0] !== value) return null;
  const units = BigInt(match[1]) * 10_000_000_000n + BigInt((match[2] ?? "").padEnd(10, "0"));
  return units > 0n && units <= fixedPercentageScale ? units : null;
}

export function percentageTotal(values: (string | null)[]): { valid: boolean; label: string } {
  const parsed = values.map((value) => value === null ? null : percentageUnits(value));
  const sum = parsed.reduce<bigint>((total, units) => total + (units ?? 0n), 0n);
  const decimal = (sum % 10_000_000_000n).toString().padStart(10, "0").replace(/0+$/, "");
  return {
    valid: parsed.length > 0 && parsed.every((units) => units !== null) && sum === fixedPercentageScale,
    label: `${sum / 10_000_000_000n}${decimal ? "." + decimal : ""}%`,
  };
}

export const gachaTypeLabels: Record<AdminGachaType, string> = {
  standard: "通常ガチャ", signup_once: "新規登録限定ガチャ", login_daily: "ログインガチャ",
};

export function emptyGachaComposition(type: AdminGachaType): AdminGachaComposition {
  return {
    gacha_type: type, title: "", description: null, notices: null, presentation_asset_id: "",
    price_points: type === "standard" ? 1 : 0, minimum_exchange_points: type === "login_daily" ? 0 : null,
    publish_start_at: null, publish_end_at: null, category_id: null, tag_ids: [],
    total_count: type === "standard" ? 1 : null, daily_draw_limit: type === "login_daily" ? 1 : 0,
    audience_code: "all_users", first_time_eligible_days: 7, allowed_draw_counts: [1], ranks: [], prizes: [],
  };
}

export function jstInput(value: string | null): string {
  if (!value) return "";
  const instant = Date.parse(value);
  return Number.isNaN(instant) ? "" : new Date(instant + 9 * 60 * 60 * 1000).toISOString().slice(0, 16);
}

export function jstTimestamp(value: string): string | null {
  return value ? `${value}:00+09:00` : null;
}
