<?php

namespace NetteProvider;

use Nette\Application\UI\Form as UIForm;
use Nette\Application\UI\Presenter;
use Nette\SmartObject;

final class PagePresenter extends Presenter
{

    public function injectDependencies(): void
    {
    }

    public function renderDefault(): void
    {
    }

    protected function createComponentMyForm(): UIForm
    {
        return new UIForm();
    }

    public function handleLogout() {
        $this->redirect('Login:');
    }

    public function HandleCaseInsensitive() { // upper-cased 'H': Nette resolves signals via case-insensitive getMethod(), so this is still a live handler
        $this->redirect('Login:');
    }

}

/**
 * @property float $radius
 * @property-read bool $visible
 * @property array<string, string> $labels
 * @property array{
 *     x: float,
 *     y: float,
 * } $center
 */
class Circle
{
    use SmartObject;

    private float $radius = 0.0; // not public

    protected function getRadius(): float
    {
        return $this->radius;
    }

    protected function setRadius(float $radius): void
    {
        $this->radius = max(0.0, $radius);
    }

    protected function isVisible(): bool
    {
        return $this->radius > 0;
    }

    /**
     * @return array<string, string>
     */
    protected function getLabels(): array
    {
        return [];
    }

    /**
     * @param array<string, string> $labels
     */
    protected function setLabels(array $labels): void
    {
    }

    /**
     * @return array{x: float, y: float}
     */
    protected function getCenter(): array
    {
        return ['x' => 0.0, 'y' => 0.0];
    }
}

$circle = new Circle;
$circle->radius = 10; // actually calls setRadius(10)
echo $circle->radius; // calls getRadius()
echo $circle->visible; // calls isVisible()
$circle->labels = ['color' => 'red']; // calls setLabels()
print_r($circle->labels); // calls getLabels()
echo $circle->center['x']; // calls getCenter()
