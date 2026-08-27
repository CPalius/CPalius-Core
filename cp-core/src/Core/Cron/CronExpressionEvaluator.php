<?php

declare(strict_types=1);

namespace App\Core\Cron;

/**
 * Standart 5 alanlı cron ifadesini ("dakika saat gün ay haftanınGünü",
 * ör. "*"'/5 * * * *") harici bir paket (dragonmantank/cron-expression vb.)
 * KURMADAN, saf PHP ile bir DateTimeImmutable anına karşı eşleştiren sade
 * bir değerlendirici.
 *
 * Bilinçli olarak sadece "bu dakika ifadeyle eşleşiyor mu?" sorusuna
 * cevap verir (isDue()) — "bir sonraki çalışma zamanı ne olacak?" gibi
 * genel bir hesaplama YAPMAZ, çünkü cp:cron:run dispatcher'ı HER dakika
 * (veya daha sık) tetiklenip "şu an eşleşiyor mu" diye sorması yeterlidir
 * (bkz. RunDueCronJobsCommand). Desteklenen sözdizimi: "*", tekil sayı
 * ("5"), liste ("1,2,3"), aralık ("1-5"), adım ("*"'/5" veya "1-10/2").
 * Desteklenmeyen (ör. "@daily" gibi kısayol) veya sözdizimi hatalı bir
 * ifade fail-safe olarak HER ZAMAN false döner — bilinçsizce "her dakika
 * çalıştır" gibi tehlikeli bir varsayılana düşülmez.
 */
final class CronExpressionEvaluator
{
    public function isDue(string $expression, \DateTimeImmutable $at): bool
    {
        $fields = preg_split('/\s+/', trim($expression));
        if ($fields === false || \count($fields) !== 5) {
            return false;
        }

        [$minute, $hour, $dayOfMonth, $month, $dayOfWeek] = $fields;

        return $this->fieldMatches($minute, (int) $at->format('i'), 0, 59)
            && $this->fieldMatches($hour, (int) $at->format('G'), 0, 23)
            && $this->fieldMatches($dayOfMonth, (int) $at->format('j'), 1, 31)
            && $this->fieldMatches($month, (int) $at->format('n'), 1, 12)
            && $this->fieldMatches($dayOfWeek, (int) $at->format('w'), 0, 6);
    }

    /**
     * Bir cron ifadesinin genel sözdizimi olarak geçerli olup olmadığını
     * kontrol eder (AACP form validasyonu için) — gerçek bir tarihe karşı
     * eşleştirme yapmaz, sadece 5 alan ve her alanın desteklenen
     * sözdizimine uyup uymadığını denetler.
     */
    public function isValidExpression(string $expression): bool
    {
        $fields = preg_split('/\s+/', trim($expression));
        if ($fields === false || \count($fields) !== 5) {
            return false;
        }

        $ranges = [[0, 59], [0, 23], [1, 31], [1, 12], [0, 6]];

        foreach ($fields as $index => $field) {
            [$min, $max] = $ranges[$index];
            if (!$this->isFieldSyntaxValid($field, $min, $max)) {
                return false;
            }
        }

        return true;
    }

    private function fieldMatches(string $field, int $actual, int $min, int $max): bool
    {
        foreach (explode(',', $field) as $part) {
            if ($this->partMatches($part, $actual, $min, $max)) {
                return true;
            }
        }

        return false;
    }

    private function partMatches(string $part, int $actual, int $min, int $max): bool
    {
        $step = 1;
        if (str_contains($part, '/')) {
            [$part, $stepRaw] = explode('/', $part, 2);
            if (!ctype_digit($stepRaw) || (int) $stepRaw < 1) {
                return false;
            }
            $step = (int) $stepRaw;
        }

        if ($part === '*') {
            $rangeMin = $min;
            $rangeMax = $max;
        } elseif (str_contains($part, '-')) {
            [$rangeMinRaw, $rangeMaxRaw] = explode('-', $part, 2);
            if (!ctype_digit($rangeMinRaw) || !ctype_digit($rangeMaxRaw)) {
                return false;
            }
            $rangeMin = (int) $rangeMinRaw;
            $rangeMax = (int) $rangeMaxRaw;
        } elseif (ctype_digit($part)) {
            $rangeMin = $rangeMax = (int) $part;
        } else {
            return false;
        }

        if ($actual < $rangeMin || $actual > $rangeMax) {
            return false;
        }

        return ($actual - $rangeMin) % $step === 0;
    }

    private function isFieldSyntaxValid(string $field, int $min, int $max): bool
    {
        foreach (explode(',', $field) as $part) {
            if (!$this->isPartSyntaxValid($part, $min, $max)) {
                return false;
            }
        }

        return true;
    }

    private function isPartSyntaxValid(string $part, int $min, int $max): bool
    {
        if (str_contains($part, '/')) {
            [$part, $stepRaw] = explode('/', $part, 2);
            if (!ctype_digit($stepRaw) || (int) $stepRaw < 1) {
                return false;
            }
        }

        if ($part === '*') {
            return true;
        }

        if (str_contains($part, '-')) {
            [$rangeMinRaw, $rangeMaxRaw] = explode('-', $part, 2);
            if (!ctype_digit($rangeMinRaw) || !ctype_digit($rangeMaxRaw)) {
                return false;
            }

            return (int) $rangeMinRaw >= $min && (int) $rangeMaxRaw <= $max && (int) $rangeMinRaw <= (int) $rangeMaxRaw;
        }

        if (!ctype_digit($part)) {
            return false;
        }

        return (int) $part >= $min && (int) $part <= $max;
    }
}
