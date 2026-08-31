FROM node:22.22.3-alpine@sha256:e58326d0d441090181ac150dc2078d3e2cf6a0d42e809aebba3ef5880935ffdd AS build

WORKDIR /workspace

ENV NEXT_TELEMETRY_DISABLED=1

RUN corepack enable \
    && corepack prepare pnpm@10.12.1 --activate

COPY package.json pnpm-workspace.yaml pnpm-lock.yaml ./
COPY apps/admin/package.json apps/admin/package.json
COPY packages/platform/package.json packages/platform/package.json
COPY packages/storefront-client/package.json packages/storefront-client/package.json
COPY packages/site-schema/package.json packages/site-schema/package.json
COPY packages/storefront-testkit/package.json packages/storefront-testkit/package.json

RUN pnpm install --frozen-lockfile

COPY apps/admin apps/admin

RUN pnpm --filter @oripa/admin build

FROM node:22.22.3-alpine@sha256:e58326d0d441090181ac150dc2078d3e2cf6a0d42e809aebba3ef5880935ffdd AS production

ARG OCI_VERSION
ARG OCI_REVISION
ARG OCI_CREATED
ARG OCI_SOURCE=https://github.com/ideal-sol/oripa

LABEL org.opencontainers.image.version="${OCI_VERSION}" \
      org.opencontainers.image.revision="${OCI_REVISION}" \
      org.opencontainers.image.source="${OCI_SOURCE}" \
      org.opencontainers.image.created="${OCI_CREATED}" \
      org.opencontainers.image.title="Oripa V2 Admin"

ENV HOSTNAME=0.0.0.0 \
    NEXT_TELEMETRY_DISABLED=1 \
    NODE_ENV=production \
    PORT=3000

WORKDIR /app

COPY --from=build --chown=node:node /workspace/apps/admin/.next/standalone/ ./
COPY --from=build --chown=node:node /workspace/apps/admin/.next/static/ ./apps/admin/.next/static/

RUN rm -rf \
        /usr/local/lib/node_modules/corepack \
        /usr/local/lib/node_modules/npm \
    && rm -f \
        /usr/local/bin/corepack \
        /usr/local/bin/npm \
        /usr/local/bin/npx \
        /usr/local/bin/pnpm \
        /usr/local/bin/pnpx

USER node

WORKDIR /app/apps/admin

EXPOSE 3000

CMD ["node", "server.js"]
