# Test wiki for the Behat suite: MediaWiki on SQLite, mw on the PATH (repo mounted at /mw-cli),
# sshd for the @ssh scenarios and, with SMW=1, Semantic MediaWiki for the @smw ones.
FROM mediawiki:1.43

ARG SMW=0
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
RUN apt-get update \
	&& apt-get install -y --no-install-recommends openssh-server unzip \
	&& rm -rf /var/lib/apt/lists/* \
	&& mkdir -p /run/sshd /root/.ssh \
	&& ssh-keygen -q -t ed25519 -N '' -f /root/.ssh/id_ed25519 \
	&& cp /root/.ssh/id_ed25519.pub /root/.ssh/authorized_keys \
	&& printf 'Host *\n  StrictHostKeyChecking no\n  UserKnownHostsFile /dev/null\n  LogLevel ERROR\n' > /root/.ssh/config \
	&& ln -s /mw-cli/bin/mw /usr/local/bin/mw

RUN php maintenance/run.php install --dbtype=sqlite --dbpath=/var/www/data \
		--server=http://localhost:8080 --scriptpath= --pass=AdminPassword123 'Test Wiki' Admin

RUN if [ "$SMW" = 1 ]; then \
		export COMPOSER_ALLOW_SUPERUSER=1 COMPOSER=composer.local.json \
		&& composer require --no-update mediawiki/semantic-media-wiki:~5.0 \
		&& composer update --no-dev --no-interaction --no-progress \
		&& printf "wfLoadExtension( 'SemanticMediaWiki' );\nenableSemantics( 'localhost' );\n" >> LocalSettings.php \
		&& php maintenance/run.php update --quick; \
	fi

# Each scenario starts from this copy of the database.
RUN chown -R www-data /var/www/data && cp -a /var/www/data /var/www/data.snapshot

CMD ["sh", "-c", "/usr/sbin/sshd && exec apache2-foreground"]
