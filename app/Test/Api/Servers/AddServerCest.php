<?php

declare(strict_types=1);

use \Helper\Fixture\Data\UserFixture;
use \Helper\Fixture\Data\ServerFixture;
use \Helper\Fixture\Data\OrganisationFixture;

class AddServersCest
{

    private const URL = '/servers/add';

    public function testIndexReturnsForbiddenWithoutAuthKey(ApiTester $I): void
    {
        $I->sendPost(self::URL);

        $I->validateRequest();
        $I->validateResponse();

        $I->seeResponseCodeIs(403);
        $I->seeResponseIsJson();
    }

    public function testAdd(ApiTester $I): void
    {
        $orgId = 1;
        $userId = 1;
        $I->haveAuthorizationKey($orgId, $userId, UserFixture::ROLE_ADMIN);

        $remoteOrgId = 2;
        $remoteOrg = OrganisationFixture::fake(['id' => $remoteOrgId]);
        $I->haveInDatabase('organisations', $remoteOrg->toDatabase());

        $fakeServer = ServerFixture::fake(
            [
                'org_id' => (string)$orgId,
                'remote_org_id' => (string)$remoteOrgId
            ]
        );

        $I->sendPost(self::URL, $fakeServer->toRequest());

        $I->validateRequest();
        $I->validateResponse();

        $fakeServer->set([
            'id' => $I->grabDataFromResponseByJsonPath('$..Server.id')[0],
        ]);

        $I->seeResponseCodeIs(200);
        $I->seeResponseContainsJson(['Server' => $fakeServer->toResponse()]);
        $I->seeInDatabase('servers', $fakeServer->toDatabase());
    }
}