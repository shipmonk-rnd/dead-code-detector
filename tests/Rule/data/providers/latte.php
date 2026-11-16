<?php declare(strict_types = 1);

namespace LatteProvider;

use Latte\Attributes\TemplateFilter;
use Latte\Attributes\TemplateFunction;
use Latte\Engine;
use Nette\Bridges\ApplicationLatte\Template;

class EngineParameters
{
    public string $title = '';

    public EngineChild $child;

    public function getTitle(): string
    {
        return $this->title;
    }

    #[TemplateFilter]
    public function upper(string $s): string
    {
        return $s;
    }

    #[TemplateFunction]
    public function now(): int
    {
        return 0;
    }

    public static function staticNotUsed(): void // error: Unused LatteProvider\EngineParameters::staticNotUsed
    {
    }

    private function privateNotUsed(): void // error: Unused LatteProvider\EngineParameters::privateNotUsed
    {
    }
}

class EngineChild
{
    public function getName(): string
    {
        return '';
    }
}

class ArrayModel
{
    public function getValue(): string
    {
        return '';
    }
}

class DynamicPropertyModel
{
    public function getValue(): string
    {
        return '';
    }
}

class AddedModel
{
    public function getValue(): string
    {
        return '';
    }
}

class ParametersModel
{
    public function getValue(): string
    {
        return '';
    }
}

class TypedTemplate extends Template
{
    public string $title;

    public TypedTemplateModel $model;

    public function helperNotUsed(): void // error: Unused LatteProvider\TypedTemplate::helperNotUsed
    {
    }
}

class TypedTemplateModel
{
    public function getValue(): string
    {
        return '';
    }
}

class TemplateTypeParameters
{
    public string $lang = '';

    public function getLang(): string
    {
        return $this->lang;
    }
}

class VarTypeModel
{
    public function getValue(): string
    {
        return '';
    }
}

class VarTypeItem
{
    public function getValue(): string
    {
        return '';
    }
}

class SingleFileModel
{
    public function getValue(): string
    {
        return '';
    }
}

class NotInTemplate
{
    public function getValue(): string // error: Unused LatteProvider\NotInTemplate::getValue
    {
        return '';
    }
}

class TemplateAttributesOnly
{
    #[TemplateFilter]
    public function filter(): void
    {
    }

    public function notUsed(): void // error: Unused LatteProvider\TemplateAttributesOnly::notUsed
    {
    }
}

function render(Engine $latte, Template $template, TypedTemplate $typedTemplate): void
{
    $params = new EngineParameters();
    $params->child = new EngineChild();

    $latte->render('page.latte', $params);
    $latte->renderToString('page.latte', ['model' => new ArrayModel()]);

    $template->foo = new DynamicPropertyModel();
    $template->add('added', new AddedModel());
    $template->setParameters(['params' => new ParametersModel()]);

    $typedTemplate->title = 'Title';
    $typedTemplate->model = new TypedTemplateModel();
    $typedTemplate->render();
}
