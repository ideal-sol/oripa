export function StatusBadge({
  archived = false,
  visible,
}: {
  archived?: boolean;
  visible: boolean;
}) {
  if (archived) {
    return <span className="catalog-status is-archived">Archive</span>;
  }
  return (
    <span className={`catalog-status ${visible ? "is-visible" : "is-hidden"}`}>
      {visible ? "公開可" : "非公開"}
    </span>
  );
}
