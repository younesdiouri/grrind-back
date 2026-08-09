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
| 1 | **Identity** — inscription, login JWT, refresh tokens, profil + timezone | ✅ |
| 2 | **Training** — déclarer / démarrer / compléter une session, garde-fous | ⬜ |
| 3 | **Progression** — ledger XP, courbe de niveaux, titres | ⬜ |
| 4 | **RewardSummary** — premier jouable de bout en bout | ⬜ |
| 5 | Streak | ⬜ |
| 6 | Loot | ⬜ |
| 7 | Arbres de compétences | ⬜ |
| 8 | Classements | ⬜ |
| 9 | Durcissement (rate limiting, observabilité, OpenAPI publié) | ⬜ |

Strava arrive après, comme simple adapter d'`ActivitySource` — jamais avant.

## En cours

Rien. Prochain lot à démarrer : **Lot 2 — Training**.

## Surface d'API

| Route | Auth | Réponse |
|---|---|---|
| `GET /health` | — | état de la base |
| `POST /api/auth/register` | — | 201, compte + jetons |
| `POST /api/auth/login` | — | 200, compte + jetons |
| `POST /api/auth/refresh` | refresh token | 200, compte + jetons tournés |
| `POST /api/auth/logout` | refresh token | 204 |
| `GET /api/me` | Bearer | profil |
| `PATCH /api/me` | Bearer | profil mis à jour |

Toute erreur sort en `application/problem+json`, avec un `type` stable en
kebab-case sous `https://grrind.app/problems/` — c'est là-dessus que le client iOS
branche ses messages, jamais sur le `detail`.

## Décisions prises

**Lot 0 — un dépôt par client.** Le back est à la racine de `grrind-back` ; le client SwiftUI
aura son propre dépôt. Pas de monorepo.

**Lot 0 — la CI rejoue les mêmes cibles `make` dans la même image que le poste de dev.**
Aucune divergence possible entre « ça passe chez moi » et la CI.

**Lot 0 — pas d'`auto_mapping` Doctrine.** Chaque module déclare son mapping dans
`config/packages/doctrine.yaml` au moment de son lot. Un module oublié se voit tout de suite.

**Lot 1 — l'entité `User` n'implémente pas `UserInterface`.** `SecurityUser` sert d'adaptateur.
Le domaine n'a ainsi à connaître ni les rôles Symfony, ni `eraseCredentials()`, ni la notion
d'identifiant de firewall.

**Lot 1 — l'identifiant de sécurité est l'UUID, pas l'e-mail.** Changer d'adresse n'invalide
aucun jeton, et l'adresse ne se promène pas dans le payload des JWT (claim `sub`).

**Lot 1 — refresh tokens rotatifs par famille.** Une famille = un appareil. Seul le SHA-256 est
stocké. Le rejeu d'un jeton consommé révoque la famille entière : impossible de distinguer le
voleur du vrai client, donc on coupe.

**Lot 1 — pas de bus de commandes.** Les contrôleurs appellent les handlers directement.
Messenger arrivera pour l'asynchrone (classements, notifications), pas pour ajouter une
indirection synchrone.

**Lot 1 — pas d'`UnitOfWork` partagé.** Les repositories flushent eux-mêmes tant qu'une seule
agrégat est touché. La transaction explicite arrivera au Lot 3, avec la complétion de session
qui en a réellement besoin (verrou pessimiste, écritures multiples).

## Pièges déjà rencontrés

**`.dockerignore` et le contexte de build.** `docker/` avait été exclu alors que le Dockerfile y
prend les `php.ini` : le build cassait. Un `.dockerignore` écrit après un build réussi ne prouve
rien — refaire `docker compose build` **et** `docker build --target prod .` après l'avoir touché.

**`APP_ENV` du conteneur gagne sur `phpunit.dist.xml`.** Le `force="true"` du fichier XML ne
suffit pas : c'est pour ça que la cible `test` du Makefile passe `-e APP_ENV=test`.

**PHPStan a besoin d'un cache dev chaud** pour l'extension Symfony — la cible `phpstan` fait le
`cache:warmup` elle-même, sinon la CI échoue sur un conteneur neuf.

**Les recettes Flex écrasent les fichiers de config existants.** `symfony/orm-pack` avait injecté
un second service `database` dans `compose.yaml` ; `deptrac/deptrac` a réécrit `deptrac.yaml` avec
ses couches par défaut. Après tout `composer require`, lire `git status` avant de committer.

**Lexik dispatche ses événements sous un nom, pas sous leur classe.** Un
`#[AsEventListener(event: JWTNotFoundEvent::class)]` ne se déclenche jamais, sans erreur ni
avertissement. Utiliser les constantes de `Lexik\...\Events`.

**Les échecs d'authentification ne passent pas par `kernel.exception`.** L'authenticator fabrique
sa propre réponse : sans branchement sur les événements Lexik, l'API renvoie du JSON maison au
milieu de problem+json partout ailleurs.

**Les clés JWT ne sont pas versionnées.** Après un clone, `make jwt-keys` avant tout le reste —
`make install` s'en charge, la CI a son étape dédiée. Elles sont aussi exclues du contexte de
build : en prod, elles viennent d'un secret monté, jamais d'une image.
