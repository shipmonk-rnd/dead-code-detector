<?php declare(strict_types = 1);

namespace TwigTemplate;

use Symfony\Bridge\Twig\Attribute\Template;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use TwigTemplateOutside\TemplateModelOutsideOfAnalysedPaths;

// Models for #[Template] attribute tests
final class TemplateSimpleModel
{
    public function __construct(private string $name) {}
    public function getName(): string { return $this->name; }
}

final class TemplateNestedData
{
    public function getValue(): string { return 'nested'; }
    public function getDeep(): TemplateDeepData { return new TemplateDeepData(); }
}

final class TemplateDeepData
{
    public function getDeepValue(): string { return 'deep'; }
}

final class TemplateParentModel
{
    public function getNested(): TemplateNestedData { return new TemplateNestedData(); }
}

final class TemplateCircularA
{
    public function getB(): TemplateCircularB { return new TemplateCircularB(); }
}

final class TemplateCircularB
{
    public function getA(): TemplateCircularA { return new TemplateCircularA(); }
}

final class TemplateModelWithProperty
{
    public function __construct(
        public string $publicValue,
        public TemplateNestedData $nestedData,
    ) {}
    public function getMethod(): string { return 'value'; }
}
final class TemplateModelReferencedFromOutsideShouldNotBeTraversed
{
    public function getName(): string { return 'outside'; } // error: Unused TwigTemplate\TemplateModelReferencedFromOutsideShouldNotBeTraversed::getName
}

// Models for $this->render() method tests
final class RenderSimpleModel
{
    public function __construct(private string $name) {}
    public function getName(): string { return $this->name; }
}

final class RenderNestedData
{
    public function getValue(): string { return 'nested'; }
    public function getDeep(): RenderDeepData { return new RenderDeepData(); }
}

final class RenderDeepData
{
    public function getDeepValue(): string { return 'deep'; }
}

final class RenderParentModel
{
    public function getNested(): RenderNestedData { return new RenderNestedData(); }
}

final class RenderCircularA
{
    public function getB(): RenderCircularB { return new RenderCircularB(); }
}

final class RenderCircularB
{
    public function getA(): RenderCircularA { return new RenderCircularA(); }
}

final class RenderModelWithProperty
{
    public function __construct(
        public string $publicValue,
        public RenderNestedData $nestedData,
    ) {}
    public function getMethod(): string { return 'value'; }
}

final class UnusedModel
{
    public function unusedMethod(): string { return 'unused'; } // error: Unused TwigTemplate\UnusedModel::unusedMethod
}

final class NonControllerModel
{
    public function nonControllerMethod(): string { return 'should be dead'; } // error: Unused TwigTemplate\NonControllerModel::nonControllerMethod
}

final class NotAController
{

    /**
     * @param array<string, mixed> $parameters
     */
    private function render(string $view, array $parameters = []): Response // error: Unused TwigTemplate\NotAController::render
    {
        return new Response();
    }

    public function someMethod(): Response // error: Unused TwigTemplate\NotAController::someMethod
    {
        return $this->render('template.twig', [
            'model' => new NonControllerModel(),
        ]);
    }

}

// Controller extending AbstractController
final class TestController extends AbstractController
{

    // #[Template] attribute tests
    #[Route('/template-simple')]
    #[Template('simple.html.twig')]
    public function templateSimple(): array
    {
        return ['model' => new TemplateSimpleModel('test')];
    }

    #[Route('/template-nested')]
    #[Template('nested.html.twig')]
    public function templateNested(): array
    {
        return ['parent' => new TemplateParentModel()];
    }

    #[Route('/template-circular')]
    #[Template('circular.html.twig')]
    public function templateCircular(): array
    {
        return ['data' => new TemplateCircularA()];
    }

    #[Route('/template-property')]
    #[Template('property.html.twig')]
    public function templateProperty(): array
    {
        return ['model' => new TemplateModelWithProperty('test', new TemplateNestedData())];
    }

    // $this->render() method tests
    #[Route('/render-simple')]
    public function renderSimple(): Response
    {
        return $this->render('simple.html.twig', [
            'model' => new RenderSimpleModel('test'),
        ]);
    }

    #[Route('/render-nested')]
    public function renderNested(): Response
    {
        return $this->render('nested.html.twig', [
            'parent' => new RenderParentModel(),
        ]);
    }

    #[Route('/render-circular')]
    public function renderCircular(): Response
    {
        return $this->render('circular.html.twig', [
            'data' => new RenderCircularA(),
        ]);
    }

    #[Route('/render-property')]
    public function renderProperty(): Response
    {
        return $this->render('property.html.twig', [
            'model' => new RenderModelWithProperty('test', new RenderNestedData()),
        ]);
    }

    // renderView() method test
    #[Route('/render-view-test')]
    public function renderViewTest(): string
    {
        return $this->renderView('view.html.twig', [
            'model' => new RenderSimpleModel('renderView'),
        ]);
    }

    // renderBlock() method test
    #[Route('/render-block-test')]
    public function renderBlockTest(): Response
    {
        return $this->renderBlock('block.html.twig', 'content', [
            'data' => new RenderNestedData(),
        ]);
    }

    // stream() method test
    #[Route('/stream-test')]
    public function streamTest(): Response
    {
        return $this->stream('stream.html.twig', [
            'circular' => new RenderCircularA(),
        ]);
    }

    #[Route('/no-template')]
    public function noTemplate(): array
    {
        return ['unused' => new UnusedModel()];
    }

    #[Route('/response')]
    #[Template('response.html.twig')]
    public function outsideAnalysedPaths(): array
    {
        return ['outside' => new TemplateModelOutsideOfAnalysedPaths()];
    }

}
