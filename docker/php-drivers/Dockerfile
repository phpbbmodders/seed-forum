# PHP CLI with every database driver phpBB supports except Oracle, for boards
# whose database the host's PHP can't reach (board.database: mssql). Built and
# tagged by bin/board-php.py; the tag carries a hash of this file, so editing
# it builds a new image.
FROM php:8.4-cli

# Microsoft's ODBC driver, which the sqlsrv extensions need. Installing it
# accepts Microsoft's ODBC driver licence (ACCEPT_EULA). Version 17, because 18
# encrypts by default and rejects the test container's self-signed certificate,
# and phpBB's driver has no setting to trust it. The Debian 13
# repository is signed with Microsoft's 2025 key; the build stops if the
# downloaded key doesn't have this fingerprint.
ARG MICROSOFT_SIGNER_FPR=AA86F75E427A19DD33346403EE4D7792F748182B
RUN set -eux; \
	apt-get update; \
	apt-get install -y --no-install-recommends ca-certificates curl gnupg libpq-dev unixodbc-dev; \
	curl -fsSL https://packages.microsoft.com/keys/microsoft-2025.asc -o /tmp/microsoft.asc; \
	gpg --show-keys --with-colons /tmp/microsoft.asc | grep -qx "fpr:::::::::${MICROSOFT_SIGNER_FPR}:"; \
	gpg --dearmor -o /usr/share/keyrings/microsoft-prod.gpg /tmp/microsoft.asc; \
	rm /tmp/microsoft.asc; \
	curl -fsSL https://packages.microsoft.com/config/debian/13/prod.list -o /etc/apt/sources.list.d/mssql-release.list; \
	apt-get update; \
	ACCEPT_EULA=Y apt-get install -y --no-install-recommends msodbcsql17=17.11.1.1-1; \
	pecl install sqlsrv-5.13.3 pdo_sqlsrv-5.13.3; \
	docker-php-ext-enable sqlsrv pdo_sqlsrv; \
	docker-php-ext-install mysqli pgsql; \
	rm -rf /var/lib/apt/lists/* /tmp/pear

# Test boards: no opcache, so edited extension files take effect at once.
RUN echo 'opcache.enable=0' > /usr/local/etc/php/conf.d/zz-seed-forum.ini; \
	echo 'opcache.enable_cli=0' >> /usr/local/etc/php/conf.d/zz-seed-forum.ini; \
	echo 'memory_limit=512M' >> /usr/local/etc/php/conf.d/zz-seed-forum.ini
