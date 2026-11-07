import { Button } from './Button';
import { ChevronEndIcon, ChevronStartIcon } from './icons';

interface PaginationProps {
  readonly page: number;
  readonly lastPage: number;
  readonly onChange: (page: number) => void;
}

export function Pagination({ page, lastPage, onChange }: PaginationProps) {
  return (
    <nav aria-label="تقسيم الصفحات" className="pagination">
      <Button
        variant="secondary"
        size="sm"
        icon={<ChevronStartIcon />}
        disabled={page <= 1}
        onClick={() => {
          onChange(page - 1);
        }}
      >
        السابق
      </Button>
      <span className="pagination-info">
        صفحة {page} من {Math.max(lastPage, 1)}
      </span>
      <Button
        variant="secondary"
        size="sm"
        className="btn-icon-end"
        icon={<ChevronEndIcon />}
        disabled={page >= lastPage}
        onClick={() => {
          onChange(page + 1);
        }}
      >
        التالي
      </Button>
    </nav>
  );
}
