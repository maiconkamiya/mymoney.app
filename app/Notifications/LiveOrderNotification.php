<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * E-mail about real money: an order sent or failed, or "Parar tudo". Sent only
 * when the portfolio owner turned the e-mail notice on.
 */
class LiveOrderNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $portfolioName,
        public readonly string $subject,
        public readonly string $message,
    ) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject($this->subject)
            ->greeting('Carteira real: '.$this->portfolioName)
            ->line($this->message)
            ->action('Abrir a carteira', url('/portfolios'))
            ->line('Você recebe este aviso porque ligou o e-mail para ordens reais nesta carteira.');
    }
}
