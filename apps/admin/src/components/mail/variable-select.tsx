"use client";

import type { AdminMailTemplateVariable } from "@/lib/admin-api/generated";

export function VariableSelect({ label, onSelect, variables }: { label: string; onSelect: (token: string) => void; variables: AdminMailTemplateVariable[] }) {
  return (
    <label className="mail-variable-select">{label}
      <select aria-label={label} onChange={(event) => { onSelect(event.target.value); event.target.value = ""; }} defaultValue="">
        <option disabled value="">変数を挿入 ▼</option>
        {variables.map((variable) => <option key={variable.key} value={variable.token}>{variable.label}</option>)}
      </select>
    </label>
  );
}
