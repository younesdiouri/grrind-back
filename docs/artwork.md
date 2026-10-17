# Packs graphiques sportifs rétro

Le catalogue graphique est indépendant du ruleset numérique : GET /api/appearances.
Quatre avatars sans équipement, sept poses par orientation et huit objets séparés.
Les PNG versionnés sont sous public/artwork/ ; le manifeste initial est dans
config/artwork/retro-v1.json. Le client et les scripts de fabrication sont dans grrind-app,
docs/design/retro-avatar-packs.md. Aucun rééquilibrage des caractéristiques.

## Administration

- /admin/artwork : sélectionner avatar, orientation et pièces ; contrôler les sept poses.
  Import PNG protégé par session admin + CSRF, 2 Mo/2048 px, type réel et décodage contrôlés.
  Le chemin retourné /game-images/<sha1>.png peut être collé dans un manifeste.
- Menu « Avatars et calques » : créer/modifier les packs, disponibilités, ordre et JSON.
  Les clés/type/version sont immuables après création. Les avatars incluent leur version
  dans leur clé, par exemple RUNNER_V1. Conserver les anciennes versions pour les replays.
- Les caractéristiques, prix, emplacement et traductions canoniques restent dans le catalogue
  des items, avec son mécanisme habituel de brouillon/publication. Le pack graphique n’en
  publie aucun et n’ajoute pas à lui seul un nouvel objet de jeu.

Un avatar déclare imageUrls, thumbnailUrls et attachments pour back (droite) et front
(gauche), avec idle/attack/hit/critical/dodge/combo/replay. foregroundUrls ajoute les masques
des mains après les objets. Chaque ancrage porte x/y/span/rotate sur un canevas normalisé.
Un objet déclare slot, iconUrl, compatibleAppearances et layers. Chaque calque déclare
attachment, plane, aspectRatio, scale, pivot et sourceUrls. L’aperçu utilise ces vrais calques.
La version active d’équipement la plus récente sert aux nouveaux snapshots ; désactiver la
précédente évite les doublons dans l’atelier. Une version inactive continue de servir les replays.
Créer de nouveaux chemins pour une nouvelle DA ; les PNG sont mis en cache.

## Import local et migrations

make console c=app:artwork:import réimporte les douze packs du manifeste ; il ne modifie pas
les inventaires ni les chiffres de jeu. Il peut écraser le calibrage de ces mêmes versions :
exporter les réglages admin à conserver avant de le relancer. Le fichier initial d’une migration
publiée reste figé ; les calibrages ultérieurs vont dans un nouveau manifeste/version.

Les migrations ajoutent le catalogue graphique et les huit objets cosmétiques, sans modificateur,
pour une pièce chacun. Ce prix symbolique respecte l’invariant existant de boutique à prix
strictement positif ; ce n’est pas un équilibrage. Elles remplacent MURID dans les profils par
STRENGTH_V1 ; les vieux snapshots bénéficient du même repli à la lecture. Aucune base n’est
réinitialisée et aucune progression, séance ou ligne d’inventaire n’est effacée.
