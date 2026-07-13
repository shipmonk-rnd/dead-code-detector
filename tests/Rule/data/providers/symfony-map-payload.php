<?php declare(strict_types = 1);

namespace SymfonyMapPayload;

use Attribute;
use Symfony\Component\HttpKernel\Attribute\MapQueryString;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use function array_diff;
use function array_values;
use function print_r;

class CreateUserDto {
    public function __construct(
        public readonly string $name,
        public readonly string $email,
    ) {}
}

class MapPayloadController {
    #[Route('/api/users', methods: ['POST'])]
    public function create(
        #[MapRequestPayload] CreateUserDto $dto,
    ): void {
        echo $dto->name;
        echo $dto->email;
    }
}

class QueryStringDto {
    public function __construct(
        public readonly int $page,
        public readonly int $limit, // error: Property SymfonyMapPayload\QueryStringDto::$limit is never read
    ) {}
}

class QueryStringController {
    #[Route('/api/items')]
    public function list(
        #[MapQueryString] QueryStringDto $query,
    ): void {
        echo $query->page;
    }
}

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

#[Attribute(Attribute::TARGET_PARAMETER)]
class CustomMapPayload extends MapRequestPayload {}

class CustomAttributeDto {
    public function __construct(
        public readonly string $title,
    ) {}
}

class CustomAttributeController {
    #[Route('/api/custom', methods: ['POST'])]
    public function create(
        #[CustomMapPayload] CustomAttributeDto $dto,
    ): void {
        echo $dto->title;
    }
}

class NullableQueryDto {
    public function __construct(
        public readonly string $sort,
    ) {}
}

class NullableQueryController {
    #[Route('/api/nullable')]
    public function list(
        #[MapQueryString] ?NullableQueryDto $query = null,
    ): void {
        echo $query?->sort;
    }
}

class SetterBasedDto {
    private string $name; // error: Property SymfonyMapPayload\SetterBasedDto::$name is never read

    /** @var array<string, string> */
    private array $extras = []; // error: Property SymfonyMapPayload\SetterBasedDto::$extras is never read

    public function setName(string $name): void
    {
        $this->name = $name;
    }

    public function setNickname(string $nickname): void // setter without backing property, still called by PropertyAccessor
    {
        $this->extras['nickname'] = $nickname;
    }

    protected function setInternal(string $value): void // error: Unused SymfonyMapPayload\SetterBasedDto::setInternal
    {
        $this->extras['internal'] = $value;
    }

    public function setDefaults(): void // error: Unused SymfonyMapPayload\SetterBasedDto::setDefaults
    {
        $this->extras = [];
    }
}

class SetterController {
    #[Route('/api/setter')]
    public function create(
        #[MapRequestPayload] SetterBasedDto $dto,
    ): void {}
}

class UnwritablePropsDto {
    public string $writable;

    private string $untouched; // error: Property SymfonyMapPayload\UnwritablePropsDto::$untouched is never read // error: Property SymfonyMapPayload\UnwritablePropsDto::$untouched is never written
}

class UnwritablePropsController {
    #[Route('/api/unwritable', methods: ['POST'])]
    public function create(
        #[MapRequestPayload] UnwritablePropsDto $dto,
    ): void {
        echo $dto->writable;
    }
}

class NestedAddressDto {
    public function __construct(
        public readonly string $city,
        public readonly string $zip, // error: Property SymfonyMapPayload\NestedAddressDto::$zip is never read
    ) {}
}

class NestedMetadataDto {
    private ?string $source = null; // error: Property SymfonyMapPayload\NestedMetadataDto::$source is never read

    public function setSource(string $source): void
    {
        $this->source = $source;
    }
}

class OrderDto {
    private ?NestedMetadataDto $metadata = null; // error: Property SymfonyMapPayload\OrderDto::$metadata is never read

    public function __construct(
        public readonly string $number,
        public readonly NestedAddressDto $shippingAddress,
    ) {}

    public function setMetadata(NestedMetadataDto $metadata): void
    {
        $this->metadata = $metadata;
    }
}

class OrderController {
    #[Route('/api/orders', methods: ['POST'])]
    public function create(
        #[MapRequestPayload] OrderDto $dto,
    ): void {
        echo $dto->number;
        echo $dto->shippingAddress->city;
    }
}

abstract class AbstractPayloadDto {
    public ?string $requestId = null;

    private ?string $locale = null; // error: Property SymfonyMapPayload\AbstractPayloadDto::$locale is never read

    public function setLocale(string $locale): void
    {
        $this->locale = $locale;
    }
}

class ChildPayloadDto extends AbstractPayloadDto {
    public ?string $comment = null;
}

class ChildPayloadController {
    #[Route('/api/child', methods: ['POST'])]
    public function create(
        #[MapRequestPayload] ChildPayloadDto $dto,
    ): void {
        echo $dto->requestId;
        echo $dto->comment;
    }
}

class CollectionDto {
    /** @var list<string> */
    private array $tags = [];

    public function addTag(string $tag): void
    {
        $this->tags[] = $tag;
    }

    public function removeTag(string $tag): void
    {
        $this->tags = array_values(array_diff($this->tags, [$tag]));
    }

    public function addOrphan(string $value): void // error: Unused SymfonyMapPayload\CollectionDto::addOrphan
    {
        $this->tags[] = $value;
    }

    /** @return list<string> */
    public function getTags(): array
    {
        return $this->tags;
    }
}

class CollectionController {
    #[Route('/api/collection', methods: ['POST'])]
    public function create(
        #[MapRequestPayload] CollectionDto $dto,
    ): void {
        print_r($dto->getTags());
    }
}
