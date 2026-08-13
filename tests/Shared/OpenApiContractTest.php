<?php

declare(strict_types=1);

namespace App\Tests\Shared;

use App\Shared\UI\Http\ProblemDetails;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RegexIterator;
use SplFileInfo;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Yaml\Yaml;

/**
 * `openapi.yaml` est le contrat que le dépôt front consomme, et un contrat qui oublie une
 * route est pire qu'un contrat absent : il donne confiance.
 *
 * **Ce test compare le fichier au routeur, pas à lui-même.** Le check de dérive de la CI
 * (`make openapi` puis `git diff --exit-code`) prouve que le fichier committé est bien celui
 * que le code produit ; il ne dit rien de ce que le code a oublié de décrire. C'est ce
 * trou-là que ce test bouche : une route ajoutée sans attributs OpenAPI le fait échouer,
 * en test, avant la revue.
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
    private const array TRANSPORT_STATUSES = [400, 404, 405, 415];

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
            ['POST /api/workouts/import'],
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
     * Ceux de `nelmio_api_doc.yaml`, et eux seuls. Le générateur en produit d'autres à
     * partir des DTO de requête — `XpHistoryQuery`, `RegisterRequest` — qu'il ne référence
     * nulle part parce qu'il déplie leurs champs en paramètres. Ceux-là ne peuvent pas
     * pourrir : ils naissent d'une classe qui existe.
     *
     * @return list<string>
     */
    private static function handWrittenSchemas(): array
    {
        $config = Yaml::parseFile(__DIR__.'/../../config/packages/nelmio_api_doc.yaml');
        self::assertIsArray($config);

        $node = $config;

        foreach (['when@dev', 'nelmio_api_doc', 'documentation', 'components', 'schemas'] as $key) {
            self::assertIsArray($node, 'Le fichier de configuration a changé de forme : ce test ne sait plus où chercher.');
            self::assertArrayHasKey($key, $node);
            $node = $node[$key];
        }

        $schemas = $node;
        self::assertIsArray($schemas);

        $names = array_keys($schemas);

        foreach ($names as $name) {
            self::assertIsString($name);
        }

        /** @var list<string> $names */
        return $names;
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
