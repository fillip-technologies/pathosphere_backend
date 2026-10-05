<?php

namespace App\Modules\Shared\Scoping;

/** Implemented by every model that uses BelongsToScope. */
interface HasScopeColumns
{
    /** Which columns place this model's rows in the network. */
    public static function scopeColumns(): ScopeColumns;
}
