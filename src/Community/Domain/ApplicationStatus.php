<?php

declare(strict_types=1);

namespace App\Community\Domain;

enum ApplicationStatus: string
{
    case Pending = 'PENDING';
    case Accepted = 'ACCEPTED';
    case Rejected = 'REJECTED';
    case Withdrawn = 'WITHDRAWN';
}
