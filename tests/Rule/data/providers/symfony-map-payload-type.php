<?php declare(strict_types = 1);

namespace SymfonyMapPayloadType;

use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;

class BatchItemDto {
    public function __construct(
        public readonly string $sku,
    ) {}
}

class BatchController {
    #[Route('/api/batch', methods: ['POST'])]
    public function create(
        #[MapRequestPayload(type: BatchItemDto::class)] array $items,
    ): void {
        foreach ($items as $item) {
            echo $item->sku;
        }
    }
}
