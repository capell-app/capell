#!/usr/bin/env bash

set -e

mysql --user=root --password="$MYSQL_ROOT_PASSWORD" <<-EOSQL
    CREATE DATABASE IF NOT EXISTS testing;
EOSQL

if [ "${CAPELL_DB_USERNAME:-root}" != "root" ]; then
    mysql --user=root --password="$MYSQL_ROOT_PASSWORD" <<-EOSQL
        CREATE USER IF NOT EXISTS '$CAPELL_DB_USERNAME'@'%' IDENTIFIED BY '$CAPELL_DB_PASSWORD';
        GRANT ALL PRIVILEGES ON \`$MYSQL_DATABASE\`.* TO '$CAPELL_DB_USERNAME'@'%';
        GRANT ALL PRIVILEGES ON \`testing%\`.* TO '$CAPELL_DB_USERNAME'@'%';
        FLUSH PRIVILEGES;
EOSQL
fi
