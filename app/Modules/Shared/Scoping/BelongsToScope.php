<?php

namespace App\Modules\Shared\Scoping;

/**
 * Add to every model whose rows belong to part of the network, together with
 * the HasScopeColumns interface. Queries are then always filtered by the
 * caller's scope; rows outside it behave as if they did not exist, so route
 * model binding returns 404 (spec §4.4).
 */
trait BelongsToScope
{
    public static function bootBelongsToScope(): void
    {
        static::addGlobalScope(new ScopeFilter);
    }
}
