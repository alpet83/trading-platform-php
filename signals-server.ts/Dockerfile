FROM node:22-alpine as base-builder

WORKDIR /app

FROM base-builder as build_fe

WORKDIR /app


COPY ./frontend/package.json ./frontend/yarn.lock* ./
RUN yarn install

ADD ./frontend .

ARG APP_BASE_PATH=
ARG SIGNALS_API_URL=https://$DOMAIN/signals/
RUN export APP_BASE_PATH=$APP_BASE_PATH NUXT_BASE_URL=$APP_BASE_PATH SIGNALS_API_URL=$SIGNALS_API_URL
RUN /usr/bin/env > /var/log/build.log
RUN yarn generate

FROM base-builder as build_be

WORKDIR /app

COPY ./backend/package.json ./backend/yarn.lock* ./
COPY ./backend/package.json ./backend/yarn.lock* ./backend/.npmrc* ./
RUN yarn install
ADD ./backend ./
RUN yarn build

FROM node:16-alpine as finalNode

WORKDIR /app
COPY --from=build_be /app /app
COPY --from=build_fe /app/dist /app/static

CMD yarn start:prod

FROM nginx:alpine as finalNginx

WORKDIR /usr/share/nginx/html

RUN rm -rf ./*
COPY --from=finalNode /app/static .
COPY ./docker/nginx/conf.conf /etc/nginx/templates/default.conf.template
CMD ["/bin/sh", "-c", "envsubst '$$APP_BASE_PATH' < /etc/nginx/templates/default.conf.template > /etc/nginx/conf.d/default.conf && nginx -g 'daemon off;'"]
