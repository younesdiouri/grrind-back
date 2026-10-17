# Packs graphiques sportifs rétro — V3

GET /api/appearances sert les images et ancrages, indépendamment du ruleset numérique.
Référence de fabrication : ../grrind-app/docs/design/retro-avatar-packs.md.
Calibrage courant : config/artwork/retro-v3.json ; PNG dans public/artwork/.

Quatre identités pieds nus : STRENGTH_V3, RUNNER_V3, CLIMBER_V3, SWIMMER_V3.
Sept dessins distincts par identité et deux orientations. Chaque pose possède ses points
pour eyes/head/hand/shoulder/footFront/footRear. La même main porte l’arme verticalement ;
les chaussons couvrent les pieds, les coiffes fermées épousent le front, la cape reste derrière.

Les six objets actifs sont Haltère ego, Lunettes de course-poursuite, Prise de tête,
Bonnet de bain de minuit, Palmes académiques et Cape du voyageur. Une seule pièce HEAD.
Ceinture, gourde et sac à magnésie restent hors rendu sans effacer leurs données numériques.
Les profils V2 passent à V3 ; les anciens packs inactifs restent lisibles pour les replays.

/admin/artwork compose les vrais calques des sept stances. « Inspecter les raccords » ouvre
la vue entière et des gros plans de la tête, du poing, des deux pieds et de l’épaule.
Le CRUD « Avatars et calques » édite disponibilité, nom, ordre et manifeste JSON ;
clé/type/version sont immuables. L’import PNG reste protégé admin + CSRF, PNG décodable,
2 Mo et 2048 px maximum. Les prix/caractéristiques restent dans le catalogue numérique.

Avatar : imageUrls, thumbnailUrls et attachments, pour back (droite) / front (gauche)
et idle/attack/hit/critical/dodge/combo/replay. Item : slot, iconUrl, compatibleAppearances,
layers avec attachment/plane/aspectRatio/scale/pivot/sourceUrls. Miroir : x → 1-x,
rotate → -rotate, pivot.x → 1-pivot.x. Les palmes utilisent réellement deux perspectives.
foregroundUrls et stanceMode: guard sont conservés pour les archives ; la production V3
omet ce mode et renseigne les sept états. Un calibrage d’attaque ne modifie pas la garde.

make console c=app:artwork:import recharge les dix packs V3 ; il peut écraser un réglage
admin, à exporter avant réimport. La copie migrations/data/retro-v3.json est figée.
Une nouvelle DA incompatible reçoit de nouveaux chemins et versions. Les combats figent
appearance et key+artworkVersion ; sans pack actif, artworkVersion reste null.

La V1 avait des raccords rejetés ; la V2 avait supprimé les stances et sa revue avait raté
le bonnet ouvert. Ces versions ne sont pas des références positives de portabilité.
La revue actuelle et ses preuves sont dans ../grrind-app/docs/design/retro-v3-review.md :
vues entières, contacts agrandis, objets seuls, tenues cumulées, puis contrôle du rendu mobile.
Une coordonnée valide et un test vert ne prouvent pas la qualité visuelle.
