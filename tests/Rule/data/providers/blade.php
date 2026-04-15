<?php declare(strict_types = 1);

namespace Blade;

use Illuminate\Contracts\View\View;
use Illuminate\Routing\Controller;
use Illuminate\Routing\ResponseFactory;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\View as ViewFacade;
use Illuminate\View\Factory as ViewFactory;

// --- Models for view() helper ---

final class ViewHelperSimpleModel
{
    public function getName(): string { return 'name'; }
}

final class ViewHelperNestedModel
{
    public function getValue(): string { return 'nested'; }

    public function getDeep(): ViewHelperDeepModel { return new ViewHelperDeepModel(); }
}

final class ViewHelperDeepModel
{
    public function getDeepValue(): string { return 'deep'; }
}

// --- Models for View::make() ---

final class ViewMakeModel
{
    public function getName(): string { return 'make'; }
}

// --- Models for View::first() ---

final class ViewFirstModel
{
    public function getName(): string { return 'first'; }
}

// --- Models for response()->view() ---

final class ResponseViewModel
{
    public function getName(): string { return 'response'; }
}

// --- Models for ->with() chaining ---

final class WithKeyValueModel
{
    public function getName(): string { return 'with-kv'; }
}

final class WithArrayModel
{
    public function getName(): string { return 'with-array'; }
}

// --- Circular reference models ---

final class BladeCircularA
{
    public function getB(): BladeCircularB { return new BladeCircularB(); }
}

final class BladeCircularB
{
    public function getA(): BladeCircularA { return new BladeCircularA(); }
}

// --- Model with public properties ---

final class BladePropertyModel
{
    public function __construct(
        public string $publicValue,
        public ViewHelperNestedModel $nestedData,
    )
    {
    }

    public function getMethod(): string { return 'value'; }
}

// --- Models for factory->make() ---

final class FactoryMakeModel
{
    public function getName(): string { return 'factory'; }
}

// --- View Composer/Creator ---

final class BreadcrumbsViewComposer
{

    public function __construct()
    {
    }

    public function compose(\Illuminate\Contracts\View\View $view): void
    {
    }

}

final class SidebarViewCreator
{

    public function __construct()
    {
    }

    public function create(\Illuminate\Contracts\View\View $view): void
    {
    }

}

final class CustomMethodComposer
{

    public function __construct()
    {
    }

    public function customCompose(\Illuminate\Contracts\View\View $view): void
    {
    }

}

final class FactoryComposer
{

    public function __construct()
    {
    }

    public function compose(\Illuminate\Contracts\View\View $view): void
    {
    }

}

// --- Unused model ---

final class BladeUnusedModel
{
    public function unusedMethod(): string { return 'unused'; } // error: Unused Blade\BladeUnusedModel::unusedMethod
}

// --- Controller ---

class BladeController extends Controller
{

    public function __construct(
        private readonly ResponseFactory $responseFactory,
        private readonly ViewFactory $viewFactory,
    )
    {
    }

    public function viewHelper(): View
    {
        return view('blade.simple', [
            'model' => new ViewHelperSimpleModel(),
        ]);
    }

    public function viewHelperNested(): View
    {
        return view('blade.nested', [
            'nested' => new ViewHelperNestedModel(),
        ]);
    }

    public function viewHelperCircular(): View
    {
        return view('blade.circular', [
            'data' => new BladeCircularA(),
        ]);
    }

    public function viewHelperProperty(): View
    {
        return view('blade.property', [
            'model' => new BladePropertyModel('test', new ViewHelperNestedModel()),
        ]);
    }

    public function viewMake(): View
    {
        return ViewFacade::make('blade.make', [
            'model' => new ViewMakeModel(),
        ]);
    }

    public function viewFirst(): View
    {
        return ViewFacade::first(['blade.first1', 'blade.first2'], [
            'model' => new ViewFirstModel(),
        ]);
    }

    public function responseView(): \Illuminate\Http\Response
    {
        return $this->responseFactory->view('blade.response', [
            'model' => new ResponseViewModel(),
        ]);
    }

    public function withKeyValue(): View
    {
        return view('blade.with-kv')->with('model', new WithKeyValueModel());
    }

    public function withArray(): View
    {
        return view('blade.with-array')->with([
            'model' => new WithArrayModel(),
        ]);
    }

    public function factoryMake(): View
    {
        return $this->viewFactory->make('blade.factory', [
            'model' => new FactoryMakeModel(),
        ]);
    }

    public function factoryComposer(): void
    {
        $this->viewFactory->composer('layouts.main', FactoryComposer::class);
    }

    public function viewMakeNoData(): View // error: Unused Blade\BladeController::viewMakeNoData
    {
        return ViewFacade::make('blade.no-data');
    }

    public function noView(): array // error: Unused Blade\BladeController::noView
    {
        return ['unused' => new BladeUnusedModel()];
    }

}

// --- Route and View registrations ---

function registerBladeRoutes(): void
{
    Route::get('/view-helper', [BladeController::class, 'viewHelper']);
    Route::get('/view-helper-nested', [BladeController::class, 'viewHelperNested']);
    Route::get('/view-helper-circular', [BladeController::class, 'viewHelperCircular']);
    Route::get('/view-helper-property', [BladeController::class, 'viewHelperProperty']);
    Route::get('/view-make', [BladeController::class, 'viewMake']);
    Route::get('/view-first', [BladeController::class, 'viewFirst']);
    Route::get('/response-view', [BladeController::class, 'responseView']);
    Route::get('/with-key-value', [BladeController::class, 'withKeyValue']);
    Route::get('/with-array', [BladeController::class, 'withArray']);
    Route::get('/factory-make', [BladeController::class, 'factoryMake']);
    Route::get('/factory-composer', [BladeController::class, 'factoryComposer']);

    ViewFacade::composer('layouts.app', BreadcrumbsViewComposer::class);
    ViewFacade::creator('partials.sidebar', SidebarViewCreator::class);

    // Class@method syntax
    ViewFacade::composer('layouts.admin', 'Blade\CustomMethodComposer@customCompose');
}
