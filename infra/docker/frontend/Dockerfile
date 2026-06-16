FROM node:22-alpine

WORKDIR /app

RUN corepack enable

COPY frontend/ ./

RUN pnpm install

EXPOSE 3000
