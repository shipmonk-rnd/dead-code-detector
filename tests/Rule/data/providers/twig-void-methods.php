<?php declare(strict_types = 1);

namespace TwigVoidMethods;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

final class ViewModel
{
    private string $name = '';

    public function getName(): string { return $this->name; }

    public function setName(string $name): void { $this->name = $name; }

    public function getNested(): NestedViewModel { return new NestedViewModel(); }
}

final class NestedViewModel
{
    public function getValue(): string { return 'nested'; }

    public function reset(): void {}

    /**
     * @return void
     */
    public function clear() {}
}

#[AsTwigComponent]
final class VoidComponent
{
    public function exposedVoidMethod(): void {}
}

final class TestController extends AbstractController
{

    #[Route('/void-methods')]
    public function voidMethods(): Response
    {
        return $this->render('void.html.twig', [
            'model' => new ViewModel(),
        ]);
    }

}
