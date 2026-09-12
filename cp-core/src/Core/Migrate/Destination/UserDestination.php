<?php

declare(strict_types=1);

namespace App\Core\Migrate\Destination;

use App\Core\Migrate\MigrationDestinationInterface;
use App\Core\Migrate\MigrationRow;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Writes rows into User.
 *
 * PASSWORDS ARE NOT CARRIED OVER. Foreign systems store hashes this one cannot
 * verify (WordPress phpass, XenForo bcrypt with its own scheme, MyBB salted
 * md5), and the usual workarounds are both bad: re-hashing the hash keeps a
 * weak primitive alive forever behind a strong-looking wrapper, and inventing
 * a password silently gives every imported account a credential nobody chose.
 * Imported users land with an unusable password, so they arrive through
 * password reset — which also proves they still control the address.
 *
 * Their status defaults to active, deliberately: an inactive user is refused by
 * QueryScopeApplier before any capability is consulted, which would also close
 * the password-reset path that is the only way into these accounts. Active
 * costs nothing here because no password can open them. An operator who would
 * rather review the list first can pass STATUS_INACTIVE and activate in bulk.
 *
 * Email is the identity that matters here, so it is what uniqueness is checked
 * on: two source rows sharing an address are the same person, and a second
 * insert would hit the unique index mid-import.
 */
final class UserDestination implements MigrationDestinationInterface
{
    /** Keys consumed as columns; everything else becomes JSON data. */
    private const RESERVED = ['email', 'username', 'firstName', 'lastName', 'status', 'roles'];

    /**
     * @param list<string> $defaultRoles CPalius role names given to every imported account
     */
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly array $defaultRoles = ['member'],
        private readonly string $defaultStatus = User::STATUS_ACTIVE,
    ) {
    }

    public function describe(): string
    {
        return sprintf('User (role %s, status %s, password unusable)', implode('+', $this->defaultRoles), $this->defaultStatus);
    }

    public function entityType(): string
    {
        return 'user';
    }

    public function write(MigrationRow $row, ?string $existingId): string
    {
        $email = trim($row->getString('email'));

        if ($email === '') {
            throw new \RuntimeException('A user row needs an "email"; it is the identity an imported account is matched and recovered by.');
        }

        $user = $existingId === null ? null : $this->entityManager->find(User::class, (int) $existingId);

        // Match an account that already exists under this address even when the
        // map has no record of it — a site that was half-imported by hand, or a
        // second migration bringing the same person across. Inserting anyway
        // would fail on the unique index partway through the run.
        $user ??= $this->entityManager->getRepository(User::class)->findOneBy(['email' => $email]);

        if ($user === null) {
            $user = new User($email);
            // Not a hash of anything: no input can produce this string through
            // the password hasher, so the account cannot be logged into until
            // its owner sets a password.
            $user->setPassword('!imported-'.bin2hex(random_bytes(16)));
            $user->setStatus($this->defaultStatus);
            $user->setCpaliusRoles($this->defaultRoles);
            $this->entityManager->persist($user);
        } else {
            $user->setEmail($email);
        }

        // Order and merge both matter here, and getting either wrong destroys
        // data silently. User keeps first-class profile fields INSIDE its JSON
        // (first_name, last_name, bio, avatar, signature, …), so replacing the
        // whole array would wipe everything the export does not know about, and
        // writing the array after the typed setters would wipe what they just
        // set. Merge first, then let the setters win.
        $user->setData([...$user->getData(), ...$this->dataFrom($row)]);

        $username = trim($row->getString('username'));
        if ($username !== '') {
            $user->setUsername($username);
        }

        $firstName = trim($row->getString('firstName'));
        if ($firstName !== '') {
            $user->setFirstName($firstName);
        }

        $lastName = trim($row->getString('lastName'));
        if ($lastName !== '') {
            $user->setLastName($lastName);
        }

        $this->entityManager->flush();

        $id = $user->getId();

        if ($id === null) {
            throw new \RuntimeException('The user was flushed but has no id; the map cannot record this row.');
        }

        return (string) $id;
    }

    public function delete(string $destinationId): bool
    {
        $user = $this->entityManager->find(User::class, (int) $destinationId);

        if ($user === null) {
            return false;
        }

        $this->entityManager->remove($user);
        $this->entityManager->flush();

        return true;
    }

    /**
     * @return array<string, mixed>
     */
    private function dataFrom(MigrationRow $row): array
    {
        $data = $row->data;

        foreach (self::RESERVED as $key) {
            unset($data[$key]);
        }

        return $data;
    }
}
