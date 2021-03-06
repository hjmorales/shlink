<?php

declare(strict_types=1);

namespace ShlinkioTest\Shlink\CLI\Command\Api;

use PHPUnit\Framework\TestCase;
use Prophecy\PhpUnit\ProphecyTrait;
use Prophecy\Prophecy\ObjectProphecy;
use Shlinkio\Shlink\CLI\Command\Api\ListKeysCommand;
use Shlinkio\Shlink\Core\Entity\Domain;
use Shlinkio\Shlink\Rest\ApiKey\Model\RoleDefinition;
use Shlinkio\Shlink\Rest\Entity\ApiKey;
use Shlinkio\Shlink\Rest\Service\ApiKeyServiceInterface;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

class ListKeysCommandTest extends TestCase
{
    use ProphecyTrait;

    private CommandTester $commandTester;
    private ObjectProphecy $apiKeyService;

    public function setUp(): void
    {
        $this->apiKeyService = $this->prophesize(ApiKeyServiceInterface::class);
        $command = new ListKeysCommand($this->apiKeyService->reveal());
        $app = new Application();
        $app->add($command);
        $this->commandTester = new CommandTester($command);
    }

    /**
     * @test
     * @dataProvider provideKeysAndOutputs
     */
    public function returnsExpectedOutput(array $keys, bool $enabledOnly, string $expected): void
    {
        $listKeys = $this->apiKeyService->listKeys($enabledOnly)->willReturn($keys);

        $this->commandTester->execute(['--enabled-only' => $enabledOnly]);
        $output = $this->commandTester->getDisplay();

        self::assertEquals($expected, $output);
        $listKeys->shouldHaveBeenCalledOnce();
    }

    public function provideKeysAndOutputs(): iterable
    {
        yield 'all keys' => [
            [ApiKey::withKey('foo'), ApiKey::withKey('bar'), ApiKey::withKey('baz')],
            false,
            <<<OUTPUT
            +-----+------------+-----------------+-------+
            | Key | Is enabled | Expiration date | Roles |
            +-----+------------+-----------------+-------+
            | foo | +++        | -               | Admin |
            | bar | +++        | -               | Admin |
            | baz | +++        | -               | Admin |
            +-----+------------+-----------------+-------+

            OUTPUT,
        ];
        yield 'enabled keys' => [
            [ApiKey::withKey('foo')->disable(), ApiKey::withKey('bar')],
            true,
            <<<OUTPUT
            +-----+-----------------+-------+
            | Key | Expiration date | Roles |
            +-----+-----------------+-------+
            | foo | -               | Admin |
            | bar | -               | Admin |
            +-----+-----------------+-------+

            OUTPUT,
        ];
        yield 'with roles' => [
            [
                ApiKey::withKey('foo'),
                $this->apiKeyWithRoles('bar', [RoleDefinition::forAuthoredShortUrls()]),
                $this->apiKeyWithRoles('baz', [RoleDefinition::forDomain((new Domain('example.com'))->setId('1'))]),
                ApiKey::withKey('foo2'),
                $this->apiKeyWithRoles('baz2', [
                    RoleDefinition::forAuthoredShortUrls(),
                    RoleDefinition::forDomain((new Domain('example.com'))->setId('1')),
                ]),
                ApiKey::withKey('foo3'),
            ],
            true,
            <<<OUTPUT
            +------+-----------------+--------------------------+
            | Key  | Expiration date | Roles                    |
            +------+-----------------+--------------------------+
            | foo  | -               | Admin                    |
            | bar  | -               | Author only              |
            | baz  | -               | Domain only: example.com |
            | foo2 | -               | Admin                    |
            | baz2 | -               | Author only              |
            |      |                 | Domain only: example.com |
            | foo3 | -               | Admin                    |
            +------+-----------------+--------------------------+

            OUTPUT,
        ];
    }

    private function apiKeyWithRoles(string $key, array $roles): ApiKey
    {
        $apiKey = ApiKey::withKey($key);
        foreach ($roles as $role) {
            $apiKey->registerRole($role);
        }

        return $apiKey;
    }
}
