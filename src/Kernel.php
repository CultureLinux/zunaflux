<?php

declare(strict_types=1);

namespace App;

use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;

final class Kernel extends BaseKernel
{
    use MicroKernelTrait;

    /**
     * Runtime-written caches (application pools, rate limiter state) live in the share directory.
     * The production image sets APP_SHARE_DIR to a tmpfs so that the code and the compiled
     * container can stay on a read-only file system.
     */
    public function getShareDir(): ?string
    {
        $directory = $_SERVER['APP_SHARE_DIR'] ?? $_ENV['APP_SHARE_DIR'] ?? getenv('APP_SHARE_DIR');

        return is_string($directory) && '' !== $directory ? $directory : parent::getShareDir();
    }
}
