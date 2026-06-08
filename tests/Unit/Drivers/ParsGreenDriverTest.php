<?php

declare(strict_types=1);

namespace Mastertek\IranSms\Tests\Unit\Drivers;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Mastertek\IranSms\Drivers\ParsGreenDriver;
use Mastertek\IranSms\Exceptions\InvalidPatternStructureException;
use Mastertek\IranSms\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class ParsGreenDriverTest extends TestCase
{
    private function driver(): ParsGreenDriver
    {
        Config::set('iran-sms.providers.pars_green.token', 'token');
        Config::set('iran-sms.providers.pars_green.from', '123');

        return $this->app->make(ParsGreenDriver::class);
    }

    // =========================
    // BASIC SMS TESTS
    // =========================

    #[Test]
    public function it_sends_text_message_successfully(): void
    {
        Http::fake([
            'http://sms.parsgreen.ir/Apiv2/Message/SendSms' => Http::response([
                'R_Success' => true,
                'Data' => [
                    'ReqID' => 111,
                    'SuccessCount' => 1,
                ],
            ]),
        ]);

        $driver = $this->driver();

        $this->callProtectedMethod(
            $driver,
            'sendText',
            [['0913', '0914'], 'text message', '123']
        );

        Http::assertSent(
            fn(Request $request) =>
            $request['SmsBody'] === 'text message'
            && $request['Mobiles'] === '0913,0914'
            && $request['SmsNumber'] === '123'
        );

        $this->assertTrue($this->callProtectedMethod($driver, 'isSuccessful'));
    }

    #[Test]
    public function it_sends_otp_successfully(): void
    {
        Http::fake([
            'http://sms.parsgreen.ir/Apiv2/Message/SendOtp' => Http::response([
                'R_Success' => true,
            ]),
        ]);

        $driver = $this->driver();

        $this->callProtectedMethod($driver, 'sendOtp', ['0913', '1234', '123']);

        Http::assertSent(
            fn(Request $request) =>
            $request['Mobile'] === '0913'
            && $request['SmsCode'] === '1234'
            && $request['AddName'] === false
        );
    }

    // =========================
    // GROUP TESTS
    // =========================

    #[Test]
    public function it_creates_group_successfully(): void
    {
        Http::fake([
            'http://sms.parsgreen.ir/Apiv2/Contact/GroupAdd' => Http::response([
                'R_Success' => true,
                'GroupID' => 10,
            ]),
        ]);

        $driver = $this->driver();

        $result = $driver->createGroup('test group', 'desc');

        Http::assertSent(
            fn(Request $request) =>
            $request['Name'] === 'test group'
            && $request['Description'] === 'desc'
        );

        $this->assertTrue($result['success']);
        $this->assertSame(10, $result['group_id']);
    }

    #[Test]
    public function it_edits_group_successfully(): void
    {
        Http::fake([
            'http://sms.parsgreen.ir/Apiv2/Contact/GroupEdit' => Http::response([
                'R_Success' => true,
            ]),
        ]);

        $driver = $this->driver();

        $result = $driver->editGroup('10', 'new name', 'desc');

        Http::assertSent(
            fn(Request $request) =>
            $request['GroupID'] === '10'
            && $request['Name'] === 'new name'
            && $request['IsActive'] === true
        );

        $this->assertTrue($result['success']);
    }

    #[Test]
    public function it_deletes_group_successfully(): void
    {
        Http::fake([
            'http://sms.parsgreen.ir/Apiv2/Contact/GroupDelete' => Http::response([
                'R_Success' => true,
            ]),
        ]);

        $driver = $this->driver();

        $result = $driver->deleteGroup('10');

        Http::assertSent(
            fn(Request $request) =>
            $request['GroupID'] === '10'
        );

        $this->assertTrue($result['success']);
    }

    #[Test]
    public function it_returns_groups_list(): void
    {
        Http::fake([
            'http://sms.parsgreen.ir/Apiv2/Contact/Grouplist' => Http::response([
                'R_Success' => true,
                'Data' => [
                    ['GroupID' => 1, 'Name' => 'A'],
                    ['GroupID' => 2, 'Name' => 'B'],
                ],
            ]),
        ]);

        $driver = $this->driver();

        $result = $driver->getGroups();

        $this->assertTrue($result['success']);
        $this->assertCount(2, $result['groups']);
    }

    // =========================
    // CONTACT TESTS
    // =========================

    #[Test]
    public function it_adds_contact_successfully(): void
    {
        Http::fake([
            'http://sms.parsgreen.ir/Apiv2/Contact/ContactAdd' => Http::response([
                'R_Success' => true,
                'ContactID' => 99,
            ]),
        ]);

        $driver = $this->driver();

        $result = $driver->addContact([
            'first_name' => 'Ali',
            'last_name' => 'Ahmadi',
            'mobile' => '09120000000',
            'group_id' => '5',
        ]);

        Http::assertSent(
            fn(Request $request) =>
            $request['FirstName'] === 'Ali'
            && $request['LastName'] === 'Ahmadi'
            && $request['MobileNumbers'] === '09120000000'
            && $request['GroupID'] === ['5']
        );

        $this->assertTrue($result['success']);
        $this->assertSame(99, $result['contact_id']);
    }

    #[Test]
    public function it_deletes_contact_successfully(): void
    {
        Http::fake([
            'http://sms.parsgreen.ir/Apiv2/Contact/ContactDelete' => Http::response([
                'R_Success' => true,
            ]),
        ]);

        $driver = $this->driver();

        $result = $driver->deleteContact('123');

        Http::assertSent(
            fn(Request $request) =>
            $request['ContactID'] === '123'
        );

        $this->assertTrue($result['success']);
    }

    #[Test]
    public function it_returns_contacts_count(): void
    {
        Http::fake([
            'http://sms.parsgreen.ir/Apiv2/Contact/ContactCount' => Http::response([
                'R_Success' => true,
                'count' => 20,
            ]),
        ]);

        $driver = $this->driver();

        $result = $driver->getContactsCount('5');

        $this->assertTrue($result['success']);
        $this->assertSame(20, $result['count']);
    }

    #[Test]
    public function it_fails_when_adding_contact(): void
    {
        Http::fake([
            'http://sms.parsgreen.ir/Apiv2/Contact/ContactAdd' => Http::response([
                'R_Success' => false,
                'R_Error' => 'invalid data',
            ]),
        ]);

        $driver = $this->driver();

        $result = $driver->addContact([
            'mobile' => '0912',
            'group_id' => '1',
        ]);

        $this->assertFalse($result['success']);
        $this->assertSame('invalid data', $result['message']);
    }

    // =========================
    // PATTERN TESTS
    // =========================

    #[Test]
    public function it_throws_invalid_pattern_exception(): void
    {
        $this->expectException(InvalidPatternStructureException::class);

        $driver = $this->driver();

        $this->callProtectedMethod(
            $driver,
            'sendPattern',
            [['0913'], 'hello %% world', ['a', 'b'], '123']
        );
    }

    #[Test]
    public function it_replaces_pattern_variables_correctly(): void
    {
        Http::fake([
            'http://sms.parsgreen.ir/Apiv2/Message/SendSms' => Http::response([
                'R_Success' => true,
            ]),
        ]);

        $driver = $this->driver();

        $this->callProtectedMethod(
            $driver,
            'sendPattern',
            [['0913'], 'hello %% %%', ['x', 'y'], '123']
        );

        Http::assertSent(
            fn(Request $request) =>
            $request['SmsBody'] === 'hello x y'
        );
    }

    // =========================
    // CREDIT TEST
    // =========================

    #[Test]
    public function it_returns_credit(): void
    {
        Http::fake([
            'http://sms.parsgreen.ir/Apiv2/User/credit' => Http::response([
                'Amount' => 1000.4,
            ]),
        ]);

        $driver = $this->driver();

        $credit = $driver->credit();

        $this->assertSame(1000, $credit);
    }
}