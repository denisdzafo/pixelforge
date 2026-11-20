<?php

declare(strict_types=1);

namespace App\Enums;

enum CreditTransactionType: string
{
    case Bonus = 'bonus';
    case Purchase = 'purchase';
    case Hold = 'hold';
    case HoldRelease = 'hold_release';
    case Charge = 'charge';

    public function label(): string
    {
        return match ($this) {
            self::Bonus => 'Welcome credit',
            self::Purchase => 'Credits purchased',
            self::Hold => 'Held for generation',
            self::HoldRelease => 'Hold released',
            self::Charge => 'Generation charged',
        };
    }
}
