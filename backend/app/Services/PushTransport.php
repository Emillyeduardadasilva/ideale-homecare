<?php

namespace App\Services;

use GuzzleHttp\Client;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;
use Psr\Http\Client\ClientInterface;

class PushTransport
{
    public function __construct(private ?ClientInterface $client = null) {}

    public function send(array $subscription, int $notificationId): string
    {
        if (! PushEndpoint::allowed($subscription['endpoint'])) {
            return 'invalid';
        }
        $c = config('care.push');
        if (! $c['subject'] || ! $c['public_key'] || ! $c['private_key']) {
            return 'unconfigured';
        }
        $client = $this->client ?? new Client(['timeout' => 20, 'connect_timeout' => 5, 'allow_redirects' => false]);
        $push = new WebPush(['VAPID' => ['subject' => $c['subject'], 'publicKey' => $c['public_key'], 'privateKey' => $c['private_key']]], [], $client);
        $report = $push->sendOneNotification(Subscription::create($subscription), json_encode(['notification_id' => $notificationId, 'title' => 'Ideale Home Care', 'body' => 'Você tem um novo lembrete. Abra o aplicativo.', 'tag' => 'ideale-'.$notificationId], JSON_THROW_ON_ERROR), ['TTL' => 300]);
        if ($report->isSubscriptionExpired()) {
            return 'expired';
        }

        return $report->isSuccess() ? 'sent' : 'retry';
    }
}
