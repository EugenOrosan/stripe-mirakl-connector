#!/bin/bash

echo 'Start DB SSL setup'
echo "PGSSLROOTCERT: ${PGSSLROOTCERT}"
echo "PGSSLKEY: ${PGSSLKEY}"
echo "PGSSLCERT: ${PGSSLCERT}"

# Function to create SSL certificates files from environment variables
build_file() {
  echo "${1}" | base64 -d | gunzip > "${2}"
  chmod 400 "${2}"
  echo "Created file ${2} with permissions:"
  ls -l "${2}"
}

certdir=${CERT_DIR:-"/etc/ssl"}
mkdir -p "${certdir}/certs" "${certdir}/private"
pdir="${certdir}/private"
cdir="${certdir}/certs"

if [[ -n "${PGSSLROOTCERT}" ]] && [[ "${PGSSLROOTCERT}" != "None" ]]; then
  echo "Writing postgres ssl ca cert"
  build_file "${PGSSLROOTCERT}" "${cdir}/root.crt"
fi

if [[ -n "${PGSSLKEY}" ]] && [[ "${PGSSLKEY}" != "None" ]]; then
  echo "Writing postgres ssl client key"
  build_file "${PGSSLKEY}" "${pdir}/server.key"
fi

if [[ -n "${PGSSLCERT}" ]] && [[ "${PGSSLCERT}" != "None" ]]; then
  echo "Writing postgres ssl client cert"
  build_file "${PGSSLCERT}" "${cdir}/server.crt"
fi

echo 'End DB SSL setup'

