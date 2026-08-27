# grrind-back

API de **GRRIND** — une app qui transforme le sport en RPG : XP, niveaux, titres, arbres de
compétences, loot, streak, ligues. Le client React Native vit dans un dépôt séparé.

## Démarrer

Rien à installer à part Docker. Aucun PHP, Composer ou Symfony CLI n'est requis sur l'hôte.

```bash
make install       # images, dépendances, secrets locaux, clés JWT, base
make up            # http://localhost:8080
curl localhost:8080/health
```

Aucun secret n'est versionné. `make install` appelle `make secrets`, qui génère un
`.env.local` et un `.env.test.local` propres à ton poste ; les clés JWT viennent de
`make jwt-keys`. En production, tout cela arrive par l'environnement ou un secret monté.

```bash
make               # liste toutes les cibles
make test          # phpunit
make qa            # phpstan + cs-fixer + deptrac
make down
```

## Stack

FrankenPHP (PHP 8.4) · Symfony 8.1 · PostgreSQL 17 · Doctrine ORM 3 · PHPStan niveau max ·
Deptrac pour les frontières entre modules.

La règle qui prime sur toutes les autres : **on utilise ce que Symfony fournit.** Un mécanisme
qui existe dans le framework ou dans un bundle de référence ne se réécrit pas — voir la section
correspondante de [CLAUDE.md](CLAUDE.md).

## Architecture

Monolithe modulaire : `Shared`, `Identity`, `Training`, `Progression`, `Rewards`, `Engagement`.
Un module ne connaît que `Shared` — `make qa` le vérifie, et casse sur une flèche interdite.

👉 **[ARCHITECTURE.md](ARCHITECTURE.md) est la vue d'ensemble en schémas** : la carte des modules,
la vie d'un import, la transaction d'import, le modèle de données, l'authentification et la
chaîne du config-as-code. C'est le fichier à ouvrir en premier.

Les invariants de conception (le serveur arbitre l'horloge, l'XP est un ledger append-only, un seul
vocabulaire de modificateurs, RNG serveur auditable) sont détaillés dans [CLAUDE.md](CLAUDE.md).

## Contrat d'API

**[`openapi.yaml`](openapi.yaml) est la source de vérité du contrat client**, et il est versionné :
le dépôt front en génère son client typé plutôt que d'écrire des DTO à la main.

```bash
make openapi       # régénère le fichier depuis les routes et les attributs
```

Il n'y a **pas de route de documentation** — le contrat est le fichier, pas un endpoint de plus sous
`^/api`, et le bundle qui le produit n'est chargé qu'en dev. Deux garde-fous le tiennent :
`OpenApiContractTest` refuse une route non décrite, et `make openapi` avant chaque push refuse —
par le `git diff` qui suit — un fichier en retard sur le code.

## Déploiement

**Un déploiement alpha tourne sur Fly.io** (`grrind-back`, Postgres géré par Supabase) pour
tester avec de premiers utilisateurs pendant que le reste du jeu se construit. La cible visée
pour la suite reste ECS Fargate — un service pour l'API en mode worker FrankenPHP, un service
pour le consommateur Messenger, RDS PostgreSQL 17 — décidée au jalon *Lot 9 — Durcissement*.

**Les migrations sont une étape de déploiement à part entière, jouée avant que les nouvelles
tâches prennent du trafic.** Elle tourne dans l'image de prod, pour que ce qui migre la base soit
exactement le code qui va la lire :

```bash
DATABASE_URL="postgresql://…" make migrate-prod
```

La commande sort en code non nul si une migration échoue — c'est ce qui doit interrompre le
déploiement. Elle ne dépend que de `DATABASE_URL` : ni clés JWT, ni secrets OAuth. En local elle
vise la base de la stack ; sur ECS, ce sera une tâche one-off dans la même définition de tâche que
l'API, lancée avant la bascule. (Contre Supabase, la connexion directe est IPv6 uniquement : si
`make migrate-prod` échoue avec *Network is unreachable* depuis un poste sans sortie IPv6,
`flyctl ssh console -C "php bin/console doctrine:migrations:migrate …"` joue les migrations
depuis une machine qui, elle, en a une.)

Les clés JWT n'ont pas besoin d'un fichier monté : `lexik_jwt_authentication.secret_key` /
`public_key` acceptent le contenu PEM brut directement (cf. le README du bundle — « path to the
secret key OR raw secret key »), c'est ce que `JWT_SECRET_KEY` / `JWT_PUBLIC_KEY` portent en
secret Fly. Reste tranché au *Lot 9* : **RDS Proxy est incompatible avec le `LISTEN/NOTIFY`**
dont dépend l'outbox ([#56]).

[#56]: https://github.com/younesdiouri/grrind-back/issues/56

## Avancement

Le suivi vit sur le [tableau GitHub](https://github.com/users/younesdiouri/projects/1) : un ticket
par feature, un jalon par lot, un label par module.
