<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Plain-text Arabic e-mail for an order event (Mailpit in development).
 */
final class OrderUpdateMail extends Mailable
{
    private const array STATUS_NAMES = [
        'pending' => 'قيد الانتظار',
        'confirmed' => 'مؤكَّد',
        'shipped' => 'تم الشحن',
        'delivered' => 'تم التسليم',
        'cancelled' => 'ملغى',
    ];

    public function __construct(
        private readonly string $customerName,
        private readonly string $orderId,
        private readonly string $status,
        private readonly bool $placed,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->placed ? 'تم استلام طلبك' : 'تحديث حالة طلبك');
    }

    public function content(): Content
    {
        return new Content(htmlString: nl2br(e(implode("\n", [
            'مرحباً '.$this->customerName.'،',
            $this->placed ? 'استلمنا طلبك رقم '.$this->orderId.'.' : 'تغيّرت حالة طلبك رقم '.$this->orderId.'.',
            'الحالة الحالية: '.(self::STATUS_NAMES[$this->status] ?? $this->status).'.',
        ]))));
    }
}
