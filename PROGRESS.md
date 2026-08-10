# Journal de progression

Ce fichier existe pour qu'une session interrompue puisse reprendre sans rien redemander.
Il dit **où on en est**, **ce qui vient ensuite**, et **pourquoi certains choix ont été faits**.
Les invariants de conception, eux, vivent dans [CLAUDE.md](CLAUDE.md) — pas de doublon ici.

À tenir à jour au fil des commits : une ligne par lot terminé, et la section « En cours » vidée
dès qu'un lot est bouclé.

## Reprendre en une minute

```bash
make secrets                   # après un clone : .env.local et .env.test.local
make jwt-keys                  # les clés ne sont pas versionnées
make up                        # stack sur http://localhost:8080
curl localhost:8080/health     # {"status":"ok","checks":{"database":"up"}}
make test && make qa           # tout doit être vert avant d'écrire une ligne
git log --oneline              # les commits racontent l'ordre d'implémentation
```

`make install` enchaîne tout ça. La règle qui prime sur les autres est en tête de
[CLAUDE.md](CLAUDE.md) : **on utilise ce que Symfony fournit**, et en cas de doute on demande.

## Feuille de route

| Lot | Contenu | État |
|---|---|---|
| 0 | Socle dockerisé, CI, barrières qualité, `GET /health` | ✅ |
| 1 | **Identity** — inscription, login JWT, refresh tokens, profil + timezone | ✅ |
| 1bis | Secrets purgés, Symfony 8.1, auth rendue au framework, social sign-in | ✅ |
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

Deux dettes ouvertes, petites et connues :

- **Timing du login.** L'ancien `LogInHandler` hachait à vide sur adresse inconnue pour égaliser
  les temps de réponse. `json_login` ne le fait pas : le corps et le statut restent
  indistinguables, la durée non. À traiter au Lot 9 (durcissement), probablement avec le rate
  limiter, qui rend la mesure statistique bien plus coûteuse.
- **Aucun appel réel à Google ni Apple n'a été fait.** Le flux est testé de bout en bout contre
  un stub ; il reste à poser de vrais `client_id` et la clé `.p8`, et à valider une fois avec
  l'app iOS.

## Surface d'API

| Route | Auth | Réponse |
|---|---|---|
| `GET /health` | — | état de la base |
| `POST /api/auth/register` | — | 201, compte + jetons |
| `POST /api/auth/login` | — | 200, compte + jetons |
| `POST /api/auth/refresh` | refresh token | 200, compte + jetons tournés |
| `POST /api/auth/logout` | refresh token | 204 |
| `POST /api/auth/social/{google\|apple}` | code d'autorisation | 200, compte + jetons |
| `GET /api/me` | Bearer | profil |
| `PATCH /api/me` | Bearer | profil mis à jour |

`register`, `login` et `social` rendent tous le même `AuthResource` : le client iOS n'a qu'un
seul chemin de traitement.

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

**Lot 1 — l'entité `User` n'implémente pas `UserInterface`.** ~~`SecurityUser` sert
d'adaptateur.~~ **Annulé au Lot 1bis** — voir ci-dessous.

**Lot 1 — l'identifiant de sécurité est l'UUID, pas l'e-mail.** Changer d'adresse n'invalide
aucun jeton, et l'adresse ne se promène pas dans le payload des JWT (claim `sub`). Tenu au
Lot 1bis, par un autre moyen : `UserLoaderInterface` sur le dépôt.

**Lot 1 — refresh tokens rotatifs par famille.** Une famille = un appareil. Seul le SHA-256 est
stocké. Le rejeu d'un jeton consommé révoque la famille entière : impossible de distinguer le
voleur du vrai client, donc on coupe.

**Lot 1 — pas de bus de commandes.** Les contrôleurs appellent les handlers directement.
Messenger arrivera pour l'asynchrone (classements, notifications), pas pour ajouter une
indirection synchrone.

**Lot 1 — pas d'`UnitOfWork` partagé.** Les repositories flushent eux-mêmes tant qu'une seule
agrégat est touché. La transaction explicite arrivera au Lot 3, avec la complétion de session
qui en a réellement besoin (verrou pessimiste, écritures multiples).

**Lot 1bis — priorité à l'écosystème Symfony, et en cas de doute on demande.** C'est devenu la
règle n°0 de CLAUDE.md. Elle est née d'un constat : la moitié du Lot 1 réimplémentait le
composant Security. Le skill `symfony-docs` matérialise la règle — la doc se consulte avant
d'écrire, pas après.

**Lot 1bis — l'entité `User` implémente `UserInterface`.** La décision inverse du Lot 1 est
annulée. Elle coûtait un adaptateur, un user provider et un port de hachage pour préserver une
pureté du domaine que le produit ne monnayait pas. Le domaine connaît maintenant les rôles
Symfony, et c'est très bien. Colonne `roles` + enum `Role`.

**Lot 1bis — Symfony 8.1 plutôt que la 7.4 LTS.** Choix assumé de suivre la stable courante :
on paie une montée par an et on reste au contact des nouveautés, au lieu d'accumuler trois ans
de dette d'un coup. La montée elle-même n'a demandé aucun changement de code.

**Lot 1bis — les refresh tokens restent faits main.** `gesdinet/jwt-refresh-token-bundle` est
le bundle de référence, et il a été écarté en connaissance de cause : il stocke le jeton en
clair, ignore la notion de famille et ne détecte pas le rejeu. C'est le seul endroit du module
où le fait-main apporte ce qu'aucune bibliothèque ne donne.

**Lot 1bis — le social sign-in n'utilise pas KnpUOAuth2ClientBundle.** Installé puis retiré :
tout ce qu'il apporte (routes de redirection, état en session) suppose un navigateur, alors que
le client est une app native et l'API stateless. Il exige en prime un `redirect_route` qui
n'aurait désigné personne. Les deux providers league sont câblés dans `services.yaml`.

**Lot 1bis — une adresse non vérifiée ne relie jamais un compte social à un compte existant.**
Sinon il suffirait de créer chez Google un compte portant l'adresse de la victime pour prendre
le sien. On renvoie 409 et on laisse le vrai propriétaire se connecter par mot de passe.

**Lot 1bis — `.env` reste versionné, mais vide de tout secret.** C'est le fichier de défauts
documenté par Flex ; le sortir du dépôt aurait cassé une convention pour rien. Ce qui change,
c'est que les valeurs sensibles n'y sont plus : `make secrets` les génère hors du suivi.

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
build : en prod, elles viennent d'un secret monté, jamais d'une image. Idem pour la clé `.p8`
de Sign in with Apple.

**Symfony ne charge pas `.env.local` en environnement `test`.** C'est délibéré de leur part —
la suite doit donner le même résultat chez tout le monde. Conséquence pratique : un secret placé
dans le seul `.env.local` laisse les tests avec une valeur vide, et les 21 échecs qui suivent
ressemblent à tout sauf à un problème de configuration. `make secrets` écrit donc aussi
`.env.test.local`, que le hall de test charge bien.

**`UserLoaderInterface` n'est pas dans le composant Security.** Il vit dans le pont Doctrine :
`Symfony\Bridge\Doctrine\Security\User\UserLoaderInterface`. L'importer depuis
`Symfony\Component\Security\Core\User` compile chez PHPStan et casse à la construction du
conteneur.

**`#[AutowireLocator]` attend des types PHP, pas des identifiants de service.** Pour pointer un
service nommé, il faut envelopper dans `new Autowire(service: 'mon.service')`. Sans ça :
« "mon.service" is not a PHP type for key … ».

**Le routeur passe avant le firewall.** Priorité 32 contre 8 : une route interceptée par
`json_login` doit malgré tout exister, sinon la requête finit en 404 avant que l'authenticator
ne la voie. D'où un `LoginController` qui ne fait que lever — il n'est jamais atteint.

**Le diff Doctrine ne sait pas renommer une colonne.** Il propose `DROP` puis `ADD`, ce qui aurait
effacé tous les hachages de mots de passe, et un `ADD … NOT NULL` sans défaut qui échoue sur une
table peuplée. Relire chaque migration avant de l'appliquer n'est pas une précaution de style.

**Le `KernelBrowser` redémarre le noyau entre deux requêtes.** Un service bouchon programmé
depuis le test est donc remis à zéro avant d'être consommé. Pour le social sign-in, le stub
dérive le profil du code d'autorisation lui-même : sans état, rien à perdre au redémarrage.
