# ʿĀlam al-Nafs

Le raid mesure uniquement les efforts de la semaine. Les cinq jauges décident du résultat ; le journal fait apparaître ces faits dans trois rencontres contre Al-Kasal. Les paramètres initiaux sont provisoires et se modifient dans Administration → Réglages globaux → Alam, puis se publient depuis le brouillon.

## Fonctionnement

Une édition hebdomadaire est révélée à l’ouverture de semaine (dimanche 20 h Europe/Paris). La première exécution amorce paresseusement la semaine en cours, avec le roster disponible à cet instant ; elle ne recrée pas des semaines antérieures sans roster historique. Ensuite le scheduler révèle les éditions et résout celles dont la borne persistée est passée. Les dates stockées sont UTC. Le calendrier utilise des jours civils pour préserver les heures aux changements DST.

La résolution verrouille la guilde et les lignes de progression des participants, dans un ordre déterministe. Les séances valides sont rejouées chronologiquement sans aucun modificateur, avec les plafonds et rendements du snapshot de l’édition. Les résultats, inventaires et demande de narration partagent le COMMIT. Une nouvelle importation ne réécrit pas un résultat. Le roster figé conserve le droit de consulter le replay après départ.

La fenêtre sportive du dimanche 19–20 h exclut la durée correspondante et prorate distance/dénivelé. Les séances restent conservées. Aucun ledger antérieur n’est réécrit. Le mode manuel n’ajoute pas de fenêtre sportive.

## Local et configuration

- `ALAM_MANUAL_ENABLED=0` par défaut ; `.env.dev` l’active en développement. Une variable d’environnement explicite peut le désactiver.
- `OPENAI_API_KEY` : clé API serveur uniquement, vide par défaut. Ajouter sa valeur dans `.env.local`, jamais dans Git ni dans le mobile.
- `OPENAI_MODEL=gpt-4.1-mini` : modèle configurable compatible Responses et sorties structurées.
- `rtk make migrate` applique les migrations additives.
- `rtk make up` démarre HTTP et le worker ; celui-ci consomme `outbox` et `scheduler_alam`.
- En avant-plan : `rtk make console c="messenger:consume outbox scheduler_alam -vv"`.
- Pour déclencher le battement sans attendre, consommer `scheduler_alam` ; les échéances sont celles des éditions stockées, pas l’heure de connexion de l’app.

L’administration `/admin/alam` expose l’historique et un formulaire CSRF de lancement manuel. Depuis l’app, tout membre peut lancer si la capacité est active. Deux clés d’idempotence distinctes créent deux éditions ; le replay d’une clé retrouve le résultat et n’ajoute aucun loot. Une édition manuelle ne ferme pas la semaine.

## API et présentation

`GET /api/guild/alam`, `POST /api/guild/alam/runs`, `GET /api/guild/alam/runs`, `GET /api/guild/alam/runs/{id}`, `GET /api/crafting/recipes`, `POST /api/crafting` sont documentés dans `openapi.yaml`, généré par `rtk make openapi`. Les POST demandent `Idempotency-Key`. Le polling conseillé vaut 5 secondes ; `serverNow`, `presentationStartsAt`, `presentationEndsAt` et les événements `offsetMs` permettent une présentation commune et un replay sans effet métier.

Les ressources se cumulent dans l’inventaire. La recette initiale consomme dix Essences du Nafs pour produire un équipement existant. Les recettes et ressources appartiennent au brouillon publié, aucun coût n’est une constante du moteur. Aucune amélioration d’objet ni PvP dans cette livraison.

## Narration

Un récit local existe dès la résolution. Le worker demande ensuite trois textes à OpenAI uniquement si une clé est définie. Un timeout, un refus, un quota dépassé ou une sortie mal formée conserve le texte local. Le réseau reste hors de la transaction de loot, timeout 8 s et durée totale 12 s, sans boucle de retry applicative ; l’outbox a trois reprises pour les pannes de persistance. `store=false`, limite 700 tokens de sortie. Les pseudonymes sont des données non fiables.

La mémoire est une lecture bornée des éditions précédentes et de leurs résultats/drops structurés. Aucun texte LLM ne devient un fait de jeu. Aucune fréquence cardiaque, calorie ou localisation n’est envoyée. La validation réelle du fournisseur attend la future clé utilisateur ; les tests utilisent MockHttpClient.

## Vérification

Tests : frontières DST et fenêtre sportive ; probabilité garantie, monotonie et déficits ; zéro contribution ; recalcul hors bonus ; imports tardifs ; roster et permissions ; idempotence/reprise ; rollback crafting ; publication ; réponses OpenAI et fallback. QA dépôt : `rtk make test`, `rtk make qa`, `rtk make openapi`, `rtk make build-prod`.

## Validation du 14 septembre 2026

`rtk make test` : 1 391 tests, 26 349 assertions. `rtk make qa` : PHPStan, CS et Deptrac réussis. `rtk make openapi` et `rtk make build-prod` réussis. Migrations additives appliquées localement sans reset ; scénario mobile réel : lancement, 14 essences, replay sans nouveau raid, fabrication des gantelets.

Le mapping Doctrine est valide. La comparaison complète du schéma relève une dérive préexistante (défauts, index et contraintes hors périmètre) ; le diff relu ne contient aucune différence sur les nouvelles tables. Aucun diff hors périmètre appliqué. L’appel OpenAI réel reste à vérifier avec la future clé ; les réponses, refus, délais et fallback sont couverts par mocks.
