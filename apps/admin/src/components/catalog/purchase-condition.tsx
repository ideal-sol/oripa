"use client";

import type { AdminGachaComposition } from "@/lib/admin-api/generated";
import { memberRankConditionLabel } from "./member-rank-condition";

export type PurchaseFields = Pick<AdminGachaComposition,
  "purchase_count_mode" | "purchase_count_value" | "purchase_amount_mode" | "purchase_amount_value">;
type Mode = NonNullable<PurchaseFields["purchase_count_mode"]>;

export function purchaseConditionLabel(value: PurchaseFields | null | undefined): string {
  return (["purchase_count", "purchase_amount"] as const).map((field) => {
    const mode = value?.[`${field}_mode`] ?? "none";
    const title = field === "purchase_count" ? "購入回数" : "購入額";
    if (mode === "none") return `${title}指定なし`;
    return `${title}${value?.[`${field}_value`]?.toLocaleString() ?? "未入力"}${field === "purchase_count" ? "回" : "円"}${mode === "gte" ? "以上" : mode === "lt" ? "未満" : ""}`;
  }).join("／");
}

export function PurchaseCondition({ value, disabled = false, onChange, audience, rank }: {
  value: PurchaseFields; disabled?: boolean; onChange: (value: PurchaseFields) => void;
  audience: AdminGachaComposition["audience_code"];
  rank: Pick<AdminGachaComposition, "member_rank_mode" | "member_rank_level">;
}) {
  const noConditions = audience === "all_users" && (rank.member_rank_mode ?? "none") === "none"
    && (value.purchase_count_mode ?? "none") === "none" && (value.purchase_amount_mode ?? "none") === "none";
  return <fieldset disabled={disabled}>
    <legend>購入条件</legend>
    <div className="catalog-form-grid">
      {(["purchase_count", "purchase_amount"] as const).map((field) => {
        const title = field === "purchase_count" ? "購入回数" : "購入額";
        const unit = field === "purchase_count" ? "回" : "円";
        const mode = value[`${field}_mode`] ?? "none";
        return <div key={field}>
          <label>{title}<select value={mode} onChange={(event) => {
            const next = event.target.value as Mode;
            onChange({ ...value, [`${field}_mode`]: next, [`${field}_value`]: next === "none" ? null : value[`${field}_value`] ?? null });
          }}>
            <option value="none">指定なし</option><option value="eq">{unit}</option>
            <option value="gte">{unit}以上</option><option value="lt">{unit}未満</option>
          </select></label>
          {mode !== "none" ? <label>{title}の値<input type="number" required step={1}
            min={mode === "eq" ? 0 : 1} max={field === "purchase_count" ? 1000000 : Number.MAX_SAFE_INTEGER}
            value={value[`${field}_value`] ?? ""}
            onChange={(event) => onChange({ ...value, [`${field}_value`]: event.target.value === "" ? null : Number(event.target.value) })} /></label> : null}
        </div>;
      })}
    </div>
    <p aria-label="対象条件の要約">{noConditions ? "条件なし（すべてのユーザー）"
      : `${({ all_users: "すべてのユーザー", first_time_users: "初回ユーザー", line_users: "LINEユーザー" })[audience]}／${memberRankConditionLabel(rank)}／${purchaseConditionLabel(value)}`}</p>
  </fieldset>;
}
