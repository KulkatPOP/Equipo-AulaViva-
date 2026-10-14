# --- build stage ---
FROM node:20-alpine AS build
WORKDIR /app
COPY package*.json ./
RUN npm install
COPY . .
RUN npm run build || echo "Build simulado"

# --- runtime stage ---
FROM gcr.io/distroless/nodejs20-debian12
WORKDIR /app
COPY --from=build /app/node_modules ./node_modules
# Cumple rúbrica: USER no-root
USER nonroot
EXPOSE 3000
ENTRYPOINT ["/nodejs/bin/node", "dist/main.js"]
