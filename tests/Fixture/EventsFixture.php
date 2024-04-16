<?php

declare(strict_types=1);

namespace App\Test\Fixture;

use App\Model\Entity\Distribution;
use Cake\TestSuite\Fixture\TestFixture;

class EventsFixture extends TestFixture
{
    public $connection = 'test';

    public const EVENT_1_ID = 1000;
    public const EVENT_1_UUID = '02a5f2e5-3c6c-4d40-b973-de465fd2f370';

    public function init(): void
    {
        $this->records = [
            [
                'id' => self::EVENT_1_ID,
                'info' => 'Event 1',
                'org_id' => OrganisationsFixture::ORGANISATION_A_ID,
                'orgc_id' => OrganisationsFixture::ORGANISATION_A_ID,
                'user_id' => UsersFixture::USER_ADMIN_ID,
                'distribution' => Distribution::ALL_COMMUNITIES,
                'analysis' => 0,
                'threat_level_id' => 0,
                'date' => '2021-01-01 00:00:00',
                'published' => 1,
                'uuid' => self::EVENT_1_UUID,
                'attribute_count' => 1,
                'sharing_group_id' => 0,
            ]
        ];
        parent::init();
    }
}