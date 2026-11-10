WEB_USER := daemon
SUITECRM_ROOT := /bitnami/suitecrm
PHP_BIN := /opt/bitnami/php/bin/php

.PHONY: up down wait smoke seed-data install-lang verify logs reset all

up:
	docker compose up -d

down:
	docker compose down

wait:
	bash ./scripts/wait_for_suitecrm.sh

smoke:
	docker compose exec -T -u $(WEB_USER) -e SUITECRM_ROOT=$(SUITECRM_ROOT) suitecrm $(PHP_BIN) /seed/smoke_bootstrap.php

seed-data:
	docker compose exec -T -u $(WEB_USER) -e SUITECRM_ROOT=$(SUITECRM_ROOT) suitecrm $(PHP_BIN) /seed/seed_suitecrm_b2b.php

install-lang:
	docker compose exec -T -u $(WEB_USER) -e SUITECRM_ROOT=$(SUITECRM_ROOT) suitecrm $(PHP_BIN) /seed/install_id_lang.php

verify:
	docker compose exec -T -u $(WEB_USER) -e SUITECRM_ROOT=$(SUITECRM_ROOT) suitecrm $(PHP_BIN) /seed/verify_seed.php

logs:
	docker compose logs -f suitecrm

reset:
	docker compose down -v
	$(MAKE) up
	$(MAKE) wait
	$(MAKE) smoke
	$(MAKE) seed-data
	$(MAKE) install-lang
	$(MAKE) verify

all: up wait smoke seed-data install-lang verify
