import { createFileRoute } from '@tanstack/react-router';
import { z } from 'zod';
import { CatalogPage } from '../../features/catalog/CatalogPage';

const searchSchema = z.object({
  category: z
    .string()
    .regex(/^[a-z0-9]+(?:-[a-z0-9]+)*$/)
    .max(64)
    .optional()
    .catch(undefined),
  page: z.number().int().min(1).catch(1),
});

export const Route = createFileRoute('/products/')({
  validateSearch: searchSchema,
  component: CatalogRoute,
});

function CatalogRoute() {
  const { category, page } = Route.useSearch();
  return <CatalogPage category={category} page={page} />;
}
