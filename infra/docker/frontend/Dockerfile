FROM node:22-alpine AS base

WORKDIR /app

ENV NEXT_TELEMETRY_DISABLED=1

RUN corepack enable \
    && corepack prepare pnpm@10.12.1 --activate

FROM base AS build-dependencies

COPY frontend/package.json frontend/pnpm-lock.yaml ./

RUN pnpm install --frozen-lockfile --ignore-workspace

FROM build-dependencies AS builder

COPY frontend/ ./

ARG NEXT_PUBLIC_APP_NAME=Oripa
ARG NEXT_PUBLIC_API_BASE_URL
ARG NEXT_PUBLIC_FRONTEND_URL

ENV NEXT_PUBLIC_APP_NAME=${NEXT_PUBLIC_APP_NAME} \
    NEXT_PUBLIC_API_BASE_URL=${NEXT_PUBLIC_API_BASE_URL} \
    NEXT_PUBLIC_FRONTEND_URL=${NEXT_PUBLIC_FRONTEND_URL}

RUN pnpm build

FROM base AS production-dependencies

COPY frontend/package.json frontend/pnpm-lock.yaml ./

RUN pnpm install --prod --frozen-lockfile --ignore-workspace

FROM node:22-alpine AS runtime

WORKDIR /app

ENV NEXT_TELEMETRY_DISABLED=1 \
    NODE_ENV=production \
    HOSTNAME=0.0.0.0 \
    PORT=3000

COPY --from=production-dependencies --chown=node:node /app/node_modules ./node_modules
COPY --from=builder --chown=node:node /app/.next ./.next
COPY --from=builder --chown=node:node /app/public ./public
COPY --from=builder --chown=node:node /app/package.json ./package.json
COPY --from=builder --chown=node:node /app/next.config.ts ./next.config.ts

USER node

EXPOSE 3000

CMD ["node", "node_modules/next/dist/bin/next", "start", "--hostname", "0.0.0.0", "--port", "3000"]
