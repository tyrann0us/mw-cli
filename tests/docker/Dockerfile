# Test wiki for the Behat suite. mw is on the PATH (the repo is mounted at /mw-cli), sshd serves
# the @ssh scenarios, and setup.sh installs the wiki on first start (SQLite or MariaDB).
ARG MW=1.43
FROM mediawiki:${MW}

# Set to a branch (e.g. master) to replace the release with a git checkout of MediaWiki core.
ARG MW_BRANCH=
ARG SMW=0

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
RUN apt-get update \
	&& apt-get install -y --no-install-recommends openssh-server unzip git mariadb-client $PHPIZE_DEPS \
	&& pecl install pcov && docker-php-ext-enable pcov && echo 'pcov.enabled=0' > /usr/local/etc/php/conf.d/zz-pcov.ini \
	&& apt-get purge -y $PHPIZE_DEPS && apt-get autoremove -y && rm -rf /var/lib/apt/lists/* \
	&& mkdir -p /run/sshd /root/.ssh \
	&& ssh-keygen -q -t ed25519 -N '' -f /root/.ssh/id_ed25519 \
	&& cp /root/.ssh/id_ed25519.pub /root/.ssh/authorized_keys \
	&& printf 'Host remotecmd\n  HostName localhost\n  RemoteCommand echo RemoteCommand ran\nHost *\n  StrictHostKeyChecking no\n  UserKnownHostsFile /dev/null\n  LogLevel ERROR\n' > /root/.ssh/config \
	&& ln -s /mw-cli/bin/mw /usr/local/bin/mw

ENV COMPOSER_ALLOW_SUPERUSER=1
RUN if [ -n "$MW_BRANCH" ]; then \
		cd / && rm -rf /var/www/html \
		&& git clone --depth 1 -b "$MW_BRANCH" https://gerrit.wikimedia.org/r/mediawiki/core.git /var/www/html \
		&& composer install -d /var/www/html --no-dev --no-interaction --no-progress; \
	fi

RUN if [ "$SMW" = 1 ]; then \
		COMPOSER=composer.local.json composer require --no-update mediawiki/semantic-media-wiki:~7.4 \
		&& composer update --no-dev --no-interaction --no-progress; \
	fi

COPY setup.sh reset-wiki /usr/local/bin/
CMD ["setup.sh"]
