<?php

namespace Tests\Support\Fixtures;

/** A small enum for testing schema macros and state machines. */
enum SampleStatus: string
{
    case Draft = 'draft';
    case Active = 'active';
    case Closed = 'closed';
}
