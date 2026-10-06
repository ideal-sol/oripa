"use client";

import { useEffect, useId, useRef } from "react";

export function ExternalIdField({ value, onChange, disabled = false }: {
  value: string;
  onChange: (value: string) => void;
  disabled?: boolean;
}) {
  const input = useRef<HTMLInputElement>(null);
  const errorId = useId();
  const normalized = value.trim();
  const invalid = normalized !== "" && !/^[A-Za-z0-9._-]{1,64}$/.test(normalized);
  const message = invalid ? "管理IDは半角英数字・ハイフン・アンダースコア・ピリオドの1〜64文字で入力してください。" : "";
  useEffect(() => { input.current?.setCustomValidity(message); }, [message]);

  return <label>管理ID
    <input aria-describedby={invalid ? errorId : undefined} aria-invalid={invalid} disabled={disabled}
      name="external_id" onChange={(event) => onChange(event.target.value)} ref={input} value={value} />
    {invalid ? <span className="form-field-error" id={errorId} role="alert">{message}</span> : null}
  </label>;
}
