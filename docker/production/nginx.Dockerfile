# PaxofiCloud Nginx image for staging/production (deploy threat model P-9).
# Serves the customer-portal web build as static files and routes the API to
# the PHP front controller. TLS terminates at Cloudflare and traffic arrives
# through the outbound-only Cloudflare Tunnel.

# Stage 1: build the web app. Nothing from this stage ships except dist/.
FROM node:22-alpine@sha256:0a7108bf6c7bf5de370ffb1a3ed6be93d405b43ff159f681a8d18c0e2bc2e402 AS web
WORKDIR /web
COPY frontend/package.json frontend/package-lock.json ./
RUN npm ci --no-audit --no-fund --ignore-scripts
COPY frontend/ ./
RUN npm run build

# Stage 2: runtime.
FROM nginx:1.28-alpine@sha256:a8b39bd9cf0f83869a2162827a0caf6137ddf759d50a171451b335cecc87d236

RUN rm -f /etc/nginx/conf.d/*.conf
COPY docker/production/nginx.conf /etc/nginx/conf.d/paxoficloud.conf
COPY docker/production/web-security-headers.conf /etc/nginx/snippets/web-security-headers.conf
COPY public/ /app/public/
COPY --from=web /web/dist/ /app/web/

ARG APP_REVISION=unknown
LABEL org.opencontainers.image.title="paxoficloud-nginx" \
      org.opencontainers.image.revision="${APP_REVISION}" \
      org.opencontainers.image.source="https://github.com/Paxofi-Technologies/paxofi-cloud"

EXPOSE 8080
