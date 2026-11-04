# syntax=docker/dockerfile:1.7
# edge = nginx serving the React build, proxying /api to PHP-FPM, /docs to
# Swagger UI and /storage to the product-image volume.
# The `frontend` build context is passed by compose (additional_contexts).

FROM node:24.22.2-alpine3.24 AS spa
ENV PNPM_HOME=/pnpm
# `pnpm --version` downloads pnpm's native binary into this cached layer.
RUN corepack enable && corepack prepare pnpm@12.16.1 --activate && pnpm --version
WORKDIR /app
COPY --from=frontend package.json pnpm-lock.yaml ./
RUN --mount=type=cache,id=pnpm-store,target=/pnpm/store pnpm install --frozen-lockfile
COPY --from=frontend . .
RUN pnpm run build

FROM nginx:1.30.6-alpine
RUN rm -f /etc/nginx/conf.d/default.conf
COPY nginx.conf /etc/nginx/nginx.conf
COPY conf.d/ /etc/nginx/conf.d/
COPY snippets/ /etc/nginx/snippets/
COPY --from=spa /app/dist /usr/share/nginx/html
EXPOSE 80
