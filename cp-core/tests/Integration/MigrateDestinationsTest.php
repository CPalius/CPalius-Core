<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Core\Migrate\Destination\TermDestination;
use App\Core\Migrate\Destination\UserDestination;
use App\Core\Migrate\MigrationRow;
use App\Core\Taxonomy\Entity\Term;
use App\Core\Taxonomy\Entity\Vocabulary;
use App\Entity\User;
use App\Tests\Support\IntegrationTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(UserDestination::class)]
#[CoversClass(TermDestination::class)]
final class MigrateDestinationsTest extends IntegrationTestCase
{
    private function users(): UserDestination
    {
        return new UserDestination($this->em());
    }

    private function terms(): TermDestination
    {
        return new TermDestination($this->em(), 'imported', 'Imported terms', 'tr');
    }

    public function testAnImportedUserCannotBeLoggedIntoUntilItsOwnerResetsThePassword(): void
    {
        $id = $this->users()->write(new MigrationRow('a', ['email' => 'someone@example.test']), null);

        $user = $this->em()->find(User::class, (int) $id);
        self::assertNotNull($user);
        // No foreign hash is carried over and none is invented: the stored
        // value is not a hash of anything, so no input opens the account.
        self::assertStringStartsWith('!imported-', $user->getPassword());
    }

    /**
     * The regression this exists for: User keeps first-class profile fields
     * inside its JSON column (first_name, bio, signature, avatar, …). A
     * destination that replaced that column wholesale erased everything the
     * export did not know about — silently, on the second import of a user who
     * had since filled in their profile here.
     */
    public function testReimportingAUserKeepsProfileFieldsTheExportKnowsNothingAbout(): void
    {
        $destination = $this->users();
        $id = $destination->write(new MigrationRow('a', [
            'email' => 'author@example.test',
            'firstName' => 'Ali',
        ]), null);

        $user = $this->em()->find(User::class, (int) $id);
        self::assertNotNull($user);
        $user->setBio('Written here, not imported');
        $user->setLocation('Ankara');
        $this->em()->flush();

        $destination->write(new MigrationRow('a', [
            'email' => 'author@example.test',
            'firstName' => 'Ali',
            'lastName' => 'Yılmaz',
        ]), $id);

        $this->em()->clear();
        $reloaded = $this->em()->find(User::class, (int) $id);
        self::assertNotNull($reloaded);

        self::assertSame('Written here, not imported', $reloaded->getBio());
        self::assertSame('Ankara', $reloaded->getLocation());
        // And the imported fields still land.
        self::assertSame('Ali', $reloaded->getFirstName());
        self::assertSame('Yılmaz', $reloaded->getLastName());
    }

    public function testAnExistingAccountWithTheSameAddressIsMatchedRatherThanDuplicated(): void
    {
        $existing = new User('already@example.test');
        $existing->setPassword('x')->setStatus(User::STATUS_ACTIVE);
        $this->em()->persist($existing);
        $this->em()->flush();

        // The map has never seen this row, but the address is taken; inserting
        // anyway would hit the unique index partway through an import.
        $id = $this->users()->write(new MigrationRow('a', ['email' => 'already@example.test']), null);

        self::assertSame((string) $existing->getId(), $id);
        self::assertCount(1, $this->em()->getRepository(User::class)->findBy([]));
    }

    public function testATakenUsernameIsSuffixedRatherThanHittingTheUniqueIndex(): void
    {
        $existing = new User('admin@example.test');
        $existing->setPassword('x')->setStatus(User::STATUS_ACTIVE)->setUsername('Slaweally');
        $this->em()->persist($existing);
        $this->em()->flush();

        $id = $this->users()->write(new MigrationRow('xf-1', [
            'email' => 'member@eski.test',
            'username' => 'Slaweally',
        ]), null);

        $imported = $this->em()->find(User::class, (int) $id);
        self::assertNotNull($imported);
        self::assertSame('Slaweally-2', $imported->getUsername());
        self::assertSame('Slaweally', $existing->getUsername());
        self::assertCount(2, $this->em()->getRepository(User::class)->findBy([]));
    }

    public function testRollbackDoesNotDeleteAnAccountThatAlreadyExistedHere(): void
    {
        $existing = new User('admin@example.test');
        $existing->setPassword('real-hash')->setStatus(User::STATUS_ACTIVE);
        $this->em()->persist($existing);
        $this->em()->flush();
        $id = (string) $existing->getId();

        $this->users()->write(new MigrationRow('xf-1', [
            'email' => 'admin@example.test',
            'username' => 'admin',
        ]), null);

        self::assertFalse($this->users()->delete($id));
        self::assertNotNull($this->em()->find(User::class, (int) $id));
    }

    public function testAUserRowWithoutAnEmailIsRefused(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/needs an "email"/');

        $this->users()->write(new MigrationRow('a', ['username' => 'nobody']), null);
    }

    public function testTheVocabularyIsCreatedOnFirstUse(): void
    {
        self::assertCount(0, $this->em()->getRepository(Vocabulary::class)->findBy(['machineName' => 'imported']));

        $this->terms()->write(new MigrationRow('a', ['name' => 'Haberler']), null);

        $vocabulary = $this->em()->getRepository(Vocabulary::class)->findOneBy(['machineName' => 'imported']);
        self::assertInstanceOf(Vocabulary::class, $vocabulary);
    }

    public function testATermIsMatchedBySlugSoAReimportDoesNotHitTheUniqueIndex(): void
    {
        $destination = $this->terms();
        $first = $destination->write(new MigrationRow('a', ['name' => 'Haberler', 'slug' => 'haberler']), null);

        // Same slug, no recorded id — the path a half-imported site takes.
        $second = $destination->write(new MigrationRow('a', ['name' => 'Haberler güncel', 'slug' => 'haberler']), null);

        self::assertSame($first, $second);
        self::assertCount(1, $this->em()->getRepository(Term::class)->findBy([]));
    }

    public function testATermIsNeverItsOwnParent(): void
    {
        $destination = $this->terms();
        $id = $destination->write(new MigrationRow('a', ['name' => 'Kök', 'slug' => 'kok']), null);

        // A source export can assert this; persisting it would create a cycle
        // that every tree walk in the product would then have to survive.
        $destination->write(new MigrationRow('a', ['name' => 'Kök', 'slug' => 'kok', 'parentId' => $id]), $id);

        $this->em()->clear();
        $term = $this->em()->find(Term::class, (int) $id);
        self::assertNotNull($term);
        self::assertNull($term->getParent());
    }

    public function testATermSlugIsDerivedFromTheNameWhenAbsent(): void
    {
        $id = $this->terms()->write(new MigrationRow('a', ['name' => 'Genel Duyurular']), null);

        $term = $this->em()->find(Term::class, (int) $id);
        self::assertNotNull($term);
        self::assertSame('genel-duyurular', $term->getSlug());
    }
}
