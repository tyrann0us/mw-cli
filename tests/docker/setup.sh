#!/bin/sh
# Installs the wiki on first start, keeps a snapshot for reset-wiki, then serves.
set -e
cd /var/www/html

if [ ! -f LocalSettings.php ]; then
	if [ "$DB" = mariadb ]; then
		until mariadb-admin ping -h db -uroot -proot --silent; do sleep 1; done
		set -- --dbtype=mysql --dbserver=db --dbname=wiki --dbuser=root --dbpass=root
	else
		set -- --dbtype=sqlite --dbpath=/var/www/data
	fi
	php maintenance/run.php install "$@" --server=http://localhost --scriptpath= \
		--pass=AdminPassword123 'Test Wiki' Admin
	if [ "$SMW" = 1 ]; then
		printf "wfLoadExtension( 'SemanticMediaWiki' );\n" >> LocalSettings.php
		php maintenance/run.php update --quick
	fi
	if [ "$DB" = mariadb ]; then
		mariadb-dump -h db -uroot -proot wiki > /var/www/snapshot.sql
	else
		cp -a /var/www/data /var/www/data.snapshot
	fi
fi

if [ "$COVERAGE" = 1 ]; then
	# The container runs as root; hand build/ to the owner of the checkout so the host can write the report.
	mkdir -p /mw-cli/build/coverage && chown -R "$(stat -c %u:%g /mw-cli)" /mw-cli/build
	printf 'pcov.enabled=1\npcov.directory=/mw-cli\nauto_prepend_file=/mw-cli/tests/coverage/prepend.php\n' \
		> /usr/local/etc/php/conf.d/zz-pcov.ini
fi

touch /ready
/usr/sbin/sshd
exec apache2-foreground
