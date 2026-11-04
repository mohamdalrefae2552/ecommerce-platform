import { queryOptions } from '@tanstack/react-query';
import { api, requireData } from '../../api/client';

export const healthKeys = {
  all: ['health'] as const,
};

export const healthQuery = queryOptions({
  queryKey: healthKeys.all,
  queryFn: async ({ signal }) => requireData(await api.GET('/health', { signal })),
});
