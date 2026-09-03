<?php

declare(strict_types=1);

namespace App\Tests\Shared;

use App\Progression\Domain\XpBreakdownSource;
use App\Shared\Application\PushNotification;
use App\Shared\Application\PushRoute;
use App\Shared\Domain\Activity\Discipline;
use App\Shared\Domain\NotificationCategory;
use App\Shared\Domain\PushRouteType;
use App\Shared\UI\Http\ProblemDetails;
use App\Shared\UI\Push\PushNotificationData;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RegexIterator;
use SplFileInfo;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Yaml\Yaml;

/**
 * `openapi.yaml` est le contrat que le dépôt front consomme, et un contrat qui oublie une
 * route est pire qu'un contrat absent : il donne confiance.
 *
 * **Ce test compare le fichier au routeur, pas à lui-même.** Le check de dérive
 * (`make openapi` puis `git diff`, à la main avant de pousser depuis #178) prouve que le fichier
 * committé est bien celui que le code produit ; il ne dit rien de ce que le code a oublié de
 * décrire. C'est ce trou-là que ce test bouche : une route ajoutée sans attributs OpenAPI le
 * fait échouer, en test, avant la revue.
 *
 * Il ne vérifie pas les *formes* des réponses — c'est le travail des suites de chaque
 * module, qui les figent contre la vraie API jusqu'à l'ordre des clés du `RewardSummary`.
 */
final class OpenApiContractTest extends KernelTestCase
{
    /** Ce qui appartient au contrat client. `/health` est une sonde d'infrastructure. */
    private const string DOCUMENTED_PREFIX = '/api';

    /**
     * Les statuts d'`HttpException` qu'une requête peut atteindre sous `^/api` : route ou
     * ressource inconnue, mauvais verbe, corps illisible, mauvais `Content-Type`.
     *
     * **Ceux-là seuls sont écrits à la main**, parce qu'ils sont les seuls qu'aucune lecture du
     * code ne peut trouver : leur `type` est *dérivé* du statut par `ProblemDetails::ofStatus()`
     * au moment de la panne, il n'existe en clair nulle part. Le slug n'est pas recopié pour
     * autant — c'est la vraie classe qui le fabrique. `ProblemDetailsTest` prouve que les quatre
     * sortent bien de l'API ; sans lui cette liste serait une supposition.
     *
     * @var list<int>
     */
    /**
     * Les statuts que le noyau produit sans qu'aucune erreur de domaine les nomme. 403 est
     * arrivé avec `#[IsGranted]` (#115) et 429 avec `#[RateLimit]` (#116) : ce sont les
     * composants qui lèvent, pas nous, donc aucun `type(): string` du code ne les déclare.
     */
    private const array TRANSPORT_STATUSES = [400, 403, 404, 405, 415, 429];

    /** @var array<string, mixed> */
    private array $spec;

    protected function setUp(): void
    {
        self::bootKernel();

        $path = self::getContainer()->getParameter('kernel.project_dir').'/openapi.yaml';
        self::assertIsString($path);
        self::assertFileExists($path, 'Le contrat n\'est pas généré. Lance `make openapi`.');

        $spec = Yaml::parseFile($path);
        self::assertIsArray($spec);

        /** @var array<string, mixed> $spec */
        $this->spec = $spec;
    }

    public function testEveryApiRouteIsDocumented(): void
    {
        $paths = $this->spec['paths'];
        self::assertIsArray($paths);

        foreach ($this->apiRoutes() as $name => $route) {
            $path = $route->getPath();

            self::assertArrayHasKey(
                $path,
                $paths,
                \sprintf('La route "%s" (%s) n\'est pas dans openapi.yaml. Décris-la, puis `make openapi`.', $name, $path),
            );

            $documented = $paths[$path];
            self::assertIsArray($documented);

            foreach ($route->getMethods() as $method) {
                self::assertArrayHasKey(
                    strtolower($method),
                    $documented,
                    \sprintf('La méthode %s de "%s" n\'est pas documentée.', $method, $path),
                );
            }
        }
    }

    /**
     * Le pendant du test précédent, dans l'autre sens : **un schéma que plus personne ne
     * référence est du contrat mort.**.
     *
     * Les chemins ne peuvent pas pourrir — ils naissent des attributs des contrôleurs — mais
     * les schémas sont écrits à la main, et une route supprimée laisse le sien derrière elle
     * sans que rien ne s'en plaigne. C'est arrivé à `TrainingSession` et `SessionPage` au
     * #93 ; ce test est ce qui l'aurait dit.
     */
    public function testNoSchemaIsLeftBehindWithoutAReference(): void
    {
        $references = [];
        self::collectReferences($this->spec, $references);

        foreach (self::handWrittenSchemas() as $name) {
            self::assertContains(
                '#/components/schemas/'.$name,
                $references,
                \sprintf('Le schéma "%s" n\'est référencé nulle part. Si sa route a disparu, il doit disparaître avec elle.', $name),
            );
        }
    }

    /**
     * **Le seul schéma du contrat qu'aucune route ne rend** — le `data` d'un push, qui
     * arrive par APNs. Les deux tests ci-dessus ne peuvent rien pour lui : l'un cherche
     * des routes non décrites, l'autre des schémas que plus rien ne référence, et celui-ci
     * n'a jamais eu de route à perdre. Ce qui le tient est ailleurs — il est décrit depuis
     * la classe que le sender utilise, et c'est ce que ce test vérifie (#147).
     *
     * La comparaison porte sur les clés **réellement envoyées** : décrire une charge utile
     * que le code ne produit plus serait la panne silencieuse que ce contrat existe pour
     * éviter, la même dans l'autre sens.
     */
    public function testTheContractDescribesThePushPayloadTheSenderSends(): void
    {
        $sent = PushNotificationData::of(new PushNotification(
            'Titre',
            'Corps',
            NotificationCategory::GuildActivity,
            'guild-roster',
            new PushRoute(PushRouteType::PlayerProfile, Uuid::v7()),
        ))->toArray();

        $schema = $this->schema('PushNotificationData');

        $properties = $schema['properties'];
        self::assertIsArray($properties);

        self::assertSame(
            array_keys($sent),
            array_keys($properties),
            'Le contrat et le `data` envoyé ne portent plus les mêmes clés. Le sender fait foi ; '
            .'recale `PushNotificationData`, puis `make openapi`.',
        );

        // Aucune n'est facultative : le client route dessus, une clé manquante le laisse
        // sans recours au moment précis où il en a besoin.
        self::assertSame(array_keys($sent), $schema['required']);

        // L'énumération que le client branche est celle que le code émet. Le contrôle de
        // dérive de la CI le dirait aussi, mais plus tard : un cas ajouté à `PushRouteType`
        // sans `make openapi` doit tomber ici, avant la revue.
        self::assertSame(
            array_column(PushRouteType::cases(), 'value'),
            $this->schema('PushRouteType')['enum'],
        );
    }

    /**
     * `XpBreakdownSource` est recopiée à la main dans `XpLine.properties.source.enum` — rien
     * ne relie les deux, et c'est ce qui a laissé passer `GUILD` neuf séances après son entrée
     * dans le domaine (#200). L'ordre compte autant que le contenu : c'est l'ordre d'animation
     * du détail de calcul, et `assertSame` le vérifie là où `assertContains` aurait laissé
     * passer une valeur au mauvais rang.
     */
    public function testTheContractDeclaresEveryXpBreakdownSource(): void
    {
        $source = $this->schema('XpLine')['properties'];
        self::assertIsArray($source);
        $source = $source['source'];
        self::assertIsArray($source);

        self::assertSame(
            array_column(XpBreakdownSource::cases(), 'value'),
            $source['enum'],
            'Le contrat et `XpBreakdownSource` ne portent plus les mêmes valeurs, dans le même '
            .'ordre. Recale l\'énumération de `XpLine.source` dans nelmio_api_doc.yaml, puis `make openapi`.',
        );
    }

    /**
     * Le schéma `Discipline` n'est pas écrit à la main : le générateur le produit depuis l'enum
     * PHP, parce que `ChooseRisalaRequest` porte une propriété typée avec (#201). Ce test ne
     * prouve donc pas qu'une recopie suit l'enum — il n'y en a plus — il prouve que ce mécanisme
     * de génération tient toujours : que `Discipline` existe encore dans le contrat, et qu'il
     * sort encore de {@see Discipline}. Il tomberait si `ChooseRisalaRequest` cessait un jour
     * d'être la seule classe qui type l'enum, ou si le générateur changeait de nom de schéma —
     * deux pannes silencieuses qui laisseraient `Workout.discipline` et `XpTransaction.discipline`
     * pointer vers un schéma qui a discrètement pris du retard (#205).
     */
    public function testTheDisciplineSchemaStillComesFromTheEnum(): void
    {
        self::assertSame(
            array_column(Discipline::cases(), 'value'),
            $this->schema('Discipline')['enum'],
            'Le schéma "Discipline" ne sort plus de l\'enum PHP, dans le même ordre. Vérifie que '
            .'ChooseRisalaRequest type toujours sa propriété avec Discipline, puis `make openapi`.',
        );
    }

    public function testUserProfileDeclaresThePersistedLocale(): void
    {
        $profile = $this->schema('UserProfile');
        $required = $profile['required'];
        $properties = $profile['properties'];
        self::assertIsArray($required);
        self::assertIsArray($properties);
        $locale = $properties['locale'];
        self::assertIsArray($locale);
        self::assertContains('locale', $required);
        self::assertSame(['en', 'fr'], $locale['enum']);
    }

    /**
     * Une opération sans réponse décrite passerait le test ci-dessus tout en ne disant
     * rien au client : elle est documentée « pour la forme ».
     */
    public function testEveryDocumentedOperationDescribesItsResponses(): void
    {
        foreach ($this->operations() as $label => $operation) {
            self::assertArrayHasKey('responses', $operation, \sprintf('%s ne décrit aucune réponse.', $label));
            self::assertNotEmpty($operation['responses'], \sprintf('%s ne décrit aucune réponse.', $label));
        }
    }

    /**
     * Toute route authentifiée doit l'annoncer, et toute route publique doit le dire
     * explicitement. Le défaut global est « authentifié » — l'oubli dangereux est dans
     * l'autre sens, et une route publique qui n'aurait pas posé `security: []` hériterait
     * simplement du défaut, sans conséquence.
     */
    public function testThePublicRoutesAreExactlyTheOnesWeExpect(): void
    {
        $public = [];

        foreach ($this->operations() as $label => $operation) {
            if ([] === ($operation['security'] ?? null)) {
                $public[] = $label;
            }
        }

        sort($public);

        self::assertSame(
            [
                'POST /api/auth/login',
                'POST /api/auth/logout',
                'POST /api/auth/refresh',
                'POST /api/auth/register',
                'POST /api/auth/social/{provider}',
            ],
            $public,
            'La liste des routes publiques a changé. Si c\'est voulu, la mettre à jour ici est le geste qui le fait relire.',
        );
    }

    /**
     * Les routes qui accordent quelque chose exigent `Idempotency-Key` : le contrat doit le
     * dire, sinon un client mobile qui rejoue crédite deux fois — ou croit l'avoir fait.
     *
     * La liste est **comparée**, pas parcourue. Une boucle sur les routes attendues passait
     * sans rien vérifier le jour où il n'en restait aucune, ce que le retrait du chronomètre
     * (#85) avait provoqué. Un test vide qui reste vert est pire qu'un test absent.
     */
    public function testTheIdempotentRoutesAreExactlyTheOnesWeExpect(): void
    {
        $idempotent = [];

        foreach ($this->operations() as $label => $operation) {
            $parameters = $operation['parameters'] ?? [];
            self::assertIsArray($parameters);

            // Le paramètre est déclaré une fois dans les composants et référencé ici : on
            // compare donc des `$ref`, sans quoi le test ne verrait qu'un tableau opaque.
            $referenced = array_map(
                static function (mixed $parameter): mixed {
                    self::assertIsArray($parameter);

                    return $parameter['$ref'] ?? $parameter['name'] ?? null;
                },
                $parameters,
            );

            if (\in_array('#/components/parameters/IdempotencyKey', $referenced, true)) {
                $idempotent[] = $label;
            }
        }

        sort($idempotent);

        self::assertSame(
            ['POST /api/battles', 'POST /api/inventory/chests/{key}/open', 'POST /api/shop/purchases', 'POST /api/workouts/import'],
            $idempotent,
            'La liste des routes idempotentes a changé. Toute route qui crédite doit y entrer, '
            .'et la mettre à jour ici est le geste qui le fait relire.',
        );
    }

    /**
     * L'énumération est **exactement** ce que le code émet, ni plus ni moins. C'est elle que
     * le client branche — un `switch` exhaustif dont le `default` n'accepte que `never` — donc
     * les deux dérives coûtent : un `type` absent le laisse sans recours, un `type` fantôme lui
     * fait écrire du code mort pour une panne qui n'arrive jamais.
     *
     * La comparaison remplace un `assertContains` qui ne regardait qu'un sens, et sur les seules
     * exceptions : `invalid-credentials` et les trois `access-token-*` naissent dans un listener,
     * pas dans une classe d'erreur, et sont passés dessous sans bruit jusqu'au ticket #81.
     */
    public function testTheEnumeratedProblemTypesAreExactlyTheOnesTheCodeEmits(): void
    {
        $emitted = $this->emittedProblemTypes();
        $enumerated = $this->enumeratedProblemTypes();

        sort($emitted);
        sort($enumerated);

        self::assertSame(
            $emitted,
            $enumerated,
            'Le contrat et le code ne disent plus la même chose. À gauche ce que le back émet, à '
            .'droite ce que `nelmio_api_doc.yaml` déclare ; recale l\'énumération, puis `make openapi`.',
        );
    }

    /**
     * Tout `$ref` désigne quelque chose qui existe.
     *
     * C'est la panne que la génération ne peut pas voir : une référence vers un composant
     * renommé ou supprimé passe le `dump` sans un mot, et casse au premier générateur de
     * client. Le document est parcouru en entier, y compris ce qui est imbriqué.
     */
    public function testEveryReferenceResolves(): void
    {
        $references = [];
        self::collectReferences($this->spec, $references);

        // Un parcours qui ne trouverait plus rien ferait passer ce test sans rien vérifier.
        self::assertGreaterThan(20, \count($references));

        foreach (array_unique($references) as $reference) {
            $path = explode('/', ltrim($reference, '#/'));
            $node = $this->spec;

            foreach ($path as $segment) {
                self::assertIsArray($node);
                self::assertArrayHasKey($segment, $node, \sprintf('La référence "%s" ne mène nulle part.', $reference));

                $node = $node[$segment];
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function schema(string $name): array
    {
        $components = $this->spec['components'];
        self::assertIsArray($components);

        $schemas = $components['schemas'];
        self::assertIsArray($schemas);
        self::assertArrayHasKey($name, $schemas, \sprintf('Le schéma "%s" a disparu du contrat.', $name));

        $schema = $schemas[$name];
        self::assertIsArray($schema);

        /** @var array<string, mixed> $schema */
        return $schema;
    }

    /**
     * Ceux de `nelmio_api_doc.yaml`, et eux seuls. Le générateur en produit d'autres à
     * partir des DTO de requête — `XpHistoryQuery`, `RegisterRequest` — qu'il ne référence
     * nulle part parce qu'il déplie leurs champs en paramètres. Ceux-là ne peuvent pas
     * pourrir : ils naissent d'une classe qui existe.
     *
     * **Un schéma déclaré sous `models.names` est de ceux-là**, même s'il apparaît aussi
     * sous `components.schemas` : ce qu'on y écrit à la main n'est que sa prose, sa forme
     * vient d'une classe (#147). Il est donc retiré de la liste — sans quoi le seul schéma
     * du contrat qui n'appartient à aucune route ferait échouer un test qui cherche des
     * schémas morts, pour la seule raison qu'il est vivant ailleurs.
     *
     * @return list<string>
     */
    private static function handWrittenSchemas(): array
    {
        $schemas = self::configuredAt('documentation', 'components', 'schemas');
        $names = array_keys($schemas);

        foreach ($names as $name) {
            self::assertIsString($name);
        }

        /** @var list<string> $names */
        return array_values(array_diff($names, self::generatedFromAClass()));
    }

    /**
     * Les alias de `models.names` — les modèles que le générateur décrit sans attendre
     * qu'une route les référence.
     *
     * @return list<string>
     */
    private static function generatedFromAClass(): array
    {
        $names = [];

        foreach (self::configuredAt('models', 'names') as $declaration) {
            self::assertIsArray($declaration);
            self::assertArrayHasKey('alias', $declaration);
            self::assertIsString($declaration['alias']);

            $names[] = $declaration['alias'];
        }

        return $names;
    }

    /**
     * @return array<mixed>
     */
    private static function configuredAt(string ...$path): array
    {
        $node = Yaml::parseFile(__DIR__.'/../../config/packages/nelmio_api_doc.yaml');

        foreach (['when@dev', 'nelmio_api_doc', ...$path] as $key) {
            self::assertIsArray($node, 'Le fichier de configuration a changé de forme : ce test ne sait plus où chercher.');
            self::assertArrayHasKey($key, $node);
            $node = $node[$key];
        }

        self::assertIsArray($node);

        return $node;
    }

    /**
     * @param list<string> $found
     */
    private static function collectReferences(mixed $node, array &$found): void
    {
        if (!\is_array($node)) {
            return;
        }

        foreach ($node as $key => $value) {
            if ('$ref' === $key && \is_string($value)) {
                $found[] = $value;

                continue;
            }

            self::collectReferences($value, $found);
        }
    }

    /**
     * Lus dans le code plutôt qu'énumérés ici : une liste recopiée serait un second endroit
     * à tenir à jour, et c'est exactement ce qu'on cherche à éviter.
     *
     * @return list<string>
     */
    private function emittedProblemTypes(): array
    {
        $directory = self::getContainer()->getParameter('kernel.project_dir').'/src';
        self::assertIsString($directory);

        $files = new RegexIterator(
            new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory)),
            '/\.php$/',
        );

        $types = [];

        foreach ($files as $file) {
            self::assertInstanceOf(SplFileInfo::class, $file);

            $source = file_get_contents($file->getPathname());
            self::assertIsString($source);

            // Une erreur de domaine nomme son type.
            if (1 === preg_match('/function type\(\): string\s*\{\s*return \'([a-z0-9-]+)\';/', $source, $matches)) {
                $types[] = ProblemDetails::of($matches[1], 0, '')->type;
            }

            // Un composeur de problèmes écrit le sien en clair. On lit **tous** les littéraux
            // en kebab-case du fichier, et pas les arguments d'un `ProblemDetails::of(` :
            // `AuthenticationResponseListener` passe par un helper privé, et un scan collé à
            // l'appel n'y verrait rien — c'est le trou par lequel #81 est passé. La forme
            // « minuscules avec tiret » ne collide avec rien d'autre dans ces fichiers : les
            // noms d'en-tête portent des majuscules et les types de média une barre oblique.
            if (str_contains($source, 'ProblemDetails::')) {
                preg_match_all('/\'([a-z][a-z0-9]*(?:-[a-z0-9]+)+)\'/', $source, $matches);

                foreach ($matches[1] as $slug) {
                    $types[] = ProblemDetails::of($slug, 0, '')->type;
                }
            }
        }

        foreach (self::TRANSPORT_STATUSES as $status) {
            $types[] = ProblemDetails::ofStatus($status)->type;
        }

        // Le code est parcouru pour de vrai : une expression qui ne trouverait plus rien ferait
        // passer ce test en ne vérifiant rien du tout. Le seuil est un plancher contre une
        // regex qui cesse de matcher, pas un décompte — le retrait du chronomètre (#85) a
        // fait tomber cinq types d'un coup, et un seuil collé au réel se serait mis en
        // travers à chaque suppression légitime.
        self::assertGreaterThan(10, \count($types));

        return array_values(array_unique($types));
    }

    /**
     * @return list<string>
     */
    private function enumeratedProblemTypes(): array
    {
        $components = $this->spec['components'];
        self::assertIsArray($components);
        $schemas = $components['schemas'];
        self::assertIsArray($schemas);
        $problem = $schemas['ProblemDetails'];
        self::assertIsArray($problem);
        $properties = $problem['properties'];
        self::assertIsArray($properties);
        $type = $properties['type'];
        self::assertIsArray($type);
        $enumerated = $type['enum'];
        self::assertIsArray($enumerated);

        foreach ($enumerated as $value) {
            self::assertIsString($value);
        }

        /** @var list<string> $enumerated */
        return $enumerated;
    }

    /**
     * @return iterable<string, Route>
     */
    private function apiRoutes(): iterable
    {
        $router = self::getContainer()->get('router');
        self::assertInstanceOf(RouterInterface::class, $router);

        foreach ($router->getRouteCollection() as $name => $route) {
            // `_` préfixe l'interne, comme partout chez Symfony : la sonde d'idempotence
            // n'existe qu'en test et n'a rien à faire dans un contrat client.
            if (str_starts_with($route->getPath(), self::DOCUMENTED_PREFIX.'/_')) {
                continue;
            }

            if (str_starts_with($route->getPath(), self::DOCUMENTED_PREFIX)) {
                yield $name => $route;
            }
        }
    }

    /**
     * @return iterable<string, array<string, mixed>>
     */
    private function operations(): iterable
    {
        $paths = $this->spec['paths'];
        self::assertIsArray($paths);

        foreach ($paths as $path => $methods) {
            self::assertIsString($path);
            self::assertIsArray($methods);

            foreach ($methods as $method => $operation) {
                self::assertIsString($method);
                self::assertIsArray($operation);

                /** @var array<string, mixed> $operation */
                yield strtoupper($method).' '.$path => $operation;
            }
        }
    }
}
