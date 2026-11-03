# syntax=docker/dockerfile:1.7
# Frontend images (ADR-0010 pins node and pnpm).
#   base  — node + pnpm only; compose `web-test` / `web-dev` mount the source at
#           /work/frontend and the contracts at /work/contracts.
#   build — standalone static build (`dist/`). The `edge` image builds the same
#           way in infra/nginx/Dockerfile and copies `dist/` into nginx.

FROM node:24.22.2-alpine3.24 AS base
# pnpm keeps its content-addressable store in $PNPM_HOME/store (a cache volume).
ENV PNPM_HOME=/pnpm
ENV PATH=$PNPM_HOME:$PATH
# pnpm >= 11 is a native binary that the corepack wrapper downloads on first
# use; `pnpm --version` bakes it into this layer instead of every container run.
RUN corepack enable && corepack prepare pnpm@12.16.1 --activate && pnpm --version
WORKDIR /work/frontend

FROM base AS build
COPY package.json pnpm-lock.yaml ./
RUN --mount=type=cache,id=pnpm-store,target=/pnpm/store pnpm install --frozen-lockfile
COPY . .
RUN pnpm run build
