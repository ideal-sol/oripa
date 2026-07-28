import { FileQuestion } from "lucide-react";
import Link from "next/link";

export default function NotFound() {
  return (
    <main className="full-page-state">
      <FileQuestion size={30} aria-hidden="true" />
      <h1>ページが見つかりません</h1>
      <p>指定された管理ページは存在しません。</p>
      <Link className="secondary-button" href="/">
        管理ホームへ
      </Link>
    </main>
  );
}
