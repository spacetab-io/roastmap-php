FROM roquie/composer-parallel

WORKDIR /depends

COPY composer.json /depends/
COPY composer.lock /depends/

RUN composer install --ignore-platform-reqs

FROM spacetabio/amphp-alpine:7.4-base-1.1.0

COPY --from=0 /depends/vendor /app/vendor/
COPY . /app/

ENTRYPOINT ["/app/bin/roastmap"]
