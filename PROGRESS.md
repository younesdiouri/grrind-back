# Journal de bord

**L'avancement ne se suit plus ici.** Il vit sur le tableau GitHub :
👉 **https://github.com/users/younesdiouri/projects/1**

Un ticket par feature, un jalon par lot, un label par module. C'est la seule source de vérité sur
« ce qui reste à faire » — ce fichier ne duplique plus la feuille de route, il ne garderait pas le
rythme.

Ce qui reste ici est ce qu'un tableau ne sait pas porter : **pourquoi** certains choix ont été
faits, et **sur quoi on s'est déjà cassé les dents**. Les invariants de conception, eux, vivent
dans [CLAUDE.md](CLAUDE.md).

## Reprendre en une minute

```bash
make secrets                   # après un clone : .env.local et .env.test.local
make jwt-keys                  # les clés ne sont pas versionnées
make up                        # stack sur http://localhost:8080
curl localhost:8080/health     # {"status":"ok","checks":{"database":"up"}}
make test && make qa           # tout doit être vert avant d'écrire une ligne
gh issue list --milestone "Lot 2 — Training"   # ce sur quoi on travaille
```

`make install` enchaîne les quatre premières. La règle qui prime sur les autres est en tête de
[CLAUDE.md](CLAUDE.md) : **on utilise ce que Symfony fournit**, et en cas de doute on demande.

## Comment on travaille

Une feature = un ticket = une branche = une PR qui ferme le ticket (`Closes #42`). On prend un
ticket à la fois, dans le jalon courant. Les lots restent l'ordre général — Training avant
Progression avant RewardSummary — mais l'unité de travail est le ticket, pas le lot.

Un choix structurant tranché en cours de route se note **ici**, dans « Décisions prises ». Un
piège qui a coûté une heure se note **ici**, dans « Pièges déjà rencontrés ». Le reste (état,
priorité, périmètre) reste sur le tableau.

| Jalon | Module dominant |
|---|---|
| Lot 0 — Socle · Lot 1 — Identity | ✅ terminés |
| Lot 2 — Training | `Training`, `Shared` (idempotence) |
| Lot 3 — Progression | `Progression` |
| Lot 4 — RewardSummary | premier jouable de bout en bout |
| Lot 5 — Streak · Lot 6 — Loot · Lot 7 — Arbres · Lot 8 — Classements | `Engagement`, `Rewards`, `Progression` |
| Lot 9 — Durcissement | transverse, plus les deux dettes du Lot 1bis |

Strava arrive après, comme simple adapter d'`ActivitySource` — jamais avant.

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
| `POST /api/training/sessions` | Bearer | 201, séance ouverte |
| `POST /api/training/sessions/{id}/complete` | Bearer + `Idempotency-Key` | 200, séance close |

`register`, `login` et `social` rendent tous le même `AuthResource` : le client iOS n'a qu'un
seul chemin de traitement.

Toute erreur sort en `application/problem+json`, avec un `type` stable en kebab-case sous
`https://grrind.app/problems/` — c'est là-dessus que le client iOS branche ses messages, jamais
sur le `detail`.

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
agrégat est touché. La transaction explicite arrivera au Lot 4, avec la complétion de session
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

**Lot 2 — le vocabulaire d'activité vit dans `Shared`, pas dans `Training`.** `Discipline`,
`SessionSource` et `TrustLevel` seront lus par quatre modules — plafond d'XP par discipline,
portée des modificateurs, tables de loot, classements — et Deptrac interdit qu'un module importe
l'enum d'un autre. `SessionStatus` reste dans `Training` : personne d'autre n'inspecte le statut
d'une séance, les autres réagissent à l'événement de clôture.

**Lot 2 — six disciplines, et pas de valeur « autre ».** Chacune coûte une courbe à équilibrer,
une table de loot et une ligne dans l'écran de sélection iOS ; en ouvrir une se décide par un
ticket. Un fourre-tout « autre » deviendrait le contournement immédiat du plafond d'XP quotidien
par discipline.

**Lot 2 — le crédit d'une séance se dérive de sa source.** `SessionSource::defaultTrust()` plutôt
que deux paramètres indépendants : ça rend impossible `MANUAL_TIMER` + `PROVIDER_VERIFIED`, la
combinaison dont personne n'a besoin et que tout le monde finit par écrire.

**Lot 2 — la réservation d'une clé d'idempotence tient en une seule requête SQL.**
`INSERT … ON CONFLICT … WHERE expires_at <= EXCLUDED.created_at`, avec `RETURNING`. Un `SELECT`
puis un `INSERT` laisserait deux requêtes concurrentes passer toutes les deux, et la clause
`WHERE` recycle une clé périmée sur place — sans elle, il faudrait qu'une purge soit passée avant
qu'un client puisse réutiliser une clé. Écriture en DBAL et non par l'ORM : une violation de
contrainte au `flush()` ferme l'EntityManager, et la requête métier qui suit ne pourrait plus
rien écrire.

**Lot 2 — une panne libère la clé, un refus métier la conserve.** Au-dessus de 500 on efface la
réservation : garder une clé sur une action qui n'a rien écrit condamnerait le joueur à la même
erreur pendant vingt-quatre heures. En dessous, la réponse est figée et rejouée telle quelle —
un 409 est un résultat, pas un incident.

**Lot 2 — la clé d'idempotence est scopée au compte.** L'unicité porte sur (user, clé) : deux
joueurs peuvent tirer la même valeur, et surtout une clé interceptée ne doit jamais rendre la
réponse de quelqu'un d'autre. `Shared` obtient l'UUID du joueur par `getUserIdentifier()`, sans
rien connaître d'`Identity`.

**Lot 2 — un contrôleur de `Training` reçoit un `UserInterface`, pas un `User`.** Deptrac interdit
à `Training` d'importer une entité d'`Identity`, et `#[CurrentUser]` accepte n'importe quelle
implémentation de `UserInterface`. L'identifiant de sécurité étant l'UUID du compte, un
`Uuid::fromString($user->getUserIdentifier())` suffit à savoir qui écrit — même mécanique que
`IdempotencyListener` dans `Shared`, sans port ni dépendance croisée.

L'autre chemin — un value resolver `#[CurrentPlayer]` injectant directement l'`Uuid` — n'est pas
écarté, il est **différé** (#46). C'est le point d'extension prévu par Symfony, donc légitime au
sens de la règle n°0, et l'invariant est déjà réécrit à deux endroits. Mais sur un seul contrôleur
la classe coûterait plus à lire que la ligne qu'elle remplace : le déclencheur, c'est le deuxième
ou troisième contrôleur de module de jeu, pas le premier.

**Lot 2 — un champ de DTO à valeurs fermées se type par son enum, pas par `#[Assert\Choice]`.**
Le Serializer refuse une valeur inconnue et `#[MapRequestPayload]` convertit cet échec de
dénormalisation en violation, donc en 422 nommant le champ fautif — le contrat d'erreur du reste
de l'API, sans dupliquer la liste des cas à côté de l'enum qui la porte déjà.

**Lot 2 — une séance ouverte et une séance close sont une seule forme.** `endedAt` et
`durationSeconds` sont dans `TrainingSessionResource` dès l'ouverture, à `null`, toujours présents
et jamais omis. Le client iOS décode un seul type ; deux DTO auraient divergé, et un champ qui
apparaît et disparaît finit lu de travers. C'est le même argument que pour `source` et `trust`.
Ce que le Lot 4 ajoutera décrit une *récompense*, pas une séance : ça ira dans `RewardSummary`.

**Lot 2 — le propriétaire est une condition de la recherche, pas un contrôle qui suit.**
`TrainingSessionRepository::ofPlayer(userId, sessionId)` : aucun chemin de code ne charge la séance
d'un autre compte avant de se demander s'il en avait le droit, donc aucun ne peut oublier de
vérifier. Et la séance d'autrui rend 404, pas 403 — un 403 confirmerait son existence, et un
identifiant s'essaie en boucle.

**Le suivi passe sur le tableau GitHub.** Un ticket par feature, un jalon par lot, un label par
module. Ce fichier ne porte plus que les décisions et les pièges — le reste divergeait dès qu'on
ne le relisait pas.

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

**Les chemins de `config/routes/*.yaml` n'interpolent pas les paramètres du conteneur.**
`%kernel.project_dir%/tests/…` y reste littéral et échoue en « file does not exist » — le loader
de routes résout relativement au fichier qui importe, donc `../../tests/…`.

**phpstan-doctrine ne tient pas une propriété d'entité pour écrite par l'ORM.** Une entité
hydratée mais jamais construite en PHP déclenche un `property.onlyRead` par champ, au niveau max.
Brancher `doctrine.objectManagerLoader` n'y change rien : l'extension attend que le code écrive
ses entités. Le remède est un constructeur — ce qui, au passage, remet les règles (identifiant,
péremption) dans l'entité plutôt que dans la requête SQL qui l'écrit.

**Un problem details a déjà un membre `status`, et les extensions ne l'écrasent pas.**
`SessionNotActive` mettait le statut de la séance sous la clé `status` : le `+` de `ProblemDetails`
donne la priorité aux membres standard, donc le `409` gagnait et le statut réel disparaissait
silencieusement de la réponse — exactement ce que l'erreur était censée apprendre au client. La
clé s'appelle `sessionStatus`. Le test de domaine ne pouvait pas le voir : la collision n'existe
qu'une fois l'exception sérialisée. Vérifier le **corps** d'une erreur, pas seulement son code.

**Le `KernelBrowser` redémarre le noyau entre deux requêtes.** Un service bouchon programmé
depuis le test est donc remis à zéro avant d'être consommé. Pour le social sign-in, le stub
dérive le profil du code d'autorisation lui-même : sans état, rien à perdre au redémarrage.
