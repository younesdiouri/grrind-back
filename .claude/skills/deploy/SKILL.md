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
| App | `grrind-back`, région `iad` |
| Image | construite par `fly deploy` depuis le stage `prod` du `Dockerfile` |
| Base | **Supabase**, pas Fly. `fly mpg list` et `fly postgres list` ne rendent rien, c'est normal |
| Secrets | `APP_SECRET`, `DATABASE_URL`, `JWT_PASSPHRASE`, `JWT_SECRET_KEY`, `JWT_PUBLIC_KEY` — déjà posés, à vérifier avec `flyctl secrets list -a grrind-back` |
| URL | https://grrind-back.fly.dev |

Les clés JWT sont des **secrets portant le PEM brut**, pas des fichiers montés :
`lexik_jwt_authentication` accepte l'un comme l'autre. `.dockerignore` exclut
`config/jwt/` de l'image, et c'est voulu.

## Les quatre pièges

**1. Les machines sont arrêtées la plupart du temps.** `min_machines_running = 0` et
`auto_stop_machines = 'stop'` : `flyctl ssh console` échoue alors avec *app grrind-back
has no started VMs*. Ce n'est **pas** un déploiement raté. Démarrer une machine avant :

```bash
flyctl machine list -a grrind-back          # relever un ID
flyctl machine start <ID> -a grrind-back
```

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

**5. Le dashboard Fly ouvre une PR automatique après un scale fait depuis l'UI**
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

## Nettoyage

Un test de fumée crée des comptes réels dans la base de prod. La base est jetable en phase
alpha, mais **le dire à l'utilisateur** plutôt que de laisser des `bob+1234@grrind.app`
s'accumuler sans qu'il le sache.
