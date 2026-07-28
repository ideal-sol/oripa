import { ShieldCheck } from "lucide-react";
import type { ReactNode } from "react";

export function AuthFrame({
  children,
  description,
  title,
}: {
  children: ReactNode;
  description: string;
  title: string;
}) {
  return (
    <main className="auth-layout">
      <section className="auth-panel" aria-labelledby="auth-title">
        <div className="auth-brand">
          <span className="brand-mark" aria-hidden="true">
            <ShieldCheck size={22} strokeWidth={1.8} />
          </span>
          <span>Oripa Admin</span>
        </div>
        <div className="auth-heading">
          <h1 id="auth-title">{title}</h1>
          <p>{description}</p>
        </div>
        {children}
      </section>
      <aside className="auth-context" aria-hidden="true">
        <div className="auth-context-copy">
          <span className="eyebrow">Platform administration</span>
          <strong>認証された操作だけを、明確な境界で。</strong>
        </div>
      </aside>
    </main>
  );
}
