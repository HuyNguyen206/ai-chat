.PHONY: *

COMPOSE := docker compose -f ../../server/docker-compose.yml

infra-shell-php:
	$(COMPOSE) exec -u=dev -it ai-app bash -l

infra-shell-root-php:
	$(COMPOSE) exec -it ai-app bash -l

start:
	$(COMPOSE) up -d
	@$(MAKE) --no-print-directory vite

stop:
	$(COMPOSE) stop ai-app

# First run after cloning: install deps, create the app key and the sqlite
# database inside the container, so everything is owned by the dev user.
install:
	$(COMPOSE) up -d
	$(COMPOSE) exec -T -u=dev ai-app sh -lc 'composer install'
	$(COMPOSE) exec -T -u=dev ai-app sh -lc '[ -f .env ] || cp .env.example .env'
	$(COMPOSE) exec -T -u=dev ai-app sh -lc 'php artisan key:generate'
	$(COMPOSE) exec -T -u=dev ai-app sh -lc 'touch database/database.sqlite && php artisan migrate --force'
	$(COMPOSE) exec -T -u=dev ai-app sh -lc 'npm install'

# Vite must run inside the container, not on the host: the browser tests reach
# the dev server at the URL in public/hot, and a host-bound vite is unreachable
# from there. Skipped when it is already up, because a second instance dies on
# strictPort and deletes public/hot on its way out, breaking assets for the
# instance that is still running. A live port with no public/hot means exactly
# that happened, so treat it as broken and restart rather than report it healthy.
vite:
	@if $(COMPOSE) exec -T -u=dev ai-app \
		curl -sf -o /dev/null --max-time 2 http://localhost:5175/@vite/client 2>/dev/null \
		&& [ -f public/hot ]; then \
		echo "vite: already running"; \
	else \
		$(MAKE) --no-print-directory vite-stop >/dev/null; \
		$(COMPOSE) exec -d -u=dev ai-app \
			sh -lc 'npm run dev > storage/logs/vite.log 2>&1'; \
		echo "vite: started (logs: storage/logs/vite.log)"; \
	fi

# The php image has no pkill/pgrep, so match on /proc instead. The `$$$$` guard
# keeps the loop from killing the shell running it.
vite-stop:
	@$(COMPOSE) exec -T -u=dev ai-app sh -lc 'for d in /proc/[0-9]*; do \
		pid=$${d##*/}; \
		[ "$$pid" = "$$$$" ] && continue; \
		tr "\\0" " " < $$d/cmdline 2>/dev/null | grep -q "vite" && kill $$pid 2>/dev/null; \
	done; true'
	@echo "vite: stopped"

test:
	$(COMPOSE) exec -T -u=dev ai-app sh -lc 'php artisan test'

logs:
	$(COMPOSE) logs -f ai-app
