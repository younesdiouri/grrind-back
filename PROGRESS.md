# Journal de progression

Ce fichier existe pour qu'une session interrompue puisse reprendre sans rien redemander.
Il dit **où on en est**, **ce qui vient ensuite**, et **pourquoi certains choix ont été faits**.
Les invariants de conception, eux, vivent dans [CLAUDE.md](CLAUDE.md) — pas de doublon ici.

À tenir à jour au fil des commits : une ligne par lot terminé, et la section « En cours » vidée
dès qu'un lot est bouclé.

## Reprendre en une minute

```bash
make up                        # stack sur http://localhost:8080
curl localhost:8080/health     # {"status":"ok","checks":{"database":"up"}}
make test && make qa           # tout doit être vert avant d'écrire une ligne
git log --oneline              # les commits racontent l'ordre d'implémentation
```

## Feuille de route

| Lot | Contenu | État |
|---|---|---|
| 0 | Socle dockerisé, CI, barrières qualité, `GET /health` | ✅ |
| 1 | **Identity** — inscription, login JWT, refresh tokens, profil + timezone | 🚧 |
| 2 | **Training** — déclarer / démarrer / compléter une session, garde-fous | ⬜ |
| 3 | **Progression** — ledger XP, courbe de niveaux, titres | ⬜ |
| 4 | **RewardSummary** — premier jouable de bout en bout | ⬜ |
| 5 | Streak | ⬜ |
| 6 | Loot | ⬜ |
| 7 | Arbres de compétences | ⬜ |
| 8 | Classements | ⬜ |
| 9 | Durcissement (rate limiting, observabilité, OpenAPI publié) | ⬜ |

Strava arrive après, comme simple adapter d'`ActivitySource` — jamais avant.

## En cours — Lot 1 : Identity

Découpage en commits, dans l'ordre :

- [ ] dépendances auth + configuration security
- [ ] erreurs RFC 7807 (dans `Shared`, tous les lots suivants s'appuient dessus)
- [ ] entité `User` + migration
- [ ] `POST /api/auth/register`
- [ ] `POST /api/auth/login`
- [ ] refresh tokens : `POST /api/auth/refresh`, `POST /api/auth/logout`
- [ ] `GET` / `PATCH` `/api/me` (profil, timezone)

## Décisions prises

**Lot 0 — un dépôt par client.** Le back est à la racine de `grrind-back` ; le client SwiftUI
aura son propre dépôt. Pas de monorepo.

**Lot 0 — la CI rejoue les mêmes cibles `make` dans la même image que le poste de dev.**
Aucune divergence possible entre « ça passe chez moi » et la CI.

**Lot 0 — pas d'`auto_mapping` Doctrine.** Chaque module déclare son mapping dans
`config/packages/doctrine.yaml` au moment de son lot. Un module oublié se voit tout de suite.

## Pièges déjà rencontrés

**`.dockerignore` et le contexte de build.** `docker/` avait été exclu alors que le Dockerfile y
prend les `php.ini` : le build cassait. Un `.dockerignore` écrit après un build réussi ne prouve
rien — refaire `docker compose build` **et** `docker build --target prod .` après l'avoir touché.

**`APP_ENV` du conteneur gagne sur `phpunit.dist.xml`.** Le `force="true"` du fichier XML ne
suffit pas : c'est pour ça que la cible `test` du Makefile passe `-e APP_ENV=test`.

**PHPStan a besoin d'un cache dev chaud** pour l'extension Symfony — la cible `phpstan` fait le
`cache:warmup` elle-même, sinon la CI échoue sur un conteneur neuf.
