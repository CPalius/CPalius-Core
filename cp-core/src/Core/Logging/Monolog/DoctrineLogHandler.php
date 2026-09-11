<?php

declare(strict_types=1);

namespace App\Core\Logging\Monolog;

use App\Core\Logging\Repository\LogEntryRepository;
use App\Core\Settings\SettingsRegistry;
use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Level;
use Monolog\LogRecord;

/**
 * Buffers Monolog records and writes them to cp_log_entries on flush().
 * Never throws — logging must not take down the request (Core Never Dies).
 */
final class DoctrineLogHandler extends AbstractProcessingHandler
{
    private const MAX_BUFFER = 100;

    /** @var list<LogRecord> */
    private array $buffer = [];

    private bool $flushing = false;

    public function __construct(
        private readonly LogEntryRepository $repository,
        private readonly SettingsRegistry $settings,
        int|string|Level $level = Level::Warning,
        bool $bubble = true,
    ) {
        parent::__construct($level, $bubble);
    }

    protected function write(LogRecord $record): void
    {
        try {
            $min = $this->resolveMinLevel();
            if ($record->level->value < $min->value) {
                return;
            }

            $this->buffer[] = $record;
            if (\count($this->buffer) >= self::MAX_BUFFER) {
                $this->flush();
            }
        } catch (\Throwable) {
            // swallow
        }
    }

    public function flush(): void
    {
        if ($this->flushing || $this->buffer === []) {
            return;
        }

        $this->flushing = true;
        $batch = $this->buffer;
        $this->buffer = [];

        try {
            foreach ($batch as $record) {
                $this->repository->insertRow(
                    strtolower($record->level->getName()),
                    $record->channel,
                    $record->message,
                    $this->sanitize($record->context),
                    $this->sanitize($record->extra),
                    \DateTimeImmutable::createFromInterface($record->datetime),
                );
            }
        } catch (\Throwable) {
            // Table missing during install, DB down — never escalate.
        } finally {
            $this->flushing = false;
        }
    }

    public function close(): void
    {
        $this->flush();
        parent::close();
    }

    public function reset(): void
    {
        $this->flush();
        $this->buffer = [];
        parent::reset();
    }

    private function resolveMinLevel(): Level
    {
        try {
            $raw = strtolower(trim((string) $this->settings->get('logging.min_level', 'warning')));
        } catch (\Throwable) {
            $raw = 'warning';
        }

        return match ($raw) {
            'debug' => Level::Debug,
            'info' => Level::Info,
            'notice' => Level::Notice,
            'error' => Level::Error,
            'critical' => Level::Critical,
            'alert' => Level::Alert,
            'emergency' => Level::Emergency,
            default => Level::Warning,
        };
    }

    /**
     * @param array<mixed> $data
     *
     * @return array<string, mixed>
     */
    private function sanitize(array $data): array
    {
        $out = [];
        foreach ($data as $key => $value) {
            $name = \is_string($key) ? $key : (string) $key;
            if (\is_scalar($value) || $value === null) {
                $out[$name] = $value;
                continue;
            }
            if ($value instanceof \DateTimeInterface) {
                $out[$name] = $value->format(\DATE_ATOM);
                continue;
            }
            if (\is_array($value)) {
                $out[$name] = $this->sanitize($value);
                continue;
            }
            if (\is_object($value)) {
                $out[$name] = $value::class;
                continue;
            }
            $out[$name] = get_debug_type($value);
        }

        return $out;
    }
}
