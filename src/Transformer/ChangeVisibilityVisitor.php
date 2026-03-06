<?php declare(strict_types = 1);

namespace ShipMonk\PHPStan\DeadCode\Transformer;

use PhpParser\Node;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Stmt\ClassConst;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Namespace_;
use PhpParser\Node\Stmt\Property;
use PhpParser\NodeVisitorAbstract;
use ShipMonk\PHPStan\DeadCode\Enum\MemberType;
use ShipMonk\PHPStan\DeadCode\Enum\Visibility;
use function is_string;
use function ltrim;

final class ChangeVisibilityVisitor extends NodeVisitorAbstract
{

    private string $currentNamespace = '';

    private string $currentClass = '';

    /**
     * @var array<string, array<int, array<string, int>>> className => [memberType => [memberName => newVisibility]]
     */
    private array $visibilityChanges;

    /**
     * @param array<string, array<int, array<string, int>>> $visibilityChanges className => [memberType => [memberName => newVisibility]]
     */
    public function __construct(
        array $visibilityChanges
    )
    {
        $this->visibilityChanges = $visibilityChanges;
    }

    public function enterNode(Node $node): ?Node
    {
        if ($node instanceof Namespace_ && $node->name !== null) {
            $this->currentNamespace = $node->name->toString();

        } elseif ($node instanceof ClassLike && $node->name !== null) {
            $this->currentClass = $node->name->name;
        }

        return null;
    }

    public function leaveNode(Node $node): ?Node
    {
        if ($node instanceof ClassMethod) {
            $newVisibility = $this->visibilityChanges[$this->getCurrentClass()][MemberType::METHOD][$node->name->name] ?? null;

            if ($newVisibility !== null) {
                $node->flags = ($node->flags & ~(Visibility::PUBLIC | Visibility::PROTECTED | Visibility::PRIVATE)) | $newVisibility;
            }

            // Handle promoted properties in constructor parameters
            foreach ($node->params as $param) {
                if (!$param->isPromoted() || !$param->var instanceof Variable || !is_string($param->var->name)) {
                    continue;
                }

                $newVisibility = $this->visibilityChanges[$this->getCurrentClass()][MemberType::PROPERTY][$param->var->name] ?? null;

                if ($newVisibility !== null) {
                    $param->flags = ($param->flags & ~(Visibility::PUBLIC | Visibility::PROTECTED | Visibility::PRIVATE)) | $newVisibility;
                }
            }
        }

        if ($node instanceof ClassConst) {
            foreach ($node->consts as $const) {
                $newVisibility = $this->visibilityChanges[$this->getCurrentClass()][MemberType::CONSTANT][$const->name->name] ?? null;

                if ($newVisibility !== null) {
                    $node->flags = ($node->flags & ~(Visibility::PUBLIC | Visibility::PROTECTED | Visibility::PRIVATE)) | $newVisibility;
                    break; // all consts in a ClassConst share the same visibility
                }
            }
        }

        if ($node instanceof Property) {
            foreach ($node->props as $prop) {
                $newVisibility = $this->visibilityChanges[$this->getCurrentClass()][MemberType::PROPERTY][$prop->name->name] ?? null;

                if ($newVisibility !== null) {
                    $node->flags = ($node->flags & ~(Visibility::PUBLIC | Visibility::PROTECTED | Visibility::PRIVATE)) | $newVisibility;
                    break; // all props in a Property share the same visibility
                }
            }
        }

        return null;
    }

    private function getCurrentClass(): string
    {
        return ltrim($this->currentNamespace . '\\' . $this->currentClass, '\\');
    }

}
