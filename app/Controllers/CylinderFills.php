<?php

namespace App\Controllers;

use App\Models\CylinderTypeModel;
use App\Services\CylinderFillService;
use App\Services\PermissionService;
use CodeIgniter\Controller;

/** Inventory > Fill / Empty Cylinders: converts shop cylinders between empty and full and keeps the history. */
class CylinderFills extends Controller
{
    private function guard(): ?\CodeIgniter\HTTP\ResponseInterface
    {
        if(!PermissionService::allows('INVENTORY_MANAGE')) return $this->response->setStatusCode(403)->setBody('Forbidden');
        return null;
    }

    public function index()
    {
        if($r=$this->guard()) return $r;
        $locationId=(int)session()->get('location_id');
        $service=new CylinderFillService();
        return view('inventory/cylinder_fills',[
            'title'=>'Fill / Empty Cylinders',
            'types'=>(new CylinderTypeModel())->activeOrdered(),
            'stock'=>$service->stockByType($locationId),
            'history'=>$service->history($locationId),
        ]);
    }

    public function save()
    {
        if($r=$this->guard()) return $r;
        try{
            $result=(new CylinderFillService())->convert((string)$this->request->getPost('direction'),(int)$this->request->getPost('cylinder_type_id'),(string)$this->request->getPost('quantity'),(string)$this->request->getPost('notes'),(int)session()->get('user_id'),(int)session()->get('location_id'));
            $message=$result['direction']===CylinderFillService::EMPTY_TO_FULL
                ?$result['quantity'].' cylinder(s) converted to full. Gas added: '.number_format($result['total_gas'],2).' KG.'
                :$result['quantity'].' cylinder(s) converted to empty. Gas removed: '.number_format($result['total_gas'],2).' KG.';
            return redirect()->to(site_url('inventory/fill-empty'))->with('success',$message);
        }catch(\Throwable $e){
            return redirect()->back()->withInput()->with('error',$e->getMessage());
        }
    }
}
