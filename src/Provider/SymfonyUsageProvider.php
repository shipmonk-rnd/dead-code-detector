<?php declare(strict_types = 1);

namespace ShipMonk\PHPStan\DeadCode\Provider;

use Composer\InstalledVersions;
use LogicException;
use PhpParser\Node;
use PhpParser\Node\Stmt\Return_;
use PHPStan\Analyser\Scope;
use PHPStan\BetterReflection\Reflector\Exception\IdentifierNotFound;
use PHPStan\Node\InClassNode;
use PHPStan\Reflection\ExtendedMethodReflection;
use PHPStan\Reflection\MethodReflection;
use PHPStan\Symfony\Configuration as PHPStanSymfonyConfiguration;
use ReflectionAttribute;
use ReflectionClass;
use ReflectionMethod;
use Reflector;
use ShipMonk\PHPStan\DeadCode\Graph\ClassMethodRef;
use ShipMonk\PHPStan\DeadCode\Graph\ClassMethodUsage;
use SimpleXMLElement;
use function file_get_contents;
use function simplexml_load_string;
use function sprintf;
use const PHP_VERSION_ID;

class SymfonyUsageProvider implements MemberUsageProvider
{

    private bool $enabled;

    /**
     * class => [method => true]
     *
     * @var array<string, array<string, true>>
     */
    private array $dicCalls = [];

    public function __construct(
        ?PHPStanSymfonyConfiguration $symfonyConfiguration,
        ?bool $enabled
    )
    {
        $this->enabled = $enabled ?? $this->isSymfonyInstalled();

        if ($symfonyConfiguration !== null && $symfonyConfiguration->getContainerXmlPath() !== null) { // @phpstan-ignore phpstanApi.method
            $this->fillDicClasses($symfonyConfiguration->getContainerXmlPath()); // @phpstan-ignore phpstanApi.method
        }
    }

    public function getUsages(Node $node, Scope $scope): array
    {
        if (!$this->enabled) {
            return [];
        }

        $usages = [];

        if ($node instanceof InClassNode) { // @phpstan-ignore phpstanApi.instanceofAssumption
            $usages = [
                ...$usages,
                ...$this->getUsagesFromReflection($node),
            ];
        }

        if ($node instanceof Return_) {
            $usages = [
                ...$usages,
                ...$this->getUsagesOfEventSubscriber($node, $scope),
            ];
        }

        return $usages;
    }

    /**
     * @return list<ClassMethodUsage>
     */
    private function getUsagesOfEventSubscriber(Return_ $node, Scope $scope): array
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

        if ($scope->getFunction()->getName() !== 'getSubscribedEvents') {
            return [];
        }

        if (!$scope->getClassReflection()->implementsInterface('Symfony\Component\EventDispatcher\EventSubscriberInterface')) {
            return [];
        }

        $className = $scope->getClassReflection()->getName();

        $usages = [];

        // phpcs:disable Squiz.PHP.CommentedOutCode.Found
        foreach ($scope->getType($node->expr)->getConstantArrays() as $rootArray) {
            foreach ($rootArray->getValuesArray()->getValueTypes() as $eventConfig) {
                // ['eventName' => 'methodName']
                foreach ($eventConfig->getConstantStrings() as $subscriberMethodString) {
                    $usages[] = new ClassMethodUsage(
                        null,
                        new ClassMethodRef(
                            $className,
                            $subscriberMethodString->getValue(),
                            true,
                        ),
                    );
                }

                // ['eventName' => ['methodName', $priority]]
                foreach ($eventConfig->getConstantArrays() as $subscriberMethodArray) {
                    foreach ($subscriberMethodArray->getFirstIterableValueType()->getConstantStrings() as $subscriberMethodString) {
                        $usages[] = new ClassMethodUsage(
                            null,
                            new ClassMethodRef(
                                $className,
                                $subscriberMethodString->getValue(),
                                true,
                            ),
                        );
                    }
                }

                // ['eventName' => [['methodName', $priority], ['methodName', $priority]]]
                foreach ($eventConfig->getConstantArrays() as $subscriberMethodArray) {
                    foreach ($subscriberMethodArray->getIterableValueType()->getConstantArrays() as $innerArray) {
                        foreach ($innerArray->getFirstIterableValueType()->getConstantStrings() as $subscriberMethodString) {
                            $usages[] = new ClassMethodUsage(
                                null,
                                new ClassMethodRef(
                                    $className,
                                    $subscriberMethodString->getValue(),
                                    true,
                                ),
                            );
                        }
                    }
                }
            }
        }

        // phpcs:disable Squiz.PHP.CommentedOutCode.Found

        return $usages;
    }

    /**
     * @return list<ClassMethodUsage>
     */
    private function getUsagesFromReflection(InClassNode $node): array
    {
        $classReflection = $node->getClassReflection();
        $nativeReflection = $classReflection->getNativeReflection();
        $className = $classReflection->getName();

        $usages = [];

        foreach ($nativeReflection->getMethods() as $method) {
            if (isset($this->dicCalls[$className][$method->getName()])) {
                $usages[] = $this->createUsage($classReflection->getNativeMethod($method->getName()));
            }

            if ($method->getDeclaringClass()->getName() !== $nativeReflection->getName()) {
                continue;
            }

            if ($this->shouldMarkAsUsed($method)) {
                $usages[] = $this->createUsage($classReflection->getNativeMethod($method->getName()));
            }
        }

        return $usages;
    }

    protected function shouldMarkAsUsed(ReflectionMethod $method): bool
    {
        return $this->isBundleConstructor($method)
            || $this->isEventListenerMethodWithAsEventListenerAttribute($method)
            || $this->isAutowiredWithRequiredAttribute($method)
            || $this->isConstructorWithAsCommandAttribute($method)
            || $this->isConstructorWithAsControllerAttribute($method)
            || $this->isMethodWithRouteAttribute($method)
            || $this->isProbablySymfonyListener($method);
    }

    protected function fillDicClasses(string $containerXmlPath): void
    {
        $fileContents = file_get_contents($containerXmlPath);

        if ($fileContents === false) {
            throw new LogicException(sprintf('Container %s does not exist', $containerXmlPath));
        }

        $xml = @simplexml_load_string($fileContents);

        if ($xml === false) {
            throw new LogicException(sprintf('Container %s cannot be parsed', $containerXmlPath));
        }

        if (!isset($xml->services->service)) {
            throw new LogicException(sprintf('XML %s does not contain container.services.service structure', $containerXmlPath));
        }

        $serviceMap = $this->buildXmlServiceMap($xml->services->service);

        foreach ($xml->services->service as $serviceDefinition) {
            /** @var SimpleXMLElement $serviceAttributes */
            $serviceAttributes = $serviceDefinition->attributes();
            $class = isset($serviceAttributes->class) ? (string) $serviceAttributes->class : null;
            $constructor = isset($serviceAttributes->constructor) ? (string) $serviceAttributes->constructor : '__construct';

            if ($class === null) {
                continue;
            }

            $this->dicCalls[$class][$constructor] = true;

            foreach ($serviceDefinition->call ?? [] as $callDefinition) {
                /** @var SimpleXMLElement $callAttributes */
                $callAttributes = $callDefinition->attributes();
                $method = $callAttributes->method !== null ? (string) $callAttributes->method : null;

                if ($method === null) {
                    continue;
                }

                $this->dicCalls[$class][$method] = true;
            }

            foreach ($serviceDefinition->factory ?? [] as $factoryDefinition) {
                /** @var SimpleXMLElement $factoryAttributes */
                $factoryAttributes = $factoryDefinition->attributes();
                $factoryClass = $factoryAttributes->class !== null ? (string) $factoryAttributes->class : null;
                $factoryService = $factoryAttributes->service !== null ? (string) $factoryAttributes->service : null;
                $factoryMethod = $factoryAttributes->method !== null ? (string) $factoryAttributes->method : null;

                if ($factoryClass !== null && $factoryMethod !== null) {
                    $this->dicCalls[$factoryClass][$factoryMethod] = true;
                }

                if ($factoryService !== null && $factoryMethod !== null && isset($serviceMap[$factoryService])) {
                    $factoryServiceClass = $serviceMap[$factoryService];
                    $this->dicCalls[$factoryServiceClass][$factoryMethod] = true;
                }
            }
        }
    }

    /**
     * @return array<string, string>
     */
    private function buildXmlServiceMap(SimpleXMLElement $serviceDefinitions): array
    {
        $serviceMap = [];

        foreach ($serviceDefinitions as $serviceDefinition) {
            /** @var SimpleXMLElement $serviceAttributes */
            $serviceAttributes = $serviceDefinition->attributes();
            $id = isset($serviceAttributes->id) ? (string) $serviceAttributes->id : null;
            $class = isset($serviceAttributes->class) ? (string) $serviceAttributes->class : null;

            if ($id === null || $class === null) {
                continue;
            }

            $serviceMap[$id] = $class;
        }

        return $serviceMap;
    }

    protected function isBundleConstructor(ReflectionMethod $method): bool
    {
        return $method->isConstructor() && $method->getDeclaringClass()->isSubclassOf('Symfony\Component\HttpKernel\Bundle\Bundle');
    }

    protected function isAutowiredWithRequiredAttribute(ReflectionMethod $method): bool
    {
        return $this->hasAttribute($method, 'Symfony\Contracts\Service\Attribute\Required');
    }

    protected function isEventListenerMethodWithAsEventListenerAttribute(ReflectionMethod $method): bool
    {
        $class = $method->getDeclaringClass();

        return $this->hasAttribute($class, 'Symfony\Component\EventDispatcher\Attribute\AsEventListener')
            || $this->hasAttribute($method, 'Symfony\Component\EventDispatcher\Attribute\AsEventListener');
    }

    protected function isConstructorWithAsCommandAttribute(ReflectionMethod $method): bool
    {
        $class = $method->getDeclaringClass();
        return $method->isConstructor() && $this->hasAttribute($class, 'Symfony\Component\Console\Attribute\AsCommand');
    }

    protected function isConstructorWithAsControllerAttribute(ReflectionMethod $method): bool
    {
        $class = $method->getDeclaringClass();
        return $method->isConstructor() && $this->hasAttribute($class, 'Symfony\Component\HttpKernel\Attribute\AsController');
    }

    protected function isMethodWithRouteAttribute(ReflectionMethod $method): bool
    {
        return $this->hasAttribute($method, 'Symfony\Component\Routing\Attribute\Route', ReflectionAttribute::IS_INSTANCEOF)
            || $this->hasAttribute($method, 'Symfony\Component\Routing\Annotation\Route', ReflectionAttribute::IS_INSTANCEOF);
    }

    /**
     * Ideally, we would need to parse DIC xml to know this for sure just like phpstan-symfony does.
     */
    protected function isProbablySymfonyListener(ReflectionMethod $method): bool
    {
        $methodName = $method->getName();

        return $methodName === 'onKernelResponse'
            || $methodName === 'onKernelException'
            || $methodName === 'onKernelRequest'
            || $methodName === 'onConsoleError'
            || $methodName === 'onConsoleCommand'
            || $methodName === 'onConsoleSignal'
            || $methodName === 'onConsoleTerminate';
    }

    /**
     * @param ReflectionClass<object>|ReflectionMethod $classOrMethod
     * @param ReflectionAttribute::IS_*|0 $flags
     */
    protected function hasAttribute(Reflector $classOrMethod, string $attributeClass, int $flags = 0): bool
    {
        if (PHP_VERSION_ID < 8_00_00) {
            return false;
        }

        if ($classOrMethod->getAttributes($attributeClass) !== []) {
            return true;
        }

        try {
            /** @throws IdentifierNotFound */
            return $classOrMethod->getAttributes($attributeClass, $flags) !== [];
        } catch (IdentifierNotFound $e) {
            return false; // prevent https://github.com/phpstan/phpstan/issues/9618
        }
    }

    private function isSymfonyInstalled(): bool
    {
        return InstalledVersions::isInstalled('symfony/event-dispatcher')
            || InstalledVersions::isInstalled('symfony/routing')
            || InstalledVersions::isInstalled('symfony/contracts')
            || InstalledVersions::isInstalled('symfony/console')
            || InstalledVersions::isInstalled('symfony/http-kernel');
    }

    private function createUsage(ExtendedMethodReflection $methodReflection): ClassMethodUsage
    {
        return new ClassMethodUsage(
            null,
            new ClassMethodRef(
                $methodReflection->getDeclaringClass()->getName(),
                $methodReflection->getName(),
                false,
            ),
        );
    }

}
