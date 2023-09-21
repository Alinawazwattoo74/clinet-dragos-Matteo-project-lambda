<?php

namespace Printq\Rest\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class RestPreviewBrochureController extends BaseController
{
    protected $pdfSearchPath = '/data/pdfs/';
    
    protected $pdfResultFolder = '/data/result/';
    
    protected $fontSearchPath = '/data/fonts/';
    
    protected $loadedFiles      = array();
    protected $loadedResources  = array();
    protected $current_line     = 0;
    protected $csv_block_values = array();
    protected $loadedFonts      = array();
    
    
    public function getList()
    {
        exit;
    }
    
    public function get($file)
    {
        $f = ROOT_PATH . $this->pdfResultFolder . $file;
        if (file_exists($f)) {
            return response()->json(array(
                'data' => base64_encode(file_get_contents($f)),
            ));
        }
        
        return response()->json(array(
            'data' => false,
        ));
    }
    
    public function getBigAction()
    {
        $file = $this->params()->fromRoute('file');
        $f    = ROOT_PATH . $this->pdfResultFolder . $file;
        if (file_exists($f)) {
            header("Cache-Control: public");
            header("Content-Description: File Transfer");
            header("Content-Disposition: attachment; filename=$file");
            header("Content-Type: application/pdf");
            header("Content-Transfer-Encoding: binary");
            readfile($f);
        } else {
            header($_SERVER["SERVER_PROTOCOL"] . " 404 Not Found");
        }
        die();
    }
    
    
    public function create(Request $request)
    {
        $data = $request->all();
        $this->time       = microtime(true);
        $this->globaltime = microtime(true);
        $data['svg']      = array();
        
        
        return $this->generatePreview($data);
        
        
        return response()->json(array(
            'data' => 'file doesn\'t exist',
        ));
    }
    
    protected function generatePreview($data)
    {
        ob_start();
        $path  = ROOT_PATH . '/data/pdfs/tmp/';
        $error = '';
        
        $this->uploadFiles($path);
        
        try {
            $pdfvt = false;
            $pdf   = false;
            //@todo remove hardcoded values
            /*    $data['pdfvt']         = 1;
                $data['pdfvt_count']   = 3;
                $data['pdfvt_sources'] = array();*/
            if (isset($data['pdfvt']) && $data['pdfvt']) {
                $pdfvt = true;
            }
            $this->startPdf($pdf, $pdfvt, $pdfvt);
            $this->loadFonts($data, $pdf, $pdfvt);
            
            $this->fillPdfBlocks($data, $pdf);
            //end page_length if
            $pdf->end_document("");
            
            $buf = $pdf->get_buffer();
            
            $pdf           = null;
            $image_preview = $data['selection'] . '.pdf';
            
            $hires = isset($data['hires']) ? $data['hires'] : '';
            
            if (file_put_contents(ROOT_PATH . $this->pdfResultFolder . "$data[selection].pdf", $buf)) {
                if (isset($data['split']) && $data['split']) {
                    $this->splitPdf($data);
                }
                if (isset($data['watermark']) && $data['watermark']) {
                    $this->_addWatermark(ROOT_PATH . $this->pdfResultFolder . "$data[selection].pdf", $data);
                    base64_encode(file_get_contents(ROOT_PATH . $this->pdfResultFolder . "$data[selection]_watermark.pdf"));
                    $wtm_image_preview = $data['selection'] . '_watermark' . '.pdf';
                }
                
                $toImage = new RestController();
                $res     = 150;
                if (isset($data['preview_resolution']) && $data['preview_resolution']) {
                    $res = $data['preview_resolution'];
                }
                if ($hires) {
                    $res = 300;
                    if (isset($data['preview_resolution']) && $data['preview_resolution']) {
                        $res = $data['preview_resolution'];
                    }
                }
                $previewPage = 1;
                if (isset($data['customPreviewPage']) && (int)$data['customPreviewPage'] >= 1) {
                    $previewPage = (int)$data['customPreviewPage'];
                } //this is for avery, because we can hide some pages from preview
                $gsQuality = 0;
                if (isset($data['gsQuality']) && (int)$data['gsQuality'] == 1) {
                    $gsQuality = (int)$data['gsQuality'];
                } //this is for ran-603
                
                $params = array(
                    'file'        => $image_preview,
                    'page'        => $previewPage,
                    'res'         => $res,
                    'hires'       => $hires,
                    'live'        => isset($data['preview_type']) && $data['preview_type'] == 'live',
                    'flip'        => isset($data['preview_type']) && $data['preview_type'] == 'flip',
                    'trim_box'    => $data['trim_box'],
                    'fit_to_page' => $data['fit_to_page'],
                    'file_output' => $data['file_output'],
                    'data'        => $data,
                    'gsQuality'   => $gsQuality,
                );
                if (isset($data['watermark']) && isset($data['watermark']['preview']) && $data['watermark']['preview']) {
                    $params['wtm_file'] = $wtm_image_preview;
                }
                $image = $toImage->get($params);
                if (isset($data['preview_type']) && $data['preview_type'] == 'live') {
                    $image = $this->generateLivePreview($image, $data);
                    if ($image) {
                        return $image;
                    }
                }
                ob_end_clean();
                $this->deleteFiles();
                $image = json_decode($image->content());
                return response()->json(array(
                    'data'    => $image && $image->result ? $image->result : "",
                    'success' => 1
                ));
                return $image;
            }
            
            
        } catch (PDFlibException $e) {
            \Log::error($e);
            $error = $e->getMessage();
        } catch (\Exception $e) {
            \Log::error($e);
            $error = $e->getMessage();
        }
        ob_end_clean();
        
        return response()->json(array(
            'result' => array('error' => print_r($error, true)),
        ));
    }
    
    /**
     * @param $path
     */
    protected function uploadFiles($path)
    {
        if ($_FILES) {
            foreach ($_FILES as $up_file) {
                $tmp_name = $up_file['tmp_name'];
                $name     = $up_file['name'];
                move_uploaded_file($tmp_name, $path . $name);
            }
        }
    }
    
    /**
     * @param $file
     * @param $pdf
     * @param $doc
     *
     * @throws \Exception
     */
    protected function startPdf(
        &$pdf,
        $pdfvt = false,
        $pdfx4 = false
    ) {
        
        $pdf = new \PDFlib();
        #$pdf->set_option("license=L900602-019092-140333-67ZR92-K23V92");
        if (config('rest.pdf_license_key')) {
        	$pdf->set_option("license=" . config('rest.pdf_license_key'));
		}
        
        $pdf->set_option("errorpolicy=return");
        $pdf->set_option("stringformat=utf8");
        $pdf->set_option("searchpath={" . ROOT_PATH . $this->pdfSearchPath . "}");
        $pdf->set_option("searchpath={" . ROOT_PATH . $this->fontSearchPath . "}");
        
        $optlist = '';
        if ($pdfx4) {
            $optlist .= " pdfx=PDF/X-4 ";
        }
        if ($pdfvt) {
            $optlist .= " pdfvt=PDF/VT-1 usestransparency=true nodenamelist={root recipient} recordlevel=1 ";
        }
        // $optlist .=  " masterpassword=PDFlib  permissions={noprint nohiresprint nocopy noaccessible noassemble} ";
        
        if (!$pdf->begin_document('', $optlist)) {
            throw new \Exception('Error: ' . $pdf->get_errmsg());
        }
        if ($pdfvt || $pdfx4) {
            $pdf->set_info("Creator", "CloudLab");
            $pdf->set_info("Title", "Business card");
            # Define output intent profile */
            if ($pdf->load_iccprofile("ISOcoated.icc", "usage=outputintent") == 0) {
                printf("Error: %s\n", $pdf->get_errmsg());
                echo("Please install the ICC profile package from " .
                    "www.pdflib.com to run the PDF/VT-1 starter sample.\n");
                exit(1);
            }
        }
    }
    
    /**
     * @param $data
     * @param $pdf
     *
     * @throws \Exception
     */
    protected function loadFonts(&$data, $pdf, $use_pdf_vt = false)
    {
        // check for provided fonts
        if (isset($data['fonts'])) {
            $fonts = array_unique($data['fonts']);
        } else {
            $fonts = array();
        }
        if ($use_pdf_vt) {
            $fonts[] = 'Helvetica';
            $fonts[] = 'Arial';
        }
        if (count($fonts)) {
            foreach ($fonts as $font) {
                $this->loadFont($font, $pdf, $use_pdf_vt);
            }
        }
        // end fonts
    }
    
    public function loadFont($font, $pdf, $pdfvt)
    {
        if (in_array($font, $this->loadedFonts)) {
            return false;
        }
        if (file_exists(ROOT_PATH . $this->fontSearchPath . $font . '.ttf') || file_exists(ROOT_PATH . $this->fontSearchPath . $font . '.TTF')) {
            if (file_exists(ROOT_PATH . $this->fontSearchPath . $font . '.ttf')) {
                $this->loadedFonts[] = $font;
                $pdf->set_option("FontOutline={" . $font . "=" . $font . ".ttf}");
            } elseif (file_exists(ROOT_PATH . $this->fontSearchPath . $font . '.TTF')) {
                $this->loadedFonts[] = $font;
                $pdf->set_option("FontOutline={" . $font . "=" . $font . ".TTF}");
            }
            if ($pdfvt) {
                $pdf->load_font($font, "unicode", "embedding") or die (PDF_get_error($pdf));
            }
        }
    }
    
    public function getGroupConfig($group, $data)
    {
        $document = isset($data['configs']) && isset($data['configs']['document']) ? $data['configs']['document'] : array();
        $boxes    = isset($document['boxes']) ? $document['boxes'] : array();
        $trimbox  = isset($boxes['trimbox']) ? $boxes['trimbox'] : array('top' => 0, 'right' => 0, "bottom" => 0, "left" => 0);
        
        $result = array(
            'totalWidth'  => $trimbox['left'],
            'totalHeight' => $trimbox['top'],
            'trimbox'     => $trimbox,
            'pagesConfig' => array()
        );
        $pages  = $data['pages'];
        foreach ($group as $pageId) {
            if (isset($pages[$pageId])) {
                $page                           = $pages[$pageId];
                $result['pagesConfig'][$pageId] = array('startX' => $result['totalWidth'], 'startY' => $trimbox['bottom']);
                $result['totalWidth']           += $page['width'];
                $result['totalHeight']          = $page['height'] + $trimbox['bottom'];
            }
        }
        $result['totalWidth']  += $trimbox['right'];
        $result['totalHeight'] += $trimbox['top'];
        return $result;
    }
    
    protected function getOptionsTextFlow($object)
    {
        $positions       = ["top" => "top", "bottom" => "bottom", "middle" => "center"];
        $rotateAngle     = isset($object['rotateAngle']) ? (-1) * (float)$object['rotateAngle'] : 0;
        $position        = isset($object['vAlign']) ? $positions[$object['vAlign']] : "top";
        $optionsTextflow = ' fitmethod=auto lastlinedist=descender firstlinedist=ascender  rotate='
            . $rotateAngle . " verticalalign=" . $position;
        return $optionsTextflow;
    }
    
    protected function getOptionsImage($object, $image_src, &$pdf, $type = "image", $page = 1)
    {
        $rotateAngle = isset($object['rotateAngle']) ? (-1) * (float)$object['rotateAngle'] : 0;
        $options     = 'rotate=' . $rotateAngle . " position={center} fitmethod=meet ";
        
        if (isset($object['cropW']) && $object['cropW'] && $type!="qr") {
            if (file_exists($image_src)) {
                $c_llx   = $object['cropX'];
                $c_lly   = $object['cropY'];
                $c_urx   = $object['cropW'];
                $c_ury   = $object['cropH'];
                $options .= " matchbox={clipping={ $c_llx% $c_lly% $c_urx% $c_ury% }} ";
            }
        }
        if (isset($object['flipHorizontal']) && (int)$object['flipHorizontal']) {
            $options .= " scale={-1 1} ";
        }
        if (isset($object['flipVertical']) && (int)$object['flipVertical']) {
            $options .= " scale={1 -1} ";
        }
        if (isset($object['flipBoth']) && (int)$object['flipBoth']) {
            $options .= " scale={-1 -1} ";
        }
        return $options;
    }
    
    protected function getColorsFromObject($colorObject = array())
    {
        $result = array(
            'color'      => "",
            'device'     => "None",
            'color_tint' => false
        );
        if (isset($colorObject['colorSpace'])) {
            switch (strtolower($colorObject['colorSpace'])) {
                case "none":
                    return false;
                    break;
                case "devicergb":
                    if (!$colorObject["RGB"]) {
                        return false;
                    }
                    $result['color']  = $colorObject['RGB'];
                    $result['device'] = "rgb";
                    break;
                case "devicecmyk":
                    $result['color'] = $colorObject['CMYK'];
                    if (!$colorObject["CMYK"]) {
                        return false;
                    }
                    $result['device'] = "cmyk";
                    break;
                case "Separation":
                    $result['device'] = "spotname";
                    switch ($colorObject['separationColorspace']) {
                        
                        case "RGB":
                            if (!$colorObject["RGB"]) {
                                return false;
                            }
                            $result['color']  = $colorObject['RGB'];
                            $result['device'] = "rgb";
                            break;
                        case "CMYK":
                            if (!$colorObject["CMYK"]) {
                                return false;
                            }
                            $result['color']  = $colorObject['CMYK'];
                            $result['device'] = "cmyk";
                            break;
                        default:
                            break;
                    }
                    break;
                default:
                    break;
            }
        }
        return $result;
    }
    
    public function drawRectangle(&$p, $params, $type = "fill")
    {
        $device         = isset($params['device']) ? $params['device'] : "rgb";
        $color          = isset($params['color']) ? $params['color'] : "";
        $borderWidth    = isset($params['borderWidth']) ? $params['borderWidth'] : 0;
        $generalOptions = ' fillcolor={' . $device . ' ' . $color . '}';
        if (strlen($color)) {
            
            $rotateAngle = isset($params['rotateAngle']) ? $params['rotateAngle'] : 0;
            $tetha       = deg2rad($rotateAngle);
            
            /*bottom line*/
            $ax   = $params['left'];
            $ay   = $params['top'];
            $x    = $ax + $params['width'] * cos($tetha);
            $y    = $ay + $params['width'] * sin($tetha);
            $path = $p->add_path_point(0, $ax, $ay, "move", $generalOptions);
            $path = $p->add_path_point($path, $x, $y, "line", "");
            
            /*right line*/
            
            $tetha = deg2rad($rotateAngle + 90);
            $x     = $x + $params['height'] * cos($tetha);
            $y     = $y + $params['height'] * sin($tetha);
            $path  = $p->add_path_point($path, $x, $y, "line", "");
            /*top line*/
            
            $tetha = deg2rad($rotateAngle);
            $x     = $x - $params['width'] * cos($tetha);
            $y     = $y - $params['width'] * sin($tetha);
            /*left line*/
            $path  = $p->add_path_point($path, $x, $y, "line", "");
            $tetha = deg2rad($rotateAngle + 90);
            $x     = $x - $params['height'] * cos($tetha);
            $y     = $y - $params['height'] * sin($tetha);
            $path  = $p->add_path_point($path, $x, $y, "line", "");
            $p->draw_path($path, 0, 0, "fill");
        }
    }
    
    private function drawBorderBlock($p, $params)
    {
        $generalOptions            = ' linecap=projecting linejoin=miter';
        $borderColorTemplateDevice = $params['device'];
        $borderColorTemplate       = $params['color'];
        $lineWidth                 = $params['borderWidth'];
        $rotateAngle               = isset($params['rotateAngle']) ? $params['rotateAngle'] : 0;
        $tetha                     = deg2rad($rotateAngle);
        if ($lineWidth > 0) {
            /*bottom line*/
            $ax   = $params['left'];
            $ay   = $params['top'];
            $x    = $ax + $params['width'] * cos($tetha);
            $y    = $ay + $params['width'] * sin($tetha);
            $path = $p->add_path_point(0, $ax, $ay, "move",
                'linewidth=' . $lineWidth . ' strokecolor={' . $borderColorTemplateDevice . ' ' . $borderColorTemplate . '}' . $generalOptions);
            $path = $p->add_path_point($path, $x, $y, "line", "");
            /*right line*/
            $path  = $p->add_path_point($path, $x, $y, "move",
                'linewidth=' . $lineWidth . ' strokecolor={' . $borderColorTemplateDevice . ' ' . $borderColorTemplate . '}' . $generalOptions);
            $tetha = deg2rad($rotateAngle + 90);
            $x     = $x + $params['height'] * cos($tetha);
            $y     = $y + $params['height'] * sin($tetha);
            $path  = $p->add_path_point($path, $x, $y, "line", "");
            /*top line*/
            $path  = $p->add_path_point($path, $x, $y, "move",
                'linewidth=' . $lineWidth . ' strokecolor={' . $borderColorTemplateDevice . ' ' . $borderColorTemplate . '}' . $generalOptions);
            $tetha = deg2rad($rotateAngle);
            $x     = $x - $params['width'] * cos($tetha);
            $y     = $y - $params['width'] * sin($tetha);
            /*left line*/
            $path  = $p->add_path_point($path, $x, $y, "line", "");
            $tetha = deg2rad($rotateAngle + 90);
            $x     = $x - $params['height'] * cos($tetha);
            $y     = $y - $params['height'] * sin($tetha);
            $path  = $p->add_path_point($path, $x, $y, "line", "");
        }
        /*draw the border*/
        if ($lineWidth > 0) {
            $p->draw_path($path, 0, 0, "stroke");
        }
    }
    
    protected function getOptionsTextLine($object, $pdf, $pdfvt = false, $is_circle = false)
    {
        $options     = "  encoding=unicode";
        $rotateAngle = isset($object['rotateAngle']) ? (-1) * (float)$object['rotateAngle'] : 0;
        $color       = isset($object['fillColor']) ? $object['fillColor'] : array();
        $fillColor   = $this->getColorsFromObject($color);
        $fontStyle   = $this->getTextStyle($object);
        $positions   = $this->getPositionOfText($object);
        $fontName    = isset($object['fontFamily']) ? $object['fontFamily'] : 'Arial';
        $this->loadFont($fontName, $pdf, $pdfvt);
        if ($fontName == "Helvetica") {
            $options .= ' embedding ';
        }
        $underline = isset($object['underline']) && (int)$object['underline'] ? ' underline=true' : '';
        
        $fontSize = isset($object['fontSize']) && $object['fontSize'] > 0 ? $object['fontSize'] : 0.1;
        $options  .= ' embedding fontname={' . $fontName . '}  fontsize=' . $fontSize . ' fillcolor={' . $fillColor['device'] . ' ' . $fillColor['color'] . '}' .
            ' strokecolor={' . $fillColor['device'] . ' ' . $fillColor['color'] . '}' . $underline . ' fontstyle=' . $fontStyle;
        if (!$is_circle) {
            $lineheightp = isset($object['lineheightp']) && $object['lineheightp'] ? $object['lineheightp'] : 120;
            $options     .= '  leading=' . $lineheightp . '% alignment=' . $object['textAlign'];
        }
        
        return $options;
    }
    
    public function getTextStyle($object)
    {
        $textStyle = "";
        $textStyle .= isset($object['bold']) && (int)$object['bold'] ? ' bold' : '';
        $textStyle .= isset($object['italic']) && (int)$object['italic'] ? 'italic' : '';
        if (!strlen($textStyle)) {
            return "normal";
        }
        return $textStyle;
    }
    
    public function getPositionOfText($object)
    {
        $position0 = '0';
        $position1 = '0';
        if (isset($object['textAlign'])) {
            if ($object['textAlign']) {
                $position0 = '50';
            }
            if ($object['textAlign'] == 'right') {
                $position0 = '100';
            }
        }
        if (isset($object['vAlign'])) {
            if ($object['vAlign'] == 'middle') {
                $position1 = '50';
            }
            if ($object['vAlign'] == 'top') {
                $position1 = '100';
            }
        }
        return '{' . $position0 . ' ' . $position1 . '}';
    }
    
    public function getCompleteObjectProps($object, $configs)
    {
        $type    = $object['type'];
        $subType = $object['subType'];
        if (isset($configs[$subType . "Cfg"])) {
            $object = array_replace_recursive($configs[$subType . "Cfg"], $object);
        }
        if (isset($configs[$type . "Cfg"])) {
            $object = array_replace_recursive($configs[$type . "Cfg"], $object);
        }
        if (isset($configs["generalCfg"])) {
            $object = array_replace_recursive($configs["generalCfg"], $object);
        }
        return $object;
    }
    
    public function saveSvg($svg, $old_img_src)
    {
        $paths = pathinfo($old_img_src);
        print_r($paths);
        
        $newPath = $paths['dirname'] . DIRECTORY_SEPARATOR . $paths['filename'] . "_" . str_replace(".", "_", microtime(true)) . "." . $paths['extension'];
        $result  = file_put_contents($newPath, $svg);
        if ($result) {
            return $newPath;
        }
        return $old_img_src;
    }
    
    public function getSvgSrc($object, $img_src)
    {
        $bgColor     = isset($object["bgColor"]) ? $object["bgColor"] : false;
        $borderColor = isset($object["borderColor"]) ? $object["borderColor"] : false;
        $borderWidth = isset($object["borderWidth"]) ? $object["borderWidth"] : false;
        $changed     = false;
        
        $svg = file_get_contents($img_src);
        
        if ($bgColor) {
            $bgColorSpace   = isset($bgColor["colorSpace"]) && in_array($bgColor["colorSpace"], ["DeviceRGB", "DeviceCMYK"]) ? $bgColor["colorSpace"] : false;
            $bgColorHtmlRGB = isset($bgColor["htmlRGB"]) ? $bgColor["htmlRGB"] : false;
            $bgColorValue   = false;
            switch ($bgColorSpace) {
                case "DeviceRGB":
                    $bgColorValue = isset($bgColor["RGB"]) ? $bgColor["RGB"] : false;
                    break;
                case "DeviceCMYK":
                    $bgColorValue = isset($bgColor["CMYK"]) ? $bgColor["CMYK"] : false;
                    break;
            }
            
            //replace svg bgcolor
            if ($bgColorHtmlRGB) {
                $svg     = preg_replace_callback('/(fill(=|:)"?)([^";]*)/', function ($matches) use ($bgColorHtmlRGB, $bgColorSpace, $bgColorValue) {
                    $result = "fill=\"rgb($bgColorHtmlRGB) ";
                    if ($bgColorSpace === "DeviceCMYK" && $bgColorValue) {
                        $result .= " device-cmyk($bgColorValue)";
                    }
                    return $result;
                }, $svg);
                $changed = true;
            }
        }
        
        if ($borderColor) {
            $borderColorSpace   = isset($borderColor["colorSpace"]) && in_array($borderColor["colorSpace"], ["DeviceRGB", "DeviceCMYK"]) ? $borderColor["colorSpace"] : false;
            $borderColorHtmlRGB = isset($borderColor["htmlRGB"]) ? $borderColor["htmlRGB"] : false;
            $borderColorValue   = false;
            switch ($borderColorSpace) {
                case "DeviceRGB":
                    $borderColorValue = isset($borderColor["RGB"]) ? $borderColor["RGB"] : false;
                    break;
                case "DeviceCMYK":
                    $borderColorValue = isset($borderColor["CMYK"]) ? $borderColor["CMYK"] : false;
                    break;
            }
            
            //replace svg bordercolor
            if ($borderColorHtmlRGB) {
                $svg     = preg_replace_callback('/(stroke(=|:)"?)([^";]*)/', function ($matches) use ($borderColorHtmlRGB, $borderColorSpace, $borderColorValue) {
                    $result = "stroke=\"rgb($borderColorHtmlRGB) ";
                    if ($borderColorSpace === "DeviceCMYK" && $borderColorValue) {
                        $result .= " device-cmyk($borderColorValue)";
                    }
                    return $result;
                }, $svg);
                $changed = true;
            }
        }
        
        if ($borderWidth || $borderWidth === 0) {
            //replace svg borderWidth
            $svg     = preg_replace_callback('/(stroke-width(=|:)"?)([^";]*)/', function ($matches) use ($borderWidth) {
                return "stroke-width=\"$borderWidth";;
            }, $svg);
            $changed = true;
        }
        
        if ($changed) {
            $img_src = $this->saveSvg($svg, $img_src);
        }
        return $img_src;
    }
    
    protected function placeBlockInPdf($object, &$pdf, $data, $pdfvt = false, $offset = array('startX' => 0, 'startY' => 0), $addInloaded = true)
    {
        $object         = $this->getCompleteObjectProps($object, $data["configs"]["objects"]);
        $startX         = isset($offset['startX']) ? $offset['startX'] : 0;
        $startY         = isset($offset['startY']) ? $offset['startY'] : 0;
        $is_circle_text =  0;
        $path           = ROOT_PATH . '/data/pdfs/tmp/';
        if (isset($object['type'])) {
            $object['left'] += $startX;
            $borderWidth    = isset($object['borderWidth']) ? $object['borderWidth'] : 0;
            $height         = $object['height'];
            $width          = $object['width'];
            $topbackground  = $startY + $object['top'];
            $left           = $object['left'];
            $top            = $startY + $object['top'];;
            $rotateAngle = isset($object['rotateAngle']) ? -1 * (float)$object['rotateAngle'] : 0;
            if ($rotateAngle != 0 && !$is_circle_text) {
                $tetha         = deg2rad($rotateAngle);
                $topleft       = array("x" => ($width * (-1)) / 2, "y" => $height * (-1) / 2);
                $topLeftD      = array(
                    "x" =>
                        $topleft["x"] * cos($tetha) - $topleft["y"] * sin($tetha),
                    "y" => $topleft["x"] * sin($tetha) + $topleft["y"] * cos($tetha)
                );
                $deviation     = array("x" => $topleft["x"] - $topLeftD["x"], "y" => $topLeftD["y"] - $topleft["y"]);
                $left          = $left - $deviation['x'];
                $top           = $top + $deviation['y'];
                $topbackground = $topbackground + $deviation['y'];
            }
            
            $subtype = isset($object['subType']) ? $object['subType'] : "";
            $params  = array('left' => $left, 'top' => $topbackground, 'width' => $width, 'height' => $height, 'borderWidth' => $borderWidth);
            $color   = isset($object['bgColor']) ? $object['bgColor'] : array();
            $bgColor = $this->getColorsFromObject($color);
            if (is_array($bgColor) && !in_array($object['type'], ["graphics", 'circletext'])) {
                $params['top']         = $topbackground;
                $params['device']      = $bgColor['device'];
                $params['rotateAngle'] = $rotateAngle;
                $params['color']       = $bgColor['color'];
                $this->drawRectangle($pdf, $params, "fill");
            }
            if ($borderWidth && !in_array($object['type'], ["graphics", "circletext"])) {
                $color       = isset($object['borderColor']) ? $object['borderColor'] : array();
                $borderColor = $this->getColorsFromObject($color);
                if (is_array($borderColor)) {
                    $heightBorder = $object['height'] + $borderWidth;
                    $widthBorder  = $object['width'] + $borderWidth;
                    $topBorder    = $startY + $object['top'] - $borderWidth / 2;
                    $leftBorder   = $object['left'] - $borderWidth / 2;
                    
                    if ($rotateAngle != 0) {
                        $tetha      = deg2rad($rotateAngle);
                        $topleft    = array("x" => ($widthBorder * (-1)) / 2, "y" => $heightBorder * (-1) / 2);
                        $topLeftD   = array(
                            "x" =>
                                $topleft["x"] * cos($tetha) - $topleft["y"] * sin($tetha),
                            "y" => $topleft["x"] * sin($tetha) + $topleft["y"] * cos($tetha)
                        );
                        $deviation  = array("x" => $topleft["x"] - $topLeftD["x"], "y" => $topLeftD["y"] - $topleft["y"]);
                        $leftBorder = $leftBorder - $deviation['x'];
                        $topBorder  = $topBorder + $deviation['y'];
                    }
                    $params['width']  = $widthBorder;
                    $params['height'] = $heightBorder;
                    $params['left']   = $leftBorder;
                    $params['top']    = $topBorder;
                    $params['device'] = $borderColor['device'];
                    $params['color']  = $borderColor['color'];
                    $this->drawBorderBlock($pdf, $params);
                }
                
            }
            
            $pattern = '/(.*)(%(.*[^%])%)(.*)/U';
            switch ($object['type']) {
                case 'text':
                    switch ($subtype) {
                        case 'text':
                        case 'textflow':
                        case 'textline':
                            $text = isset($object['pdflibValue']) ? ($object['pdflibValue']) : "";
                            if ($pdfvt) {
                                $text = preg_replace_callback($pattern, array(
                                    $this,
                                    'replace_callback'
                                ), $text, -1);
                            }
                            if (strlen($text)) {
                              
                                $optionsTextflow = $this->getOptionsTextFlow($object);
                                $options         = $this->getOptionsTextLine($object, $pdf, $pdfvt);
    
    
                                $text = preg_replace('/[\t]{2,}/', ' ', $text);;
                                $tf              = $pdf->create_textflow($text, $options);
                             
                                if ($tf) {
                                    $pdf->fit_textflow($tf, $left, $top, $left + $width, $top + $height, $optionsTextflow);
                                }
                                
                            }
                            
                            break;
                        /*         case 'textline':
                                     $text = isset($object['pdflibValue']) ? strip_tags($object['pdflibValue']) : "";
                                     if ($pdfvt) {
                                         $text = preg_replace_callback($pattern, array(
                                             $this,
                                             'replace_callback'
                                         ), $text, -1);
                                     }
                                     $options = $this->getOptionsTextLine($object, $pdf, $pdfvt);
                                     $options .= "boxsize={ " . $width . " " . $height .
                                         "}";
                                     $pdf->fit_textline($text, $left, $top, $options);
                                     break;*/
                        default:
                            break;
                    }
                    
                    break;
                case 'circletext':
                    $text               = isset($object['value']) ? strip_tags($object['value']) : "";
                    $radius             = $object['width'] / 2;
                    $rotate             = isset($object['rotation']) ? (-1) * (float)$object['rotation'] : 0;
                    $circletextposition = isset($object['position']) ? $object['position'] : 0;
                    if ($circletextposition == 1) {
                        $circletextposition = 0;
                    } else {
                        $circletextposition = 1;
                    }
                    
                    $position       = "";
                    $options        = $this->getOptionsTextLine($object, $pdf, false, true);
                    $generalOptions = "";
                    if ($borderWidth) {
                        $color       = isset($object['borderColor']) ? $object['borderColor'] : array();
                        $borderColor = $this->getColorsFromObject($color);
                        if (is_array($borderColor)) {
                            $generalOptions .= ' strokecolor={' . $borderColor['device'] . ' ' . $borderColor['color'] . '}';
                        }
                    }
                    $options .= " boxsize={ " . $width . " " . $height . "}";
                    if (is_array($bgColor)) {
                        $generalOptions .= ' fillcolor={' . $bgColor['device'] . ' ' . $bgColor['color'] . '}';
                    }
                    if ($circletextposition) {
                        $rotate      = (($rotate - 90)) % 360;
                        $x           = $radius + $radius * cos(deg2rad($rotate));
                        $y           = $radius + $radius * sin(deg2rad($rotate));
                        $path_circle = $pdf->add_path_point(0, $x, $y, "move", $generalOptions);
                        $path_circle = $pdf->add_path_point($path_circle, $radius + $radius * cos(deg2rad(($rotate + 90))),
                            $radius + $radius * sin(deg2rad(($rotate + 90))), "control", "");
                        $path_circle = $pdf->add_path_point($path_circle, $radius + $radius * cos(deg2rad($rotate + 180)),
                            $radius + $radius * sin(deg2rad($rotate + 180)), "circular", "");
                        $path_circle = $pdf->add_path_point($path_circle, $radius + $radius * cos(deg2rad($rotate + 270)),
                            $radius + $radius * sin(deg2rad($rotate + 270)), "control", "");
                        $path_circle = $pdf->add_path_point($path_circle, $x, $y, "circular", "");
                        $position    = "position={center bottom}";
                    } else {
                        $rotate      = (($rotate - 90)) % 360;
                        $x           = $radius + $radius * cos(deg2rad($rotate));
                        $y           = $radius + $radius * sin(deg2rad($rotate));
                        $path_circle = $pdf->add_path_point(0, $x, $y, "move", $generalOptions);
                        $path_circle = $pdf->add_path_point($path_circle, $radius + $radius * cos(deg2rad(($rotate - 90))),
                            $radius + $radius * sin(deg2rad(($rotate - 90))), "control", "");
                        $path_circle = $pdf->add_path_point($path_circle, $radius + $radius * cos(deg2rad($rotate - 180)),
                            $radius + $radius * sin(deg2rad($rotate - 180)), "circular", "");
                        $path_circle = $pdf->add_path_point($path_circle, $radius + $radius * cos(deg2rad($rotate - 270)),
                            $radius + $radius * sin(deg2rad($rotate - 270)), "control", "");
                        $path_circle = $pdf->add_path_point($path_circle, $x, $y, "circular", "");
                        $position    = "position={center top}";
                    }
                    if (is_array($bgColor)) {
                        $pdf->draw_path($path_circle, $left, $top, "fill");
                    }
                    $options .= " textpath={path=" . $path_circle . "} " . $position;
                    if ($borderWidth) {
                        $pdf->setlinewidth($borderWidth);
                        $pdf->draw_path($path_circle, $left, $top, "stroke");
                    }
                    
                    
                    //   print_r($options);exit;
                    $pdf->fit_textline($text, $left, $top, $options);
                    break;
                case'image':
                case 'graphics':
                    if (isset($object['uuid']) && $object['uuid']) {
                        $filePath   = $data['uuids'][$object['uuid']];
                        $tmp        = explode('/', urldecode($filePath));
                        $image_name = array_pop($tmp);
                        $image_src  = $path . $image_name;
                        
                        if (file_exists($image_src)) {
                            switch ($subtype) {
                                case 'image':
                                    
                                    ///add efect here
                                    /*   $image_src        = $this->setBrighnessContrast($object, $image_src);*/
                                    $image_src_effect = $this->testEffectOtp("sepia", $object, $image_src);
                                    if ($image_src_effect != $image_src) {
                                        $fileMd5   = md5("page_1_type_image" . $image_src);
                                        $image_src = $image_src_effect;
                                        if (!array_key_exists($fileMd5, $this->loadedFiles)) {
                                            $this->loadedFiles[$fileMd5] = $image_src;
                                        }
                                        $fileMd5 = md5("page_1_type_image" . $image_src);
                                        if (!array_key_exists($fileMd5, $this->loadedFiles)) {
                                            $this->loadedFiles[$fileMd5] = $image_src;
                                        }
                                    }
                                    
                                    $image_src_effect = $this->testEffectOtp("greyscale", $object, $image_src_effect);
                                    if ($image_src_effect != $image_src) {
                                        $fileMd5   = md5("page_1_type_image" . $image_src);
                                        $image_src = $image_src_effect;
                                        if (!array_key_exists($fileMd5, $this->loadedFiles)) {
                                            $this->loadedFiles[$fileMd5] = $image_src;
                                        }
                                        $fileMd5 = md5("page_1_type_image" . $image_src);
                                        if (!array_key_exists($fileMd5, $this->loadedFiles)) {
                                            $this->loadedFiles[$fileMd5] = $image_src;
                                        }
                                    }
                                    $image_src_effect = $this->testEffectOtp("invert", $object, $image_src);
                                    if ($image_src_effect != $image_src) {
                                        $fileMd5   = md5("page_1_type_image" . $image_src);
                                        $image_src = $image_src_effect;
                                        if (!array_key_exists($fileMd5, $this->loadedFiles)) {
                                            $this->loadedFiles[$fileMd5] = $image_src;
                                        }
                                        $fileMd5 = md5("page_1_type_image" . $image_src);
                                        if (!array_key_exists($fileMd5, $this->loadedFiles)) {
                                            $this->loadedFiles[$fileMd5] = $image_src;
                                        }
                                        
                                    }
                                    $img = $this->loadImage($image_src, $pdf, $pdfvt, "image", 1, $addInloaded);
                                    if ($img) {
                                        $options = $this->getOptionsImage($object, $image_src, $pdf, "image");
                                        $options .= "boxsize={ " . $width . " " . $height .
                                            "}";
                                        $pdf->fit_image($img, $left, $top, $options);
                                    }
                                    
                                    break;
                                case 'pdf':
                                    $page = isset($object['pdfPage']) ? $object['pdfPage'] : 0;
                                    $img  = $this->loadImage($image_src, $pdf, $pdfvt, "pdf", $page + 1, $addInloaded);
                                    
                                    if ($img) {
                                        $options = $this->getOptionsImage($object, $image_src, $pdf, "pdf", $page, $addInloaded);
                                        $options .= "boxsize={ " . $width . " " . $height .
                                            "}";
                                        $pdf->fit_pdi_page($img, $left, $top, $options);
                                    }
                                    break;
                                case 'qr':
                                case 'graphics':
                                case 'svg':
                                    $image_src = $this->getSvgSrc($object, $image_src);
                                    $img       = $this->loadImage($image_src, $pdf, false, $subtype, 1, $addInloaded);
                                    if ($img) {
                                        $options = $this->getOptionsImage($object, $image_src, $pdf, "qr");
                                        $options .= "boxsize={ " . $width . " " . $height .
                                            "}";
                                        
                                        $pdf->fit_graphics($img, $left, $top, $options);
                                    }
                                    break;
                                default:
                                    break;
                            }
                        }
                    }
                    break;
                default:
                    break;
            }
        }
    }
    
    protected function loadImage(
        $image_src,
        &$pdf,
        $pdfvt = false,
        $type = "image",
        $page = 1,
        $addInloaded = true
    ) {
        $img        = false;
        $optionList = "";
        $fileMd5    = md5("page_" . $page . "_type_" . $type . $image_src);
        if ($pdfvt) {
            $optionList = "pdfvt={scope=file}";
        }
        if (file_exists($image_src)) {
            if (!array_key_exists($fileMd5, $this->loadedFiles)) {
                $this->loadedFiles[$fileMd5] = $image_src;
            }
            if (array_key_exists($fileMd5, $this->loadedResources) && $addInloaded) {
                $img = $this->loadedResources[$fileMd5];
            } else {
                switch ($type) {
                    case 'image':
                        $img = $pdf->load_image('auto', $image_src, $optionList);
                        if ($addInloaded) {
                            $this->loadedResources[$fileMd5] = $img;
                        }
                        break;
                    case 'graphics':
                    case 'qr':
                    case 'svg':
                        $img = $pdf->load_graphics('auto', $image_src, $optionList);
                        if ($addInloaded) {
                            $this->loadedResources[$fileMd5] = $img;
                        }
                        break;
                    case 'pdf':
                        $attach = $pdf->open_pdi_document($image_src, '');
                        $img    = $pdf->open_pdi_page($attach, $page, $optionList);
                        if ($addInloaded) {
                            $this->loadedResources[$fileMd5] = $img;
                        }
                        break;
                    default:
                        break;
                }
                
            }
        }
        return $img;
    }
    
    /**
     * @param $datata
     * @param $page_length
     * @param $pdf
     * @param $doc
     * @param $path
     * @param $img
     */
    protected function fillPdfBlocks(
        &$data,
        &$pdf
    ) {
        try {
            $groups = isset($data['groups']) && (is_array($data['groups']) && count($data['groups'])) ? $data['groups'] : array();
            if (!count($groups)) {
                throw new \Exception("No groups defined!");
            }
            $objects = array();
            if (isset($data['objects'])) {
                $objects = $data['objects'];
            }
            $index = 0;
            
            $totalPages = count($data['pages']);
            $pageNo     = 0;
            $pdfvt      = isset($data['pdfvt']) ? $data['pdfvt'] : false;
            $count      = isset($data['pdfvt_count']) && $data['pdfvt_count'] > 0 ? $data['pdfvt_count'] : 1;
            
            
            if (isset($data['pdf_vt_values']) && count($data['pdf_vt_values'])) {
                $this->csv_block_values = $data['pdf_vt_values'];
            }
            if ($pdfvt) {
                $pdf->begin_dpart("");
            }
            for ($j = 1; $j <= $count; $j++) {
                $this->current_line = $j - 1;
                foreach ($groups as $group) {
                    $index++;
                    $group_config   = $this->getGroupConfig($group, $data);
                    $width          = $group_config['totalWidth'];
                    $height         = $group_config['totalHeight'];
                    $trimbox        = $group_config['trimbox'];
                    $trimboxOptions = " trimbox={" . $trimbox['left'] . " " . $trimbox['bottom'] . " " . ($width - $trimbox['right']) . " " . ($height - $trimbox['top'])
                        . "}";;;
                    //here we create the entire page from grouped pages
                    /*  if($index ==2){
                          print_r($group_config);exit;
                      }*/
                    if ($pdfvt) {
                        $pdf->begin_dpart("");
                    }
                    if ($pdfvt) {
                        $icc            = $pdf->load_iccprofile("sRGB", "");
                        $trimboxOptions .= " defaultrgb=" . $icc;
                    }
                    $pdf->begin_page_ext($width, $height, $trimboxOptions);
                    ///start filling the page with blocks
                    if (isset($group_config['pagesConfig'])) {
                        if (is_array($group_config['pagesConfig']) && count($group_config['pagesConfig'])) {
                            $pageGroupIndex = 0;
                            foreach ($group_config['pagesConfig'] as $pageId => $positions) {
                                $pageGroupIndex++;
                                if (isset($data['pages'])) {
                                    $pages = $data['pages'];
                                    if (isset($pages[$pageId])) {
                                        /* get current page*/
                                        $page = $pages[$pageId];
                                        if (isset($page['objectsIds'])) {
                                            $objectIds = $page['objectsIds'];
                                            foreach ($objectIds as $objectId) {
                                                if (isset($objects[$objectId])) {
                                                    $object        = $objects[$objectId];
                                                    $object['top'] = $page['height'] - ($object['top'] + $object['height']);
                                                    $opacity       = false;
                                                    if (isset($object['opacity']) && strlen(trim($object['opacity'])) > 0 && 1 > floatval($object['opacity'])
                                                    ) {
                                                        $opacity = floatval($object['opacity']);
                                                    }
                                                    $positionsBlock = $positions;
                                                    if (isset($object['isPdfBackground']) && $object['isPdfBackground']) {
                                                        $positionsBck = $positions;
                                                        $pageWidth    = $page['width'] + $trimbox['left'] + $trimbox['right'];
                                                        $pageHeight   = $page['height'] + $trimbox['top'] + $trimbox['bottom'];
                                                        
                                                        if ($pageGroupIndex % 2 == 0) {
                                                            $positionsBck['startX'] = $trimbox['left'];
                                                        }
                                                        $this->createPdfBackground($object, $data, $positionsBck, $pageWidth, $pageHeight);
                                                        $object['subType']     = "pdf";
                                                        $object['type']        = "image";
                                                        $object['cropX']       = "0";
                                                        $object['cropW']       = "100";
                                                        $object['cropH']       = "100";
                                                        $object['cropY']       = "0";
                                                        $object['bgColor']     = [];
                                                        $object['bgColor']     = [];
                                                        $object['borderColor'] = [];
                                                        $object['pdfPage']     = 0;
                                                        $object['width']       = $pageWidth;
                                                        $object['height']      = $pageHeight;
                                                        $object['left']        = -1 * $trimbox['left'];
                                                        $object['top']         = -1 * $trimbox['top'];
                                                        $prTrimboxleft         = ($trimbox['left'] / $pageWidth) * 100;
                                                        $prTrimboxright        = ($trimbox['right'] / $pageWidth) * 100;
                                                        if (count($group) > 1) {
                                                            if ($pageGroupIndex % 2 == 0) {
                                                                $object['cropX'] = $prTrimboxleft;
                                                                $object['left']  += $trimbox['left'];
                                                                $object['width'] -= $trimbox['left'];
                                                            }
                                                            if ($pageGroupIndex % 2 == 1) {
                                                                $object['cropW'] = 100 - $prTrimboxright;
                                                                $object['width'] -= $trimbox['right'];
                                                                
                                                            }
                                                        }
                                                        
                                                    }
                                                    if (isset($object['additional']) && $object['additional']) {
                                                        $positionsBck = $positions;
                                                        $pageWidth    = $page['width'] + $trimbox['left'] + $trimbox['right'];
                                                        $pageHeight   = $page['height'] + $trimbox['top'] + $trimbox['bottom'];
                                                        
                                                        if ($pageGroupIndex % 2 == 0) {
                                                            $positionsBck['startX'] = $trimbox['left'];
                                                        }
                                                        $this->createPdfBackground($object, $data, $positionsBck, $pageWidth, $pageHeight);
                                                        $object['subType']     = "pdf";
                                                        $object['type']        = "image";
                                                        $object['cropX']       = "0";
                                                        $object['cropW']       = "100";
                                                        $object['cropH']       = "100";
                                                        $object['cropY']       = "0";
                                                        $object['bgColor']     = [];
                                                        $object['bgColor']     = [];
                                                        $object['borderColor'] = [];
                                                        $object['pdfPage']     = 0;
                                                        $object['width']       = $pageWidth;
                                                        $object['height']      = $pageHeight;
                                                        $object['left']        = -1 * $trimbox['left'];
                                                        $object['top']         = -1 * $trimbox['top'];
                                                        $prTrimboxleft         = ($trimbox['left'] / $pageWidth) * 100;
                                                        $prTrimboxright        = ($trimbox['right'] / $pageWidth) * 100;
                                                        if (count($group) > 1) {
                                                            if ($pageGroupIndex % 2 == 0) {
                                                                $object['cropX'] = $prTrimboxleft;
                                                                $object['left']  += $trimbox['left'];
                                                                $object['width'] -= $trimbox['left'];
                                                            }
                                                            if ($pageGroupIndex % 2 == 1) {
                                                                $object['cropW'] = 100 - $prTrimboxright;
                                                                $object['width'] -= $trimbox['right'];
                                                                
                                                            }
                                                        }
                                                        
                                                    }
                                                    
                                                    if ($opacity !== false) {
                                                        $pdf->save();
                                                        $gstate = $pdf->create_gstate("opacityfill=" . $opacity);
                                                        $pdf->set_gstate($gstate);
                                                    }
                                                    
                                                    $this->placeBlockInPdf($object, $pdf, $data, $pdfvt, $positions);
                                                    if ($opacity !== false) {
                                                        $pdf->restore();
                                                    }
                                                }
                                            }
                                        }
                                        if (isset($data['configs'])) {
                                            $keysHeaderFooter = array('header', "footer");
                                            $config           = $data['configs']['document'];
                                            foreach ($keysHeaderFooter as $keyHeaderFooter) {
                                                if (isset($config[$keyHeaderFooter])) {
                                                    $headerFooterConfig = $config[$keyHeaderFooter];
                                                    if (isset($headerFooterConfig['enabled']) && $headerFooterConfig['enabled']) {
                                                        if ($headerFooterConfig['activeOn'] == "inner" && ($pageNo == 0 || $pageNo == $totalPages - 1)) {
                                                            continue;
                                                        }
                                                        if (isset($headerFooterConfig['objectsIds'])) {
                                                            $headerFooterConfigObjIds = $headerFooterConfig['objectsIds'];
                                                            if (is_array($headerFooterConfigObjIds) && count($headerFooterConfigObjIds)) {
                                                                foreach ($headerFooterConfigObjIds as $hfId) {
                                                                    if (isset($objects[$hfId])) {
                                                                        $object = $objects[$hfId];
                                                                        if (isset($object['top'])) {
                                                                            if ($keyHeaderFooter == "footer") {
                                                                                $object['top'] = $page['height'] - $headerFooterConfig['height'] + $object['top'];
                                                                            }
                                                                            if (intval($headerFooterConfig['mirrored']) && $pageGroupIndex % 2 == 0) {
                                                                                // print_r($object['left']);
                                                                                //   print_r("------------- ");
                                                                                $object['left'] = $page['width'] - ($object['width'] + $object['left']);
                                                                                // print_r($object);exit;
                                                                            }
                                                                            $object['top'] = $page['height'] - ($object['top'] + $object['height']);
                                                                            $opacity       = false;
                                                                            if (isset($object['opacity']) && strlen(trim($object['opacity'])) > 0
                                                                                && 1 > floatval($object['opacity'])
                                                                            ) {
                                                                                $opacity = floatval($object['opacity']);
                                                                            }
                                                                            if ($opacity !== false) {
                                                                                $pdf->save();
                                                                                $gstate = $pdf->create_gstate("opacityfill=" . $opacity);
                                                                                $pdf->set_gstate($gstate);
                                                                            }
                                                                            $this->placeBlockInPdf($object, $pdf, $data, $pdfvt, $positions);
                                                                            
                                                                            if ($opacity !== false) {
                                                                                $pdf->restore();
                                                                            }
                                                                        }
                                                                    }
                                                                }
                                                            }
                                                        }
                                                    }
                                                }
                                            }
                                        }
                                    }
                                }
                                $pageNo++;
                                
                            }
                        }
                    }
                    
                    $pdf->end_page_ext("");
                    if ($pdfvt) {
                        $pdf->end_dpart("");
                    }
                    
                }
                
            }
            if ($pdfvt) {
                $pdf->end_dpart("");
            }
        } catch (\PDFlibException $e) {
            print_r($e->getMessage());
            exit;
        } catch (\Exception $e) {
            print_r($e->getMessage());
            exit;
        }
        
        return false;
    }
    
    protected function generateLivePreview(
        $image,
        $data
    ) {
        $img = $image->getVariables();
        if (isset($img['result']['image']) && !empty($img['result']['image'])) {
            
            $written = file_put_contents(dirname(__FILE__) . '/temp.png', base64_decode($img['result']['image']));
            if ($written) {
                
                // $client = new Client('http://192.162.84.131/preview/public/preview');
                // $client->setFileUpload(dirname(__FILE__) . '/temp.png', 'toPsd');
                // $client->setParameterPost(array(
                //     'file' => $data['psd_preview'],
                // ));
                // $client->setMethod(Request::METHOD_POST);
                
                // $response = $client->send();
                $response = Http::attach(
                    'attachment', file_get_contents(ROOT_PATH . '/data/tmp/temp.png'), $data['psd_preview']
                )->post('http://192.162.84.131/preview/public/preview');
                unlink(dirname(__FILE__) . '/temp.png');
                if ($response->successful()) {
                    
                    $res = json_decode( $response->body() );
                    if (isset($res->result->success) && $res->result->success) {
                        
                        return response()->json(array(
                            'result' => array(
                                'image' => $res->result->file,
                                'file'  => $img['result']['pdf']
                            ),
                        ));
                    }
                }
            }
        }
    }
    
    private function setBrighnessContrast(
        $block_property,
        $image_src
    ) {
        $b = 1;
        $c = 1;
        if (isset($block_property['brightness']) && $block_property['brightness']) {
            $b += $block_property['brightness'];
        }
        if (isset($block_property['contrast']) && $block_property['contrast']) {
            $c += $block_property['contrast'];
        }
        if ($c != 1 || $b != 1) {
            // Calculate level values
            $z1           = ($c - 1) / (2 * $b * $c);
            $z2           = ($c + 1) / (2 * $b * $c);
            $bc_image_src = $image_src . "_b_c";
            exec('convert ' . $image_src . ' -level ' . ($z1 * 100) . '%,' . ($z2 * 100) . '% ' . $bc_image_src);
            return $bc_image_src;
        }
        return $image_src;
    }
    
    private function testEffectOtp(
        $effect,
        $block_property,
        $image_src
    ) {
        if (isset($block_property[$effect]) && (int)$block_property[$effect]) {
            $block_image_options = array(
                'original_image_src' => $image_src,
                'effect'             => $effect
            );
            $effect_image_src    = $this->effectImageOtp($block_image_options);
            if ($effect_image_src) {
                $image_src = $effect_image_src;
            }
        }
        return $image_src;
    }
    
    private function effectImageOtp(
        $options
    ) {
        $effect = '';
        if (isset($options['effect'])
            && in_array($options['effect'], array(
                'sepia',
                'greyscale',
                'invert',
            ))
        ) {
            $effect = $options['effect'];
        } else {
            return false;
        }
        $source          = $options['original_image_src'];
        $effect_filename = $options['original_image_src'] . "_" . $effect;
        switch ($effect) {
            case 'greyscale' :
                exec(escapeshellcmd('convert ' . $source . ' -colorspace Gray ' . $effect_filename));
                break;
            case 'sepia':
                exec(escapeshellcmd('convert ' . $source . ' -color-matrix   "0.393 0.769 0.189 0.349 0.686 0.168 0.272 0.534 0.131" ' . $effect_filename));
                break;
            case 'invert':
                exec(escapeshellcmd('convert ' . $source . ' -negate ' . $effect_filename));
                break;
            default:
                break;
        }
        return $effect_filename;
        
        return false;
    }
    
    public function update(
        $id,
        $data
    ) {
        # code...
    }
    
    public function delete(
        $id
    ) {
        # code...
    }
    
    public function replace_callback(
        $matches
    ) {
        $middle = isset($this->csv_block_values[$matches[3]][$this->current_line]) ? $this->csv_block_values[$matches[3]][$this->current_line] : '%' . $matches[3] . '%';
        return $matches[1] . $middle . $matches[4];
    }
    
    public function splitPdf(
        $data
    ) {
        $groups      = isset($data['groups']) && (is_array($data['groups']) && count($data['groups'])) ? $data['groups'] : array();
        $inputPdf    = ROOT_PATH . $this->pdfResultFolder . $data['selection'] . ".pdf";
        $destination = ROOT_PATH . $this->pdfResultFolder . "split_" . $data['selection'] . ".pdf";
        
        if (file_exists($inputPdf)) {
            if (is_array($groups) && count($groups)) {
                $pdf         = false;
                $pdfvt       = isset($data['pdfvt']) ? $data['pdfvt'] : false;
                $pdfvt_count = isset($data['pdfvt_count']) && $data['pdfvt_count'] > 0 ? $data['pdfvt_count'] : 1;
                $this->startPdf($pdf, $pdfvt, $pdfvt);
                $attach = $pdf->open_pdi_document($inputPdf, '');
                if ($pdfvt) {
                    $pdf->begin_dpart("");
                }
                for ($j = 1; $j <= $pdfvt_count; $j++) {
                    $currentPage = 1;
                    $groupCount  = count($groups);
                    foreach ($groups as $group) {
                        $group_config = $this->getGroupConfig($group, $data);
                        $trimbox      = $group_config['trimbox'];
                        if (isset($group_config['pagesConfig'])) {
                            $indexPage   = 1;
                            $pageToSplit = $pdf->open_pdi_page($attach, $currentPage + (($j - 1) * $groupCount), '');
                            $llx         = 0;
                            $lly         = 0;
                            foreach ($group_config['pagesConfig'] as $pageId => $pageConfig) {
                                if (isset($data['pages'])) {
                                    $pages = $data['pages'];
                                    if (isset($pages[$pageId])) {
                                        $page          = $pages[$pageId];
                                        $width         = $page['width'] + $trimbox['left'] + $trimbox['right'];
                                        $height        = $page['height'] + $trimbox['top'] + $trimbox['bottom'];
                                        $leftTrimbox   = $trimbox['left'];
                                        $bottomTrimbox = $trimbox['bottom'];
                                        $topTrimbox    = $height - $trimbox['top'];
                                        $rightTrimbox  = $width - $trimbox['right'];
                                        $urx           = $llx + $width;
                                        $ury           = $height;
                                        $clipping      = "matchbox={clipping={ $llx $lly $urx $ury }}";
                                        
                                        $trimboxOptions = " trimbox={" . $leftTrimbox . " " . $bottomTrimbox . " " . $rightTrimbox . " " . $topTrimbox . "}";
                                        //print_r($clipping);exit;
                                        $indexPage++;
                                        if ($pdfvt) {
                                            $pdf->begin_dpart("");
                                        }
                                        $pdf->begin_page_ext($width, $height, $trimboxOptions);
                                        $options = "boxsize={" . $width . " " . $height . "} fitmethod=nofit " . $clipping;
                                        $pdf->fit_pdi_page($pageToSplit, 0, 0, $options);
                                        $pdf->end_page_ext("");
                                        if ($pdfvt) {
                                            $pdf->end_dpart("");
                                        }
                                        $llx = $urx - $trimbox['right'] - $trimbox['left'];
                                    }
                                }
                            }
                            $pdf->close_pdi_page($pageToSplit);
                            $currentPage++;
                        }
                    }
                    //end page_length if
                }
                if ($pdfvt) {
                    $pdf->end_dpart("");
                }
                $pdf->end_document("");
                
                $buf = $pdf->get_buffer();
                file_put_contents($destination, $buf);
            }
        }
    }
    
    private function _addWatermark(
        $file,
        $data
    ) {
        
        $pdf = null;
        $doc = null;
        $this->startPdfWatermark($pdf, $doc, $file);
        $this->loadFonts($data, $pdf, false, false);
        $pdf->set_option("FontOutline={Helvetica=Helvetica.ttf}");
        $wtm_fid = $pdf->load_font('Helvetica', "unicode", "embedding");
        $count   = $pdf->pcos_get_string($doc, "length:pages");
        
        $wtm_text = 'Watermark';
        if (isset($data['watermark']['text']) && strlen($data['watermark']['text'])) {
            $wtm_text = $data['watermark']['text'];
        }
        $wtm_color = '{rgb 0 0 0}';
        
        
        if (isset($data['watermark']['color']) && strlen($data['watermark']['color'])) {
            
            $additional = explode(" ", rtrim(trim($data['watermark']['color'])));
            if (is_array($additional) && count($additional) == 4) {
                
                $wtm_color = '{cmyk ' . $data['watermark']['color'] . '}';
            } else {
                $wtm_color = '{rgb ' . $data['watermark']['color'] . '}';
            }
        }
        
        $wtm_opacity = 9;
        if (isset($data['watermark']['opacity']) && strlen($data['watermark']['opacity']) && (float)$data['watermark']['opacity'] < 10
            && (float)$data['watermark']['opacity'] >= 1
        ) {
            $wtm_opacity = $data['watermark']['opacity'];
        }
        $wtm_limit = 0.8;
        $wtm_fsize = 150;
        
        if (isset($data['watermark']['size']) && strlen($data['watermark']['size'])) {
            $wtm_fsize = $data['watermark']['size'];
        }
        for ($i = 0; $i < $count; $i++) {
            $page   = $pdf->open_pdi_page($doc, $i + 1, "cloneboxes");
            $width  = $pdf->pcos_get_number($doc, "pages[$i]/width");
            $height = $pdf->pcos_get_number($doc, "pages[$i]/height");
            $pdf->begin_page_ext($width, $height, '');
            $pdf->fit_pdi_page($page, 0.0, 0.0, "cloneboxes");
            $diagonal     = ceil(sqrt(pow($width, 2) + pow($height, 2)));
            $limit        = $diagonal * $wtm_limit;
            $rotate       = $this->pdf_calculateAngle($height, $width, $diagonal);
            $string_width = $this->getNewFontSize($pdf, $wtm_text, $wtm_fid, $wtm_fsize);
            if ($string_width >= $limit) {
                $wtm_fontsize = number_format(($limit * $wtm_fsize / $string_width), 2, '.', '');
            } else {
                $wtm_fontsize = $wtm_fsize;
            }
            $gstate = $pdf->create_gstate("opacityfill=.{$wtm_opacity}");
            $pdf->set_gstate($gstate);
            $tf = $pdf->create_textflow($wtm_text, "fontname=Helvetica fontsize=" . $wtm_fontsize . " encoding=unicode fillcolor=" . $wtm_color);
            $pdf->fit_textflow($tf, $wtm_fontsize / 1.5, 0, $diagonal, $wtm_fontsize, "rotate=" . $rotate . " verticalalign=center");
            $pdf->end_page_ext("");
        }
        $this->endPdfWatermark($pdf, $doc);
        $buf        = $pdf->get_buffer();
        $pdf_result = ROOT_PATH . $this->pdfResultFolder . $data['selection'] . '_watermark.pdf';
        file_put_contents($pdf_result, $buf);
        
    }
    
    private function startPdfWatermark(
        &$pdf,
        &$doc,
        $file
    ) {
        $pdf = new \PDFlib();
        #$pdf->set_option("license=L900602-019080-140333-5VQF92-9XSM82");
        if (config('rest.pdf_license_key')) {
        	$pdf->set_option("license=" . config('rest.pdf_license_key'));
		}
        $pdf->set_option("errorpolicy=return");
        $pdf->set_option("stringformat=utf8");
        $pdf->set_option("searchpath={" . ROOT_PATH . $this->pdfSearchPath . "}");
        $pdf->set_option("searchpath={" . ROOT_PATH . $this->fontSearchPath . "}");
        $optlist = "masterpassword=" . config('rest.watermark_master_password') . " permissions={noprint nomodify nocopy}";
        
        if (!$pdf->begin_document('', $optlist)) {
            throw new \Exception('Error: ' . $pdf->get_errmsg());
        }
        $pdf->set_info("Creator", "CloudLab");
        $pdf->set_info("Title", "Cloudlab ");
        $doc = $pdf->open_pdi_document($file, "");
        if (!$doc) {
            throw new \Exception('Error: ' . $pdf->get_errmsg());
        }
    }
    
    private function pdf_calculateAngle(
        $c,
        $a,
        $b
    ) {
        $angleInRadians = acos((pow($a, 2) + pow($b, 2) - pow($c, 2)) / (2 * $a * $b));
        
        return rad2deg($angleInRadians);
    }
    
    private function getNewFontSize(
        $pdf,
        $text,
        $fid,
        $fsize
    ) {
        $_string_width = $pdf->stringwidth("$text", $fid, $fsize);
        
        return $_string_width;
    }
    
    private function endPdfWatermark(
        &$pdf,
        &$doc
    ) {
        $pdf->end_document("");
        $pdf->close_pdi_document($doc);
    }
    
    public function createPdfBackground(&$object, &$data, $positions, $width, $height)
    {
        $pdf  = false;
        $time = md5(microtime(true) . rand(0, 100)) . rand(0, 100);
        $pdfvt      = isset($data['pdfvt']) ? $data['pdfvt'] : false;
        
        if (isset($object['uuid']) && $object['uuid']) {
            $time = md5($object['uuid']);
        }
        $color       = isset($object['bgColor']) ? $object['bgColor'] : array();
        $borderColor = isset($object["borderColor"]) ? $object["borderColor"] : array();
        $borderWidth = isset($object["borderWidth"]) ? $object["borderWidth"] : 0;
        $bgColor     = $this->getColorsFromObject($color);
        $borderColor = $this->getColorsFromObject($borderColor);
        if (isset($bgColor['color'])) {
            $time .= md5($bgColor['color']);
        }
        if (isset($borderColor['color'])) {
            $time .= md5($borderColor['color']);
        }
        $time .= $borderWidth;
        $path = ROOT_PATH . '/data/pdfs/tmp/' . $time . ".pdf";
        if (!file_exists($path)) {
            $this->startPdf($pdf, false, $pdfvt);
			$icc = $pdf->load_iccprofile("sRGB", "");
            $pdf->begin_page_ext($width, $height, "defaultrgb=" . $icc);
            $this->placeBlockInPdf($object, $pdf, $data, false, $positions, false);
            $pdf->end_page_ext("");
            $pdf->end_document("");
            $buf = $pdf->get_buffer();
            $put = file_put_contents($path, $buf);
            if ($put) {
                $object['uuid']                 = $time;
                $data['uuids'][$object['uuid']] = $path;
            }
        } else {
            $object['uuid']                 = $time;
            $data['uuids'][$object['uuid']] = $path;
        }
    }
    
    public function deleteFiles()
    {
        if (count($this->loadedFiles)) {
            foreach ($this->loadedFiles as $file) {
                if (is_file($file)) {
                    @unlink($file);
                }
            }
        }
    }
}