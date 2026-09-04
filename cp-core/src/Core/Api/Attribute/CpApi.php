<?php

declare(strict_types=1);

namespace App\Core\Api\Attribute;

/**
 * Exposes a service method under /api via the gateway. Not repeatable: one path+methods contract per method.
 * $path is the suffix after /api — do not include the /api prefix yourself.
 */
#[\Attribute(\Attribute::TARGET_METHOD)]
final class CpApi
{
    /**
     * @param string        $path    Path after /api (e.g. "/blog/posts/{id}").
     * @param list<string>  $methods Allowed HTTP methods.
     * @param bool          $public  Skip X-CP-API-KEY when true (default false).
     */
    public function __construct(
        public readonly string $path,
        public readonly array $methods = ['GET'],
        public readonly bool $public = false,
    ) {
    }
}
