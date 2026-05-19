<?php

declare(strict_types=1);

namespace Mastertek\IranSms;

use Mastertek\IranSms\Commands\PruneLogsCommand;
use Mastertek\IranSms\Drivers\AmootSmsDriver;
use Mastertek\IranSms\Drivers\AsanakDriver;
use Mastertek\IranSms\Drivers\BehinPayamDriver;
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
use Illuminate\Foundation\Application;
use Spatie\LaravelPackageTools\Commands\InstallCommand;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

/**
 * @internal
 */
final class IranSmsServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package->name('iran-sms')
            ->hasConfigFile()
            ->hasMigration('create_sms_logs_table')
            ->hasCommand(PruneLogsCommand::class)
            ->hasInstallCommand(function (InstallCommand $command): void {
                $command
                    ->publishConfigFile()
                    ->publishMigrations()
                    ->askToStarRepoOnGitHub('amyavari/iran-sms-laravel');
            });
    }

    public function packageRegistered(): void
    {
        $this->app->singleton(SmsManager::class, fn(Application $app): SmsManager => new SmsManager($app));

        $this->app->bind(
            SmsIrDriver::class,
            fn(): SmsIrDriver => new SmsIrDriver(...config()->array('iran-sms.providers.sms_ir'))
        );

        $this->app->bind(
            MeliPayamakDriver::class,
            fn(): MeliPayamakDriver => new MeliPayamakDriver(...config()->array('iran-sms.providers.meli_payamak'))
        );

        $this->app->bind(
            PayamResanDriver::class,
            fn(): PayamResanDriver => new PayamResanDriver(...config()->array('iran-sms.providers.payam_resan'))
        );

        $this->app->bind(
            KavenegarDriver::class,
            fn(): KavenegarDriver => new KavenegarDriver(...config()->array('iran-sms.providers.kavenegar'))
        );

        $this->app->bind(
            FarazSmsDriver::class,
            fn(): FarazSmsDriver => new FarazSmsDriver(...config()->array('iran-sms.providers.faraz_sms'))
        );

        $this->app->bind(
            RayganSmsDriver::class,
            fn(): RayganSmsDriver => new RayganSmsDriver(...config()->array('iran-sms.providers.raygan_sms'))
        );

        $this->app->bind(
            WebOneDriver::class,
            fn(): WebOneDriver => new WebOneDriver(...config()->array('iran-sms.providers.web_one'))
        );

        $this->app->bind(
            AmootSmsDriver::class,
            fn(): AmootSmsDriver => new AmootSmsDriver(...config()->array('iran-sms.providers.amoot_sms'))
        );

        $this->app->bind(
            FaraPayamakDriver::class,
            fn(): FaraPayamakDriver => new FaraPayamakDriver(...config()->array('iran-sms.providers.fara_payamak'))
        );

        $this->app->bind(
            GhasedakDriver::class,
            fn(): GhasedakDriver => new GhasedakDriver(...config()->array('iran-sms.providers.ghasedak'))
        );

        $this->app->bind(
            BehinPayamDriver::class,
            fn(): BehinPayamDriver => new BehinPayamDriver(...config()->array('iran-sms.providers.behin_payam'))
        );

        $this->app->bind(
            AsanakDriver::class,
            fn(): AsanakDriver => new AsanakDriver(...config()->array('iran-sms.providers.asanak'))
        );

        $this->app->bind(
            MedianaDriver::class,
            fn(): MedianaDriver => new MedianaDriver(...config()->array('iran-sms.providers.mediana'))
        );
    }
}
