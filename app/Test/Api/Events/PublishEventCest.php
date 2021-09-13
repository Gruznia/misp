<?php

declare(strict_types=1);

use \Helper\Fixture\Data\EventFixture;
use \Helper\Fixture\Data\UserFixture;

class PublishEventCest
{

    private const URL = '/events/publish/%s';

    public function testPublishReturnsForbiddenWithoutAuthKey(ApiTester $I): void
    {
        $eventId = 1;
        $I->sendPost(sprintf(self::URL, $eventId));

        $I->validateRequest();
        $I->validateResponse();

        $I->seeResponseCodeIs(403);
        $I->seeResponseIsJson();
    }

    public function testPublish(ApiTester $I): void
    {
        $orgId = 1;
        $eventId = 1;
        $I->haveAuthorizationKey($orgId, 1, UserFixture::ROLE_ADMIN);
        $I->haveMispSetting('MISP.background_jobs', '0');
        $fakeEvent = EventFixture::fake(
            [
                'id' => (string)$eventId,
                'org_id' => (string)$orgId,
                'orgc_id' => (string)$orgId,
                'published' => false
            ]
        );
        $I->haveInDatabase('events', $fakeEvent->toDatabase());

        $I->sendPost(sprintf(self::URL, $eventId), $fakeEvent->toRequest());

        $I->validateRequest();
        $I->validateResponse();

        $I->seeResponseCodeIs(200);
        $I->seeResponseContainsJson(
            [
                'name' => 'Event published without alerts',
                'message' => 'Event published without alerts'
            ]
        );
        $I->seeInDatabase('events', ['id' => $eventId, 'published' => true]);
    }
}