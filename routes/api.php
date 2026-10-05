<?php

/*
| Every API route lives under /api/v1 (decision D8). Each module keeps its
| routes in routes/api/v1/<module>.php; this file only loads them.
*/

foreach (glob(__DIR__.'/api/v1/*.php') ?: [] as $moduleRoutes) {
    require $moduleRoutes;
}
