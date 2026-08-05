SHELL := /bin/sh
VERSION := $(shell sed -n 's/^ \* Version: //p' wp-rocket-nginx.php)
PACKAGE_FILES := wp-rocket-nginx.php includes templates LICENSE NOTICE README.md readme.txt

.PHONY: test e2e package clean

test:
	find . -path './.git' -prune -o -name '*.php' -print0 | xargs -0 -n1 php -l
	php tests/unit/run.php
	sh -n tests/e2e/setup.sh tests/e2e/test.sh tests/e2e/nginx/entrypoint.sh
	docker compose --file tests/e2e/docker-compose.yml config --quiet

e2e:
	tests/e2e/test.sh

package:
	mkdir -p dist
	git archive --format=zip --prefix=wp-rocket-nginx/ --output="dist/wp-rocket-nginx-$(VERSION).zip" HEAD $(PACKAGE_FILES)

clean:
	rm -rf dist
