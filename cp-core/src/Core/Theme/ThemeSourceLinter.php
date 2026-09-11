<?php

declare(strict_types=1);

namespace App\Core\Theme;

use Twig\Environment;
use Twig\Error\SyntaxError;
use Twig\Loader\ArrayLoader;
use Twig\Source;

/**
 * Parse-time checks so a broken theme file can be refused (or saved with confirmation).
 */
final class ThemeSourceLinter
{
    /**
     * @return list<string> human-readable problems; empty means the source parses
     */
    public function problems(string $kind, string $content, string $label): array
    {
        return match ($kind) {
            'twig' => $this->twigProblems($content, $label),
            'css', 'js' => $this->balancedProblems($content, $kind),
            default => [],
        };
    }

    /**
     * @return list<string>
     */
    private function twigProblems(string $content, string $label): array
    {
        $twig = new Environment(new ArrayLoader([]), [
            'autoescape' => false,
            'debug' => false,
            'cache' => false,
            'strict_variables' => false,
        ]);

        try {
            $twig->parse($twig->tokenize(new Source($content, $label)));
        } catch (SyntaxError $e) {
            $line = $e->getTemplateLine();

            return [sprintf('Twig: %s (line %d)', $e->getRawMessage(), $line > 0 ? $line : 1)];
        } catch (\Throwable $e) {
            return ['Twig: '.$e->getMessage()];
        }

        return [];
    }

    /**
     * @return list<string>
     */
    private function balancedProblems(string $content, string $kind): array
    {
        $pairs = ['(' => ')', '[' => ']', '{' => '}'];
        $opening = array_keys($pairs);
        $stack = [];
        $length = strlen($content);
        $line = 1;
        $i = 0;

        while ($i < $length) {
            $ch = $content[$i];
            if ($ch === "\n") {
                ++$line;
            }

            if ($kind === 'js' && $ch === '/' && ($content[$i + 1] ?? '') === '/') {
                $nl = strpos($content, "\n", $i);
                $i = $nl === false ? $length : $nl;
                continue;
            }

            if ($ch === '/' && ($content[$i + 1] ?? '') === '*') {
                $end = strpos($content, '*/', $i + 2);
                if ($end === false) {
                    return [sprintf('%s: unclosed block comment (line %d)', strtoupper($kind), $line)];
                }
                $line += substr_count($content, "\n", $i, $end - $i);
                $i = $end + 2;
                continue;
            }

            if ($ch === '"' || $ch === "'" || ($kind === 'js' && $ch === '`')) {
                $consumed = $this->consumeString($content, $i, $ch, $line);
                if ($consumed['error'] !== null) {
                    return [$consumed['error']];
                }
                $i = $consumed['index'];
                $line = $consumed['line'];
                continue;
            }

            if (\in_array($ch, $opening, true)) {
                $stack[] = ['ch' => $ch, 'line' => $line];
            } elseif (\in_array($ch, $pairs, true)) {
                if ($stack === []) {
                    return [sprintf('%s: unexpected "%s" (line %d)', strtoupper($kind), $ch, $line)];
                }
                $last = array_pop($stack);
                if ($pairs[$last['ch']] !== $ch) {
                    return [sprintf(
                        '%s: expected "%s" but found "%s" (line %d)',
                        strtoupper($kind),
                        $pairs[$last['ch']],
                        $ch,
                        $line,
                    )];
                }
            }

            ++$i;
        }

        if ($stack !== []) {
            $last = $stack[\count($stack) - 1];

            return [sprintf('%s: unclosed "%s" (line %d)', strtoupper($kind), $last['ch'], $last['line'])];
        }

        return [];
    }

    /**
     * @return array{index: int, line: int, error: ?string}
     */
    private function consumeString(string $content, int $start, string $quote, int $line): array
    {
        $length = strlen($content);
        $i = $start + 1;
        while ($i < $length) {
            $ch = $content[$i];
            if ($ch === "\n") {
                ++$line;
                if ($quote !== '`') {
                    return ['index' => $i, 'line' => $line, 'error' => sprintf('Unclosed %s string (line %d)', $quote.$quote, $line - 1)];
                }
            }
            if ($ch === '\\') {
                $i += 2;
                continue;
            }
            if ($ch === $quote) {
                return ['index' => $i + 1, 'line' => $line, 'error' => null];
            }
            ++$i;
        }

        return ['index' => $i, 'line' => $line, 'error' => sprintf('Unclosed %s string', $quote.$quote)];
    }
}
