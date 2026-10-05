<?php
declare(strict_types=1);

namespace App\Controllers\Web;

use App\Core\Request;
use App\Core\Response;
use App\Services\Notify\CronRunner;

/** /cron/run?key=… — lo llama el cron del hosting o un servicio externo (cron-job.org) cada 1–5 min. */
final class CronController
{
    public function run(Request $request): Response
    {
        if (!hash_equals(CronRunner::key(), (string) $request->input('key', ''))) {
            return new Response('Clave inválida', 403, ['Content-Type' => 'text/plain; charset=utf-8']);
        }
        @set_time_limit(60);
        return Response::json(CronRunner::run(25));
    }
}
