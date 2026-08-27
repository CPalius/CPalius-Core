<?php

declare(strict_types=1);

namespace App\Controller;

use App\Core\Cron\CronDispatcher;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Pseudo-Cron HTTP giriş noktası: gerçek bir crontab/Task Scheduler girdisi
 * kuramayan (ör. paylaşımlı hosting) kurulumlar için, dışarıdan bir HTTP
 * istemcisiyle (ör. cPanel "Cron Job URL" özelliği, UptimeRobot, harici bir
 * scheduler) CPalius'un cron dispatcher'ını tetikleyen tek uç nokta.
 *
 * cp:cron:run (CLI) ile AYNI CronDispatcher::runDueTasks() metodunu çağırır
 * — dispatch mantığı BURADA TEKRARLANMAZ (bkz. CronDispatcher docblock'u).
 *
 * Güvenlik: AACP kurtarma kapısıyla (AACP_RECOVERY_TOKEN) AYNI desen —
 * .env'deki CRON_TOKEN ile sabit zamanlı (timing-safe, hash_equals) bir
 * karşılaştırma yapılır. Token boşsa (fail-safe) uç nokta TAMAMEN kapalı
 * kabul edilir; token verilmemiş veya yanlışsa 401 döner, dispatcher HİÇ
 * ÇAĞRILMAZ. Bu uç nokta admin oturumu GEREKTİRMEZ (dışarıdan, oturumsuz
 * bir HTTP istemcisi tarafından çağrılabilmesi gerekir) — güvenliği
 * TAMAMEN token'a dayanır, bu yüzden token'ın production'da güçlü ve
 * gizli tutulması ZORUNLUDUR (AACP_RECOVERY_TOKEN ile aynı gerekçe).
 */
final class CronExecuteController
{
    public function __construct(
        private readonly CronDispatcher $cronDispatcher,
        private readonly string $cronToken,
    ) {
    }

    #[Route('/cron/execute', name: 'cp_cron_execute', methods: ['GET', 'POST'])]
    public function __invoke(Request $request): Response
    {
        $submittedToken = (string) $request->query->get('token', $request->request->get('token', ''));

        if ($this->cronToken === '' || $submittedToken === '' || !hash_equals($this->cronToken, $submittedToken)) {
            return new JsonResponse(['error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        $results = $this->cronDispatcher->runDueTasks();

        return new JsonResponse([
            'executedAt' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            'taskCount' => \count($results),
            'results' => $results,
        ]);
    }
}
