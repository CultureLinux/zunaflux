<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

final class StatisticsController extends AbstractController
{
    #[Route('/api/statistics/counters', name: 'app_api_statistics_counters', methods: ['GET'])]
    public function counters(Connection $connection): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }
        $parameters = [$user->getId()->toBinary()];
        $types = [\Doctrine\DBAL\ParameterType::BINARY];
        $base = ' FROM subscription s LEFT JOIN category c ON c.id = s.category_id INNER JOIN article a ON a.feed_id = s.feed_id LEFT JOIN article_state st ON st.article_id = a.id AND st.user_id = s.user_id WHERE s.user_id = ? AND s.enabled = 1';
        $counts = 'COUNT(a.id) AS total, COALESCE(SUM(CASE WHEN st.is_read = 1 THEN 1 ELSE 0 END), 0) AS read_count, COALESCE(SUM(CASE WHEN st.is_read = 1 THEN 0 ELSE 1 END), 0) AS unread_count';

        $result = [
            'all' => $connection->fetchAssociative('SELECT '.$counts.$base, $parameters, $types),
            'categories' => $connection->fetchAllAssociative('SELECT c.id AS id, c.name AS name, '.$counts.$base.' GROUP BY c.id, c.name', $parameters, $types),
            'feeds' => $connection->fetchAllAssociative('SELECT f.id AS id, f.title AS title, f.canonical_url AS url, '.$counts.' FROM subscription s INNER JOIN feed f ON f.id = s.feed_id INNER JOIN article a ON a.feed_id = s.feed_id LEFT JOIN article_state st ON st.article_id = a.id AND st.user_id = s.user_id WHERE s.user_id = ? AND s.enabled = 1 GROUP BY f.id, f.title, f.canonical_url', $parameters, $types),
        ];
        foreach (['categories', 'feeds'] as $group) {
            foreach ($result[$group] as &$row) {
                if (null !== $row['id']) $row['id'] = (string) Uuid::fromBinary($row['id']);
            }
            unset($row);
        }

        return $this->json($result);
    }
}
