<?php

namespace App\Modules\Catalogue\Enums;

/** Which qualification may sign a department's results (spec §7.2, §10). */
enum SigningDiscipline: string
{
    case Pathology = 'pathology';
    case Microbiology = 'microbiology';
    case Biochemistry = 'biochemistry';
}
