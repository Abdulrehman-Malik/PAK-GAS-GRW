<?php

namespace App\Libraries;

use CodeIgniter\Log\Handlers\BaseHandler;

/** Copies application errors and uncaught exceptions into the diagnostics log, next to the request that caused them. */
class DiagnosticsLogHandler extends BaseHandler
{
    public function handle($level, $message): bool
    {
        Diagnostics::write('log', ['level' => $level, 'message' => Diagnostics::cut(Diagnostics::maskSql((string) $message))]);
        return true;
    }
}
