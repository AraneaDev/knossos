<?php

namespace Fields;

use JMS\Serializer\Annotation as JMS;
use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Serializer\Attribute\SerializedName;

class SymfonyRenamed
{
    #[SerializedName('user_name')]
    public string $userName;
}

class SymfonyGrouped
{
    public function __construct(
        #[Groups(['read'])]
        public string $id,
    ) {
    }
}

#[MapInputName(SnakeCaseMapper::class)]
class SpatieMapped
{
    public string $firstName;
}

class JmsRenamed
{
    /**
     * @JMS\SerializedName("user_name")
     */
    public string $userName;
}

/**
 * @Serializer\AccessType("public_method")
 */
class JmsClassTag
{
    public string $id;
}

class AliasedAttribute
{
    #[\Other\SerializedPath('[a][b]')]
    public string $id;
}

class Unrelated
{
    #[\Doctrine\ORM\Mapping\Column]
    public string $id;

    /** @var string the id, grouped by @see Groups */
    public string $documented;
}

class Magic
{
    public string $id = '';

    public function __get(string $name): mixed
    {
        return null;
    }

    public function __set(string $name, mixed $value): void
    {
    }
}

class Typed
{
    public (\Countable&\Traversable)|null $either;
    public \Countable&\Traversable $both;
    public int|string $scalar;
}

/** Symfony's bare docblock tags, imported under their short names. */
class SymfonyIgnored
{
    public string $id;

    /** @Ignore */
    public string $secret;
}

class SymfonyDepth
{
    /**
     * @MaxDepth(2)
     */
    public array $children;
}

/** Tags that only end in a serializer's name. */
class NotSerializerTags
{
    /** @codeCoverageIgnore @IgnoreAnnotation("x") @MaxDepthLimit */
    public string $id;
}
