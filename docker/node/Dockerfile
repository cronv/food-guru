# Сборка фронтендов и запуск SSR-сервера публичного сайта.
FROM node:22-alpine AS build

WORKDIR /app

# Отдельный слой зависимостей: пересобирается только при смене package.json.
COPY frontend/package.json frontend/package-lock.json* ./
RUN npm ci || npm install

COPY frontend/ ./
RUN npm run build:site && npm run build:admin

# --- рантайм только с готовой сборкой ---
FROM node:22-alpine AS runtime

WORKDIR /app
ENV NODE_ENV=production

COPY --from=build /app/dist ./dist
COPY --from=build /app/package.json ./package.json
# SSR-серверу нужен express; остальное уже внутри бандла.
RUN npm install express@4 --omit=dev

EXPOSE 4000
CMD ["node", "dist/site/server/server.mjs"]
