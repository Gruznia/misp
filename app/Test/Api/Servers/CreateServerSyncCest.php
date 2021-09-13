<?php

declare(strict_types=1);

use \Helper\Fixture\Data\UserFixture;

class CreateServerSyncCest
{

    private const URL = '/servers/createSync';

    public function testCreateSyncReturnsForbiddenWithoutAuthKey(ApiTester $I): void
    {
        $I->sendPost(self::URL);

        $I->validateRequest();
        $I->validateResponse();

        $I->seeResponseCodeIs(403);
        $I->seeResponseIsJson();
    }

    public function testCreateSync(ApiTester $I): void
    {
        $orgId = 1;
        $adminId = 1;
        $userId = 10;
        $I->haveAuthorizationKey($orgId, $adminId, UserFixture::ROLE_ADMIN);
        $I->haveMispSetting('MISP.host_org_id', (string)$orgId);

        $I->haveAuthorizationKey($orgId, $userId, UserFixture::ROLE_SYNC_USER);

        $I->sendPost(self::URL);

        $I->validateRequest();
        $I->validateResponse();

        $I->seeResponseCodeIs(200);
        $I->seeResponseContainsJson(['Server' => []]);
    }
}