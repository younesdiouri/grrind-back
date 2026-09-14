<?php

declare(strict_types=1);

namespace App\Community\Domain;

enum AlamNarrationSource: string
{
    case Local = 'LOCAL';
    case OpenAi = 'OPENAI';
}
