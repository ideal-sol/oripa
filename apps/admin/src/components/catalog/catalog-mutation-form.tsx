"use client";

import { LoaderCircle, X } from "lucide-react";
import { type FormEvent, useEffect, useMemo, useRef, useState } from "react";

export interface CatalogMasterDraft {
  code: string;
  description: string | null;
  isVisible: boolean;
  name: string;
  slug: string;
  sortOrder: number;
}

export function CatalogMutationForm({
  initial,
  mode,
  resource,
  onCancel,
  onSubmit,
}: {
  initial?: CatalogMasterDraft;
  mode: "create" | "edit";
  resource: "categories" | "tags" | "ranks";
  onCancel: () => void;
  onSubmit: (draft: CatalogMasterDraft) => Promise<void>;
}) {
  const empty = useMemo<CatalogMasterDraft>(
    () => ({
      code: "",
      description: null,
      isVisible: true,
      name: "",
      slug: "",
      sortOrder: 0,
    }),
    [],
  );
  const original = initial ?? empty;
  const [draft, setDraft] = useState(original);
  const [error, setError] = useState<string | null>(null);
  const [submitting, setSubmitting] = useState(false);
  const heading = useRef<HTMLHeadingElement>(null);
  const dirty = JSON.stringify(draft) !== JSON.stringify(original);

  useEffect(() => {
    heading.current?.focus();
  }, []);
  useEffect(() => {
    if (!dirty) return;
    const guard = (event: BeforeUnloadEvent) => event.preventDefault();
    window.addEventListener("beforeunload", guard);
    return () => window.removeEventListener("beforeunload", guard);
  }, [dirty]);

  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setError(null);
    if (
      !draft.name.trim() ||
      (resource !== "ranks" && !draft.slug.trim()) ||
      (mode === "create" && !draft.code.trim()) ||
      !Number.isSafeInteger(draft.sortOrder) ||
      draft.sortOrder < 0 ||
      /[<>]/u.test(`${draft.name}${draft.description ?? ""}`)
    ) {
      setError("入力内容を確認してください。HTMLは入力できません。");
      return;
    }
    setSubmitting(true);
    try {
      await onSubmit({
        ...draft,
        description: draft.description?.trim() || null,
        name: draft.name.trim(),
        slug: draft.slug.trim(),
      });
    } catch {
      setError("保存できませんでした。画面の案内に従って再試行してください。");
    } finally {
      setSubmitting(false);
    }
  }

  function cancel() {
    if (dirty && !window.confirm("未保存の変更を破棄しますか。")) {
      return;
    }
    onCancel();
  }

  return (
    <div className="dialog-backdrop" role="presentation">
      <section
        aria-labelledby="catalog-mutation-heading"
        aria-modal="true"
        className="dialog-panel catalog-mutation-panel"
        role="dialog"
      >
        <header className="dialog-header">
          <div>
            <span className="eyebrow">Catalog Master</span>
            <h2 id="catalog-mutation-heading" ref={heading} tabIndex={-1}>
              {mode === "create" ? "新規作成" : "編集"}
            </h2>
          </div>
          <button
            aria-label="閉じる"
            className="icon-button"
            disabled={submitting}
            onClick={cancel}
            type="button"
          >
            <X size={18} aria-hidden="true" />
          </button>
        </header>
        <form className="catalog-mutation-form" onSubmit={submit}>
          {mode === "create" ? (
            <label>
              Code
              <input
                autoComplete="off"
                maxLength={resource === "ranks" ? 32 : 64}
                onChange={(event) => setDraft({ ...draft, code: event.target.value })}
                pattern="[a-z][a-z0-9_-]*"
                required
                value={draft.code}
              />
            </label>
          ) : (
            <p className="catalog-immutable-code">
              Code <code>{draft.code}</code>
            </p>
          )}
          {resource !== "ranks" ? (
            <label>
              Slug
              <input
                autoComplete="off"
                maxLength={128}
                onChange={(event) => setDraft({ ...draft, slug: event.target.value })}
                pattern="[a-z0-9]+(?:-[a-z0-9]+)*"
                required
                value={draft.slug}
              />
            </label>
          ) : null}
          <label>
            名称
            <input
              autoComplete="off"
              maxLength={resource === "ranks" ? 128 : 191}
              onChange={(event) => setDraft({ ...draft, name: event.target.value })}
              required
              value={draft.name}
            />
          </label>
          {resource === "categories" ? (
            <label>
              説明
              <textarea
                maxLength={2000}
                onChange={(event) =>
                  setDraft({ ...draft, description: event.target.value })
                }
                rows={5}
                value={draft.description ?? ""}
              />
            </label>
          ) : null}
          <label>
            表示順
            <input
              min={0}
              onChange={(event) =>
                setDraft({ ...draft, sortOrder: Number(event.target.value) })
              }
              required
              type="number"
              value={draft.sortOrder}
            />
          </label>
          <label className="catalog-checkbox">
            <input
              checked={draft.isVisible}
              onChange={(event) =>
                setDraft({ ...draft, isVisible: event.target.checked })
              }
              type="checkbox"
            />
            公開Catalogへ表示する
          </label>
          {error ? (
            <p aria-live="assertive" className="form-error" role="alert">
              {error}
            </p>
          ) : null}
          <div className="catalog-dialog-actions">
            <button
              className="secondary-button"
              disabled={submitting}
              onClick={cancel}
              type="button"
            >
              取り消し
            </button>
            <button className="primary-button" disabled={submitting || !dirty} type="submit">
              {submitting ? (
                <LoaderCircle className="spin" size={16} aria-hidden="true" />
              ) : null}
              保存
            </button>
          </div>
        </form>
      </section>
    </div>
  );
}
