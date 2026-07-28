"use client";

import { type ReactNode, useEffect, useRef } from "react";

export function AdminPageHeader({
  eyebrow,
  title,
  description,
  action,
}: {
  eyebrow: string;
  title: string;
  description?: string;
  action?: ReactNode;
}) {
  const heading = useRef<HTMLHeadingElement>(null);
  useEffect(() => {
    heading.current?.focus();
  }, []);

  return (
    <header className="workspace-header">
      <div>
        <span className="eyebrow">{eyebrow}</span>
        <h1 ref={heading} tabIndex={-1}>
          {title}
        </h1>
        {description ? <p>{description}</p> : null}
      </div>
      {action}
    </header>
  );
}
