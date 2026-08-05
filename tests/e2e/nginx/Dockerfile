FROM nginx:1.25-alpine

RUN apk add --no-cache curl inotify-tools

COPY site.conf /etc/nginx/conf.d/default.conf
COPY entrypoint.sh /entrypoint.sh

RUN chmod +x /entrypoint.sh

ENTRYPOINT ["/entrypoint.sh"]
