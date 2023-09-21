<?php

namespace Printq\Rest\Http\Controllers;

use Illuminate\Http\Request;
use Printq\Rest\Services\ReadPdfService;

class RestReadPdfController extends BaseController
{
    protected $pdfSearchPath = '/data/pdfs/';
    protected $pdf           = null;
    protected $doc           = null;
    protected $editorConfig  = [];
    
    public function get($file)
    {
        return;
    }
    
    public function create(Request $request)
    {
        $result = ["success" => 1, "message" => "", 'data' => []];
        $data = $request->all();
        try {
            $pdfService     = new ReadPdfService($data, $_FILES);
            $result['data'] = $pdfService->handleRequest();
        } catch (\Exception $e) {
            \Log::error($e);
            $result['success'] = 0;
            $result['message'] = $e->getMessage();
        }
        \Log::info('asd', $result);
        return response()->json($result);
    }
    
    public function update($id, $data)
    {
        # code...
    }
    
    public function getList()
    {
        exit;
    }
    
    public function delete($id)
    {
        # code...
    }
    
    
    protected static function getPdfTrimboxSize($p, $indoc, $page = 1, $quality = 100, $trimBoxStatus = 0, $previewMaxWidth = 1200, $previewMaxHeight = 1200)
    {
        $pageCount = $p->pcos_get_number($indoc, "length:pages");
        $i         = 0;
        if (($page - 1) >= 0 && ($page - 1) < $pageCount) {
            $i = $page - 1;
        }
        $level            = $quality / 100;
        $correctionWidth  = 0;
        $correctionHeight = 0;
        if ($p->pcos_get_string($indoc, "type:pages[$i]/MediaBox") == 'array') {
            $correctionWidth  = abs($p->pcos_get_number($indoc, "pages[$i]/MediaBox[0]"));
            $correctionHeight = abs($p->pcos_get_number($indoc, "pages[$i]/MediaBox[1]"));
            $pdfPageWidth     = abs($p->pcos_get_number($indoc, "pages[$i]/MediaBox[2]")) + $correctionWidth;
            $pdfPageHeight    = abs($p->pcos_get_number($indoc, "pages[$i]/MediaBox[3]")) + $correctionHeight;
            
        } else {
            $pdfPageWidth  = $p->pcos_get_number($indoc, "pages[$i]/width");
            $pdfPageHeight = $p->pcos_get_number($indoc, "pages[$i]/height");
        }
        $pageWidth   = round((float)$pdfPageWidth * self::$pttopx * $level, 0, PHP_ROUND_HALF_ODD);
        $pageHeight  = round((float)$pdfPageHeight * self::$pttopx * $level, 0, PHP_ROUND_HALF_ODD);
        $newQualityW = round(($quality * $previewMaxWidth) / $pageWidth, 0, PHP_ROUND_HALF_ODD);
        $newQualityH = round(($quality * $previewMaxHeight) / $pageHeight, 0, PHP_ROUND_HALF_ODD);
        $quality     = min($newQualityW, $newQualityH);
        $level       = $quality / 100;
        
        $pageWidth  = round($pdfPageWidth * self::$pttopx * $level, 0, PHP_ROUND_HALF_ODD);
        $pageHeight = round($pdfPageHeight * self::$pttopx * $level, 0, PHP_ROUND_HALF_ODD);
        //}
        $trimboxSizes = " -x 0 -y 0 -W $pageWidth -H $pageHeight";
        $x            = 0;
        $y            = 0;
        if ($trimBoxStatus) {
            if ($p->pcos_get_string($indoc, "type:pages[$i]/TrimBox") == 'array') {
                $trimbox    = [
                    $p->pcos_get_string($indoc, "pages[$i]/TrimBox[0]"),
                    $p->pcos_get_string($indoc, "pages[$i]/TrimBox[1]"),
                    $p->pcos_get_string($indoc, "pages[$i]/TrimBox[2]"),
                    $p->pcos_get_string($indoc, "pages[$i]/TrimBox[3]"),
                ];
                $trimbox[0] += $correctionWidth;
                $trimbox[1] += $correctionHeight;
                $trimbox[2] += $correctionWidth;
                $trimbox[3] += $correctionHeight;
                $x          = round($trimbox[0] * self::$pttopx * $level, 0, PHP_ROUND_HALF_ODD);
                $y          = round((($pageHeight - ($trimbox[3] * self::$pttopx * $level))), 0, PHP_ROUND_HALF_ODD);
                $pageWidth  = round(($trimbox[2] - $trimbox[0]) * self::$pttopx * $level, 0, PHP_ROUND_HALF_ODD);
                $pageHeight = round(($trimbox[3] - $trimbox[1]) * self::$pttopx * $level, 0, PHP_ROUND_HALF_ODD);
                
            }
            $trimboxSizes = " -x $x -y $y -W $pageWidth -H $pageHeight";
        }
        
        
        return array('trimboxSizes' => $trimboxSizes, 'quality' => $quality);
        
    }
}