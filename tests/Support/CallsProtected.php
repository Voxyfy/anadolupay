<?php

declare(strict_types=1);

namespace Voxyfy\AnadoluPay\Tests\Support;

use Closure;

/**
 * Korumalı metotları test içinden çağırmak için yardımcı.
 *
 * Hash üretimi bilinçli olarak korumalıdır; ancak imza algoritmaları
 * bu paketin en kritik parçası olduğu için doğrudan test edilmeleri gerekir.
 */
final class CallsProtected
{
    public static function call(object $object, string $method, mixed ...$arguments): mixed
    {
        $invoker = Closure::bind(
            fn () => $object->{$method}(...$arguments),
            null,
            $object::class,
        );

        return $invoker();
    }
}
