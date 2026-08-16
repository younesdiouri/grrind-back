# GRRIND — Backend

API du produit GRRIND : une app qui transforme le sport en RPG (XP, niveaux, titres, arbres de
compétences, loot, streak, ligues). Ce dépôt ne contient **que le back**. Le client est une app
**React Native** (dépôt séparé) — voir ci-dessous pourquoi, la question se repose sinon.

### Le client est en React Native, et ce n'est pas à « corriger »

Le projet est développé en solo et **Android est un objectif réel à 12-18 mois**. SwiftUI ferme
cette porte définitivement : Android imposerait une réécriture complète en Kotlin/Compose, dont
seul le client HTTP généré depuis l'OpenAPI serait réutilisable. React Native préserve l'option
pour un coût marginal.

Le risque de React Native est unique et localisé : le `RewardSummary` est *l'*écran du produit,
« l'ordre des champs, c'est l'ordre de l'animation ». C'est le seul endroit où il peut décevoir,
et il se lève par un spike — pas par un pari.

**Swift ne disparaît pas.** HealthKit passera par un module natif Expo écrit en Swift (aucune vue),
et une vraie app watchOS reste prévue à long terme comme target séparé à côté de l'app RN. Une
mention de SwiftUI ou d'iOS qui parle de *la plateforme* — le clavier qui ajoute une majuscule,
l'exigence d'Apple sur le sign-in tiers, un schéma d'URL de redirection — reste donc vraie. Seules
celles qui parlaient *du client* étaient à corriger.

**Rien de tout ça n'a bougé côté serveur, et c'est le point.** `HEALTHKIT` était déjà prévu comme
`source` avec `trust=PROVIDER_VERIFIED`, et la forme du `RewardSummary` est dictée par la mise en
scène, pas par le framework qui la joue.

Le virage santé l'a **renforcé** plutôt que remis en cause : le module natif Swift pour HealthKit
était déjà écrit ici comme le plan, et il est devenu la brique centrale du produit. Son pendant
Android — Health Connect, en Kotlin — est un second module natif derrière la même interface JS.
C'est exactement l'option que React Native préservait.

## Règle n°0 : priorité à l'écosystème Symfony

**Ce que Symfony fournit ne se réécrit pas.** Avant d'écrire une classe, la question est
« le framework, ou un bundle de référence, sait-il déjà faire ça ? ». La réponse est oui plus
souvent qu'on ne croit, et une abstraction maison par-dessus une abstraction du framework
n'achète rien : elle coûte une classe à maintenir et une indirection à lire.

L'ordre de préférence, sans exception tacite :

1. **Le composant Symfony**, configuré. Security (firewalls, authenticators, providers, voters,
   hachage), Validator, Serializer, Messenger, HttpClient, RateLimiter, Clock, Uid, Lock.
2. **Le bundle de référence** de l'écosystème : Doctrine, LexikJWTAuthenticationBundle,
   MakerBundle. `make console c="list make"` avant d'écrire un squelette à la main.
3. **Le point d'extension prévu** quand le comportement standard ne suffit pas —
   `UserLoaderInterface` sur un dépôt, un value resolver, un normalizer, un event listener.
   Il y en a presque toujours un ; le chercher est plus rapide que le contourner.
4. **Du code à nous**, en dernier, et alors on écrit *pourquoi* dans le fichier.

**En cas de doute, demander.** Si deux chemins se valent (`json_login` contre un authenticator
sur mesure, un port contre une dépendance directe), poser la question avec le compromis explicite
plutôt que de trancher seul. C'est une règle, pas une politesse.

Le skill **`symfony-docs`** (`.claude/skills/symfony-docs/SKILL.md`) existe pour ça : il consulte
`symfony.com/doc/current` avant d'écrire, plutôt que de s'en remettre à des souvenirs de version.
Les noms de services changent entre majeures, et les inventer échoue silencieusement.

Ce que cette règle a déjà coûté quand on l'a ignorée, au Lot 1 : un value object `Email` avec
`filter_var` là où `#[Assert\Email]` suffisait, un port `PasswordHasher` enveloppant
`UserPasswordHasherInterface`, un `LogInHandler` vérifiant le mot de passe à la main au lieu de
`json_login`, un `getRoles()` en dur. Quatorze classes supprimées à la refonte, aucun
comportement perdu.

Les rares ports qui restent se justifient un par un dans leur docblock. Il n'y en a qu'un dans
Identity — `SocialProfileResolver`, parce qu'aucun test ne peut appeler Google et qu'aucune
bibliothèque n'abstrait « code d'autorisation → profil ».

## Règle n°1 : tout passe par Docker

Il n'y a **pas de PHP, Composer ou Symfony CLI installés sur la machine hôte**. Aucune commande
PHP ne doit être lancée en direct. Tout passe par `make`, qui délègue à `docker compose`.

```bash
make               # liste toutes les cibles
make install       # première installation : images, dépendances, base
make up            # démarre la stack (http://localhost:8080)
make sh            # shell dans le conteneur php
make composer c="require foo/bar"
make console c="debug:router"
make secrets       # génère .env.local et .env.test.local — jamais versionnés
make jwt-keys      # (re)génère la paire de clés JWT — jamais versionnée
make migration     # génère une migration (à relire avant de l'appliquer)
make migrate       # applique les migrations
make test          # phpunit (crée et migre la base de test au passage)
make qa            # phpstan + cs-fixer + deptrac
make down
```

Si une commande manque au Makefile, on l'ajoute au Makefile — on ne contourne pas.

## Stack

| Brique | Choix |
|---|---|
| Runtime | FrankenPHP (PHP 8.4), mode worker en prod |
| Framework | Symfony 8.1 (on suit la stable courante, pas la LTS) |
| Persistance | PostgreSQL 17 + Doctrine ORM 3 (migrations versionnées, jamais de `schema:update`) |
| Async | Symfony Messenger, transport Doctrine (pattern outbox) |
| Auth | Firewall Symfony + JWT (LexikJWTAuthenticationBundle) + refresh tokens maison, social sign-in Google/Apple (league/oauth2-client) |
| HTTP | Contrôleurs fins + DTO + Serializer. OpenAPI généré (`openapi.yaml`, source de vérité du contrat client) |
| Tests | PHPUnit — le moteur de jeu est testable sans aucune infra |
| Qualité | PHPStan niveau max, PHP-CS-Fixer, Deptrac (frontières entre modules) |

## Architecture : monolithe modulaire

Un seul déploiement, des modules à frontières dures. Deptrac interdit les dépendances croisées :
la communication inter-modules se fait par **événements de domaine** ou par des **ports** explicites,
jamais par import direct d'une entité d'un autre module.

```
src/
  Shared/         # Kernel, Clock, UuidV7, types Doctrine, idempotence, ModifierVocabulary
  Identity/       # User, auth, profil, timezone
  Training/       # Workout : import santé, arbitrage, historique
  Progression/    # Ledger XP, courbe de niveaux, titres, arbres de compétences
  Rewards/        # Tables de loot, tirages, inventaire, équipement
  Engagement/     # Streak, classements, ligues
  Community/      # Guildes, adhésions, invitations
```

Chaque module suit la même découpe :

```
<Module>/
  Domain/         # entités, VO, règles pures — zéro dépendance framework
  Application/    # commandes/handlers, orchestration, ports
  Infrastructure/ # Doctrine, adapters, implémentations des ports
  UI/Http/        # contrôleurs, DTO requête/réponse
```

## Invariants de conception (à ne jamais casser)

**Le serveur *arbitre* l'horloge.** Il ne la possède plus, et c'est un vrai changement
d'invariant — pas une reformulation. Un workout a eu lieu avant que Grrind en entende parler :
il n'existe aucune horloge serveur pour un fait passé, donc les bornes viennent du fournisseur
santé. Ce que le serveur garde, c'est le **jugement** : la durée n'est jamais un champ acceptable
(elle se recalcule des deux bornes), et fenêtre d'antériorité, chevauchements, plancher et
plafond décident de ce qui compte.

La raison d'origine — empêcher l'antidatage — est servie autrement, et mieux : la donnée est
certifiée par la plateforme, et l'arbitrage reste serveur. Ce qui n'a pas changé d'un mot :
**aucune valeur de jeu ne se calcule à partir de ce que le client affirme.**

**Toute activité est une source attribuée.** Chaque workout porte `source` (`APPLE_HEALTH`,
`HEALTH_CONNECT`) et `trust` (`DECLARED` → `PROVIDER_VERIFIED`). Le modèle ne bouge pas quand on
branche un fournisseur de plus.

C'est l'invariant qui a le mieux vieilli, et ça vaut d'être noté : `HEALTHKIT` était déjà prévu
avec `trust=PROVIDER_VERIFIED` bien avant qu'on décide d'en faire la source principale, et le
modèle n'a pas eu à bouger pour l'accueillir — seulement les valeurs de l'enum. C'est la
démonstration que la règle marche.

**L'XP est un ledger append-only.** `xp_transaction` est la vérité ; le niveau est une projection.
On n'incrémente jamais un compteur `xp` en place. Un workout invalidé génère une transaction
négative, on ne supprime rien. `progression_snapshot` est un cache reconstructible.

Chaque écriture est datée par le **sport** (`occurred_at`), pas par son insertion : dix workouts
importés d'un coup appartiennent à dix journées différentes, et c'est ce qui range chacun sous le
bon plafond quotidien. L'instant de l'écriture n'est pas perdu pour autant — il est encodé dans
l'UUID v7 de la ligne.

**Le calcul d'XP est une fonction pure et versionnée.** On stocke le montant accordé *et* la version
du ruleset *et* le détail du calcul. On peut rééquilibrer sans corrompre l'historique, et le client
peut afficher « 90 base, +18 streak, +13 bottes ».

**Un seul vocabulaire de modificateurs.** Compétences, objets équipés, streak et ligue produisent
tous des modificateurs typés (`XP_MULTIPLIER`, `LOOT_LUCK`, `STREAK_SHIELD`, `UNLOCK_SESSION_TYPE`).
Un unique `ModifierResolver` calcule l'ensemble actif d'un user. C'est ce qui empêche le moteur de
pourrir.

**Le RNG est serveur et auditable.** Tout tirage de loot stocke le roll, la graine et la version de
la table. Le client ne tire jamais rien.

**Les écritures métier sont idempotentes.** Header `Idempotency-Key` obligatoire sur l'import —
les clients mobiles rejouent leurs requêtes. Il ne fait pas doublon avec l'unicité
`(user, source, externalId)` : celle-ci empêche le double crédit, celui-là rend la **réponse**
d'origine. Sans lui, un client qui rejoue reçoit une synchronisation vide au lieu de sa mise en
scène — l'XP serait juste, l'animation perdue.

**La balance du jeu est du config-as-code.** Courbe de niveaux, coefficients XP, tables de loot,
arbres, catalogue d'objets vivent en YAML versionné sous `config/game/v1/`, chargés et validés au
boot, hashés pour produire le `rulesetVersion`. Rien de tout ça en base en v1.

## Transaction d'import

C'est le cœur du produit — le moment dopamine. Le client envoie **tout ce que la montre a
enregistré depuis sa dernière synchronisation** : revenir après dix jours avec trois séances est
le cas nominal, pas l'exception. Séquence, en une transaction :

```
trier les workouts par startedAt croissant
écarter ce qui se juge seul : doublon, activité non traduite, durée sous le plancher
départager les chevauchements du lot : le plus complet gagne

BEGIN
  pour chaque workout, dans l'ordre :
      écarter s'il chevauche un workout déjà en base
      écrire le workout
      hors fenêtre → conservé, non crédité, on s'arrête là
      verrou pessimiste sur la ligne progression du user (pris une fois, tenu jusqu'au COMMIT)
      résoudre les modificateurs actifs (ModifierResolver)
      relire la charge du jour — celle de la journée du sport
      calculer l'XP (XpCalculator, pur)
      écrire l'XpTransaction
      mettre à jour le snapshot, attribuer les points de compétence
      tirer le loot (LootRoller, audité)
      mettre à jour le streak
      publier WorkoutImported dans l'outbox
COMMIT
→ asynchrone : classements, notifications
→ réponse : SyncSummary
```

**Un import est un ensemble, pas une transaction tout-ou-rien** — et les deux phrases coexistent.
Un workout *écarté* n'annule rien : neuf séances valides ne peuvent pas échouer parce que la
dixième est une partie de curling. Une *panne*, elle, défait tout : un workout écrit sans XP
créditée est une perte silencieuse.

**L'ordre chronologique n'est pas cosmétique.** Les rendements décroissants se calculent sur ce
que le joueur a déjà fait ce jour-là, donc la charge du jour se relit à chaque itération, en
voyant ce que la boucle vient d'écrire. Le même lot envoyé dans un autre ordre doit donner le
même ledger.

Le verrou est pris une fois — un verrou de ligne est ré-entrant dans une transaction — et
sérialise deux synchronisations concurrentes : deux appareils du même compte, ou l'app relancée
pendant qu'une synchronisation tournait encore.

Le `SyncSummary` est un payload unique conçu pour être **animé séquentiellement** : un
`RewardSummary` par workout crédité, dans l'ordre chronologique, chacun portant son palier de
départ *et* d'arrivée pour que la barre s'enchaîne sans un seul recalcul côté client. **L'ordre
des champs est l'ordre de l'animation** — entre les workouts, puis à l'intérieur de chacun — et
il ne doit rien au framework qui la joue.

## Garde-fous anti-abus v1

Ce sont **des règles de game design avant d'être de l'anti-triche**, et c'est pour ça qu'elles
survivent à une donnée certifiée par Apple : ce sont elles qui font qu'une journée de quatre
heures ne vaut pas quatre fois une journée d'une heure. Elles protègent aussi le jour où une
source déclarée reviendra.

- rendements décroissants sur la journée (0-60 min ×1, 60-90 ×0.6, 90-120 ×0.3, au-delà ×0),
  calculés workout par workout dans l'ordre chronologique
- plafond d'XP quotidien par discipline
- durée plancher (un faux départ sur la montre n'est pas une séance) et plafond (l'enregistrement
  oublié est **écrêté**, jamais rejeté)
- fenêtre d'antériorité de 30 jours : au-delà, un workout est **conservé sans être crédité**
- chevauchement : deux enregistrements du même effort par deux applications ne comptent qu'une fois

Le cooldown entre séances et la règle « une seule séance active » ont disparu avec le
chronomètre : Apple produit trois workouts d'affilée sans demander la permission à personne, et
refuser le troisième reviendrait à refuser un fait qui a eu lieu.

## Conventions

- Identifiants : UUID v7 (triables, générés applicativement).
- Dates : `TIMESTAMPTZ`, stockage UTC. **Le streak se calcule dans le fuseau du user** — le fuseau
  est un attribut de profil, pas une déduction.
- Argent/ratios : jamais de float sur des valeurs de jeu persistées ; entiers ou décimaux.
- Enums PHP natifs, backed, sur toutes les valeurs fermées.
- Migrations Doctrine relues à la main, jamais générées-appliquées à l'aveugle. Le diff se
  trompe : il propose `DROP` + `ADD` là où il faut un `RENAME`, et des `NOT NULL` sans défaut
  qui échouent sur une table peuplée.
- Réponses d'erreur en RFC 7807 (problem+details).
- **Aucun secret versionné.** `.env` porte les défauts non sensibles et reste committé ; les
  valeurs réelles vivent dans `.env.local` / `.env.test.local` (`make secrets`) en dev, et dans
  l'environnement en prod. Les clés JWT et la clé `.p8` d'Apple ne sont ni versionnées ni
  incluses dans l'image.

## Authentification

Le client détient deux jetons de nature différente :

- un **JWT** signé RS256, 15 minutes, identité portée par le claim `sub` (l'UUID du compte).
  Il ne se révoque pas — c'est pour ça qu'il est court.
- un **refresh token** de 30 jours, à usage unique, **rotatif**, groupé par *famille*. Une famille
  correspond à un appareil connecté ; s'en déconnecter la révoque entièrement.

Seul le SHA-256 du refresh token est stocké. Le rejeu d'un jeton déjà consommé révoque toute la
famille : on ne peut pas distinguer le voleur du vrai client qui a été doublé, donc on coupe.

L'entité `User` **est** le `UserInterface` du firewall : pas d'adaptateur, une colonne `roles`,
un enum `Role`. Le login passe par `json_login` — vérification du mot de passe, protection contre
l'énumération de comptes et rehash opportuniste viennent du composant, pas de nous.

L'identifiant de sécurité reste l'UUID et non l'e-mail : changer d'adresse n'invalide aucun jeton.
C'est `UserLoaderInterface` sur le dépôt qui sert les deux firewalls — l'UUID du claim `sub` pour
`^/api`, l'adresse normalisée pour le login. Les contrôleurs authentifiés reçoivent le `User` par
`#[CurrentUser]` : **aucune route ne prend d'identifiant de compte en paramètre.**

**Social sign-in.** `POST /api/auth/social/{google|apple}`. Le client natif mène l'écran
d'autorisation et envoie le code ; le serveur seul l'échange. La clé de liaison est le couple
(fournisseur, `sub`), jamais l'adresse. Rattacher un compte préexistant exige que le fournisseur
**certifie** l'adresse — sinon c'est une prise de contrôle en une requête. Un compte créé ainsi
n'a pas de mot de passe et ne peut pas passer par `/api/auth/login`.

## Suivi du travail : le tableau GitHub, pas un fichier

**L'avancement se suit sur https://github.com/users/younesdiouri/projects/1.** C'est la seule
source de vérité sur ce qui reste à faire. Un ticket par feature, un **jalon** par lot, un
**label** par module (`identity`, `training`, `progression`, `rewards`, `engagement`, `shared`,
`infra`) et par nature (`dette`, `securite`, `contrat-api`).

Le CLI `gh` est disponible et c'est par lui que ça se passe :

```bash
gh issue list --milestone "Lot 2 — Training"   # le jalon courant
gh issue view 42                               # le périmètre exact d'un ticket
gh issue list --label dette                    # ce qu'on s'est promis de rembourser
```

**On travaille ticket par ticket.** Avant d'écrire du code : lire le ticket, et si le périmètre a
bougé, le mettre à jour plutôt que de dévier en silence. Une feature = un ticket = une branche =
une PR qui le ferme. Ne pas ouvrir un chantier qui n'a pas de ticket — en créer un d'abord, avec
le même soin que les autres.

### Tout commit référence son ticket

**Aucun commit sans numéro de ticket.** C'est ce qui relie le code à son intention : dans six mois,
`git log` seul ne dira jamais *pourquoi*, le ticket si.

Le dernier commit d'une feature porte un **mot-clé de fermeture**, pour que GitHub close le ticket
tout seul à l'arrivée sur `main` — et le tableau le déplace en `Done` dans la foulée, le workflow
« Item closed » du projet est actif.

```
feat(training): la durée se calcule, elle ne se recopie pas

Le client envoie deux bornes du fournisseur, jamais une durée : une troisième
valeur à réconcilier lui donnerait une prise sur ce qu'il gagne.

Closes #83
```

- **Mots-clés qui ferment** : `Closes`, `Fixes`, `Resolves` (+ leurs variantes `close`/`fixed`/…),
  suivis de `#42`. Un par ticket fermé — `Closes #6, #7` ne ferme que le premier, il faut
  `Closes #6` puis `Closes #7` sur des lignes séparées.
- **La fermeture n'a lieu qu'à l'arrivée sur la branche par défaut.** Sur une branche de feature,
  le mot-clé ne fait rien tant que ce n'est pas mergé dans `main` — c'est normal, pas un bug.
- **Commit intermédiaire qui n'achève pas le ticket** : le référencer sans mot-clé, `Refs #6`. Il
  apparaîtra dans le fil du ticket sans le fermer.
- **Toucher à autre chose en passant** reste interdit : si ça n'est pas dans le ticket, ça mérite
  son propre ticket, pas une ligne discrète dans un commit qui parle d'autre chose.
- **Viser un ticket de l'autre dépôt** s'écrit en toutes lettres : `Closes younesdiouri/grrind-back#42`
  depuis le front, `younesdiouri/grrind-app#7` depuis ici. Les mots-clés de fermeture fonctionnent
  sous cette forme. Le tableau est commun aux deux dépôts — c'est un projet utilisateur, il affiche
  la colonne « Repository » — mais **les jalons et les labels, eux, sont par dépôt** : « Lot 4 » ne
  traverse pas, et c'est un champ du projet qui doit en tenir lieu.

Le format reste le conventionnel déjà en place — `feat(module):`, `fix(module):`, `refactor:`,
`chore:`, `docs:`, `test:` — corps en français, à l'impératif, qui explique le *pourquoi* et pas
le *quoi* (le diff le dit déjà).

Identity, Training, Progression et le **premier jouable** (import santé → `SyncSummary`) sont
faits ; le reste est ouvert sur le tableau, dans l'ordre Streak → Loot → Arbres → Classements →
durcissement. Strava arrive après, comme simple source supplémentaire.

[ARCHITECTURE.md](ARCHITECTURE.md) est la vue d'ensemble, **en schémas** : la carte des modules et
de leurs frontières, la vie d'un import, la transaction d'import, le modèle de données,
l'authentification, la chaîne du config-as-code. C'est ce qu'on ouvre pour comprendre le système
tel qu'il est aujourd'hui — pas pour savoir ce qui reste à faire, ça vit sur le tableau.

**Un choix structurant s'écrit dans le docblock du fichier qu'il concerne, et dans le corps de la
PR.** Pas dans un fichier de suivi à part : celui-là devient une troisième copie qui vieillit sans
que personne s'en aperçoive — c'est ce qui est arrivé à `PROGRESS.md`, supprimé au ticket 68.
`ARCHITECTURE.md` ne reçoit que ce qui change la **forme** du système, et deux sections courtes :
les décisions contre-intuitives qu'on « corrigerait » dans le mauvais sens, et les pièges qui se
reproduisent.

Le mapping Doctrine n'a **pas** d'`auto_mapping` : chaque module déclare le sien dans
`config/packages/doctrine.yaml` au moment de son lot. Idem pour les layers Deptrac, déjà déclarés
dans `deptrac.yaml` pour les sept modules.
