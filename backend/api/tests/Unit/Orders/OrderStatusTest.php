<?php

declare(strict_types=1);

use App\Modules\Orders\Domain\OrderStatus;

/*
| State machine (docs/02 Orders): every one of the 5x5 pairs is checked.
| pending → confirmed → shipped → delivered; pending|confirmed → cancelled.
*/

const ALLOWED_TRANSITIONS = [
    'pending' => ['confirmed', 'cancelled'],
    'confirmed' => ['shipped', 'cancelled'],
    'shipped' => ['delivered'],
    'delivered' => [],
    'cancelled' => [],
];

it('allows exactly the transitions of the state machine', function (OrderStatus $from, OrderStatus $to): void {
    expect($from->canTransitionTo($to))->toBe(in_array($to->value, ALLOWED_TRANSITIONS[$from->value], true));
})->with(function (): Generator {
    foreach (OrderStatus::cases() as $from) {
        foreach (OrderStatus::cases() as $to) {
            yield $from->value.' → '.$to->value => [$from, $to];
        }
    }
});
