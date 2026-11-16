<?php declare(strict_types = 1);

namespace ShipMonk\PHPStan\DeadCode\Provider;

use AssertionError;
use Composer\InstalledVersions;
use Doctrine\DBAL\Exception as DbalException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Exception\ORMException;
use Doctrine\ORM\Query\AST\NewObjectExpression;
use Doctrine\ORM\Query\AST\SelectExpression;
use Doctrine\ORM\Query\AST\SelectStatement;
use Doctrine\Persistence\Mapping\MappingException;
use LogicException;
use PhpParser\Node;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Stmt\Return_;
use PHPStan\Analyser\Scope;
use PHPStan\BetterReflection\Reflection\Adapter\ReflectionClass;
use PHPStan\BetterReflection\Reflection\Adapter\ReflectionMethod;
use PHPStan\BetterReflection\Reflection\Adapter\ReflectionProperty;
use PHPStan\DependencyInjection\Container;
use PHPStan\Node\InClassNode;
use PHPStan\Reflection\ExtendedMethodReflection;
use PHPStan\Reflection\ExtendedPropertyReflection;
use PHPStan\Reflection\MethodReflection;
use PHPStan\TrinaryLogic;
use PHPStan\Type\Doctrine\ObjectMetadataResolver;
use PHPStan\Type\Doctrine\Query\QueryType;
use PHPStan\Type\Type;
use PHPStan\Type\TypeTraverser;
use PHPStan\Type\UnionType;
use ShipMonk\PHPStan\DeadCode\Enum\AccessType;
use ShipMonk\PHPStan\DeadCode\Graph\ClassConstantRef;
use ShipMonk\PHPStan\DeadCode\Graph\ClassConstantUsage;
use ShipMonk\PHPStan\DeadCode\Graph\ClassMemberUsage;
use ShipMonk\PHPStan\DeadCode\Graph\ClassMethodRef;
use ShipMonk\PHPStan\DeadCode\Graph\ClassMethodUsage;
use ShipMonk\PHPStan\DeadCode\Graph\ClassPropertyRef;
use ShipMonk\PHPStan\DeadCode\Graph\ClassPropertyUsage;
use ShipMonk\PHPStan\DeadCode\Graph\UsageOrigin;
use ShipMonk\PHPStan\DeadCode\Naming\CaseInsensitiveName;
use function in_array;
use function is_array;
use function is_string;
use function ltrim;
use function method_exists;
use function str_starts_with;
use function stripos;

final class DoctrineUsageProvider implements ActivatableUsageProvider
{

    private readonly bool $enabled;

    public function __construct(
        private readonly Container $container,
        ?bool $enabled,
    )
    {
        $this->enabled = $enabled ?? $this->isDoctrineInstalled();
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function getUsages(
        Node $node,
        Scope $scope,
    ): array
    {
        $usages = [];

        if ($node instanceof InClassNode) { // @phpstan-ignore phpstanApi.instanceofAssumption
            $usages = [
                ...$usages,
                ...$this->getUsagesFromReflection($node, $scope),
            ];
        }

        if ($node instanceof Return_) {
            $usages = [
                ...$usages,
                ...$this->getUsagesOfEventSubscriber($node, $scope),
            ];
        }

        if ($node instanceof MethodCall) {
            $usages = [
                ...$usages,
                ...$this->getUsagesOfDqlNewOperator($node, $scope),
            ];
        }

        return $usages;
    }

    /**
     * The hydrator calls the constructor of each class in a DQL NEW expression via ReflectionClass::newInstanceArgs().
     * The DQL comes from the Query type that phpstan-doctrine infers.
     *
     * @return list<ClassMethodUsage>
     */
    private function getUsagesOfDqlNewOperator(
        MethodCall $node,
        Scope $scope,
    ): array
    {
        if (!$node->name instanceof Identifier) {
            return [];
        }

        if (!in_array($node->name->toLowerString(), ['createquery', 'getquery'], true)) {
            return [];
        }

        $usages = [];

        foreach ($this->getDqls($scope->getType($node)) as $dql) {
            foreach ($this->getClassesInstantiatedByDql($dql) as $className) {
                $usages[] = new ClassMethodUsage(
                    UsageOrigin::createRegular($node, $scope),
                    new ClassMethodRef(
                        $className,
                        '__construct',
                        possibleDescendant: false,
                    ),
                );
            }
        }

        return $usages;
    }

    /**
     * @return list<string>
     */
    private function getDqls(Type $type): array
    {
        $dqls = [];

        TypeTraverser::map($type, static function (Type $type, callable $traverse) use (&$dqls): Type {
            if ($type instanceof UnionType) {
                return $traverse($type);
            }

            if ($type instanceof QueryType) {
                $dqls[] = $type->getDql();
            }

            return $type;
        });

        return $dqls;
    }

    /**
     * @return list<string>
     */
    private function getClassesInstantiatedByDql(string $dql): array
    {
        if (stripos($dql, 'new') === false) {
            return []; // skips the entity manager and the second DQL parse for most queries
        }

        $entityManager = $this->getEntityManager($dql);

        if ($entityManager === null) {
            return [];
        }

        try {
            $ast = $entityManager->createQuery($dql)->getAST();
        } catch (AssertionError | DbalException | MappingException | ORMException $e) { // @phpstan-ignore catch.neverThrown, catch.neverThrown, catch.neverThrown, catch.neverThrown (Doctrine does not declare them)
            return []; // phpstan-doctrine reports invalid DQL
        }

        if (!$ast instanceof SelectStatement) {
            return [];
        }

        $classNames = [];

        foreach ($ast->selectClause->selectExpressions as $selectExpression) {
            if (!$selectExpression instanceof SelectExpression) {
                throw new LogicException('Doctrine parser creates only SelectExpression there');
            }

            $classNames = [
                ...$classNames,
                ...$this->getClassesOfNewObjectExpression($selectExpression->expression),
            ];
        }

        return $classNames;
    }

    /**
     * @return list<string>
     */
    private function getClassesOfNewObjectExpression(mixed $expression): array
    {
        if (!$expression instanceof NewObjectExpression) {
            return [];
        }

        $classNames = [ltrim($expression->className, '\\')];

        foreach ($expression->args as $arg) {
            $classNames = [
                ...$classNames,
                ...$this->getClassesOfNewObjectExpression($arg),
            ];
        }

        return $classNames;
    }

    private function getEntityManager(string $dql): ?EntityManagerInterface
    {
        $resolverServiceNames = $this->container->findServiceNamesByType(ObjectMetadataResolver::class);

        if ($resolverServiceNames === []) {
            return null; // phpstan-doctrine is not installed
        }

        $resolver = $this->container->getByType(ObjectMetadataResolver::class); // @phpstan-ignore missingType.checkedException (guarded by findServiceNamesByType above)

        $objectManager = method_exists($resolver, 'getObjectManagerForDql') // @phpstan-ignore function.alreadyNarrowedType (exists since phpstan-doctrine 2.0.28)
            ? $resolver->getObjectManagerForDql($dql) // @phpstan-ignore phpstanApi.method
            : $resolver->getObjectManager();

        return $objectManager instanceof EntityManagerInterface
            ? $objectManager
            : null;
    }

    /**
     * @return list<ClassMemberUsage>
     */
    private function getUsagesFromReflection(
        InClassNode $node,
        Scope $scope,
    ): array
    {
        $classReflection = $node->getClassReflection();
        $nativeReflection = $classReflection->getNativeReflection();

        $usages = [];

        foreach ($nativeReflection->getProperties() as $nativePropertyReflection) {
            $propertyName = $nativePropertyReflection->name;
            $propertyReflection = $classReflection->getProperty($propertyName, $scope);

            $usages = [
                ...$usages,
                ...$this->getUsagesOfEnumColumn($classReflection->getName(), $propertyReflection),
            ];

            $propertyUsageNote = $this->shouldMarkPropertyAsUsed($nativePropertyReflection);

            if ($propertyUsageNote !== null) {
                $usages[] = $this->createPropertyUsage($nativePropertyReflection, $propertyUsageNote, AccessType::READ);
                $usages[] = $this->createPropertyUsage($nativePropertyReflection, $propertyUsageNote, AccessType::WRITE);
            }
        }

        foreach ($nativeReflection->getMethods() as $method) {
            if ($method->getDeclaringClass()->getName() !== $nativeReflection->getName()) {
                continue;
            }

            $usageNote = $this->shouldMarkMethodAsUsed($method);

            if ($usageNote !== null) {
                $usages[] = $this->createMethodUsage($classReflection->getNativeMethod($method->getName()), $usageNote);
            }
        }

        return $usages;
    }

    /**
     * @return list<ClassMethodUsage>
     */
    private function getUsagesOfEventSubscriber(
        Return_ $node,
        Scope $scope,
    ): array
    {
        if ($node->expr === null) {
            return [];
        }

        if (!$scope->isInClass()) {
            return [];
        }

        if (!$scope->getFunction() instanceof MethodReflection) {
            return [];
        }

        if (!CaseInsensitiveName::equals($scope->getFunction()->getName(), 'getSubscribedEvents')) {
            return [];
        }

        if (!$scope->getClassReflection()->implementsInterface('Doctrine\Common\EventSubscriber')) {
            return [];
        }

        $className = $scope->getClassReflection()->getName();

        $usages = [];
        $usageOrigin = UsageOrigin::createRegular($node, $scope);

        foreach ($scope->getType($node->expr)->getConstantArrays() as $rootArray) {
            foreach ($rootArray->getValuesArray()->getValueTypes() as $eventConfig) {
                foreach ($eventConfig->getConstantStrings() as $subscriberMethodString) {
                    $usages[] = new ClassMethodUsage(
                        $usageOrigin,
                        new ClassMethodRef(
                            $className,
                            $subscriberMethodString->getValue(),
                            possibleDescendant: true,
                        ),
                    );
                }
            }
        }

        return $usages;
    }

    private function shouldMarkMethodAsUsed(ReflectionMethod $method): ?string
    {
        $methodName = $method->getName();
        $class = $method->getDeclaringClass();

        if ($this->isLifecycleEventMethod($method)) {
            return 'Lifecycle event method via attribute';
        }

        if ($this->isEntityRepositoryConstructor($class, $method)) {
            return 'Entity repository constructor (created by EntityRepositoryFactory)';
        }

        if ($this->isPartOfAsEntityListener($class, $methodName)) {
            return 'Is part of AsEntityListener methods';
        }

        if ($this->isPartOfAsDoctrineListener($class, $methodName)) {
            return 'Is part of AsDoctrineListener methods';
        }

        if ($this->isPartOfAutoconfigureTagDoctrineListener($class, $methodName)) {
            return 'Is part of AutoconfigureTag doctrine.event_listener methods';
        }

        if ($this->isProbablyDoctrineListener($methodName)) {
            return 'Is probable listener method';
        }

        return null;
    }

    private function shouldMarkPropertyAsUsed(ReflectionProperty $property): ?string
    {
        $attributes = $property->getAttributes();

        foreach ($attributes as $attribute) {
            $attributeName = $attribute->getName();

            if (str_starts_with($attributeName, 'Doctrine\ORM\Mapping\\')) {
                return 'Doctrine ORM mapped property';
            }
        }

        return null;
    }

    private function isLifecycleEventMethod(ReflectionMethod $method): bool
    {
        return $this->hasAttribute($method, 'Doctrine\ORM\Mapping\PostLoad')
            || $this->hasAttribute($method, 'Doctrine\ORM\Mapping\PostPersist')
            || $this->hasAttribute($method, 'Doctrine\ORM\Mapping\PostRemove')
            || $this->hasAttribute($method, 'Doctrine\ORM\Mapping\PostUpdate')
            || $this->hasAttribute($method, 'Doctrine\ORM\Mapping\PreFlush')
            || $this->hasAttribute($method, 'Doctrine\ORM\Mapping\PrePersist')
            || $this->hasAttribute($method, 'Doctrine\ORM\Mapping\PreRemove')
            || $this->hasAttribute($method, 'Doctrine\ORM\Mapping\PreUpdate');
    }

    /**
     * Ideally, we would need to parse DIC xml to know this for sure just like phpstan-symfony does.
     * - see Doctrine\ORM\Events::* and Doctrine\ORM\Tools\ToolEvents::*
     */
    private function isProbablyDoctrineListener(string $methodName): bool
    {
        return CaseInsensitiveName::isOneOf($methodName, [
            'preRemove',
            'postRemove',
            'prePersist',
            'postPersist',
            'preUpdate',
            'postUpdate',
            'postLoad',
            'loadClassMetadata',
            'onClassMetadataNotFound',
            'preFlush',
            'onFlush',
            'postFlush',
            'onClear',
            'postGenerateSchemaTable',
            'postGenerateSchema',
        ]);
    }

    private function hasAttribute(
        ReflectionMethod $method,
        string $attributeClass,
    ): bool
    {
        return $method->getAttributes($attributeClass) !== [];
    }

    private function isPartOfAsEntityListener(
        ReflectionClass $class,
        string $methodName,
    ): bool
    {
        foreach ($class->getAttributes('Doctrine\Bundle\DoctrineBundle\Attribute\AsEntityListener') as $attribute) {
            $listenerMethodName = $attribute->getArguments()['method'] ?? $attribute->getArguments()[1] ?? null;

            if (is_string($listenerMethodName) && CaseInsensitiveName::equals($listenerMethodName, $methodName)) {
                return true;
            }
        }

        return false;
    }

    private function isPartOfAsDoctrineListener(
        ReflectionClass $class,
        string $methodName,
    ): bool
    {
        foreach ($class->getAttributes('Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener') as $attribute) {
            $eventName = $attribute->getArguments()['event'] ?? $attribute->getArguments()[0] ?? null;

            // AsDoctrineListener doesn't have a 'method' parameter
            // Symfony looks for a method named after the event, or falls back to __invoke
            if (
                (is_string($eventName) && CaseInsensitiveName::equals($eventName, $methodName))
                || CaseInsensitiveName::equals($methodName, '__invoke')
            ) {
                return true;
            }
        }

        return false;
    }

    private function isPartOfAutoconfigureTagDoctrineListener(
        ReflectionClass $class,
        string $methodName,
    ): bool
    {
        foreach ($class->getAttributes('Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag') as $attribute) {
            $arguments = $attribute->getArguments();
            $tagName = $arguments[0] ?? $arguments['name'] ?? null;

            // Only handle doctrine.event_listener tags
            if ($tagName !== 'doctrine.event_listener') {
                continue;
            }

            // AutoconfigureTag(?string $name, array $attributes) nests the tag attributes in its second argument
            $tagAttributes = $arguments[1] ?? $arguments['attributes'] ?? null;
            $eventName = is_array($tagAttributes) ? $tagAttributes['event'] ?? null : null;

            // The doctrine.event_listener tag has no 'method' attribute
            // Symfony looks for a method named after the event, or falls back to __invoke
            if (
                (is_string($eventName) && CaseInsensitiveName::equals($eventName, $methodName))
                || CaseInsensitiveName::equals($methodName, '__invoke')
            ) {
                return true;
            }
        }

        return false;
    }

    private function isEntityRepositoryConstructor(
        ReflectionClass $class,
        ReflectionMethod $method,
    ): bool
    {
        if (!$method->isConstructor()) {
            return false;
        }

        return $class->isSubclassOf('Doctrine\ORM\EntityRepository');
    }

    private function isDoctrineInstalled(): bool
    {
        return InstalledVersions::isInstalled('doctrine/orm')
            || InstalledVersions::isInstalled('doctrine/event-manager')
            || InstalledVersions::isInstalled('doctrine/doctrine-bundle');
    }

    private function createMethodUsage(
        ExtendedMethodReflection $methodReflection,
        string $note,
    ): ClassMethodUsage
    {
        return new ClassMethodUsage(
            UsageOrigin::createVirtual($this, VirtualUsageData::withNote($note)),
            new ClassMethodRef(
                $methodReflection->getDeclaringClass()->getName(),
                $methodReflection->getName(),
                possibleDescendant: false,
            ),
        );
    }

    private function createPropertyUsage(
        ReflectionProperty $propertyReflection,
        string $note,
        AccessType $accessType,
    ): ClassPropertyUsage
    {
        return new ClassPropertyUsage(
            UsageOrigin::createVirtual($this, VirtualUsageData::withNote($note)),
            new ClassPropertyRef(
                $propertyReflection->getDeclaringClass()->getName(),
                $propertyReflection->getName(),
                possibleDescendant: false,
            ),
            $accessType,
        );
    }

    /**
     * @return list<ClassConstantUsage>
     */
    private function getUsagesOfEnumColumn(
        string $className,
        ExtendedPropertyReflection $property,
    ): array
    {
        $usages = [];
        $propertyName = $property->getName();

        foreach ($property->getAttributes() as $attribute) {
            if ($attribute->getName() !== 'Doctrine\ORM\Mapping\Column') {
                continue;
            }

            foreach ($attribute->getArgumentTypes() as $name => $type) {
                if ($name !== 'enumType') {
                    continue;
                }

                foreach ($type->getConstantStrings() as $constantString) {
                    $usages[] = new ClassConstantUsage(
                        UsageOrigin::createVirtual($this, VirtualUsageData::withNote("Used in enumType of #[Column] of $className::$propertyName")),
                        new ClassConstantRef(
                            className: $constantString->getValue(),
                            constantName: null,
                            possibleDescendant: false,
                            isEnumCase: TrinaryLogic::createYes(),
                        ),
                    );
                }
            }
        }

        return $usages;
    }

}
