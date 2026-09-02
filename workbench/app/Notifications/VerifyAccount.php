<?php

namespace Workbench\App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class VerifyAccount extends Notification
{
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Verify your email address')
            ->greeting('Hello!')
            ->line('Please click the button below to verify your email address.')
            ->action('Verify Email Address', 'https://acme.test/verify/'.hash('sha256', 'demo').'?expires=1893456000&signature=abc123')
            ->line('If you did not create an account, no further action is required.');
    }
}
