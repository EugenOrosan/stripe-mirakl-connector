Stripe Mirakl Connector Docker Sample
=======================

## About this sample

Based on [TrafeX/docker-php-nginx](https://github.com/TrafeX/docker-php-nginx), this sample project shows how to build and start the [Stripe Mirakl Connector](https://github.com/stripe/stripe-mirakl-connector) application on PHP-FPM 7.3, Nginx 1.16 and PostgreSQL 11.5 using Docker.

Although not production-ready as-is, it shows the basic configuration required.

Some examples of tasks required to complete the configuration for production:
- Replace the [certs](examples/docker/certs) content with valid certificates.
- Update [nginx.conf](app/config/nginx.conf) and [php.ini](app/config/php.ini) to fit your server configuration.
- Deny access to the OpenAPI specs.

## Installation

### Option 1. Without Secure (SSL) Connection

1. Rename [.env.dist](../../.env.dist) to `.env` and update the configuration, see the [Configuration](https://stripe.com/docs/plugins/mirakl/configuration) step in our docs.
2. From the [examples/docker](./) folder, run `docker compose build --no-cache` to build the application.
3. After the build is done successfully, from the [examples/docker](./) folder, run `docker compose up` to start the application.

### Option 2. With Secure (SSL) Connection

1. Rename [.env.dist](../../.env.dist) to `.env` and update the configuration, see the [Configuration](https://stripe.com/docs/plugins/mirakl/configuration) step in our docs.
2. Create the `server.key` `server.crt` `client.crt` `client.key` (client is used for the Symfony app) and `ca.crt` files in the `examples/docker_ssl/certs` folder. When creating the certificates, they have to be signed by the same authority and the CN (Common Name) for the Symfony app is 'symfony'.
3. Gzip and encode the `server.key`, `server.crt`, `client.key`, `client.crt` and `ca.crt` files in base64 using the following example command:
```bash
# Compress the SSL certificate file using gzip
gzip -c server.crt > server.crt.gz
# Encode the compressed file in Base64
base64 server.crt.gz | tr -d '\n' > server.crt.gz.b64
```
4. Rename the `examples/docker_ssl/.env.dist` file to `examples/docker_ssl/.env`
5. Copy the content of `server.key.gz.b64` `server.crt.gz.b64`, `client.crt.gz.b64`, `client.key.gz.b64` and `ca.crt.gz.b64` encoded files in the `examples/docker_ssl/.env`:
```bash
PGSSLROOTCERT=content of the ca.crt file
PGSSLCERT=content of the server.crt file
PGSSLKEY=content of the server.key file
PGSSLCLIENTCERT=content of the client.crt file
PGSSLCLIENTKEY=content of the client.key file
```
6. Update in the [.env](../../.env.dist) file the `DATABASE_URL` with the following format: `pgsql://symfony:symfony@db:5432/symfony?charset=UTF-8&sslmode=verify-full&sslrootcert=/etc/ssl/certs/root.crt&sslcert=/etc/ssl/certs/client.crt&sslkey=/etc/ssl/private/client.key
7. Optional: if you want to view the logs of the database connection, uncomment the following lines in the `examples/docker_ssl/config/config-ssl.sql` file:
```bash
 ALTER SYSTEM SET log_connections TO 'on';
 ALTER SYSTEM SET log_hostname TO 'on';
```
8. After the build is done successfully, from the [examples/docker_ssl](./) folder, run `docker compose up` to start the application.



## Versioning

See also [Versioning](../../README.md#versioning).

To upgrade:

1. Delete the `var` folder to clean the cache.
2. From the root of your clone, run `git pull` to download changes.
3. From the [examples/docker](./) ( or [examples/docker_ssl](./) in case of database SSL connection) folder, run `docker-compose up -d --build app` to rebuild and deploy the new version.
4. Run `make db-install` to check and apply database updates.

To downgrade:

1. Find the latest database migration for the targeted version in [src/Migrations](../../src/Migrations).
2. Run the database migrations with that version, e.g. `docker-compose run --rm app bin/console doctrine:migration:migrate --no-interaction 20201016122853`
3. Delete the `var` folder to clean the cache.
4. From the root of your clone, run `git reset` to the desired commit or tag.
5. From the [examples/docker](./) ( or [examples/docker_ssl](./) in case of database SSL connection) folder, run `docker-compose up -d --build app` to rebuild and deploy the desired version.

## Start jobs manually

1. Find the command you wish to run manually in [app/config/crontab](app/config/crontab).
2. Run the command through docker, e.g. `docker-compose run --rm app php bin/console connector:dispatch:process-transfer`

## Read logs

Logs are available under the `app` service: `docker-compose logs -tf app`.

