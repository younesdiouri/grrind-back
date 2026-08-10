# grrind-back

API de **GRRIND** — une app qui transforme le sport en RPG : XP, niveaux, titres, arbres de
compétences, loot, streak, ligues. Le client SwiftUI vit dans un dépôt séparé.

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

Les invariants de conception (le serveur possède l'horloge, l'XP est un ledger append-only, un seul
vocabulaire de modificateurs, RNG serveur auditable) sont détaillés dans [CLAUDE.md](CLAUDE.md).
L'avancement lot par lot est dans [PROGRESS.md](PROGRESS.md).
