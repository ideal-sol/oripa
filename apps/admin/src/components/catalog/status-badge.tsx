export function StatusBadge({ visible }: { visible: boolean }) {
  return (
    <span className={`catalog-status ${visible ? "is-visible" : "is-hidden"}`}>
      {visible ? "公開可" : "非公開"}
    </span>
  );
}
