ALTER SYSTEM SET ssl_cert_file TO '/etc/ssl/certs/server.crt';
ALTER SYSTEM SET listen_addresses TO '*';
ALTER SYSTEM SET ssl_key_file TO '/etc/ssl/private/server.key';
ALTER SYSTEM SET ssl_ca_file TO '/etc/ssl/certs/root.crt';
ALTER SYSTEM SET ssl TO 'ON';
ALTER SYSTEM SET hba_file TO '/etc/postgresql/pg_hba.conf';
ALTER SYSTEM SET ssl_crl_file TO '';
ALTER SYSTEM SET ssl_ciphers TO 'HIGH:MEDIUM:+3DES:!aNULL';
-- ALTER SYSTEM SET log_connections TO 'on';
-- ALTER SYSTEM SET log_hostname TO 'on';

