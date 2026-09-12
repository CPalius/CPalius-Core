<?php

declare(strict_types=1);

namespace App\Core\Migrate;

/**
 * Where rows come from: a CSV file, a WordPress export, a legacy database.
 *
 * rows() returns an iterable and is expected to STREAM. A source that builds
 * the whole set in memory first works fine on a demo export and dies on the
 * real one — which is exactly how WordPress's own importer earned its
 * reputation. Drivers should yield.
 */
interface MigrationSourceInterface
{
    /**
     * One line an operator can recognise, shown in --dry-run and status output,
     * e.g. "CSV file var/import/customers.csv".
     */
    public function describe(): string;

    /**
     * @return iterable<MigrationRow>
     */
    public function rows(): iterable;

    /**
     * Total rows, when that can be known without consuming the stream.
     *
     * Null means "unknowable up front" and is a legitimate answer: a driver
     * that reads a 2 GB export twice just to print a progress total has made
     * the import twice as slow for cosmetics.
     */
    public function count(): ?int;
}
