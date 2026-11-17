<?php

declare(strict_types=1);

use Modules\ModuleMtsPbx\Lib\BasicAuth;
use Modules\ModuleMtsPbx\Lib\CdrXmlBuilder;
use Modules\ModuleMtsPbx\Lib\ModuleSettings;
use Modules\ModuleMtsPbx\Lib\MtsAccountKey;
use Modules\ModuleMtsPbx\Lib\MtsAccountsForm;
use Modules\ModuleMtsPbx\Lib\MtsCallId;
use Modules\ModuleMtsPbx\Lib\MtsEventMapper;

foreach (['MtsCallId', 'MtsAccountKey', 'ModuleSettings', 'MtsAccountsForm', 'BasicAuth', 'CdrXmlBuilder', 'MtsEventMapper'] as $class) {
    require_once dirname(__DIR__) . "/Lib/{$class}.php";
}

final class MultiPortalTest
{
    private int $assertions = 0;

    public function run(): void
    {
        $this->testCallId();
        $this->testAccountKey();
        $this->testAccountsForm();
        $this->testModuleSettings();
        $this->testBasicAuth();
        $this->testCdrXml();
        $this->testEventMapper();
        fwrite(STDOUT, "OK ({$this->assertions} assertions)\n");
    }

    public static function jwt(string $id): string
    {
        $b64 = static fn(string $v): string => rtrim(strtr(base64_encode($v), '+/', '-_'), '=');
        return $b64('{"alg":"HS256","typ":"JWT"}') . '.' . $b64(json_encode(['id' => $id, 'x' => '?>?'])) . '.sig';
    }

    private function testCallId(): void
    {
        $this->assertSame('fs-mts-75317940442', MtsCallId::build(1, true, '75317940442'));
        $this->assertSame('fs-mts-2-75317940442', MtsCallId::build(2, false, '75317940442'));
        $this->assertSame('75317940442', MtsCallId::eventCallId(1, true, '75317940442'));
        $this->assertSame('2-75317940442', MtsCallId::eventCallId(2, false, '75317940442'));
        $this->assertSame('2-75317940442', MtsCallId::fileBase('fs-mts-2-75317940442'));
        $this->assertSame('75317940442', MtsCallId::callIdFromLegacyLinkedId('fs-mts-75317940442'));
        $this->assertSame('', MtsCallId::fileBase('mikopbx-123.4'));
    }

    private function testAccountKey(): void
    {
        $this->assertSame('9807100241.RU', MtsAccountKey::parseDomain(self::jwt('api@9807100241.RU')));
        $this->assertSame('', MtsAccountKey::parseDomain(self::jwt('no-domain')));
        $this->assertSame('', MtsAccountKey::parseDomain('garbage'));
        $this->assertSame('', MtsAccountKey::parseDomain(''));
        $this->assertSame('9807100241.ru', MtsAccountKey::normalizeDomain(' 9807100241.RU '));
        $this->assertSame('eyJhbGciOi…gk1Y12', MtsAccountKey::shorten('eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.payload.sig-gk1Y12'));
        $this->assertSame('short', MtsAccountKey::shorten(' short '));
    }

    private function testAccountsForm(): void
    {
        $result = MtsAccountsForm::normalize([
            '1'    => ['name' => 'Основной', 'authApiKey' => self::jwt('a@one.ru'), 'gap' => '2', 'enabled' => '1', 'downloadRecords' => '0'],
            'new1' => ['name' => '', 'authApiKey' => ' ' . self::jwt('a@two.ru') . ' ', 'gap' => 'x', 'enabled' => '0'],
            'new2' => ['name' => '', 'authApiKey' => '', 'gap' => '0', 'enabled' => '1'],
        ]);
        $this->assertSame([], $result['errors']);
        $this->assertSame(2, count($result['rows']));
        $this->assertSame('1', $result['rows'][0]['key']);
        $this->assertSame(2, $result['rows'][0]['gap']);
        $this->assertSame(1, $result['rows'][0]['enabled']);
        $this->assertSame(0, $result['rows'][0]['downloadRecords'], 'download records switched off');
        $this->assertSame(1, $result['rows'][1]['downloadRecords'], 'missing field keeps downloads on');
        $this->assertSame('two.ru', $result['rows'][1]['name'], 'empty name falls back to domain');
        $this->assertSame(0, $result['rows'][1]['gap']);
        $this->assertSame(0, $result['rows'][1]['enabled']);
        $this->assertSame(self::jwt('a@two.ru'), $result['rows'][1]['authApiKey'], 'key is trimmed');

        $result = MtsAccountsForm::normalize([
            '1'    => ['name' => 'A', 'authApiKey' => self::jwt('a@one.ru')],
            'new1' => ['name' => 'B', 'authApiKey' => self::jwt('b@ONE.ru')],
            '3'    => ['name' => 'C', 'authApiKey' => 'broken'],
            '4'    => ['name' => 'D', 'authApiKey' => ''],
        ]);
        $this->assertSame([
            ['code' => MtsAccountsForm::ERROR_DUPLICATE_DOMAIN, 'name' => 'B'],
            ['code' => MtsAccountsForm::ERROR_INVALID_KEY, 'name' => 'C'],
            ['code' => MtsAccountsForm::ERROR_EMPTY_KEY, 'name' => 'D'],
        ], $result['errors']);
        $this->assertSame(['rows' => [], 'errors' => []], MtsAccountsForm::normalize(null));
    }

    private function testModuleSettings(): void
    {
        $new = new stdClass();
        $new->inLogin = '';
        $new->inPassword = '';
        ModuleSettings::initNewSettings($new);
        $this->assertSame('1', $new->cdrAuthEnabled, 'new install: CDR auth enabled');
        $this->assertSame('1', $new->accountsMigrated);
        $this->assertSame(32, strlen($new->inLogin));
        $this->assertSame(true, $new->inLogin !== $new->inPassword);

        $old = new stdClass();
        $old->cdrAuthEnabled = null;
        $old->accountsMigrated = null;
        $old->authApiKey = self::jwt('a@legacy.ru');
        $old->gap = '3';
        $old->offset = '2026-11-01T10:00:00';
        $this->assertSame(true, ModuleSettings::applyUpgradeDefaults($old));
        $this->assertSame('0', $old->cdrAuthEnabled, 'upgrade: CDR auth disabled');
        $this->assertSame(false, ModuleSettings::applyUpgradeDefaults($old), 'second run keeps value');
        $old->cdrAuthEnabled = '1';
        $this->assertSame(false, ModuleSettings::applyUpgradeDefaults($old), 'explicit value is not overwritten');

        $data = ModuleSettings::legacyAccountData($old);
        $this->assertSame('legacy.ru', $data['domain']);
        $this->assertSame('3', $data['gap']);
        $this->assertSame('2026-11-01T10:00:00', $data['offset']);
        $this->assertSame('1', $data['legacyIdFormat']);

        $old->accountsMigrated = '1';
        $this->assertSame(null, ModuleSettings::legacyAccountData($old), 'already migrated');
        $old->accountsMigrated = null;
        $old->authApiKey = '';
        $this->assertSame(null, ModuleSettings::legacyAccountData($old), 'nothing to migrate');

        $this->assertSame(true, ModuleSettings::isCdrAuthEnabled('1'));
        $this->assertSame(true, ModuleSettings::isCdrAuthEnabled(1));
        $this->assertSame(false, ModuleSettings::isCdrAuthEnabled(null));
        $this->assertSame(false, ModuleSettings::isCdrAuthEnabled('0'));
    }

    private function testBasicAuth(): void
    {
        $header = 'Basic ' . base64_encode('user:pa:ss');
        $this->assertSame(true, BasicAuth::check($header, 'user', 'pa:ss'));
        $this->assertSame(false, BasicAuth::check($header, 'user', 'other'));
        $this->assertSame(false, BasicAuth::check(null, 'user', 'pa:ss'));
        $this->assertSame(false, BasicAuth::check('', 'user', 'pa:ss'));
        $this->assertSame(false, BasicAuth::check('Bearer abc', 'user', 'pa:ss'));
        $this->assertSame(false, BasicAuth::check('Basic ' . base64_encode('nocolon'), 'user', 'pa:ss'));
        $this->assertSame(false, BasicAuth::check('Basic ' . base64_encode(':'), '', ''), 'empty credentials never match');
    }

    private function testCdrXml(): void
    {
        $rows = [
            $this->cdrRow(10, 'fs-mts-2-555', '2026-11-16 10:05:00', 'a&b "q" <x>'),
            $this->cdrRow(7, 'fs-mts-444', '2026-11-16 10:00:00', ''),
            $this->cdrRow(12, 'mikopbx-1.1', '2026-11-16 09:00:00', ''),
        ];
        $result = CdrXmlBuilder::build($rows, 5);
        $this->assertSame(12, $result['maxOffset'], 'max offset counts skipped rows too');

        $xml = simplexml_load_string($result['xml']);
        $this->assertSame(true, $xml !== false, 'XML with special chars is valid');
        $this->assertSame(2, count($xml->history_record));
        $this->assertSame('fs-mts-444', (string)$xml->history_record[0]['no'], 'sorted by start');
        $this->assertSame('fs-mts-2-555', (string)$xml->history_record[1]->details['call_id']);
        $this->assertSame('a&b "q" <x>', (string)$xml->history_record[1]['line']);
        $this->assertSame('ANSWER', (string)$xml->history_record[1]->details['status']);

        $this->assertSame(5, CdrXmlBuilder::build([], 5)['maxOffset']);
    }

    private function cdrRow(int $id, string $linkedId, string $start, string $did): array
    {
        return [
            'id' => $id, 'linkedid' => $linkedId, 'did' => $did, 'disposition' => 'ANSWERED',
            'start' => $start, 'answer' => $start, 'endtime' => $start, 'duration' => '10', 'billsec' => '8',
            'recordingfile' => '/storage/a&b.mp3', 'src_num' => '79001112233', 'dst_num' => '79004445566',
        ];
    }

    private function testEventMapper(): void
    {
        $this->assertSame(null, MtsEventMapper::decode('not json'));
        $this->assertSame(null, MtsEventMapper::decode('{"CallID":1}'));
        $this->assertSame(null, MtsEventMapper::decode('[]'));
        $data = MtsEventMapper::decode('{"CallID":75317940442,"Domain":"9807100241.RU","EventType":"EndCall"}');
        $this->assertSame('EndCall', $data['EventType']);

        $this->assertSame('Calling', MtsEventMapper::state('CallAccepted'));
        $this->assertSame('Connected', MtsEventMapper::state('CallAnswered'));
        $this->assertSame('Finished', MtsEventMapper::state('EndCall'));
        $this->assertSame(null, MtsEventMapper::state('Other'));

        $accounts = [
            ['id' => 1, 'domain' => '9807100241.ru', 'legacyIdFormat' => '1'],
            ['id' => 2, 'domain' => 'second.ru', 'legacyIdFormat' => '0'],
        ];
        $legacy = MtsEventMapper::findAccount($accounts, '9807100241.RU');
        $this->assertSame(1, $legacy['id'], 'domain match is case-insensitive');
        $this->assertSame('75317940442', MtsEventMapper::callId($data, $legacy));

        $second = MtsEventMapper::findAccount($accounts, 'SECOND.ru');
        $this->assertSame('2-75317940442', MtsEventMapper::callId($data, $second));

        $this->assertSame(null, MtsEventMapper::findAccount($accounts, 'unknown.ru'));
        $this->assertSame(null, MtsEventMapper::findAccount($accounts, ''));
        $this->assertSame('75317940442', MtsEventMapper::callId($data, null), 'unknown portal keeps raw CallID');
    }

    private function assertSame($expected, $actual, string $message = ''): void
    {
        $this->assertions++;
        if ($expected !== $actual) {
            throw new RuntimeException(
                ($message !== '' ? $message . ': ' : '') .
                'Expected ' . var_export($expected, true) . ', got ' . var_export($actual, true)
            );
        }
    }
}

(new MultiPortalTest())->run();
