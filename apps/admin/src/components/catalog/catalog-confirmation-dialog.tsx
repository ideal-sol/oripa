"use client";

import { Archive, LoaderCircle } from "lucide-react";
import { useEffect, useRef } from "react";

export function CatalogConfirmationDialog({
  busy,
  name,
  onCancel,
  onConfirm,
}: {
  busy: boolean;
  name: string;
  onCancel: () => void;
  onConfirm: () => void;
}) {
  const heading = useRef<HTMLHeadingElement>(null);
  useEffect(() => heading.current?.focus(), []);

  return (
    <div className="dialog-backdrop" role="presentation">
      <section
        aria-labelledby="catalog-archive-heading"
        aria-modal="true"
        className="dialog-panel"
        role="alertdialog"
      >
        <Archive size={24} aria-hidden="true" />
        <h2 id="catalog-archive-heading" ref={heading} tabIndex={-1}>
          Archiveしますか
        </h2>
        <p>
          <strong>{name}</strong> は一覧へ履歴として残り、通常編集できなくなります。
        </p>
        <div className="catalog-dialog-actions">
          <button className="secondary-button" disabled={busy} onClick={onCancel} type="button">
            取り消し
          </button>
          <button className="danger-button" disabled={busy} onClick={onConfirm} type="button">
            {busy ? <LoaderCircle className="spin" size={16} aria-hidden="true" /> : null}
            Archive
          </button>
        </div>
      </section>
    </div>
  );
}
