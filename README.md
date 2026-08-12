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
Un module ne connaît que `Shared` — Deptrac le vérifie en CI.

👉 **[ARCHITECTURE.md](ARCHITECTURE.md) est la vue d'ensemble en schémas** : la carte des modules,
la vie d'une séance, la transaction de complétion, le modèle de données, l'authentification et la
chaîne du config-as-code. C'est le fichier à ouvrir en premier.

Les invariants de conception (le serveur possède l'horloge, l'XP est un ledger append-only, un seul
vocabulaire de modificateurs, RNG serveur auditable) sont détaillés dans [CLAUDE.md](CLAUDE.md).

## Contrat d'API

**[`openapi.yaml`](openapi.yaml) est la source de vérité du contrat client**, et il est versionné :
le dépôt front en génère son client typé plutôt que d'écrire des DTO à la main.

```bash
make openapi       # régénère le fichier depuis les routes et les attributs
```

Il n'y a **pas de route de documentation** — le contrat est le fichier, pas un endpoint de plus sous
`^/api`, et le bundle qui le produit n'est chargé qu'en dev. Deux garde-fous le tiennent : la CI
régénère et refuse un fichier en retard, et `OpenApiContractTest` refuse une route non décrite.

## Déploiement

Rien n'est déployé à ce jour ; la cible visée est ECS Fargate — un service pour l'API en mode
worker FrankenPHP, un service pour le consommateur Messenger, RDS PostgreSQL 17.

**Les migrations sont une étape de déploiement à part entière, jouée avant que les nouvelles
tâches prennent du trafic.** Elle tourne dans l'image de prod, pour que ce qui migre la base soit
exactement le code qui va la lire :

```bash
DATABASE_URL="postgresql://…" make migrate-prod
```

La commande sort en code non nul si une migration échoue — c'est ce qui doit interrompre le
déploiement. Elle ne dépend que de `DATABASE_URL` : ni clés JWT, ni secrets OAuth. En local elle
vise la base de la stack ; sur ECS, c'est une tâche one-off dans la même définition de tâche que
l'API, lancée avant la bascule.

Deux points à trancher avant le premier déploiement, ticketés sur le jalon *Lot 9 — Durcissement* :
**RDS Proxy est incompatible avec le `LISTEN/NOTIFY`** dont dépend l'outbox ([#56]), et les clés
JWT sont aujourd'hui référencées par *chemin de fichier* alors qu'un gestionnaire de secrets
injecte une valeur ([#54]).

[#54]: https://github.com/younesdiouri/grrind-back/issues/54
[#56]: https://github.com/younesdiouri/grrind-back/issues/56

## Avancement

Le suivi vit sur le [tableau GitHub](https://github.com/users/younesdiouri/projects/1) : un ticket
par feature, un jalon par lot, un label par module.
