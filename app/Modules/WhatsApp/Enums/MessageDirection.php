<?php

declare(strict_types=1);

namespace App\Modules\WhatsApp\Enums;

enum MessageDirection: string
{
    case Inbound = 'inbound';
    case Outbound = 'outbound';
}
