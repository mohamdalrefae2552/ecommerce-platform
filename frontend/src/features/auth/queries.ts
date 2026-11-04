import { queryOptions } from '@tanstack/react-query';
import { api, requireData } from '../../api/client';

export const authKeys = {
  me: ['auth', 'me'] as const,
};

export const meQuery = queryOptions({
  queryKey: authKeys.me,
  queryFn: async ({ signal }) => requireData(await api.GET('/auth/me', { signal })).data,
});
