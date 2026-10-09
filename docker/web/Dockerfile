FROM node:22-alpine

ENV NODE_ENV=development \
    COREPACK_ENABLE_DOWNLOAD_PROMPT=0

RUN corepack enable \
    && apk add --no-cache git

COPY start.sh /usr/local/bin/start.sh
RUN chmod +x /usr/local/bin/start.sh

WORKDIR /app

EXPOSE 5174

CMD ["start.sh"]
