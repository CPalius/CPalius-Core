<?php

declare(strict_types=1);

namespace App\Tests\Unit\Core\Audit;

use App\Core\Audit\EventListener\AuditLogListener;
use App\Core\Resource\Attribute\CpField;
use App\Core\Resource\ResourceRegistry;
use App\Core\Settings\SettingsRegistry;
use Doctrine\ORM\Mapping\Column;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

#[CoversClass(AuditLogListener::class)]
final class AuditSensitiveFieldsTest extends TestCase
{
    public function testOnlyMarkedPropertiesAreSensitiveIncludingInheritedOnes(): void
    {
        $entity = new class extends AuditSensitiveFieldsBase {
            #[CpField(sensitive: true)]
            public string $salary = '0';

            #[CpField(label: 'Name')]
            public string $name = '';
        };

        self::assertSame(['salary' => true, 'iban' => true], $this->sensitiveFields($entity));
    }

    public function testAnEncryptedColumnIsSensitiveEvenWithoutTheFlag(): void
    {
        $entity = new class {
            #[Column(type: 'cp_encrypted', nullable: true)]
            public ?string $nationalId = null;

            #[Column(type: 'string')]
            public string $name = '';
        };

        self::assertSame(['nationalId' => true], $this->sensitiveFields($entity));
    }

    public function testAPlainEntityHasNoSensitiveFields(): void
    {
        self::assertSame([], $this->sensitiveFields(new \stdClass()));
    }

    /**
     * @return array<string, true>
     */
    private function sensitiveFields(object $entity): array
    {
        $listener = new AuditLogListener(
            (new \ReflectionClass(ResourceRegistry::class))->newInstanceWithoutConstructor(),
            $this->createMock(TokenStorageInterface::class),
            $this->createMock(SettingsRegistry::class),
        );

        return (new \ReflectionMethod($listener, 'sensitiveFields'))->invoke($listener, $entity);
    }
}

abstract class AuditSensitiveFieldsBase
{
    #[CpField(sensitive: true)]
    public string $iban = '';
}
