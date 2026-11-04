import { useMutation, useQueryClient } from '@tanstack/react-query';
import { api, requireData } from '../../api/client';
import type { components } from '../../api/schema';
import { endSession, startSession } from '../../auth/session';

type LoginRequest = components['schemas']['LoginRequest'];
type RegisterRequest = components['schemas']['RegisterRequest'];

export function useLogin() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: async (body: LoginRequest) => requireData(await api.POST('/auth/login', { body })),
    onSuccess: ({ data }) => {
      // Data cached for a previous user must never be shown to this one.
      queryClient.clear();
      startSession(data);
    },
  });
}

export function useRegister() {
  return useMutation({
    mutationFn: async (body: RegisterRequest) =>
      requireData(await api.POST('/auth/register', { body })),
  });
}

export function useLogout() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: async () => {
      await api.POST('/auth/logout');
    },
    // Logged out locally even if the server call failed (e.g. network).
    onSettled: () => {
      endSession();
      queryClient.clear();
    },
  });
}
