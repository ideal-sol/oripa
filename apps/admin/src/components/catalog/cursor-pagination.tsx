import { ArrowLeft, ArrowRight } from "lucide-react";

export function CursorPagination({
  canGoBack,
  canGoNext,
  onBack,
  onNext,
}: {
  canGoBack: boolean;
  canGoNext: boolean;
  onBack: () => void;
  onNext: () => void;
}) {
  return (
    <nav aria-label="Catalogページ" className="cursor-pagination">
      <button
        className="secondary-button"
        disabled={!canGoBack}
        onClick={onBack}
        type="button"
      >
        <ArrowLeft size={16} aria-hidden="true" />
        前へ
      </button>
      <button
        className="secondary-button"
        disabled={!canGoNext}
        onClick={onNext}
        type="button"
      >
        次へ
        <ArrowRight size={16} aria-hidden="true" />
      </button>
    </nav>
  );
}
