<?php

namespace Printq\Rest\Services;

use Printq\Rest\Services\ReadPdfExternal;

class ReadPdfService
{
    
    protected $pdf       = null;
    protected $doc       = null;
    protected $version   = null;
    protected $data      = [];
    protected $root_path = null;
    
    public function __construct($data, $files)
    {
        $this->input   = isset($data['input']) ? $data['input'] : null;
        $this->version = isset($data['version']) ? $data['version'] : null;
        $this->root_path = ROOT_PATH . '/data';
        $this->data = $data;
        if (!$this->input) {
            throw new \Exception("Pdf file name not not provided");
        }
        if (!$this->version) {
            throw new \Exception("Version not provided");
        }
        $path = $this->root_path . '/read_pdf/';
        $this->uploadFiles($path, $files);
  
        $pathininfo = pathinfo($this->input);
        $pdf = $this->root_path . '/read_pdf/'.$pathininfo['basename'];
        if(!file_exists($pdf)){
            throw new \Exception("File not Found");
        }
        $this->pdf = $this->getPdfResource();
        $this->doc = $this->getPdfDocResources($pdf);
    }
    
    public function handleRequest(){
        $result = [];
        switch($this->version){
            case "external":
                $pathininfo = pathinfo($this->input);
                $pdfExternal = new ReadPdfExternal($this->pdf,$this->doc, $this->data, $pathininfo['basename']);
                $result = $pdfExternal->handleRequest();
                break;
            default : break;
        }
        $this->clearFolder();
        return $result;
    }
    public function clearFolder(){
        $pathininfo = pathinfo($this->input);
        $pdf        = $this->root_path . '/read_pdf/' . $pathininfo['basename'];
        if(file_exists($pdf)){
            @unlink($pdf);
        }
    }
    public function uploadFiles($path, $files)
    {
        if ($files) {
            if (is_array($files) && count($files)) {
                foreach ($files as $up_file) {
                    $tmp_name = $up_file['tmp_name'];
                    $name     = $up_file['name'];
                    move_uploaded_file($tmp_name, $path . $name);
                }
            }
        }
    }
    
    protected  function getPdfResource()
    {
        $p       = new \PDFlib();
        // $p->set_option("license=L900602-0190B3-144087-GQGR32-LB3VA2");
        $optlist = "";
        
        if (!$p->begin_document('', $optlist)) {
            throw new \Exception('Error: ' . $p->get_errmsg());
        }
        $p->set_option("errorpolicy=return");
        $p->set_option("stringformat=utf8");
        return $p;
    }
    
    protected  function getPdfDocResources($pdfFilename)
    {
        $indoc = $this->pdf->open_pdi_document($pdfFilename, "");
        if (!$indoc) {
            throw new Exception("Error: " . $this->pdf->get_errmsg());
        }
        
        return $indoc;
    }
    
}


