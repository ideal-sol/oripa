import { LoaderCircle } from "lucide-react";

export default function Loading() {
  return (
    <main className="full-page-state" role="status">
      <LoaderCircle className="spin" size={24} aria-hidden="true" />
      <span>読み込み中</span>
    </main>
  );
}
