#!/usr/bin/env bash
set -euo pipefail
umask 077

sso_dir=/home/mis-ubuntu-1/lguquezon/sso
ca_dir=/home/mis-ubuntu-1/lguquezon/infra/certs
tls_dir="$sso_dir/tls"

if [ -e "$tls_dir/sso.key" ] || [ -e "$tls_dir/sso.fullchain.crt" ]; then
    printf 'SSO certificate already exists; stopped\n' >&2
    exit 1
fi
install -d -m 700 "$tls_dir"

cat > "$tls_dir/request.cnf" <<'CONFIG'
[req]
prompt = no
distinguished_name = subject
req_extensions = server_extensions

[subject]
CN = sso.lguquezon.local

[server_extensions]
subjectAltName = @names
basicConstraints = CA:FALSE
keyUsage = digitalSignature, keyEncipherment
extendedKeyUsage = serverAuth

[names]
DNS.1 = sso.lguquezon.local
DNS.2 = api.sso.lguquezon.local
CONFIG

openssl req -new -newkey rsa:3072 -nodes \
    -keyout "$tls_dir/sso.key" \
    -out "$tls_dir/sso.csr" \
    -config "$tls_dir/request.cnf" >/dev/null 2>&1

serial=$(openssl rand -hex 16)
openssl x509 -req -in "$tls_dir/sso.csr" \
    -CA "$ca_dir/lguquezonCA.crt" \
    -CAkey "$ca_dir/lguquezonCA.key" \
    -set_serial "0x$serial" -days 397 -sha256 \
    -extfile "$tls_dir/request.cnf" -extensions server_extensions \
    -out "$tls_dir/sso.crt" >/dev/null 2>&1

cat "$tls_dir/sso.crt" "$ca_dir/lguquezonCA.crt" > "$tls_dir/sso.fullchain.crt"
chmod 600 "$tls_dir/sso.key"
chmod 644 "$tls_dir/sso.crt" "$tls_dir/sso.fullchain.crt"
openssl verify -CAfile "$ca_dir/lguquezonCA.crt" "$tls_dir/sso.crt"
openssl x509 -in "$tls_dir/sso.crt" -noout -ext subjectAltName -enddate
