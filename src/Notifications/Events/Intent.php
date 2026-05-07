<?php

declare(strict_types=1);

namespace NwsCad\Notifications\Events;

enum Intent: string
{
    case Created = 'Created';
    case Updated = 'Updated';
    case Closed  = 'Closed';
}
