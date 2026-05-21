<?php

namespace ApiPlatformApp;

use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\GraphQl\Query;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\FilterInterface;
use ApiPlatform\State\ProcessorInterface;
use ApiPlatform\State\ProviderInterface;

// Case 1: plain #[ApiResource] — properties are deserialized/serialized, ctor is invoked

#[ApiResource]
class Book
{

    public function __construct(
        public string $title,
    )
    {
    }

    public int $pages;

    private string $description;

    public function getDescription(): string
    {
        return $this->description;
    }

    public function setDescription(string $description): void
    {
        $this->description = $description;
    }

    public function isActive(): bool
    {
        return true;
    }

    public function deadHelper(): void // error: Unused ApiPlatformApp\Book::deadHelper
    {
    }

}

// Case 2: only operation attribute (no #[ApiResource]) still marks the class as resource

#[Get]
#[GetCollection]
class Author
{

    public string $name;

    private int $age;

    public function getAge(): int
    {
        return $this->age;
    }

    public function setAge(int $age): void
    {
        $this->age = $age;
    }

    public function privateDead(): void // error: Unused ApiPlatformApp\Author::privateDead
    {
    }

}

// Case 3: provider/processor/controller class-string references

class BookProvider implements ProviderInterface
{

    public function __construct(private string $dsn)
    {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): object|array|null
    {
        return $this->dsn === '' ? null : [];
    }

}

class BookProcessor implements ProcessorInterface
{

    public function __construct(private string $bus)
    {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): void
    {
        echo $this->bus;
    }

}

class BookController
{

    public function __construct(private string $logger)
    {
    }

    public function __invoke(): void
    {
        echo $this->logger;
    }

}

#[Get(provider: BookProvider::class, processor: BookProcessor::class, controller: BookController::class)]
#[Post(processor: BookProcessor::class)]
#[Delete(provider: 'ApiPlatformApp\BookProvider::provide')]
class Review
{

    public int $rating;

    public function getRating(): int
    {
        return $this->rating;
    }

}

// Case 4: GraphQL operation marks class as resource too

#[Query]
class Tag
{

    public string $label;

    public function dead(): void // error: Unused ApiPlatformApp\Tag::dead
    {
    }

}

// Case 5: ApiFilter references a filter class — its ctor is invoked by DIC

class MyFilter implements FilterInterface
{

    public function __construct()
    {
    }

    public function getDescription(string $resourceClass): array
    {
        return [];
    }

}

#[ApiResource]
#[ApiFilter(MyFilter::class)]
class Article
{

    public string $title;

    #[ApiFilter(MyFilter::class)]
    public string $slug;

}

// Case 6: a plain class without any API Platform attribute still reports dead members

class NotAResource
{

    public string $neverUsedProp; // error: Property ApiPlatformApp\NotAResource::$neverUsedProp is never read // error: Property ApiPlatformApp\NotAResource::$neverUsedProp is never written

    public function neverUsed(): void // error: Unused ApiPlatformApp\NotAResource::neverUsed
    {
    }

}
