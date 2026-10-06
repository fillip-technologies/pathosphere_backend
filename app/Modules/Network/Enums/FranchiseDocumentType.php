<?php

namespace App\Modules\Network\Enums;

/** KYC and compliance papers (spec §6 enumerations). */
enum FranchiseDocumentType: string
{
    case Pan = 'pan';
    case GstCertificate = 'gst_certificate';
    case AddressProof = 'address_proof';
    case BankProof = 'bank_proof';
    case PremisesPhoto = 'premises_photo';
    case TradeLicence = 'trade_licence';
    case ClinicalEstablishmentReg = 'clinical_establishment_reg';
    case PathologistRegistration = 'pathologist_registration';
    case Other = 'other';
}
