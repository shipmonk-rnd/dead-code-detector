<?php declare(strict_types = 1);

namespace ShipMonk\PHPStan\DeadCode\Provider;

use Composer\Autoload\ClassLoader;
use Composer\InstalledVersions;
use FilesystemIterator;
use LogicException;
use PhpParser\Node;
use PhpParser\Node\Stmt\Return_;
use PHPStan\Analyser\Scope;
use PHPStan\BetterReflection\Reflection\Adapter\ReflectionClass;
use PHPStan\BetterReflection\Reflection\Adapter\ReflectionMethod;
use PHPStan\BetterReflection\Reflector\Exception\IdentifierNotFound;
use PHPStan\DependencyInjection\Container;
use PHPStan\DependencyInjection\ParameterNotFoundException;
use PHPStan\Node\InClassMethodNode;
use PHPStan\Node\InClassNode;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Reflection\ExtendedMethodReflection;
use PHPStan\Reflection\MethodReflection;
use PHPStan\TrinaryLogic;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionAttribute;
use Reflector;
use ShipMonk\PHPStan\DeadCode\Graph\ClassConstantRef;
use ShipMonk\PHPStan\DeadCode\Graph\ClassConstantUsage;
use ShipMonk\PHPStan\DeadCode\Graph\ClassMethodRef;
use ShipMonk\PHPStan\DeadCode\Graph\ClassMethodUsage;
use ShipMonk\PHPStan\DeadCode\Graph\UsageOrigin;
use SimpleXMLElement;
use SplFileInfo;
use UnexpectedValueException;
use function array_filter;
use function array_keys;
use function count;
use function explode;
use function extension_loaded;
use function file_get_contents;
use function in_array;
use function is_dir;
use function is_string;
use function preg_match_all;
use function reset;
use function simplexml_load_string;
use function sprintf;
use function strpos;

class SymfonyUsageProvider implements MemberUsageProvider
{

    private bool $enabled;

    private ?string $configDir;

    /**
     * class => [method => true]
     *
     * @var array<string, array<string, true>>
     */
    private array $dicCalls = [];

    /**
     * class => [constant => config file]
     *
     * @var array<string, array<string, string>>
     */
    private array $dicConstants = [];

    public function __construct(
        Container $container,
        ?bool $enabled,
        ?string $configDir
    )
    {
        $this->enabled = $enabled ?? $this->isSymfonyInstalled();
        $this->configDir = $configDir ?? $this->autodetectConfigDir();
        $containerXmlPath = $this->getContainerXmlPath($container);

        if ($this->enabled && $containerXmlPath !== null) {
            $this->fillDicClasses($containerXmlPath);
        }

        if ($this->enabled && $this->configDir !== null) {
            $this->fillDicConstants($this->configDir);
        }
    }

    public function getUsages(
        Node $node,
        Scope $scope
    ): array
    {
        if (!$this->enabled) {
            return [];
        }

        $usages = [];

        if ($node instanceof InClassNode) { // @phpstan-ignore phpstanApi.instanceofAssumption
            $usages = [
                ...$usages,
                ...$this->getUniqueEntityUsages($node),
                ...$this->getMethodUsagesFromReflection($node),
                ...$this->getConstantUsages($node->getClassReflection()),
            ];
        }

        if ($node instanceof InClassMethodNode) { // @phpstan-ignore phpstanApi.instanceofAssumption
            $usages = [
                ...$usages,
                ...$this->getMethodUsagesFromAttributeReflection($node, $scope),
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
    private function getUniqueEntityUsages(InClassNode $node): array
    {
        $repositoryClass = null;
        $repositoryMethod = null;

        foreach ($node->getClassReflection()->getNativeReflection()->getAttributes() as $attribute) {
            if ($attribute->getName() === 'Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity') {
                $arguments = $attribute->getArguments();

                if (isset($arguments['repositoryMethod']) && is_string($arguments['repositoryMethod'])) {
                    $repositoryMethod = $arguments['repositoryMethod'];
                }
            }

            if ($attribute->getName() === 'Doctrine\ORM\Mapping\Entity') {
                $arguments = $attribute->getArguments();

                if (isset($arguments['repositoryClass']) && is_string($arguments['repositoryClass'])) {
                    $repositoryClass = $arguments['repositoryClass'];
                }
            }
        }

        if ($repositoryClass !== null && $repositoryMethod !== null) {
            $usage = new ClassMethodUsage(
                UsageOrigin::createVirtual($this, VirtualUsageData::withNote('Used in #[UniqueEntity] attribute')),
                new ClassMethodRef(
                    $repositoryClass,
                    $repositoryMethod,
                    false,
                ),
            );
            return [$usage];
        }

        return [];
    }

    /**
     * @return list<ClassMethodUsage>
     */
    private function getUsagesOfEventSubscriber(
        Return_ $node,
        Scope $scope
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

        if ($scope->getFunction()->getName() !== 'getSubscribedEvents') {
            return [];
        }

        if (!$scope->getClassReflection()->implementsInterface('Symfony\Component\EventDispatcher\EventSubscriberInterface')) {
            return [];
        }

        $className = $scope->getClassReflection()->getName();

        $usages = [];
        $usageOrigin = UsageOrigin::createRegular($node, $scope);

        // phpcs:disable Squiz.PHP.CommentedOutCode.Found
        foreach ($scope->getType($node->expr)->getConstantArrays() as $rootArray) {
            foreach ($rootArray->getValuesArray()->getValueTypes() as $eventConfig) {
                // ['eventName' => 'methodName']
                foreach ($eventConfig->getConstantStrings() as $subscriberMethodString) {
                    $usages[] = new ClassMethodUsage(
                        $usageOrigin,
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
                            $usageOrigin,
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
                                $usageOrigin,
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
    private function getMethodUsagesFromReflection(InClassNode $node): array
    {
        $classReflection = $node->getClassReflection();
        $nativeReflection = $classReflection->getNativeReflection();
        $className = $classReflection->getName();

        $usages = [];

        foreach ($nativeReflection->getMethods() as $method) {
            if (isset($this->dicCalls[$className][$method->getName()])) {
                $usages[] = $this->createUsage($classReflection->getNativeMethod($method->getName()), 'Called via DIC');
            }

            if ($method->getDeclaringClass()->getName() !== $nativeReflection->getName()) {
                continue;
            }

            $note = $this->shouldMarkAsUsed($method);

            if ($note !== null) {
                $usages[] = $this->createUsage($classReflection->getNativeMethod($method->getName()), $note);
            }
        }

        return $usages;
    }

    /**
     * @return list<ClassMethodUsage>
     */
    private function getMethodUsagesFromAttributeReflection(
        InClassMethodNode $node,
        Scope $scope
    ): array
    {
        $usages = [];
        $usageOrigin = UsageOrigin::createRegular($node, $scope);

        foreach ($node->getMethodReflection()->getParameters() as $parameter) {
            foreach ($parameter->getAttributes() as $attributeReflection) {
                if ($attributeReflection->getName() === 'Symfony\Component\DependencyInjection\Attribute\AutowireLocator') {
                    $arguments = $attributeReflection->getArgumentTypes();

                    if (!isset($arguments['services']) || !isset($arguments['defaultIndexMethod'])) {
                        continue;
                    }

                    if ($arguments['services']->isArray()->yes()) {
                        $classNames = $arguments['services']->getIterableValueType()->getConstantStrings();
                    } else {
                        $classNames = $arguments['services']->getConstantStrings();
                    }

                    $defaultIndexMethod = $arguments['defaultIndexMethod']->getConstantStrings();

                    if ($classNames === [] || !isset($defaultIndexMethod[0])) {
                        continue;
                    }

                    foreach ($classNames as $className) {
                        $usages[] = new ClassMethodUsage(
                            $usageOrigin,
                            new ClassMethodRef(
                                $className->getValue(),
                                $defaultIndexMethod[0]->getValue(),
                                true,
                            ),
                        );
                    }
                } elseif ($attributeReflection->getName() === 'Symfony\Component\DependencyInjection\Attribute\AutowireIterator') {
                    $arguments = $attributeReflection->getArgumentTypes();

                    if (!isset($arguments['tag']) || !isset($arguments['defaultIndexMethod'])) {
                        continue;
                    }

                    $classNames = $arguments['tag']->getConstantStrings();
                    $defaultIndexMethod = $arguments['defaultIndexMethod']->getConstantStrings();

                    if ($classNames === [] || !isset($defaultIndexMethod[0])) {
                        continue;
                    }

                    foreach ($classNames as $className) {
                        $usages[] = new ClassMethodUsage(
                            UsageOrigin::createRegular($node, $scope),
                            new ClassMethodRef(
                                $className->getValue(),
                                $defaultIndexMethod[0]->getValue(),
                                true,
                            ),
                        );
                    }
                }
            }
        }

        return $usages;
    }

    protected function shouldMarkAsUsed(ReflectionMethod $method): ?string
    {
        if ($this->isBundleConstructor($method)) {
            return 'Bundle constructor (created by Kernel)';
        }

        if ($this->isEventListenerMethodWithAsEventListenerAttribute($method)) {
            return 'Event listener method via #[AsEventListener] attribute';
        }

        if ($this->isMessageHandlerMethodWithAsMessageHandlerAttribute($method)) {
            return 'Message handler method via #[AsMessageHandler] attribute';
        }

        if ($this->isWorkflowEventListenerMethod($method)) {
            return 'Workflow event listener method via workflow attribute';
        }

        if ($this->isAutowiredWithRequiredAttribute($method)) {
            return 'Autowired with #[Required] (called by DIC)';
        }

        if ($this->isConstructorWithAsCommandAttribute($method)) {
            return 'Class has #[AsCommand] attribute';
        }

        if ($this->isConstructorWithAsControllerAttribute($method)) {
            return 'Class has #[AsController] attribute';
        }

        if ($this->isMethodWithRouteAttribute($method)) {
            return 'Route method via #[Route] attribute';
        }

        if ($this->isMethodWithCallbackConstraintAttribute($method)) {
            return 'Callback constraint method via #[Assert\Callback] attribute';
        }

        if ($this->isProbablySymfonyListener($method)) {
            return 'Probable listener method';
        }

        return null;
    }

    protected function fillDicClasses(string $containerXmlPath): void
    {
        $fileContents = file_get_contents($containerXmlPath);

        if ($fileContents === false) {
            throw new LogicException(sprintf('Container %s does not exist', $containerXmlPath));
        }

        if (!extension_loaded('simplexml')) { // should never happen as phpstan-doctrine requires that
            throw new LogicException('Extension simplexml is required to parse DIC xml');
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

            if ($class !== null) {
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

    protected function isMessageHandlerMethodWithAsMessageHandlerAttribute(ReflectionMethod $method): bool
    {
        $class = $method->getDeclaringClass();
        $methodName = $method->getName();

        // Check if this method has the attribute directly (fallback to method name itself if no target specified)
        foreach ($method->getAttributes('Symfony\Component\Messenger\Attribute\AsMessageHandler') as $attribute) {
            $arguments = $attribute->getArguments();
            $targetMethod = $arguments['method'] ?? $arguments[3] ?? $methodName;

            if ($targetMethod === $methodName) {
                return true;
            }
        }

        // Check class-level attributes (fallback to __invoke if no target specified)
        foreach ($class->getAttributes('Symfony\Component\Messenger\Attribute\AsMessageHandler') as $attribute) {
            $arguments = $attribute->getArguments();
            $targetMethod = $arguments['method'] ?? $arguments[3] ?? '__invoke';

            if ($targetMethod === $methodName) {
                return true;
            }
        }

        // Check if any other method points to this method (only if explicitly specified)
        foreach ($class->getMethods() as $otherMethod) {
            if ($otherMethod->getName() === $methodName) {
                continue;
            }

            foreach ($otherMethod->getAttributes('Symfony\Component\Messenger\Attribute\AsMessageHandler') as $attribute) {
                $arguments = $attribute->getArguments();
                $targetMethod = $arguments['method'] ?? $arguments[3] ?? null;
                if ($methodName === $targetMethod) {
                    return true;
                }
            }
        }

        return false;
    }

    protected function isWorkflowEventListenerMethod(ReflectionMethod $method): bool
    {
        return $this->hasAttribute($method, 'Symfony\Component\Workflow\Attribute\AsAnnounceListener')
            || $this->hasAttribute($method, 'Symfony\Component\Workflow\Attribute\AsCompletedListener')
            || $this->hasAttribute($method, 'Symfony\Component\Workflow\Attribute\AsEnterListener')
            || $this->hasAttribute($method, 'Symfony\Component\Workflow\Attribute\AsEnteredListener')
            || $this->hasAttribute($method, 'Symfony\Component\Workflow\Attribute\AsGuardListener')
            || $this->hasAttribute($method, 'Symfony\Component\Workflow\Attribute\AsLeaveListener')
            || $this->hasAttribute($method, 'Symfony\Component\Workflow\Attribute\AsTransitionListener');
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
        $isInstanceOf = 2; // ReflectionAttribute::IS_INSTANCEOF, since PHP 8.0

        return $this->hasAttribute($method, 'Symfony\Component\Routing\Attribute\Route', $isInstanceOf)
            || $this->hasAttribute($method, 'Symfony\Component\Routing\Annotation\Route', $isInstanceOf);
    }

    protected function isMethodWithCallbackConstraintAttribute(ReflectionMethod $method): bool
    {
        $attributes = $method->getDeclaringClass()->getAttributes('Symfony\Component\Validator\Constraints\Callback');

        foreach ($attributes as $attribute) {
            $arguments = $attribute->getArguments();

            $callback = $arguments['callback'] ?? $arguments[0] ?? null;

            if ($callback === $method->getName()) {
                return true;
            }
        }

        return $this->hasAttribute($method, 'Symfony\Component\Validator\Constraints\Callback');
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
     * @param ReflectionClass|ReflectionMethod $classOrMethod
     * @param ReflectionAttribute::IS_*|0 $flags
     */
    protected function hasAttribute(
        Reflector $classOrMethod,
        string $attributeClass,
        int $flags = 0
    ): bool
    {
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
        foreach (InstalledVersions::getInstalledPackages() as $package) {
            if (strpos($package, 'symfony/') === 0) {
                return true;
            }
        }

        return false;
    }

    private function createUsage(
        ExtendedMethodReflection $methodReflection,
        string $reason
    ): ClassMethodUsage
    {
        return new ClassMethodUsage(
            UsageOrigin::createVirtual($this, VirtualUsageData::withNote($reason)),
            new ClassMethodRef(
                $methodReflection->getDeclaringClass()->getName(),
                $methodReflection->getName(),
                false,
            ),
        );
    }

    private function autodetectConfigDir(): ?string
    {
        $vendorDirs = array_filter(array_keys(ClassLoader::getRegisteredLoaders()), static function (string $vendorDir): bool {
            return strpos($vendorDir, 'phar://') === false;
        });

        if (count($vendorDirs) !== 1) {
            return null;
        }

        $vendorDir = reset($vendorDirs);
        $configDir = $vendorDir . '/../config';

        if (is_dir($configDir)) {
            return $configDir;
        }

        return null;
    }

    private function fillDicConstants(string $configDir): void
    {
        try {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($configDir, FilesystemIterator::SKIP_DOTS),
            );
        } catch (UnexpectedValueException $e) {
            throw new LogicException("Provided config path '$configDir' is not a directory", 0, $e);
        }

        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if (
                $file->isFile()
                && in_array($file->getExtension(), ['yaml', 'yml'], true)
                && $file->getRealPath() !== false
            ) {
                $this->extractYamlConstants($file->getRealPath());
            }
        }
    }

    private function extractYamlConstants(string $yamlFile): void
    {
        $dicFileContents = file_get_contents($yamlFile);

        if ($dicFileContents === false) {
            return;
        }

        $nameRegex = '[a-zA-Z_\x80-\xff][a-zA-Z0-9_\x80-\xff]*'; // https://www.php.net/manual/en/language.oop5.basic.php

        preg_match_all(
            "~!php/const ($nameRegex(?:\\\\$nameRegex)+::$nameRegex)~",
            $dicFileContents,
            $matches,
        );

        foreach ($matches[1] as $usedConstants) {
            [$className, $constantName] = explode('::', $usedConstants); // @phpstan-ignore offsetAccess.notFound
            $this->dicConstants[$className][$constantName] = $yamlFile;
        }
    }

    /**
     * @return list<ClassConstantUsage>
     */
    private function getConstantUsages(ClassReflection $classReflection): array
    {
        $usages = [];

        foreach ($this->dicConstants[$classReflection->getName()] ?? [] as $constantName => $configFile) {
            if (!$classReflection->hasConstant($constantName)) {
                continue;
            }

            $usages[] = new ClassConstantUsage(
                UsageOrigin::createVirtual($this, VirtualUsageData::withNote('Referenced in config in ' . $configFile)),
                new ClassConstantRef(
                    $classReflection->getName(),
                    $constantName,
                    false,
                    TrinaryLogic::createNo(),
                ),
            );
        }

        return $usages;
    }

    private function getContainerXmlPath(Container $container): ?string
    {
        try {
            /** @var array{containerXmlPath: string|null} $symfonyConfig */
            $symfonyConfig = $container->getParameter('symfony');

            return $symfonyConfig['containerXmlPath'];
        } catch (ParameterNotFoundException $e) {
            return null;
        }
    }

}
