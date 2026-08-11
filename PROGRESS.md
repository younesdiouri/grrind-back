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
| `POST /api/training/sessions/{id}/abandon` | Bearer + `Idempotency-Key` | 200, séance abandonnée |
| `GET /api/training/sessions` | Bearer | 200, page d'historique + `nextCursor` |
| `GET /api/training/sessions/active` | Bearer | 200 séance en cours, ou 204 |

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

**Lot 2 — un abandon compte dans le cooldown, sauf sous la durée plancher.** C'était la question
ouverte du ticket #8. Ne jamais le compter ferait de l'abandon le contournement du cooldown ; le
compter toujours punirait le chronomètre lancé par erreur, qui est précisément ce que la route
existe pour réparer. Donc : une séance abandonnée sous le plancher n'a jamais eu lieu et
n'enclenche rien, au-delà elle enclenche le cooldown comme une séance complétée. Ça ne se triche
pas — sous le plancher, il n'y avait rien à gagner. L'implémentation est au ticket #10, avec le
reste des garde-fous ; la décision est prise ici pour qu'il n'ait plus qu'à l'appliquer.

**Lot 2 — l'abandon est idempotent comme la clôture, alors même que rien ne se double.** Le ticket
ne l'exigeait pas et l'abandon n'accorde rien : le doublon ne coûte rien au jeu. Il coûte au
client. Sans clé, une requête perdue en route puis renvoyée rend un `409` — un échec affiché pour
une action réussie. La clé fait de ce cas la non-opération qu'il est, et rend au `409` son seul
sens utile : *une autre* requête a fermé cette séance entre-temps.

**Lot 2 — la séance en cours a sa propre route, pas un champ de l'historique.**
`GET /api/training/sessions/active`. Ce sont deux écrans, deux fréquences et deux tailles de
réponse : adosser la séance active à la liste obligerait à charger une page d'historique au
démarrage de l'app pour savoir si un chronomètre tourne, et poserait la question de ce que
devient ce champ quand la liste est filtrée. Elle rend **204** quand rien ne tourne, pas 404 :
n'avoir aucune séance en cours est l'état normal du joueur, et un 404 le ferait traiter dans la
branche d'échec du client, où il se confondrait avec le vrai 404 des routes voisines.

**Lot 2 — pagination par curseur sur l'UUID v7, sans total.** `?cursor=<uuid>&limit=n`, ordre
`id DESC`, une ligne lue en plus pour savoir s'il y a une suite. C'est la raison d'être du choix
de l'UUID v7 : l'ordre est dans l'identifiant, donc `id < :cursor` suffit — ni colonne d'ordre,
ni `OFFSET` qui décalerait la page dès qu'une séance s'ouvre pendant le défilement. Pas de total
non plus : un `COUNT(*)` par page pour une information dont un défilement infini n'a aucun usage.
Le jour où une activité s'importera après coup (Strava), `startedAt` cessera de suivre l'ordre des
identifiants et il faudra un curseur composite `(startedAt, id)`.

**Lot 2 — la lecture n'a pas de handler quand elle n'a pas de règle.** L'historique en a un :
il découpe les pages. La séance active n'en a pas — son contrôleur appelle le dépôt directement,
parce qu'une classe qui relaierait un `findOneBy` est exactement l'indirection que la règle n°0
demande de ne pas écrire.

**Lot 2 — une clôture trop courte est refusée, pas requalifiée en abandon.** C'était la question
ouverte du ticket #10. Refuser laisse la séance en cours : le joueur continue, ou renonce par
`/abandon`, qui existe exactement pour ça. Requalifier déciderait à sa place et détruirait une
séance qu'un appui malheureux à 4 min 59 aurait suffi à faire disparaître — entre deux options,
celle qui ne détruit rien. L'erreur porte le temps restant, le client affiche « encore 2 min ».

**Lot 2 — le plafond écrête, il ne rejette pas.** Un chrono oublié rend quatre heures créditées
au lieu de tout perdre. Conséquence à connaître : `durationSeconds` peut être plus petit que
`endedAt - startedAt`, et c'est lui qui fait foi — c'est ce que la séance *vaut*, pas ce que la
montre a affiché.

**Lot 2 — l'unicité de la séance active est garantie par un index unique partiel, pas par le
contrôle applicatif.** `CREATE UNIQUE INDEX ... ON training_session (user_id) WHERE status =
'ACTIVE'`. Entre le `SELECT` qui ne trouve rien et l'`INSERT`, deux requêtes simultanées passent
toutes les deux : le contrôle applicatif ne sert qu'à rendre une erreur lisible dans le cas
courant. Le perdant de la course est rattrapé sur `UniqueConstraintViolationException` et reçoit
la même erreur — mais l'identifiant de la séance gagnante se relit **hors ORM**, l'échec du flush
ayant fermé l'`EntityManager`.

**Lot 2 — l'équilibrage vit en YAML dès maintenant.** `config/game/v1/training.yaml`, importé
comme paramètres de conteneur et injecté dans `TrainingRules`. C'est le minimum qui tienne la
promesse « pas de constantes en dur » sans écrire de chargeur : Symfony sait déjà lire du YAML, et
un paramètre absent casse la compilation du conteneur plutôt que la première requête. Le vrai
chargeur — validation, hash, `rulesetVersion` — arrive au Lot 3 et n'aura rien à déplacer.

**Lot 2 — les événements de domaine vivent dans `Shared`, pas chez leur émetteur.** Un événement
qui franchit une frontière de module *est* le contrat entre les deux : le laisser dans
`Training\Domain\Event` obligerait `Progression` à importer une classe de `Training`, exactement la
dépendance que Deptrac refuse. Convention complète — nommage au passé, payload de scalaires et
d'enums, abonnement par `#[AsMessageHandler]` — dans le docblock de `Shared\Domain\Event\DomainEvent`.
Le routage Messenger porte sur **l'interface** : un événement ajouté est routé du seul fait qu'il
l'implémente, plutôt qu'on découvre trois lots plus tard qu'il est resté synchrone.

**Lot 2 — l'outbox est en base parce que c'est ce qui la rend atomique.** Le transport Doctrine
n'est pas un pis-aller en attendant un vrai broker : l'`INSERT` du message partage la transaction
et le `COMMIT` de la séance. Publier après le commit perd l'événement si le processus meurt entre
les deux ; publier avant annonce un fait encore annulable. `TrainingSessionRepository::transactional()`
enveloppe les deux écritures, et `auto_setup=0` — la table vient d'une migration relue, la règle
vaut aussi pour les tables qu'on n'écrit pas soi-même.

**Le suivi passe sur le tableau GitHub.** Un ticket par feature, un jalon par lot, un label par
module. Ce fichier ne porte plus que les décisions et les pièges — le reste divergeait dès qu'on
ne le relisait pas.

**Lot 3 — l'équilibrage est lu à la compilation du conteneur, jamais à l'exécution.** Un
`CompilerPass` (`GameBalancePass`) lit `config/game/v1/`, valide chaque fichier contre son schéma
et pose le résultat en paramètres. C'est ce qui tient les deux exigences d'un coup : en mode
worker FrankenPHP aucune requête ne rouvre un YAML, et un fichier incohérent fait échouer le
`cache:warmup` du build — donc la CI et l'image — plutôt que la première requête d'un joueur.
Le prix est qu'un rééquilibrage demande un redéploiement, ce qui est exactement la promesse du
config-as-code : ça se rééquilibre par un commit et une relecture, pas par un `UPDATE`.

**Lot 3 — un schéma par fichier, dans le module qui le lit, déclaré dans `Kernel::build()`.**
`training.yaml` → `App\Training\Infrastructure\Config\TrainingSection`. Le schéma ne peut pas
vivre dans `Shared` : il a besoin du module pour déléguer ses règles de cohérence à l'objet du
domaine qui les porte — `TrainingSection` construit un `TrainingRules` et convertit son refus,
plutôt que de réécrire « plafond ≥ plancher » une seconde fois. Et la liste des sections ne peut
pas vivre dans le pass, qui est dans `Shared` : elle est dans `Kernel`, seul endroit qui
n'appartient à aucune couche. Même geste que les mappings Doctrine, un module à la fois.

**Lot 3 — un YAML posé dans `config/game/v1/` sans schéma casse la compilation.** Sans cette
garde, c'est du réglage que personne ne lit, que rien ne valide et qui n'entre pas dans le
`rulesetVersion` : le silence complet. Mieux vaut casser le jour où le fichier apparaît que
chercher six mois plus tard pourquoi un rééquilibrage n'a rien changé. Corollaire : le pass suit
le **dossier** (`DirectoryResource`) et pas seulement les fichiers connus, sinon en dev la garde
ne se déclencherait qu'au prochain `cache:clear`.

**Lot 3 — l'équilibrage sort du pass en paramètres scalaires, pas en tableau.**
`game.training.minimum_duration_seconds` plutôt que `game.training` : c'est ce qu'un argument de
service sait consommer, donc `TrainingRules` reste construit avec trois entiers et un réglage
renommé casse la compilation *en nommant le service* qui l'attendait. Le domaine ne voit jamais un
tableau d'équilibrage. Effet de bord voulu de la migration : les noms de paramètres n'ont pas
changé, le câblage de `Training` est resté tel quel.

**Lot 3 — le `rulesetVersion` hashe la configuration normalisée, pas les octets des fichiers.**
`v1-fe4edd019948` : le préfixe est le dossier, les douze hex un SHA-256 du résultat *après*
validation et défauts appliqués, tables de clés triées et listes laissées dans leur ordre (l'ordre
des paliers d'une courbe *est* la donnée). Donc reformater un YAML ou y écrire un commentaire ne
date pas un rééquilibrage, alors qu'un défaut de schéma qui bouge, si — c'est bien l'équilibrage
effectif qu'on date. Disponible partout par le bind `string $rulesetVersion`, sans VO : il n'y a
rien à valider qu'un `Timezone` ferait, et le composant a déjà tout refusé.

**Checkpoint #53 — pas de Redis en v1, et le déclencheur est écrit.** Rien dans le code ne dépend
d'un état partagé entre processus : `cache.app` est en filesystem, il n'y a ni `LockFactory`, ni
`RateLimiter`, ni `CacheInterface` injecté nulle part. Le verrou de la complétion sera pessimiste
sur la ligne de progression, dans la transaction — un verrou distribué serait un affaiblissement,
pas un progrès. Redis entre le jour où il y a **plus d'un conteneur applicatif en prod** *et* un
besoin d'état partagé ; le premier sera le rate limiter (#38), et même là un pool
`cache.adapter.doctrine_dbal` suffit tant que le volume ne le dément pas. Les classements (#36)
sont le seul candidat sérieux à terme, et une table Postgres indexée tient les premiers milliers
de joueurs.

**Checkpoint #53 — la règle d'admission dans `Shared`.** Une classe y entre si (a) au moins
**deux** modules l'importent, ou (b) c'est une préoccupation transverse HTTP/persistance sans
logique de jeu. `Domain\Activity` passe par (a), `Idempotency` par (b). À 1 300 lignes pour trois
modules, `Shared` est proportionné ; le risque est qu'il devienne le dépotoir quand Progression,
Rewards et Engagement arriveront — `ModifierVocabulary` sera le premier à se présenter. Point de
contrôle au Lot 5 : au-delà de ~2 500 lignes, l'idempotence sort dans son propre module technique.
Et la question « hexagonal plutôt que ça ? » ne se pose pas : `Domain / Application /
Infrastructure / UI` par module *est* ports & adapters, la découpe existe déjà.

**Checkpoint #53 — les objets commande restent, et la tension est assumée.** `StartSession` a deux
propriétés, il est construit dans le contrôleur et passé à un handler appelé directement : sans
bus, il ne découple de rien, et c'est en tension avec « Lot 1 — pas de bus de commandes ». On les
garde parce qu'ils donnent un domicile aux invariants (« le *quand* n'est pas un paramètre »),
rendent les handlers testables sans HTTP, et que `CompleteSession` va grossir au Lot 4. C'est écrit
ici pour que personne ne « corrige » l'incohérence dans le mauvais sens dans six mois.

**Lot 3 — le détail d'un calcul d'XP est une table fille, pas une colonne JSON.**
`xp_transaction_line` : une ligne par contribution, `source` en colonne typée, `amount` en entier
signé, `position` pour l'ordre d'animation. Ce que ça achète au-delà du stockage : « combien d'XP
ce joueur doit-il à son streak » est un `GROUP BY` et non une relecture de tout l'historique, et
PostgreSQL refuse une contribution qui ne serait pas un entier. `position` est explicite plutôt que
déduit de l'ordre d'insertion — s'en remettre à celui-ci marcherait jusqu'au jour où il ne
marcherait plus, sans que rien ne le signale.

**Lot 3 — l'append-only est tenu par l'applicatif, pas par un trigger PostgreSQL.** Décision prise
contre l'habitude du Lot 2 (« l'unicité vient de l'index, pas du contrôle applicatif ») et assumée
comme telle. Les entités n'ont aucun mutateur, et `LedgerIsAppendOnly` — un entity listener
Doctrine, le point d'extension prévu — refuse `preUpdate` et `preRemove` sur les deux tables.
**Ce que ça ne couvre pas** : un `DELETE` en SQL direct ou une requête DQL de masse ne passent pas
par l'unité de travail. C'est le prix du choix, il est écrit ici pour que personne ne croie la
garantie plus large qu'elle n'est. Le `ON DELETE RESTRICT` sur la jointure est la seule chose que
la base oppose, et c'est déjà mieux que le `CASCADE` par défaut, qui est le contraire de ce qu'on
veut sur une table de vérité comptable.

**Lot 3 — l'idempotence du ledger est `uniq_xp_transaction_source_reason`, pas un `SELECT`.**
Le couple (source, raison) autorise exactement ce qu'il faut : une séance se crédite une fois,
s'invalide une fois. `recordedFor()` existe pour rendre le cas courant lisible, pas pour garantir
quoi que ce soit — entre la lecture et l'écriture, deux complétions rejouées par un client mobile
passent toutes les deux.

**Lot 3 — le montant d'une transaction n'est pas une donnée d'entrée : c'est la somme de son
détail.** `XpTransaction` le calcule depuis le `XpBreakdown` qu'on lui donne. Un total qui pourrait
diverger du détail censé l'expliquer serait le premier endroit où le ledger cesserait d'être
vérifiable. Corollaire : `XpBreakdown` refuse d'être vide — un montant sans explication n'est pas
un montant, y compris quand il vaut zéro (une séance entièrement rognée par les rendements
décroissants s'écrit quand même, et c'est le détail qui la rend compréhensible).

**Lot 3 — une annulation reprend la `rulesetVersion` du crédit qu'elle annule.** On rend ce qu'on
avait donné, sous les règles qui l'avaient donné, ligne par ligne inversée. Recalculer aux règles
courantes ferait de chaque rééquilibrage une redistribution silencieuse sur tout l'historique.
Et pas de valeur « correction manuelle » dans `XpReason` : aucune route ni commande n'en crédite,
et une valeur qu'aucun code n'écrit est une porte qu'on finit par pousser.

**Lot 3 — les modificateurs se composent en additif sur le socle, et il n'y a pas de plafond.**
C'était la question ouverte du #18, tranchée en #14 parce que c'est de l'arithmétique. Un socle de
90 avec +20 % de streak et +15 % d'objets vaut `90 + 18 + 13 = 121`, pas `90 × 1,20 × 1,15`. Trois
raisons : **chaque ligne du breakdown reste vraie isolément** (en multiplicatif, la contribution
d'un objet dépend de ce qui a été appliqué avant, donc l'ordre devient porteur de sens et perdre
son streak ferait aussi baisser la ligne « objets ») ; **la croissance est linéaire**, alors que
c'est l'empilement multiplicatif qui explose et oblige à un plafond ; **le rééquilibrage est
local**. D'où l'absence de plafond, qui n'est pas un oubli : le déclencheur est écrit, c'est le
jour où les arbres (#31) ouvrent assez de nœuds pour dépasser +100 % de bonus cumulé. Les
garde-fous de #15 bornent déjà la journée, qui est la vraie surface d'abus.

**Lot 3 — le breakdown groupe par source, et le cumul précède l'arrondi.** Deux objets à +5 %
donnent une ligne `ITEM` à +9 sur un socle de 90, pas deux lignes à +4. Deux raisons : deux
troncatures successives perdent des points au joueur sans que rien ne l'explique, et **grouper rend
le breakdown déterministe** — le même ensemble de modificateurs donne le même détail quel que soit
l'ordre dans lequel le resolver les a produits, sans quoi deux calculs identiques écriraient deux
lignes de ledger différentes. Conséquence assumée : pas d'attribution par objet (« +13 bottes »
devient « +13 objets ») tant que la colonne d'origine laissée ouverte au #13 n'existe pas. Une
contribution qui s'arrondit à zéro n'occupe aucune ligne : elle n'explique rien.

**Lot 3 — le socle est linéaire, par heure et par discipline.** `intdiv(durée × xp_per_hour, 3600)`,
tronqué vers le bas, donc une séance ne rapporte jamais plus que ce que le barème annonce.
L'équilibrage se lit comme une phrase — « une heure de natation vaut 100 XP » — et la courbe non
linéaire vient d'ailleurs : les rendements décroissants de #15 s'expriment en tranches de minutes,
les deux morceaux s'emboîtent sans se recouvrir. `xp.yaml` déclare les disciplines en **liste** et
non en table indexée, parce que le chargeur ne descend pas dans les listes : `game.xp.disciplines`
reste un paramètre unique, et ouvrir une discipline ne demande pas de recâbler `services.yaml`.
Une discipline non couverte fait échouer le démarrage — sinon elle rapporterait zéro en silence,
et c'est un joueur qui découvrirait le trou.

**Lot 3 — le fuseau du joueur traverse la frontière des modules par un port dans `Shared`.**
`Shared\Application\PlayerTimezones` : `Identity\UserRepository` l'implémente, `Progression` le
consomme, aucune flèche ne va de l'un à l'autre. C'est le premier port de ce genre, et il est
justifié un par un comme le veut la règle n°0 : le fuseau est un attribut de profil, le plafond
quotidien et le streak se calculent dedans, et aucun composant Symfony ne répond à une frontière
de *notre* découpage. `Engagement` le réutilisera tel quel au #24. La réplication par événement a
été écartée : elle est asynchrone, et un joueur qui change de fuseau puis clôture une séance dans
la seconde serait compté sur l'ancien. Un compte introuvable rend `UTC` plutôt que de lever — c'est
le pire découpage possible pour le joueur, jamais le plus avantageux, donc ça ne se triche pas.

**Lot 3 — les garde-fous quotidiens s'appliquent au temps, puis au total.** Socle → rendements
décroissants (qui rabotent le socle) → bonus (en % du socle **raboté**) → plafond quotidien (qui
écrête le total). Placer les rendements avant les bonus donne exactement le même montant qu'après
— les deux opérations sont linéaires — mais une seule troncature entière au lieu d'un ratio
appliqué à un sous-total, et une narration que le joueur suit : « 90 de base, −40 parce que tu as
déjà beaucoup couru, +10 grâce à ta série ». Les deux garde-fous ont chacun **leur ligne dans le
breakdown** : montrer ce qui a été rogné est ce qui sépare une mécanique d'une punition.

**Lot 3 — le découpage des rendements est par tranche, pas par palier global.** Un palier global
(« au-delà d'une heure, tout vaut 60 % ») ferait perdre à la 61ᵉ minute de l'XP déjà acquise, et
le joueur apprendrait à s'arrêter à 59. Par tranche, découper sa journée en trois séances ou en
faire une seule donne le même total — c'est testé, sans quoi le découpage deviendrait une stratégie.

**Lot 3 — `duration_seconds` est signée au ledger, comme le montant.** L'annulation d'une séance
porte une durée négative, donc les deux compteurs de la journée se soldent par simple somme : une
séance invalidée cesse de peser sur les rendements décroissants exactement comme elle cesse de
compter en XP, sans que la requête ait à filtrer sur les raisons.

**Lot 3 — le plafond quotidien est un filet, pas une laisse.** Deux heures de barème par
discipline, au-dessus des ~130 XP de socle qu'une journée permet une fois les rendements passés :
il ne se fait sentir que quand les bonus s'empilent. Un plafond serré aurait rogné tous les jours
les bonus que le joueur a investi pour obtenir, et un bonus qui ne sert jamais est un bonus qu'on
regrette d'avoir acheté. `XpRates` refuse un plafond sous le taux horaire — ce serait faire du
backstop le limiteur principal.

**Lot 3 — la courbe de niveaux porte des seuils *cumulés*, pas des coûts.** `levels.yaml` donne
pour chaque niveau le total d'XP à partir duquel il est atteint, ce qui rend la projection
indépendante de l'historique : un joueur à 3 060 XP est niveau 10, qu'il y soit arrivé en un mois
ou en un jour, et qu'on ait rejoué ou non les transactions qui l'y ont mené. Les seuils sont écrits
en clair et non calculés au chargement depuis `coût(n) = 100 + 60 × (n − 1)` — une formule
interdirait de retoucher un niveau seul, et c'est justement ce que « config-as-code » doit
permettre. Le niveau 1 est déclaré explicitement à 0 XP : un fichier qui commence à 2 laisse le
socle implicite, et un sous-entendu se relit de travers.

**Lot 3 — le snapshot stocke les points de compétence *accordés*, pas *disponibles*.** Le ticket
#16 disait « disponibles » ; c'est la seule chose qu'un cache reconstructible ne peut pas porter.
Les points dépensés viendront de l'arbre du joueur (#32), que le ledger ignore : stocker un solde
ici rendrait le snapshot irreconstruisible, et « disponibles » se calcule de toute façon en
`accordés − dépensés` au moment de l'afficher. Le ticket a été corrigé plutôt que le code.

**Lot 3 — la reprojection relit la somme du ledger, elle n'incrémente pas le snapshot.**
`retotal()` reçoit `SUM(amount)` et en dérive tout. Un `+=` serait plus rapide d'une requête et
transformerait chaque divergence en dette permanente ; là, un écart — import, correction, reprise
après incident — se résorbe tout seul au crédit suivant. C'est aussi ce qui fait que la commande
de reconstruction (#20) n'aura rien de particulier à faire.

**Lot 3 — `user_id` *est* la clé primaire de `progression_snapshot`.** Une ligne par compte, sans
identifiant propre : l'unicité vient de la structure au lieu de reposer sur un index qu'on pourrait
oublier, et c'est cette ligne que la complétion verrouille en `PESSIMISTIC_WRITE` — un verrou par
joueur, donc deux comptes ne s'attendent jamais. La ligne se crée au premier crédit par un
`INSERT … ON CONFLICT (user_id) DO NOTHING` hors ORM, pas à l'inscription : `Identity` n'a pas à
connaître `Progression`, et entre un `SELECT` qui ne trouve rien et un `INSERT`, deux requêtes
simultanées passent toutes les deux. Même geste qu'à la réservation d'une clé d'idempotence.

**Lot 3 — les niveaux franchis sont une *liste*, et une baisse n'annonce rien.** Un joueur qui
revient après une pause en gagne deux ou trois d'un coup, et le client les anime un par un ; un
booléen « a monté de niveau » les lui ferait avaler en silence. À l'inverse, une annulation qui
fait redescendre le niveau ne produit aucune annonce : le joueur revient à son niveau réel, mais
il n'y a rien à célébrer ni à animer.

## Pièges déjà rencontrés

**Un index partiel se déclare tel que PostgreSQL le *relit*, casts compris.** Écrire
`options: ['where' => "(status = 'ACTIVE')"]` dans le mapping produit un index correct, mais
PostgreSQL stocke le prédicat normalisé — `((status)::text = 'ACTIVE'::text)` — et
`doctrine:migrations:diff` compare deux chaînes : chaque diff reproposait un `DROP` + `CREATE`
identique. La forme normalisée est dans le mapping, la forme lisible dans la migration. Vérifier
qu'un diff est bien vide **après** avoir ajouté un index partiel.

**Le worker redémarre en boucle tant que `messenger_messages` n'existe pas.** Le service `worker`
de `compose.yaml` démarre avec la stack ; sur une base fraîche, `make up` avant `make migrate` le
laisse en `Restarting`. Ce n'est pas une panne — il se stabilise dès la migration appliquée.

**Une horloge serveur qui ne se contourne pas rend les tests dépendants du temps réel.** Aucune
route ne permet d'antidater, et les garde-fous parlent en minutes : impossible de clôturer une
séance dans la seconde. La suite ne simule pas l'horloge — elle recule la séance *déjà écrite* en
base (`TrainingSessions::ageSession()`). Le serveur continue de dater ce qu'il date ; on ne déplace
que le passé, ce qui prouve le comportement avec la vraie heure plutôt qu'avec une fausse.

**`.dockerignore` et le contexte de build.** `docker/` avait été exclu alors que le Dockerfile y
prend les `php.ini` : le build cassait. Un `.dockerignore` écrit après un build réussi ne prouve
rien — refaire `docker compose build` **et** `docker build --target prod .` après l'avoir touché.

**`APP_ENV` du conteneur gagne sur `phpunit.dist.xml`.** Le `force="true"` du fichier XML ne
suffit pas : c'est pour ça que la cible `test` du Makefile passe `-e APP_ENV=test`.

**PHPStan a besoin d'un cache dev chaud** pour l'extension Symfony — la cible `phpstan` fait le
`cache:warmup` elle-même, sinon la CI échoue sur un conteneur neuf.

**`migrations:diff` compare au *schéma de la base de dev*, pas aux migrations écrites.** Une base
en retard d'une migration fait reproduire ses instructions dans la suivante : le diff du #16 a
resservi les deux `ALTER TABLE xp_transaction` du #15, qui auraient cassé la montée sur toute base
à jour. `make migrate` **avant** `make migration`, et relire le diff en cherchant ce qui ne parle
pas du ticket en cours.

**`LockMode` vit dans DBAL, pas dans l'ORM.** `Doctrine\ORM\LockMode` n'existe plus en ORM 3 ;
c'est `Doctrine\DBAL\LockMode`. L'erreur ne se voit qu'à l'exécution — l'autoload ne se plaint pas
d'un `use` inutilisé à l'analyse — et un verrou pessimiste n'a pas de comportement dégradé : ou il
s'exécute, ou il lève.

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

**Doctrine hydrate une projection de champ à travers son type.** `->select('u.timezone')` ne rend
pas une chaîne mais un `Timezone` — le type Doctrine s'applique aussi aux sélections scalaires.
Le code attendait une chaîne et retombait sur son repli `UTC` **sans rien signaler** : le pire des
cas, puisqu'un joueur se serait simplement vu compter sa journée de travers. Seul le test
d'intégration l'a vu, en comparant deux fenêtres autour de minuit à Paris ; aucun test unitaire du
domaine n'aurait pu. Vérifier le *type réel* de ce que rend une projection, et se méfier de tout
repli silencieux sur une valeur par défaut plausible.

**Le `KernelBrowser` redémarre le noyau entre deux requêtes.** Un service bouchon programmé
depuis le test est donc remis à zéro avant d'être consommé. Pour le social sign-in, le stub
dérive le profil du code d'autorisation lui-même : sans état, rien à perdre au redémarrage.
