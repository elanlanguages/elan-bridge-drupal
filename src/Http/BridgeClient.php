<?php

declare(strict_types=1);

namespace Drupal\elan_bridge\Http;

use Drupal\elan_bridge\Connection\ConnectionSettings;
use Drupal\elan_bridge\Event\EventSigner;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;

/**
 * Posts exact signed event bytes to the Bridge webhook receiver.
 */
final class BridgeClient {

  /**
   * Constructs the Bridge client.
   */
  public function __construct(
    private readonly ClientInterface $httpClient,
    private readonly ConnectionSettings $settings,
  ) {}

  /**
   * Delivers one durable event without changing its identity.
   */
  public function deliver(
    string $event_id,
    string $payload,
    string $connection_fingerprint,
  ): DeliveryResult {
    if (!$this->settings->isConfigured()) {
      return new DeliveryResult(DeliveryResult::PERMANENT, 'The ELAN Bridge connection is incomplete.');
    }
    if (!hash_equals($connection_fingerprint, $this->settings->deliveryFingerprint())) {
      return new DeliveryResult(
        DeliveryResult::PERMANENT,
        'The event belongs to an earlier ELAN Bridge connection. Reconnect before retrying it.',
      );
    }
    $timestamp = (string) time();
    $url = $this->settings->bridgeUrl()
      . '/connectors/drupal/webhook/'
      . rawurlencode($this->settings->connectionId());
    try {
      $response = $this->httpClient->request('POST', $url, [
        'allow_redirects' => FALSE,
        'body' => $payload,
        'connect_timeout' => 5,
        'headers' => [
          'Accept' => 'application/json',
          'Content-Type' => 'application/json',
          'X-ELAN-Event-ID' => $event_id,
          'X-ELAN-Timestamp' => $timestamp,
          'X-ELAN-Signature' => EventSigner::sign($this->settings->webhookSecret(), $timestamp, $payload),
        ],
        'http_errors' => FALSE,
        'timeout' => 15,
      ]);
    }
    catch (GuzzleException $exception) {
      return new DeliveryResult(DeliveryResult::RETRY, $exception->getMessage());
    }

    $status = $response->getStatusCode();
    if ($status >= 200 && $status < 300) {
      return new DeliveryResult(DeliveryResult::DELIVERED);
    }
    $body = substr(trim(strip_tags((string) $response->getBody())), 0, 1000);
    $error = sprintf('Bridge returned HTTP %d%s', $status, $body === '' ? '.' : ': ' . $body);
    if (in_array($status, [408, 425, 429], TRUE) || $status >= 500) {
      return new DeliveryResult(DeliveryResult::RETRY, $error);
    }
    return new DeliveryResult(DeliveryResult::PERMANENT, $error);
  }

}
