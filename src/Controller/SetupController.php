<?php

declare(strict_types=1);

namespace Drupal\elan_bridge\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\elan_bridge\Setup\SetupManager;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Handles the verified setup callback and reachability challenge.
 */
final class SetupController extends ControllerBase {

  /**
   * Constructs the setup controller.
   */
  public function __construct(private readonly SetupManager $setup) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static($container->get('elan_bridge.setup_manager'));
  }

  /**
   * Authenticates a bounded setup challenge before returning its proof.
   */
  public function probe(Request $request): JsonResponse {
    try {
      if (strlen($request->getContent()) > 2048) {
        throw new \RuntimeException('Invalid setup challenge.');
      }
      $body = json_decode($request->getContent(), TRUE, 16, JSON_THROW_ON_ERROR);
      $result = $this->setup->probe($request->headers->get('Authorization', ''), $body);
      return new JsonResponse($result, 200, ['Cache-Control' => 'no-store, private']);
    }
    catch (\Throwable) {
      return new JsonResponse(['error' => 'Invalid or expired setup proof.'], 401, ['Cache-Control' => 'no-store, private']);
    }
  }

  /**
   * Completes a state-bound handoff as the administrator who started it.
   */
  public function complete(Request $request): RedirectResponse {
    try {
      $this->setup->complete((string) $request->query->get('handoff'), (string) $request->query->get('state'));
      $this->messenger()->addStatus($this->t('ELAN is connected. Your provider and language mappings are ready. Submit a test translation and review its result.'));
    }
    catch (\Throwable $error) {
      $this->messenger()->addError($error->getMessage());
    }
    return $this->redirect('elan_bridge.setup', [], ['query' => []])->setPrivate();
  }

}
