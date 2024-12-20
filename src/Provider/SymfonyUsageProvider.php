<?php declare(strict_types = 1);

namespace ShipMonk\PHPStan\DeadCode\Provider;

use Composer\InstalledVersions;
use PhpParser\Node;
use PhpParser\Node\Stmt\Return_;
use PHPStan\Analyser\Scope;
use PHPStan\BetterReflection\Reflector\Exception\IdentifierNotFound;
use PHPStan\Node\InClassNode;
use PHPStan\Reflection\ExtendedMethodReflection;
use PHPStan\Reflection\MethodReflection;
use PHPStan\Symfony\ServiceMapFactory;
use ReflectionAttribute;
use ReflectionClass;
use ReflectionMethod;
use Reflector;
use ShipMonk\PHPStan\DeadCode\Graph\ClassMethodRef;
use ShipMonk\PHPStan\DeadCode\Graph\ClassMethodUsage;
use const PHP_VERSION_ID;

class SymfonyUsageProvider implements MemberUsageProvider
{

    private bool $enabled;

    /**
     * @var array<string, true>
     */
    private array $dicClasses = [];

    public function __construct(
        ?ServiceMapFactory $serviceMapFactory,
        ?bool $enabled
    )
    {
        $this->enabled = $enabled ?? $this->isSymfonyInstalled();

        if ($serviceMapFactory !== null) {
            $this->fillDicClasses($serviceMapFactory);
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

        // phpcs:enable // phpcs:disable Squiz.PHP.CommentedOutCode.Found

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
            if ($method->isConstructor() && isset($this->dicClasses[$className])) {
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

    protected function fillDicClasses(ServiceMapFactory $serviceMapFactory): void
    {
        foreach ($serviceMapFactory->create()->getServices() as $service) { // @phpstan-ignore phpstanApi.method
            $dicClass = $service->getClass();

            if ($dicClass === null) {
                continue;
            }

            $this->dicClasses[$dicClass] = true;
        }
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
