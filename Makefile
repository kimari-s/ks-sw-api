# `make.local` (gitignored) holds local settings: deployment (DEPLOY_HOST,
# DEPLOY_PATH) and, if needed, where to get the specification (SPEC_URL, or
# SPEC_DIR for an existing checkout).
-include make.local

.PHONY: help test spec test-php74 test-php83 test-apache test-admin-disabled test-admin-https test-trusted-proxy test-forwarded-proto test-old-php lint-php conformance check-schema deploy-dry deploy clean

DEPLOY_HOST ?= you@your.host.example.com
DEPLOY_PATH ?= public_html/your-domain/api
# pytest for the test suites (host side; needs Python 3.11+ via uv).
PYTEST      ?= uv run --no-project --with pytest pytest -q
SCRATCH     := .scratch
# The kssw specification (ks-sw-spec): its conformance suite runs against
# this implementation. spec.lock pins the repository URL and commit; `make
# spec` checks that commit out into SPEC_DIR. The URL in spec.lock is the
# public one (works once ks-sw-spec is public); override SPEC_URL in
# make.local (a local path or a private remote), or set SPEC_DIR to an
# existing checkout (used as it is, not pinned).
SPEC_URL    ?= $(shell sed -n 's/^url=//p' spec.lock)
SPEC_COMMIT := $(shell sed -n 's/^commit=//p' spec.lock)
SPEC_DIR    ?= $(SCRATCH)/spec
CONFORMANCE := $(SPEC_DIR)/conformance
# Test settings (tests/config.test.php) and the admin password and token
# whose hashes it holds. Test-only: never on a real server.
TEST_CONFIG := tests/config.test.php
TEST_ADMIN_PASSWORD := test-admin-password
TEST_ADMIN_TOKEN    := kssw-test-admin-token-public-never-use
# What runs against each server: the kssw conformance suite (client and
# admin API), then the PHP-specific tests and the CLI.
SUITES      := ADMIN_TOKEN=$(TEST_ADMIN_TOKEN) $(PYTEST) $(CONFORMANCE) \
	&& ADMIN_PASSWORD=$(TEST_ADMIN_PASSWORD) ADMIN_TOKEN=$(TEST_ADMIN_TOKEN) $(PYTEST) tests/admin tests/cli
# tools/kssw-admin on its oldest supported Python, inside the server
# container's network (so it talks to http://127.0.0.1, as on the server).
CLI_PY38    := docker run --rm --network container:kssw-php74 -e KSSW_URL=http://127.0.0.1:8080 -e KSSW_ADMIN_TOKEN=$(TEST_ADMIN_TOKEN) \
	-v $(CURDIR)/tools:/tools:ro python:3.8-slim sh -c \
	"python /tools/kssw-admin capabilities >/dev/null && python /tools/kssw-admin posts list --page-size 1 >/dev/null \
	&& python /tools/kssw-admin posts delete --dry-run 00000000-0000-4000-8000-000000000000 >/dev/null; test \$$? = 3 \
	&& python /tools/kssw-admin commands >/dev/null && python /tools/kssw-admin token new >/dev/null && echo kssw-admin on python 3.8 OK"

help:
	@echo "Targets:"
	@echo "  test          spec + check-schema + lint-php + test-php74 + test-php83 + test-apache + test-admin-disabled"
	@echo "                + test-admin-https + test-trusted-proxy + test-forwarded-proto + test-old-php"
	@echo "  spec          check out ks-sw-spec at the commit in spec.lock into $(SCRATCH)/spec (SPEC_URL / SPEC_DIR to override)"
	@echo "  test-php74    kssw conformance (+ admin) + tests/admin + tests/cli against php/api on 'php -S' in docker php:7.4-cli;"
	@echo "                plus the CLI on python:3.8"
	@echo "  test-php83    same on php:8.3-cli (backup through the file-copy path)"
	@echo "  test-apache   same behind Apache + .htaccess (docker php:7.4-apache), plus access-denial checks"
	@echo "  test-admin-disabled  no password hash / token: admin.php 403, /v1/admin/* 404 (php:7.4-cli)"
	@echo "  test-admin-https     the admin API refuses plain http unless allowed (php:7.4-cli)"
	@echo "  test-trusted-proxy   behind a trusted TLS-terminating proxy (php:7.4-cli)"
	@echo "  test-forwarded-proto with trusted_proxies, X-Forwarded-Proto/-Ssl count only from them (php:7.4-cli)"
	@echo "  test-old-php         PHP 5.6 / 7.0 / 7.3 answer a php_version error"
	@echo "  lint-php      php -l on every file (7.4 and 8.3)"
	@echo "  conformance   the kssw conformance suite against BASE_URL=... (any server; never production)"
	@echo "  check-schema  php/api/lib/schema.sql is a copy of schema.sql"
	@echo "  deploy-dry / deploy   rsync php/api/ to DEPLOY_HOST:DEPLOY_PATH"

test: spec check-schema lint-php test-php74 test-php83 test-apache test-admin-disabled test-admin-https test-trusted-proxy test-forwarded-proto test-old-php

spec:
	@if [ "$(SPEC_DIR)" != "$(SCRATCH)/spec" ]; then \
		test -d "$(CONFORMANCE)" || { echo "SPEC_DIR=$(SPEC_DIR) has no conformance/" >&2; exit 2; }; \
		echo "spec: using $(SPEC_DIR) as it is (not pinned)"; \
	else \
		test -n "$(SPEC_COMMIT)" || { echo "spec.lock has no commit=" >&2; exit 2; }; \
		if [ ! -d $(SCRATCH)/spec/.git ]; then mkdir -p $(SCRATCH) && git clone -q --no-checkout "$(SPEC_URL)" $(SCRATCH)/spec || exit 1; fi; \
		git -C $(SCRATCH)/spec remote set-url origin "$(SPEC_URL)"; \
		git -C $(SCRATCH)/spec cat-file -e "$(SPEC_COMMIT)^{commit}" 2>/dev/null || git -C $(SCRATCH)/spec fetch -q origin || exit 1; \
		git -C $(SCRATCH)/spec checkout -q --detach "$(SPEC_COMMIT)" && echo "spec: ks-sw-spec $(SPEC_COMMIT)"; \
	fi

check-schema:
	@cmp -s schema.sql php/api/lib/schema.sql || { echo "php/api/lib/schema.sql differs from schema.sql: cp schema.sql php/api/lib/schema.sql" >&2; exit 1; }

lint-php:
	for img in php:7.4-cli php:8.3-cli; do \
		docker run --rm -v $(CURDIR)/php/api:/app:ro $$img sh -c 'for f in /app/*.php /app/lib/*.php; do php -l $$f >/dev/null || exit 1; done && echo "$$0 lint OK"' $$img || exit 1; \
	done

# $(call run_cli,image,port,name,extra docker args,test command)
# `-t /app`: index.php hands /admin.php back to the built-in server.
define run_cli
	scripts/with-server.sh http://127.0.0.1:$(2) \
		"exec docker run --rm --name kssw-$(3) -p 127.0.0.1:$(2):8080 -e KSSW_CONFIG=/conf/config.test.php $(4) \
			-v $(CURDIR)/php/api:/app:ro -v $(CURDIR)/tests:/conf:ro $(1) php -q -S 0.0.0.0:8080 -t /app /app/index.php" \
		'docker rm -f kssw-$(3)' \
		sh -c '$(5)'
endef

test-php74: spec
	$(call run_cli,php:7.4-cli,8774,php74,,$(SUITES) && $(CLI_PY38))

test-php83: spec
	$(call run_cli,php:8.3-cli,8783,php83,-e KSSW_TEST_BACKUP_METHOD=copy,$(SUITES))

test-admin-disabled: spec
	$(call run_cli,php:7.4-cli,8775,php74-noadmin,-e KSSW_TEST_ADMIN_DISABLED=1,$(PYTEST) $(CONFORMANCE) tests/admin tests/cli)

# Old PHP (XREA defaults to 7.0): index.php and admin.php must answer a
# clear php_version error instead of a parse error.
test-old-php:
	@for v in 5.6 7.0 7.3; do \
	  docker rm -f kssw-oldphp >/dev/null 2>&1; \
	  docker run -d --rm --name kssw-oldphp -p 127.0.0.1:8777:8000 -v "$(CURDIR)/php/api:/app:ro" php:$$v-cli php -S 0.0.0.0:8000 -t /app /app/index.php >/dev/null || exit 1; \
	  sleep 2; \
	  for u in v1/meta admin.php; do \
	    body=$$(curl -sS http://127.0.0.1:8777/$$u); \
	    case "$$body" in *'"code":"php_version"'*) echo "php $$v $$u: php_version OK";; *) echo "php $$v $$u: unexpected: $$body"; docker rm -f kssw-oldphp >/dev/null; exit 1;; esac; \
	  done; \
	  docker rm -f kssw-oldphp >/dev/null; \
	done

# The test client acts as a trusted TLS-terminating proxy (XREA shared SSL).
test-trusted-proxy:
	$(call run_cli,php:7.4-cli,8778,php74-proxy,-e KSSW_TEST_TRUSTED_PROXY=1,ADMIN_TOKEN=$(TEST_ADMIN_TOKEN) ADMIN_EXPECT_PROXY=1 $(PYTEST) tests/admin/test_trusted_proxy.py)

# trusted_proxies set: X-Forwarded-Proto / X-Forwarded-Ssl from a client
# that is not a trusted proxy are ignored, from a trusted proxy honoured.
test-forwarded-proto:
	$(call run_cli,php:7.4-cli,8779,php74-fwd-untrusted,-e KSSW_TEST_FORWARDED=untrusted,ADMIN_TOKEN=$(TEST_ADMIN_TOKEN) ADMIN_EXPECT_FORWARDED=untrusted $(PYTEST) tests/admin/test_forwarded_proto.py)
	$(call run_cli,php:7.4-cli,8779,php74-fwd-trusted,-e KSSW_TEST_FORWARDED=trusted,ADMIN_TOKEN=$(TEST_ADMIN_TOKEN) ADMIN_EXPECT_FORWARDED=trusted $(PYTEST) tests/admin/test_forwarded_proto.py)

test-admin-https:
	$(call run_cli,php:7.4-cli,8776,php74-https,-e KSSW_TEST_ADMIN_HTTPS=1,ADMIN_TOKEN=$(TEST_ADMIN_TOKEN) ADMIN_EXPECT_HTTPS=1 $(PYTEST) tests/admin/test_https.py tests/admin/test_errors.py)

# Apache + mod_php + .htaccess, the shape of a shared host. The app is
# copied with the test config in place as config.php (mod_php does not see
# -e env).
test-apache: spec
	rm -rf $(SCRATCH)/apache && mkdir -p $(SCRATCH)/apache
	cp -R php/api $(SCRATCH)/apache/api && cp $(TEST_CONFIG) $(SCRATCH)/apache/api/config.php
	@# The document root's .htaccess of php/well-known/README.md (way B), so
	@# the conformance suite's discovery test runs against /.well-known/kssw.
	printf 'RewriteEngine On\nRewriteRule ^\\.well-known/kssw$$ /api/.well-known/kssw [L]\n' > $(SCRATCH)/apache/.htaccess
	scripts/with-server.sh http://127.0.0.1:8780/api \
		"exec docker run --rm --name kssw-apache -p 127.0.0.1:8780:80 -v $(CURDIR)/$(SCRATCH)/apache:/var/www/html:ro php:7.4-apache \
			sh -c 'a2enmod rewrite >/dev/null && sed -i \"s/AllowOverride None/AllowOverride All/\" /etc/apache2/apache2.conf && exec apache2-foreground'" \
		'docker rm -f kssw-apache' \
		sh -c '$(SUITES) && scripts/check-denied.sh http://127.0.0.1:8780/api'

conformance: spec
	@test -n "$(BASE_URL)" || { echo "set BASE_URL=http://..." >&2; exit 2; }
	BASE_URL=$(BASE_URL) $(PYTEST) $(CONFORMANCE) $(PYTEST_ARGS)

# config.php and data/ live only on the server: never overwritten or deleted.
RSYNC_EXCLUDES := --exclude config.php --exclude 'data/'

deploy-dry: check-schema
	rsync -avzn --delete $(RSYNC_EXCLUDES) php/api/ $(DEPLOY_HOST):$(DEPLOY_PATH)/

deploy: check-schema
	rsync -avz --delete $(RSYNC_EXCLUDES) php/api/ $(DEPLOY_HOST):$(DEPLOY_PATH)/

clean:
	rm -rf $(SCRATCH)/php-test $(SCRATCH)/apache $(SCRATCH)/spec
