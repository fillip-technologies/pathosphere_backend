<?php

namespace App\Modules\Network\Services;

use App\Modules\Network\Models\FranchiseAgreement;

/** The agreement PDF's content: the parties and the commercial terms (spec §5.1 step 3). */
final class AgreementDocumentBuilder
{
    public function __construct(private readonly NetworkDirectory $network) {}

    public function html(FranchiseAgreement $agreement): string
    {
        return view('agreements.agreement', ['document' => [
            'brand' => $this->network->organizationName($agreement->organization_id),
            'agreement' => $agreement,
            'franchise' => $agreement->franchise,
            'pincodes' => $agreement->pincodeList(),
        ]])->render();
    }
}
