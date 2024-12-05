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

}

/**
 * @property float $radius
 * @property-read bool $visible
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
}

$circle = new Circle;
$circle->radius = 10; // actually calls setRadius(10)
echo $circle->radius; // calls getRadius()
echo $circle->visible; // calls isVisible()
