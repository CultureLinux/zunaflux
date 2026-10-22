<?php

declare(strict_types=1);

namespace App\Controller;

use App\Application\Subscription\SubscribeToFeed;
use App\Entity\User;
use App\Message\DiscoverFeedMessage;
use App\Domain\Feed\DiscoveryFetcher;
use App\Domain\Feed\FeedDiscovery;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;

final class FeedDiscoveryController extends AbstractController
{
    #[Route('/subscriptions/discovery/live', name: 'app_feed_discovery_live', methods: ['POST'])]
    public function live(Request $request, DiscoveryFetcher $fetcher, FeedDiscovery $discovery): Response
    {
        $this->user();
        if (!$this->isCsrfTokenValid('discover-feed', $request->request->getString('_token'))) throw $this->createAccessDeniedException();
        $url = trim($request->request->getString('site_url'));
        $parts = parse_url($url);
        if (strlen($url) > 2048 || !filter_var($url, FILTER_VALIDATE_URL) || !is_array($parts) || !in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true) || isset($parts['user']) || isset($parts['pass'])) {
            return $this->json(['error' => 'Saisissez une adresse HTTP ou HTTPS valide.'], 400);
        }
        try {
            $page = $fetcher->fetch($url);
            return $this->json(['feeds' => $discovery->find($page['body'], $page['url'])]);
        } catch (\InvalidArgumentException $e) {
            return $this->json(['error' => 'Adresse inaccessible ou interdite par les règles de sécurité.'], 422);
        } catch (\RuntimeException $e) {
            return $this->json(['error' => $e->getMessage(), 'reason' => 403 === $e->getCode() ? 'site_forbidden' : 'fetch_error'], 422);
        } catch (\Throwable) {
            return $this->json(['error' => 'La page n’a pas pu être consultée. Vérifiez son adresse et réessayez.'], 422);
        }
    }

    #[Route('/subscriptions/discovery', name: 'app_feed_discovery_start', methods: ['POST'])]
    public function start(Request $request, CacheItemPoolInterface $cache, MessageBusInterface $bus): Response
    {
        if (!$this->isCsrfTokenValid('discover-feed', $request->request->getString('_token'))) throw $this->createAccessDeniedException();
        $user = $this->user();
        $url = trim($request->request->getString('site_url'));
        $parts = parse_url($url);
        if (strlen($url) > 2048 || !filter_var($url, FILTER_VALIDATE_URL) || !is_array($parts) || !in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true) || isset($parts['user']) || isset($parts['pass'])) {
            $this->addFlash('error', 'Saisissez une adresse HTTP ou HTTPS valide.');
            return $this->redirectToRoute('app_subscriptions', status: 303);
        }
        $id = bin2hex(random_bytes(16));
        $key = $this->key($user, $id);
        $item = $cache->getItem($key);
        $item->set(['status' => 'pending', 'feeds' => []])->expiresAfter(1800);
        $cache->save($item);
        $bus->dispatch(new DiscoverFeedMessage($key, $url));
        return $this->redirectToRoute('app_feed_discovery_result', ['id' => $id], 303);
    }

    #[Route('/subscriptions/discovery/{id}', name: 'app_feed_discovery_result', requirements: ['id' => '[a-f0-9]{32}'], methods: ['GET', 'POST'])]
    public function result(string $id, Request $request, CacheItemPoolInterface $cache, SubscribeToFeed $subscribe): Response
    {
        $user = $this->user();
        $item = $cache->getItem($this->key($user, $id));
        if (!$item->isHit()) throw $this->createNotFoundException();
        $result = $item->get();
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('discover-select-'.$id, $request->request->getString('_token'))) throw $this->createAccessDeniedException();
            $index = $request->request->getString('feed');
            if (!ctype_digit($index) || !isset($result['feeds'][(int) $index]['url'])) throw $this->createNotFoundException();
            try {
                $subscribe->subscribe($user, $result['feeds'][(int) $index]['url']);
                $cache->deleteItem($this->key($user, $id));
                $this->addFlash('success', 'Flux ajouté à vos abonnements.');
            } catch (\InvalidArgumentException $e) {
                $this->addFlash('error', $e->getMessage());
            }
            return $this->redirectToRoute('app_subscriptions', status: 303);
        }
        return $this->render('subscription/discovery.html.twig', ['id' => $id, 'result' => $result]);
    }

    private function user(): User
    {
        $user = $this->getUser();
        if (!$user instanceof User) throw $this->createAccessDeniedException();
        return $user;
    }

    private function key(User $user, string $id): string
    {
        return 'discovery_'.str_replace('-', '', (string) $user->getId()).'_'.$id;
    }
}
