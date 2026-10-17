# Packs graphiques sportifs rétro — V2

Le catalogue graphique (GET /api/appearances) reste indépendant du ruleset numérique.
La référence de fabrication est ../grrind-app/docs/design/retro-avatar-packs.md et le
calibrage courant config/artwork/retro-v2.json. Les PNG vivent sous public/artwork/.

Quatre bases pieds nus : STRENGTH_V2, RUNNER_V2, CLIMBER_V2, SWIMMER_V2. Une garde par
identité est partagée entre les sept états de combat, animés ensemble avec leurs calques.
Les objets visibles sont trois coiffes/lunettes, Haltère ego, Palmes académiques et la
Cape du voyageur (item existant TRAVELERS_CLOAK). Ceinture, gourde et sac à magnésie sont
retirés du choix graphique ; leurs inventaires et caractéristiques restent intacts.
Les profils V1 passent à la V2 correspondante. Les packs V1 inactifs restent lisibles pour
leurs anciens replays, sans être présentés dans l’atelier ou les nouveaux choix.

## Administration

/admin/artwork compose les vrais calques des avatars actifs. Le menu « Avatars et calques »
édite nom, disponibilité, ordre et manifeste JSON ; clé/type/version sont immuables.
L’import PNG exige admin + CSRF, un vrai PNG décodable, au plus 2 Mo et 2048 px ; le chemin
retourné /game-images/<sha1>.png peut être utilisé dans le JSON. Les prix/modificateurs et
noms canoniques restent dans le catalogue numérique avec publication séparée.

Un avatar fournit imageUrls, thumbnailUrls, attachments pour back (droite) / front (gauche),
et idle/attack/hit/critical/dodge/combo/replay. La V2 référence la même image de garde dans
chaque état. Les ancrages eyes/head/hand/shoulder/footFront/footRear portent x/y/span/rotate.
Aucun ancrage de taille n’est prévu en V1 visible. foregroundUrls reste un champ historique
facultatif pour les anciens replays ; les nouveaux corps n’en ont pas besoin.

L’objet déclare slot, iconUrl, compatibleAppearances et layers, avec attachment, plane,
aspectRatio, scale, pivot et sourceUrls. L’arme verticale passe derrière le poing fermé,
dégagé du torse ; la cape derrière les épaules ; coiffes et chaussons devant. Les palmes
emploient deux perspectives distinctes (pied de face, pied de côté). Le miroir inverse x,
rotate et pivot.x pour l’ensemble ; il ne remplace pas ces deux perspectives.

## Import et versions

make console c=app:artwork:import charge par défaut les dix packs V2. Cette commande peut
écraser leur calibrage admin : exporter les valeurs à conserver avant réimport. Les données
des migrations publiées restent figées ; toute nouvelle DA incompatible reçoit des chemins
et versions distincts. Désactiver la version précédente évite les doublons dans l’atelier.
Les combats figent l’apparence et key+artworkVersion ; un item sans pack actif garde sa clé
mais artworkVersion=null dans les nouveaux combats. Aucun inventaire n’est effacé.

## Contrôle visuel

Les captures V1 ont été rejetées par l’auteur malgré les tests techniques : elles ne sont
pas des références positives. Contrôler maintenant les pieds réellement enveloppés, le
contact poignée/poing, la cape portée aux épaules, les contours et le cumul des objets,
puis le combat mobile à taille réelle dans les deux orientations. La présence de sept
lignes identiques dans une planche ne constitue pas sept validations indépendantes.
Les preuves courantes sont dans ../grrind-app/docs/design/proofs/retro-v2-*.png.

Le manifeste de garde utilise `stanceMode: "guard"` et uniquement `idle` dans chaque série
d’images et d’ancrages. Le catalogue développe les sept états à la lecture, sans changer le
contrat HTTP. Les autres états dans un manifeste de garde sont refusés pour éviter des
calibrages contradictoires ; omettre ce mode conserve les poses indépendantes historiques.
