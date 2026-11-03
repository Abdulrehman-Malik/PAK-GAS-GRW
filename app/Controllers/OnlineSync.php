<?php

namespace App\Controllers;

use App\Services\OnlineSyncService;
use App\Services\PermissionService;
use CodeIgniter\Controller;

/** Configuration > Online Database Sync: connection, Sync Now and the sync history. */
class OnlineSync extends Controller
{
    private function guard(): ?\CodeIgniter\HTTP\ResponseInterface
    {
        if(!PermissionService::allows('BACKUP_MANAGE')) return $this->response->setStatusCode(403)->setBody('Forbidden');
        return null;
    }

    public function index()
    {
        if($r=$this->guard()) return $r;
        $service=new OnlineSyncService();
        return view('online_sync/index',['title'=>'Online Database Sync','settings'=>$service->settings(),'logs'=>$service->logs()]);
    }

    public function save()
    {
        if($r=$this->guard()) return $r;
        try{
            (new OnlineSyncService())->save($this->request->getPost(),(int)session()->get('user_id'));
            return redirect()->to(site_url('online-sync'))->with('success','Online database settings saved.');
        }catch(\Throwable $e){
            return redirect()->back()->withInput()->with('error',$e->getMessage());
        }
    }

    public function test()
    {
        if($r=$this->guard()) return $r;
        try{
            $service=new OnlineSyncService();
            $message=$service->test($service->fromForm($this->request->getPost()));
            return redirect()->back()->withInput()->with('success',$message);
        }catch(\Throwable $e){
            return redirect()->back()->withInput()->with('error',$e->getMessage());
        }
    }

    public function run()
    {
        if($r=$this->guard()) return $r;
        $replace=trim((string)$this->request->getPost('replace_confirm'))!=='';
        if($replace&&trim((string)$this->request->getPost('replace_confirm'))!=='REPLACE ONLINE DATABASE') return redirect()->to(site_url('online-sync'))->with('error','Type REPLACE ONLINE DATABASE exactly to replace the online database.');
        try{
            set_time_limit(0);
            $result=(new OnlineSyncService())->run('manual',(int)session()->get('user_id'),$replace);
            return redirect()->to(site_url('online-sync'))->with('success','Sync finished: '.$result['tables'].' tables and '.$result['rows'].' rows copied.');
        }catch(\Throwable $e){
            return redirect()->to(site_url('online-sync'))->with('error',$e->getMessage());
        }
    }
}
