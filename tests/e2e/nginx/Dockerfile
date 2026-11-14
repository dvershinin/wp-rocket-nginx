FROM alpine:3.22

# The Brotli module is loaded (Alpine auto-loads /etc/nginx/modules/*.conf) but
# stays off until the test toggles it through e2e-brotli.http.
RUN apk add --no-cache nginx nginx-mod-http-brotli curl inotify-tools \
    && rm -f /etc/nginx/http.d/default.conf

COPY site.conf /etc/nginx/http.d/default.conf
COPY brotli-toggle.conf /etc/nginx/http.d/brotli-toggle.conf
COPY entrypoint.sh /entrypoint.sh

RUN chmod +x /entrypoint.sh

ENTRYPOINT ["/entrypoint.sh"]
