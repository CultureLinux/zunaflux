<?php

declare(strict_types=1);

namespace App\Controller;

use App\Application\Reading\ReadingCounters;
use App\Entity\User;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

final class StatisticsController extends AbstractController
{
    #[Route('/api/statistics/counters', name: 'app_api_statistics_counters', methods: ['GET'])]
    public function counters(ReadingCounters $counters): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        return $this->json($counters->summary($user));
    }
}
