<?php

namespace App\Modules\Catalogue\Domain;

use App\Modules\Shared\Money\Money;

/** The priced, routed lines for a booking, or the problems preventing it. */
final class Quote
{
    /**
     * @param  list<QuoteLine>  $lines
     * @param  list<QuoteProblem>  $problems
     */
    public function __construct(
        public readonly array $lines,
        public readonly array $problems,
    ) {}

    public function isBookable(): bool
    {
        return $this->problems === [];
    }

    public function mrpTotal(): Money
    {
        return array_reduce($this->lines, fn (Money $total, QuoteLine $line) => $total->add($line->mrpPrice), Money::zero());
    }

    public function partnerTotal(): Money
    {
        return array_reduce($this->lines, fn (Money $total, QuoteLine $line) => $total->add($line->partnerPrice), Money::zero());
    }
}
