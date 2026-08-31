---
name: deploy
description: Déploie le back sur Fly.io (app `grrind-back`) et joue les migrations Doctrine contre la base Supabase. À utiliser dès que l'utilisateur demande de déployer, de release, de mettre en prod, de pousser en prod, ou de jouer les migrations en production. Contient les deux commandes exactes, l'ordre dans lequel elles doivent passer, et les pièges qui ont déjà coûté du temps.
---

# Déployer GRRIND sur Fly.io

**Deux commandes, dans cet ordre.** Le reste de ce fichier explique pourquoi elles sont
ce qu'elles sont — la version courte tient en dix lignes, mais chaque piège en dessous a
déjà été payé une fois.

```bash
flyctl deploy -a grrind-back
flyctl machine start <ID>   # seulement si aucune machine n'est démarrée, cf. plus bas
flyctl ssh console -a grrind-back -C "php bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration --query-time --no-debug"
```

Puis un test de fumée (voir la dernière section). **Un déploiement n'est pas fini tant
qu'une requête réelle n'a pas répondu** : `fly deploy` dit seulement que des machines ont
démarré, pas que l'app sert quoi que ce soit.

## Confirmer avant de lancer

`fly deploy` est une action de production sortante. Ne la déclenche pas sur une intention
vague (« il faudrait déployer un jour ») : demande confirmation, sauf si l'utilisateur a
dit explicitement de lancer. L'accord donné une fois ne vaut pas pour le déploiement
suivant.

## Le contexte, en une carte

| Quoi | Où |
|---|---|
| App | `grrind-back`, région **`cdg`** — Paris, parce que l'auteur et ses testeurs sont en France. `primary_region` de `fly.toml` fait foi |
| Image | construite par `fly deploy` depuis le stage `prod` du `Dockerfile` |
| Base | **Supabase**, pas Fly. `fly mpg list` et `fly postgres list` ne rendent rien, c'est normal |
| Secrets | `APP_SECRET`, `DATABASE_URL`, `JWT_PASSPHRASE`, `JWT_SECRET_KEY`, `JWT_PUBLIC_KEY` — déjà posés, à vérifier avec `flyctl secrets list -a grrind-back` |
| URL | https://grrind-back.fly.dev |

Les clés JWT sont des **secrets portant le PEM brut**, pas des fichiers montés :
`lexik_jwt_authentication` accepte l'un comme l'autre. `.dockerignore` exclut
`config/jwt/` de l'image, et c'est voulu.

## Deux groupes de processus depuis le #153

`flyctl machine list -a grrind-back` rend **deux machines**, une par groupe : `app`
(FrankenPHP, sert le trafic HTTP) et `worker` (`messenger:consume outbox`, consomme l'outbox
Messenger). `flyctl ssh console` sans `-C` ou une commande visant explicitement une machine
peut tomber sur l'une ou l'autre — préciser l'ID avec `--machine` quand la distinction compte.

**Le compte d'un groupe est un état de scaling, pas une ligne de `fly.toml`.** C'est ce qui a
fait apparaître un *second* worker sans que personne ne l'écrive : Fly en crée deux par défaut
quand un groupe de processus naît. Ramené à 1 le 2026-08-27 (#185) — un seul consommateur
suffit, et deux sur la même outbox n'apportent qu'une concurrence dont personne n'a besoin ici.
`flyctl scale show -a grrind-back` est la seule source de vérité sur ce compte.

Le groupe `worker` **tourne en permanence** (`[[restart]] policy = 'always'` scopé à
`processes = ['worker']` dans `fly.toml`) : `min_machines_running` du `[http_service]` ne
s'applique qu'à `app`, `worker` n'a pas de `[http_service]` du tout. Ce n'est pas une
incohérence — `stale_window_minutes` et `announcement_delay_seconds` d'`AnnounceGuildActivity`
sont calibrés pour un consommateur vivant, pas pour une consommation périodique. Voir le
commentaire de `fly.toml` et le #153 pour l'arbitrage complet.

**Depuis le #188, les deux groupes tournent en permanence**, pour deux raisons différentes :
`worker` par `[[restart]] policy = 'always'`, `app` par `min_machines_running = 1`. Une machine
`app` arrêtée n'est donc plus l'état normal — c'est le signe qu'il s'est passé quelque chose.

`messenger:consume` sort avec le code 0 quand `--time-limit`/`--memory-limit` est atteint
(comportement documenté par Symfony, pas un bug) : sans la section `[[restart]]`, la
politique par défaut de Fly (`on-failure`) ne l'aurait jamais relancé, et le worker se
serait arrêté pour de bon au bout d'une heure.

## Les six pièges

**1. `min_machines_running` empêche d'arrêter, il ne démarre pas — donc `fly deploy` peut
laisser l'`app` éteinte.** C'est le piège du #188, constaté au déploiement qui le fermait :
`flyctl deploy` a rendu `Machine ... reached stopped state` pour le groupe `app` et l'a
considéré comme *a good state*, puis fly-proxy l'a laissée arrêtée indéfiniment. Le seuil est
appliqué au moment où le proxy **arrête** des machines ; il ne rallume jamais une machine déjà
éteinte pour remonter au minimum. Il a fallu un `machine start` unique pour l'amorcer.

**Après chaque `fly deploy`, vérifier l'état du groupe `app` et le démarrer s'il est arrêté.**
Sans ce geste, le déploiement remet silencieusement le démarrage à froid de 21 s en place et
rouvre le #188 sans que rien ne le signale — le déploiement, lui, s'est déclaré réussi.

```bash
flyctl status -a grrind-back                # `app` doit être `started`
flyctl machine start <ID> -a grrind-back    # une seule fois ; ensuite le proxy ne l'arrête plus
```

Une fois amorcée, elle tient : vérifié 12 minutes sans une seule requête, toujours `started`,
et `/health` à 276 ms au réveil contre 21 s à froid.

Le corollaire vaut aussi. Avant le #188, `flyctl ssh console` échouant avec *app grrind-back
has no started VMs* était banal — le groupe s'éteignait entre deux requêtes. Ce n'est plus le
cas : hors du cas ci-dessus, ce message veut dire que la machine est tombée pour une vraie
raison, et `flyctl logs` se regarde avant de la relancer.

**2. `make migrate-prod` ne marchera pas depuis un poste de dev.** La connexion directe à
Supabase est **IPv6 uniquement**, et la cible du Makefile passe par la machine locale :
elle échoue en *Network is unreachable* sur un réseau sans sortie IPv6. C'est pour ça que
les migrations passent par `flyctl ssh console` — la machine Fly, elle, a de l'IPv6. La
cible du Makefile reste juste sur le principe (migrer depuis l'image de prod, pour que ce
qui migre soit exactement le code qui va lire) ; c'est seulement le chemin réseau qui ne
convient pas ici.

**3. `doctrine/doctrine-migrations-bundle` est en `require`, pas en `require-dev`.** C'est
ce qui rend la commande disponible dans une image bâtie `--no-dev`. Si un jour elle
bascule en dev, la migration échouera en prod avec *command not found* alors que tout
marche en local — le même piège que `phpdocumentor/reflection-docblock` au #113.

**4. `fly.toml` s'est contredit sur la mémoire, une fois.** Le bloc `[[vm]]` portait à la
fois `memory = '1gb'` et `memory_mb = 256`, et c'était `memory_mb` qui gagnait : les
machines tournaient en `shared-cpu-1x:256MB`, serré pour FrankenPHP en mode worker avec
preload opcache. Passé à **512MB** le 2026-08-18 (les deux champs s'accordent maintenant),
parce qu'un Symfony qui tourne le justifiait. Si l'app démarre puis meurt, `flyctl logs
-a grrind-back` avant de chercher ailleurs.

**5. `scale count` à la baisse détruit la machine qui *tourne*, pas celle qui dort.**
`flyctl scale count worker=1` a détruit la machine démarrée et gardé l'arrêtée : l'app s'est
retrouvée avec son unique worker à l'arrêt, sans que la commande signale rien — elle avait fait
ce qu'on lui demandait. L'outbox aurait gonflé en silence, la panne du #153 en plus discret,
puisque cette fois `flyctl scale show` affiche bien `worker │ 1`.

Après **tout** `scale count` à la baisse : relire `flyctl machine list`, démarrer la survivante
si elle est arrêtée, et vérifier qu'elle **consomme** — pas seulement qu'elle est `started`. La
seule vérification qui prouve quelque chose est un import réel suivi de `messenger:stats` : une
machine démarrée qui ne consomme pas rend exactement le même `outbox 0` qu'une machine qui
consomme, tant que personne ne produit de message.

**6. Le dashboard Fly ouvre une PR automatique après un scale fait depuis l'UI**
(`flyio-scale-from-ui`, auteur `app/fly-io`), pour resynchroniser `fly.toml`. **Ne pas la
merger telle quelle** : elle ajoute un *second* bloc `[[vm]]` scopé par `processes` au lieu
de modifier celui qui existe, et repart de la dernière version de `main` — donc écrase
silencieusement un changement local pas encore commité (ça nous est arrivé avec le passage
en région `cdg`, fait juste avant). Le geste correct est de reporter la valeur qu'elle
contient (mémoire, taille de VM) à la main dans le bloc `[[vm]]` existant, de fermer la PR
du bot sans la merger, et de committer depuis le poste de dev comme d'habitude.

## Ce qu'on ne fait pas (encore)

**Pas de `[deploy] release_command` dans `fly.toml`.** Ce serait le geste idiomatique —
Fly joue la migration dans une machine temporaire avant que les nouvelles prennent du
trafic, et un échec interrompt le déploiement, ce qui est mot pour mot ce que décrit le
docblock de `make migrate-prod`. Il n'y est pas parce que la chaîne de déploiement
définitive se décide au **Lot 9 — Durcissement** (cible ECS Fargate, cf. README). Si
l'utilisateur le demande, ça mérite un ticket, pas une ligne glissée en passant.

## Ce que ça coûte

**Deux `shared-cpu-1x` de 512 Mo tournent en permanence depuis le #188**, une par groupe : le
`worker` (#153) et l'`app` (#188). La facture Fly de cet environnement de dev *est* ce couple —
c'est pour ça que le second worker était du gaspillage pur, pas un détail, et c'est la même
grille qui rend le passage de `app` à une machine toujours allumée négligeable : le doublement
porte sur une base déjà minuscule, et il achète l'import qui arrive quand la séance a lieu.

Ce qui reste comme leviers, par ordre décroissant de gain et croissant de risque : la taille des
machines (512 MB aujourd'hui, alors que `messenger:consume` s'auto-limite à 128 MB côté PHP),
puis la fréquence de consommation — mais celle-là a été tranchée au #153 et la rouvrir défait un
arbitrage, elle ne l'optimise pas. **Le nombre de machines allumées, lui, n'est plus un levier** :
les deux le sont pour une raison écrite, et les remettre à zéro rouvre le #153 ou le #188.

## Le test de fumée

Le minimum, qui vérifie que le firewall, la base et le contrat répondent :

```bash
curl -sS https://grrind-back.fly.dev/health
# {"status":"ok","checks":{"database":"up"}}
```

`/health` interroge la base : un `database: down` ici dit que `DATABASE_URL` est
faux ou que Supabase ne répond pas, avant même qu'on parle de migrations.

Pour un déploiement qui touche une feature, aller plus loin : inscrire un compte
(`POST /api/auth/register`, qui rend le profil **et** la paire de jetons), puis appeler la
route concernée avec le `accessToken`. Un exemple complet du parcours guilde — fonder,
générer un code, rejoindre, lister les membres — est reproductible depuis les tests
fonctionnels de `tests/Community/`.

**Vérifier aussi un refus, pas seulement un succès.** Les décisions les plus coûteuses du
projet sont des 404 là où on attendrait un 403 : un déploiement qui rendrait 403 sur
`GET /api/players/{uuid-inconnu}` serait cassé sans qu'aucun chemin nominal ne le montre.

**Vérifier que le worker consomme, pas seulement qu'il est démarré.** C'est le symptôme qui
a produit le #153 : un `outbox` qui gonfle sans jamais se vider passe inaperçu tant que
personne ne le regarde.

```bash
flyctl ssh console -a grrind-back -C "php bin/console messenger:stats"
```

`outbox` doit revenir à `0` après un import réel (ou rester bas et stable) — s'il grossit
d'un déploiement à l'autre, le groupe `worker` n'est pas en train de consommer, qu'il soit
démarré ou non.

## Nettoyage

Un test de fumée crée des comptes réels dans la base de prod. La base est jetable en phase
alpha, mais **le dire à l'utilisateur** plutôt que de laisser des `bob+1234@grrind.app`
s'accumuler sans qu'il le sache.
