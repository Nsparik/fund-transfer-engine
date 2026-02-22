# ═══════════════════════════════════════════════════════════════════════════════
# Apache Dockerfile
#
# Uses the official httpd:2.4 image (Alpine variant for minimal attack surface).
# Works on AMD64 (Ubuntu/Linux servers) and ARM64 (Mac M1/M2/M3) — both
# officially supported by the httpd Docker image.
# ═══════════════════════════════════════════════════════════════════════════════
FROM httpd:2.4-alpine

# ─── Enable required Apache modules ──────────────────────────────────────────
# mod_proxy + mod_proxy_fcgi : forward PHP requests to PHP-FPM over TCP
# mod_headers                : set security headers (X-Frame-Options, etc.)
# mod_rewrite                : Symfony routing (FallbackResource needs it)
#
# httpd:2.4-alpine ships with these compiled in but not enabled.
# We patch httpd.conf to uncomment the LoadModule lines.
RUN sed -i \
        -e 's|#LoadModule proxy_module|LoadModule proxy_module|' \
        -e 's|#LoadModule proxy_fcgi_module|LoadModule proxy_fcgi_module|' \
        -e 's|#LoadModule headers_module|LoadModule headers_module|' \
        -e 's|#LoadModule rewrite_module|LoadModule rewrite_module|' \
        /usr/local/apache2/conf/httpd.conf \
    # Include our vhost config from conf/extra/
    && echo "Include conf/extra/vhost.conf" >> /usr/local/apache2/conf/httpd.conf

# ─── Virtual Host Configuration ───────────────────────────────────────────────
COPY vhost.conf /usr/local/apache2/conf/extra/vhost.conf

# Port 80 — exposed to the host only (only public entry point)
EXPOSE 80
