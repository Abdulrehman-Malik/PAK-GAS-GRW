<?php

namespace App\Controllers;

use App\Models\CustomerModel;
use App\Models\CylinderTypeModel;
use App\Services\OldIssuedCylinderService;
use App\Services\PermissionService;
use CodeIgniter\Controller;

/** Inventory > Old Issued Cylinders: cylinders customers already held before the system was used. */
class OldIssuedCylinders extends Controller
{
    private function guard(): ?\CodeIgniter\HTTP\ResponseInterface
    {
        if(!PermissionService::allows('INVENTORY_MANAGE')) return $this->response->setStatusCode(403)->setBody('Forbidden');
        return null;
    }

    private function locationId(): int { return (int)session()->get('location_id'); }
    private function userId(): int { return (int)session()->get('user_id'); }

    public function index()
    {
        if($r=$this->guard()) return $r;
        return view('inventory/old_issued',[
            'title'=>'Old Issued Cylinders',
            'customers'=>(new CustomerModel())->activeDirectory(),
            'types'=>(new CylinderTypeModel())->activeOrdered(),
            'entries'=>(new OldIssuedCylinderService())->recent($this->locationId()),
        ]);
    }

    public function save()
    {
        if($r=$this->guard()) return $r;
        try{
            $result=(new OldIssuedCylinderService())->save((array)$this->request->getPost('rows'),(string)$this->request->getPost('note'),$this->userId(),$this->locationId());
            return redirect()->to(site_url('inventory/old-issued'))->with('success','Saved '.$result['cylinders'].' cylinder(s). Outstanding added to customers: Rs. '.number_format($result['outstanding'],2).'.');
        }catch(\Throwable $e){
            return redirect()->back()->withInput()->with('error',$e->getMessage());
        }
    }

    public function void(int $id)
    {
        if($r=$this->guard()) return $r;
        try{
            (new OldIssuedCylinderService())->void($id,(string)$this->request->getPost('reason'),$this->userId(),$this->locationId());
            return redirect()->to(site_url('inventory/old-issued'))->with('success','Entry voided.');
        }catch(\Throwable $e){
            return redirect()->to(site_url('inventory/old-issued'))->with('error',$e->getMessage());
        }
    }
}
