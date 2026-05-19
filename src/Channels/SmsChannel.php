<?php

declare(strict_types=1);

namespace Mastertek\IranSms\Channels;

use Mastertek\IranSms\Contracts\Sms;
use Illuminate\Notifications\Notification;
use UnexpectedValueException;

/**
 * Notification channel for sending SMS via Laravel Notifications.
 *
 * @see https://github.com/amyavari/iran-sms-laravel?tab=readme-ov-file#notifications
 */
final class SmsChannel
{
    /**
     * Send the given SMS notification.
     */
    public function send(object $notifiable, Notification $notification): void
    {
        /** @phpstan-ignore method.notFound */
        $message = $notification->toSms($notifiable);

        if (!$message instanceof Sms) {
            throw new UnexpectedValueException(
                sprintf('The toSms() method must return an instance of "\Mastertek\IranSms\Contracts\Sms", "%s" given.', get_debug_type($message))
            );
        }

        $message->send();
    }
}
