<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Generic customer e-mail. Customers are not system users, so this is sent
 * as an on-demand notification (Notification::route('mail', ...)).
 */
class CustomerMessage extends Notification
{
    use Queueable;

    public function __construct(
        public string $customerName,
        public string $subjectLine,
        public string $body,
    ) {
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject($this->subjectLine)
            ->greeting("Hello {$this->customerName},");

        foreach (preg_split('/\R/', $this->body) as $line) {
            if (trim($line) !== '') {
                $mail->line($line);
            }
        }

        return $mail->salutation('Thank you, ' . config('shoplink.company.name'));
    }
}
