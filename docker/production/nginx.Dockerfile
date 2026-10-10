# PaxofiCloud Nginx image for staging/production (deploy threat model P-9).
# Serves only the front controller; TLS terminates at Cloudflare and traffic
# arrives through the outbound-only Cloudflare Tunnel.
FROM nginx:1.28-alpine@sha256:a8b39bd9cf0f83869a2162827a0caf6137ddf759d50a171451b335cecc87d236

RUN rm -f /etc/nginx/conf.d/*.conf
COPY docker/production/nginx.conf /etc/nginx/conf.d/paxoficloud.conf
COPY public/ /app/public/

ARG APP_REVISION=unknown
LABEL org.opencontainers.image.title="paxoficloud-nginx" \
      org.opencontainers.image.revision="${APP_REVISION}" \
      org.opencontainers.image.source="https://github.com/Paxofi-Technologies/paxofi-cloud"

EXPOSE 8080
