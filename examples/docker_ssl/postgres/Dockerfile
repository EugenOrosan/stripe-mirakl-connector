FROM postgres:14.0-alpine

# Install necessary packages
RUN apk update && apk add --no-cache \
    gzip \
    coreutils \
    bash

# Create the SSL directory
RUN mkdir -p /etc/ssl/private /etc/ssl/certs

# Set environment variables for SSL certificates
ARG PGSSLROOTCERT
ARG PGSSLKEY
ARG PGSSLCERT
ENV CERT_DIR="/etc/ssl"

# Create SSL certificates
COPY postgres/db_ssl.sh /var/db_ssl.sh
RUN chmod +x /var/db_ssl.sh

# Execute the SSL setup script with build arguments
RUN PGSSLROOTCERT=$PGSSLROOTCERT PGSSLKEY=$PGSSLKEY PGSSLCERT=$PGSSLCERT /var/db_ssl.sh

# Verify the existence of SSL files
RUN ls -l /etc/ssl/private/server.key && ls -l /etc/ssl/certs/server.crt && ls -l /etc/ssl/certs/root.crt

# Copy PostgreSQL configuration files
COPY config/postgresql.conf /etc/postgresql/postgresql.conf
COPY config/pg_hba.conf /etc/postgresql/pg_hba.conf

# Set ownership and permissions for the SSL certificates and config files
RUN chown postgres:postgres /etc/ssl/private/server.key \
    && chmod 600 /etc/ssl/private/server.key \
    && chown postgres:postgres /etc/ssl/certs/server.crt \
    && chmod 600 /etc/ssl/certs/server.crt \
    && chown postgres:postgres /etc/ssl/certs/root.crt \
    && chmod 600 /etc/ssl/certs/root.crt \
    && chown -R postgres:postgres /etc/postgresql \
    && chown -R postgres:postgres /var/lib/postgresql

ENV PGSSLROOTCERT=/etc/ssl/certs/root.crt
ENV PGSSLCERT=/etc/ssl/certs/server.crt
ENV PGSSLKEY=/etc/ssl/private/server.key

# Start PostgreSQL with the custom configuration
CMD ["postgres", "-c", "config_file=/etc/postgresql/postgresql.conf"]
