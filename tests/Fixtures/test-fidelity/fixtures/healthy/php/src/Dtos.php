<?php

namespace Healthy;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use JMS\Serializer\Annotation as JMS;
use Symfony\Component\Serializer\Attribute\SerializedName;

enum Tier: string
{
    case Gold = 'gold';
    case Free = 'free';
}

/** A backed enum field, and a defaulted one a fixture may leave out. */
final class Member
{
    public string $id;
    public Tier $tier;
    public ?string $nickname = null;
}

/** Scalar kinds: an int for a float, a dict for an array, null for a nullable. */
final class Invoice
{
    public int $total;
    public ?string $memo;
    public array $lines;
    public float $rate;
}

/** A Doctrine entity: its constructor sets the collection a fixture leaves out. */
class Article
{
    public int $id;
    public string $title;
    private Collection $tags;

    public function __construct()
    {
        $this->tags = new ArrayCollection();
    }
}

/** Promoted properties: the constructor takes them, a default makes one optional. */
final class Coupon
{
    public function __construct(public string $code, public int $percent = 10) {}
}

/** The wire key is the constructor's parameter, not the field it sets. */
final class Price
{
    private int $cents;

    public function __construct(int $amount, public string $currency)
    {
        $this->cents = $amount;
    }
}

/** No constructor; its non-public fields are its own state, never read from a fixture. */
final class Customer
{
    public string $customerId;
    private string $passwordHash;
    protected array $cache;
}

/** Built only through a factory, which reads keys its fields do not name. */
final class Rate
{
    private function __construct(public readonly int $basisPoints) {}

    public static function fromArray(array $data): self
    {
        return new self((int) round($data['percent'] * 100));
    }
}

/** Answers to any property name. */
class Sack
{
    public string $id = '';

    public function __get(string $name): mixed
    {
        return null;
    }
}

/** Inherits its magic from a parent. */
final class Envelope extends Sack
{
    public string $stamp = '';
}

/** Symfony renames the key: the list is open. */
final class RenamedUser
{
    #[SerializedName('user_name')]
    public string $userName;
}

/** JMS renames it in a docblock. */
final class JmsUser
{
    /** @JMS\SerializedName("user_name") */
    public string $userName;
}

/** A bare Symfony docblock tag leaves a field off the wire. */
final class IgnoredSecret
{
    public string $id;

    /** @Ignore */
    public string $secret;
}
