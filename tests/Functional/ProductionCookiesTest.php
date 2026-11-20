<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Kernel;
use PHPUnit\Framework\TestCase;

/** Boots the production kernel to read the resolved cookie settings; no request is handled. */
final class ProductionCookiesTest extends TestCase
{
    protected function tearDown(): void
    {
        putenv('ZUNAFLUX_COOKIE_SECURE');
        unset($_SERVER['ZUNAFLUX_COOKIE_SECURE'], $_ENV['ZUNAFLUX_COOKIE_SECURE']);
    }

    public function testSessionAndRememberMeCookiesAreSecureByDefaultInProduction(): void
    {
        $container = $this->bootProduction();

        self::assertTrue($container->getParameter('session.storage.options')['cookie_secure']);
        self::assertTrue($this->rememberMeUsesTheSameSwitch($container->getParameter('kernel.cache_dir')));
    }

    public function testAPlainHttpInstallCanOptOut(): void
    {
        putenv('ZUNAFLUX_COOKIE_SECURE=0');
        $_SERVER['ZUNAFLUX_COOKIE_SECURE'] = $_ENV['ZUNAFLUX_COOKIE_SECURE'] = '0';

        $container = $this->bootProduction();

        self::assertFalse($container->getParameter('session.storage.options')['cookie_secure']);
    }

    private function bootProduction(): \Symfony\Component\DependencyInjection\ContainerInterface
    {
        $kernel = new Kernel('prod', false);
        $kernel->boot();

        return $kernel->getContainer();
    }

    /** The remember-me cookie options are compiled into the handler service: the flag must come from the same switch. */
    private function rememberMeUsesTheSameSwitch(string $cacheDir): bool
    {
        foreach (glob($cacheDir.'/Container*/getSecurity_Authenticator_RememberMeHandler_MainService.php') ?: [] as $file) {
            if (str_contains((string) file_get_contents($file), "'secure' => \$container->getEnv('bool:default:app.cookie_secure_default:ZUNAFLUX_COOKIE_SECURE')")) {
                return true;
            }
        }

        return false;
    }
}
