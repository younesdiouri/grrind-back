# Vente des équipements — #271

Spécification et plan validés par l’utilisateur et le ticket GitHub #271.

Vendre un équipement à la fois, doublons compris, sans vendre le dernier exemplaire équipé.
Coffres exclus. Prix entier indépendant, éditable dans EasyAdmin, zéro accepté.
Initialisation des existants à floor(prix achat / 2), nouvelles créations à zéro.

POST /api/inventory/sales : {itemKey, expectedSellPriceCoins}, authentifié et Idempotency-Key requis.
200 : {itemKey, quantity, coins, coinsBefore, coinsAfter}. Les quatre refus métier sont 422 :
item-not-owned, item-not-sellable, item-equipped, sale-price-changed.

Transaction : inventaire puis pièces, crédit SALE append-only, UUID source par vente, aucune
écriture zéro. Ligne inventaire conservée à zéro, rachat autorisé. Le prix reçu est une
précondition, jamais le montant à créditer.

Compatibilité : la migration initialise game_item ; les anciens snapshots et audits ne sont
pas modifiés. ItemCatalog interprète un sell_price_coins absent comme la moitié du prix figé
DANS ce snapshot. Dès publication, le champ explicite indépendant entre dans l’empreinte
existante GameRulesetVersion. Les seeds/imports historiques passent par la migration nouvelle.

## Checklist
- [x] Modèle, migration relue, publication/admin et validation des prix
- [x] Transaction, doublons équipés, zéro, refus et rollback, rachat
- [x] Authentification, validation, idempotence et OpenAPI généré
- [x] Tests complets, QA, vérification EasyAdmin
Livraison prévue : commit, push et PR sans fusion ni déploiement.

Sources : https://symfony.com/doc/current/controller.html et
https://symfony.com/doc/current/validation.html (MapRequestPayload et contraintes natives).

Verrous et administration : https://www.doctrine-project.org/projects/doctrine-orm/en/3.6/reference/transactions-and-concurrency.html
et https://symfony.com/bundles/EasyAdminBundle/current/fields/IntegerField.html.

Validation : `rtk make test` : 1297 tests, 23589 assertions ; complément admin/API
26 tests, 543 assertions. `rtk make qa` : PHPStan, CS et Deptrac passent.
OpenAPI régénéré. Aucun blocage fonctionnel restant.
