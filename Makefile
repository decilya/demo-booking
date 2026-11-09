# Makefile — стандартные команды для проекта demo-booking.
#
# Все цели выполняются через Docker Compose; локальный PHP не требуется.
# Использование:
#   make install   — поднять контейнеры, поставить зависимости, накатить БД
#                    (ПЕРЕСОЗДАЁТ БД — только для чистой установки)
#   make test      — прогнать тесты
#   make lint      — проверить стиль (Pint)
#   make fresh     — сбросить БД и засеять заново

.PHONY: install test lint lint-fix fresh shell

install:
	docker compose up -d --wait
	docker compose exec app composer install --no-interaction
	docker compose exec app php artisan migrate:fresh --seed

test:
	docker compose exec app php artisan test

lint:
	docker compose exec app ./vendor/bin/pint --test

lint-fix:
	docker compose exec app ./vendor/bin/pint

fresh:
	docker compose exec app php artisan migrate:fresh --seed

shell:
	docker compose exec app bash
