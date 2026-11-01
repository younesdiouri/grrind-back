# ʿĀlam al-Nafs

Le raid mesure uniquement les efforts de la semaine des joueurs inscrits. Les cinq jauges décident du résultat d’un seul combat contre Al-Kasal. Le chef choisit facile (25 %), intermédiaire (55 %) ou difficile (100 % des cibles complètes) ; chaque difficulté dispose de ses récompenses. Les paramètres initiaux sont provisoires et se modifient dans Administration → Réglages globaux → Alam, puis se publient depuis le brouillon.

## Fonctionnement

Une édition hebdomadaire est révélée à l’ouverture de semaine (dimanche 20 h Europe/Paris). La première exécution amorce paresseusement la semaine en cours, avec le roster disponible à cet instant ; elle ne recrée pas des semaines antérieures sans roster historique. Ensuite le scheduler révèle les éditions et résout celles dont la borne persistée est passée. Les dates stockées sont UTC. Le calendrier utilise des jours civils pour préserver les heures aux changements DST.

Chaque joueur s’inscrit personnellement ; l’inscription persiste entre les semaines et disparaît avec son adhésion à la guilde. Seuls les inscrits contribuent et deviennent éligibles aux récompenses, selon leur contribution effective. L’effectif de référence reste celui des membres actifs de la guilde, figé à la révélation.

Chaque nouvelle édition commence en facile. Le chef peut choisir une difficulté avant dimanche 19 h Europe/Paris. `difficultyOptions` fournit les trois probabilités (`probabilityMillionths`, de 0 à 1 000 000) et leurs jauges. La résolution utilise exactement la même formule ; remplir toutes les jauges de la difficulté choisie garantit la victoire. Le résultat et les prévisions de clôture sont figés pour le replay.

La résolution verrouille la guilde et les lignes de progression des participants, dans un ordre déterministe. Les séances valides sont rejouées chronologiquement sans aucun modificateur, avec les plafonds et rendements du snapshot de l’édition. Les résultats, inventaires et demande de narration partagent le COMMIT. Une nouvelle importation ne réécrit pas un résultat. Le roster figé conserve le droit de consulter le replay après départ.

Les éditions hebdomadaires se résolvent automatiquement à 19 h, même sans connexion. Une inscription modifiée après la clôture ne change pas le combat terminé ; le prochain raid utilisera le nouvel état. Le rattrapage règle les éditions échues avant une modification d’inscription et avant la révélation suivante.

La fenêtre sportive du dimanche 19–20 h exclut la durée correspondante et prorate distance/dénivelé. Les séances restent conservées. Aucun ledger antérieur n’est réécrit. Le mode manuel n’ajoute pas de fenêtre sportive.

## Caractéristiques hebdomadaires et équipement (#332)

Force, endurance, mobilité, dextérité et vitalité repartent à zéro chaque dimanche à 20 h Europe/Paris, pour tout joueur, en PvE comme en PvP. L’XP totale, le niveau, les titres/compétences et l’inventaire restent acquis. La vitalité garde son calcul actuel à partir des quatre caractéristiques de la semaine, avec son bonus d’énergie active pour les lectures du personnage.

Le ledger reste append-only. Les agrégations de caractéristiques utilisent la semaine de `occurred_at`, donc la date du sport. Un ancien import, une annulation ou un supplément Sport ensemble ne remplit pas la nouvelle semaine. Les résumés animent le delta des jauges courantes, qui peut valoir zéro malgré un crédit d’XP historique. Le cache de caractéristiques expire à la lecture sans écriture pendant un GET ; il est reprojeté sous verrou à la prochaine écriture. Les dates d’une opération de crédit sont cohérentes même si elle traverse l’heure du reset.

Les objets n’ajoutent plus de caractéristiques de base. Critique, esquive, mitigation, maintien, garde, résistance critique, réduction du délai, combo et précision proviennent uniquement des objets équipés, avec les plafonds publiés. Les formules de PV/dégâts et leurs bonus directs restent inchangés. Les publications actives et le brouillon perdent les anciens bonus de caractéristiques ; aucun transfert arbitraire de leurs valeurs vers de nouveaux taux. L’équilibrage final reste à faire.

Les anciennes éditions résolues gardent leurs trois rencontres, avec `difficulty: null` et `difficultyOptions: []`. Les nouvelles éditions ont une seule rencontre et une difficulté explicite. Les publications et récompenses historiques restent intactes.

## Local et configuration

- `ALAM_MANUAL_ENABLED=0` par défaut ; `.env.dev` l’active en développement. Une variable d’environnement explicite peut le désactiver.
- `OPENAI_API_KEY` : clé API serveur uniquement, vide par défaut. Ajouter sa valeur dans `.env.local`, jamais dans Git ni dans le mobile.
- `OPENAI_MODEL=gpt-4.1-mini` : modèle configurable compatible Responses et sorties structurées.
- `rtk make migrate` applique les migrations additives.
- `rtk make up` démarre HTTP et le worker ; celui-ci consomme `outbox` et `scheduler_alam`.
- En avant-plan : `rtk make console c="messenger:consume outbox scheduler_alam -vv"`.
- Pour déclencher le battement sans attendre, consommer `scheduler_alam` ; les échéances sont celles des éditions stockées, pas l’heure de connexion de l’app.

L’administration `/admin/alam` expose l’historique et un formulaire CSRF de lancement manuel. Depuis l’app, tout membre peut lancer si la capacité est active. Deux clés d’idempotence distinctes créent deux éditions ; le replay d’une clé retrouve le résultat et n’ajoute aucun loot. Une édition manuelle ne ferme pas la semaine.

Au premier déploiement du #332, après les migrations, exécuter `app:progression:rebuild` depuis une machine Fly. Cette reprojection recalcule les caches existants avec la période hebdomadaire, en conservant l’XP historique ; vérifier ensuite `app:progression:rebuild --dry-run`.

## API et présentation

`GET /api/guild/alam`, `PUT /api/guild/alam/registration` (`registered: boolean`), `PUT /api/guild/alam/difficulty` (`difficulty: EASY | INTERMEDIATE | HARD`), `POST /api/guild/alam/runs`, `GET /api/guild/alam/runs`, `GET /api/guild/alam/runs/{id}`, `GET /api/crafting/recipes`, `POST /api/crafting` sont documentés dans `openapi.yaml`, généré par `rtk make openapi`. Les mutations Ālam demandent `Idempotency-Key`. L’état courant expose `registered` et `canChooseDifficulty`, et l’édition expose `difficulty` et `difficultyOptions`. Un membre ordinaire reçoit 403 sur le choix de difficulté ; un joueur sans guilde reçoit 404 ; un choix après clôture reçoit 409. Le polling conseillé vaut 5 secondes ; `serverNow`, `presentationStartsAt`, `presentationEndsAt` et les événements `offsetMs` permettent une présentation commune et un replay sans effet métier.

Les ressources se cumulent dans l’inventaire. La recette initiale consomme dix Essences du Nafs pour produire un équipement existant. Les recettes et ressources appartiennent au brouillon publié, aucun coût n’est une constante du moteur. Aucune amélioration d’objet ni PvP dans cette livraison.

## Narration

Un récit local existe dès la résolution. Le worker demande ensuite un texte par rencontre à OpenAI (un pour les nouvelles éditions, trois pour les anciennes) uniquement si une clé est définie. Un timeout, un refus, un quota dépassé ou une sortie mal formée conserve le texte local. Le réseau reste hors de la transaction de loot, timeout 8 s et durée totale 12 s, sans boucle de retry applicative ; l’outbox a trois reprises pour les pannes de persistance. `store=false`, limite 700 tokens de sortie. Les pseudonymes sont des données non fiables.

La mémoire est une lecture bornée des éditions précédentes et de leurs résultats/drops structurés. Aucun texte LLM ne devient un fait de jeu. Aucune fréquence cardiaque, calorie ou localisation n’est envoyée. La validation réelle du fournisseur attend la future clé utilisateur ; les tests utilisent MockHttpClient.

## Vérification

Tests : frontières DST et fenêtre sportive ; probabilité garantie, monotonie et déficits ; zéro contribution ; recalcul hors bonus ; imports tardifs ; roster et permissions ; idempotence/reprise ; rollback crafting ; publication ; réponses OpenAI et fallback. QA dépôt : `rtk make test`, `rtk make qa`, `rtk make openapi`, `rtk make build-prod`.

## Validation historique de la livraison initiale, 14 septembre 2026

`rtk make test` : 1 391 tests, 26 349 assertions. `rtk make qa` : PHPStan, CS et Deptrac réussis. `rtk make openapi` et `rtk make build-prod` réussis. Migrations additives appliquées localement sans reset ; scénario mobile réel : lancement, 14 essences, replay sans nouveau raid, fabrication des gantelets.

Le mapping Doctrine est valide. La comparaison complète du schéma relève une dérive préexistante (défauts, index et contraintes hors périmètre) ; le diff relu ne contient aucune différence sur les nouvelles tables. Aucun diff hors périmètre appliqué. L’appel OpenAI réel reste à vérifier avec la future clé ; les réponses, refus, délais et fallback sont couverts par mocks.
