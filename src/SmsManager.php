<?php

declare(strict_types=1);

namespace Mastertek\IranSms;

use Mastertek\IranSms\Abstracts\Driver;
use Mastertek\IranSms\Contracts\Sms;
use Mastertek\IranSms\Drivers\AmootSmsDriver;
use Mastertek\IranSms\Drivers\AsanakDriver;
use Mastertek\IranSms\Drivers\BehinPayamDriver;
use Mastertek\IranSms\Drivers\FakeDriver;
use Mastertek\IranSms\Drivers\FaraPayamakDriver;
use Mastertek\IranSms\Drivers\FarazSmsDriver;
use Mastertek\IranSms\Drivers\GhasedakDriver;
use Mastertek\IranSms\Drivers\KavenegarDriver;
use Mastertek\IranSms\Drivers\MedianaDriver;
use Mastertek\IranSms\Drivers\MeliPayamakDriver;
use Mastertek\IranSms\Drivers\PayamResanDriver;
use Mastertek\IranSms\Drivers\RayganSmsDriver;
use Mastertek\IranSms\Drivers\SmsIrDriver;
use Mastertek\IranSms\Drivers\WebOneDriver;
use Illuminate\Support\Manager;
use InvalidArgumentException;
use Mastertek\IranSms\Drivers\ParsGreenDriver;
use Override;

/**
 * @internal Behind the SMS facade
 */
final class SmsManager extends Manager
{
    public function getDefaultDriver(): string
    {
        return $this->config->get('iran-sms.default');
    }

    /**
     * {@inheritdoc}
     */
    #[Override]
    public function driver($driver = null)
    {
        $driver ??= $this->getDefaultDriver();

        /**
         * Make sure we get a new SMS instance each time by removing it from the manager cache.
         */
        if ($this->mustBeFresh($driver)) {
            unset($this->drivers[$driver]);
        }

        return parent::driver($driver);
    }

    /**
     * Get an SMS instance to send by specific SMS provider
     *
     * @throws InvalidArgumentException
     */
    public function provider(?string $provider = null): Sms
    {
        return $this->driver($provider);
    }

    /**
     * Set custom driver instance for the given driver key
     */
    public function setDriver(string $key, Driver $driver): self
    {
        $this->drivers[$key] = $driver;

        return $this;
    }

    protected function createSmsIrDriver(): SmsIrDriver
    {
        return $this->container->make(SmsIrDriver::class);
    }

    protected function createMeliPayamakDriver(): MeliPayamakDriver
    {
        return $this->container->make(MeliPayamakDriver::class);
    }

        protected function createParsGreenDriver(): ParsGreenDriver
    {
        return $this->container->make(ParsGreenDriver::class);
    }


    protected function createKavenegarDriver(): KavenegarDriver
    {
        return $this->container->make(KavenegarDriver::class);
    }

    protected function createFarazSmsDriver(): FarazSmsDriver
    {
        return $this->container->make(FarazSmsDriver::class);
    }

    protected function createRayganSmsDriver(): RayganSmsDriver
    {
        return $this->container->make(RayganSmsDriver::class);
    }

    protected function createWebOneDriver(): WebOneDriver
    {
        return $this->container->make(WebOneDriver::class);
    }

    protected function createAmootSmsDriver(): AmootSmsDriver
    {
        return $this->container->make(AmootSmsDriver::class);
    }

    protected function createFaraPayamakDriver(): FaraPayamakDriver
    {
        return $this->container->make(FaraPayamakDriver::class);
    }

    protected function createGhasedakDriver(): GhasedakDriver
    {
        return $this->container->make(GhasedakDriver::class);
    }

    protected function createBehinPayamDriver(): BehinPayamDriver
    {
        return $this->container->make(BehinPayamDriver::class);
    }

    protected function createAsanakDriver(): AsanakDriver
    {
        return $this->container->make(AsanakDriver::class);
    }

    protected function createMedianaDriver(): MedianaDriver
    {
        return $this->container->make(MedianaDriver::class);
    }

    private function mustBeFresh(string $driver): bool
    {
        return isset($this->drivers[$driver])
            && !$this->drivers[$driver] instanceof FakeDriver;
    }
}
