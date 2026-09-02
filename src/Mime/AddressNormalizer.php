<?php

declare(strict_types=1);

namespace Rudisang\Mailbox\Mime;

use Symfony\Component\Mime\Address;

/** @internal */
final class AddressNormalizer
{
    /**
     * @param  list<Address>  $addresses
     * @return list<array{address: string, name: string}>
     */
    public static function normalize(array $addresses): array
    {
        return array_map(fn (Address $address) => [
            'address' => $address->getAddress(),
            'name' => $address->getName(),
        ], $addresses);
    }

    /**
     * @param  list<Address>  $addresses
     * @return list<string>
     */
    public static function emails(array $addresses): array
    {
        return array_map(fn (Address $address) => $address->getAddress(), $addresses);
    }
}
