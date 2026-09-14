<?php

declare(strict_types=1);

namespace App\Core\Storage;

/**
 * A place outside this server that CPalius can put a file.
 *
 * The interface is deliberately tiny — put, has, delete, test — because that is
 * the whole of what the two callers need. Media offload copies an upload up and
 * asks later whether it arrived; the backup shipper sends an archive and prunes
 * old ones. Nothing in CPalius reads a file back down over this interface, and
 * adding a get() "for completeness" would invite code that depends on the
 * remote being readable at request time, which is exactly the dependency the
 * offload design (local original, remote copy) exists to avoid.
 *
 * Keys are always POSIX-style, relative, and without a leading slash. The
 * driver owns whatever prefix the operator configured; callers pass the same
 * key they would use locally (e.g. "2026/09/<hash>.jpg").
 */
interface RemoteTargetInterface
{
    /** Driver id as stored in settings: s3 | r2 | ftp. */
    public function type(): string;

    /**
     * Uploads a local file, creating intermediate directories where the
     * transport has them. Overwrites an existing object of the same key.
     *
     * @throws StorageException when the file could not be placed
     */
    public function put(string $localPath, string $remoteKey): void;

    /**
     * @throws StorageException when the target could not be reached at all
     *                          (a definite "not there" returns false instead)
     */
    public function has(string $remoteKey): bool;

    /**
     * Removing something that is already gone is a success, not an error —
     * retention pruning runs repeatedly over the same list.
     *
     * @throws StorageException when the target refused the delete
     */
    public function delete(string $remoteKey): void;

    /**
     * Proves the credentials work by writing a probe object, confirming it is
     * there, and removing it again.
     *
     * A connection-only check is not enough: S3 credentials that can list but
     * not write, and an FTP account whose home directory is read-only, both
     * pass a login test and then fail on the first real upload — at which point
     * the operator has already turned the feature on and walked away.
     *
     * @throws StorageException with a human-readable reason on any failure
     */
    public function test(): void;

    /** Short operator-facing description, e.g. "s3://bucket/uploads". Never includes credentials. */
    public function describe(): string;
}
