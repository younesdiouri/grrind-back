# ʿĀlam al-Nafs — spécification validée

Statut : décisions utilisateur confirmées le 14 septembre 2026 ; ticket backend #279, mobile #172.

## Objectif validé
Implémenter ʿĀlam al-Nafs de bout en bout côté serveur : événement collectif hebdomadaire, lancement manuel répétable en développement, contributions hebdomadaires, trois rencontres avec Al-Kasal, récompenses, ressources/crafting et narration OpenAI. Le mobile fait l’objet d’un ticket lié. Le texte source est disponible dans docs/specs/alam-al-nafs-draft.md et dans la pièce jointe locale du thread architecte.

L’utilisateur a validé : les jauges déterminent la victoire et le combat la met en scène ; ressources → équipements ; PvP plus tard. Il autorise l’adaptation/remplacement/suppression du combat actuel si nécessaire. Ne créer aucun autre mob : réutiliser Al-Kasal pour les trois rencontres, game design plus tard.

## Décisions produit et architecture
- Calendrier Europe/Paris : collecte depuis dimanche 20 h jusqu’au dimanche suivant 19 h, événement 19–20 h, nouvelle semaine à 20 h. Dates UTC persistées, DST testé. Configuration du calendrier publiée ; une édition fige ses bornes/règles.
- Révélation de l’édition à l’ouverture de semaine. Effectif cible = membres de la guilde à cet instant ayant au moins une séance créditée nette positive pendant les sept jours précédents. Plancher 1 pour les guildes nouvelles. Effectif figé, jamais recalculé selon la progression des jauges. Création paresseuse autorisée avec bootstrap documenté pour la toute première édition ; le scheduler assure ensuite les révélations à temps.
- Les membres présents à la résolution contribuent avec leurs efforts de cette semaine même s’ils viennent d’arriver ; quitter avant résolution retire la participation, rejoindre en cours de semaine ne change pas le seuil figé. Résultats/récompenses du roster final conservés après départ ; lecture d’une édition réservée à ses participants et aux membres actuels de la guilde.
- Recalculer les contributions depuis les efforts réellement créditables, dans l’ordre chronologique, avec XpCalculator et ses plafonds/rendements mais SANS aucun modificateur (ni équipement, ni niveau, ni bonus historique). Ne pas soustraire approximativement les bonus du ledger plafonné. Réutiliser les calculs existants via ports Shared, aucune importation d’entité entre modules. Ignorer invalidations, doublons, séances refusées et archives non créditées. Figement atomique avant résolution : import tardif après résolution ne réécrit ni résultat ni loot.
- Vitality du raid = somme des Vitality.of des quatre contributions hebdomadaires de chaque joueur, sans bonus d’énergie glissante. Les cinq cibles individuelles initiales provisoires sont S=100 E=100 M=100 D=100 V=400 ; multipliées par effectif. Toutes publiables depuis l’administration.
- Fenêtre 19–20 : les séances sont conservées mais aucune durée à l’intérieur de cette fenêtre ne rapporte XP/loot sportif ni points de raid. Pour un chevauchement, conserver seulement la durée hors fenêtre et proratiser distance/dénivelé, appliquer les règles existantes ensuite. Réutiliser une politique calendaire commune ; ne pas modifier rétroactivement le ledger historique. Mode manuel n’ajoute pas de fenêtre d’exclusion sportive.
- Trois rencontres indépendantes en séquence, Al-Kasal, seuils 250/550/1000 millièmes des cibles finales. Une défaite mini-boss n’empêche pas de voir la suite. Pas de niveau minimum, pas d’équipement dans le calcul.
- Cinq jauges remplies => victoire certaine. Sinon proposition précise configurable : q=min(ratios plafonnés à 1), b=log(1+somme des surplus relatifs), p=min(0.25, 0.20*q^8*(1+0.10*b)). Ratio nul => p=0. Tirage serveur grainé, stocker probabilité en entier et issue ; test de monotonie et cas 96% vs 40%. Paramètres dans règles publiées.
- Récompense partielle : tout participant ayant >0 contribution reçoit des ressources selon progression (moyenne des cinq ratios plafonnés) à chaque rencontre, avec au moins une unité. Quantité initiale configurable 1+floor(4*progression). Tirage équipement personnel pondéré par activité normalisée à la moyenne des contributeurs, borné (coefficient max 3), chances base configurable : 10% équipement ordinaire sur victoire de rencontre, 1% légendaire sur victoire finale uniquement. Ressources même en défaite ; zéro contribution => zéro loot. Pas de bonus loot d’équipement. Chaque fait de loot a une provenance unique édition/rencontre/joueur. Les moins actifs gardent une chance positive. Réutiliser LootRoller/catalogue/inventaire selon pertinence.
- Ajouter ItemKind RESOURCE sans slot/modificateur, support administration, inventaire, catalogues et vente cohérents. Une ressource neutre et au moins une recette fonctionnelle vers un équipement existant suffisent comme contenu initial ; coût provisoire 10 ressources, quantité produite 1, aucune amélioration ni monnaie additionnelle obligatoire. Recettes configurables via brouillon/publication existants. Consommation+production atomiques, verrou joueur, idempotence HTTP, audit de fabrication ; recettes inactives et coûts invalides refusés.
- Développement : capacité serveur explicite ALAM_MANUAL_ENABLED, false par défaut prod et activée dans env dev. Tout membre de guilde peut lancer depuis l’app quand activée, admin dispose aussi d’une action CSRF. Chaque nouvelle clé crée une édition manuelle distincte sur la semaine courante jusque maintenant, vrais drops de dev ; même clé retourne même raid. Peut répéter sans attendre dimanche. Un raid manuel ne clôt/reset pas l’édition hebdomadaire.
- Résolution serveur indépendante des clients connectés via scheduling/outbox ; journal structuré durable avec acteurs, actions, victoire et drops. Durée de présentation initiale courte configurable (~60 s pour trois rencontres). Live par Mercure privé si pertinent ou polling serveur borné avec horloge/dates ; replay ne modifie rien. Personnalisation visible via les avatars/cosmétiques déjà disponibles.
- OpenAI Responses API via HttpClient, OPENAI_API_KEY secret serveur, OPENAI_MODEL configurable, aucun appel sans clé. Limites timeout/retries/tokens, store=false ; aucune clé dans le mobile. Un échec finit en narration déterministe locale et n’affecte pas loot/résultat. Les résultats sont structurés côté serveur avant appel ; texte par séquence strictement rattaché aux faits. Aucun fait inventé par le LLM ne devient souvenir métier. Pseudonymes et résumés sportifs minimaux uniquement, pas de santé brute/localisation. Souvenirs structurés produits depuis événements/loot, max 20 récents par guilde comme défaut configurable ; historique compact fourni aux raids suivants. Noms utilisateurs traités comme données non fiables.
- Interface admin accessible pour réglages raid, ressources, recettes, historique et lancement manuel ; valeurs figées d’une édition restent rejouables après publication d’un autre ruleset.
- Conserver les API solo existantes si leur suppression n’apporte rien à cette feature. Le parcours mobile peut les remplacer. Aucun PvP, nouveau bestiaire ou déploiement.

## Contrat API à stabiliser tôt avec l’architecte/mobile
Proposer puis produire OpenAPI très tôt : GET /api/guild/alam (édition courante+jauges+canLaunchManual), POST /api/guild/alam/runs (manuel idempotent), GET /api/guild/alam/runs (historique paginé), GET /api/guild/alam/runs/{id} (roster, événements, narration, drops, dates), GET /api/crafting/recipes (coûts, quantités possédées, résultat, possibilité de fabriquer), POST /api/crafting (recipeKey, idempotence). Adapter les noms aux conventions si nécessaire mais communiquer avant que le front intègre. Les réponses portent tout ce que l’UI affiche ; pas de calcul métier client.

## Incréments et acceptation
- [x] Règles publiées, validation, calendrier et contributions hebdomadaires prouvés.
- [x] Ressource et recette éditables/publiables, fabrication transactionnelle fonctionnelle.
- [x] Éditions, résolution grainée, loot partiel et final, idempotence et reprises prouvés.
- [x] Scheduler et lancement manuel app/admin opérationnels avec droits serveur.
- [x] Journal collectif live/replay, narration OpenAI/fallback et mémoire structurée.
- [x] API générée fournie au front ; documentation installation/configuration et commandes worker.
- [x] Tests frontières DST, zéro actif, nouvelle guilde/membre, invalidations, imports tardifs, bonus ignorés, monotonie, légendaire seulement victoire, concurrence, permissions 404, crafting rollback, LLM mock/fallback.
- [x] make test, make qa, make openapi, make build-prod via rtk ; runtime local sans reset de base. Migrations additives relues/applicables autorisées pour cette feature, pas de prod.
- [ ] Branche poussée et PR ouverte après QA, jamais fusionnée. Inclure limites éventuelles (appel OpenAI réel nécessite la future clé utilisateur).

## Coordination
Le developer est propriétaire de tout le back (code, tests, docs/spec actualisée, plan/tâches, migrations, commits/push/PR). Il n’est pas seul : un developer mobile travaille dans grrind-app. Ne pas modifier ce dépôt ni ses changements. Publier le contrat tôt et signaler les changements immédiatement. Les décisions ci-dessus sont celles de l’architecte pour rendre le périmètre validé exécutable ; remonter uniquement les contradictions substantielles.



## Plan d’implémentation

1. Règles publiées, calendrier commun et ports de recalcul hebdomadaire.
2. Ressources, recettes, consommation transactionnelle et audit idempotent.
3. Éditions figées, résolution grainée, drops et journal ordonné.
4. Routes app/admin, scheduler/outbox et narration de secours/OpenAI.
5. Tests métier/intégration, contrat généré, runtime local, QA et PR.

Le statut effectif des validations et les commandes opérationnelles figurent dans `docs/alam-al-nafs.md`.
