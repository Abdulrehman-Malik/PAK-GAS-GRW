<?php
namespace App\Controllers\Filters;

use App\Libraries\Diagnostics;
use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\Files\UploadedFile;
use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

/** Logs every request and its response while diagnostics.enabled is on. */
class DiagnosticsFilter implements FilterInterface
{
    private static float $started = 0.0;

    public function before(RequestInterface $request,$arguments=null)
    {
        if(!Diagnostics::enabled()) return null;
        $path=trim($request->getPath(),'/');
        if(str_starts_with($path,'diagnostics/')){Diagnostics::mute();return null;}
        self::$started=microtime(true);
        session();
        $files=[];
        foreach($request->getFiles() as $name=>$file){if($file instanceof UploadedFile)$files[$name]=['name'=>$file->getClientName(),'size'=>$file->getSize()];}
        Diagnostics::write('request',[
            'method'=>$request->getMethod(),'path'=>$path,'get'=>$request->getGet(),'post'=>$request->getPost(),'files'=>$files,
            'ip'=>$request->getIPAddress(),'referer'=>$request->getHeaderLine('Referer'),'agent'=>$request->getHeaderLine('User-Agent'),
        ]);
        return null;
    }

    public function after(RequestInterface $request,ResponseInterface $response,$arguments=null)
    {
        if(!Diagnostics::enabled()) return null;
        $type=$response->getHeaderLine('Content-Type');
        $data=['status'=>$response->getStatusCode(),'ms'=>round((microtime(true)-self::$started)*1000,1),'content_type'=>$type,'queries'=>Diagnostics::queryStats()];
        if($response instanceof RedirectResponse)$data['redirect']=$response->getHeaderLine('Location');
        foreach(['error','success','migration_error'] as $flash){if(isset($_SESSION[$flash])&&is_string($_SESSION[$flash]))$data['flash'][$flash]=$_SESSION[$flash];}
        // JSON replies show what the screen was given (POS rates, posting errors). HTML pages are too large to keep.
        if(stripos($type,'json')!==false)$data['body']=Diagnostics::cut((string)$response->getBody(),Diagnostics::MAX_BODY);
        Diagnostics::write('response',$data);
        return null;
    }
}
