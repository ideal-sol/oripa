"use client";

import type { AdminGachaComposition } from "@/lib/admin-api/generated";

type Mode = NonNullable<AdminGachaComposition["member_rank_mode"]>;
const names = ["ビギナー", "ブロンズ", "シルバー", "ゴールド", "プラチナ", "ダイヤモンド", "VIP"];
const modes: Record<Mode, string> = { none: "指定なし", at_least: "このランク以上", only: "このランクのみ", at_most: "このランク以下" };

export function memberRankConditionLabel(value?: Pick<AdminGachaComposition, "member_rank_mode" | "member_rank_level"> | null): string {
  const mode = value?.member_rank_mode ?? "none";
  if (mode === "none") return "指定なし";
  const name = names[(value?.member_rank_level ?? 0) - 1] ?? "未選択";
  return `${name}${mode === "at_least" ? "以上" : mode === "at_most" ? "以下" : "のみ"}`;
}

export function MemberRankCondition({ mode = "none", level = null, disabled = false, onChange }: {
  mode?: Mode; level?: number | null; disabled?: boolean;
  onChange: (mode: Mode, level: number | null) => void;
}) {
  return <div className="catalog-form-grid">
    <label>会員ランク条件<select disabled={disabled} value={mode} onChange={(event) => {
      const next = event.target.value as Mode;
      onChange(next, next === "none" ? null : level);
    }}>{Object.entries(modes).map(([value, label]) => <option key={value} value={value}>{label}</option>)}</select></label>
    {mode !== "none" ? <label>条件の会員ランク<select required disabled={disabled} value={level ?? ""} onChange={(event) => onChange(mode, event.target.value === "" ? null : Number(event.target.value))}>
      <option value="">選択してください</option>{names.map((name, index) => <option key={name} value={index + 1}>{name}</option>)}
    </select></label> : null}
  </div>;
}
