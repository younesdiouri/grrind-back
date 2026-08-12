## GRRIND backend — point d'entrée unique.
## Aucun PHP/Composer sur l'hôte : tout passe par Docker.
## Si une commande te manque, ajoute-la ici — ne contourne pas.

DC      := docker compose
# Sans dépendance : ni base ni serveur nécessaires (composer, analyse statique).
RUN     := $(DC) run --rm --no-deps php
# Avec dépendances : la base est démarrée automatiquement (console, migrations).
RUN_DB  := $(DC) run --rm php
# Tests : APP_ENV forcé côté conteneur, sinon le APP_ENV=dev de compose.yaml gagne.
RUN_TEST := $(DC) run --rm -e APP_ENV=test php

.DEFAULT_GOAL := help
.PHONY: help build up down restart logs sh worker failures composer console cc secrets jwt-keys migration migrate migrate-prod db-reset openapi test qa phpstan cs cs-fix deptrac install

help: ## Liste les commandes disponibles
	@grep -hE '^[a-zA-Z_-]+:.*?## .*$$' $(MAKEFILE_LIST) | awk 'BEGIN{FS=":.*?## "}{printf "  \033[36m%-14s\033[0m %s\n", $$1, $$2}'

## —— Stack ————————————————————————————————————————————————————————————————
build: ## Construit les images
	$(DC) build --pull

up: ## Démarre la stack en arrière-plan
	$(DC) up -d --wait

down: ## Arrête la stack
	$(DC) down --remove-orphans

restart: down up ## Redémarre la stack

logs: ## Suit les logs (s=service)
	$(DC) logs -f $(s)

sh: ## Ouvre un shell dans le conteneur php
	$(DC) exec php sh

# Le service `worker` de compose consomme déjà l'outbox en fond ; cette cible sert à
# regarder un message passer, ou à en rejouer un après l'avoir corrigé.
worker: ## Consomme l'outbox en avant-plan (Ctrl-C pour arrêter)
	$(RUN_DB) bin/console messenger:consume outbox -vv

failures: ## Liste les messages en échec (c="show 42" pour le détail, c="retry" pour rejouer)
	$(RUN_DB) bin/console messenger:failed:$(or $(c),show)

install: build ## Installation initiale : images, dépendances, secrets, clés, base
	$(RUN) composer install
	$(MAKE) secrets
	$(MAKE) jwt-keys
	$(MAKE) up
	$(MAKE) db-reset

## —— PHP ——————————————————————————————————————————————————————————————————
composer: ## Composer (c="require foo/bar")
	$(RUN) composer $(c)

console: ## Console Symfony (c="cache:clear")
	$(RUN_DB) bin/console $(c)

cc: ## Vide le cache
	$(RUN) bin/console cache:clear

# Deux fichiers et pas un : Symfony ignore délibérément .env.local dans l'environnement
# `test`, pour que la suite donne le même résultat chez tout le monde. Sans son pendant
# .env.test.local, les tests tourneraient avec une passphrase vide et des clés chiffrées.
# Les deux sont couverts par /.env.local et /.env.*.local dans .gitignore.
secrets: ## Génère .env.local et .env.test.local (APP_SECRET, JWT_PASSPHRASE) — un jeu par poste
	@if [ -f .env.local ]; then \
		echo "  .env.local existe déjà — supprime-le (et .env.test.local) pour tout regénérer."; \
	else \
		app=$$(openssl rand -hex 16); jwt=$$(openssl rand -hex 32); \
		for f in .env.local .env.test.local; do \
			printf '# Généré par `make secrets`. Jamais versionné, jamais partagé.\n# Valeurs de développement : la prod fournit les siennes par l'"'"'environnement.\nAPP_SECRET=%s\nJWT_PASSPHRASE=%s\n' "$$app" "$$jwt" > $$f; \
		done; \
		echo "  .env.local et .env.test.local écrits. Enchaîne avec 'make jwt-keys' : la passphrase a changé."; \
	fi

jwt-keys: ## (Re)génère la paire de clés JWT — jamais versionnée, à refaire à chaque poste
	$(RUN) bin/console lexik:jwt:generate-keypair --overwrite --no-interaction

## —— Base de données ——————————————————————————————————————————————————————
migration: ## Génère une migration (à relire à la main avant de l'appliquer)
	$(RUN_DB) bin/console doctrine:migrations:diff

migrate: ## Applique les migrations
	$(RUN_DB) bin/console doctrine:migrations:migrate --no-interaction

db-reset: ## Recrée la base et rejoue toutes les migrations
	$(RUN_DB) bin/console doctrine:database:drop --if-exists --force
	$(RUN_DB) bin/console doctrine:database:create
	$(RUN_DB) bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration

# La migration du déploiement. Elle ne tourne pas dans l'image de dev mais dans
# l'image de prod — celle qui partira sur ECS — pour que ce qui migre la base soit
# exactement le code qui va la lire. Voir README, « Déploiement ».
#
# `--allow-no-migration` : un déploiement qui n'apporte aucune migration est le cas
# courant, et il ne doit pas échouer pour ça.
#
# `--query-time --no-debug` : un déploiement qui traîne doit dire *où*, et le
# profiler n'a rien à faire dans une tâche de migration.
#
# La commande sort en code non nul si une migration échoue — c'est ce qui doit
# interrompre le déploiement avant que les nouvelles tâches prennent du trafic.
migrate-prod: ## Applique les migrations depuis l'image de prod (étape de déploiement)
	$(DC) run --rm --build php-prod \
		bin/console doctrine:migrations:migrate \
			--no-interaction --allow-no-migration --query-time --no-debug

## —— Contrat d'API —————————————————————————————————————————————————————————
# Le contrat client est un **fichier versionné**, pas une route de documentation : le
# dépôt front en génère son client TypeScript, et un contrat qu'on ne peut pas differ est
# un contrat qui dérive. La CI rejoue cette cible et refuse un openapi.yaml pas à jour.
#
# Le bundle n'est chargé qu'en dev, d'où `$(RUN)` : l'image de prod est bâtie `--no-dev` et
# n'expose aucune documentation.
openapi: ## Régénère openapi.yaml depuis les routes et les attributs
	$(RUN) sh -c "bin/console nelmio:apidoc:dump --format=yaml" > openapi.yaml

## —— Qualité ——————————————————————————————————————————————————————————————
test: ## Suite de tests (base de test créée/migrée au passage)
	$(RUN_TEST) sh -c "bin/console doctrine:database:create --if-not-exists \
		&& bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration \
		&& vendor/bin/phpunit $(c)"

qa: phpstan cs deptrac ## Toutes les barrières qualité

phpstan: ## Analyse statique (le cache dev alimente l'extension Symfony)
	$(RUN) sh -c "bin/console cache:warmup --env=dev && vendor/bin/phpstan analyse --memory-limit=1G"

cs: ## Vérifie le style (sans modifier)
	$(RUN) vendor/bin/php-cs-fixer check --diff

cs-fix: ## Corrige le style
	$(RUN) vendor/bin/php-cs-fixer fix

deptrac: ## Vérifie les frontières entre modules
	$(RUN) vendor/bin/deptrac analyse
