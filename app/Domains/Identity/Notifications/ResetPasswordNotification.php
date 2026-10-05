<?php
namespace App\Domains\Identity\Notifications;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
class ResetPasswordNotification extends Notification {
    use Queueable;
    public function __construct(private string $token) {}
    public function via(object $notifiable): array { return ['mail']; }
    public function toMail(object $notifiable): MailMessage {
        $url = rtrim((string) config('app.frontend_url', env('FRONTEND_URL', 'http://localhost:5173')), '/')
            . '/reset-password?token=' . urlencode($this->token)
            . '&email=' . urlencode($notifiable->getEmailForPasswordReset());
        return (new MailMessage)
            ->subject('Reset your MerchantOS password')
            ->greeting('Hello ' . ($notifiable->name ?? 'there') . ',')
            ->line('We received a request to reset your MerchantOS password.')
            ->action('Reset Password', $url)
            ->line('This link expires according to your password reset configuration.')
            ->line('If you did not request this, you can safely ignore this email.');
    }
}
