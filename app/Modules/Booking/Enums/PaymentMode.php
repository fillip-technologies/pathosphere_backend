<?php

namespace App\Modules\Booking\Enums;

enum PaymentMode: string
{
    case Cash = 'cash';
    case Card = 'card';
    case Upi = 'upi';
    case Netbanking = 'netbanking';
    case Wallet = 'wallet';
    case CreditNote = 'credit_note';
}
