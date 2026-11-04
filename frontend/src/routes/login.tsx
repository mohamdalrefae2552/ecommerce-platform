import { createFileRoute } from '@tanstack/react-router';
import { z } from 'zod';
import { LoginPage } from '../features/auth/LoginPage';
import { redirectSearchSchema } from '../features/auth/schemas';

const searchSchema = redirectSearchSchema.extend({
  registered: z.boolean().optional().catch(undefined),
});

export const Route = createFileRoute('/login')({
  validateSearch: searchSchema,
  component: LoginRoute,
});

function LoginRoute() {
  const { redirect, registered } = Route.useSearch();
  return <LoginPage redirect={redirect} registered={registered === true} />;
}
