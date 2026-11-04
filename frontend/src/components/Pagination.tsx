interface PaginationProps {
  readonly page: number;
  readonly lastPage: number;
  readonly onChange: (page: number) => void;
}

export function Pagination({ page, lastPage, onChange }: PaginationProps) {
  return (
    <nav aria-label="تقسيم الصفحات">
      <button
        type="button"
        disabled={page <= 1}
        onClick={() => {
          onChange(page - 1);
        }}
      >
        السابق
      </button>{' '}
      <span>
        صفحة {page} من {Math.max(lastPage, 1)}
      </span>{' '}
      <button
        type="button"
        disabled={page >= lastPage}
        onClick={() => {
          onChange(page + 1);
        }}
      >
        التالي
      </button>
    </nav>
  );
}
