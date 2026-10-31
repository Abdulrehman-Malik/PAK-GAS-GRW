<?php

namespace App\Controllers;

use App\Libraries\Diagnostics as DiagnosticsLog;
use App\Services\PermissionService;
use CodeIgniter\Controller;

/** Receives browser events for the diagnostics log and lets an administrator download the log. */
class Diagnostics extends Controller
{
    private const MAX_EVENTS = 200;

    public function client()
    {
        if(!DiagnosticsLog::enabled()) return $this->response->setStatusCode(204);
        $body=(string)$this->request->getBody();
        if(strlen($body)>DiagnosticsLog::MAX_BODY*8) return $this->response->setStatusCode(413);
        $payload=json_decode($body,true);
        $events=is_array($payload['events']??null)?array_slice($payload['events'],0,self::MAX_EVENTS):[];
        $page=is_array($payload['page']??null)?$payload['page']:[];
        // The filter muted this request so that it does not log itself; its events still go in.
        DiagnosticsLog::mute(false);
        foreach($events as $event){
            if(is_array($event)) DiagnosticsLog::write('browser',['page'=>$page,'event'=>$event]);
        }
        DiagnosticsLog::mute();
        return $this->response->setStatusCode(204);
    }

    public function download()
    {
        if(!PermissionService::allows('BACKUP_MANAGE')) return $this->response->setStatusCode(403)->setBody('Forbidden');
        $date=(string)($this->request->getGet('date')?:date('Y-m-d'));
        if(!preg_match('/^\d{4}-\d{2}-\d{2}$/',$date)) return $this->response->setStatusCode(422)->setBody('Invalid date.');
        $file=DiagnosticsLog::file($date);
        if(!is_file($file)) return $this->response->setStatusCode(404)->setBody('No diagnostics log for '.$date.'. Check that diagnostics.enabled = true is in .env.');
        return $this->response->download($file,null)->setFileName(basename($file));
    }
}
