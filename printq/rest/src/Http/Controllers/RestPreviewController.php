<?php

namespace Printq\Rest\Http\Controllers;

use Illuminate\Http\Request;

class RestPreviewController extends BaseController
{
    protected $pdfSearchPath = '/data/pdfs/';

    protected $pdfResultFolder = '/data/result/';

    protected $helperPdfFolder = '/data/helperpdfs/';

    protected $whiteUnderprintPdfFolder = '/data/whiteunderprintpdfs/';

    protected $helperPdfResultFolder = '/data/helperpdfs/result/';

    protected $watermarkPath = '/data/watermark_resources/';

    protected $pdfToMergeFolder = '/data/pdfs_to_merge/';

    protected $fontSearchPath = '/data/fonts/';

    protected $time = 0;

    protected $globaltime = 0;

    protected $csv_block_values = array();
	
	protected $filesToDelete = array();

    protected $loaded_fonts_desinger = array();

    protected $current_line  = 0;
    protected $loaded_images = array();

    protected $MM_TO_PT = 2.834645669;


    protected $customPdfVersion = false;

    protected $packingDiecutColors = [
        'default'    => [
            'DieCutRed'     => [0, 1, 1, 0],
            'DieCutBlue'    => [1, 1, 0, 0],
            'DieCutGreen'   => [0, 0, 1, 0.5],
            'DieCutNoPrint' => [0, 0, 0, 0.28]
        ],
        'pacific'    => [
            'Dieline_Score'     => [0, 1, 1, 0],
            'Dieline_Cut'    => [1, 1, 0, 0],
            'Dieline_Bleed'   => [0, 0, 1, 0.5],
            'Dieline_NoPrint' => [0, 0, 0, 0.28]
        ],
        'schwarzach' => [
            'DieCutRed'     => [0, 0.85, 0.85, 0],
            'DieCutBlue'    => [0, 0, 0, 0.7],
            'DieCutGreen'   => [0.2, 0, 0.4, 0],
            'DieCutNoPrint' => [0.05, 0.05, 0.05, 0.3]
        ],
        'colordruck' => [
            'Creasing'      => [0, 1, 1, 0],
            'Cutting'       => [1, 1, 0, 0],
            'Bleed'         => [0, 0, 1, 0.5],
            'DieCutNoPrint' => [0, 0, 0, 0.28]
        ],
        'flyeralarm_staging' => [
            'DieCutRed'     => [ 0.144, 0.978, 0.325, 0.575 ],
            'DieCutBlue'    => [ 0.482, 0.394, 0.39, 0.344 ],
            'DieCutGreen'   => [ 0.139, 0.136, 0.177, 0.014 ],
            'DieCutNoPrint' => [ 0, 0, 0, 0 ],
            'DieCutSafe'    => [ 0.991, 0, 0.194, 0 ]
        ],
        'flyeralarm' => [
            'DieCutRed'     => [0.144, 0.978, 0.325, 0.575],
            'DieCutBlue'    => [0.482, 0.394, 0.39, 0.344],
            'DieCutGreen'   => [0.139, 0.136, 0.177, 0.014],
            'DieCutNoPrint' => [0, 0, 0, 0],
            'DieCutSafe'    => [0.991, 0, 0.194, 0]
        ],
		'easypaper' => [
			'Crease'     	=> [0, 0.99, 1, 0],
			'Cut'    		=> [1, 0, 0, 0],
			'Outside Bleed' => [0.63, 0, 1, 0],
			'Inside Bleed'  => [0.63, 0, 1, 0],
			'DieCutNoPrint' => [0, 0, 0, 0]
		],
        'wirmachendruck' => [
            'rillen'     => [1, 0, 0, 0],
            'Cutkontur'  => [0, 1, 0, 0],
            'unbedruckt' => [0.01, 0.99, 0.97, 0],
            'Stanze'     => [1, 0, 0, 0],
            'Background' => [0.17, 0.12, 0.13, 0]
        ]
    ];

    protected $packingOverprint = [
        'default'        => 0,
        'wirmachendruck' => 1,
        'easypaper' => 1
    ];

    public function get($name = null)
    {
        $f = storage_path() . $this->pdfResultFolder . $name;
        if (file_exists($f)) {
            return response()->json([
                'data' => base64_encode(file_get_contents($f))
            ]);
        }

        return response()->json([
            'data' => false
        ]);
    }

    public function generateWithoutOutline($selection)
    {
        $file    = ROOT_PATH . $this->pdfResultFolder . $selection . ".pdf";
        $ps_file = ROOT_PATH . $this->pdfResultFolder . $selection . ".ps";

        if (file_exists($file)) {
            $opts     = array(
                '/usr/bin/gs',
                '-o',
                $ps_file,
                '-dNOCACHE',
                '-sDEVICE=ps2write',
                $file
            );
            $exec_res = exec(escapeshellcmd((string)implode(' ', $opts)), $ret);
            if (file_exists($ps_file)) {
                $opts = array(
                    '/usr/bin/gs',
                    '-o',
                    $file,
                    ' -sDEVICE=pdfwrite',
                    $ps_file
                );
                @unlink($file);
                $exec_res = exec(escapeshellcmd((string)implode(' ', $opts)), $ret);
                @unlink($ps_file);
            }
        }
    }

    public function getBigAction($id)
    {
        // $file = $this->params()->fromRoute('file');
        $file = $id;
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

    public function createGizehPdfAction()
    {
        $pdfData = $this->params()->fromPost('preview_pdf_template', null);
        if (!$pdfData) {
            throw new \Exception('PDF template not provided');
        }

        $printPdfFile = $this->params()->fromPost('print_pdf_file', null);
        if (!$printPdfFile) {
            throw new \Exception('Print PDF selection not provided');
        }

        $screenshots = $this->params()->fromPost('td_screenshots', []);
        if (!$screenshots) {
            throw new \Exception('3D screenshots not provided');
        }

        $mainImage = $this->params()->fromPost('main_image', null);
        if (!$mainImage) {
            throw new \Exception('Main Image not provided');
        }

        $path       = ROOT_PATH . '/data/pdfs/tmp/gizeh_' . time() . '/';
        $tmpPdfName = 'output.pdf';
        if (file_exists($path) && is_dir($path)) {
            if (!rmdir($path)) {
                throw new \Exception('cannot delete existing tmp folder');
            }
        }

        if (!mkdir($path)) {
            throw new \Exception('cannot create tmp folder');
        }

        if (!file_put_contents($path . $tmpPdfName, base64_decode($pdfData))) {
            throw new \Exception('Could not store pdf template');
        }
        $screenshotFiles = [];
        foreach ($screenshots as $i => $ss) {
            $filename = $path . 'ss_' . $i . '.' . $ss['ext'];
            if (!file_put_contents($filename, base64_decode($ss['image_data']))) {
                throw new \Exception('Could not store screenshot');
            }
            $screenshotFiles['Image_' . ($i + 1)] = $filename;
        }

        $mainImageFilePath = $path . 'main_image';
        if (!file_put_contents($mainImageFilePath, base64_decode($mainImage['image_data']))) {
            throw new \Exception('Could not store main image');
        }

        $pdf = new \PDFlib();
        if (config('rest.pdf_license_key')) {
        	$pdf->set_option("license=" . config('rest.pdf_license_key'));
		}
        $pdf->set_option("errorpolicy=return");
        $pdf->set_option("stringformat=utf8");
        $pdf->set_option("searchpath={" . ROOT_PATH . $this->pdfSearchPath . "}");


        if ($pdf->begin_document('', '') == 0) {
            throw new \Exception("Error: " . $pdf->get_errmsg());
        }
        $doc = $pdf->open_pdi_document($path . $tmpPdfName, "");
        if (!$doc) {
            throw new \Exception('Error: ' . $pdf->get_errmsg());
        }

        $pdf->set_info("Creator", "CloudLab");
        $pdf->set_info("Title", "PDF Preview");

        $page   = $pdf->open_pdi_page($doc, 1, "cloneboxes");
        $width  = $pdf->pcos_get_number($doc, "pages[0]/width");
        $height = $pdf->pcos_get_number($doc, "pages[0]/height");
        $pdf->begin_page_ext($width, $height, "");
        $pdf->fit_pdi_page($page, 0.0, 0.0, "cloneboxes");

        $blocks = $pdf->pcos_get_number($doc, "length:pages[0]/blocks");
        if ($blocks) {
            for ($j = 0; $j < $blocks; $j++) {
                $name = $pdf->pcos_get_string($doc, "pages[0]/blocks[$j]/Name");
                if (in_array($name, array_keys($screenshotFiles))) {
                    if (file_exists($screenshotFiles[$name])) {
                        $image_src = $screenshotFiles[$name];
                        $img       = $pdf->load_image('auto', $image_src, '');
                        $options   = ' position={50 50}';
                        $pdf->fill_imageblock($page, $name, $img, $options);
                    }
                } else if ($name === 'MainImage') {
                    if (file_exists($mainImageFilePath)) {
                        $image_src = $mainImageFilePath;
                        $img       = $pdf->load_image('auto', $image_src, '');
                        $options   = ' position={50 50}';
                        $pdf->fill_imageblock($page, $name, $img, $options);
                    }
                }
            }

        }

        $pdf->end_page_ext("");

        $indoc = $pdf->open_pdi_document(ROOT_PATH . $this->pdfResultFolder . $printPdfFile . '.pdf', "");
        if (!$indoc) {
            throw new \Exception("Error: " . $pdf->get_errmsg());
        }

        $pagecount = $pdf->pcos_get_number($indoc, "length:pages");
        $i         = 0;
        for ($i; $i < $pagecount; $i += 1) {
            $width  = $pdf->pcos_get_number($indoc, "pages[0]/width");
            $height = $pdf->pcos_get_number($indoc, "pages[0]/height");
            $pdf->begin_page_ext($width, $height, "");

            $page_p = $pdf->open_pdi_page($indoc, $i + 1, "");
            if ($page_p == 0) {
                throw new \Exception("Error: %s\n", $page_p->get_errmsg());
            }
            $pdf->fit_pdi_page($page_p, 0, 0, "boxsize={" . $width . " " . $height . "} fitmethod=entire");

            $pdf->end_page_ext("");
        }

        $pdf->end_document("");
        $pdf->close_pdi_document($doc);
        $buf = $pdf->get_buffer();

        file_put_contents($path . 'test.pdf', $buf);

        foreach ($screenshotFiles as $ssFile){
            unlink($ssFile);
        }
        unlink($mainImageFilePath);
        unlink($path . $tmpPdfName);
        unlink($path . 'test.pdf');
        rmdir($path);

        $this->response->getHeaders()->addHeaderLine('Content-Type', 'application/json');
        $this->response->setContent(json_encode([
            'data'    => base64_encode($buf),
            'success' => 1
        ]));
        return $this->response;
    }

    public function getAsJpgAction()
    {
        $file        = $this->params()->fromRoute('file');
        $resolution  = $this->params()->fromRoute('res');
        $currentPage = $this->params()->fromRoute('page');

        $f = ROOT_PATH . $this->pdfResultFolder . $file;
        if (file_exists($f)) {
            try {
                $p       = new \PDFlib();
                $optlist = "";

                if ( ! $p->begin_document('', $optlist)) {
                    throw new \Exception('Error: ' . $p->get_errmsg());
                }

                $indoc = $p->open_pdi_document($f, "");
                if ( ! $indoc) {
                    throw new Exception("Error: " . $p->get_errmsg());
                }

                $new_image = '';
                $pagecount = $p->pcos_get_number($indoc, "length:pages");
                for ($i = 0; $i < $pagecount; $i++) {
                    if ($i == $currentPage) {
                        $params = array(
                            'flip'               => 0,
                            'page'               => $i + 1,
                            'device'             => 'jpeg',
                            'quality'            => $resolution,
                            'jpegQ'              => 85,
                            'trimBox'            => 0,
                            'fitToPage'          => 0,
                            'rotationAngle'      => '',
                            'rotationAnglePages' => '',
                            'resolutionWidth'    => '',
                            'resolutionHeight'   => '',
                            'engine_type'        => 'gs',
                            'input_unit'         => 'px',
                            'additional_params'  => []
                        );

                        $image     = str_replace('.pdf', '', strtolower($file));
                        $files     = RestController::outputAsJpgResult($f, $image . '_' . $i, $params);
                        $new_image = $files;
                    }
                }

                if (file_exists($new_image)) {
                    $newFile = base64_encode(file_get_contents($new_image));

                    @unlink($new_image);

                    return response()->json(array(
                        'data' => $newFile,
                    ));
                }

            } catch (PDFlibException $e) {
                die("PDFlib exception occurred:\n" .
                    "[" . $e->get_errnum() . "] " . $e->get_apiname() .
                    ": " . $e->get_errmsg() . "\n");
            } catch (Exception $e) {
                die($e->getMessage());
            }
        } else {
            header($_SERVER["SERVER_PROTOCOL"] . " 404 Not Found");
        }
        die();
    }

    public function getHelperPdfAction()
    {
        $file = $this->params()->fromRoute('file');
        $f    = ROOT_PATH . $this->helperPdfResultFolder . $file;
        if (file_exists($f)) {
            header("Cache-Control: public");
            header("Content-Description: File Transfer");
            header("Content-Disposition: attachment; filename=$file");
            header("Content-Type: application/pdf");
            header("Content-Transfer-Encoding: binary");
            readfile($f);
            @unlink($f);
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
        $file             = isset($data['file']) ? $data['file'] : "";
        $data['svg']      = array();
        $html5Editor      = isset($data['html5Editor']) ? $data['html5Editor'] : $data['html5Editor'];
        $isApiTemplate    = ! empty($data['is_api_template']) && $data['is_api_template'];
        if (file_exists(ROOT_PATH . $this->pdfSearchPath . $file) || $html5Editor || $isApiTemplate) {
            return $this->generatePreview($data, $file);
        }


        return response()->json(array(
            'data' => 'file doesn\'t exist',
        ));
    }

    protected function generatePreview($data, $file)
    {
        ob_start();
        $path  = ROOT_PATH . '/data/pdfs/tmp/';
        $error = '';

        $this->uploadFiles($path);

        try {
            $pdi                        = true;
            $svg                        = false;
            $usePdfDesigner             = false;
            //   $data['applyWatermarkOnTheSamePdf'] = false;
            $returnPdfContent           = isset($data['get_pdf']) && $data['get_pdf'] ? $data['get_pdf'] : false;
            $splitDesignerPdf           = isset($data['splitDesignerPdf']) && $data['splitDesignerPdf'] ? $data['splitDesignerPdf'] : false;
            $only_pdf_content           = isset($data['only_pdf_content']) && $data['only_pdf_content'] ? $data['only_pdf_content'] : false;
            $html5Editor                = isset($data['html5Editor']) && $data['html5Editor'] ? $data['html5Editor'] : false;
            $applyWatermarkOnTheSamePdf = isset($data['applyWatermarkOnTheSamePdf']) && $data['applyWatermarkOnTheSamePdf'] ? $data['applyWatermarkOnTheSamePdf'] : false;
            $customPdfVersion           = false;


            if (isset($data['api_additional_parameters']) && is_array($data['api_additional_parameters']) &&
                isset($data['api_additional_parameters']['customPdfVersion']) &&
                $data['api_additional_parameters']['customPdfVersion']) {
                $customPdfVersion = $data['api_additional_parameters']['customPdfVersion'];
            }
            $this->customPdfVersion       = $customPdfVersion;

            if (isset($data['generateSvgImage']) && $data['generateSvgImage']) {
                $pdi = false;
            }


            $pdfvt      = false;
            $use_pdf_vt = false;
            $use_pdvt_designer = false;
            if (isset($data['pdfData']) && strlen($data['pdfData'])) {
                $svg = true;
            }
            if (isset($data['use_pdf']) && $data['use_pdf']) {
                $usePdfDesigner = true;
            }
            if( isset( $data['use_pdvt_designer'] ) && $data['use_pdvt_designer'] ) {
                    $use_pdvt_designer = true;
            }
            if (isset($data['use_pdf_vt_otp']) && $data['use_pdf_vt_otp']) {
                $use_pdf_vt = true;
            }
            if($svg) {
                $newData = $this->createWhiteUnderprintForBlocks($data);
                if ($newData) {
                    // updated the whiteUnderprint imageSrc
                    $data = $newData;
                }
            }

            $this->startPdf($file, $pdf, $doc, $pdi, $pdfvt, $svg, $use_pdf_vt, $usePdfDesigner, $applyWatermarkOnTheSamePdf,$use_pdvt_designer);

            if (isset($data['isPackaging']) && $data['isPackaging']) {
                $this->startPdf($file, $pdf3, $doc, $pdi, $pdfvt, $svg, $use_pdf_vt, false);
            }
            $this->loadFonts($data, $pdf, $pdfvt, $use_pdf_vt,$use_pdvt_designer);

            if ($svg) {

                $this->createSvgFromJson($data, $doc, $pdf, $path, true);
                if (isset($data['isPackaging']) && $data['isPackaging']) {
                    $this->createPdfPackaging($data, $pdf3);
                }

            } else {
                if ($pdi) {
                    $page_length = 0;
                    if ( ! $html5Editor) {
                        $page_length = $pdf->pcos_get_number($doc, 'length:pages');
                    }
                    if ($page_length || $html5Editor) {
                        if (isset($data['shapeGenerate']) && $data['shapeGenerate']) {
                            $this->createShape($data, $pdf, $doc, false, $pdi);
                        } else {
                            $this->fillPdfBlocks($data, $page_length, $pdf, $doc, $path, $img, false);
                        }
                    } //end page_length if
                } else {
                    if (isset($data['shapeGenerate']) && $data['shapeGenerate']) {
                        $this->createShape($data, $pdf, $doc, false, $pdi);
                    }
                }
            }
            $pdf->end_document("");
            if ( ! $svg && $pdi && ! $html5Editor) {
                $pdf->close_pdi_document($doc);
            }


            if (isset($data['isPackaging']) && $data['isPackaging']) {
                $pdf3->end_document("");

            }

            $buf = $pdf->get_buffer();

            if (isset($data['isPackaging']) && $data['isPackaging']) {
                $buf3 = $pdf3->get_buffer();
            }
            $pdf           = null;
            $image_preview = $data['selection'] . '.pdf';
            $hires         = isset($data['hires']) ? $data['hires'] : '';
            $pdf_file_name = "$data[selection].pdf";
            $watermark     = isset($data['watermark']) && $data['watermark'] ? true : false;
            if($applyWatermarkOnTheSamePdf && $watermark){
                $pdf_file_name = "$data[selection]_watermark.pdf";
                $image_preview = "$data[selection]_watermark.pdf";
            }
            if (file_put_contents(ROOT_PATH . $this->pdfResultFolder . $pdf_file_name, $buf)) {
                if (isset($data['scale_pdf_printservice']) && $data['scale_pdf_printservice']) {
                    $this->scalePrintServicePdf(ROOT_PATH . $this->pdfResultFolder . $pdf_file_name, $data);
                }
                if(isset($data['rotation_print_pdf']) && $data['rotation_print_pdf']){

                    $pdfForRotate = ROOT_PATH . $this->pdfResultFolder . $pdf_file_name;
                    $pdf_path       = $this->rotatePrintPdf($pdfForRotate, $data);
                }

                if(isset($data['split_tiles_print_pdf']) && $data['split_tiles_print_pdf']){

                    $pdfForSplitTiles = ROOT_PATH . $this->pdfResultFolder . $pdf_file_name;
                    $this->splitTilesPrintPdf($pdfForSplitTiles, $data);
                }

				if(isset($data['custom_fruit_candy_pdf']) && $data['custom_fruit_candy_pdf']){

                    $pdfForCandy = ROOT_PATH . $this->pdfResultFolder . $pdf_file_name;
                    $this->createFruchtbonbons($pdfForCandy, $data);
                }


                if ($splitDesignerPdf) {
                    $pdfForSplit = ROOT_PATH . $this->pdfResultFolder . $pdf_file_name;

                    $splitPagesOrder = isset($data['splitPagesOrder']) && $data['splitPagesOrder'] ? $data['splitPagesOrder'] : [];
                    $pdf_path       = $this->splitPdfForApi($pdfForSplit, $data, $splitPagesOrder);
                    if ( ! $pdf_path) {
                        throw new \Exception('Cannot split pdfs');
                    }

                    return response()->json(array(
                        'data'    => [
                            'pdfContent' => base64_encode(file_get_contents($pdf_path))
                        ],
                        'success' => true
                    ));
                }

                $pdfContent = false;
                if ($returnPdfContent) {
                    $pdfContent = base64_encode(file_get_contents(ROOT_PATH . $this->pdfResultFolder . $pdf_file_name));
                }

                if (isset($data['use_outline']) && $data['use_outline']) {
                    $this->generateWithoutOutline($data['selection']);
                }
                if (isset($data['watermark']) && $data['watermark'] && !$applyWatermarkOnTheSamePdf) {

                    if (isset($data['watermark']) && isset($data['watermark']['client']) && strlen($data['watermark']['client']) && $data['watermark']['client'] == 'flyeralarm') {
                        $buf2 = $this->_addWatermarkFA(ROOT_PATH . $this->pdfResultFolder . "$data[selection].pdf", $data);
                    }else{
                        $buf2 = $this->_addWatermarkNew(ROOT_PATH . $this->pdfResultFolder . "$data[selection].pdf", $data);
                    }


                    if ($returnPdfContent) {
                        $pdfContent = base64_encode(file_get_contents(ROOT_PATH . $this->pdfResultFolder . "$data[selection]_watermark.pdf"));
                    }
                    $wtm_image_preview = $data['selection'] . '_watermark' . '.pdf';
                }
				if (isset($data['barcode']) && $data['barcode']) {
					 $this->addBarcode(ROOT_PATH . $this->pdfResultFolder . "$data[selection].pdf", $data);  
                     $pdfContent = base64_encode(file_get_contents(ROOT_PATH . $this->pdfResultFolder . $pdf_file_name));
                }
                if(isset($data['make_overprint_image']) && $data['make_overprint_image'] ){

                    if (isset($data['watermark']) && $data['watermark'] && !$applyWatermarkOnTheSamePdf) {
                       $response_underprint =  $this->addWhiteUnderprintLayer(ROOT_PATH . $this->pdfResultFolder . "$data[selection]_watermark.pdf",true,$data);
                       if(!$response_underprint['success']){
                           throw new \Exception($response_underprint['message']);
                       }
                        if ($returnPdfContent) {

                            $pdfContent = base64_encode(file_get_contents(ROOT_PATH . $this->pdfResultFolder . $pdf_file_name));
                        }
                    }else{
                        $response_underprint =   $this->addWhiteUnderprintLayer(ROOT_PATH . $this->pdfResultFolder . "$data[selection].pdf",false, $data);
                        if(!$response_underprint['success']){
                            throw new \Exception($response_underprint['message']);
                        }
                        if ($returnPdfContent) {

                            $pdfContent = base64_encode(file_get_contents(ROOT_PATH . $this->pdfResultFolder . $pdf_file_name));
                        }
                    }

                }
                if (isset($data['isPackaging']) && $data['isPackaging']) {

                    file_put_contents(ROOT_PATH . $this->pdfResultFolder . "$data[selection]_packaging.pdf", $buf3);

                }
				if($only_pdf_content){
					return response()->json(array(
                        'data'    => [
                            'pdfContent' =>  $pdfContent
                        ],
                        'success' => true
                    ));
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
                }
                //this is for ran-603

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
                    'gsQuality'   => $gsQuality
                );
                if (isset($data['watermark']) && isset($data['watermark']['preview']) && $data['watermark']['preview'] && !$applyWatermarkOnTheSamePdf) {
                    $params['wtm_file'] = $wtm_image_preview;
                }
                if($applyWatermarkOnTheSamePdf){
                    $image = $toImage->getImage($params);
                }else{
                    $image = $toImage->get($params);
                }


                if (isset($data['preview_type']) && $data['preview_type'] == 'live') {

                    $image = $this->generateLivePreview($image, $data);

                    if ($image) {
                        if ($image->result && $returnPdfContent) {
                            $variables_json               = $image->result;
                            $variables_json['pdfContent'] = $pdfContent;
                            $image->setVariable('result', $variables_json);
                        }
                        return response()->json(array(
                            'data'    => $image && $image->result ? $image->result : null,
                            'success' => $image && $image->result ? 1 : 0
                        ));
                    }
                }
                ob_end_clean();
                $image = json_decode($image->content());
                if ($image && $image->result && $returnPdfContent) {
                    $variables_json               = $image->result;
                    $variables_json['pdfContent'] = $pdfContent;
                    // $image->setVariable('result', $variables_json);
                    $image->result = $variables_json;
                }
                return response()->json(array(
                    'data'    => $image && $image->result ? $image->result : "",
                    'success' => 1
                ));
                return $image;
            }
        } catch (PDFlibException $e) {
            \Log::error($e);
			$this->deleteAllFiles($this->filesToDelete);
            $error = $e->getMessage();
        } catch (\Exception $e) {
            \Log::error($e);
			$this->deleteAllFiles($this->filesToDelete);
            $error = $e->getMessage();
        }
        ob_end_clean();

        return response()->json(array(
            'result' => array('error' => print_r($error, true)),
        ));
    }

    private function applyFAWatermarkOnPdfPage(&$pdf,$data,$pdfwidth,$pdfheight){
        $pdf_scale = $pdfwidth / $pdfheight;
        $svg_logo_name='fa_logo.svg';
        if( $pdf_scale >= 0.25 && $pdf_scale <= 4) {
            $svg_logo_name='fa_logo_small.svg';
        }

        //  logo
        $svg_logo=ROOT_PATH . $this->watermarkPath . $svg_logo_name;
        $graphics = $pdf->load_graphics("auto", $svg_logo, "");
        $xml=simplexml_load_file($svg_logo) ;
        $svg_width  = 500;
        $svg_height = 500;
        try{
            $svg_width=$xml->attributes()['width'];
            $svg_height=$xml->attributes()['height'];
        }
        catch(\Exception $e) {
        }

        $scaleX = $pdfwidth / 2.5;
        $scaleY = $pdfheight / 2.5;
        $scale = $scaleX;
        $useX = true;
        if( $scale > $scaleY ) {
            $scale=$scaleY;
            $useX = false;
        }

        $logoscale = $scale / 200;

        $scale=$logoscale;
        if( $useX ) {
            if ( $svg_height * $scale < $pdfheight ) {
                $scale = $pdfheight / $svg_height;
            }
        }
        else {
            if ( $svg_width * $scale < $pdfwidth ) {
                $scale = $pdfwidth / $svg_width;
            }
        }

        $optlist = "boxsize={ " . ($pdfwidth ) . " " . $pdfheight . "}  position={center} scale=$scale";

        $pdf->fit_graphics($graphics, 0 , 0,  $optlist);
        $pdf->close_graphics($graphics);
    }


    private function applyWatermarkOnPdfPage(&$pdf,$data,$width,$height){
        $wtm_text = 'Watermark';
        if (isset($data['watermark']['text']) && strlen($data['watermark']['text'])) {
            $wtm_text = $data['watermark']['text'];
        }
        $wtm_color = '{rgb 0 0 0}';
        $wtm_fid = $pdf->load_font('Helvetica', "unicode", "embedding");

        if (isset($data['watermark']['color']) && strlen($data['watermark']['color'])) {

            $additional = explode(" ", rtrim(trim($data['watermark']['color'])));
            if (is_array($additional) && count($additional) == 4) {

                $wtm_color = '{cmyk ' . $data['watermark']['color'] . '}';
            } else {
                $wtm_color = '{rgb ' . $data['watermark']['color'] . '}';
            }
        }

        $wtm_opacity = 9;
        if (isset($data['watermark']['opacity']) && strlen($data['watermark']['opacity']) && (float)$data['watermark']['opacity'] < 10 && (float)$data['watermark']['opacity'] >= 1) {
            $wtm_opacity = $data['watermark']['opacity'];
        }
        $wtm_limit = 0.8;
        $wtm_fsize = 150;

        if (isset($data['watermark']['size']) && strlen($data['watermark']['size'])) {
            $wtm_fsize = $data['watermark']['size'];
        }
        $diagonal = ceil(sqrt(pow($width, 2) + pow($height, 2)));
        $limit = $diagonal * $wtm_limit;
        $rotate = $this->pdf_calculateAngle($height, $width, $diagonal);
        $string_width = $this->getNewFontSize($pdf, $wtm_text, $wtm_fid, $wtm_fsize);
        if ($string_width >= $limit) {
            $wtm_fontsize = number_format(($limit * $wtm_fsize / $string_width), 2, '.', '');
        } else {
            $wtm_fontsize = $wtm_fsize;
        }
        $pdf->save();
        $gstate = $pdf->create_gstate("opacityfill=.{$wtm_opacity}");
        $pdf->set_gstate($gstate);
        $tf = $pdf->create_textflow($wtm_text, "fontname=Helvetica fontsize=" . $wtm_fontsize . " encoding=unicode fillcolor=" . $wtm_color);
        $pdf->fit_textflow($tf, $wtm_fontsize / 1.5, 0, $diagonal, $wtm_fontsize, "rotate=" . $rotate . " verticalalign=center");
        $pdf->restore();

    }
    private function addBarcode($file, $data)
    {
		$barcodeConfig  = isset($data['barcode']) ? $data['barcode'] :false;
		if(!$barcodeConfig){
			return false;
		}
		$barcodeType 		= isset($barcodeConfig['type']) ? $barcodeConfig['type'] : 'code39';
		$barcodeText 		= isset($barcodeConfig['text']) ? $barcodeConfig['text'] : '';
		$barcodePosition 	= isset($barcodeConfig['position']) ? $barcodeConfig['position'] : '';
		$barcodeRotation 	= isset($barcodeConfig['rotation']) ? $barcodeConfig['rotation'] : '0';
		$barcodeOptions 	= isset($barcodeConfig['options']) ? $barcodeConfig['options'] : [];
		$barcodeFilename	= 'barcode_'. microtime(true). '_' . rand(1,1000) . '.jpeg';
		
		$barcode = false;
		if ($this->getBarCode($barcodeType, $barcodeText, $barcodeFilename, $barcodeOptions)) {
			$barcode = ROOT_PATH . '/data/pdfs/tmp/' . $barcodeFilename;
        }
		
		if (!$barcode)
			return false;
		
		$pdf = null;
        $doc = null;
        $this->startPdfBarcode($pdf, $doc, $file);
      
        $count   = $pdf->pcos_get_string($doc, "length:pages");
		$pages = isset($barcodeConfig['pages']) ? $barcodeConfig['pages']:[0];	
		
		$barcodeImage = $pdf->load_image("auto", $barcode, "");
		if ($barcodeImage == 0) {
			return false;
		}
        for ($i = 0; $i < $count; $i++) {
			if (!in_array($i, $pages))
				continue;
            $page   = $pdf->open_pdi_page($doc, $i + 1, "cloneboxes");
            $width  = $pdf->pcos_get_number($doc, "pages[$i]/width");
            $height = $pdf->pcos_get_number($doc, "pages[$i]/height");
            $pdf->begin_page_ext($width, $height, '');
            $pdf->fit_pdi_page($page, 0.0, 0.0, "cloneboxes");	
			
			$unit = isset($barcodePosition['unit']) ? $barcodePosition['unit'] : 'mm';
			$x = isset($barcodePosition['x']) ? $barcodePosition['x'] : '0.5';
			$y = isset($barcodePosition['y']) ? $barcodePosition['y'] : '0.5';
			switch($unit){
				case 'mm':
					$x = $x * 2.8346456693;
					$y = $y * 2.8346456693;
					break;
			}
			
			$orientate = 'north';
			switch($barcodeRotation){
				case '0':
				case 'north':
					$orientate = 'north';
					break;
				case '90':
				case 'west':
					$orientate = 'west';
					break;
				case '180':
				case 'south':
					$orientate = 'south';
					break;
				case '270':
				case 'east':
					$orientate = 'east';
					break;
			}
			$optlist = "orientate=$orientate";
			$pdf->fit_image($barcodeImage, $x, $y, $optlist);			
			
            $pdf->end_page_ext("");
        }
        $this->endPdfWatermark($pdf, $doc);
        $buf        = $pdf->get_buffer();        
        $pdf_result = ROOT_PATH . $this->pdfResultFolder . $data['selection'] . '.pdf';
        file_put_contents($pdf_result, $buf);
    }

    private function getPdfMask($pdf, $outputFilename, $page = 0, $removeMask=true, $useMaskFilename = null, 
        $usePrintFullMask = null, $usemaskColor = null, $quality = 300, $threshold = 2)
    {
        $tmp_dir         = ROOT_PATH . '/data/result/';
        $page            = (int) $page;
        $transp          = "-transp";
        $qualityOption   = "-r $quality";
        $pageOption      = "-singlefile -f $page -l $page";
        $deviceCairo     = "-png";
        $previewFilename = $usePrintFullMask == null ? $tmp_dir.'preview_'.uniqid(true) : $usePrintFullMask;
        $optsCairo       = [
            'pdftocairo',
            //'-q',
            $pageOption,
            $deviceCairo,
            $transp,
            $qualityOption,
            "'".$pdf."'",
            "'$previewFilename'"
        ];
        $exec_res        = exec(escapeshellcmd((string) implode(' ', $optsCairo)), $ret);
        $previewFilename .= ".png";

        if (!file_exists($previewFilename)) {
            throw new \Exception('Couldn`t preview pdf');
        }        
        $fuzz        = "-fuzz 0%";
        $fill        = "-fill black";
        $color       = "+opaque none";
        $maskFilename = $tmp_dir.'mask_'.uniqid(true) . ".tif";
        if( $useMaskFilename ) {
            $maskFilename = $useMaskFilename;
            $fill        = "-fill " . $usemaskColor;
        }
        $optsConvertMask = [
            'convert',
            $previewFilename,
            $fuzz,
            $fill,
            $color,
            $maskFilename
        ];
        $exec_res    = exec(escapeshellcmd((string) implode(' ', $optsConvertMask)), $ret);

        if (!file_exists($maskFilename)) {
            throw new \Exception('Couldn`t create mask');
        }

        $this->applyMorphology($threshold, $maskFilename, $outputFilename);
        
        if( $removeMask ) {
            unlink($previewFilename);
            unlink($maskFilename);
        }
        
        if (!file_exists($outputFilename)) {
            throw new \Exception('Couldn`t create output');
        }

        return $outputFilename;
        
    }
    private function applyMorphology($threshold, $maskFilename, $outputFilename) {
        $morphology        = "-morphology Erode Disk:$threshold";
        $optsConvertErode = [
            'convert',
            $maskFilename,
            $morphology,
            $outputFilename
        ];
        $exec_res    = exec(escapeshellcmd((string) implode(' ', $optsConvertErode)), $ret);
    }

    public function getWhiteUnderPrintPdfAction(){

        $data = false;
        $file = $this->params()->fromRoute('file');
        $f = ROOT_PATH . $this->pdfResultFolder . $file;

        try{
            if(file_exists($f)){

                if(strpos($file,'_watermark') !== false){
                    $underprintResponse = $this->addWhiteUnderprintLayer($f,true,array(),true);

                }else{
                    $underprintResponse = $this->addWhiteUnderprintLayer($f,false,array(),true);
                }

                if($underprintResponse['success']){
                    $data= base64_encode($underprintResponse['buffer']);
                }
            }

        }catch (PDFlibException $e) {
            $data = false;
        }
        catch (\Exception $e) {
            $data = false;
        }
        return response()->json(array(
            'data' => $data,
        ));
    }

    private function addWhiteUnderprintLayer($file,$watermark,$data= array(),$onlyBuffer=false){

        $response = array(
            'success'=>1,
            'message'=>'Success',
            'buffer'=>''
        );

        try{
            $pdf = null;
            $doc = null;
            $this->startPdfBarcode($pdf, $doc, $file, $watermark ? 1 : 0);
            $count   = $pdf->pcos_get_string($doc, "length:pages");

            for ($i = 0; $i < $count; $i++) {

                $page           = $pdf->open_pdi_page($doc, $i + 1, "cloneboxes");
                $width          = $pdf->pcos_get_number($doc, "pages[$i]/width");
                $height         = $pdf->pcos_get_number($doc, "pages[$i]/height");

                $pdf->begin_page_ext($width, $height, '');
                $pdf->fit_pdi_page($page, 0.0, 0.0, "cloneboxes");

                $image_mask_path = ROOT_PATH . '/data/result/preview_mask_'.uniqid(true).'.tif';
                $this->getPdfMask($file,$image_mask_path,($i+1),true,null,null,null,360);

                $pdf->setcolor("fillstroke", "cmyk", 1, 0, 0, 0);
                $spot            = $pdf->makespotcolor("White");
                $optlist         = "colorize=" . $spot;
                $image           = $pdf->load_image("auto", $image_mask_path, $optlist);
                if ($image == 0) {
                    throw new \Exception("Error: " . $pdf->get_errmsg());
                }
                $optlist = "boxsize={" . $width . " " . $height. "}   fitmethod=entire";
                $gstate = $pdf->create_gstate("overprintstroke=true overprintfill=true overprintmode=1");
                $pdf->save();
                $pdf->set_gstate($gstate);

                $pdf->fit_image($image, 0, 0, $optlist);
                $pdf->restore();
                $pdf->end_page_ext("");
                if(file_exists($image_mask_path)){
                    unlink($image_mask_path);
                }
            }

            $this->endPdfWatermark($pdf, $doc);
            $buf = $pdf->get_buffer();
            if($onlyBuffer){
                $response['buffer'] = $buf;
            }else{
                if($watermark){
                    $pdf_result = ROOT_PATH . $this->pdfResultFolder . $data['selection'] . '_watermark.pdf';
                }else{
                    $pdf_result = ROOT_PATH . $this->pdfResultFolder . $data['selection'] . '.pdf';
                }
                file_put_contents($pdf_result, $buf);
            }


        } catch (PDFlibException $e) {
            $response['success'] = 0;
            $response['message'] = $e->getMessage();
        }
        catch (\Exception $e) {
            $response['success'] = 0;
            $response['message'] = $e->getMessage();
        }

        return $response;
    }
    private function _addWatermarkFA($file, $data)
    {

        $pdf = null;
        $doc = null;
        $this->startPdfWatermark($pdf, $doc, $file);
        $this->loadFonts($data, $pdf, false, false);
        $pdf->set_option("FontOutline={Helvetica=Helvetica.ttf}");
        $wtm_fid = $pdf->load_font('Helvetica', "unicode", "embedding");
        $count   = $pdf->pcos_get_string($doc, "length:pages");


        for ($i = 0; $i < $count; $i++) {
            $page   = $pdf->open_pdi_page($doc, $i + 1, "cloneboxes");
            $width  = $pdf->pcos_get_number($doc, "pages[$i]/width");
            $height = $pdf->pcos_get_number($doc, "pages[$i]/height");
            $pdf->begin_page_ext($width, $height, '');
            $pdf->fit_pdi_page($page, 0.0, 0.0, "cloneboxes");
            $this->applyFAWatermarkOnPdfPage($pdf, $data, $width, $height);
            $pdf->end_page_ext("");
        }
        $this->endPdfWatermark($pdf, $doc);
        $buf        = $pdf->get_buffer();
        $pdf_result = ROOT_PATH . $this->pdfResultFolder . $data['selection'] . '_watermark.pdf';
        file_put_contents($pdf_result, $buf);

    }

	 private function _addWatermarkNew($file, $data)
    {

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

                $wtm_color = '{cmyk ' . $data['watermark']['color'].'}';
            } else {
                $wtm_color = '{rgb ' . $data['watermark']['color'].'}';
            }
        }

        $wtm_opacity = 9;
        if (isset($data['watermark']['opacity']) && strlen($data['watermark']['opacity']) && (float)$data['watermark']['opacity'] < 10 && (float)$data['watermark']['opacity'] >= 1) {
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
            $tf = $pdf->create_textflow($wtm_text, "fontname=Helvetica fontsize=" . $wtm_fontsize . " encoding=unicode fillcolor=".$wtm_color);
            $pdf->fit_textflow($tf, $wtm_fontsize / 1.5, 0, $diagonal, $wtm_fontsize, "rotate=" . $rotate . " verticalalign=center");
            $pdf->end_page_ext("");
        }
        $this->endPdfWatermark($pdf, $doc);
        $buf        = $pdf->get_buffer();
        $pdf_result = ROOT_PATH . $this->pdfResultFolder . $data['selection'] . '_watermark.pdf';
        file_put_contents($pdf_result, $buf);

    }
	
	
	

    private function startPdfWatermark(&$pdf, &$doc, $file)
    {


        $pdf = new \PDFlib();
        if (config('rest.pdf_license_key')) {
        	$pdf->set_option("license=" . config('rest.pdf_license_key'));
		}
        $pdf->set_option("errorpolicy=return");
        $pdf->set_option("stringformat=utf8");
        $pdf->set_option("searchpath={" . ROOT_PATH . $this->pdfSearchPath . "}");
        $pdf->set_option("searchpath={" . ROOT_PATH . $this->fontSearchPath . "}");
        $optlist = "masterpassword=".config('rest.watermark_master_password') ." permissions={noprint nomodify nocopy}";

        if ( ! $pdf->begin_document('', $optlist)) {
            throw new \Exception('Error: ' . $pdf->get_errmsg());
        }
        $pdf->set_info("Creator", "CloudLab");
        $pdf->set_info("Title", "Cloudlab ");
        $doc = $pdf->open_pdi_document($file, "");
        if ( ! $doc) {
            throw new \Exception('Error: ' . $pdf->get_errmsg());
        }
    }
	
	private function startPdfBarcode(&$pdf, &$doc, $file,$watermark=false)
    {


        $pdf = new \PDFlib();
        if (config('rest.pdf_license_key')) {
        	$pdf->set_option("license=" . config('rest.pdf_license_key'));
		}
        $pdf->set_option("errorpolicy=return");
        $pdf->set_option("stringformat=utf8");
        $pdf->set_option("searchpath={" . ROOT_PATH . $this->pdfSearchPath . "}");
        $pdf->set_option("searchpath={" . ROOT_PATH . $this->fontSearchPath . "}");
        $optlist = "";
        $open_pdi_options= '';

        if($watermark){
            $optlist = " masterpassword=".config('rest.watermark_master_password') ." permissions={noprint nomodify nocopy}";
            $open_pdi_options = "password=".config('rest.watermark_master_password');
        }

        if ( ! $pdf->begin_document('', $optlist)) {
            throw new \Exception('Error: ' . $pdf->get_errmsg());
        }
        $pdf->set_info("Creator", "CloudLab");
        $pdf->set_info("Title", "Cloudlab ");


        $doc = $pdf->open_pdi_document($file, $open_pdi_options);
        if ( ! $doc) {
            throw new \Exception('Error: ' . $pdf->get_errmsg());
        }
    }

    private function endPdfWatermark(&$pdf, &$doc)
    {
        $pdf->end_document("");
        $pdf->close_pdi_document($doc);
    }


    public function createPdfAction()
    {
        ob_start();
        $data  = $_POST;
        $error = '';

        try {
            $outfile            = "";
            $options            = "";
            $bleed              = $data['pdfData']['bleed'] * 0.75;
            $pdfWidth           = $data['pdfData']['width'] * 0.75 + $bleed * 2;
            $pdfHeight          = $data['pdfData']['height'] * 0.75 + $bleed * 2;
            $imageWidth         = $data['imageData']['width'] * 0.75;
            $imageHeight        = $data['imageData']['height'] * 0.75;
            $imageLeft          = $data['imageData']['left'] * 0.75;
            $imageTop           = $data['imageData']['top'] * 0.75;
            $imageRotationAngle = -1 * $data['imageData']['angle'];
            $image_url          = $data['imageData']['src'];

            $trimbox_llx = $bleed;
            $trimbox_lly = $bleed;
            $trimbox_urx = $trimbox_llx + $data['pdfData']['width'] * 0.75;
            $trimbox_ury = $trimbox_lly + $data['pdfData']['height'] * 0.75;
            $trimbox     = " trimbox={" . $trimbox_llx . " " . $trimbox_lly . " " . $trimbox_urx . " " . $trimbox_ury . "}";

            $imageLeftPDFLIB = $imageLeft - $imageWidth / 2;
            $imageTopPDFLIB  = $pdfHeight - ($imageTop + $imageHeight / 2);
            if ($imageRotationAngle !== 0) {
                $rad         = deg2rad($imageRotationAngle);
                $result['x'] = (-$imageWidth / 2) * cos($rad) - (-$imageHeight / 2) * sin($rad);
                $result['y'] = (-$imageWidth / 2) * sin($rad) + (-$imageHeight / 2) * cos($rad);

                $deviation['x'] = (-$imageWidth / 2) - $result['x'];
                $deviation['y'] = (-$imageHeight / 2) - $result['y'];

                $imageLeftPDFLIB = $imageLeftPDFLIB - $deviation['x'];
                $imageTopPDFLIB  = $imageTopPDFLIB - $deviation['y'];
            }

            $options .= " boxsize={" . $imageWidth . " " . $imageHeight . "}";
            $options .= " fitmethod=entire";
            $options .= " rotate=" . $imageRotationAngle;


            $p = new \pdflib();

            if (config('rest.pdf_license_key')) {
                $p->set_option("license=" . config('rest.pdf_license_key'));
            }

            $p->set_option("errorpolicy=return");
            $p->set_option("stringformat=utf8");
            $p->set_option("searchpath={" . ROOT_PATH . $this->pdfSearchPath . "}");

            if ($p->begin_document($outfile, "") == 0) {
                throw new Exception("Error: " . $p->get_errmsg());
            }

            $p->set_info("Creator", "CloudLab");
            $p->set_info("Title", "Label");

            /* Start a page */
            $p->begin_page_ext(0, 0, "width=" . $pdfWidth . " height=" . $pdfHeight . $trimbox);

            $imageData = file_get_contents($image_url);
            if ($imageData == false) {
                throw new Exception("Error: file_get_contents($image_url) failed");
            }

            /* Store the image in a PDFlib virtual file (PVF) called
                 * "/pvf/image"
                 */
            $p->create_pvf("/pvf/image", $imageData, "");

            /* Load the image from the PVF */
            $image = $p->load_image("auto", "/pvf/image", "");
            if ($image == 0) {
                throw new Exception("Error: " . $p->get_errmsg());
            }

            /* place the image and finish the page */
            $p->fit_image($image, $imageLeftPDFLIB, $imageTopPDFLIB, $options);

            $p->end_page_ext("");

            /* Delete the virtual file to free the allocated memory */
            $p->delete_pvf("/pvf/image");

            $p->end_document("");

            $buf = $p->get_buffer();

            return response()->json(array(
                'result' => array('file' => base64_encode($buf)),
            ));

        } catch (PDFlibException $e) {
            $error = $e->getMessage();
        }
        ob_end_clean();
        return response()->json(array(
            'result' => array('error' => print_r($error, true)),
        ));

    }

    public function creatediecutAction()
    {
        ob_start();
        $data         = $_POST;
        $error        = '';
        $pdfx4_schwar = isset($data['pdfx4_schwar']) && $data['pdfx4_schwar'] ? $data['pdfx4_schwar'] : false;
        try {
            if (isset($data['isPackaging']) && $data['isPackaging']) {
                if (isset($data['client']) && ($data['client'] === 'flyeralarm' || $data['client'] === 'flyeralarm_staging') &&
                    isset($data['has_pdf_to_merge']) && $data['has_pdf_to_merge']
                ) {
                    $this->uploadFiles(ROOT_PATH . $this->pdfToMergeFolder);
                    $mergePdfName = $data['pdf_to_merge_filename'];
                    $mergePdfPath = ROOT_PATH . $this->pdfToMergeFolder . $mergePdfName;
                    $this->startPackagingPdfFlyeralarm($mergePdfPath, $pdf3, $indoc);
                    $this->deleteAllFiles([$mergePdfPath]);
                } else {
                    if ($pdfx4_schwar) {
                        $this->startPdfSchwarDownloadPdf(false, $pdf3, $doc, false, true, true, false, false);
                    } else {
                        $this->startPdf(false, $pdf3, $doc, false, false, true, false, false);
                    }
                }

                $this->createPdfPackaging($data, $pdf3);

                $pdf3->end_document("");

                $buf3 = $pdf3->get_buffer();

                if (file_put_contents(ROOT_PATH . $this->pdfResultFolder . "$data[file]_packaging.pdf", $buf3)) {
                    ob_end_clean();

                    return response()->json(array(
                        'result' => array('file' => $data['file'] . '_packaging.pdf'),
                    ));
                } else {
                    return response()->json(array(
                        'result' => array('error' => 'Couldn`t write file on server'),
                    ));
                }

            }

        } catch (PDFlibException $e) {
            $error = $e->getMessage();
        } catch (\Exception $e) {
            $error = $e->getMessage();
        }
        ob_end_clean();
        return response()->json(array(
            'result' => array('error' => print_r($error, true)),
        ));

    }

    public function createPdfPreviewImage($data, $pdfFilename)
    {
        $toImage     = new RestController();
        $res         = isset($data['preview_resolution']) && $data['preview_resolution'] ? $data['preview_resolution'] : 150;
        $previewPage = 1;
        $gsQuality   = (isset($data['gsQuality']) && (int)$data['gsQuality'] == 1) ? (int)$data['gsQuality'] : 0;
        $params   = array(
            'file'        => $pdfFilename,
            'page'        => $previewPage,
            'res'         => $res,
            'hires'       => 0,
            'live'        => 0,
            'flip'        => 0,
            'trim_box'    => 0,
            'fit_to_page' => 0,
            'file_output' => 'jpeg',
            'gsQuality'   => $gsQuality,
        );
        $response = $toImage->get($params);
        if (!$response) {
            throw new \Exception("An error has occured!");
        }
        if (!isset($response->result)) {
            throw new \Exception("An error has occured!");
        }
        if (isset($response->result->error)) {
            throw new \Exception($response->result->error);
        }
        return $response;
    }
    public function deleteFileAction()
    {
        $file    = $this->params()->fromRoute('file');
        $f       = ROOT_PATH . $this->pdfResultFolder . $file;
        $deleted = 0;
        if (file_exists($f)) {
            $deleted = 1;
            @unlink($f);
        }
        return response()->json(array('success' => $deleted));
    }
    public function applyDieCutToPdf($destDir, $pdfFilename, $diecutPdfFilename, $data)
    {
        $this->startPdf($destDir . $pdfFilename, $pdf, $doc, true, false, false, false, true);
        $this->createPdfPackaging($data, $pdf, $doc, 1);
        $pdf->end_document("");
        $buf         = $pdf->get_buffer();
        $pdfResponse = $this->createPdfPreviewImage($data, $pdfFilename);
        @unlink($destDir . $pdfFilename);
        $saveFile = file_put_contents(ROOT_PATH . $this->pdfResultFolder . $diecutPdfFilename, $buf);
        if (!$saveFile) {
            throw new \Exception("Couldn`t write file on server!");
        }
        //Start create preview
        $diecutResponse = $this->createPdfPreviewImage($data, $diecutPdfFilename);
        $result = array(
            'pdf'          => $pdfResponse->result['pdf'],
            'diecutPdf'    => $diecutResponse->result['pdf'],
            'images'       => array(
                $pdfResponse->result['image'],
            ),
            'diecutImages' => array(
                $diecutResponse->result['image'],
            )
        );
        return $result;
    }
    public function applyDieCutToPdfAction()
    {
        $data              = $_POST;
        $pdf               = $_FILES['pdf'];
        $destDir           = ROOT_PATH . $this->pdfResultFolder;
        $uniqueId          = uniqid('.', true);
        $pdfFilename       = pathinfo($pdf['name'], PATHINFO_FILENAME) . $uniqueId . '.pdf';
        $diecutPdfFilename = pathinfo($pdf['name'], PATHINFO_FILENAME) . '.diecut' . $uniqueId . '.pdf';
        move_uploaded_file($pdf['tmp_name'], $destDir . $pdfFilename);
        try {
            $result = $this->applyDieCutToPdf($destDir, $pdfFilename, $diecutPdfFilename, $data);
        } catch (\Exception $e) {
            return response()->json(array(
                'stats'   => 'fail',
                'code'    => 400,
                'message' => $e->getMessage()
            ));
        }
        return response()->json($result);
    }
    public function createhelperpdfAction()
    {
        ob_start();
        $data            = $_POST;
        $error           = '';
        $path            = ROOT_PATH . $this->helperPdfFolder;
        $resultPath      = ROOT_PATH . $this->helperPdfResultFolder;
        $fileName        = $data['file'] . '.pdf';
        $pdfPath         = $path . $fileName;
        $this->filesToDelete[] = $pdfPath;

        try {
            if (isset($data['isPackaging']) && $data['isPackaging']) {

                $this->uploadFiles($path);

                $this->startPdf($pdfPath, $pdf3, $doc, true, false, false, false, false);

                $this->createPdfPackaging($data, $pdf3, $doc, 1);

                $pdf3->end_document("");

                $buf3 = $pdf3->get_buffer();

                $this->deleteAllFiles($this->filesToDelete);

                if (file_put_contents($resultPath . "$data[file].pdf", $buf3)) {
                    ob_end_clean();

                    return response()->json(array(
                        'result' => array('file' => $data['file'] . '.pdf'),
                    ));
                }

            }

        } catch (PDFlibException $e) {
            $error = $e->getMessage();
        }
        ob_end_clean();
        return response()->json(array(
            'result' => array('error' => print_r($error, true)),
        ));

    }
    protected function startPackagingPdfFlyeralarm($file, &$pdf, &$doc)
    {
        $pdf = new \PDFlib();
        if (config('rest.pdf_license_key')) {
        	$pdf->set_option("license=" . config('rest.pdf_license_key'));
		}
        $pdf->set_option("errorpolicy=return");
        $pdf->set_option("stringformat=utf8");
        $pdf->set_option("searchpath={" . ROOT_PATH . $this->pdfSearchPath . "}");
        $pdf->set_option("searchpath={" . ROOT_PATH . $this->fontSearchPath . "}");
        $pdf->set_option("searchpath={" . ROOT_PATH . $this->pdfToMergeFolder . "}");
        $optlist = '';
        if ( ! $pdf->begin_document('', $optlist)) {
            throw new \Exception('Error: ' . $pdf->get_errmsg());
        }
        $pdf->set_info("Creator", "CloudLab");
        $pdf->set_info("Title", "Packaging");
        $doc = $pdf->open_pdi_document($file, "");
        if ( ! $doc) {
            throw new \Exception('Error: ' . $pdf->get_errmsg());
        }
        $endpage = $pdf->pcos_get_number($doc, "length:pages");
        for ($pageno = 1; $pageno <= $endpage; $pageno++) {
            $page = $pdf->open_pdi_page($doc, $pageno, "");
            $pdf->begin_page_ext(0, 0, "");
            $pdf->fit_pdi_page($page, 0, 0, "adjustpage");
            $pdf->close_pdi_page($page);
            $pdf->end_page_ext("");
        }
    }

    public function createPdfPackingHelper($data, $svg_content, $page, $pdf, $doc, $exist)
    {
        $x_margin     = 0;
        $y_margin     = 0;
        $pixelConvert = 1;
        $svg          = tempnam("/tmp", "SVG");
        $this->filesToDelete[]  = $svg;
        file_put_contents($svg, $svg_content);

        preg_match('/(packingwidth)=["\']?((?:.(?!["\']?\s+(?:\S+)=|[p]))+.)["\']?/is', $svg_content, $packingwidth);
        preg_match('/(packingheight)=["\']?((?:.(?!["\']?\s+(?:\S+)=|[p]))+.)["\']?/is', $svg_content, $packingheight);

        if ( ! isset($packingwidth[2]) || ! $packingwidth[2]) {
            throw new \Exception('Error: Width not provided');
        }
        if ( ! isset($packingheight[2]) || ! $packingheight[2]) {
            throw new \Exception('Error: Height not provided');
        }

        $width       = $packingwidth[2] * $pixelConvert;
        $height      = $packingheight[2] * $pixelConvert;
        $optlist     = "";
        $optlistTrim = "";

        preg_match('/(trimboxulx)=["\']?((?:.(?!["\']?\s+(?:\S+)=|["\']))+.)["\']?/is', $svg_content, $trimboxulx);
        preg_match('/(trimboxuly)=["\']?((?:.(?!["\']?\s+(?:\S+)=|["\']))+.)["\']?/is', $svg_content, $trimboxuly);
        preg_match('/(trimboxlrx)=["\']?((?:.(?!["\']?\s+(?:\S+)=|["\']))+.)["\']?/is', $svg_content, $trimboxlrx);
        preg_match('/(trimboxlry)=["\']?((?:.(?!["\']?\s+(?:\S+)=|["\']))+.)["\']?/is', $svg_content, $trimboxlry);
        preg_match('/(bleedColor)=["\']?((?:.(?!["\']?\s+(?:\S+)=|["\']))+.)["\']?/is', $svg_content, $bleedColor);

        if ( ! (is_array($bleedColor) && count($bleedColor))) {
            $bleedColor = false;
        }


        //extract the trimbox

        if (is_array($trimboxulx) && count($trimboxulx) &&
            is_array($trimboxuly) && count($trimboxuly) &&
            is_array($trimboxlrx) && count($trimboxlrx) &&
            is_array($trimboxlry) && count($trimboxlry) &&
            is_array($packingwidth) && count($packingheight)
        ) {

            $xl = $trimboxulx[2] * $pixelConvert;
            $yl = ($packingheight[2] - $trimboxlry[2]) * $pixelConvert;
            $xr = $trimboxlrx[2] * $pixelConvert;
            $yr = ($packingheight[2] - $trimboxuly[2]) * $pixelConvert;

            $optlistTrim = $optlistTrim . " trimbox={" . $xl . " " . $yl . " " . $xr . " " . $yr . "}";

        }
        if ($exist) {
            $width  = (float)$pdf->pcos_get_number($doc, "pages[0]/width");
            $height = (float)$pdf->pcos_get_number($doc, "pages[0]/height");
        }

        $pdf->begin_page_ext(0, 0, "width=" . $width . " height=" . $height . $optlistTrim);

        if ($exist) {
            $pdf->fit_pdi_page($page, 0, 0, "");
            $optlist = "boxsize={ " . $width . " " . $height .
                       "} position={center} fitmethod=entire";
        } else {
            $optlist = "boxsize={ " . $width . " " . $height .
                       "} position={center} fitmethod=meet";
        }

        $client         = (isset($data['client']) && $data['client'] != '' && array_key_exists($data['client'], $this->packingDiecutColors)) ? $data['client'] : 'default';
        $diecutColors   = $this->packingDiecutColors[$client];
        //$overprintArray = isset($this->packingOverprint[$client]) ? $this->packingOverprint[$client] : $this->packingOverprint['default'];
        $overprint      = isset($this->packingOverprint[$client]) ? $this->packingOverprint[$client] : $this->packingOverprint['default'];

        $graphics = $pdf->load_graphics("auto", $svg, "");
        /*
        foreach ($overprint as $key => $arr) {
            $isOverprintOn = $key == 1;

            if (is_array($arr) && count($arr) > 0) {
                if ($isOverprintOn) {
                    $gstate = $pdf->create_gstate("overprintstroke=true overprintfill=true overprintmode=1");
                    $pdf->save();
                    $pdf->set_gstate($gstate);
                }
                //else {
                //    $gstate = $pdf->create_gstate( "overprintstroke=false" );
                //    $pdf->save();
                //    $pdf->set_gstate( $gstate );
                //}

                foreach ($arr as $cType) {
                    if (isset($diecutColors[$cType])) {
                        $cValue = $diecutColors[$cType];
                        if (strpos($svg_content, $cType) !== false) {
                            $pdf->setcolor("fillstroke", "cmyk", $cValue[0], $cValue[1], $cValue[2], $cValue[3]);
                            $spot = $pdf->makespotcolor($cType);
                        }
                    }
                }

                if ($isOverprintOn) {
                    $pdf->restore();
                }
            }
        }*/

        if ($overprint) {
            $gstate = $pdf->create_gstate("overprintstroke=true overprintfill=true overprintmode=1");
            $pdf->save();
            $pdf->set_gstate($gstate);
        }

        //$graphics = $pdf->load_graphics("auto", $svg, "");
        foreach ($diecutColors as $cType => $cValue) {
            if (strpos($svg_content, $cType) !== false) {
                $pdf->setcolor("fillstroke", "cmyk", $cValue[0], $cValue[1], $cValue[2], $cValue[3]);
                $spot = $pdf->makespotcolor($cType);
            }
        }


        if (strpos($svg_content, 'DieCutBleed') !== false || ! $bleedColor) {
            $rgbTmp = str_replace(array('rgb(', ')'), '', (string)$bleedColor[2]);
            $rgbTmp = explode(',', $rgbTmp);
            if (isset($rgbTmp[0]) && isset($rgbTmp[1]) && isset($rgbTmp[2])) {
                $pdf->setcolor("fillstroke", "rgb", $rgbTmp[0] / 255, $rgbTmp[1] / 255, $rgbTmp[2] / 255, 0);
                $spot = $pdf->makespotcolor("DieCutBleed");
            }

        }

        if ($pdf->info_graphics($graphics, "fittingpossible", '') == 1) {
            $pdf->fit_graphics($graphics, $x_margin, $y_margin, $optlist);
        } else {
            print_r($pdf->get_errmsg());
        }

        if ($overprint) {
            $pdf->restore();
        }

        $pdf->close_graphics($graphics);

    }

    public function createPdfPackaging($data, $pdf, $doc = false, $exist = 0)
    {

        $page =false;
        try {

            if(is_array($data['packaging']) && count($data['packaging'])){

                foreach ($data['packaging'] as $key => $packing_data) {
                    if ($exist) {
                        $page = $pdf->open_pdi_page($doc, ($key+1), "");
                    }
                    $this->createPdfPackingHelper($data, $packing_data, $page, $pdf, $doc, $exist);
                    $pdf->end_page_ext("");

                    if ($exist) {
                        $pdf->close_pdi_page($page);
                    }
                }
            }else{

                if ($exist) {
                    $page = $pdf->open_pdi_page($doc, 1, "");
                }
                $this->createPdfPackingHelper($data,$data['packaging'],$page,$pdf,$doc,$exist);
            $pdf->end_page_ext("");

            if ($exist) {
                $pdf->close_pdi_page($page);
            }

            }
        } catch (PDFlibException $e) {
            throw new \Exception($e->getMessage());
        }
    }

    public function createPdfPackaging_old($data, $pdf)
    {


        $svg = tempnam("/tmp", "SVG");

        $this->filesToDelete[]  = $svg;
        file_put_contents($svg, $data['packaging']);

        $xml      = simplexml_load_file($svg);
        $attr     = $xml->attributes();
        $x_margin = 0;
        $y_margin = 0;
        //create page

        $width  = (int)$attr->width * 72 / 96;
        $height = (int)$attr->height * 72 / 96;

        $pdf->begin_page_ext(0, 0, "width=" . $width . " height=" . $height);
        $optlist = "boxsize={ " . $width . " " . $height .
            "} position={center} fitmethod=meet";

        $graphics = $pdf->load_graphics("auto", $svg, "");
        if (strpos($data['packaging'], 'DieCutRed') !== false) {
            $pdf->setcolor("fillstroke", "cmyk", 0, 1, 1, 0);
            $spot = $pdf->makespotcolor("DieCutRed");
        }
        if (strpos($data['packaging'], 'DieCutBleed') !== false) {
            $rgbTmp = str_replace(array('rgb(', ')'), '', (string)$attr->bleedColor);
            $rgbTmp = explode(',', $rgbTmp);

            $pdf->setcolor("fillstroke", "rgb", $rgbTmp[0] / 255, $rgbTmp[1] / 255, $rgbTmp[2] / 255, 0);
            $spot = $pdf->makespotcolor("DieCutBleed");
        }

        if (strpos($data['packaging'], 'DieCutGreen') !== false) {
            $pdf->setcolor("fillstroke", "cmyk", 0, 0, 1, 0.5);
            $spot = $pdf->makespotcolor("DieCutGreen");
        }
        if (strpos($data['packaging'], 'DieCutBlue') !== false) {
            $pdf->setcolor("fillstroke", "cmyk", 1, 1, 0, 0);
            $spot = $pdf->makespotcolor("DieCutBlue");
        }
        if ($pdf->info_graphics($graphics, "fittingpossible", '') == 1) {
            $pdf->fit_graphics($graphics, $x_margin, $y_margin, $optlist);
        } else {
            print_r($pdf->get_errmsg());
        }

        $pdf->end_page_ext("");

        $pdf->close_graphics($graphics);
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
    protected function startPdf($file, &$pdf, &$doc, $pdi = true, $pdfvt = false, $svg = false, $use_pdf_vt_otp = false, $usePdfDesiner = false,$applyWatermarkOnTheSamePdf = false,$use_pdvt_designer = false)
    {

        $pdf = new \PDFlib();
        if (config('rest.pdf_license_key')) {
        	$pdf->set_option("license=" . config('rest.pdf_license_key'));
		}

        $pdf->set_option("errorpolicy=return");
        $pdf->set_option("stringformat=utf8");
        $pdf->set_option("searchpath={" . ROOT_PATH . $this->pdfSearchPath . "}");
        $pdf->set_option("searchpath={" . ROOT_PATH . $this->fontSearchPath . "}");

        $optlist = '';
        if ($use_pdf_vt_otp || $use_pdvt_designer) {
            $optlist = "pdfx=PDF/X-4 pdfvt=PDF/VT-1 usestransparency=true nodenamelist={root recipient} recordlevel=1";
        }

        if($applyWatermarkOnTheSamePdf){
            $optlist = " masterpassword=".config('rest.watermark_master_password') ." permissions={noprint nomodify nocopy}";
        }
		
		if($this->customPdfVersion){
			$optlist = " ".$this->customPdfVersion;
		}
		
        if ( ! $pdf->begin_document('', $optlist)) {
            throw new \Exception('Error: ' . $pdf->get_errmsg());
        }
		
		

        if (( ! $svg || $usePdfDesiner) && $pdi) {

            if ($pdi) {
                if ($use_pdf_vt_otp || $use_pdvt_designer) {
                    $pdf->set_info("Creator", "CloudLab");
                    $pdf->set_info("Title", "Business card");
                    # Define output intent profile */
                    if ($pdf->load_iccprofile(ROOT_PATH . "/data/ISOcoated.icc", "usage=outputintent") == 0) {
                        printf("Error: %s\n", $pdf->get_errmsg());
                        echo("Please install the ICC profile package from " .
                            "www.pdflib.com to run the PDF/VT-1 starter sample.\n");
                        exit(1);
                    }
                }
                if ($usePdfDesiner) {
                    $doc = $pdf->open_pdi_document($file, "");
                    if ( ! $doc) {
                        throw new \Exception('Error: ' . $pdf->get_errmsg());
                    }
                }
            }
        }else{
            if($use_pdvt_designer){
                $pdf->set_info( "Creator", "CloudLab" );
                $pdf->set_info( "Title", "Business card" );
                # Define output intent profile */
                if( $pdf->load_iccprofile( ROOT_PATH . "/data/ISOcoated.icc", "usage=outputintent" ) == 0 ) {
                    printf( "Error: %s\n", $pdf->get_errmsg() );
                    echo( "Please install the ICC profile package from " .
                        "www.pdflib.com to run the PDF/VT-1 starter sample.\n" );
                    exit( 1 );
                }
            }
        }
        if($this->customPdfVersion && strpos($this->customPdfVersion, 'pdfx=PDF/X-4')!==false ){
            $pdf->set_info("Creator", "CloudLab");
            $pdf->set_info("Title", "Editor pdf");
            if ($pdf->load_iccprofile(ROOT_PATH . "/data/ISOcoated.icc", "usage=outputintent") == 0) {
                printf("Error: %s\n", $pdf->get_errmsg());
                echo("Please install the ICC profile package from " .
                    "www.pdflib.com to run the PDF/VT-1 starter sample.\n");
                exit(1);
            }
        }
    }

    protected function startPdfSchwarDownloadPdf($file, &$pdf, &$doc, $pdi = true, $pdfvt = false, $svg = false, $use_pdf_vt_otp = false, $usePdfDesiner = false)
    {

        $pdf = new \PDFlib();
        if (config('rest.pdf_license_key')) {
        	$pdf->set_option("license=" . config('rest.pdf_license_key'));
		}

        $pdf->set_option("errorpolicy=return");
        $pdf->set_option("stringformat=utf8");
        $pdf->set_option("searchpath={" . ROOT_PATH . $this->pdfSearchPath . "}");
        $pdf->set_option("searchpath={" . ROOT_PATH . $this->fontSearchPath . "}");

        $optlist = '';
        if ($pdfvt || $use_pdf_vt_otp) {
            $optlist = "pdfx=PDF/X-4";
        }

        if ( ! $pdf->begin_document('', $optlist)) {
            throw new \Exception('Error: ' . $pdf->get_errmsg());
        }


        $pdf->set_info("Creator", "CloudLab");
        $pdf->set_info("Title", "Stanzkontur");
        # Define output intent profile */
        if ($pdf->load_iccprofile(ROOT_PATH . "/data/ISOcoated_v2_eci.icc", "usage=outputintent") == 0) {
            printf("Error: %s\n", $pdf->get_errmsg());
            echo("Please install the ICC profile package from " .
                "www.pdflib.com to run the PDF/VT-1 starter sample.\n");
            exit(1);
        }


    }

    /**
     * @param $data
     * @param $pdf
     *
     * @throws \Exception
     */
    protected function loadFonts(&$data, $pdf, $pdfvt = false, $use_pdf_vt_otp = false,$use_pdvt_designer=false)
    {
        // check for provided fonts
        if (isset($data['fonts'])) {
            $fonts = array_unique($data['fonts']);
        } else {
            $fonts = array();
        }
        if ($use_pdf_vt_otp ||$use_pdvt_designer) {
            $fonts[] = 'Helvetica';
        }
        if (count($fonts)) {
            foreach ($fonts as $font) {
                if (file_exists(ROOT_PATH . $this->fontSearchPath . $font . '.ttf') || file_exists(ROOT_PATH . $this->fontSearchPath . $font . '.TTF')) {
                    if (file_exists(ROOT_PATH . $this->fontSearchPath . $font . '.ttf')) {
                        $pdf->set_option("FontOutline={" . $font . "=" . $font . ".ttf}");
                    } elseif (file_exists(ROOT_PATH . $this->fontSearchPath . $font . '.TTF')) {
                        $pdf->set_option("FontOutline={" . $font . "=" . $font . ".TTF}");
                    }
                    if ($pdfvt || $use_pdf_vt_otp ||$use_pdvt_designer) {
                        $pdf->load_font($font, "unicode", "embedding") or die (PDF_get_error($pdf));
                    }
                }
            }
        }
        // end fonts
    }

    protected function createShape($data, &$pdf, $doc, $watermark = false, $pdi = true)
    {
        if ($pdi) {
            $page   = $pdf->open_pdi_page($doc, 1, "cloneboxes");
            $width  = $pdf->pcos_get_number($doc, "pages[0]/width");
            $height = $pdf->pcos_get_number($doc, "pages[0]/height");
        } else {
            $width  = $data['shapeWidth'];
            $height = $data['shapeHeight'];
        }
        $pdf->begin_page_ext($width, $height, "");
        if ($pdi) {
            $pdf->fit_pdi_page($page, 0.0, 0.0, "cloneboxes");
        }

        $svg = tempnam("/tmp", "SVG");

        $this->filesToDelete[]  = $svg;
        file_put_contents($svg, $data['shape']);

        $graphics = $pdf->load_graphics("auto", $svg, "");
        if ($pdf->info_graphics($graphics, "fittingpossible", '') == 1) {
            $pdf->fit_graphics($graphics, 0, 0, '');
        } else {
            print_r($pdf->get_errmsg());
        }
        if ($watermark) {
            $this->_addWatermark($pdf, $data, $width, $height);
        }
        $pdf->end_page_ext("");
        if ($pdi) {
            $pdf->close_pdi_page($page);
        }
    }

    private function _addWatermark(&$pdf, &$data, $width, $height)
    {

        $wtm_text = 'Watermark';
        if (isset($data['watermark']['text']) && strlen($data['watermark']['text'])) {
            $wtm_text = $data['watermark']['text'];
        }
        $wtm_color = 'rgb 0 0 0';

        if (isset($data['watermark']['color']) && strlen($data['watermark']['color'])) {

            $additional = explode(" ", rtrim(trim($data['watermark']['color'])));
            if (is_array($additional) && count($additional) == 4) {
                $wtm_color = 'cmyk ' . $data['watermark']['color'];
            } else {
                $wtm_color = 'rgb ' . $data['watermark']['color'];
            }
        }
        $wtm_opacity = 9;
        if (isset($data['watermark']['opacity']) && strlen($data['watermark']['opacity']) && $data['watermark']['opacity'] < 10 && $data['watermark']['opacity'] >= 1) {
            $wtm_opacity = $data['watermark']['opacity'];
        }
        $pdf->set_option("FontOutline={Helvetica=Helvetica.ttf}");
        $wtm_fid = $pdf->load_font('Helvetica', "unicode", "embedding") or die (PDF_get_error($pdf));
        $wtm_limit = 0.8;
        $wtm_fsize = 150;
        if (isset($data['watermark']['size']) && strlen($data['watermark']['size'])) {
            $wtm_fsize = $data['watermark']['size'];
        }
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
        $tf = $pdf->create_textflow($wtm_text, "fontname=Helvetica fontsize=" . $wtm_fontsize . " encoding=unicode fillcolor={{$wtm_color}}");
        $pdf->fit_textflow($tf, $wtm_fontsize / 1.5, 0, $diagonal, $wtm_fontsize, "rotate=" . $rotate . " verticalalign=center");
    }

    private function pdf_calculateAngle($c, $a, $b)
    {
        $angleInRadians = acos((pow($a, 2) + pow($b, 2) - pow($c, 2)) / (2 * $a * $b));

        return rad2deg($angleInRadians);
    }

    private function getNewFontSize($pdf, $text, $fid, $fsize)
    {
        $_string_width = $pdf->stringwidth("$text", $fid, $fsize);

        return $_string_width;
    }

    protected function fillPdfVtBlocks(&$data, $page_length, &$pdf, $doc, $path, &$img, $watermark = false)
    {
        $image_cache = array();
        $pdfs_cache  = array();
        if (isset($data['csv_block_values_serialized'])) {
            $data['csv_block_values'] = unserialize($data['csv_block_values_serialized']);
        }
        if (isset($data['selection'])) {
            $selection = md5(preg_replace('/[^A-Za-z0-9\-]/', '', $data['selection']));
            if (file_exists($path . $selection . '.zip')) {
                $archive_name = $path . $selection . '.zip';
                $this->unzipImagesArchive($archive_name, $path);
            }
        }
        $pdf->begin_dpart("");
        $tmpBlock = array();
        if ( ! isset($data['csv_block_values'])) {
            $blocksList = array();
        }
        $count = 0;
        if (isset ($data ['pdfvt_source']) && isset ($data ['pdfvt_source'] ['count'])) {
            $count = ( int )$data ['pdfvt_source'] ['count'];
        }
        // save block headers from csv
        for ($j = 1; $j <= $count; $j++) {
            for ($i = 0; $i < $page_length; $i++) {
                if (isset($data['csv_block_values'])) {
                    $blocksList = array();
                }
                if ( ! isset($blocksList[$i])) {
                    if (isset($data['csv_block_values'])) {
                        $blocksList[$i] = $this->getBlockCount($pdf, $doc, $i, $data, $j);
                    } else {
                        $blocksList[$i] = $this->getBlockCount($pdf, $doc, $i, $data, $j);
                    }
                }

                $blocks = $blocksList[$i];

                if ( ! isset($tmpBlock[$i])) {
                    if (is_array($blocks) && count($blocks)) {
                        $tmpBlock[$i] = array();
                        foreach ($blocks as $block_key => $block_value) {
                            $block_id = $block_value ['name'];
                            if (isset($data[$block_id])) {
                                $tmpBlock[$i][$block_id] = $data[$block_id];
                            }
                        }
                    }
                }

                if ($tmpBlock[$i] && is_array($tmpBlock[$i]) && count($tmpBlock[$i])) {
                    foreach ($tmpBlock[$i] as $block_id => $block_value) {
                        if (isset($data['csv_block_values'])) {
                            if (isset($data['csv_block_values'][$j][$block_id])) {
                                $data[str_replace(' ', '_', $block_id)] = '';
                                $data[str_replace(' ', '_', $block_id)] = $data['csv_block_values'][$j][$block_id];
                            }
                        } else {
                            if (strlen($block_value)) {
                                $data[str_replace(' ', '_', $block_id)] = '';
                                $currentCSValue                         = explode(',', $block_value);

                                $tmpValues = array();
                                foreach ($currentCSValue as $val) {
                                    if (isset ($data ['pdfvt_source'] ['elements'] [trim($val)]) && isset ($data ['pdfvt_source'] ['elements'] [trim($val)] [$j - 1])) {
                                        $tmpValues[] = $data ['pdfvt_source'] ['elements'] [trim($val)] [$j - 1];
                                    }
                                    if ($val && strpos($val, "&#xBF;") !== false) {
                                        $val         = str_replace('&#xBF;', '', $val);
                                        $tmpValues[] = str_replace('&#x2C;', ',', $val);
                                    }
                                }
                                $data[str_replace(' ', '_', $block_id)] = implode(' ', $tmpValues);
                            }
                        }
                    }
                }

                $page   = $pdf->open_pdi_page($doc, $i + 1, "cloneboxes pdfvt={scope=global environment={Clodlab}}");
                $width  = $pdf->pcos_get_number($doc, "pages[$i]/width");
                $height = $pdf->pcos_get_number($doc, "pages[$i]/height");
                $pdf->begin_dpart("");
                $icc = $pdf->load_iccprofile("sRGB", "");
                $pdf->begin_page_ext($width, $height, "defaultrgb=" . $icc);
                //  $pdf->begin_page_ext( $width, $height, "" );
                $pdf->fit_pdi_page($page, 0.0, 0.0, "cloneboxes");
                $blocks = $this->checkEmpty($blocks);
                if ( ! empty ($blocks)) {
                    foreach ($blocks as $block) {
                        $options = '';
                        if (isset ($block ['reorder']) && $block ['reorder']) {
                            $options = " refpoint={" . ( float )($block ['x1_orig'] - $block ['crop_left']) . " " . ( float )($block ['y1'] - $block ['crop_buttom']) . "}" . " boxsize={" . ( float )($block ['x2_orig'] - $block ['x1_orig']) . " " . ( float )($block ['y2'] - $block ['y1']) . "}";
                        }
                        // try and get rid of the spaces in the block name
                        $blk  = str_replace(' ', '_', $block ['name']);
                        $text = isset ($data [$blk]) ? $data [$blk] : '';
                        // above might not work all the time
                        switch (strtolower($block ['type'])) {
                            case 'text' :
                                // check hypen
                                if (isset ($block ['custom'] ['Hyphen']) && $block ['custom'] ['Hyphen']) {
                                    $text = $this->_hyphen($text, $block);
                                }
                                $text    = str_replace(array('<br>', '<br />', '<br/>', '<BR>', '<BR />', '<BR/>', '\n'), "<nextline>", $text);
                                $options .= ' embedding ';
                                $ret     = $pdf->fill_textblock($page, $block ['name'], $text, $options);
                                if ($ret == 0) {
                                    echo $pdf->get_errmsg();
                                    exit ();
                                    printf("Warning: [%d] %s\n", $pdf->get_errnum(), $pdf->get_errmsg());
                                }
                                break;
                            case 'image' :
                                if ( ! isset ($data ['dependent_block_hidden_list'])) {
                                    $data ['dependent_block_hidden_list'] = array();
                                }
                                if ( ! in_array($block ['name'], $data ['dependent_block_hidden_list'])) {
                                    if (isset ($data ['image'] [$block ['name']])) {
                                        $img_path = $path . $data ['image'] [$block ['name']];
                                        if (file_exists($img_path)) {
                                            if (array_key_exists($img_path, $image_cache)) {
                                                $img = $image_cache[$img_path];
                                            } else {
                                                $img                    = $pdf->load_image('auto', $img_path, '');
                                                $image_cache[$img_path] = $img;
                                            }
                                            $pdf->fill_imageblock($page, $block ['name'], $img, $options);
                                        }
                                    } elseif (isset ($data ['local_images'] [$block ['name']])) {
                                        if (file_exists($path . $data ['local_images'] [$block ['name']])) {
                                            $img = $pdf->load_image('auto', $path . $data ['local_images'] [$block ['name']], '');
                                            $pdf->fill_imageblock($page, $block ['name'], $img, $options);
                                        }
                                    } elseif (isset($data['csv_block_values'][$j]['image'][$block['name']])) {
                                        $img_path = '';
                                        if (file_exists($path . $selection . "/" . basename($data['csv_block_values'][$j]['image'][$block['name']]))) {
                                            $img_path = $path . $selection . "/" . basename($data['csv_block_values'][$j]['image'][$block['name']]);
                                        } else {
                                            //$img_path = $path . $data['csv_block_values'][$j]['image'][$block['name']];
                                        }
                                        if (file_exists($img_path)) {
                                            if (array_key_exists($img_path, $image_cache)) {
                                                $img = $image_cache[$img_path];
                                            } else {
                                                $img                    = $pdf->load_image('auto', $img_path, '');
                                                $image_cache[$img_path] = $img;
                                            }
                                            $pdf->fill_imageblock($page, $block ['name'], $img, $options);
                                        }
                                    }
                                }
                                break;
                            case 'graphics' :
                                if (isset ($data ['image'] [$block ['name']])) {
                                    if (file_exists($path . $data ['image'] [$block ['name']])) {
                                        $img = $pdf->load_graphics('auto', $path . $data ['image'] [$block ['name']], '');
                                        $pdf->fill_graphicsblock($page, $block ['name'], $img, $options);
                                    }
                                } elseif (isset ($data ['local_images'] [$block ['name']])) {
                                    if (file_exists($path . $data ['local_images'] [$block ['name']])) {
                                        $img = $pdf->load_graphics('auto', $path . $data ['local_images'] [$block ['name']], '');
                                        $pdf->fill_graphicsblock($page, $block ['name'], $img, $options);
                                    }
                                } elseif (isset($data['csv_block_values'][$j]['image'][$block['name']])) {
                                    if (file_exists($path . $data['csv_block_values'][$j]['image'][$block['name']])) {
                                        $img = $pdf->load_graphics('auto', $path . $data['csv_block_values'][$j]['image'][$block['name']], '');
                                        $pdf->fill_graphicsblock($page, $block ['name'], $img, $options);
                                    }
                                }
                                break;
                            case 'pdf' :
                                if (isset ($data ['image'] [$block ['name']])) {
                                    if (file_exists($path . $data ['image'] [$block ['name']])) {
                                        $attach = $pdf->open_pdi_document($path . $data ['image'] [$block ['name']], '');
                                        if ($attach) {
                                            $newPage = $pdf->open_pdi_page($attach, 1, '');
                                            if ($newPage) {
                                                $pdf->fill_pdfblock($page, $block ['name'], $newPage, $options);
                                            }
                                        }
                                    }
                                } elseif (isset ($data ['local_images'] [$block ['name']])) {
                                    if (file_exists($path . $data ['local_images'] [$block ['name']])) {
                                        $attach = $pdf->open_pdi_document($path . $data ['local_images'] [$block ['name']], '');
                                        if ($attach) {
                                            $newPage = $pdf->open_pdi_page($attach, 1, '');
                                            if ($newPage) {
                                                $pdf->fill_pdfblock($page, $block ['name'], $newPage, $options);
                                            }
                                        }
                                    }
                                }
                                if (isset($data['csv_block_values'][$j][$block['name']])) {
                                    if (file_exists($path . $selection . "/" . basename($data['csv_block_values'][$j][$block['name']]))) {
                                        $pdf_path = $path . $selection . "/" . basename($data['csv_block_values'][$j][$block['name']]);
                                    } else {
                                        $pdf_path = $path . $data['csv_block_values'][$j][$block['name']];
                                    }
                                    if (file_exists($pdf_path)) {
                                        if (array_key_exists($pdf_path, $pdfs_cache)) {
                                            $attach = $pdfs_cache[$pdf_path];
                                        } else {
                                            $attach = $pdf->open_pdi_document($pdf_path, '');;
                                            $pdfs_cache[$pdf_path] = $attach;
                                        }
                                        if ($attach) {
                                            $newPage = $pdf->open_pdi_page($attach, 1, '');
                                            if ($newPage) {
                                                $pdf->fill_pdfblock($page, $block ['name'], $newPage, $options);
                                            }
                                        }
                                    }
                                }
                                break;
                        } // end switch
                    } // end foreach
                } // end if
                $pdf->end_page_ext("");
                $pdf->end_dpart("");
                $pdf->close_pdi_page($page);
            }    //end for pages
        }
        $pdf->end_dpart("");
        $this->deleteFiles($path . $selection);
    }

    protected function getBlockCount(&$pdf, $doc, $index, &$data, $pdfvtLine)
    {
        $return = array();
        try {
            $blocks      = $pdf->pcos_get_number($doc, "length:pages[$index]/blocks");
            $crop_left   = 0;
            $crop_buttom = 0;
            if ($pdf->pcos_get_string($doc, "type:pages[$index]/CropBox") != 'null') {
                $crop_left   = $pdf->pcos_get_string($doc, "pages[$index]/CropBox/[0]");
                $crop_buttom = $pdf->pcos_get_string($doc, "pages[$index]/CropBox/[1]");
            }
            if ($blocks) {
                for ($j = 0; $j < $blocks; $j++) {
                    $name        = $pdf->pcos_get_string($doc, "pages[$index]/blocks[$j]/Name");
                    $placeholder = str_replace(' ', '_', $name);

                    $isTextflow = $pdf->pcos_get_string($doc, "type:pages[$index]/blocks/" . $name . "/textflow") == "boolean" && $pdf->pcos_get_string($doc,
                            "pages[$index]/blocks/" . $name . "/textflow") == "true";
                    $block      = array(
                        'name'        => $name,
                        'type'        => $pdf->pcos_get_string($doc, "pages[$index]/blocks[$j]/Subtype"),
                        'x1'          => $pdf->pcos_get_string($doc, "pages[$index]/blocks[$j]/Rect[0]"),
                        'y1'          => $pdf->pcos_get_string($doc, "pages[$index]/blocks[$j]/Rect[1]"),
                        'x2'          => $pdf->pcos_get_string($doc, "pages[$index]/blocks[$j]/Rect[2]"),
                        'y2'          => $pdf->pcos_get_string($doc, "pages[$index]/blocks[$j]/Rect[3]"),
                        'x1_orig'     => $pdf->pcos_get_string($doc, "pages[$index]/blocks[$j]/Rect[0]"),
                        'y1_orig'     => $pdf->pcos_get_string($doc, "pages[$index]/blocks[$j]/Rect[1]"),
                        'x2_orig'     => $pdf->pcos_get_string($doc, "pages[$index]/blocks[$j]/Rect[2]"),
                        'y2_orig'     => $pdf->pcos_get_string($doc, "pages[$index]/blocks[$j]/Rect[3]"),
                        'value'       => isset($data[$placeholder]) ? $data[$placeholder] : '',
                        'crop_left'   => $crop_left,
                        'crop_buttom' => $crop_buttom
                    );
                    if ($pdf->pcos_get_string($doc, "pages[$index]/blocks[$j]/Subtype") == "Text" || $pdf->pcos_get_string($doc,
                            "pages[$index]/blocks[$j]/Subtype") == "Textflow") {
                        $block['fontsize'] = $pdf->pcos_get_string($doc, "pages[$index]/blocks[$j]/fontsize");
                        $block['fontname'] = $pdf->pcos_get_string($doc, "pages[$index]/blocks[$j]/fontname");
                    }
                    $custom = (int)$pdf->pcos_get_number($doc, "length:pages[$index]/blocks[$j]/Custom");
                    if (($custom > 0)) {
                        for ($k = 0; $k < $custom; $k++) {
                            $key                   = $pdf->pcos_get_string($doc, "pages[$index]/blocks[$j]/Custom[$k].key");
                            $value                 = $pdf->pcos_get_string($doc, "pages[$index]/blocks[$j]/Custom/" . $key);
                            $block['custom'][$key] = $value;
                        }
                    }
                    if (isset($block['custom']['StickMaster']) && $block['custom']['StickMaster']) {
                        if ($pdf->pcos_get_string($doc, "pages[$index]/blocks[$j]/Subtype") == "Text" || $pdf->pcos_get_string($doc,
                                "pages[$index]/blocks[$j]/Subtype") == "Textflow") {
                            $block['fontsize'] = $data[$name . '_properties']['fontsize'];
                            $block['fontname'] = $data[$name . '_properties']['fontname'];
                        }

                    }
                    //directsmile check
                    if (isset($block['custom']['personalization']) && $block['custom']['personalization']) {
                        $this->_setPersonalizationImage($block, $data);
                    }
                    //QR check
                    if (isset($block['custom']['QR']) && $block['custom']['QR']) {
                        if (isset($data['pdfvt']) && $data['pdfvt'] && isset($data['csv_block_values'])) {
                            $this->_setQRPdfVtdata($block, $data, $pdfvtLine);
                        } else {

                            $this->_setQRdata($block, $data);
                        }
                    }
                    if (isset($block['custom']['BarCode'])) {
                        $this->setBarCodeData($block, $data);
                    }
                    $return[] = $block;

                }
            }
        } catch (PDFlibException $e) {
            throw new \Exception($e->getMessage());
        } catch (\Exception $e) {
            throw new \Exception($e->getMessage());
        }

        return $return;
    }

    private function _setPersonalizationImage($block, &$data)
    {
        if (isset($block['custom']['personalizationSource']) && strlen($block['custom']['personalizationSource'])) {
            $sources = explode(';', $block['custom']['personalizationSource']);
            $text    = '';
            $id      = $block['custom']['personalization'];
            if (is_array($sources) && count($sources)) {
                foreach ($sources as $source) {
                    if (isset($data[$source])) {
                        $text .= $data[$source] . "\n";
                    }
                }
                if (strlen($text)) {
                    $file_name = $block['name'] . time();
                    $this->getPersonalizationFile($text, $file_name, $id);
                    $data['image'][$block['name']] = $file_name;
                }
            }
        }
        /*$file_name = $block['name'].time();
    	 $this->getQr($text, $file_name);
    	$data['image'][$block['name']] = $file_name;*/
    }

    private function getPersonalizationFile($text, $file_name = 'example.jpeg', $id = null)
    {
        $img_r = file_get_contents('http://api.imagepersonalization.com?set=' . $id . '&t=' . urlencode($text) . '&a=24C1F057A8877348E1C3C5C9AE3638F0');
        file_put_contents(ROOT_PATH . '/data/pdfs/tmp/' . $file_name, $img_r);
    }

    private function _setQRPdfVtdata($block, &$data, $j)
    {
        if ( ! empty($j)) {
            if (strlen($block['custom']['QRSource'])) {
                $sources    = explode(';', $block['custom']['QRSource']);
                $text       = '';
                $correction = 'H';
                $margin     = 4;
                if (is_array($sources) && count($sources)) {
                    foreach ($sources as $source) {
                        if (isset($data['csv_block_values'][$j][$source])) {
                            $text .= $data['csv_block_values'][$j][$source] . "\n";
                        }
                    }
                    if (strlen($text)) {
                        $file_name = $block['name'] . time() . "_" . $j;
                        $color     = false;
                        if (isset($block['custom']['QRColor'])) {
                            $color = $block['custom']['QRColor'];
                        }
                        if (isset($block['custom']['QRCorrection'])) {
                            $correction = $block['custom']['QRCorrection'];
                        }
                        if (isset($block['custom']['QRCorrectionMargin'])) {
                            $margin = $block['custom']['QRCorrectionMargin'];
                        }
                        file_put_contents(ROOT_PATH . '/data/pdfs/tmp/' . $file_name, $this->getQr($text, $color, $correction, $margin));
                        $data['csv_block_values'][$j]['image'][$block['name']] = $file_name;
                    }
                }
            }
        }
    }

    private function getQrrest3($text, $color = false, $correction = 'H', $margin = 4)
    {
        $ch = curl_init();
        if (isset($color)) {
            curl_setopt($ch, CURLOPT_URL,
                'http://chart.apis.google.com/chart?cht=qr&chs=500x500&choe=UTF-8&chl=' . urlencode($text) . '&chld=' . $correction . '|' . $margin . '&chco=' . $color);
        } else {
            curl_setopt($ch, CURLOPT_URL, 'http://chart.apis.google.com/chart?cht=qr&chs=500x500&choe=UTF-8&chl=' . urlencode($text) . '&chld=' . $correction . '|' . $margin);
        }
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_HEADER, 0);

        return curl_exec($ch);
    }

    private function cutQRmargin($image)
    {
        //load the image
        $img = imagecreatefrompng($image);
        //find the size of the border.
        $border = 0;
        while (imagecolorat($img, $border, $border) == 0xFFFFFF) {
            $border++;
        }

        //copy the contents, excluding the border
        //This code assumes that the border is the same size on all sides of the image.
        $newimg = imagecreatetruecolor(imagesx($img) - ($border * 2), imagesy($img) - ($border * 2));
        imagecopy($newimg, $img, 0, 0, $border, $border, imagesx($newimg), imagesy($newimg));

        //finally, overwrite the original image
        imagejpeg($newimg, $image);
    }

    private function getQr($text, $color = false, $correction = 'H', $margin = 4, $cmykfcolor = false, $cmykbcolor = false)
    {
        include_once ROOT_PATH . '/lib/phpqrcode/qrlib.php';

        $corr = QR_ECLEVEL_L;
        switch ($correction) {
            case 'L':
                $corr = QR_ECLEVEL_L;
                break;
            case 'M':
                $corr = QR_ECLEVEL_M;
                break;
            case 'Q':
                $corr = QR_ECLEVEL_Q;
                break;
            case 'H':
                $corr = QR_ECLEVEL_H;
                break;
        }

        $file = ROOT_PATH . '/data/svgs/tmp/' . uniqid('svg_') . '.svg';

        \QRcode::svg($text, $file, $corr, 1, $margin, false, 0xFFFFFF, $color ? hexdec($color) : 0x000000, $cmykfcolor, $cmykbcolor);

        $res = file_get_contents($file);

        return $res;
    }

    private function _setQRdata($block, &$data)
    {

        if (isset($block['custom']['QRSource']) && strlen($block['custom']['QRSource'])) {
            $sources = explode(';', $block['custom']['QRSource']);
            $text    = '';
            if (is_array($sources) && count($sources)) {
                foreach ($sources as $source) {
                    if (isset($data[$source]) && strlen($data[$source])) {
                        $text .= $data[$source] . "\n";
                    }
                }
                if (strlen($text)) {
                    $file_name  = $block['name'] . time();
                    $color      = false;
                    $correction = 'H';
                    $margin     = 4;

                    if (isset($block['custom']['QRCMYKFColor'])) {
                        $cmykfcolor = $block['custom']['QRCMYKFColor'];
                    } else {
                        $cmykfcolor = false;
                    }

                    if (isset($block['custom']['QRCMYKBColor'])) {
                        $cmykbcolor = $block['custom']['QRCMYKBColor'];
                    } else {
                        $cmykbcolor = false;
                    }

                    if (isset($block['custom']['QRColor'])) {
                        $color = $block['custom']['QRColor'];
                    }

                    if (isset($block['custom']['QRCorrection'])) {
                        $correction = $block['custom']['QRCorrection'];
                    }
                    if (isset($block['custom']['QRCorrectionMargin'])) {
                        $margin = $block['custom']['QRCorrectionMargin'];
                    }
                    $cutmargin = 0;
                    if (isset($block['custom']['QRCutMargin'])) {
                        $cutmargin = $block['custom']['QRCutMargin'];
                    }

                    if (isset($data['qr_rest3']) && $data['qr_rest3']) {
                        file_put_contents(ROOT_PATH . '/data/pdfs/tmp/' . $file_name, $this->getQrrest3($text, $color, $correction, $margin));
                    } else {
                        file_put_contents(ROOT_PATH . '/data/pdfs/tmp/' . $file_name, $this->getQr($text, $color, $correction, $margin, $cmykfcolor, $cmykbcolor));
                    }
                    //                        if( $cutmargin == 1 ) {
                    //                            $this->cutQRmargin( ROOT_PATH . '/data/pdfs/tmp/' . $file_name );
                    //                        }
                    $data['image'][$block['name']] = $file_name;
                }
            }
        }
    }


    //move blocks based on hidden blocks

    private function setBarCodeData($block, &$data)
    {
        if ( ! isset($data['dependent_block_hidden_list'])) {
            $data['dependent_block_hidden_list'] = array();
        }
        if (strlen($block['custom']['BarCodeSource']) && strlen($block['custom']['BarCode'])) {
            $sources = explode(';', $block['custom']['BarCodeSource']);
            $text    = '';
            if (is_array($sources) && count($sources)) {
                foreach ($sources as $source) {
                    if (isset($data[$source])) {
                        $text .= $data[$source] . " ";
                    }
                }
                if (strlen($text) && ! in_array($block['name'], $data['dependent_block_hidden_list'])) {
                    $file_name = $block['name'] . time();
                    if ($this->getBarCode($block['custom']['BarCode'], $text, $file_name)) {
                        $data['image'][$block['name']] = $file_name;
                    }
                }
            }
        }
    }

    //check blocks that are empty and need to be removed from page

    private function getBarCode($type, $text, $file_name = 'test.jpeg', $options = array())
    {
        $type = strtolower($type);
        switch ($type) {
            case 'code128':
                $ok = true;
                break;
            case 'code39':
                $ok = true;
                break;
            case 'code25interleaved':
                $ok = true;
                break;
            case 'ean13':
                $ok = true;
                break;
            case 'ean8':
                $ok = true;
                break;
            default:
                $ok = false;
                break;
        }
        if ( ! $ok) {
            return false;
        }

        require_once(ROOT_PATH . '/vendor/zendframework/zendframework/library/Zend/Barcode/Barcode.php');
        $text            = strtoupper($text);
        $barcodeOptions  = array('text' => $text);
		if (is_array($options) && count($options)){
			foreach($options as $option => $value){
				if ($option == 'font')
					continue;
				$barcodeOptions[$option] = $value;
			}
		}
		if(isset($options['font']) && ($options['font']!= null || $options['font'] != '')){
			$fontPath = ROOT_PATH . $this->fontSearchPath . $options['font'] . '.ttf';
			if (file_exists($fontPath))
				$barcodeOptions ['font'] = $fontPath;
		}
        $rendererOptions = array();
        $imageResource   = \Zend\Barcode\Barcode::draw(
            $type, 'image', $barcodeOptions, $rendererOptions
        );

        $tmp_folder = ROOT_PATH . '/data/pdfs/tmp/' . $file_name;
        imagejpeg($imageResource, $tmp_folder, 90);

        return true;
    }

    private function checkEmpty($blocks)
    {
        if (is_array($blocks) && count($blocks)) {
            $blocksLength = count($blocks);
            for ($i = 0; $i < $blocksLength; $i++) {
                $value = $blocks[$i];
                if ( ! strlen($value['value'])) {
                    if (isset($value['custom']['HideLineUp'])) {
                        $this->moveBlocks($blocks, $value);
                    }

                    if (isset($value['custom']['HideLineDown'])) {
                        $this->moveBlocks($blocks, $value, true);
                    }
                }
            }
        }

        return $blocks;
    }

    private function getAnchors($blocks)
    {
        $anchors   = array();
        $positions = array();
        if (is_array($blocks) && count($blocks)) {
            $blocksLength = count($blocks);
            for ($i = 0; $i < $blocksLength; $i++) {
                $value = $blocks[$i];
                if (isset($value['custom']) && isset($value['custom']['AnchorTarget'])) {
                    if (strlen($value['custom']['AnchorTarget'])) {
                        $anchors[] = $value['custom']['AnchorTarget'];
                    }
                }
                $positions[$value['name']]['y1'] = $value['y1'];
                $positions[$value['name']]['y2'] = $value['y2'];
            }
        }
        $result['anchors']   = $anchors;
        $result['positions'] = $positions;
        return $result;
    }

    private function moveBlocks(&$blocks, $block, $reverse = false)
    {
        $targetUp   = array();
        $targetDown = array();
        if (isset($block['custom']['HideLineUpTarget']) && strlen($block['custom']['HideLineUpTarget'])) {
            $targetUp = explode(',', $block['custom']['HideLineUpTarget']);
        }
        if (isset($block['custom']['HideLineDownTarget']) && strlen($block['custom']['HideLineDownTarget'])) {
            $targetDown = explode(',', $block['custom']['HideLineDownTarget']);
        }
        foreach ($blocks as $key => $value) {
            //if block starts higher then current block && is between current block
            $condition       = ((( ! $reverse && $value['y2'] <= $block['y1']) || ($reverse && $value['y2'] >= $block['y1'])) && $block['x1'] < $value['x2'] && $block['x2'] > $value['x1'] && ! isset($value['custom']['Freeze']));
            $contitionTarget = (( ! $reverse && in_array($value['name'], $targetUp)) || ($reverse && in_array($value['name'], $targetDown)));

            if (count($targetUp) || count($targetDown)) {
                $condition = $contitionTarget;
            }

            if ($condition) {
                //check if a exiting interval
                if (isset($blocks[$key]['intervals']) && $interval = $this->_checkInternals($blocks[$key]['intervals'], $block['y1_orig'], $block['y2_orig'])) {
                    //size of old block
                    $size = (float)abs($interval[1] - $interval[0]);
                } else {
                    //size of old block
                    $size = (float)($block['y2'] - $block['y1']);
                }


                $blocks[$key]['reorder'] = true;
                if ($reverse) {
                    //move down
                    $blocks[$key]['y1'] = $value['y1'] - $size;
                    $blocks[$key]['y2'] = $blocks[$key]['y2'] - $size;
                } else {
                    //move up
                    $blocks[$key]['y1'] = $value['y1'] + $size;
                    $blocks[$key]['y2'] = $blocks[$key]['y2'] + $size;
                }
                $blocks[$key]['x1'] = $value['x1'];
                $blocks[$key]['x2'] = $value['x2'];

                //save intervals where block was moved..to check for other blocks also
                $blocks[$key]['intervals'][] = array($block['y1_orig'], $block['y2_orig']);
            }
        }
    }

    private function _checkInternals($intervals, $y1, $y2)
    {
        if (is_array($intervals) && count($intervals)) {

            foreach ($intervals as $key => $value) {
                //if current block shares the position with another block that has been moved
                if ($y1 < $value[1] && $y2 > $value[0]) {
                    if ($y2 > $value[1]) {
                        return array($y1, $value[0]);
                    }

                    return array($y2, $value[1]);
                }
            }
        }

        return false;
    }

    private function _hyphen($text, $block)
    {
        if (strlen($text)) {
            require_once(ROOT_PATH . '/lib/Org_Heigl_Hyphenator/src/Org/Heigl/Hyphenator/Hyphenator.php');
            \Org\Heigl\Hyphenator\Hyphenator::registerAutoload();

            $words = explode(" ", $text);
            if (is_array($words) && count($words)) {
                $hyphenator = \Org\Heigl\Hyphenator\Hyphenator::factory(null, 'de_DE');
                $hyphenator->getOptions()
                    ->setHyphen("\xC2\xAD")
                    ->setLeftMin(2)
                    ->setRightMin(2)
                    ->setWordMin(2)
                    ->setQuality(5);

                foreach ($words as $key => $word) {
                    $words[$key] = $hyphenator->hyphenate($word);
                }

                return implode(' ', $words);
            }
        }

        return $hyphenated;
    }

    /**
     * @param $datata
     * @param $page_length
     * @param $pdf
     * @param $doc
     * @param $path
     * @param $img
     */
    protected function fillPdfBlocks(&$data, $page_length, &$pdf, $doc, $path, &$img, $watermark = false)
    {
        $html5Editor      = 0;
        $editorVersion    = '0.0';
        $allow_blockorder = false;
        $filesToBeDeleted = array();
        $activateExclude  = (isset($data['activateExclude']) && (int)$data['activateExclude']) ? 1 : 0;
        $inline           = (isset($data['allow_inline_editing']) && (int)$data['allow_inline_editing']) ? 1 : 0;
        $use_pdf_vt_otp   = (isset($data['use_pdf_vt_otp']) && $data['use_pdf_vt_otp']) ? 1 : 0;
        $useSmallImages   = isset($data['useSmallImages']) && $data['useSmallImages'] ? 1 : 0;
        $count            = 1;
        /*apo get from data page length*/
        $page_length = count($data['pages_pdf']);
        if (isset($data['html5Editor']) && (int)$data['html5Editor']) {
            $html5Editor = 1;
        }
        if ($use_pdf_vt_otp) {
            if (isset($data['pdf_vt_values']) && count($data['pdf_vt_values'])) {
                $this->csv_block_values = $data['pdf_vt_values'];
            }
            if (isset($data['pdvt_count']) && $data['pdvt_count']) {
                $count = $data['pdvt_count'];
            }
        }
        if (isset($data['editorVersion']) && (int)$data['editorVersion']) {
            $editorVersion = $data['editorVersion'];
        }
        if (isset($data['allow_blockorder']) && (int)$data['allow_blockorder']) {
            $allow_blockorder = $data['allow_blockorder'];
        }
        if (isset($data['renderTables']) && strlen($data['renderTables'])) {
            $data['renderTables'] = unserialize($data['renderTables']);
        }
        //loop over all pages to generate the document, fill blocks
        if (isset($data['new_blocks']) && $html5Editor) {
            $data['new_blocks'] = json_decode($data['new_blocks'], true);
        } else {
            $data['new_blocks'] = array();
        }
        $distanceToMove = array();
        if ($use_pdf_vt_otp) {
            $pdf->begin_dpart("");
        }
        for ($j = 1; $j <= $count; $j++) {
            for ($i = 0; $i < $page_length; $i++) {
                $pageData       = $data['pages_pdf'][$i];
                $width          = $pageData['width'];
                $height         = $pageData['height'];
                $optListTrimbox = "";
                if (isset($pageData['boxes'])) {
                    if (isset($pageData['boxes']['trimbox']) && is_array($pageData['boxes']['trimbox'])) {
                        $box            = $pageData['boxes']['trimbox'];
                        $optListTrimbox = " trimbox={" . $box['left'] . " " . $box['bottom'] . " " . ($width - $box['right']) . " " . ($height - $box['top']) . "}";
                    }
                }
                if ($use_pdf_vt_otp) {
                    $icc            = $pdf->load_iccprofile("sRGB", "");
                    $optListTrimbox .= " defaultrgb=" . $icc;
                }
                if ($use_pdf_vt_otp) {
                    $pdf->begin_dpart("");
                }

                $pdf->begin_page_ext($width, $height, $optListTrimbox);
                $blocks = [];
                if (is_array($data['new_blocks']) && count($data['new_blocks'])) {
                    if (isset($data['new_blocks'][$i])) {
                        $blocks = array_merge($blocks, $data['new_blocks'][$i]);
                    }
                }
                if ($html5Editor && version_compare($editorVersion, '0.0') && $allow_blockorder) {
                    $blocks = $this->reorderBlocks($blocks, $data);
                }

                if ( ! empty($blocks)) {
                    foreach ($blocks as $block) {
                        if (isset($data[$block['name'] . '_properties']['deleted']) && (int)$data[$block['name'] . '_properties']['deleted']) {
                            continue;
                        }
                        if ($activateExclude && isset($data[$block['name'] . '_properties']['excluded']) && (int)$data[$block['name'] . '_properties']['excluded']) {
                            continue;
                        }
                        $options         = '';
                        $optionsTextflow = '';
                        $is_textflow     = (isset($block['type']) && strtolower($block['type']) == "textflow") ? true : false;
                        $is_circle       = isset($data[$block['name'] . '_properties']['circletext']) && $data[$block['name'] . '_properties']['circletext'] ? true : false;
                        $addtional_layer = isset($data[$block['name'] . '_properties']['additional_layer']) && $data[$block['name'] . '_properties']['additional_layer'] ? true : false;
                        if ( ! $is_textflow) {
                            if (isset($data[$block['name'] . '_properties']['width'])) {
                                $options .= " boxsize={" . (float)($data[$block['name'] . '_properties']['width']) . " " . (float)($data[$block['name'] . '_properties']['height']) . "}";
                            }
                        }
                        if ($is_textflow) {
                            $optionsTextflow = " rotate=" . (float)($data[$block['name'] . '_properties']['rotateAngle']);
                        } else {
                            if ( ! $is_circle && ! $addtional_layer) {
                                $options .= " rotate=" . (float)($data[$block['name'] . '_properties']['rotateAngle']);
                            }
                        }
                        // try and get rid of the spaces in the block name
                        $blk  = str_replace(' ', '_', $block['name']);
                        $text = isset($data[$blk]) ? $data[$blk] : '';

                        $pattern = '/(.*)(%(.*[^%])%)(.*)/U';
                        if ($use_pdf_vt_otp) {
                            $this->current_line = $j - 1;

                            $text = preg_replace_callback($pattern, array(
                                $this,
                                'replace_callback'
                            ), $text, -1);
                        }
                        $text = ($text);
                        if (isset($data['html5Editor']) && (int)$data['html5Editor']) {

                            /*$text = str_ireplace('</div><div>', "<br>", $text);
							$text = str_ireplace('<div>', "<br>", $text);
							$text = str_ireplace('</div>', "", $text);*/
                            $text   = str_ireplace('<p><br></p>', "<br>", $text);
                            $text   = str_ireplace('</p><br>', "<br>", $text);
                            $text   = str_ireplace('</p>', "<br>", $text);
                            $text   = str_ireplace('<p>', "", $text);
                            $text   = str_ireplace('&nbsp;', " ", $text);
                            $text   = preg_replace('#<br\s*/?>#i', "\n", $text);
                            $inline = isset($data[$block['name'] . '_properties']['inline']) ? $data[$block['name'] . '_properties']['inline'] : $inline;

                            if ( ! $inline) {
                                $text = strip_tags($text);
                            }
                        }
                        // above might not work all the time

                        if (isset($data[$block['name'] . '_properties']['text_block_type']) && in_array($data[$block['name'] . '_properties']['text_block_type'], array(
                                'text',
                                'textflow'
                            ))
                        ) {
                            $block_prop = $data[$block['name'] . '_properties'];
                            $params_bck = array('color' => false, 'device' => false, 'block_prop' => $block_prop);
                            if (isset($block_prop['backgroundcolor']) && strlen($block_prop['backgroundcolor'])) {
                                if (isset($block_prop['bgcolorspace']) && strlen($block_prop['bgcolorspace'])) {

                                    switch ($block_prop['bgcolorspace']) {

                                        case 'None':
                                            break;
                                        case 'DeviceRGB':
                                            $params_bck['device'] = "rgb";
                                            $params_bck['color']  = $block_prop['backgroundcolor'];
                                            break;
                                        case 'DeviceCMYK' :
                                            $params_bck['device'] = "cmyk";
                                            $params_bck['color']  = $block_prop['backgroundcolor'];
                                            break;
                                        case 'Separation':
                                            $params_bck['color']  = '{' . $block_prop['bordercolor'] . '} ' . $block_prop['bordercolor_tint'];
                                            $params_bck['device'] = "spotname";
                                            if (stripos($block_prop['backgroundcolor'], 'pantone ') === false && stripos($block_prop['backgroundcolor'], 'hks ') === false) {
                                                if (isset($block_prop['bgseparation_colorspace']) && strlen($block_prop['bgseparation_colorspace'])) {
                                                    if (isset($block_prop['bgseparation_color']) && strlen($block_prop['bgseparation_color'])) {

                                                        switch ($block_prop['bgseparation_colorspace']) {
                                                            case 'DeviceRGB':
                                                                $params_bck['color'] .= ' {rgb ' . $block_prop['bgseparation_color'] . '}';
                                                                break;
                                                            case 'DeviceCMYK' :
                                                                $params_bck['color'] .= ' {cmyk ' . $block_prop['bgseparation_color'] . '}';
                                                                break;
                                                        }
                                                    }
                                                }
                                            }
                                            break;
                                    }
                                }
                            }

                            $borderwidth = 0;
                            if (isset($block_prop['borderwidth'])) {
                                $borderwidth = (float)$block_prop['borderwidth'];
                            }
                            $params_border = array(
                                'device'     => false,
                                'color'      => false,
                                'block_prop' => $block_prop,
                                'line_width' => $borderwidth
                            );
                            if ((float)$borderwidth > 0) {
                                if (isset($block_prop['bordercolor']) && strlen($block_prop['bordercolor'])) {
                                    if (isset($block_prop['bordercolorspace']) && strlen($block_prop['bordercolorspace'])) {
                                        switch ($block_prop['bordercolorspace']) {
                                            case 'None':
                                                break;
                                            case 'DeviceRGB':
                                                $params_border['color']  = $block_prop['bordercolor'];
                                                $params_border['device'] = "rgb";
                                                break;
                                            case 'DeviceCMYK' :
                                                $params_border['color']  = $block_prop['bordercolor'];
                                                $params_border['device'] = "cmyk";
                                                break;
                                            case 'Separation':
                                                $params_border['color']  = '{' . $block_prop['bordercolor'] . '} ' . $block_prop['bordercolor_tint'];
                                                $params_border['device'] = "spotname";
                                                if (stripos($block_prop['bordercolor'], 'pantone ') === false && stripos($block_prop['bordercolor'], 'hks ') === false) {
                                                    if (isset($block_prop['borderseparation_colorspace']) && strlen($block_prop['borderseparation_colorspace'])) {
                                                        if (isset($block_prop['borderseparation_color']) && strlen($block_prop['borderseparation_color'])) {

                                                            switch ($block_prop['borderseparation_colorspace']) {
                                                                case 'DeviceRGB':
                                                                    $params_border['color'] .= ' {rgb ' . $block_prop['borderseparation_color'] . '}';
                                                                    break;
                                                                case 'DeviceCMYK' :
                                                                    $params_border['color'] .= ' {cmyk ' . $block_prop['borderseparation_color'] . '}';
                                                                    break;
                                                            }
                                                        }
                                                    }
                                                }
                                                break;
                                        }
                                    }
                                }
                            }

                            //draw border and rectangle

                            if (isset($params_bck) && ! $is_circle) {
                                if ($params_bck['color'] && $params_bck['device']) {
                                    $this->drawRectangle($pdf, $params_bck);
                                }
                            }
                            if (isset($data[$block['name'] . '_properties']) && isset($data[$block['name'] . '_properties']['prefix']) && strlen($data[$block['name'] . '_properties']['prefix']) > 0) {
                                $prefix = $data[$block['name'] . '_properties']['prefix'];
                                $text   = $prefix . $text;
                            }

                            if (isset($data[$block['name'] . '_properties']['fontname']) && strlen($data[$block['name'] . '_properties']['fontname']) > 0) {
                                $options .= ' fontname={' . $data[$block['name'] . '_properties']['fontname'] . '} ';
                            }
                            if (isset($data[$block['name'] . '_properties']['fontname']) && $data[$block['name'] . '_properties']['fontname'] != 'Helvetica') {
                                $options .= ' embedding ';
                            }
                            if (isset($data[$block['name'] . '_properties']['fontsize']) && strlen($data[$block['name'] . '_properties']['fontsize']) > 0) {
                                $options .= ' fontsize=' . ($data[$block['name'] . '_properties']['fontsize']);
                            }

                            if (isset($block_prop['fillcolor']) && strlen($block_prop['fillcolor'])) {
                                if (isset($block_prop['colorspace']) && strlen($block_prop['colorspace'])) {
                                    switch ($block_prop['colorspace']) {
                                        case 'DeviceRGB':
                                            $options .= ' fillcolor={rgb ' . $block_prop['fillcolor'] . '}';
                                            $options .= ' strokecolor={rgb ' . $block_prop['fillcolor'] . '}';
                                            break;
                                        case 'DeviceCMYK' :
                                            $options .= ' fillcolor={cmyk ' . $block_prop['fillcolor'] . '}';
                                            $options .= ' strokecolor={cmyk ' . $block_prop['fillcolor'] . '}';
                                            break;
                                        case 'Separation':
                                            $options .= ' fillcolor={spotname {' . $block_prop['fillcolor'] . '} ' . $block_prop['fontcolor_tint'];

                                            if (stripos($block_prop['fillcolor'], 'pantone ') === false && stripos($block_prop['fillcolor'], 'hks ') === false) {
                                                if (isset($block_prop['fontseparation_colorspace']) && strlen($block_prop['fontseparation_colorspace'])) {
                                                    if (isset($block_prop['fontseparation_color']) && strlen($block_prop['fontseparation_color'])) {

                                                        switch ($block_prop['fontseparation_colorspace']) {
                                                            case 'DeviceRGB':
                                                                $options .= ' {rgb ' . $block_prop['fontseparation_color'] . '}';
                                                                break;
                                                            case 'DeviceCMYK' :
                                                                $options .= ' {cmyk ' . $block_prop['fontseparation_color'] . '}';
                                                                break;
                                                        }
                                                    }
                                                }
                                            }
                                            $options .= ' } ';
                                            $options .= ' strokecolor={spotname {' . $block_prop['fillcolor'] . '} ' . $block_prop['fontcolor_tint'];
                                            if (stripos($block_prop['fillcolor'], 'pantone ') === false && stripos($block_prop['fillcolor'], 'hks ') === false) {
                                                if (isset($block_prop['fontseparation_colorspace']) && strlen($block_prop['fontseparation_colorspace'])) {
                                                    if (isset($block_prop['fontseparation_color']) && strlen($block_prop['fontseparation_color'])) {
                                                        switch ($block_prop['fontseparation_colorspace']) {
                                                            case 'DeviceRGB':
                                                                $options .= ' {rgb ' . $block_prop['fontseparation_color'] . '}';
                                                                break;
                                                            case 'DeviceCMYK' :
                                                                $options .= ' {cmyk ' . $block_prop['fontseparation_color'] . '}';
                                                                break;
                                                        }
                                                    }
                                                }
                                            }
                                            $options .= ' } ';
                                            break;
                                    }
                                }
                            }

                            if (isset($data[$block['name'] . '_properties']['underline']) && '1' == $data[$block['name'] . '_properties']['underline']) {
                                $options .= ' underline';
                            }
                            if (isset($data[$block['name'] . '_properties']['charspacing']) && (int)$data[$block['name'] . '_properties']['charspacing']) {
                                $options .= ' charspacing=' . $data[$block['name'] . '_properties']['charspacing'];
                            }
                            if (isset($data[$block['name'] . '_properties']['underline']) && '0' == $data[$block['name'] . '_properties']['underline']) {
                                $options .= ' underline=false';
                            }

                            $fontstyle = '';
                            if ('1' !== $data[$block['name'] . '_properties']['bold'] && '1' != $data[$block['name'] . '_properties']['italic']) {
                                $fontstyle = 'normal';
                            }
                            if (isset($data[$block['name'] . '_properties']['bold']) && '1' == $data[$block['name'] . '_properties']['bold']) {
                                $fontstyle = 'bold';
                            }
                            if (isset($data[$block['name'] . '_properties']['italic']) && '1' == $data[$block['name'] . '_properties']['italic']) {
                                $fontstyle .= 'italic';
                            }
                            if (strlen($fontstyle) > 0) {
                                $options .= ' fontstyle=' . $fontstyle;
                            }
                            if (isset($data[$block['name'] . '_properties']['text_block_type']) && $data[$block['name'] . '_properties']['text_block_type'] == 'text') {
                                if ( ! $is_circle) {
                                    $position0 = '0';
                                    $position1 = '0';
                                    if (isset($data[$block['name'] . '_properties']['alignment']) && strlen($data[$block['name'] . '_properties']['alignment']) > 0) {
                                        if ($data[$block['name'] . '_properties']['alignment'] == 'center') {
                                            $position0 = '50';
                                        }
                                        if ($data[$block['name'] . '_properties']['alignment'] == 'right') {
                                            $position0 = '100';
                                        }
                                    }
                                    if (isset($data[$block['name'] . '_properties']['valignment']) && strlen($data[$block['name'] . '_properties']['valignment']) > 0) {
                                        if ($data[$block['name'] . '_properties']['valignment'] == 'middle') {
                                            $position1 = '50';
                                        }
                                        if ($data[$block['name'] . '_properties']['valignment'] == 'top') {
                                            $position1 = '100';
                                        }
                                    }
                                    $options .= ' position={' . $position0 . ' ' . $position1 . '}';
                                }
                                if (isset($data[$block['name'] . '_properties']['circletext']) && $data[$block['name'] . '_properties']['circletext']) {
                                    $radius             = $data[$block['name'] . '_properties']['circletextradius'];
                                    $circletextposition = $data[$block['name'] . '_properties']['circletextposition'];
                                    if ($circletextposition) {
                                        if (isset($data[$block['name'] . '_properties']['rotateAngle'])) {
                                            $rotate = $data[$block['name'] . '_properties']['rotateAngle'];
                                        }
                                        //add 180 degress to correct the start point
                                        $rotate = (-180 - (-1) * ($rotate)) % 360;
                                        //here we have the start point
                                        $x = $radius + $radius * cos(deg2rad($rotate));
                                        $y = $radius + $radius * sin(deg2rad($rotate));
                                        //starting point
                                        $path_circle = $pdf->add_path_point(0, $x, $y, "move", "");
                                        // calculate the next point on axes
                                        $path_circle = $pdf->add_path_point($path_circle, $radius + $radius * cos(deg2rad(($rotate + 90))),
                                            $radius + $radius * sin(deg2rad(($rotate + 90))), "control", "");
                                        $path_circle = $pdf->add_path_point($path_circle, $radius + $radius * cos(deg2rad($rotate + 180)),
                                            $radius + $radius * sin(deg2rad($rotate + 180)), "circular", "");
                                        $path_circle = $pdf->add_path_point($path_circle, $radius + $radius * cos(deg2rad($rotate + 270)),
                                            $radius + $radius * sin(deg2rad($rotate + 270)), "control", "");
                                        $path_circle = $pdf->add_path_point($path_circle, $x, $y, "circular", "");
                                        $position    = "position={" . $rotate . " bottom}";
                                    } else {
                                        if (isset($data[$block['name'] . '_properties']['rotateAngle'])) {
                                            $rotate = $data[$block['name'] . '_properties']['rotateAngle'];
                                        }
                                        //add 180 degress to correct the start point
                                        $rotate = (-180 - (-1) * ($rotate)) % 360;
                                        //here we have the start point
                                        $x = $radius + $radius * cos(deg2rad($rotate));
                                        $y = $radius + $radius * sin(deg2rad($rotate));
                                        //starting point
                                        $path_circle = $pdf->add_path_point(0, $x, $y, "move", "");
                                        // calculate the next point on axes
                                        $path_circle = $pdf->add_path_point($path_circle, $radius + $radius * cos(deg2rad(($rotate - 90))),
                                            $radius + $radius * sin(deg2rad(($rotate - 90))), "control", "");
                                        $path_circle = $pdf->add_path_point($path_circle, $radius + $radius * cos(deg2rad($rotate - 180)),
                                            $radius + $radius * sin(deg2rad($rotate - 180)), "circular", "");
                                        $path_circle = $pdf->add_path_point($path_circle, $radius + $radius * cos(deg2rad($rotate - 270)),
                                            $radius + $radius * sin(deg2rad($rotate - 270)), "control", "");
                                        $path_circle = $pdf->add_path_point($path_circle, $x, $y, "circular", "");
                                        $position    = "position={0 top}";
                                    }

                                    if (isset($data['center_circle']) && $data['center_circle']) {
                                        if ($circletextposition) {
                                            $rotate =0;
                                            if (isset($data[$block['name'] . '_properties']['rotateAngle'])) {
                                                $rotate = $data[$block['name'] . '_properties']['rotateAngle'];
                                            }
                                            //add 180 degress to correct the start point
                                            $rotate = (-90 - (-1) * ($rotate)) % 360;
                                            //here we have the start point
                                            $x = $radius + $radius * cos(deg2rad($rotate));
                                            $y = $radius + $radius * sin(deg2rad($rotate));
                                            //starting point
                                            $path_circle = $pdf->add_path_point(0, $x, $y, "move", "");
                                            // calculate the next point on axes
                                            $path_circle = $pdf->add_path_point($path_circle, $radius + $radius * cos(deg2rad(($rotate + 90))),
                                                $radius + $radius * sin(deg2rad(($rotate + 90))), "control", "");
                                            $path_circle = $pdf->add_path_point($path_circle, $radius + $radius * cos(deg2rad($rotate + 180)),
                                                $radius + $radius * sin(deg2rad($rotate + 180)), "circular", "");
                                            $path_circle = $pdf->add_path_point($path_circle, $radius + $radius * cos(deg2rad($rotate + 270)),
                                                $radius + $radius * sin(deg2rad($rotate + 270)), "control", "");
                                            $path_circle = $pdf->add_path_point($path_circle, $x, $y, "circular", "");
                                            $position = "position={center bottom}";
                                        } else {
                                            //add 180 degress to correct the start point
                                            $rotate =0;
                                            if (isset($data[$block['name'] . '_properties']['rotateAngle'])) {
                                                $rotate = $data[$block['name'] . '_properties']['rotateAngle'];
                                            }
                                            $rotate = (-90 - (-1) * ($rotate)) % 360;
                                            //here we have the start point
                                            $x = $radius + $radius * cos(deg2rad($rotate));
                                            $y = $radius + $radius * sin(deg2rad($rotate));
                                            //starting point
                                            $path_circle = $pdf->add_path_point(0, $x, $y, "move", "");
                                            // calculate the next point on axes
                                            $path_circle = $pdf->add_path_point($path_circle, $radius + $radius * cos(deg2rad(($rotate - 90))),
                                                $radius + $radius * sin(deg2rad(($rotate - 90))), "control", "");
                                            $path_circle = $pdf->add_path_point($path_circle, $radius + $radius * cos(deg2rad($rotate - 180)),
                                                $radius + $radius * sin(deg2rad($rotate - 180)), "circular", "");
                                            $path_circle = $pdf->add_path_point($path_circle, $radius + $radius * cos(deg2rad($rotate - 270)),
                                                $radius + $radius * sin(deg2rad($rotate - 270)), "control", "");
                                            $path_circle = $pdf->add_path_point($path_circle, $x, $y, "circular", "");
                                            $position = "position={center top}";
                                        }
                                        $options .= " textpath={path=" . $path_circle . "} " . $position;
                                    } else {
                                        $options .= " textpath={path=" . $path_circle . "} " . $position;
                                    }
                                    if ($params_bck['device']) {
                                        $new_color = explode(" ", $params_bck['color']);
                                        $pdf->setcolor("fill", $params_bck['device'], $new_color[0], $new_color[1], $new_color[2], $new_color[3]);
                                        $pdf->draw_path($path_circle, $data[$block['name'] . '_properties']['leftRotate'], $data[$block['name'] . '_properties']['topRotate'],
                                            "fill");
                                    }
                                    if ($params_border['device']) {
                                        $pdf->setlinewidth($params_border['line_width']);
                                        $new_color = explode(" ", $params_border['color']);
                                        $pdf->setcolor("stroke", $params_border['device'], $new_color[0], $new_color[1], $new_color[2], $new_color[3]);
                                        $pdf->draw_path($path_circle, $data[$block['name'] . '_properties']['leftRotate'], $data[$block['name'] . '_properties']['topRotate'],
                                            "stroke");
                                    }
                                }
                            }
                            if ((isset($data[$block['name'] . '_properties']['text_block_type']) && $data[$block['name'] . '_properties']['text_block_type'] == 'textflow')) {
                                if (isset($data['html5Editor']) && (int)$data['html5Editor'] && isset($data[$block['name'] . '_properties']['lineHeight']) && (float)$data[$block['name'] . '_properties']['lineHeight']) {
                                    $options .= ' leading=' . $data[$block['name'] . '_properties']['lineHeight'];
                                }
                                if (isset($data[$block['name'] . '_properties']['alignment']) && strlen($data[$block['name'] . '_properties']['alignment']) > 0) {
                                    $options .= ' alignment=' . $data[$block['name'] . '_properties']['alignment'];
                                    if ($data[$block['name'] . '_properties']['alignment'] == 'justify') {
                                        $options .= ' maxspacing=5000 ';
                                    }
                                }
                                if (isset($data[$block['name'] . '_properties']['valignment']) && strlen($data[$block['name'] . '_properties']['valignment']) > 0) {
                                    $verticalalign = $data[$block['name'] . '_properties']['valignment'];
                                    if ($data[$block['name'] . '_properties']['valignment'] == 'middle') {
                                        $verticalalign = 'center';
                                    }
                                    if (in_array($verticalalign, array(
                                        'top',
                                        'center',
                                        'bottom',
                                        'justify'
                                    ))) {
                                        if ($is_textflow) {
                                            $optionsTextflow .= ' verticalalign=' . $verticalalign;
                                        } else {
                                            $options .= ' verticalalign=' . $verticalalign;
                                        }
                                    }
                                }
                                /*fix from fontsConfiguration otp*/
                                if (isset($data['html5Editor']) && (int)$data['html5Editor']) {
                                    if (isset($data[$block['name'] . '_properties']['lastlinedist']) && strlen($data[$block['name'] . '_properties']['lastlinedist'])) {
                                        if ($is_textflow) {
                                            $optionsTextflow .= ' lastlinedist=' . $data[$block['name'] . '_properties']['lastlinedist'];
                                        } else {
                                            $options .= ' lastlinedist=' . $data[$block['name'] . '_properties']['lastlinedist'];
                                        }
                                    }
                                    if (isset($data[$block['name'] . '_properties']['firstlinedist']) && strlen($data[$block['name'] . '_properties']['firstlinedist'])) {
                                        if ($is_textflow) {
                                            $optionsTextflow .= ' firstlinedist=' . $data[$block['name'] . '_properties']['firstlinedist'];
                                        } else {
                                            $options .= ' firstlinedist=' . $data[$block['name'] . '_properties']['firstlinedist'];
                                        }
                                    }
                                }
                                /**/
                            }
                        }
                        if (isset($data[$block['name'] . '_properties']['image_block_type']) && $data[$block['name'] . '_properties']['image_block_type'] == 'image') {
                            if (isset($data['html5Editor']) && (int)$data['html5Editor']) {
                                $options .= ' fitmethod=entire';//force fiting all image in the block; otherwise will apear white top and left margin
                            }
                        }
                        $opacity = false;
                        if (isset($data[$block['name'] . '_properties']['opacity']) && strlen(trim($data[$block['name'] . '_properties']['opacity'])) > 0 && 1 > floatval($data[$block['name'] . '_properties']['opacity'])) {
                            $opacity = floatval($data[$block['name'] . '_properties']['opacity']);
                        }
                        if ($useSmallImages) {
                            $blockType = $block['type'];
                            if ($blockType == "pdf") {
                                $block['type'] = "image";
                            }
                        }
                        switch (strtolower($block['type'])) {
                            case 'text' :
                                if ($opacity !== false) {
                                    $pdf->save();
                                    $gstate = $pdf->create_gstate("opacityfill=" . $data[$block['name'] . '_properties']['opacity'] . " opacitystroke=" . $data[$block['name'] . '_properties']['opacity']);
                                    $pdf->set_gstate($gstate);
                                }
                                if (isset($data['html5Editor']) && (int)$data['html5Editor'] && strlen($text) == 0) {
                                    $text = ' ';
                                }
                                if (isset($block['custom']['Hyphen']) && $block['custom']['Hyphen']) {
                                    $text = $this->_hyphen($text, $block);
                                }
                                $options .= ' encoding=unicode';
                                if (isset($data['clipText']) && $data['clipText']) {
                                    $options .= " fitmethod=clip adjustmethod=clip";
                                } else {
                                    $options .= ' fitmethod=auto ';
                                }
                                $pdf->fit_textline($text, (float)($data[$block['name'] . '_properties']['left']), (float)($data[$block['name'] . '_properties']['top']),
                                    $options);
                                if ($opacity !== false) {
                                    $pdf->restore();
                                }
                                if (isset($params_border) && ! $is_circle) {
                                    if ($params_border['color'] && $params_border['device']) {
                                        $new_border =( isset($data['new_border']) && $data['new_border'] )? 1:0;
                                        $this->drawTextBlockBorders($pdf, $params_border,$new_border);
                                    }
                                }
                                break;
                            case 'textflow' :
                                if ($opacity !== false) {
                                    $pdf->save();
                                    $gstate = $pdf->create_gstate("opacityfill=" . $data[$block['name'] . '_properties']['opacity'] . " opacitystroke=" . $data[$block['name'] . '_properties']['opacity']);
                                    $pdf->set_gstate($gstate);
                                }
                                if (isset($data['html5Editor']) && (int)$data['html5Editor'] && strlen($text) == 0) {
                                    $text = ' ';
                                }
                                if (isset($block['custom']['Hyphen']) && $block['custom']['Hyphen']) {
                                    $text = $this->_hyphen($text, $block);
                                }

                                $options .= ' encoding=unicode';
                                if (isset($data['clipText']) && $data['clipText']) {
                                    $options         .= "  adjustmethod=clip";
                                    $optionsTextflow .= ' fitmethod=clip';
                                } else {
                                    $optionsTextflow .= ' fitmethod=auto';
                                }
                                $tf          = $pdf->create_textflow($text, $options);
                                $left        = (float)($data[$block['name'] . '_properties']['left']);
                                $top         = (float)($data[$block['name'] . '_properties']['top']);
                                $width_text  = (float)($data[$block['name'] . '_properties']['width']);
                                $height_text = (float)($data[$block['name'] . '_properties']['height']);
                                /*lx,ly,urx,ury*/
                                $pdf->fit_textflow($tf, $left, $top, $left + $width_text, $top + $height_text, $optionsTextflow);
                                if ($opacity !== false) {
                                    $pdf->restore();
                                }
                                if (isset($params_border) && ! $is_circle) {
                                    if ($params_border['color']) {
                                        $new_border = (isset($data['new_border']) && $data['new_border']) ? 1 : 0;
                                        $this->drawTextBlockBorders($pdf, $params_border, $new_border);
                                    }
                                }
                                break;
                            case 'image':
                                $image_src = false;
                                if (isset($data['image'][$block['name']])) {
                                    if (isset($data['uuids'][$data['image'][$block['name']]])) {
                                        $filePath   = $data['uuids'][$data['image'][$block['name']]];
                                        $tmp        = explode('/', urldecode($filePath));
                                        $image_name = array_pop($tmp);

                                        $image_src           = $path . $image_name;
                                        $filesToBeDeleted [] = $image_src;
                                    }
                                }
                                if ($image_src && file_exists($image_src)) {
                                    //apply effect
                                    $block_image_options = array(
                                        'block_name'         => $block['name'],
                                        'original_image_src' => $image_src,
                                        'angle'              => isset($data[$block['name'] . '_properties']['angle']) ? $data[$block['name'] . '_properties']['angle'] : 0
                                    );
                                    $rotate_image_src    = $this->rotateImageIM($block_image_options);
                                    if ($rotate_image_src) {
                                        $image_src           = $rotate_image_src;
                                        $filesToBeDeleted [] = $image_src;
                                    }

                                    if (isset($data[$block['name'] . '_properties'])) {
                                        $image_src        = $this->setBrighnessContrast($data[$block['name'] . '_properties'], $image_src);
                                        $image_src_effect = $this->testEffectOtp('sepia', $data[$block['name'] . '_properties'], $image_src);
                                        if ($image_src_effect != $image_src) {
                                            $image_src = $image_src_effect;
                                            if ( ! in_array($image_src, $filesToBeDeleted)) {
                                                $filesToBeDeleted [] = $image_src;
                                            }
                                        }
                                        $image_src_effect = $this->testEffectOtp('greyscale', $data[$block['name'] . '_properties'], $image_src_effect);
                                        if ($image_src_effect != $image_src) {
                                            $image_src = $image_src_effect;
                                            if ( ! in_array($image_src, $filesToBeDeleted)) {
                                                $filesToBeDeleted [] = $image_src;
                                            }
                                        }
                                        $image_src_effect = $this->testEffect('invert', $data[$block['name'] . '_properties'], $image_src_effect);
                                        if ($image_src_effect != $image_src) {
                                            $image_src = $image_src_effect;
                                            if ( ! in_array($image_src, $filesToBeDeleted)) {
                                                $filesToBeDeleted [] = $image_src;
                                            }
                                        }
                                        if (isset($data[$block['name'] . '_properties']['flip_horizontal']) && (int)$data[$block['name'] . '_properties']['flip_horizontal']) {
                                            $options .= " scale={-1 1} ";
                                        }
                                        if (isset($data[$block['name'] . '_properties']['flip_vertical']) && (int)$data[$block['name'] . '_properties']['flip_vertical']) {
                                            $options .= " scale={1 -1} ";
                                        }
                                        if (isset($data[$block['name'] . '_properties']['flip_both']) && (int)$data[$block['name'] . '_properties']['flip_both']) {
                                            $options .= " scale={-1 -1} ";
                                        }
                                    }
                                    //crop image to fit block
                                    if (isset($data[$block['name'] . '_properties']['cropW']) && $data[$block['name'] . '_properties']['cropW'] >= 0) {
                                        $ratioWidth  = 1;
                                        $ratioHeight = 1;
                                        $size        = getimagesize($image_src);
                                        if ( ! $useSmallImages) {
                                            if (isset($data[$block['name'] . '_properties']['actualWidth'])) {
                                                $ratioWidth = $size[0] / $data[$block['name'] . '_properties']['actualWidth'];
                                            }
                                            if (isset($data[$block['name'] . '_properties']['actualHeight'])) {
                                                $ratioHeight = $size[1] / $data[$block['name'] . '_properties']['actualHeight'];
                                            }
                                        }
                                        if (isset($data[$block['name'] . '_properties']['ignoreRatio'])) {
                                            $ratioWidth  = 1;
                                            $ratioHeight = 1;
                                        }

                                        $block_image_options = array(
                                            'original_image_src' => $image_src,
                                            'cropX'              => isset($data[$block['name'] . '_properties']['cropX']) ? $data[$block['name'] . '_properties']['cropX'] * $ratioWidth : 0,
                                            'cropY'              => isset($data[$block['name'] . '_properties']['cropY']) ? $data[$block['name'] . '_properties']['cropY'] * $ratioHeight : 0,
                                            'cropW'              => isset($data[$block['name'] . '_properties']['cropW']) ? $data[$block['name'] . '_properties']['cropW'] * $ratioWidth : 0,
                                            'cropH'              => isset($data[$block['name'] . '_properties']['cropH']) ? $data[$block['name'] . '_properties']['cropH'] * $ratioHeight : 0,
                                        );
                                        if ( ! ($block_image_options['cropX'] == 0 && $block_image_options['cropY'] == 0 && $block_image_options['cropW'] == 0 && $block_image_options['cropH'] == 0)) {

                                            $c_llx   = $block_image_options['cropX'];
                                            $c_lly   = $size[1] - $block_image_options['cropY'] - $block_image_options['cropH'];
                                            $c_urx   = $block_image_options['cropX'] + $block_image_options['cropW'];
                                            $c_ury   = $size[1] - $block_image_options['cropY'];
                                            if ((isset($data[$block['name'] . '_properties']['circle']) && $data[$block['name'] . '_properties']['circle'])) {
                                                $pdf->save();
                                                $this->clipImage($pdf, $data[$block['name'] . '_properties']);
                                            }
                                            $options .= " matchbox={clipping={ $c_llx $c_lly $c_urx $c_ury }} ";
                                        }
                                        $img = false;
                                        if (array_key_exists($image_src, $this->loaded_images)) {
                                            $img = $this->loaded_images[$image_src];
                                        } else {
                                            $optionsList = "";
                                            if ($use_pdf_vt_otp) {
                                                $optionsList = "pdfvt={scope=file}";
                                            }
                                            $img                             = $pdf->load_image('auto', $image_src, $optionsList);
                                            $this->loaded_images[$image_src] = $img;
                                        }


                                        if ($opacity !== false) {
                                            $pdf->save();
                                            $gstate = $pdf->create_gstate("opacityfill=" . $data[$block['name'] . '_properties']['opacity']);
                                            $pdf->set_gstate($gstate);
                                        }
                                    }
                                    if ($img) {
                                        $pdf->fit_image($img, (float)($data[$block['name'] . '_properties']['left']), (float)($data[$block['name'] . '_properties']['top']),
                                            $options);
                                    }
                                    if ($opacity !== false) {
                                        $pdf->restore();
                                    }
                                    if ((isset($data[$block['name'] . '_properties']['circle']) && $data[$block['name'] . '_properties']['circle'])) {
                                        $pdf->restore();
                                    }
                                }
                                break;
                            case 'graphics':
                                
                                if (isset($data['cliparts'][$block['name']]) || isset($data['qrs'][$block['name']])) {
                                    if (isset($data['qrs'][$block['name']])) {
                                        if (isset($data['uuids'][$data['qrs'][$block['name']]])) {
                                            $filePath   = $data['uuids'][$data['qrs'][$block['name']]];
                                            $tmp        = explode('/', urldecode($filePath));
                                            $image_name = array_pop($tmp);
                                            $image_src  = $path . $image_name;
                                        }
                                    } else {
                                        $tmp        = explode('/', $data['cliparts'][$block['name']]);
                                        $image_name = array_pop($tmp);
                                        $image_src  = $path . $image_name;
                                    }
                                    if (file_exists($image_src)) {
                                       
                                        // print_r($image_src);exit;
                                        $filesToBeDeleted[] = $image_src;
                                        $img                = $pdf->load_graphics('auto', $image_src, '');

                                        if ($opacity !== false) {
                                            $pdf->save();
                                            $gstate = $pdf->create_gstate("opacityfill=" . $data[$block['name'] . '_properties']['opacity'] . " opacitystroke=" . $data[$block['name'] . '_properties']['opacity']);
                                            $pdf->set_gstate($gstate);
                                        }
                                        if ($html5Editor) {
                                            $options .= " position={center} fitmethod=" . $data[$block['name'] . '_properties']['fitmethod'];
                                        }
                                        $pdf->fit_graphics($img, (float)($data[$block['name'] . '_properties']['left']),
                                            (float)($data[$block['name'] . '_properties']['top']), $options);
                                        if ($opacity !== false) {
                                            $pdf->restore();
                                        }
                                    }
                                }
                                break;
                            case 'pdf':
                                if (isset($data['image'][$block['name']])) {
                                    if (isset($data['uuids'][$data['image'][$block['name']]])) {
                                        $filePath = $data['uuids'][$data['image'][$block['name']]];

                                        if (strpos($filePath, '/') !== false) {
                                            $tmp        = explode('/', urldecode($filePath));
                                            $image_name = array_pop($tmp);

                                         //   $data['image'][$block['name']] = $image_name;
                                            $image_src                     = $path . $image_name;
                                            if (file_exists($image_src)) {
                                                $filesToBeDeleted[] = $image_src;
                                                $attach             = $pdf->open_pdi_document($image_src, '');

                                                if ($attach) {
                                                    $pdfPage     = isset($data[$block['name'] . '_properties']['pdfPage']) ? $data[$block['name'] . '_properties']['pdfPage'] : 0;
                                                    $optionsList = "";
                                                    if ($use_pdf_vt_otp) {
                                                        $optionsList = "pdfvt={scope=file}";
                                                    }
                                                    $newPage = $pdf->open_pdi_page($attach, $pdfPage + 1, $optionsList);
                                                    if ($newPage) {
                                                        if (isset($data[$block['name'] . '_properties']['auto_position']) && $data[$block['name'] . '_properties']['auto_position'] == 1) {
                                                            $position0 = '50';
                                                            $position1 = '50';
                                                            $options   .= ' position={' . $position0 . ' ' . $position1 . '}';
                                                        }
                                                        if (isset($data[$block['name'] . '_properties']['cropW']) && $data[$block['name'] . '_properties']['cropW'] >= 0) {
                                                            $sizes       = array(
                                                                $pdf->pcos_get_number($attach, "pages[$pdfPage]/width"),
                                                                $pdf->pcos_get_number($attach, "pages[$pdfPage]/height")
                                                            );
                                                            $ratioWidth  = 1;
                                                            $ratioHeight = 1;

                                                            if ( ! $useSmallImages) {
                                                                if (isset($data[$block['name'] . '_properties']['actualWidth'])) {
                                                                    $ratioWidth = $sizes[0] / $data[$block['name'] . '_properties']['actualWidth'];
                                                                }
                                                                if (isset($data[$block['name'] . '_properties']['actualHeight'])) {
                                                                    $ratioHeight = $sizes[1] / $data[$block['name'] . '_properties']['actualHeight'];
                                                                }
                                                            }
                                                            if(isset($data[$block['name'] . '_properties']['ignoreRatio'])){
                                                                $ratioWidth = 1;
                                                                $ratioHeight = 1;
                                                            }
                                                            $block_image_options = array(
                                                                'cropX' => isset($data[$block['name'] . '_properties']['cropX']) ? $data[$block['name'] . '_properties']['cropX'] * $ratioWidth : 0,
                                                                'cropY' => isset($data[$block['name'] . '_properties']['cropY']) ? $data[$block['name'] . '_properties']['cropY'] * $ratioHeight : 0,
                                                                'cropW' => isset($data[$block['name'] . '_properties']['cropW']) ? $data[$block['name'] . '_properties']['cropW'] * $ratioWidth : 0,
                                                                'cropH' => isset($data[$block['name'] . '_properties']['cropH']) ? $data[$block['name'] . '_properties']['cropH'] * $ratioHeight : 0,
                                                            );
                                                            $c_llx               = $block_image_options['cropX'];
                                                            $c_lly               = $sizes[1] - $block_image_options['cropY'] - $block_image_options['cropH'];
                                                            $c_urx               = $block_image_options['cropX'] + $block_image_options['cropW'];
                                                            $c_ury               = $sizes[1] - $block_image_options['cropY'];
                                                            $options             .= " matchbox={clipping={ $c_llx $c_lly $c_urx $c_ury }} ";
                                                        }
                                                        $options .= "  position={center} fitmethod=meet";
                                                        $pdf->fit_pdi_page($newPage, (float)($data[$block['name'] . '_properties']['left']),
                                                            (float)($data[$block['name'] . '_properties']['top']), $options);
                                                    }
                                                }
                                            }
                                        }
                                    }
                                }
                                break;
                        } //end switch
                    } //end foreach
                } //end if


                try {
                    if (isset($data['renderTables'])) {
                        if (is_array($data['renderTables']) && count($data['renderTables']) > 0) {
                            $tf = array();

                            foreach ($data['renderTables'][$i] as $page_table) {
                                $tbl     = 0;
                                $row     = 0;
                                $borders = array();
                                foreach ($page_table['rows'] as $table_row) {
                                    $row++;
                                    $col = 0;
                                    foreach ($table_row['cols'] as $row_col) {
                                        $col++;
                                        if ($row_col['hide']) {
                                            continue;
                                        }

                                        $colspan = isset($row_col['colSpan']) ? $row_col['colSpan'] : 1;
                                        $rowspan = isset($row_col['rowSpan']) ? $row_col['rowSpan'] : 1;

                                        if (isset($row_col['type']) && $row_col['type'] == 'textflow') {
                                            $optlist = '';
                                            if (isset($row_col['font'])) {
                                                $optlist .= ' fontname={' . $row_col['font'] . '} encoding=unicode';
                                            }
                                            if (isset($row_col['font']) && $row_col['font'] != 'Helvetica' && $use_pdf_vt_otp) {
                                                $optlist .= ' embedding ';
                                            }
                                            if (isset($row_col['fontsize'])) {
                                                $optlist .= ' fontsize=' . $row_col['fontsize'];
                                            }

                                            //$optlist .= ' matchbox={fillcolor={rgb 0.0 0.8 0.8}}';
                                            $optlist .= ' fillcolor={' . $row_col['color']['device'] . ' ' . $row_col['color']['color'] . '}';
                                            $optlist .= ' strokecolor={' . $row_col['color']['device'] . ' ' . $row_col['color']['color'] . '}';
                                            $optlist .= (isset($row_col['underline']) && '1' == $row_col['underline']) ? ' underline' : '';
                                            $optlist .= ' hyphenchar=none';

                                            $fontstyle = '';
                                            if ('1' !== $row_col['bold'] && '1' != $row_col['italic']) {
                                                $fontstyle = 'normal';
                                            }
                                            if (isset($row_col['bold']) && '1' == $row_col['bold']) {
                                                $fontstyle = 'bold';
                                            }
                                            if (isset($row_col['italic']) && '1' == $row_col['italic']) {
                                                $fontstyle .= 'italic';
                                            }
                                            if (strlen($fontstyle) > 0) {
                                                $optlist .= ' fontstyle=' . $fontstyle;
                                            }
                                            $optlist .= ' alignment=' . $row_col['align'];

                                            $text = trim($row_col['text']);
                                            $text = str_ireplace('<p><br></p>', "<br>", $text);
                                            $text = str_ireplace('</p><br>', "<br>", $text);
                                            $text = str_ireplace('</p>', "<br>", $text);
                                            $text = str_ireplace('<p>', "", $text);
                                            $text = str_ireplace('&nbsp;', " ", $text);
                                            $text = preg_replace('#<br\s*/?>#i', "\n", $text);
                                            $text = strip_tags($text);

                                            $tf[$row][$col] = 0;
                                            $tf[$row][$col] = $pdf->add_textflow($tf[$row][$col], $text, $optlist);


                                            $celloptlist = " textflow=" . $tf[$row][$col];
                                            $celloptlist .= ' rowheight=' . (($row_col['rowheight']) + ($row_col['1pixelW'] * 4));
                                            $celloptlist .= ' colwidth=' . $row_col['colwidth'];
                                            $celloptlist .= ' margin=' . $row_col['1pixelW'] * 2;
                                            $celloptlist .= ' colspan=' . $colspan;
                                            $celloptlist .= ' rowspan=' . $rowspan;
                                            if ($row_col['bgcolor']['color'] != 'transparent') {
                                                $celloptlist .= ' matchbox={fillcolor={' . $row_col['bgcolor']['device'] . ' ' . $row_col['bgcolor']['color'] . '}}';
                                            }
                                            $celloptlist .= ' fittextflow={ fitmethod=nofit verticalalign=' . $row_col['valign'] . ' lastlinedist=descender}';


                                            $tbl = $pdf->add_table_cell($tbl, $col, $row, "", $celloptlist);

                                            $borders[] = array(
                                                'r'         => $row - 1,
                                                'c'         => $col - 1,
                                                'rowspan'   => $rowspan - 1,
                                                'colspan'   => $colspan - 1,
                                                'colwidth'  => $row_col['colwidth'],
                                                'rowheight' => $row_col['rowheight'],
                                                'linewidth' => array(
                                                    $row_col['border']['top']['width'] * 0.75,
                                                    $row_col['border']['right']['width'] * 0.75,
                                                    $row_col['border']['bottom']['width'] * 0.75,
                                                    $row_col['border']['left']['width'] * 0.75
                                                ),
                                                'awlH'      => $row_col['1pixelH'] * 0.75,
                                                'awlW'      => $row_col['1pixelW'] * 0.75,
                                                'awl'       => 1,
                                                'device'    => array(
                                                    $row_col['border']['top']['device'],
                                                    $row_col['border']['right']['device'],
                                                    $row_col['border']['bottom']['device'],
                                                    $row_col['border']['left']['device']
                                                ),
                                                'color'     => array(
                                                    $row_col['border']['top']['color'],
                                                    $row_col['border']['right']['color'],
                                                    $row_col['border']['bottom']['color'],
                                                    $row_col['border']['left']['color']
                                                ),
                                            );

                                        }
                                    }
                                }
                                /* Stroke a line with that pattern */

                                $optlist = "";
                                //$optlist .= "showcells=true";
                                //$optlist .= " showgrid=true";
                                //$optlist .= " stroke={{line=other linewidth=1}}";

                                $result = $pdf->fit_table($tbl, $page_table['llx'], $page_table['lly'], $page_table['urx'], $page_table['ury'], $optlist);

                                if (count($borders)) {
                                    foreach ($borders as $border) {
                                        $this->drawCellBorderUpdated($pdf, $tbl, $border);
                                    }
                                }

                                if ($result == "_error") {
                                    print_r("Couldn't place table: " . $pdf->get_errmsg());
                                }
                            }
                        }
                    }
                } catch (PDFlibException $e) {
                    print_r($e->getMessage());
                    exit;
                } catch (Exception $e) {
                    print_r($e->getMessage());
                    exit;
                }

                $pdf->end_page_ext("");
                if ($use_pdf_vt_otp) {
                    $pdf->end_dpart("");
                }
            }
        }
        if ($use_pdf_vt_otp) {
            $pdf->end_dpart("");
        }
        //end pages for
        $this->deleteAllFiles($filesToBeDeleted);
    }

    private function moveBlocksForCoupon($pdf, &$blocks, $data)
    {
        $move_groups_hor = array();
        $maxlength       = array();
        $maxlengthblocks = array();
        $imageblocks     = array();
        $offset          = array();
        if (is_array($blocks) && count($blocks)) {

            $blocksLength = count($blocks);
            for ($i = 0; $i < $blocksLength; $i++) {
                $value = $blocks[$i];

                if (isset($data['moveBlocksCoupon']) && $data['moveBlocksCoupon']) {
                    if (isset($value['custom']['IsName']) && ($value['custom']['IsName'])) {
                        $fontname      = $value['fontname'];
                        $blockvalue    = $value['value'];
                        $font          = $pdf->load_font("$fontname", "unicode", "embedding");
                        $_string_width = $pdf->stringwidth("$blockvalue", $font, $value['fontsize']);
                        if ($_string_width > ($value['x2'] - $value['x1'])) {
                            $_string_width = $value['x2'] - $value['x1'];
                        }
                        $new_x1 = $_string_width + $value['x1'];
                        $new_x2 = $_string_width + $value['x2'];
                        break;

                    }
                }

            }

        }
        if (is_array($blocks) && count($blocks)) {
            $blocksLength = count($blocks);
            for ($i = 0; $i < $blocksLength; $i++) {
                $value = $blocks[$i];
                if (isset($data['moveBlocksCoupon']) && $data['moveBlocksCoupon']) {
                    if (isset($value['custom']['AlignToName']) && ($value['custom']['AlignToName'])) {
                        if (isset($new_x1) && isset($new_x2)) {
                            $blocks[$i]['x1'] = $new_x1;
                            $blocks[$i]['x2'] = $new_x2;
                        }
                        break;
                    }
                }
            }
        }
    }

    private function moveBlocksHor($pdf, &$blocks)
    {
        $move_groups_hor = array();
        $maxlength       = array();
        $maxlengthblocks = array();
        $imageblocks     = array();
        $offset          = array();
        if (is_array($blocks) && count($blocks)) {
            $blocksLength = count($blocks);
            for ($i = 0; $i < $blocksLength; $i++) {
                $value = $blocks[$i];
                if (isset($value['custom']['MoveGroupHor']) && strlen($value['custom']['MoveGroupHor'])) {
                    $move_groups_hor[$value['custom']['MoveGroupHor']][] = $value['name'];
                    $fontname                                            = $value["fontname"];
                    $blockvalue                                          = $value['value'];
                    $font                                                = $pdf->load_font("$fontname", "unicode", "embedding");
                    $_string_width                                       = $pdf->stringwidth("$blockvalue", $font, $value['fontsize']);
                    if ( ! isset($maxlength[$value['custom']['MoveGroupHor']])) {
                        $maxlength[$value['custom']['MoveGroupHor']] = 0;
                    }
                    if ($maxlength[$value['custom']['MoveGroupHor']] < $_string_width) {
                        $maxlength[$value['custom']['MoveGroupHor']]       = $_string_width;
                        $maxlengthblocks[$value['custom']['MoveGroupHor']] = $value;
                    }
                }
                if (isset($value['custom']['AllignSide']) && strlen($value['custom']['AllignSide'])) {
                    $imageblocks[$value['name']]['blockdata'] = $value;
                    $imageblocks[$value['name']]['index']     = $i;
                }
            }
            foreach ($maxlengthblocks as $key => $value) {
                if ($imageblocks[$key]['blockdata']['custom']['AllignSide'] == 'Right') {
                    $vline = $imageblocks[$key]['blockdata']['x2'];
                } elseif ($imageblocks[$key]['blockdata']['custom']['AllignSide'] == 'Left') {
                    $vline = $imageblocks[$key]['blockdata']['x1'];
                }
                $offset[$key] = $vline - $value['x1'] - $maxlength[$key];
            }

            for ($i = 0; $i < $blocksLength; $i++) {
                $value = $blocks[$i];
                if (isset($value['custom']['MoveGroupHor']) && strlen($value['custom']['MoveGroupHor'])) {
                    $blocks[$i]['x1'] += (float)$offset[$value['custom']['MoveGroupHor']];
                    $blocks[$i]['x2'] += (float)$offset[$value['custom']['MoveGroupHor']];
                }
            }
        }
    }

    private function moveImageForCoupons($pdf, &$blocks, $data)
    {
        $move_groups_hor  = array();
        $maxlength        = array();
        $maxlengthblocks  = array();
        $imageblocks      = array();
        $offset           = array();
        $max_string_width = 0;
        //   print_r($blocks);exit;
        if (is_array($blocks) && count($blocks)) {
            $blocksLength = count($blocks);
            for ($i = 0; $i < $blocksLength; $i++) {
                $value = $blocks[$i];

                if (isset($data['isCouponEditor']) && $data['isCouponEditor']) {
                    if (isset($value['custom']['StickMaster']) && ($value['custom']['StickMaster'])) {
                        $fontname      = $value['fontname'];
                        $blockvalue    = $value['value'];
                        $font          = $pdf->load_font("$fontname", "unicode", "embedding");
                        $_string_width = $pdf->stringwidth("$blockvalue", $font, $value['fontsize']);
                        if ($_string_width > ($value['x2'] - $value['x1'])) {
                            $_string_width = $value['x2'] - $value['x1'];
                        }
                        if ($max_string_width < $_string_width) {
                            $new_x1           = $_string_width + $value['x1'];
                            $new_x2           = $_string_width + $value['x2'];
                            $max_string_width = $_string_width;
                            // $j++;
                        }


                    }
                }

            }

        }
        if (is_array($blocks) && count($blocks)) {
            $blocksLength = count($blocks);
            for ($i = 0; $i < $blocksLength; $i++) {
                $value = $blocks[$i];
                if (isset($data['isCouponEditor']) && $data['isCouponEditor']) {
                    if (isset($value['custom']['StickChild']) && ($value['custom']['StickChild'])) {
                        if (isset($new_x1) && isset($new_x2)) {
                            $blocks[$i]['x1'] = $new_x1;
                            $blocks[$i]['x2'] = $new_x2;
                        }
                        break;
                    }
                }
            }
        }
    }

    private function stickWith(&$blocks)
    {
        if (is_array($blocks) && count($blocks)) {
            $blocksLength    = count($blocks);
            $stickWithBlocks = array();
            for ($i = 0; $i < $blocksLength; $i++) {
                $value = $blocks[$i];
                if (isset($value['custom']['StickWith']) && strlen($value['custom']['StickWith'])) {
                    $name                                   = $value['custom']['StickWith'];
                    $value['custom']['position']            = $i;
                    $stickWithBlocks[$name][$value['name']] = $value;
                }
            }
            for ($i = 0; $i < $blocksLength; $i++) {
                $value = $blocks[$i];
                if (isset($value['name']) && strlen($value['name'])) {
                    if (array_key_exists($value['name'], $stickWithBlocks)) {
                        $stickerValues = $stickWithBlocks[$value['name']];
                        foreach ($stickerValues as $stickerValue) {
                            if (isset($stickerValue['custom']['StickOrientation']) && strlen($stickerValue['custom']['StickOrientation'])) {
                                $offset = 0;
                                if (isset($stickerValue['custom']['StickOffset']) && strlen($stickerValue['custom']['StickOffset'])) {
                                    $offset = $stickerValue['custom']['StickOffset'];
                                }
                                $position = $stickerValue['custom']['position'];
                                $height   = abs($blocks[$position]['y2'] - $blocks[$position]['y1']);
                                $topY     = (float)($blocks[$i]['y2']);
                                $bottomY  = (float)($blocks[$i]['y1']);
                                switch ($stickerValue['custom']['StickOrientation']) {
                                    case 'top':
                                        $blocks[$position]['y1'] = $topY + $offset;
                                        $blocks[$position]['y2'] = $topY + $offset + $height;
                                        break;
                                    case 'bottom':
                                        $blocks[$position]['y1'] = $bottomY - $offset - $height;
                                        $blocks[$position]['y2'] = $bottomY - $offset;
                                        break;
                                    default:
                                        break;
                                }
                            }
                        }
                    }
                }
            }
        }
    }

    private function verticalAllign($pageheight, &$blocks)
    {
        if (is_array($blocks) && count($blocks)) {
            $blocksLength = count($blocks);
            $maxTop       = 0;
            $minBot       = 9999;
            for ($i = 0; $i < $blocksLength; $i++) {
                if ($blocks[$i]['y1'] < $minBot && $blocks[$i]['value']) {
                    $minBot = $blocks[$i]['y1'];
                }
                if ($blocks[$i]['y2'] > $maxTop && $blocks[$i]['value']) {
                    $maxTop = $blocks[$i]['y2'];
                }
            }
            $topDifference    = $pageheight - $maxTop;
            $bottomDifference = $minBot;
            $middle           = ($topDifference + $bottomDifference) / 2;
            $correction       = $topDifference - $middle;
            for ($i = 0; $i < $blocksLength; $i++) {
                $blocks[$i]['y1'] += $correction;
                $blocks[$i]['y2'] += $correction;
            }
        }
    }

    private function reorderBlocks($blocks, $data)
    {
        $result           = false;
        $new_blocks_order = array();
        if ( ! empty($blocks)) {
            foreach ($blocks as $block) {
                $order = 0;
                if (
                    isset($data[$block['name'] . '_properties']) &&
                    isset($data[$block['name'] . '_properties']['order']) &&
                    (int)$data[$block['name'] . '_properties']['order'] > 0
                ) {
                    $order = (int)$data[$block['name'] . '_properties']['order'];
                }
                $new_blocks_order[$order][] = $block;
            }
        }
        if (count($new_blocks_order) > 0) {
            ksort($new_blocks_order);
            foreach ($new_blocks_order as $block_position) {
                foreach ($block_position as $block) {
                    $result[] = $block;
                }
            }
        } else {
            $result = $blocks;
        }

        return $result;
    }

    private function rotateImageIM($options)
    {
        if (in_array($options['angle'], array(90, 180, 270))) {
            if (file_exists($options['original_image_src'])) {
                $rotated_filename = $options['original_image_src'] . '_rotate' . $options['angle'] . '_' . $options['block_name'];
                $image            = new \Imagick($options['original_image_src']);
                $image->rotateImage(new \ImagickPixel('none'), $options['angle']);
                $image->writeImage($rotated_filename);

                if (file_exists($rotated_filename)) {
                    return $rotated_filename;
                }
            }
        }

        return false;
    }

    private function testEffectOtp($effect, $block_property, $image_src)
    {
        if (isset($block_property[$effect]) && '1' == $block_property[$effect]) {
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

    private function setBrighnessContrast($block_property, $image_src)
    {
        $b = 1;
        $c = 1;
        if (isset($block_property['brightness']) && $block_property['brightness']) {
            $b += $block_property['brightness']-0.001;
        }
        if (isset($block_property['contrast']) && $block_property['contrast']) {
            $c += $block_property['contrast']-0.001;
        }
        if ($c != 1 || $b != 1) {
            // Calculate level values
            $z1           = ($c - 1) / (2 * $b * $c);
            $z2           = ($c + 1) / (2 * $b * $c);
            $bc_image_src = $image_src . "_b_c";
            exec('convert -background white -alpha remove -alpha off ' . $image_src . ' -level ' . ($z1 * 100) . '%,' . ($z2 * 100) . '% ' . $bc_image_src);
            return $bc_image_src;
        }
        return $image_src;
    }

    private function effectImageOtp($options)
    {
        $effect = '';
        if (isset($options['effect']) && in_array($options['effect'], array(
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
        $effect_filename = $options['original_image_src'] . '_' . $effect;
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

    private function imagePrepareFilterImage($sourceImageFile)
    {
        $img  = false;
        $info = $this->getImageInfo($sourceImageFile);
        $src  = false;
        # open image
        if ($info) {
            $w   = $info[0];
            $h   = $info[1];
            $src = $this->openImage($info, $sourceImageFile);
        }

        if ($src) {
            list($img, $alphacolor) = $this->createDraftImage($src, $w, $h);
            imagecopyresampled($img, $src, 0, 0, 0, 0, $w, $h, $w, $h);
        }

        return $img;
    }

    private function getImageInfo($sourceImageFile)
    {
        $res = false;
        if (file_exists($sourceImageFile)) {
            $res = is_array($info = getimagesize($sourceImageFile));
        }
        if ($res) {
            return $info;
        }

        return false;
    }

    private function openImage($info, $sourceImageFile)
    {
        $src = false;
        if ($info) {
            switch ($info['mime']) {
                case 'image/jpeg':
                    $src = imagecreatefromjpeg($sourceImageFile);
                    break;
                case 'image/gif':
                    $src = imagecreatefromgif($sourceImageFile);
                    break;
                case 'image/png':
                    $src = imagecreatefrompng($sourceImageFile);
                    break;
            }
        }

        return $src;
    }

    private function createDraftImage($src, $w, $h)
    {
        list($r, $g, $b) = $this->getUniqColor($src);
        $img        = imagecreatetruecolor($w, $h);
        $alphacolor = imagecolorallocatealpha($img, $r, $g, $b, 127);
        imagealphablending($img, false);
        imagesavealpha($img, true);
        imagefilledrectangle($img, 0, 0, $w, $h, $alphacolor);
        imagefill($img, 0, 0, $alphacolor);

        return array($img, $alphacolor);
    }

    private function getUniqColor($src)
    {
        do {
            $r = rand(0, 255);
            $g = rand(0, 255);
            $b = rand(0, 255);
        } while (imagecolorexact($src, $r, $g, $b) < 0);

        return array($r, $g, $b);
    }

    private function flipHImage($sourceImageFile)
    {
        $img  = false;
        $info = $this->getImageInfo($sourceImageFile);
        $src  = false;
        # open image
        if ($info) {
            $w   = $info[0];
            $h   = $info[1];
            $src = $this->openImage($info, $sourceImageFile);
        }

        if ($src) {
            list($img, $alphacolor) = $this->createDraftImage($src, $w, $h);

            for ($x = 0; $x < $w; $x++) {
                imagecopy($img, $src, $x, 0, $w - $x - 1, 0, 1, $h);
            }
        }

        return $img;
    }

    private function flipVImage($sourceImageFile)
    {
        $img  = false;
        $info = $this->getImageInfo($sourceImageFile);
        $src  = false;
        # open image
        if ($info) {
            $w   = $info[0];
            $h   = $info[1];
            $src = $this->openImage($info, $sourceImageFile);
        }

        if ($src) {
            list($img, $alphacolor) = $this->createDraftImage($src, $w, $h);

            for ($y = 0; $y < $h; $y++) {
                imagecopy($img, $src, 0, $y, 0, $h - $y - 1, $w, 1);
            }
        }

        return $img;
    }

    private function cropBlockImageZoomerRemoved($options)
    {
        if ((int)$options['cropW'] > 0) {
            if (file_exists($options['original_image_src'])) {
                $cropped_filename = $options['original_image_src']
                    . $options['block_name'];
                $targ_w           = $options['cropW'];
                $targ_h           = $options['cropH'];

                $dst_r = ImageCreateTrueColor($targ_w, $targ_h);

                list($orig_w, $orig_h) = getimagesize(
                    $options['original_image_src']
                );
                $dst_x = 0;
                $dst_y = 0;

                if ($options['cropX'] < 0) {
                    $dst_x            = round(
                        ($options['cropX'] * -1) * ($targ_w / $options['cropW'])
                    );
                    $options['cropX'] = 0;
                }
                if ($options['cropW'] > $orig_w - abs($options['cropX'])) {
                    $tmpCropW         = $options['cropW'];
                    $options['cropW'] = $orig_w - abs($options['cropX']);
                    $targ_w           = $targ_w * $options['cropW'] / $tmpCropW;
                }

                if ($options['cropY'] < 0) {
                    $dst_y            = round(
                        ($options['cropY'] * -1) * ($targ_h / $options['cropH'])
                    );
                    $options['cropY'] = 0;
                }
                if ($options['cropH'] > $orig_h - abs($options['cropY'])) {
                    $tmpCropH         = $options['cropH'];
                    $options['cropH'] = $orig_h - abs($options['cropY']);
                    $targ_h           = $targ_h * $options['cropH'] / $tmpCropH;
                }

                switch (exif_imagetype($options['original_image_src'])) {
                    case 2: //'IMAGETYPE_JPEG':
                        $img_r = imagecreatefromjpeg(
                            $options['original_image_src']
                        );

                        # find unique color
                        do {
                            $r = rand(0, 255);
                            $g = rand(0, 255);
                            $b = rand(0, 255);
                        } while (imagecolorexact($img_r, $r, $g, $b) < 0);

                        $transparency = imagecolorallocatealpha(
                            $dst_r, $r, $g, $b, 127
                        );
                        imagefill($dst_r, 0, 0, $transparency);
                        imagesavealpha($dst_r, true);

                        imagecopyresampled(
                            $dst_r, $img_r, $dst_x, $dst_y, $options['cropX'],
                            $options['cropY'], $targ_w, $targ_h,
                            $options['cropW'], $options['cropH']
                        );
                        break;
                    case 3: // 'IMAGETYPE_PNG':
                        $img_r = imagecreatefrompng(
                            $options['original_image_src']
                        );

                        # find unique color
                        do {
                            $r = rand(0, 255);
                            $g = rand(0, 255);
                            $b = rand(0, 255);
                        } while (imagecolorexact($img_r, $r, $g, $b) < 0);

                        $transparency = imagecolorallocatealpha(
                            $dst_r, $r, $g, $b, 127
                        );
                        imagefill($dst_r, 0, 0, $transparency);
                        imagesavealpha($dst_r, true);

                        imagealphablending($dst_r, false);
                        imagesavealpha($dst_r, true);
                        imagecopyresampled(
                            $dst_r, $img_r, $dst_x, $dst_y, $options['cropX'],
                            $options['cropY'], $targ_w, $targ_h,
                            $options['cropW'], $options['cropH']
                        );
                        break;
                    case 1: // 'IMAGETYPE_GIF':
                        $img_r = imagecreatefromgif(
                            $options['original_image_src']
                        );

                        # find unique color
                        do {
                            $r = rand(0, 255);
                            $g = rand(0, 255);
                            $b = rand(0, 255);
                        } while (imagecolorexact($img_r, $r, $g, $b) < 0);

                        $transparency = imagecolorallocatealpha(
                            $dst_r, $r, $g, $b, 127
                        );
                        imagefill($dst_r, 0, 0, $transparency);
                        imagesavealpha($dst_r, true);

                        imagealphablending($dst_r, false);
                        imagesavealpha($dst_r, true);
                        imagecopyresampled(
                            $dst_r, $img_r, $dst_x, $dst_y, $options['cropX'],
                            $options['cropY'], $targ_w, $targ_h,
                            $options['cropW'], $options['cropH']
                        );
                        break;
                    default:
                        return false;
                }

                imagepng($dst_r, $cropped_filename);

                if (file_exists($cropped_filename)) {
                    return $cropped_filename;
                }
            }
        }

        return false;
    }

    private function cropBlockImageIM($options)
    {
        if ((int)$options['cropW'] > 0) {
            if (file_exists($options['original_image_src'])) {
                $cropped_filename = $options['original_image_src'] . $options['block_name'];
                $image            = new \Imagick($options['original_image_src']);
                $image->cropImage($options['cropW'], $options['cropH'], $options['cropX'], $options['cropY']);
                $image->writeImage($cropped_filename);

                if (file_exists($cropped_filename)) {
                    return $cropped_filename;
                }
            }
        }

        return false;
    }

    private function cropBlockImage($options)
    {
        if ((int)$options['cropW'] > 0) {
            if (file_exists($options['original_image_src'])) {
                $cropped_filename = $options['original_image_src'] . $options['block_name'];
                //$resizePercentage_width = (float)$options['resizePercentage_width'] > 0 ? (float)$options['resizePercentage_width'] : 100;
                //$resizePercentage_height = (float)$options['resizePercentage_height'] > 0 ? (float)$options['resizePercentage_height'] : 100;
                //$targ_w = $options['block_width'] ;
                //$targ_h = $options['block_height'];
                $targ_w = $options['cropW'];
                $targ_h = $options['cropH'];
                $cropW  = $options['cropW'];
                $cropH  = $options['cropH'];

                list($orig_w, $orig_h) = getimagesize($options['original_image_src']);
                $dst_x = 0;
                $dst_y = 0;

                /*if ( $options['cropX'] < 0 ){
						$dst_x = round( ($options['cropX'] * -1) * ($targ_w / $options['cropW']));
						$options['cropX'] = 0;
					}
					if ( $options['cropW'] > $orig_w - abs($options['cropX']) ){
						$tmpCropW = $options['cropW'];
						$options['cropW'] = $orig_w - abs($options['cropX']);
						$targ_w = $targ_w * $options['cropW'] / $tmpCropW;
					}

					if ( $options['cropY'] < 0 ){
						$dst_y = round( ($options['cropY'] * -1) * ($targ_h / $options['cropH']));
						$options['cropY'] = 0;
					}
					if ( $options['cropH'] > $orig_h - abs($options['cropY']) ){
						$tmpCropH = $options['cropH'];
						$options['cropH'] = $orig_h - abs($options['cropY']);
						$targ_h = $targ_h * $options['cropH'] / $tmpCropH;
					}
						if ( $cropW > $targ_w && $cropH > $targ_h ){
							$normalize = $cropW / $targ_w;
							$targ_h *= $normalize;
							$targ_w *= $normalize;
							$dst_x *= $normalize;
							$dst_y *= $normalize;

							//$targ_h = 10;
						}*/

                $dst_r = ImageCreateTrueColor($targ_w, $targ_h);

                switch (exif_imagetype($options['original_image_src'])) {
                    case 2: //'IMAGETYPE_JPEG':
                        $img_r = imagecreatefromjpeg($options['original_image_src']);

                        /*# find unique color
							//do {
								$r = rand(0, 255);
								$g = rand(0, 255);
								$b = rand(0, 255);
							//}
						   // while (imagecolorexact($img_r, $r, $g, $b) < 0);

							//$transparency = imagecolorallocatealpha($dst_r, $r, $g, $b, 127);
							//imagefill($dst_r, 0, 0, $transparency);
						   // imagesavealpha($dst_r, true);*/

                        imagecopyresampled($dst_r, $img_r, $dst_x, $dst_y, $options['cropX'], $options['cropY'], $targ_w, $targ_h, $options['cropW'], $options['cropH']);
                        break;
                    case 3: // 'IMAGETYPE_PNG':
                        $img_r = imagecreatefrompng($options['original_image_src']);

                        # find unique color
                        do {
                            $r = rand(0, 255);
                            $g = rand(0, 255);
                            $b = rand(0, 255);
                        } while (imagecolorexact($img_r, $r, $g, $b) < 0);

                        $transparency = imagecolorallocatealpha($dst_r, $r, $g, $b, 127);
                        imagefill($dst_r, 0, 0, $transparency);
                        imagesavealpha($dst_r, true);

                        imagealphablending($dst_r, false);
                        imagesavealpha($dst_r, true);
                        imagecopyresampled($dst_r, $img_r, $dst_x, $dst_y, $options['cropX'], $options['cropY'], $targ_w, $targ_h, $options['cropW'], $options['cropH']);
                        break;
                    case 1: // 'IMAGETYPE_GIF':
                        $img_r = imagecreatefromgif($options['original_image_src']);

                        # find unique color
                        //do {
                        $r = rand(0, 255);
                        $g = rand(0, 255);
                        $b = rand(0, 255);
                        //}
                        //while (imagecolorexact($img_r, $r, $g, $b) < 0);

                        $transparency = imagecolorallocatealpha($dst_r, $r, $g, $b, 127);
                        imagefill($dst_r, 0, 0, $transparency);
                        imagesavealpha($dst_r, true);

                        imagealphablending($dst_r, false);
                        imagesavealpha($dst_r, true);
                        imagecopyresampled($dst_r, $img_r, $dst_x, $dst_y, $options['cropX'], $options['cropY'], $targ_w, $targ_h, $options['cropW'], $options['cropH']);
                        break;
                    default:
                        return false;
                }

                imagepng($dst_r, $cropped_filename, 0);

                if (file_exists($cropped_filename)) {
                    return $cropped_filename;
                }
            }
        }

        return false;
    }

    private function roundImageCorners($options)
    {
        $source           = $options['original_image_src'];
        $rounded_filename = $options['original_image_src'] . '_round';
        $round_image      = $this->imageCreateCorners($source);
        if ($round_image) {
            imagepng($round_image, $rounded_filename, 0);
            if (file_exists($rounded_filename)) {
                return $rounded_filename;
            }
        }

        return false;
    }

    private function imageCreateCorners($sourceImageFile)
    {
        $dest = false;
        $info = $this->getImageInfo($sourceImageFile);
        $src  = false;
        # open image
        if ($info) {
            $w      = $info[0];
            $h      = $info[1];
            $radius = ($w < $h) ? (int)($w / 2) : (int)($h / 2);
            $src    = $this->openImage($info, $sourceImageFile);
        }

        if ($src) {
            $q      = 5; # change this if you want
            $radius *= $q;

            $nw = $w * $q;
            $nh = $h * $q;
            list($img, $alphacolor) = $this->createDraftImage($src, $nw, $nh);
            list($r, $g, $b) = $this->getUniqColor($src);

            imagecopyresampled($img, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);

            imagearc($img, $radius - 1, $radius - 1, $radius * 2, $radius * 2, 180, 270, $alphacolor);
            imagefilltoborder($img, 0, 0, $alphacolor, $alphacolor);
            imagearc($img, $nw - $radius, $radius - 1, $radius * 2, $radius * 2, 270, 0, $alphacolor);
            imagefilltoborder($img, $nw - 1, 0, $alphacolor, $alphacolor);
            imagearc($img, $radius - 1, $nh - $radius, $radius * 2, $radius * 2, 90, 180, $alphacolor);
            imagefilltoborder($img, 0, $nh - 1, $alphacolor, $alphacolor);
            imagearc($img, $nw - $radius, $nh - $radius, $radius * 2, $radius * 2, 0, 90, $alphacolor);
            imagefilltoborder($img, $nw - 1, $nh - 1, $alphacolor, $alphacolor);
            imagealphablending($img, true);
            imagecolortransparent($img, $alphacolor);

            # resize image down
            $dest = $this->resizeDraftDown($src, $img, $w, $h, $nw, $nh);
        }

        return $dest;
    }

    private function resizeDraftDown($src, $img, $w, $h, $nw, $nh)
    {
        list($dest, $alphacolor) = $this->createDraftImage($src, $w, $h);
        imagecopyresampled($dest, $img, 0, 0, 0, 0, $w, $h, $nw, $nh);
        imagedestroy($src);
        imagedestroy($img);

        return $dest;
    }

    protected function drawCellBorderUpdated($p, $tbl, $params = array())
    {
        $v0 = round($p->info_table($tbl, "xvertline" . $params['c']), 2);
        $v1 = round($p->info_table($tbl, "xvertline" . ($params['c'] + 1 + $params['colspan'])), 2);

        $h0 = round($p->info_table($tbl, "yhorline" . $params['r']), 2);
        $h1 = round($p->info_table($tbl, "yhorline" . ($params['r'] + 1 + $params['rowspan'])), 2);

        $awlT   = (isset($params['awl']) && $params['awl']) ? ($params['linewidth'][0] / 2) : 0;
        $awlR   = (isset($params['awl']) && $params['awl']) ? ($params['linewidth'][1] / 2) : 0;
        $awlB   = (isset($params['awl']) && $params['awl']) ? ($params['linewidth'][2] / 2) : 0;
        $awlL   = (isset($params['awl']) && $params['awl']) ? ($params['linewidth'][3] / 2) : 0;
        $isLine = false;

        $generalOptions = ' linecap=projecting linejoin=miter';

        /* top line*/
        if ((float)$params['linewidth'][0] > 0) {
            if ($params['color'][0] != 'transparent') {
                $isLine = true;
                $path   = $p->add_path_point(0, $v0 + $awlL - $params['awlH'], $h0 - $awlT + $params['awlW'], "move",
                    'linewidth=' . $params['linewidth'][0] . ' strokecolor={' . $params['device'][0] . ' ' . $params['color'][0] . '}' . $generalOptions);
                $path   = $p->add_path_point($path, $v1 + $awlL - $params['awlH'], $h0 - $awlT + $params['awlW'], "line", "");
            }
        }

        /*right line*/
        if ((float)$params['linewidth'][1] > 0) {
            if ($params['color'][1] != 'transparent') {
                $isLine = true;
                $path   = $p->add_path_point($path, $v1 + $awlR - $params['awlH'], $h0 - $awlT + $params['awlW'], "move",
                    'linewidth=' . $params['linewidth'][1] . ' strokecolor={' . $params['device'][1] . ' ' . $params['color'][1] . '}' . $generalOptions);
                $path   = $p->add_path_point($path, $v1 + $awlR - $params['awlH'], $h1 - $awlB + $params['awlW'], "line", "");
            }
        }

        /*bottom line*/
        if ((float)$params['linewidth'][2] > 0) {
            if ($params['color'][2] != 'transparent') {
                $isLine = true;
                $path   = $p->add_path_point($path, $v1 + $awlL - $params['awlH'], $h1 - $awlB + $params['awlW'], "move",
                    'linewidth=' . $params['linewidth'][2] . ' strokecolor={' . $params['device'][2] . ' ' . $params['color'][2] . '}' . $generalOptions);
                $path   = $p->add_path_point($path, $v0 + $awlL - $params['awlH'], $h1 - $awlB + $params['awlW'], "line", "");
            }
        }

        /*left line*/
        if ((float)$params['linewidth'][3] > 0) {
            if ($params['color'][3] != 'transparent') {
                $isLine = true;
                $path   = $p->add_path_point($path, $v0 + $awlL - $params['awlH'], $h1 + $params['awlW'] + $awlB, "move",
                    'linewidth=' . $params['linewidth'][3] . ' strokecolor={' . $params['device'][3] . ' ' . $params['color'][3] . '}' . $generalOptions);
                $path   = $p->add_path_point($path, $v0 + $awlL - $params['awlH'], $h0 - $awlT + $params['awlW'], "line", "");
            }
        }

        if ($isLine) {
            $p->draw_path($path, 0, 0, "stroke");
        }
    }

    protected function generateLivePreview($image, $data)
    {
        $img = $image->getVariables();

        if (isset($img['result']['image']) && ! empty($img['result']['image'])) {

            $written = file_put_contents(dirname(__FILE__) . '/temp.png', base64_decode($img['result']['image']));
            if ($written) {

                $client = new Client('http://192.162.84.131/preview/public/preview');
                $client->setFileUpload(dirname(__FILE__) . '/temp.png', 'toPsd');
                $client->setParameterPost(array(
                    'file' => $data['psd_preview'],
                ));

                $client->setMethod(Request::METHOD_POST);

                $response = $client->send();

                unlink(dirname(__FILE__) . '/temp.png');
                if ($response->isSuccess()) {

                    $res = json_decode($response->getBody());

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

    public function update($id, $data)
    {
        # code...
    }

    public function delete($id)
    {
        # code...
    }

    function writeLog($text, $params = array())
    {
        return;
        $file       = '/var/www/html/rest/performanceLog/log.log';
        $curtime    = microtime(true);
        $time       = number_format($curtime - $this->time, 4);
        $globaltime = number_format($curtime - $this->globaltime, 4);
        $this->time = $curtime;
        $flag       = FILE_APPEND;
        if (isset($params['append']) && ! $params['append']) {
            $flag = 0;
        }
        file_put_contents($file, $text . "\t:" . $time . ' (' . $globaltime . ')' . "\n", $flag);
        if (isset($params['close']) && $params['close']) {
            file_put_contents($file, $time . ' (' . $globaltime . ')' . "\n\n\n", $flag);
        }
    }

    /**
     * @param       $pdf
     * @param array $params
     *     llx
     *     lly
     *     colwidth
     *     rowHeight
     */
    protected function drawCellBorder($pdf, $params = array())
    {
        /* top line*/
        $pdf->setlinewidth($params['linewidth'][0]);
        $pdf->setcolor("stroke", $params['device'][0], $params['color'][0][0], $params['color'][0][1], $params['color'][0][2], $params['color'][0][3]);
        $awl = (isset($params['awl']) && $params['awl']) ? ($params['linewidth'][0] / 2) : 0; //$adjust_with_linewidth
        $pdf->moveto($params['llx'] - $params['awlH'], $params['lly'] - $awl + $params['awlW']);
        $pdf->lineto($params['llx'] - $params['awlH'] + $params['colwidth'], $params['lly'] - $awl + $params['awlW']);

        $pdf->stroke();

        /*right line*/
        $pdf->setlinewidth($params['linewidth'][1]);
        $pdf->setcolor("stroke", $params['device'][1], $params['color'][1][0], $params['color'][1][1], $params['color'][1][2], $params['color'][1][3]);
        $awl = (isset($params['awl']) && $params['awl']) ? ($params['linewidth'][1] / 2) : 0; //$adjust_with_linewidth
        //$awl = 0; //$adjust_with_linewidth
        $pdf->moveto($params['llx'] + $params['colwidth'] + $awl - $params['awlH'], $params['lly'] + $params['awlW']);
        $pdf->lineto($params['llx'] + $params['colwidth'] + $awl - $params['awlH'], $params['lly'] - $params['rowheight'] + $params['awlW']);
        $pdf->stroke();

        /*bottom line*/
        $pdf->setlinewidth($params['linewidth'][2]);
        $pdf->setcolor("stroke", $params['device'][2], $params['color'][2][0], $params['color'][2][1], $params['color'][2][2], $params['color'][2][3]);
        $awl = (isset($params['awl']) && $params['awl']) ? ($params['linewidth'][2] / 2) : 0; //$adjust_with_linewidth
        //$awl = 0; //$adjust_with_linewidth
        $pdf->moveto($params['llx'] - $params['awlH'], $params['lly'] - $params['rowheight'] - $awl + $params['awlW']);
        $pdf->lineto($params['llx'] + $params['colwidth'] - $params['awlH'] + $params['linewidth'][1], $params['lly'] - $params['rowheight'] - $awl + $params['awlW']);
        $pdf->stroke();

        /*left line*/
        $pdf->setlinewidth($params['linewidth'][3]);
        $pdf->setcolor("stroke", $params['device'][3], $params['color'][3][0], $params['color'][3][1], $params['color'][3][2], $params['color'][3][3]);
        $awl = (isset($params['awl']) && $params['awl']) ? ($params['linewidth'][3] / 2) : 0; //$adjust_with_linewidth
        $pdf->moveto($params['llx'] + $awl - $params['awlH'], $params['lly'] + $params['awlW']);
        $pdf->lineto($params['llx'] + $awl - $params['awlH'], $params['lly'] - $params['rowheight'] + $params['awlW']);
        $pdf->stroke();
    }

    private function getFontSize($pdf, $text, $limit, $fid, $fsize, $fspace)
    {
        if ($limit <= 0) {
            return $fsize;
        }
        for ($i = $fsize; $i > 0.1; $i = $i - 0.1) {
            $pdf->setfont($fid, $i);
            $pdf->set_value("charspacing", $fspace);

            $string_width = $this->getNewFontSize($pdf, "$text", $fid, $i);

            if ($string_width < $limit) {
                break;
            }
        }

        if ($i <= 0) {
            $i = 0.1;
        }

        return $i;
    }

    private function rotateImage90($sourceImageFile)
    {
        $img  = false;
        $info = $this->getImageInfo($sourceImageFile);
        $src  = false;
        # open image
        if ($info) {
            $w   = $info[0];
            $h   = $info[1];
            $src = $this->openImage($info, $sourceImageFile);
        }

        if ($src) {
            list($img, $alphacolor) = $this->createDraftImage($src, $w, $h);

            $rotation = imagerotate($img, 90, $alphacolor);

        }

        return $img;
    }

    private function unzipImagesArchive($archive, $extract_to)
    {
        $zip  = new \ZipArchive;
        $res  = $zip->open($archive);
        $dest = $extract_to . pathinfo($archive)['filename'];
        if ( ! is_dir($extract_to . '/' . pathinfo($archive)['filename'])) {
            mkdir($dest, 0777, true);
        }

        if ($res) {
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $entry = $zip->getNameIndex($i);
                if (substr($entry, -1) == '/') {
                    continue;
                } // skip directories
                if (strpos($entry, '/._') !== false) {
                    continue;
                } // skip mac files

                $fp = $zip->getStream($entry);

                $ofp = fopen($dest . '/' . basename($entry), 'w');

                if ( ! $fp) {
                    throw new Exception('Unable to extract the file.');
                    die('Unable to extract the file.');
                }

                while ( ! feof($fp)) {
                    fwrite($ofp, fread($fp, 8192));
                }

                fclose($fp);
                fclose($ofp);
            }
            $zip->close();
        } else {
            die("Unable to unzip");
        }

    }

    private function deleteFiles($dirPath)
    {
        if (file_exists($dirPath . '.zip')) {
            unlink($dirPath . ".zip");
        }
        if (is_dir($dirPath)) {
            if (substr($dirPath, strlen($dirPath) - 1, 1) != '/') {
                $dirPath .= '/';
            }

            $files = glob($dirPath . '*');
            foreach ($files as $file) {
                if (is_dir($file)) {
                    rmdir($file);
                } else {
                    unlink($file);
                }
            }
            rmdir($dirPath);
        }
    }

    private function deleteAllFiles($files = array())
    {
        if (is_array($files) && count($files)) {
            foreach ($files as $key => $file) {
                if (file_exists($file)) {
                    @unlink($file);
                }
            }
        }
    }

    private function drawTextBlockBorders($p, $params,$newBorder =0)
    {
        $generalOptions            = ' linecap=projecting linejoin=miter';
        $block_prop                = $params['block_prop'];
        $borderColorTemplateDevice = $params['device'];
        $borderColorTemplate       = $params['color'];
        $lineWidth                 = $params['line_width'];
        $rotateAngle               = $block_prop['rotateAngle'];
        $tetha                     = deg2rad($rotateAngle);
        if ($lineWidth > 0) {
            /*bottom line*/
            $ax = $block_prop['left'] ;
            $ay = $block_prop['top'];
            if($newBorder) {
                $block_prop['width']  += $lineWidth;
                $block_prop['height'] += $lineWidth;
                $ax = $block_prop['left'] - $lineWidth / 2;
                $ay = $block_prop['top'] - $lineWidth / 2;
            }

            $x    = $ax + $block_prop['width'] * cos($tetha);
            $y    = $ay + $block_prop['width'] * sin($tetha);
            $path = $p->add_path_point(0, $ax, $ay, "move",
                'linewidth=' . $lineWidth . ' strokecolor={' . $borderColorTemplateDevice . ' ' . $borderColorTemplate . '}' . $generalOptions);
            $path = $p->add_path_point($path, $x, $y, "line", "");
            /*right line*/
            $path  = $p->add_path_point($path, $x, $y, "move",
                'linewidth=' . $lineWidth . ' strokecolor={' . $borderColorTemplateDevice . ' ' . $borderColorTemplate . '}' . $generalOptions);
            $tetha = deg2rad($block_prop['rotateAngle'] + 90);
            $x     = $x + $block_prop['height'] * cos($tetha);
            $y     = $y + $block_prop['height'] * sin($tetha);
            $path  = $p->add_path_point($path, $x, $y, "line", "");
            /*top line*/
            $path  = $p->add_path_point($path, $x, $y, "move",
                'linewidth=' . $lineWidth . ' strokecolor={' . $borderColorTemplateDevice . ' ' . $borderColorTemplate . '}' . $generalOptions);
            $tetha = deg2rad($block_prop['rotateAngle']);
            $x     = $x - $block_prop['width'] * cos($tetha);
            $y     = $y - $block_prop['width'] * sin($tetha);
            /*left line*/
            $path  = $p->add_path_point($path, $x, $y, "line", "");
            $tetha = deg2rad($block_prop['rotateAngle'] + 90);
            $x     = $x - $block_prop['height'] * cos($tetha);
            $y     = $y - $block_prop['height'] * sin($tetha);
            $path  = $p->add_path_point($path, $x, $y, "line", "");
        }
        /*draw the border*/
        if ($lineWidth > 0) {
            $p->draw_path($path, 0, 0, "stroke");
        }
    }
    private function clipImage($pdf,$props){
        $width  = $props['width'];
        $height = $props['height'];
        $x  = $props['left'];
        $y  = $props['top'];
        $pdf->ellipse($x + $width / 2, $y + $height / 2, $width / 2, $height / 2);
        /* Set clipping path to defined path */
        $pdf->clip();
    }

    private function drawRectangle($p, $params)
    {
        $device = $params['device'];
        $color  = explode(" ", $params['color']);


        $block_prop = $params['block_prop'];
        $p->setcolor("fill", $device, $color[0], $color[1], $color[2], $color[3]);

        $rotateAngle = $block_prop['rotateAngle'];
        $tetha       = deg2rad($rotateAngle);

        /*bottom line*/
        $ax   = $block_prop['left'];
        $ay   = $block_prop['top'];
        $x    = $ax + $block_prop['width'] * cos($tetha);
        $y    = $ay + $block_prop['width'] * sin($tetha);
        $path = $p->add_path_point(0, $ax, $ay, "move", "");
        $path = $p->add_path_point($path, $x, $y, "line", "");

        /*right line*/

        $tetha = deg2rad($block_prop['rotateAngle'] + 90);
        $x     = $x + $block_prop['height'] * cos($tetha);
        $y     = $y + $block_prop['height'] * sin($tetha);
        $path  = $p->add_path_point($path, $x, $y, "line", "");
        /*top line*/

        $tetha = deg2rad($block_prop['rotateAngle']);
        $x     = $x - $block_prop['width'] * cos($tetha);
        $y     = $y - $block_prop['width'] * sin($tetha);
        /*left line*/
        $path  = $p->add_path_point($path, $x, $y, "line", "");
        $tetha = deg2rad($block_prop['rotateAngle'] + 90);
        $x     = $x - $block_prop['height'] * cos($tetha);
        $y     = $y - $block_prop['height'] * sin($tetha);
        $path  = $p->add_path_point($path, $x, $y, "line", "");

        $p->draw_path($path, 0, 0, "fill");


        $p->save();
        $p->restore();


        //$p->rect( $block_prop['left'], $block_prop['top'], $block_prop['width'], $block_prop['height'] );
        //$p->fill();
        //$p->save();
        //$p->restore();
    }

    protected function getTextOptions($textOptions)
    {
        $options = '';

        if (is_array($textOptions) && count($textOptions) > 1) {
            foreach ($textOptions as $opt) {
                $options .= $opt;
            }
        }

        return $options;
    }
    //not working with orientation because of trimbox and bleedbox are ignored so we need to reconstruct the page
    protected function rotatePrintPdf($file_path, $data){
        $data['pdfData'] = json_decode($data['pdfData'], true);

        $currentPdf              = null;
        $currentDoc              = null;
        $rotation_print_pdf      = isset($data['rotation_print_pdf']) && $data['rotation_print_pdf'] ? 1 : 0;
        $rotation_print_pdf_data = isset($data['rotation_print_pdf_data']) && $data['rotation_print_pdf_data'] ? $data['rotation_print_pdf_data'] : array();

        if ($rotation_print_pdf) {

            $this->startPdf(false, $currentPdf, $currentDoc, false, false, true, false, false);
            $indoc = $currentPdf->open_pdi_document($file_path, "");
            foreach ($data['pdfData'] as $pageKey => $pageData) {
				$orientation='';
                
                $optList = '';
                $width   = isset($pageData['isFromPdf']) && $pageData['isFromPdf'] ? $pageData['page_width_pdf'] : $pageData['page_width'];
                $height  = isset($pageData['isFromPdf']) && $pageData['isFromPdf'] ? $pageData['page_height_pdf'] : $pageData['page_height'];
				
				
				if(isset($rotation_print_pdf_data[$pageKey]) && $rotation_print_pdf_data[$pageKey]){
					switch((int)$rotation_print_pdf_data[$pageKey]){
						case 90:	
							$cpy_width   =  $width;
							$width		 =  $height;
							$height		 =  $cpy_width;
							if (isset($pageData['addBleed']) && $pageData['addBleed'] && $pageData['bleed']) {
								if (strpos($pageData['bleed'], ',') !== false) {
									$bleed_values 	   = explode(',', $pageData['bleed']);//left,top,right,bottom
									$pageData['bleed'] = $bleed_values[3].','.$bleed_values[0].','.$bleed_values[1].','.$bleed_values[2];
								}
							}
							$orientation = 'orientate=east';
							break;
						case -90:	
							$cpy_width   =  $width;
							$width		 =  $height;
							$height		 =  $cpy_width;
							if (isset($pageData['addBleed']) && $pageData['addBleed'] && $pageData['bleed']) {
								if (strpos($pageData['bleed'], ',') !== false) {
									$bleed_values 	   = explode(',', $pageData['bleed']);//left,top,right,bottom
									$pageData['bleed'] = $bleed_values[1].','.$bleed_values[2].','.$bleed_values[3].','.$bleed_values[0];
								}
							}
							$orientation = 'orientate=west';
							break;	
						default:
							break;
					}	
					
				}
                if (isset($pageData['addBleed']) && $pageData['addBleed'] && $pageData['bleed']) {

                    if (strpos($pageData['bleed'], ',') !== false) {
                        $bleed_values = explode(',', $pageData['bleed']);//left,top/right/bottom
                        //left,bottom/right/top

                        $bleed_string =" bleedbox={" . $bleed_values[0] . " " . $bleed_values[3] . " " . ($width - $bleed_values[2]) . " " . ($height - $bleed_values[1]) . "}";

                        if (isset($data['api_additional_parameters']) && is_array($data['api_additional_parameters']) && isset($data['api_additional_parameters']['ignore_bleed_in_pdf']) && $data['api_additional_parameters']['ignore_bleed_in_pdf']) {
                            $bleed_string='';
                        }
                        if (isset($data['api_additional_parameters']) && is_array($data['api_additional_parameters']) && isset($data['api_additional_parameters']['zero_bleed']) && $data['api_additional_parameters']['zero_bleed']) {
                            $bleed_string  = " bleedbox={0 0 " . ($width) . " " . ($height) . "}";
                        }
                        $optList .= $bleed_string;
                        if (isset($data['api_additional_parameters']) && is_array($data['api_additional_parameters']) && isset($data['api_additional_parameters']['trimbox_as_bleed']) && $data['api_additional_parameters']['trimbox_as_bleed']) {
                            $optList .= " trimbox={" . $bleed_values[0] . " " . $bleed_values[3] . " " . ($width - $bleed_values[2]) . " " . ($height - $bleed_values[1]) . "}";
                        }

                    } else {
                        $bleed_string  = " bleedbox={" . $pageData['bleed'] . " " . $pageData['bleed'] . " " . ($width - $pageData['bleed']) . " " . ($height - $pageData['bleed']) . "}";

                        if (isset($data['api_additional_parameters']) && is_array($data['api_additional_parameters']) && isset($data['api_additional_parameters']['ignore_bleed_in_pdf']) && $data['api_additional_parameters']['ignore_bleed_in_pdf']) {
                            $bleed_string='';
                        }
                        if (isset($data['api_additional_parameters']) && is_array($data['api_additional_parameters']) && isset($data['api_additional_parameters']['zero_bleed']) && $data['api_additional_parameters']['zero_bleed']) {
                            $bleed_string  = " bleedbox={0 0 " . ($width) . " " . ($height) . "}";
                        }
                        $optList .= $bleed_string;

                        if (isset($data['api_additional_parameters']) && is_array($data['api_additional_parameters']) && isset($data['api_additional_parameters']['trimbox_as_bleed']) && $data['api_additional_parameters']['trimbox_as_bleed']) {
                            $optList .= " trimbox={" . $pageData['bleed'] . " " . $pageData['bleed'] . " " . ($width - $pageData['bleed']) . " " . ($height - $pageData['bleed']) . "}";
                        }
                    }
                }

                if($this->customPdfVersion && strpos($this->customPdfVersion, 'pdfx=PDF/X-4')!==false ){
                    $icc = $currentPdf->load_iccprofile("sRGB", "");
                    $optList.=' defaultrgb='.$icc;
                }
                $masterPdfPage = $currentPdf->open_pdi_page($indoc, ($pageKey + 1), "");
                if ($masterPdfPage == 0) {
                    throw new \Exception("Error: %s\n", $masterPdfPage->get_errmsg());
                }
                $currentPdf->begin_page_ext($width, $height, $optList);
				$currentPdf->fit_pdi_page($masterPdfPage, 0, 0, $orientation);

                $currentPdf->close_pdi_page($masterPdfPage);

                $currentPdf->end_page_ext("");
            }

            $currentPdf->end_document('');
            $buffer = $currentPdf->get_buffer();
            $file_path = ROOT_PATH . $this->pdfResultFolder . $data['selection'] . '.pdf';
            if (!file_put_contents($file_path, $buffer)) {
                throw new \Exception('Split Pdf right error!');
            }
        }
    }
	protected function createFruchtbonbons($file_path_pdf, $data){
			$currentDoc 					= null;
			$pdf							= null;
			
			$this->startPdf(false, $pdf, $currentDoc, false, false, true, false, false);
					
			$this->loadFonts( $data, $pdf );
				
			$indoc = $pdf->open_pdi_document( $file_path_pdf, "" );
							
			$page_p = $pdf->open_pdi_page( $indoc, 1 , "" );
			
			
			if( $page_p == 0 ) {
				throw new Exception( "Error: %s\n", $page_p->get_errmsg() );
			}
			
			$width  = $pdf->pcos_get_number( $indoc, "pages[0]/width" );
			$height = $pdf->pcos_get_number( $indoc, "pages[0]/height" );
			
			
			$pdf->begin_page_ext( $width, $height*4, "" );
			
			for($i=0; $i<4; $i++){
					$pdf->fit_pdi_page( $page_p, 0, $i*$height, "boxsize={" . $width . " " . $height . "} fitmethod=entire" );
			}
			
			$pdf->end_page_ext( "" );
			
			$pdf->end_document( "" );
					
			$buf = $pdf->get_buffer();
			
			
			
			$file_path = ROOT_PATH . $this->pdfResultFolder . $data['selection'] . '.pdf';
			
            if (!file_put_contents($file_path, $buf)) {
                throw new \Exception('Fruchtbonbons error!');
            }
			
	}
    protected function splitTilesPrintPdf($file_path, $data)
    {
        $currentPdf = null;
        $currentDoc = null;
        $mm_to_px_adjustment = 3.779527559 * 0.75;

        $this->startPdf(false, $currentPdf, $currentDoc, false, false, true, false, false);
        if (file_exists($file_path)) {
            $indoc = $currentPdf->open_pdi_document($file_path, "");

            if (isset($data['split_tiles_print_pdf_data']) && count($data['split_tiles_print_pdf_data'])) {
                foreach ($data['split_tiles_print_pdf_data'] as $split_key => $split_data) {
                    $page_idx = isset($split_data['page_key']) && $split_data['page_key'] ? $split_data['page_key'] : 1;

                    $masterPdfPage = $currentPdf->open_pdi_page($indoc, $page_idx, "");
                    if ($masterPdfPage == 0) {
                        throw new \Exception("Error: %s\n", $masterPdfPage->get_errmsg());
                    }
                    $opt_list = '';
                    $crop_data = isset($split_data['crop_data']) && $split_data['crop_data'] ? $split_data['crop_data'] : false;
                    if (!$crop_data) {
                        throw new \Exception("Crop data not provided\n");
                    }
                    $page_width = $crop_data['width'] * $mm_to_px_adjustment;
                    $page_height = $crop_data['height'] * $mm_to_px_adjustment;
                    if (isset($split_data['bleed']) && $split_data['bleed']) {
                        $bleed_value = $split_data['bleed'] * $mm_to_px_adjustment;

                        $opt_list .= " bleedbox={" . $bleed_value . " " . $bleed_value . " " . ($page_width - $bleed_value) . " " . ($page_height - $bleed_value) . "}";

                        if (isset($data['api_additional_parameters']) && is_array($data['api_additional_parameters']) && isset($data['api_additional_parameters']['trimbox_as_bleed']) && $data['api_additional_parameters']['trimbox_as_bleed']) {
                            $opt_list .= " trimbox={" . $bleed_value . " " . $bleed_value . " " . ($page_width - $bleed_value) . " " . ($page_height - $bleed_value) . "}";
                        }
                    }

                    $currentPdf->begin_page_ext($page_width, $page_height, $opt_list);

                    $left_crop = $crop_data['x'] * $mm_to_px_adjustment;
                    $top_crop = $crop_data['y'] * $mm_to_px_adjustment;
                    $width_crop = $crop_data['width'] * $mm_to_px_adjustment;
                    $height_crop = $crop_data['height'] * $mm_to_px_adjustment;

                    $currentPdf->fit_pdi_page($masterPdfPage, 0, 0, " fitmethod=nofit matchbox={clipping={ {$left_crop} {$top_crop}  ".($left_crop + $width_crop)." ".($height_crop + $top_crop)." }}");
                    $currentPdf->end_page_ext("");
                }
            }

            $currentPdf->end_document('');
            $buffer = $currentPdf->get_buffer();
            $file_path = ROOT_PATH . $this->pdfResultFolder . $data['selection'] . '.pdf';
            if (!file_put_contents($file_path, $buffer)) {
                throw new \Exception('Split Pdf right error!');
            }
        }
    }

    protected function splitPdfForApi($file_path, $data, $pagesOrder)
    {
        $data['pdfData'] = json_decode($data['pdfData'], true);
        $pdf_paths       = [];

        $currentPdf = null;
        $currentDoc = null;
        $this->startPdf(false, $currentPdf, $currentDoc, false, false, true, false, false);
        $indoc = $currentPdf->open_pdi_document($file_path, "");


        foreach ($data['pdfData'] as $pageKey => $pageData) {

            $masterPdfPage = $currentPdf->open_pdi_page($indoc, ($pageKey + 1), "");
            if ($masterPdfPage == 0) {
                throw new \Exception("Error: %s\n", $masterPdfPage->get_errmsg());
            }
            $width_full_page  = isset($pageData['isFromPdf']) && $pageData['isFromPdf'] ? $pageData['page_width_pdf'] : $pageData['page_width'];
            $height_full_page = isset($pageData['isFromPdf']) && $pageData['isFromPdf'] ? $pageData['page_height_pdf'] : $pageData['page_height'];
            $left_bleed       = 0;
            $right_bleed      = 0;
            if (isset($pageData['addBleed']) && $pageData['addBleed'] && $pageData['bleed']) {
                if (strpos($pageData['bleed'], ',') !== false) {
                    $bleed_values = explode(',', $pageData['bleed']);//left,bottom/right/top
                    //left,bottom/right/top
                    $left_bleed  = $bleed_values[0];
                    $right_bleed = $bleed_values[2];//we store like left,top,right,bottom in $pageData['bleed']
                } else {
                    $left_bleed  = $pageData['bleed'];
                    $right_bleed = $pageData['bleed'];
                }
            }//left_page;
            $left_page_width  = ($width_full_page - $left_bleed - $right_bleed) / 2 + $left_bleed;
            $left_page_height = $height_full_page;
            $optList_left     = '';
            if (isset($pageData['addBleed']) && $pageData['addBleed'] && $pageData['bleed']) {

                if (strpos($pageData['bleed'], ',') !== false) {
                    $bleed_values = explode(',', $pageData['bleed']);//left,bottom/right/top
                    //left,bottom/right/top
                    $optList_left = " bleedbox={" . $bleed_values[0] . " " . $bleed_values[3] . " " . $left_page_width . " " . ($left_page_height - $bleed_values[1]) . "}";
					
					if (isset($data['api_additional_parameters']) && is_array($data['api_additional_parameters']) && isset($data['api_additional_parameters']['trimbox_as_bleed']) && $data['api_additional_parameters']['trimbox_as_bleed']) {
                         $optList_left .= " trimbox={" . $bleed_values[0] . " " . $bleed_values[3] . " " . $left_page_width . " " . ($left_page_height - $bleed_values[1]) . "}";
                    }
					
                } else {
                    $optList_left = " bleedbox={" . $pageData['bleed'] . " " . $pageData['bleed'] . " " . $left_page_width . " " . ($left_page_height - $pageData['bleed']) . "}";
					
					if (isset($data['api_additional_parameters']) && is_array($data['api_additional_parameters']) && isset($data['api_additional_parameters']['trimbox_as_bleed']) && $data['api_additional_parameters']['trimbox_as_bleed']) {
                         $optList_left .= " trimbox={" . $pageData['bleed'] . " " . $pageData['bleed'] . " " . $left_page_width . " " . ($left_page_height - $pageData['bleed']) . "}";
                    }
                }
            }
            $currentPdf->begin_page_ext($left_page_width, $left_page_height, $optList_left);
            $currentPdf->fit_pdi_page($masterPdfPage, 0, 0, "boxsize={" . $width_full_page . " " .
                                                            $height_full_page . "} fitmethod=nofit matchbox={clipping={0 0 " . $left_page_width . " " . $height_full_page . "}}");
            $currentPdf->end_page_ext("");

            $right_page_width  = ($width_full_page - $left_bleed - $right_bleed) / 2 + $right_bleed;
            $right_page_height = $height_full_page;
            $optList_right     = '';
            if (isset($pageData['addBleed']) && $pageData['addBleed'] && $pageData['bleed']) {

                if (strpos($pageData['bleed'], ',') !== false) {
                    $bleed_values = explode(',', $pageData['bleed']);//left,top/right/bottom
                    //pdflib is left/top/right/bottom

                    $optList_right = " bleedbox={0 " . $bleed_values[3] . " " . ($right_page_width - $bleed_values[2]) . " " . ($right_page_height - $bleed_values[1]) . "}";
					
					if (isset($data['api_additional_parameters']) && is_array($data['api_additional_parameters']) && isset($data['api_additional_parameters']['trimbox_as_bleed']) && $data['api_additional_parameters']['trimbox_as_bleed']) {
                         $optList_right .= " trimbox={0 " . $bleed_values[3] . " " . ($right_page_width - $bleed_values[2]) . " " . ($right_page_height - $bleed_values[1]) . "}";
                    }
					
                } else {
                    $optList_right = " bleedbox={0 " . $pageData['bleed'] . " " . ($right_page_width - $pageData['bleed']) . " " . ($right_page_height - $pageData['bleed']) . "}";;
					
					if (isset($data['api_additional_parameters']) && is_array($data['api_additional_parameters']) && isset($data['api_additional_parameters']['trimbox_as_bleed']) && $data['api_additional_parameters']['trimbox_as_bleed']) {
                         $optList_right .= " trimbox={0 " . $pageData['bleed'] . " " . ($right_page_width - $pageData['bleed']) . " " . ($right_page_height - $pageData['bleed']) . "}";;
                    }
                }
            }
            $currentPdf->begin_page_ext($right_page_width, $right_page_height, $optList_right);
            $currentPdf->fit_pdi_page($masterPdfPage, 0, 0, "boxsize={" . $width_full_page . " " . $height_full_page . "} " .
                                                            "fitmethod=nofit " .
                                                            "matchbox={clipping={" . ($width_full_page - $right_page_width) . " 0 " . $width_full_page . " " . $height_full_page . "}}");
            $currentPdf->end_page_ext("");

            /*  $currentPdf->end_document('');
            $buffer      = $currentPdf->get_buffer();
            $rightPath   = ROOT_PATH . $this->pdfResultFolder . "$data[selection]_{$pageKey}_right.pdf";
            if ( ! file_put_contents($rightPath, $buffer)) {
                throw new \Exception('Split Pdf right error!');
            }
            $pdf_paths[$pageKey . '_right'] = basename($rightPath);
            */
        }
        $currentPdf->end_document('');
        $buffer    = $currentPdf->get_buffer();
        $file_path = ROOT_PATH . $this->pdfResultFolder . $data['selection'] .'.pdf';
        if ( ! file_put_contents($file_path, $buffer)) {
            throw new \Exception('Split Pdf right error!');
        }
        $masterPdfPage = null;

        if ($pagesOrder) {
            $file_path = $this->rearrangePages($file_path, $pagesOrder);
        }

        return $file_path;
    }

    /**
     * @param string $file_path
     * @param array $pagesOrder Array containing pages numbers as values. Eg: [2,3,4,5,6,7,8,1]
     *
     * @return string
     * @throws \Exception
     */
    public function rearrangePages($file_path, $pagesOrder)
    {
        $currentPdf = null;
        $currentDoc = null;
        $this->startPdf(false, $currentPdf, $currentDoc, false, false, true, false, false);
        $indoc = $currentPdf->open_pdi_document($file_path, '');
        if ($indoc == 0) {
            throw new \Exception('Error: ' . $currentPdf->get_errmsg());
        }
        foreach ($pagesOrder as $key => $pageNumber) {
            /* Dummy page size; will be adjusted later */
            $currentPdf->begin_page_ext(10, 10, '');

            $pageHandle = $currentPdf->open_pdi_page($indoc, $pageNumber, 'cloneboxes');
            if ($pageHandle == 0) {
                throw new \Exception('Error opening page: ' . $currentPdf->get_errmsg());
            }
            /**
             * Place the imported page on the output page, and adjust
             * the page size
             */
            $currentPdf->fit_pdi_page($pageHandle, 0, 0, 'adjustpage cloneboxes');
            $currentPdf->close_pdi_page($pageHandle);
            $currentPdf->end_page_ext('');
        }

        $currentPdf->end_document('');
        $buffer = $currentPdf->get_buffer();
        if ( ! file_put_contents($file_path, $buffer)) {
            throw new \Exception('Split Pdf error!');
        }
        return $file_path;
    }


    protected function addBlankPages($originalPdfData,$blankPagesIndexes = array()){


        $newPdfData = array();

        if (is_array($originalPdfData) && count($originalPdfData)) {
            foreach ($originalPdfData as $key => $value) {
                $page_index = (int)$key;
                if (in_array($page_index, $blankPagesIndexes)) {
                    foreach ($blankPagesIndexes as $index => $value) {
                        $index_to_insert = (int)$value;
                        if ($index_to_insert === $page_index) {
                            if (isset($originalPdfData[$page_index - 1])) {
                                $newPage =$originalPdfData[$page_index - 1];
                            } else {
                                $newPage = $originalPdfData[$page_index ];
                            }
                            $newPage['blocks'] = array();
                            array_push($newPdfData, $newPage);
                        }
                    }
                }
                array_push($newPdfData, $originalPdfData[$key]);

                if($page_index+1 == count($originalPdfData) && in_array( count($originalPdfData), $blankPagesIndexes)){
                    foreach ($blankPagesIndexes as $index => $value) {
                        $index_to_insert = (int)$value;
                        $original_idx = count($originalPdfData);
                        if ($index_to_insert ===  $original_idx) {
                            if (isset($originalPdfData[$original_idx - 1])) {
                                $newPage =$originalPdfData[$original_idx - 1];
                                $newPage['blocks'] = array();
                                array_push($newPdfData, $newPage);
                            }
                        }
                    }
                }
            }
        }


        return $newPdfData;
    }

    protected function createSvgFromJson($data, $doc, $pdf, $pathFolder, $clean)
    {
        $microtime_start = str_replace('.', '', microtime(1));

        $data['pdfData'] = json_decode($data['pdfData'], true);
         $storedImages    = array();
        $diecut_pacific_tmp_file = false;
        if(isset($data['pacific_diecut_pdf_content']) && $data['pacific_diecut_pdf_content']){
            $diecut_pacific_tmp_file = ROOT_PATH . '/data/pdfs/tmp/'.'pacific_diecut_pdf'.microtime(true).rand(1,10000).'.pdf';
            file_put_contents($diecut_pacific_tmp_file,base64_decode($data['pacific_diecut_pdf_content']));
        }

        $blankPages           = isset($data['blankPages']) && is_array($data['blankPages']) && count($data['blankPages']) ? $data['blankPages'] : false;


        if($blankPages){
            $data['pdfData'] = $this->addBlankPages($data['pdfData'],$blankPages);

        }


       
        $applyWatermarkOnTheSamePdf      = isset($data['applyWatermarkOnTheSamePdf']) && $data['applyWatermarkOnTheSamePdf'] ? $data['applyWatermarkOnTheSamePdf'] : false;

        $use_pdvt_designer     = ( isset( $data['use_pdvt_designer'] ) && $data['use_pdvt_designer'] ) ? 1 : 0;
        $countPdfSheets        = 1;
        if( $use_pdvt_designer ) {
                if( isset( $data['pdf_vt_values_designer'] ) && count( $data['pdf_vt_values_designer'] ) ) {
                    $this->csv_block_values = $data['pdf_vt_values_designer'];
                }
                if( isset( $data['pdvt_count_designer'] ) && $data['pdvt_count_designer'] ) {
                    $countPdfSheets = $data['pdvt_count_designer'];
                }
        }


        if($use_pdvt_designer){
            $pdf->begin_dpart( "" );

        }
        for($designerCounter = 1; $designerCounter <= $countPdfSheets; $designerCounter++) {
        foreach ($data['pdfData'] as $pageKey => $pageData) {

            $pageEditorWidth  = 0;
            $pageEditorHeight = 0;
            if ($data['use_pdf']) {

                    if ($use_pdvt_designer) {
                        $page = $pdf->open_pdi_page($doc, $pageKey + 1, "cloneboxes pdfvt={scope=global environment={Cloudlab}}");
                    } else {
                $page   = $pdf->open_pdi_page($doc, $pageKey + 1, "cloneboxes");
                    }

                    $optList = '';
                    if ($use_pdvt_designer) {
                        $pdf->begin_dpart("");

                        $icc = $pdf->load_iccprofile("sRGB", "");
                        $optList = $optList . " defaultrgb=" . $icc;
                    }

                $width  = $pdf->pcos_get_number($doc, "pages[$pageKey]/width");
                $height = $pdf->pcos_get_number($doc, "pages[$pageKey]/height");
                    $pdf->begin_page_ext($width, $height, $optList);
                $pdf->fit_pdi_page($page, 0.0, 0.0, "cloneboxes");


                $pageEditorWidth  = $width;
                $pageEditorHeight = $height;

            } else {
                $optList = '';
                $width   = isset($pageData['isFromPdf']) && $pageData['isFromPdf'] ? $pageData['page_width_pdf'] : $pageData['page_width'];
                $height  = isset($pageData['isFromPdf']) && $pageData['isFromPdf'] ? $pageData['page_height_pdf'] : $pageData['page_height'];

                if (isset($pageData['addTrimBox']) && $pageData['addTrimBox'] && $pageData['trimBox']) {
                    $optList = " trimbox={" . $pageData['trimBox'][0] . " " . $pageData['trimBox'][1] . " " . $pageData['trimBox'][2] . " " . $pageData['trimBox'][3] . "}";;
                }
                if (isset($pageData['addBleed']) && $pageData['addBleed'] && $pageData['bleed']) {

                    if (strpos($pageData['bleed'], ',') !== false) {
                        $bleed_values = explode(',', $pageData['bleed']);//left,bottom/right/top
                        //left,bottom/right/top

                        $bleed_string =" bleedbox={" . $bleed_values[0] . " " . $bleed_values[3] . " " . ($width - $bleed_values[2]) . " " . ($height - $bleed_values[1]) . "}";

                        if (isset($data['api_additional_parameters']) && is_array($data['api_additional_parameters']) && isset($data['api_additional_parameters']['ignore_bleed_in_pdf']) && $data['api_additional_parameters']['ignore_bleed_in_pdf']) {
                            $bleed_string='';
                        }
                        if (isset($data['api_additional_parameters']) && is_array($data['api_additional_parameters']) && isset($data['api_additional_parameters']['zero_bleed']) && $data['api_additional_parameters']['zero_bleed']) {
                            $bleed_string  = " bleedbox={0 0 " . ($width) . " " . ($height) . "}";
                        }
                        $optList .= $bleed_string;
                        if (isset($data['api_additional_parameters']) && is_array($data['api_additional_parameters']) && isset($data['api_additional_parameters']['trimbox_as_bleed']) && $data['api_additional_parameters']['trimbox_as_bleed']) {
                            $optList .= " trimbox={" . $bleed_values[0] . " " . $bleed_values[3] . " " . ($width - $bleed_values[2]) . " " . ($height - $bleed_values[1]) . "}";
                        }

                    } else {
                        $bleed_string  = " bleedbox={" . $pageData['bleed'] . " " . $pageData['bleed'] . " " . ($width - $pageData['bleed']) . " " . ($height - $pageData['bleed']) . "}";

                        if (isset($data['api_additional_parameters']) && is_array($data['api_additional_parameters']) && isset($data['api_additional_parameters']['ignore_bleed_in_pdf']) && $data['api_additional_parameters']['ignore_bleed_in_pdf']) {
                            $bleed_string='';
                        }
                        if (isset($data['api_additional_parameters']) && is_array($data['api_additional_parameters']) && isset($data['api_additional_parameters']['zero_bleed']) && $data['api_additional_parameters']['zero_bleed']) {
                            $bleed_string  = " bleedbox={0 0 " . ($width) . " " . ($height) . "}";
                        }
                        $optList .= $bleed_string;

                        if (isset($data['api_additional_parameters']) && is_array($data['api_additional_parameters']) && isset($data['api_additional_parameters']['trimbox_as_bleed']) && $data['api_additional_parameters']['trimbox_as_bleed']) {
                            $optList .= " trimbox={" . $pageData['bleed'] . " " . $pageData['bleed'] . " " . ($width - $pageData['bleed']) . " " . ($height - $pageData['bleed']) . "}";
                        }
                    }


                }

                if($this->customPdfVersion && strpos($this->customPdfVersion, 'pdfx=PDF/X-4')!==false ){
                    $icc = $pdf->load_iccprofile("sRGB", "");
                    $optList.=' defaultrgb='.$icc;
                    }
                    if ($use_pdvt_designer) {
                        $pdf->begin_dpart("");

                        $icc = $pdf->load_iccprofile("sRGB", "");
                        $optList = $optList . " defaultrgb=" . $icc;
                }
                $pdf->begin_page_ext($width, $height, $optList);
            }

            $pageEditorWidth  = $width;
            $pageEditorHeight = $height;

            if (isset($pageData['layout']) && is_array($pageData['layout']) && isset($pageData['layout']['src']) && $pageData['layout']['src']) {


                $indoc = $pdf->open_pdi_document(ROOT_PATH . $this->pdfSearchPath . $pageData['layout']['src'], "");
                if (isset($pageData['multiple_pages_layout']) && $pageData['multiple_pages_layout']) {
                    $page = $pdf->open_pdi_page($indoc, ($pageKey + 1), "");
                } else {
                    $page = $pdf->open_pdi_page($indoc, 1, "");
                }

                if ($page == 0) {
                    throw new Exception("Error: %s\n", $page->get_errmsg());
                }
                $pdf->fit_pdi_page($page, 0, 0, "boxsize={" . $width . " " . $height . "} fitmethod=entire");

            }
            $gstateTransparentOpacityText       = $pdf->create_gstate("opacityfill=0 overprintfill=false overprintmode=0");
            $gstateTransparentOpacityTextRevert = $pdf->create_gstate("opacityfill=1 overprintfill=false overprintmode=0");

            if (isset($pageData['page_refinement']) && $pageData['page_refinement'] && is_array($pageData['page_refinement'])) {
                $gstateOverprint         = $pdf->create_gstate("opacityfill=1 overprintfill=true overprintmode=1");
                $gstateRefinementOpacity = $pdf->create_gstate("opacityfill=0 overprintfill=false overprintmode=0");
                $pdf->save();
                $pdf->set_gstate($gstateOverprint);

                $spotColor = explode(" ", $pageData['page_refinement']['cmyk_value']);

                $pdf->setcolor("fill", "cmyk", $spotColor[0], $spotColor[1], $spotColor[2], $spotColor[3]);
                $spot = $pdf->makespotcolor($pageData['page_refinement']['spot_name']);
            }

            if (is_array($pageData['blocks']) && count($pageData['blocks']) > 0) {
                foreach ($pageData['blocks'] as $block) {

                    if (isset($block['opacity']) && $block['opacity'] !== 1) {

                        $gstate = $pdf->create_gstate("opacityfill=" . $block['opacity']);
                        $pdf->save();
                        $pdf->set_gstate($gstate);
                    }

                    switch (strtolower($block['type'])) {

                        case 'pdf':

                            if (isset($block['src'])) {

                                $pdfSrc = '';
                                if ( ! empty($block['is_base_64']) && $block['is_base_64']) {
                                    //store base64 image to file
                                    $pdfSrc = $pathFolder . md5($block['src']);
                                    if (!file_exists($pdfSrc)) {
										
                                        file_put_contents($pdfSrc, fopen($block['src'], 'r'));
                                    }
                                } else {
                                    $pdfSrc = ROOT_PATH . $this->pdfSearchPath . 'tmp/' . $block['src'];
                                }
                                $this->filesToDelete[] = $pdfSrc;

                                $indoc   = $pdf->open_pdi_document($pdfSrc, "");
                                $pdfPage = isset($block['pdfPage']) ? $block['pdfPage'] : 1;
                                $page_p  = $pdf->open_pdi_page($indoc, $pdfPage, "");
                                if ($page_p == 0) {

                                    throw new \Exception("Error: ". $pdf->get_errmsg()."\n");
                                }
                                $flipX = $block['flipX'];
                                $flipY = $block['flipY'];
                                $pdf->fit_pdi_page($page_p, $block['x'], $block['y'],
                                    "boxsize={" . $block['width'] . " " . $block['height'] . "} fitmethod=entire scale={ $flipX $flipY } rotate=" . $block['angle']);
                            }
                            break;
                        case 'graphics':

                            $svg                    = tempnam("/tmp", "SVG");
                            $this->filesToDelete[]  = $svg;
                            file_put_contents($svg, $block['svg']);

                            $optlist = "boxsize={ " . $block['width'] . " " . $block['height'] .
                                "} position={center} fitmethod=entire ";

                            if(isset($block['forceAngle']) && $block['forceAngle'] && isset($block['angle']) && $block['angle']){
                                $optlist.=' rotate='.$block['angle'];
                            }
							if (isset($block['clipPathSibling']) && $block['clipPathSibling']) {
                                $this->startClipPath($pdf, $width, $height, $block['clipPathSibling']);
                            }  
							
                            $graphics = $pdf->load_graphics("auto", $svg, "");


                            if (isset($block['fillOptions']) && $block['fillOptions'] && $block['fillOptions']['spotname']) {
                                $color = explode(' ', $block['fillOptions']['originalcolor']);

                                $pdf->setcolor("fill", "cmyk", $color[0], $color[1], $color[2], $color[3]);
                                $spot = $pdf->makespotcolor($block['fillOptions']['spotname']);
                            }

                            if(isset($block['allFillOptions']) && count($block['allFillOptions'])){
                                foreach ($block['allFillOptions'] as $key_b=>$subFillOptions){
                                    if(isset($subFillOptions['originalcolor'])){
                                        $color = explode(' ', $subFillOptions['originalcolor']);
                                        if(count($color) > 3){
                                            $pdf->setcolor("fill", "cmyk", $color[0], $color[1], $color[2], $color[3]);
                                        }
                                        if(isset($subFillOptions['spotname']) && $subFillOptions['spotname']){
                                            $spot = $pdf->makespotcolor($subFillOptions['spotname']);
                                        }
                                    }
                                }
                            }
                            if(isset($block['indesign_graphics']) && $block['indesign_graphics']){
                                $optlist .= ' rotate=' . $block['angle'];
                            }else{
                                if (isset($block['hasRefinement']) && $block['hasRefinement'] && isset($block['angle']) && $block['angle']) {
                                    $optlist .= ' rotate=' . $block['angle'];
                                }
                            }

                            if ($pdf->info_graphics($graphics, "fittingpossible", '') == 1) {
                                $pdf->fit_graphics($graphics, $block['x'], $block['y'], $optlist);
                            } else {
                                print_r($pdf->get_errmsg());
                            }
							
							if (isset($block['clipPathSibling']) && $block['clipPathSibling']) {
                                $pdf->restore();
                            }
							
                            break;
                        case 'graphics_varnish':

                            $svg = tempnam("/tmp", "SVG");
                            $this->filesToDelete[]  = $svg;
                            file_put_contents($svg, $block['svg']);

                            $optlist = "boxsize={ " . $block['width'] . " " . $block['height'] .
                                "} position={center} fitmethod=entire ";


                            $graphics = $pdf->load_graphics("auto", $svg, "");

                            $pdf->setcolor("fillstroke", "cmyk", 0.62, 0.16, 0.11, 0);
                            $spot = $pdf->makespotcolor("Lack");

                            if ($pdf->info_graphics($graphics, "fittingpossible", '') == 1) {
                                $pdf->fit_graphics($graphics, $block['x'], $block['y'], $optlist);
                            } else {
                                print_r($pdf->get_errmsg());
                            }
                            break;
                        case 'logoblock':

                            $svg = tempnam("/tmp", "SVG");
                            $this->filesToDelete[]  = $svg;
                            file_put_contents($svg, $block['svg']);
                            $optlist = "boxsize={ " . $block['width'] . " " . $block['height'] .
                                "} position={center} fitmethod=entire ";
                            if (isset($block['angle']) && $block['angle']) {
                                $optlist .= ' rotate=' . $block['angle'];
                            }

                            $graphics = $pdf->load_graphics("auto", $svg, "");

                            if ($pdf->info_graphics($graphics, "fittingpossible", '') == 1) {
                                $pdf->fit_graphics($graphics, $block['x'], $block['y'], $optlist);
                            } else {
                                print_r($pdf->get_errmsg());
                            }
                            break;
                        case 'image':

                            $imageSrc = '';

                            if(isset($block['isApiImage']) && $block['isApiImage']){
                                if(isset($block['apiOriginalImageUrl']) && $block['apiOriginalImageUrl']!=""){
                                    $filename = md5($block['apiOriginalImageUrl']);
                                    if(!file_exists($pathFolder.$filename)){
                                        $put = copy($block['apiOriginalImageUrl'],$pathFolder. $filename);
                                        if(!$put){
                                            break;
                                        }
                                    }
                                    $block['src']        = $filename;
                                }
                            }
                            if ( ! empty($block['is_base_64']) && $block['is_base_64']) {
                                //store base64 image to file
                                $imageSrc = $pathFolder . md5($block['src']).'_'.$microtime_start;
                                if (!file_exists($imageSrc) ) {
								//	$block['src'] = str_replace("flyeralarm.createx-editor.com","s3.eu-central-1.wasabisys.com/flyeralarm.createx-editor.com",$block['src'] );
									//$block['src'] = str_replace("gallery.createx-editor.com","s3.eu-central-1.wasabisys.com/gallery.createx-editor.com",$block['src'] );
                                    $resourceFile =  fopen($block['src'], 'r');
                                    if(!$resourceFile){
                                        $resourceFile =  fopen($block['src'], 'r'); //we try one more time
                                        if(!$resourceFile){
                                            throw new \Exception("Error: Image not found: ".$block['src']);
                                        }
                                    }
                                    if(!file_put_contents($imageSrc, $resourceFile)){
                                        throw new \Exception("Error: Image can not be stored! ".$block['src']);
                                    }
                                }
                            } else {
                                $imageSrc = $pathFolder . $block['src'];
                            }

                            if ($imageSrc) {
                                $this->filesToDelete[] = $imageSrc;
                                $cx               = $block['cx'];
                                $cy               = $block['cy'];
                                $cw               = $block['cw'];
                                $ch               = $block['ch'];
                                $flipX            = $block['flipX'];
                                $flipY            = $block['flipY'];
                                $brightness_value = (isset($block['brightness']) && $block['brightness']) ? $block['brightness'] : 0;
                                $contrast_value   = (isset($block['contrast']) && $block['contrast']) ? $block['contrast'] : 0;


                                if (isset($block['filter']) && $block['filter']) {
                                    $prepared_image = $this->prepareImageForEffect($imageSrc);
                                    if($prepared_image){

                                        $this->filesToDelete[] = $prepared_image;
                                        $imageSrc = $this->filterImageDesigner($block['filter'], array('original_file_src' => $prepared_image));

                                        $this->prepareImageForEffectAddProfile($imageSrc);

                                    }else{
                                        $imageSrc = $this->filterImageDesigner($block['filter'], array('original_file_src' => $imageSrc));
                                    }

                                    $this->filesToDelete[] = $imageSrc;

                                }

                                if ($brightness_value != 0 || $contrast_value != 0) {

                                    $imageSrc = $this->setBrighnessContrast(array(
                                        'brightness' => $brightness_value / 100,
                                        'contrast'   => $contrast_value / 100
                                    ), $imageSrc);

                                }
								if (isset($block['clipPathSibling']) && $block['clipPathSibling']) {
                                    $this->startClipPath($pdf, $width, $height, $block['clipPathSibling']);
                                }
                                if (isset($block['hasClippingMask']) && $block['hasClippingMask']) {
                                    $svg = tempnam("/tmp", "SVG");
                                    $this->filesToDelete[]  = $svg;
                                    $block['svg'] = str_replace('{{imageName}}', $imageSrc, $block['svg']);;

                                    file_put_contents($svg, $block['svg']);
                                    //file_put_contents("/var/www/clpers.kundenmaschine.de/restserver_external/test.txt", $block['svg']);

                                    $optlist  = "boxsize={ " . $block['width'] . " " . $block['height'] .
                                        "} position={center} fitmethod=entire ";

                                    /*if($this->customPdfVersion && strpos($this->customPdfVersion, 'pdfx=PDF/X-4')!==false ){
                                        $icc = $pdf->load_iccprofile("sRGB", "");
                                        $optListG=' defaultrgb='.$icc;

                                    }else{
                                        $graphics = $pdf->load_graphics("auto", $svg, "defaultimageoptions={iccprofile=srgb}");    
                                    }*/

                                   // $graphics = $pdf->load_graphics("auto", $svg, 'defaultimageoptions={ignoremask=true}');

                                    $imageDetails = getimagesize($imageSrc);
                                    $mime         = $imageDetails && isset($imageDetails['mime']) ? $imageDetails['mime'] : false;
                                    $image   = $pdf->load_image("auto", $imageSrc, "");
                                    if( !$image ){
                                        $this->removeProfile($imageSrc);
                                        $image = $pdf->load_image("auto", $imageSrc, "");
                                    }
                                    if($mime && in_array($mime,['image/jpeg','image/jpg'])){
                                        $graphics = $pdf->load_graphics("auto", $svg, 'defaultimageoptions={ignoremask }');
                                    }else{
                                        $graphics = $pdf->load_graphics("auto", $svg, '');
                                    }

                                    

                                    if ($pdf->info_graphics($graphics, "fittingpossible", '') == 1) {
                                        $pdf->fit_graphics($graphics, $block['x'], $block['y'], $optlist);
                                    } else {
                                        print_r($pdf->get_errmsg());
                                    }
                                } else {
                                        $optlistOpen = '';

                                        if ($use_pdvt_designer) {
                                            $optlistOpen = 'pdfvt={scope=file}';
                                        }


                                        if (isset($storedImages[$imageSrc])) {
                                            $image = $storedImages[$imageSrc];
                                        } else {
                                            $image = $pdf->load_image("auto", $imageSrc, $optlistOpen);
                                            $storedImages[$imageSrc] = $image;
                                        }
                                    if( !$image ){
                                        $this->removeProfile($imageSrc);
                                        $image = $pdf->load_image("auto", $imageSrc, $optlistOpen);
                                    }
                                    $optlist = "boxsize={" . $block['width'] . " " . $block['height'] . "}  matchbox={clipping={ $cx $cy $cw $ch }} fitmethod=entire scale={ $flipX $flipY } rotate=" . $block['angle'];

                                    $pdf->fit_image($image, $block['x'], $block['y'], $optlist);
                                }
								if (isset($block['clipPathSibling']) && $block['clipPathSibling']) {
                                    $pdf->restore();
                                }

                            }
                            break;
                        case 'textbox':

                            if (isset($block['opacity']) && $block['opacity'] !== 1) {

                                $gstate_stroke_opacity = $pdf->create_gstate("opacitystroke=" . $block['opacity']);
                                $pdf->save();
                                $pdf->set_gstate($gstate_stroke_opacity);
                            }


                            $defaultOptlist       = array(
                                'fontStyle'  => '',
                                'fontFamily' => '',
                                'leading'    => '',
                                'fillcolor'  => '',
                                'alignment'  => '',
                                'fontSize'   => '',
                                'encoding'   => ' encoding=unicode'
                            );
                            $optTextFlow          = '';
                            $tf                   = 0;
                            $firstLineMaxFontSize = 0;
                            $leadNextLine         = '';

                            if ($block['angle'] !== 0) {
                                $optTextFlow .= ' rotate=' . $block['angle'];
                            }
                            $text = '';

                            //used also for inline->maybe change it with a function
                            $fontStyle = '';
                            if (( ! isset($block['fontWeight']) || $block['fontWeight'] == '' || $block['fontWeight'] == 'normal') && ( ! isset($block['fontStyle']) || $block['fontStyle'] == '' || $block['fontStyle'] == 'normal')) {
                                $fontStyle = 'normal';
                            }

                            if (isset($block['fontWeight']) && $block['fontWeight'] == 'bold') {
                                $fontStyle = 'bold';
                            }

                            if (isset($block['fontStyle']) && $block['fontStyle'] == 'italic') {
                                $fontStyle .= 'italic';
                            }





                            $defaultOptlist['fontStyle'] = ' fontstyle=' . $fontStyle;


                                if ($use_pdvt_designer) {
                                    if (file_exists(ROOT_PATH . $this->fontSearchPath . $block['fontFamily'] . '.ttf')) {
                                        $pdf->set_option("FontOutline={" . $block['fontFamily'] . "=" . $block['fontFamily'] . ".ttf}");
                                    }
                                }

                            if (isset($block['fontFamily']) && strlen($block['fontFamily']) > 0) {
                                $defaultOptlist['fontFamily'] = ' fontname={' . $block['fontFamily'] . '} ';
                                if (!in_array($block['fontFamily'], $this->loaded_fonts_desinger)) {
                                    $font = $pdf->load_font($block['fontFamily'], "unicode", "embedding fallbackfonts={{fontname=Times-New-Roman encoding=unicode embedding}}");
                                }
                            }

                            if (isset($block['fontFamily']) && strpos($block['fontFamily'], "Helvetica") !== false) {
                                $defaultOptlist['fontFamily'] .= ' embedding';
                            }
                            if (isset($block['fontSize']) && strlen($block['fontSize']) > 0) {
                                $defaultOptlist['fontSize'] = ' fontsize=' . $block['fontSize'];
                            }



                            if (is_array($block['fillOptions']) && ! empty($block['fillOptions'])) {

                                switch ($block['fillOptions']['colorspace']) {

                                    case 'DeviceCMYK':
                                        $defaultOptlist['fillcolor'] = ' fillcolor={cmyk ' . $block['fillOptions']['originalcolor'] . '}'.' strokecolor={cmyk ' . $block['fillOptions']['originalcolor'] . '}';
                                        break;
                                    case 'DeviceRGB':
                                        $defaultOptlist['fillcolor'] = ' fillcolor={rgb ' . $block['fillOptions']['originalcolor'] . '}'.' strokecolor={rgb ' . $block['fillOptions']['originalcolor'] . '}';
                                        break;
                                }
                                if (isset($block['fillOptions']['code']) && $block['fillOptions']['code']) {
                                    $spot = $pdf->makespotcolor($block['fillOptions']['code']);
                                    $pdf->setcolor("fill", "spot", $spot, 1.0, 0, 0);

                                }
                                if (isset($block['fillOptions']) && $block['fillOptions'] && isset($block['fillOptions']['spotname']) && $block['fillOptions']['spotname']) {
                                    $defaultOptlist['fillcolor'] = ' fillcolor={spotname {' . $block['fillOptions']['spotname'] . '}  1.0 {cmyk ' . $block['fillOptions']['originalcolor'] . '} }'.
                                        ' strokecolor={spotname {' . $block['fillOptions']['spotname'] . '}  1.0 {cmyk ' . $block['fillOptions']['originalcolor'] . '} }';
                                }
                                if (isset($block['hasRefinement']) && $block['hasRefinement']) {
                                    if (isset($block['fillOptions']) && $block['fillOptions'] && $block['fillOptions']['transparent']) {
                                        $defaultOptlist['transparent'] = ' gstate=' . $gstateRefinementOpacity;
                                    } else {

                                        $defaultOptlist['transparent'] = ' gstate=' . $gstateOverprint;
                                    }
                                }
                                if(isset($block['gizeh']) && $block['gizeh']) {
                                    if (isset($block['fillOptions']) && $block['fillOptions'] && isset($block['fillOptions']['transparentText']) && $block['fillOptions']['transparentText']) {
                                        $defaultOptlist['transparent'] = ' gstate=' . $gstateTransparentOpacityText;
                                    }else{
                                        $defaultOptlist['transparent'] = ' gstate=' . $gstateTransparentOpacityTextRevert;
                                    }
                                }


                            } else {
                                $defaultOptlist['fillcolor'] = ' fillcolor={rgb 0 0 0} strokecolor={rgb 0 0 0}';
                            }


                            if (isset($block['underline']) && $block['underline']) {
                                $defaultOptlist['underline'] = ' underline underlineposition=-10% underlinewidth=' . $block['fontSize'] / 15; //this is how fabricjs is render underline stroke
                            } else {
                                $defaultOptlist['underline'] = ' underline=false';
                            }

                            $lead                      = (float)$block['fontSize'] * (float)$block['lineHeight'] * (float)$block['_fontSizeMult'];
                            $defaultOptlist['leading'] = ' leading=' . $lead;
                            $leadNextLine              = $defaultOptlist['leading'];


                            if (isset($block['textAlign'])) {
                                $defaultOptlist ['alignment'] = ' alignment=' . $block['textAlign'];
                            }
                            if(isset($block['addStroke']) && $block['addStroke']){
                                $defaultOptlist['stroke'] = ' strokecolor={'.$block['stroke'].'} textrendering={2} strokewidth={'.$block['strokeWidth'].'}';
                            }else{
                                if(isset($block['strokedElement']) && $block['strokedElement']){

                                    $defaultOptlist['stroke'] = ' textrendering={3} strokewidth={0}';

                                }
                            }

                            //calculateleading for each Line
                            $leadingText = array();

                            foreach ($block['textLines'] as $key => $textLine) {
                                if ($key == 0) {
                                    $leadingText[$key] = (float)$block['_lineHeightsOffsets'][$key]['ascender'];
                                } else {
                                    $leadingText[$key] = (float)$block['_lineHeightsOffsets'][$key]['ascender'] + (float)$block['_lineHeightsOffsets'][$key - 1]['descender'];
                                }
								if($leadingText[$key]<0){
									$leadingText[$key]=0;
								}
	
                            }


                            $firstLineMaxFontSize = -1;
                            $pdflibLineWidths     = array();
                            foreach ($block['textLines'] as $key => $textLine) {

                                $text  = '';

                                    $matches_pdf_vt = array();
                                    $headers_array = array();

                                    if ($use_pdvt_designer) {
                                        $this->current_line = $designerCounter - 1;
                                        preg_match_all('/\%(.[^ \n]*?)\%/', $textLine, $matches_pdf_vt, PREG_OFFSET_CAPTURE);
                                        if (count($matches_pdf_vt[0]) && count($matches_pdf_vt[1])) {
                                            foreach ($matches_pdf_vt[0] as $keyProp => $tagProps) {
                                                $headers_array[$tagProps[1]] = array(
                                                    'text_with_delimiters' => $tagProps[0],
                                                    'text_without_delimiters' => $matches_pdf_vt[1][$keyProp][0]
                                                );
                                            }

                                        }

                                    }

                                $textLine = str_replace("\t"," ",$textLine);
                                $chars = preg_split("//u", $textLine, -1, PREG_SPLIT_NO_EMPTY); //replaced the str_split because of unicode

                                $pdflibLineWidths[$key] = 0;

                                if( $block['textAlign'] == "justify" ) {
                                    if ( $this->isEndOfWrappingText($block, $key) ) {
                                        // .. make it left aligned
                                        $defaultOptlist ['alignment'] = ' alignment=left lastalignment=left maxspacing=0';
                                    }
                                    else {
                                        $defaultOptlist ['alignment'] = ' alignment=justify lastalignment=justify maxspacing=5000';
                                    }
                                }

                                if (count($chars)) {
                                    for ($charIndex = 0; $charIndex < count($chars); $charIndex++) {
                                       if( $block['textAlign'] == "justify" && $charIndex == count($chars)-1 && $chars[$charIndex] == ' ') {
                                           $chars[$charIndex]=" ‏‏‎ "; // wee need to add a transparent character in order to pdflib justify work
                                        }
                                        $currentStyle = $this->getStyleDeclaration($block, $key, $charIndex);
                                        if(is_array($currentStyle) && isset($currentStyle['fontSize']) && $currentStyle['fontSize']==0){
                                            continue;
                                        }

                                            $skipCharsPdfVt = false;

                                            if ($use_pdvt_designer && isset($headers_array[$charIndex]) && isset($this->csv_block_values[$headers_array[$charIndex]['text_without_delimiters']])) {
                                                if (isset($this->csv_block_values[$headers_array[$charIndex]['text_without_delimiters']][$this->current_line])) {
                                                    $skipCharsPdfVt = true;
                                                    $text .= $this->csv_block_values[$headers_array[$charIndex]['text_without_delimiters']][$this->current_line];


                                                    //add br if the tag is last on the line
                                                    if ($charIndex + strlen($headers_array[$charIndex]['text_with_delimiters']) == count($chars)) {
                                                        $text .= '<br>';
                                                    }
                                                }
                                            } else {
                                        $text .= $chars[$charIndex];
                                            }

                                        if ($charIndex == count($chars) - 1 && $key < count($block['textLines']) - 1) {
                                            $text .= '<br>';
                                        }

                                        //calculate first line max fontSize
                                        if ($key == 0) {

                                            if ((is_array($currentStyle) && isset($currentStyle['fontSize'])) && ($charIndex == 0 || $currentStyle['fontSize'] > $firstLineMaxFontSize)) {
                                                $firstLineMaxFontSize = (float)$currentStyle['fontSize'];
                                            } else {

                                                if (is_array($currentStyle) && ! isset($currentStyle['fontSize']) && $firstLineMaxFontSize == -1) {
                                                    $firstLineMaxFontSize = $block['fontSize'];
                                                }

                                            }

                                        }

                                        //end
                                            if ($currentStyle != $this->getStyleDeclaration($block, $key, $charIndex + 1) || ($charIndex == count($chars) - 1) || $skipCharsPdfVt) {

                                            $text    = preg_replace('#<br\s*/?>#i', "\n", $text);
                                            //$text    = strip_tags($text);
                                            $optlist = $defaultOptlist;

                                            if (is_array($currentStyle) && ! empty($currentStyle)) {
                                                $fontStyleChar = $fontStyle;
                                                if (isset($currentStyle['fontWeight']) && $currentStyle['fontWeight']) {

                                                    switch ($fontStyleChar) {

                                                        case 'normal':
                                                            $fontStyleChar = str_replace('normal', $currentStyle['fontWeight'] == 'bold' ? 'bold' : 'normal', $fontStyleChar);
                                                            break;
                                                        case 'italic':
                                                            $fontStyleChar .= ($currentStyle['fontWeight'] == 'bold' ? 'bold' : '');
                                                            break;
                                                        case 'bold':
                                                            $fontStyleChar = str_replace('bold', $currentStyle['fontWeight'] == 'bold' ? 'bold' : 'normal', $fontStyleChar);
                                                            break;
                                                        case 'bolditalic':
                                                            $fontStyleChar = str_replace('bold', $currentStyle['fontWeight'] == 'bold' ? 'bold' : '', $fontStyleChar);
                                                            break;

                                                    }
                                                }

                                                if (isset($currentStyle['fontStyle']) && $currentStyle['fontStyle']) {

                                                    switch ($fontStyleChar) {

                                                        case 'normal':
                                                            $fontStyleChar = str_replace('normal', $currentStyle['fontStyle'] == 'italic' ? 'italic' : 'normal', $fontStyleChar);
                                                            break;
                                                        case 'italic':
                                                            $fontStyleChar = $currentStyle['fontStyle'] == 'italic' ? 'italic' : 'normal';
                                                            break;
                                                        case 'bold':
                                                            $fontStyleChar .= ($currentStyle['fontStyle'] == 'italic' ? 'italic' : '');
                                                            break;
                                                        case 'bolditalic':
                                                            $fontStyleChar = str_replace('italic', $currentStyle['fontStyle'] == 'italic' ? 'italic' : '', $fontStyleChar);
                                                            break;

                                                    }
                                                }
                                                if ($fontStyleChar == 'italicbold') {
                                                    $fontStyleChar = 'bolditalic';
                                                }
                                                $optlist['fontStyle'] = ' fontstyle=' . $fontStyleChar;

                                                if (isset($currentStyle['fontFamily']) && strlen($currentStyle['fontFamily']) > 0) {
                                                    $optlist['fontFamily'] = ' fontname={' . $currentStyle['fontFamily'] . '} ';

                                                        if ($use_pdvt_designer) {
                                                            if (file_exists(ROOT_PATH . $this->fontSearchPath . $currentStyle['fontFamily'] . '.ttf')) {
                                                                $pdf->set_option("FontOutline={" . $currentStyle['fontFamily'] . "=" . $currentStyle['fontFamily'] . ".ttf}");
                                                            }

                                                        }

                                                    if (!in_array($currentStyle['fontFamily'], $this->loaded_fonts_desinger)) {
                                                        $font = $pdf->load_font($currentStyle['fontFamily'], "unicode",
                                                            "embedding fallbackfonts={{fontname=Times-New-Roman encoding=unicode embedding}}");
                                                    }
                                                }

                                                if (isset($currentStyle['fontFamily']) && strpos($currentStyle['fontFamily'], "Helvetica") !== false) {
                                                    $optlist['fontFamily'] .= ' embedding';
                                                }
                                                if (isset($currentStyle['fontSize']) && strlen($currentStyle['fontSize']) > 0) {
                                                    $optlist['fontSize'] = ' fontsize=' . ((float)$currentStyle['fontSize']);
                                                }
                                                if(isset($currentStyle['underline'])){
                                                    if ($currentStyle['underline']) {
                                                        $optlist['underline'] = ' underline underlineposition=-10%  underlinewidth=' . $block['fontSize'] / 15; //this is how fabricjs is render underline stroke

                                                    } else {
                                                        $optlist['underline'] = ' underline=false';
                                                    }
                                                }





                                                if (isset($currentStyle['fillOptions']) && is_array($currentStyle['fillOptions']) && ! empty($currentStyle['fillOptions'])) {

                                                    switch ($currentStyle['fillOptions']['colorspace']) {

                                                        case 'DeviceCMYK':
                                                            $optlist['fillcolor'] = ' fillcolor={cmyk ' . $currentStyle['fillOptions']['originalcolor'] . '}'.' strokecolor={cmyk ' . $currentStyle['fillOptions']['originalcolor'] . '}';

                                                            if (isset($currentStyle['fillOptions']['spotname']) && $currentStyle['fillOptions']['spotname']) {
                                                                $optlist['fillcolor'] = ' fillcolor={spotname {' . $currentStyle['fillOptions']['spotname'] . '}  1.0 {cmyk ' . $currentStyle['fillOptions']['originalcolor'] . '} }'.
                                                                    ' strokecolor={spotname {' . $currentStyle['fillOptions']['spotname'] . '}  1.0 {cmyk ' . $currentStyle['fillOptions']['originalcolor'] . '} }';
                                                            }

                                                            break;
                                                        case 'DeviceRGB':
                                                            $optlist['fillcolor'] = ' fillcolor={rgb ' . $currentStyle['fillOptions']['originalcolor'] . '}'.' strokecolor={rgb ' . $currentStyle['fillOptions']['originalcolor'] . '}';
                                                            break;
                                                    }
                                                    if (isset($block['hasRefinement']) && $block['hasRefinement']) {
                                                        if (isset($currentStyle['fillOptions']['transparent']) && $currentStyle['fillOptions']['transparent']) {

                                                            $optlist['transparent'] = ' gstate=' . $gstateRefinementOpacity;
                                                        } else {

                                                            $optlist['transparent'] = ' gstate=' . $gstateOverprint;
                                                        }
                                                    }


                                                }
                                                if(isset($block['gizeh']) && $block['gizeh']){

                                                    if (isset($currentStyle['fillOptions']) && is_array($currentStyle['fillOptions']) && ! empty($currentStyle['fillOptions'])&&
                                                        isset($currentStyle['fillOptions']['transparentText']) && $currentStyle['fillOptions']['transparentText']) {
                                                        $optlist['transparent'] = ' gstate=' . $gstateTransparentOpacityText;
                                                    }else{
                                                        $optlist['transparent'] = ' gstate=' . $gstateTransparentOpacityTextRevert;
                                                    }

                                                 }


                                                if(isset($currentStyle['addStroke']) && $currentStyle['addStroke']){
                                                    $optlist['stroke'] = ' strokecolor={'.$currentStyle['stroke'].'} textrendering={2} strokewidth={'.$currentStyle['strokeWidth'].'}';
                                                }else{

                                                    if(isset($block['strokedElement']) && $block['strokedElement']){

                                                        $optlist['stroke'] = ' textrendering={3} strokewidth={0}';

                                                    }
                                                }

                                            }

                                            if (isset($leadingText[$key])) {
                                                $optlist['leading'] = ' leading=' . $leadingText[$key];
                                            }
                                            if ( ! empty($block['charSpacing']) && ($charSpacing = (float)$block['charSpacing'])) {
                                                $charSpacing            = (float)$block['fontSize'] * $charSpacing /1000;
                                                $optlist['charspacing'] = ' charspacing='.$charSpacing.''  ;
                                            }
                                            $optionText             = $this->getTextOptions($optlist);
                                            $charOptions            = $optlist['fontStyle'] . $optlist['fontFamily'] . $optlist['fontSize'] . $optlist['encoding'];

                                            $width                  = $pdf->info_textline($text, "width", $charOptions);
                                            $pdflibLineWidths[$key] += $width;

                                            $tf = $pdf->add_textflow($tf, $text, $optionText. ' avoidbreak=true ');

                                            $text = '';
                                                if ($skipCharsPdfVt) {
                                                    //we are on the % position and we need to jump to next % delimiter
                                                    $charIndex += strlen($headers_array[$charIndex]['text_with_delimiters']) - 1;


                                                }
                                        }

                                    }
                                } else {
                                    if ($key == 0) {
                                        $firstLineMaxFontSize = $block['fontSize'];
                                    }
                                    $optlist              = $defaultOptlist;

                                    $currentStyle = $this->getStyleDeclaration($block, $key, 0);

                                    if(is_array($currentStyle) && isset($currentStyle['fontSize']) && $currentStyle['fontSize']==0){
                                        continue;
                                    }


                                    if (isset($currentStyle['fontSize']) && strlen($currentStyle['fontSize']) > 0) {
                                        $optlist['fontSize']  = ' fontsize=' . ((float)$currentStyle['fontSize']);
                                        if ($key == 0) {
                                            $firstLineMaxFontSize = (float)$currentStyle['fontSize'];
                                        }
                                    }
                                    if (isset($leadingText[$key])) {
                                        $optlist['leading'] = ' leading=' . $leadingText[$key];
                                    }
                                    if (isset($currentStyle['fontFamily']) && strlen($currentStyle['fontFamily']) > 0) {
                                        $optlist['fontFamily'] = ' fontname={' . $currentStyle['fontFamily'] . '} ';
                                        if (!in_array($currentStyle['fontFamily'], $this->loaded_fonts_desinger)) {
                                            $font = $pdf->load_font($currentStyle['fontFamily'], "unicode", "embedding fallbackfonts={{fontname=Times-New-Roman encoding=unicode embedding}}");
                                        }
                                    }

                                    if (isset($currentStyle['fontFamily']) && strpos($currentStyle['fontFamily'], "Helvetica") !== false) {
                                        $optlist['fontFamily'] .= ' embedding';
                                    }


                                    $optionText = $this->getTextOptions($optlist);
                                    $tf         = $pdf->add_textflow($tf, "\n", $optionText. ' avoidbreak=true ');
                                }

                            }

                            if (is_array($block['backgroundColorOptions']) && isset($block['backgroundColorOptions']['originalcolor']) && $block['backgroundColorOptions']['originalcolor'] !== 'transparent') {
                                $this->drawBackgroundRectangle($pdf, $block);
                            }

                            $verticalAlignOffset = 0;

                            if (isset($block['verticalAlign']) && isset($block['verticalAlignOffset'])) {
                                $verticalAlignOffset = $block['verticalAlignOffset'];
                            }

                            if ($firstLineMaxFontSize !== $block['fontSize']) {

                                $firstlinedist = (float)$block['fontSize'] * 0.03 + (float)$firstLineMaxFontSize * (float)$block['_fontSizeMult'] * (1 - (float)$block['fontSizeFraction']);//symplified

                            } else {
                                $firstlinedist = ((float)$firstLineMaxFontSize * (float)$block['_fontSizeMult']) - (float)$block['fontSize'] * $block['fontSizeFraction'];

                            }

                            $firstlinedist += $verticalAlignOffset;
                            if($firstlinedist<0){
                                $firstlinedist=0;
                            }
                            $optTextFlow   .= ' firstlinedist=' . $firstlinedist;


                            $widthOfBlock = $block['width'] > max($pdflibLineWidths) ? $block['width'] : max($pdflibLineWidths);
                            if ($tf) {
                                $pdf->fit_textflow($tf, $block['x'], $block['y'], $block['x'] + $widthOfBlock, $block['y'] + $block['height'], $optTextFlow . ' fitmethod=nofit');
                            }

                            if (isset($block['opacity']) && $block['opacity'] !== 1) {
                                $pdf->restore();
                            }
                            break;
                        case 'curvedtext':

                            $optlist = '';


                            // if( $block['angle'] !== 0 ) {
                            //  	$optlist.=' rotate='.$block['angle'];
                            //}


                            //  $text = strip_tags( $text );


                            $fontstyle = '';

                            if (( ! isset($block['fontWeight']) || $block['fontWeight'] == '') && ( ! isset($block['fontStyle']) || $block['fontStyle'] == 'normal')) {
                                $fontstyle = 'normal';
                            }

                            if (isset($block['fontWeight']) && $block['fontWeight'] == 'bold') {
                                $fontstyle = 'bold';
                            }

                            if (isset($block['fontStyle']) && $block['fontStyle'] == 'italic') {
                                $fontstyle .= 'italic';
                            }

                            if (strlen($fontstyle) > 0) {
                                $optlist .= ' fontstyle=' . $fontstyle;
                            }
                            if (isset($block['addStroke']) && $block['addStroke']) {
                                $optlist .= ' strokecolor={' . $block['stroke'] . '} textrendering={2} strokewidth={' . $block['strokeWidth'] . '}';
                            }

                            if (isset($block['fontFamily']) && strlen($block['fontFamily']) > 0) {
                                $optlist .= ' fontname={' . $block['fontFamily'] . '} ';

                                if (!in_array($block['fontFamily'], $this->loaded_fonts_desinger)) {
                                    $font = $pdf->load_font($block['fontFamily'], "unicode", "embedding fallbackfonts={{fontname=Times-New-Roman encoding=unicode embedding}}");
                                }
                            }

                            if (isset($block['fontFamily']) && $block['fontFamily'] !== 'Helvetica') {
                                $optlist .= ' embedding';
                            }

                            if (isset($block['fontSize']) && strlen($block['fontSize']) > 0) {
                                $optlist .= ' fontsize=' . $block['fontSize'];
                            }


                            if (is_array($block['fillOptions']) && count($block['fillOptions'])) {

                                switch ($block['fillOptions']['colorspace']) {

                                    case 'DeviceCMYK':
                                        if (isset($block['fillOptions']) && $block['fillOptions'] && isset($block['fillOptions']['spotname']) && $block['fillOptions']['spotname']) {
                                            $optlist .= ' fillcolor={spotname {' . $block['fillOptions']['spotname'] . '}  1.0 {cmyk ' . $block['fillOptions']['originalcolor'] . '} }';
                                        } else {
                                            $optlist .= ' fillcolor={cmyk ' . $block['fillOptions']['originalcolor'] . '}';
                                        }

                                        break;
                                    case 'DeviceRGB':
                                        $optlist .= ' fillcolor={rgb ' . $block['fillOptions']['originalcolor'] . '}';
                                        break;
                                }

                            } else {
                                $optlist .= ' fillcolor={rgb 0 0 0}';
                            }

                            if (is_array($block['backgroundColorOptions']) && isset($block['backgroundColorOptions']['originalcolor']) && $block['backgroundColorOptions']['originalcolor'] !== 'transparent') {
                                $this->drawBackgroundRectangle($pdf, $block);
                            }
                            foreach ($block['textLetters'] as $textLine) {
                                foreach ($textLine as $charElement) {

                                    $pdf->fit_textline(htmlspecialchars_decode($charElement['char'], ENT_QUOTES | ENT_HTML5),
                                        $block['x'] + $block['width'] / 2 + $charElement['left'] * $block['scaleX'],
                                        $block['y'] + $block['height'] / 2 - $charElement['top'] * $block['scaleX'],
                                        $optlist . "  encoding=unicode rotate=" . $charElement['rotation']);
                                }
                            }

                            break;


                    }
                    
                    if(  isset($block['whiteUnderprintIsActive']) && $block['whiteUnderprintIsActive'] && isset($block['whiteUnderprintImageSrc']) ) {
                        $imageSrc = $block['whiteUnderprintImageSrc'];                        
                        $this->filesToDelete[] = $imageSrc;

                        $pdf->setcolor("fillstroke", "cmyk", 1, 0, 0, 0);
                        $spot            = $pdf->makespotcolor("White");
                        $optlist         = "colorize=" . $spot;
                        $image   = $pdf->load_image("auto", $imageSrc, $optlist);
                        $optlist = "boxsize={" . $block['width'] . " " . $block['height'] . "}  fitmethod=entire rotate=" . $block['angle'];
                        $gstate = $pdf->create_gstate("overprintstroke=true overprintfill=true overprintmode=1");
                        $pdf->save();
                        $pdf->set_gstate($gstate);

                        $pdf->fit_image($image, $block['x'], $block['y'], $optlist);
                        $pdf->restore();
                    } 

                    if (isset($block['opacity']) && $block['opacity'] !== 1) {
                        $pdf->restore();
                    }

                }
            }


            if (isset($pageData['diecut_packing']) && $pageData['diecut_packing']) {
                $svgPdf = tempnam("/tmp", "SVG");

                $this->filesToDelete[]  = $svgPdf;
                file_put_contents($svgPdf, $pageData['diecut_packing']);

                $packingBlockWidth  = isset($pageData['isFromPdf']) && $pageData['isFromPdf'] ? $pageData['page_width_pdf'] : $pageData['page_width'];
                $packingBlockHeight = isset($pageData['isFromPdf']) && $pageData['isFromPdf'] ? $pageData['page_width_pdf'] : $pageData['page_height'];


                $optlist = "boxsize={ " . $packingBlockWidth . " " . $packingBlockHeight .
                    "} position={center} fitmethod=entire ";

                $graphics = $pdf->load_graphics("auto", $svgPdf, "");
				
				
				$client       = (isset($pageData['packing_customer']) && $pageData['packing_customer'] != '' && array_key_exists($pageData['packing_customer'], $this->packingDiecutColors)) ? $pageData['packing_customer'] : 'default';

			    if($client && $client == 'easypaper'){
			        if($pageKey == 0){
                        $layerDiecut = $pdf->define_layer("Diecut", "");
                    }

                    $pdf->begin_layer($layerDiecut);
                }

				$diecutColors = $this->packingDiecutColors[$client];
				foreach ($diecutColors as $cType => $cValue) {
				if (strpos($pageData['diecut_packing'], $cType) !== false) {
					
						$pdf->setcolor("fillstroke", "cmyk", $cValue[0], $cValue[1], $cValue[2], $cValue[3]);
						$spot = $pdf->makespotcolor($cType);
					}
				}

                preg_match('/(bleedColor)=["\']?((?:.(?!["\']?\s+(?:\S+)=|["\']))+.)["\']?/is', $pageData['diecut_packing'], $bleedColor);

                if ( ! (is_array($bleedColor) && count($bleedColor))) {
                    $bleedColor = false;
                }


                if (strpos($pageData['diecut_packing'], 'DieCutBleed') !== false || ! $bleedColor) {
                    $rgbTmp = str_replace(array('rgb(', ')'), '', (string)$bleedColor[2]);
                    $rgbTmp = explode(',', $rgbTmp);

                    $pdf->setcolor("fillstroke", "rgb", $rgbTmp[0] / 255, $rgbTmp[1] / 255, $rgbTmp[2] / 255, 0);
                    $spot = $pdf->makespotcolor("DieCutBleed");
                }
                if (strpos($pageData['diecut_packing'], 'Lack') !== false) {
                    $pdf->setcolor("fillstroke", "cmyk", 0.62, 0.16, 0.11, 0);
                    $spot = $pdf->makespotcolor("Lack");
                }
                $gstate = $pdf->create_gstate("overprintstroke=false");
                $pdf->save();
                $pdf->set_gstate($gstate);
                if (strpos($pageData['diecut_packing'], 'DieCutGreen') !== false) {

                    $pdf->setcolor("fillstroke", "cmyk", 0, 0, 1, 0.5);
                    $spot = $pdf->makespotcolor("DieCutGreen");
                }
                $pdf->restore();


                $gstate = $pdf->create_gstate("overprintstroke=true overprintmode=1");
                $pdf->save();
                $pdf->set_gstate($gstate);

                if (strpos($pageData['diecut_packing'], 'DieCutBlue') !== false) {
                    $pdf->setcolor("fillstroke", "cmyk", 1, 1, 0, 0);
                    $spot = $pdf->makespotcolor("DieCutBlue");
                }
                if (strpos($pageData['diecut_packing'], 'DieCutRed') !== false) {
                    $pdf->setcolor("fillstroke", "cmyk", 0, 1, 1, 0);
                    $spot = $pdf->makespotcolor("DieCutRed");
                }


                if ($pdf->info_graphics($graphics, "fittingpossible", '') == 1) {
                    $pdf->fit_graphics($graphics, 0, 0, $optlist);
                } else {
                    print_r($pdf->get_errmsg());
                }
                $pdf->restore();

                if($client && $client == 'easypaper'){
                    $pdf->end_layer();
                }
            }

            if (isset($pageData['diecut_packing_rosendahls']) && $pageData['diecut_packing_rosendahls']) {
                $this->diecutPackingRosendahls($pageData, $pdf);
            }
            if (isset($pageData['custom_layers_diecuts']) && $pageData['custom_layers_diecuts']) {
                $this->diecutCustomLayers($pageData, $pdf);
            }

            if (isset($data['add_cut_marks']) && $data['add_cut_marks']) {

                $offset    = $data['cut_marks_options']['offset'];
                $crop_mark = 0;
                $crop_mark = $pdf->add_path_point($crop_mark, 0, (-$offset + 1) * $this->MM_TO_PT, "move", "stroke nofill strokecolor={cmyk 0 0 0 1}");
                $crop_mark = $pdf->add_path_point($crop_mark, 0, -$offset * $this->MM_TO_PT, "line", "");

                $crop_mark = $pdf->add_path_point($crop_mark, (-$offset + 1) * $this->MM_TO_PT, 0, "move", "stroke nofill strokecolor={cmyk 0 0 0 1}");
                $crop_mark = $pdf->add_path_point($crop_mark, -$offset * $this->MM_TO_PT, 0, "line", "");

                for ($step = 0; $step < 4; $step++) {
                    $x = ($offset * $this->MM_TO_PT) + intval((($step + 1) % 4) / 2) * $pageEditorWidth - 2 * intval((($step + 1) % 4) / 2) * ($offset * $this->MM_TO_PT);
                    $y = ($offset * $this->MM_TO_PT) + intval($step / 2) * $pageEditorHeight - 2 * intval($step / 2) * ($offset * $this->MM_TO_PT);
                    $this->draw_corner($pdf, $step * 90, $x, $y, $crop_mark);
                }


            }

            if (isset($pageData['page_refinement']) && $pageData['page_refinement'] && is_array($pageData['page_refinement'])) {
                //objects with refinement are on overprint layer
                $pdf->restore();
            }


            if($diecut_pacific_tmp_file){
                $indoc_diecut_pdf = $pdf->open_pdi_document($diecut_pacific_tmp_file, "");
                $page_diecut_pdf = $pdf->open_pdi_page($indoc_diecut_pdf, ($pageKey + 1), "");
                if ($page_diecut_pdf == 0) {
                    throw new Exception("Error: %s\n", $page_diecut_pdf->get_errmsg());
                }
                $pdf->fit_pdi_page($page_diecut_pdf, 0, 0, "boxsize={" . $pageEditorWidth . " " . $pageEditorHeight . "} fitmethod=entire");
            }

            if($applyWatermarkOnTheSamePdf){
                if (isset($data['watermark']) && isset($data['watermark']['client']) && strlen($data['watermark']['client']) && $data['watermark']['client'] == 'flyeralarm') {
                    $this->applyFAWatermarkOnPdfPage($pdf, $data, $pageEditorWidth, $pageEditorHeight);
                }else{
                    $this->applyWatermarkOnPdfPage($pdf,$data,$pageEditorWidth,$pageEditorHeight);
                }

            }


            $pdf->end_page_ext("");
                if ($use_pdvt_designer) {
                    $pdf->end_dpart("");
                }
            }
        }
        if( $use_pdvt_designer ) {
            $pdf->end_dpart( "" );
        }

        if($diecut_pacific_tmp_file){
            $this->filesToDelete[] = $diecut_pacific_tmp_file;
        }

        if( $clean ) {
            $this->loaded_fonts_desinger= array();
            $this->deleteAllFiles($this->filesToDelete);
        }
    }
    public function prepareImageForEffectAddProfile($source){
        $image    = new \Imagick($source);
        $image->stripImage();

        $profile = file_get_contents(ROOT_PATH . '/data/ISOcoated_v2_300_eci.icc');
        $image->profileImage("icc", $profile);
        $image->transformImageColorspace(\Imagick::COLORSPACE_CMYK);

        $image->writeImage($source);
    }

    public function prepareImageForEffect($source){
        $image    			= new \Imagick($source);
        $source_response	= false;
        if (\Imagick::COLORSPACE_RGB != $image->getImageColorspace() && \Imagick::COLORSPACE_SRGB != $image->getImageColorspace()) {

            $sRgb = file_get_contents(ROOT_PATH . '/data/sRGB.icc');

            $image->profileImage('icc', $sRgb);
            $image->setImageRenderingIntent (\Imagick::RENDERINGINTENT_RELATIVE);
            $image->setOption("black-point-compensation", true);
            $source_response = $source.'_rgb';
            $image->writeImage($source_response);

        }
        return $source_response;

    }

    public function removeProfile($path)
    {
        $image    = new \Imagick($path);
        //remove all profiles from image
        $image->stripImage();
        $image->writeImage();
    }

    public function draw_corner($p, $angle, $x, $y, $crop_mark)
    {
        $p->save();
        $p->translate($x, $y);
        $p->rotate($angle);
        $p->draw_path($crop_mark, 0, 0, "fill stroke");
        $p->restore();
    }
	
	public function startClipPath($pdf, $width, $height, $block )
    {
        $pdf->save();
        if( isset($block['inverted']) && $block['inverted'] ) {
            $pdf->set_graphics_option("cliprule=evenodd");
            $pdf->rect(0, 0, $width, $height);
        }
        
        $pdf->rect($block['x'], $block['y'], $block['width'], $block['height']);
        $pdf->clip();
    }


    private function filterImageDesigner($effect, $options)
    {

        if ( ! $effect) {
            return false;
        }


        $source          = $options['original_file_src'];
        $effect_filename = $options['original_file_src'] . '_' . $effect;
        switch ($effect) {
            case 'Grayscale' :
                exec(escapeshellcmd('convert ' . $source . ' -colorspace Gray ' . $effect_filename));
                break;
            case 'Sepia':
                exec(escapeshellcmd('convert -colorspace sRGB ' . $source . ' -color-matrix   "0.393 0.769 0.189 0.349 0.686 0.168 0.272 0.534 0.131" ' . $effect_filename)); //sepia
                break;
            case 'brownie':
                exec(escapeshellcmd('convert ' . $source . ' -color-matrix   "0.5997023498159715 0.34553243048391263 -0.2708298674538042 -0.037703249837783157 0.8609577587992641 0.15059552388459913 0.24113635128153335 -0.07441037908422492 0.44972182064877153"  -channel R -fx "r+0.18600756296" -channel G -fx "g-0.1449741764" -channel B -fx "b-0.02965519716" ' . $effect_filename)); //sepia
                break;
            case 'technicolor':
                exec(escapeshellcmd('convert ' . $source . '   -color-matrix   "1.9125277891456083 -0.8545344976951645 -0.09155508482755585 -0.3087833385928097 1.7658908555458428 -0.10601743074722245 -0.231103377548616 -0.7501899197440212 1.847597816108189"  -channel R -fx "r+0.04624942523" -channel G -fx "g-0.27589039848" -channel B -fx "b+0.1213762387" ' . $effect_filename)); //sepia
                break;
            case 'kodachrome':
                exec(escapeshellcmd('convert ' . $source . ' -color-matrix   "1.1285582396593525 -0.3967382283601348 -0.03992559172921793 -0.16404339962244616 1.0835251566291304 -0.05498805115633132 -0.16786010706155763 -0.5603416277695248 1.6014850761964943"  -channel R -fx "r+0.24991995145" -channel G -fx "g+0.09698983488" -channel B -fx "b+0.13972481597" ' . $effect_filename)); //sepia
                break;
            case 'vintagePinhole':
                exec(escapeshellcmd('convert ' . $source . ' -color-matrix   "0.6279345635605994 0.3202183420819367 -0.03965408211312453  0.02578397704808868 0.6441188644374771 0.03259127616149294 0.0466055556782719 -0.0851232987247891 0.5241648018700465"  -channel R -fx "r+0.03784817974" -channel G -fx "g+0.02926599677" -channel B -fx "b+0.02023211995" ' . $effect_filename)); //sepia
                break;
            case 'Invert':
                exec('convert '.$source.' -channel RGB -negate  '.$effect_filename);
                /*$effect_filename = $this->effectImage(array(
                    'original_image_src' => $options['original_file_src'],
                    'effect'             => 'invert'
                ));*/
                break;
            default:
                break;
        }


        return $effect_filename;

    }

    protected function getStyleDeclaration($canvasObject, $lineIndex, $charIndex)
    {
        if (isset($canvasObject['hasStyleMap']) && $canvasObject['hasStyleMap']) {
            if (isset($canvasObject['styleMap'])) {

                $map = $canvasObject['styleMap'][$lineIndex];


                if (is_array($map) && isset($map['line']) && isset($map['offset'])) {

                    return $this->getStyleDeclarationText($canvasObject, $map['line'], (int)$map['offset'] + (int)$charIndex);
                }
            }
        } else {
            return $this->getStyleDeclarationText($canvasObject, $lineIndex, (int)$charIndex);
        }


        return array();
    }

    protected function getStyleDeclarationText($canvasObject, $lineIndex, $charIndex)
    {

        if (is_array($canvasObject['styles']) &&
            isset($canvasObject['styles'][$lineIndex]) &&
            is_array($canvasObject['styles'][$lineIndex]) &&
            isset($canvasObject['styles'][$lineIndex][$charIndex]) &&
            is_array($canvasObject['styles'][$lineIndex][$charIndex]) &&
            ! empty($canvasObject['styles'][$lineIndex][$charIndex])) {
            return $canvasObject['styles'][$lineIndex][$charIndex];
        }

        return array();

    }

    protected function isEndOfWrappingText($canvasObject, $lineIndex)
    {
        if (isset($canvasObject['hasStyleMap']) && $canvasObject['hasStyleMap']) {
            if (isset($canvasObject['styleMap'])) {

                if (! isset($canvasObject['styleMap'][$lineIndex + 1]) ) {
                    // is last line, return true;
                    return true;
                }

                if ( $canvasObject['styleMap'][$lineIndex + 1]['line'] !== $canvasObject['styleMap'][$lineIndex]['line'] ) {
                    // this is last line before a line break, return true;
                    return true;
                }
            }
        }
        return false;
    }

    function hex2rgb($hex)
    {
        $hex = str_replace("#", "", $hex);

        if (strlen($hex) == 3) {
            $r = hexdec(substr($hex, 0, 1) . substr($hex, 0, 1));
            $g = hexdec(substr($hex, 1, 1) . substr($hex, 1, 1));
            $b = hexdec(substr($hex, 2, 1) . substr($hex, 2, 1));
        } else {
            $r = hexdec(substr($hex, 0, 2));
            $g = hexdec(substr($hex, 2, 2));
            $b = hexdec(substr($hex, 4, 2));
        }
        $rgb = array($r, $g, $b);
        //return implode(",", $rgb); // returns the rgb values separated by commas
        return $rgb; // returns an array with the rgb values
    }

    private function testEffect($effect, $block_property, $image_src)
    {
        if (isset($block_property[$effect]) && '1' == $block_property[$effect]) {
            $block_image_options = array(
                'original_image_src' => $image_src,
                'effect'             => $effect
            );
            $effect_image_src    = $this->effectImage($block_image_options);
            if ($effect_image_src) {
                $image_src = $effect_image_src;
            }
        }

        return $image_src;
    }

    private function effectImage($options)
    {
        $effect = '';
        if (isset($options['effect']) && in_array($options['effect'], array(
                'sepia',
                'greyscale',
                'invert',
                'flip_horizontal',
                'flip_vertical',
                'flip_both'
            ))
        ) {
            $effect = $options['effect'];
        } else {
            return false;
        }

        $source          = $options['original_image_src'];
        $effect_filename = $options['original_image_src'] . '_' . $effect;
        switch ($effect) {
            case 'greyscale' :
                $effect_image = $this->imagePrepareFilterImage($source);
                if ( ! $effect_image) {
                    return false;
                }
                imagefilter($effect_image, IMG_FILTER_GRAYSCALE, 100, 50, 0);
                break;
            case 'sepia':
                $effect_image = $this->imagePrepareFilterImage($source);
                if ( ! $effect_image) {
                    return false;
                }
                imagefilter($effect_image, IMG_FILTER_GRAYSCALE);
                imagefilter($effect_image, IMG_FILTER_COLORIZE, 100, 50, 0);
                break;
            case 'invert':
                $effect_image = $this->imagePrepareFilterImage($source);
                if ( ! $effect_image) {
                    return false;
                }
                imagefilter($effect_image, IMG_FILTER_NEGATE);
                break;
            case 'flip_horizontal':
                $effect_image = $this->flipHImage($source);
                if ( ! $effect_image) {
                    return false;
                }
                break;
            case 'flip_vertical':
                $effect_image = $this->flipVImage($source);
                if ( ! $effect_image) {
                    return false;
                }
                break;
            case 'flip_both':
                $effect_image = $this->flipVImage($source);
                if ( ! $effect_image) {
                    return false;
                }
                imagepng($effect_image, $effect_filename, 0);
                if (file_exists($effect_filename)) {
                    $effect_image = $this->flipHImage($effect_filename);
                }
                if ( ! $effect_image) {
                    return false;
                }
                break;
            default:
                break;
        }

        imagepng($effect_image, $effect_filename, 0);
        if (file_exists($effect_filename)) {
            return $effect_filename;
        }

        return false;
    }

    private function drawBackgroundRectangle($p, $block)
    {
        $device = 'cmyk';
        $color  = explode(" ", $block['backgroundColorOptions']['originalcolor']);

        if (isset($block['backgroundColorOptions']['code']) && $block['backgroundColorOptions']['code']) {

            $spot = $p->makespotcolor($block['backgroundColorOptions']['code']);
            $p->setcolor("fill", "spot", $spot, 1.0, 0, 0);

        } else {
            if (isset($block['backgroundColorOptions']['spotname']) && $block['backgroundColorOptions']['spotname']) {

                $spot = $p->makespotcolor($block['backgroundColorOptions']['spotname']);
                $p->setcolor("fill", "spot", $spot, 1.0, 0, 0);

        } else {
            $p->setcolor("fill", $device, $color[0], $color[1], $color[2], $color[3]);
            }




        }

        $generalOptions = '';

        $rotateAngle = isset($block['angle']) && $block['angle'] ? $block['angle']:0;
        $tetha       = deg2rad($rotateAngle);
        if (isset($block['addStroke']) && $block['addStroke']) {
            $block['x'] = $block['x'] - $block['strokeWidth'] / 2;
            $block['y'] = $block['y'] - $block['strokeWidth'] / 2;
            $block['width'] = $block['width'] + $block['strokeWidth'];
            $block['height'] = $block['height'] + $block['strokeWidth'];
        }
        /*bottom line*/
        $ax   = $block['x'];
        $ay   = $block['y'];
        $x    = $ax + $block['width'] * cos($tetha);
        $y    = $ay + $block['width'] * sin($tetha);
        $path = $p->add_path_point(0, $ax, $ay, "move", $generalOptions);
        $path = $p->add_path_point($path, $x, $y, "line", "");

        /*right line*/

        $tetha = deg2rad($rotateAngle + 90);
        $x     = $x + $block['height'] * cos($tetha);
        $y     = $y + $block['height'] * sin($tetha);
        $path  = $p->add_path_point($path, $x, $y, "line", "");
        /*top line*/

        $tetha = deg2rad($rotateAngle);
        $x     = $x - $block['width'] * cos($tetha);
        $y     = $y - $block['width'] * sin($tetha);
        /*left line*/
        $path  = $p->add_path_point($path, $x, $y, "line", "");
        $tetha = deg2rad($rotateAngle + 90);
        $x     = $x - $block['height'] * cos($tetha);
        $y     = $y - $block['height'] * sin($tetha);
        $path  = $p->add_path_point($path, $x, $y, "line", "");

        #path
        /*draw the border*/

        $p->draw_path($path, 0, 0, "fill");

        $p->save();
        $p->restore();

    }

    public function replace_callback($matches)
    {
        $middle = isset($this->csv_block_values[$matches[3]][$this->current_line]) ? $this->csv_block_values[$matches[3]][$this->current_line] : '%' . $matches[3] . '%';
        return $matches[1] . $middle . $matches[4];
    }

    public function getFontSizeToFit($pdf, $block, $text, $fsize, $fontname)
    {
        try {
            $text    = rtrim($text, "\n");
            $sarray  = explode("\n", $text);
            $mapping = array_combine($sarray, array_map('strlen', $sarray));
            $longest = array_keys($mapping, max($mapping));
            $longest = $longest[0];
            $width   = round(($block['x2'] - $block['x1']));
            $font    = $pdf->load_font("$fontname", "unicode", "embedding");
            if ($font) {
                $_string_width = $pdf->stringwidth("$longest", $font, $fsize);
                $t             = 0;
                $range         = range($width - 5, $width + 5);
                if ($_string_width > $width) {
                    for ($i = $fsize; $i > 0.1; $i = $i - 0.1) {
                        $_string_width = round($pdf->stringwidth("$longest", $font, $i));
                        if ($_string_width == $width || in_array($_string_width, $range)) {
                            break;
                        }
                        $t++;
                        if ($t == 3000) {
                            break;
                        }
                    }
                } else {
                    for ($i = $fsize; $i > 0.1; $i = $i + 0.1) {
                        $_string_width = round($pdf->stringwidth("$longest", $font, $i));
                        if ($_string_width == $width || in_array($_string_width, $range)) {
                            break;
                        }
                        $t++;
                        if ($t == 3000) {
                            break;
                        }
                    }
                }
                if ((int)$i > 0) {
                    $fsize = $i;
                }
            }
            return $fsize;
        } catch (PDFlibException $e) {
            $error = $e->getMessage();
        } catch (\Exception $e) {
            $error = $e->getMessage();
        }

        return $fsize;
    }
    public function diecutCustomLayers($pageData, &$pdf)
    {
        if (isset($pageData['custom_layers_diecuts']) && is_array($pageData['custom_layers_diecuts']) && count($pageData['custom_layers_diecuts'])) {

            foreach($pageData['custom_layers_diecuts'] as $key=> $layer_data){

                $use_layer         = isset($layer_data['use_layer']) && $layer_data['use_layer'] ? 1 : 0;
                $custom_layer_name = isset($layer_data['custom_layer_name']) && $layer_data['custom_layer_name'] ? $layer_data['custom_layer_name'] : 0;

                if($use_layer && $custom_layer_name){
                    $layerDiecut = $pdf->define_layer($custom_layer_name, "");
                    $pdf->begin_layer($layerDiecut);
                }

                $svgPdf = tempnam("/tmp", "SVG");

                $this->filesToDelete[]  = $svgPdf;
                file_put_contents($svgPdf, $layer_data['svg']);


                $optlist = "boxsize={ " . $pageData['page_width'] . " " . $pageData['page_height'] .
                    "} position={center} fitmethod=entire ";

                $graphics = $pdf->load_graphics("auto", $svgPdf, "");

                $overprint_string = "overprintstroke=false overprintmode=0";

                if(isset($layer_data['use_overprint']) && $layer_data['use_overprint'])	{
                    $overprint_string = "overprintstroke=true overprintmode=1";
                }

                $gstate = $pdf->create_gstate($overprint_string );
                $pdf->save();
                $pdf->set_gstate($gstate);

                if(isset($layer_data['use_spot']) && $layer_data['use_spot']){

                    $spot_name         = isset($layer_data['spot_name']) && $layer_data['spot_name'] ? $layer_data['spot_name'] : 0;
                    $spot_cmyk 		   = isset($layer_data['spot_cmyk']) && $layer_data['spot_cmyk'] ? $layer_data['spot_cmyk'] : 0;

                    if($spot_name && $spot_cmyk){
                        $spot_cmyk = explode(" ", $spot_cmyk);
                        $pdf->setcolor("fillstroke", "cmyk", $spot_cmyk[0], $spot_cmyk[1], $spot_cmyk[2], $spot_cmyk[3]);
                        $spot = $pdf->makespotcolor($spot_name);
                    }

                }



                if ($pdf->info_graphics($graphics, "fittingpossible", '') == 1) {
                    $pdf->fit_graphics($graphics, 0, 0, $optlist);
                } else {
                    print_r($pdf->get_errmsg());
                }
                $pdf->restore();

                if($use_layer && $custom_layer_name){
                    $pdf->end_layer();
                }

            }
        }
    }
    public function diecutPackingRosendahls($pageData, &$pdf)
    {
        if (isset($pageData['diecut_packing_rosendahls']) && $pageData['diecut_packing_rosendahls']) {
            $svgPdf = tempnam("/tmp", "SVG");
            $this->filesToDelete[]  = $svgPdf;
            file_put_contents($svgPdf, $pageData['diecut_packing_rosendahls']);


            $optlist = "boxsize={ " . $pageData['page_width'] . " " . $pageData['page_height'] .
                "} position={center} fitmethod=entire ";

            $graphics = $pdf->load_graphics("auto", $svgPdf, "");


            preg_match('/(bleedColor)=["\']?((?:.(?!["\']?\s+(?:\S+)=|["\']))+.)["\']?/is', $pageData['diecut_packing_rosendahls'], $bleedColor);

            if ( ! (is_array($bleedColor) && count($bleedColor))) {
                $bleedColor = false;
            }


            /*if( strpos( $pageData['diecut_packing_rosendahls'], 'DieCutBleed' ) !== false || ! $bleedColor ) {
                        $rgbTmp = str_replace(array('rgb(',')'),'',(string)$bleedColor[2]);
                        $rgbTmp = explode(',',$rgbTmp);

                        $pdf->setcolor( "fillstroke", "rgb", $rgbTmp[0] / 255, $rgbTmp[1] / 255, $rgbTmp[2] / 255, 0 );
                        $spot = $pdf->makespotcolor( "DieCutBleed" );
                }*/

            if (strpos($pageData['diecut_packing_rosendahls'], 'Bleed') !== false) {

                $pdf->setcolor("fillstroke", "cmyk", 0, 0.7, 1, 0);
                $spot = $pdf->makespotcolor("Bleed");
            }

            $gstate = $pdf->create_gstate("overprintstroke=false");
            $pdf->save();
            $pdf->set_gstate($gstate);
            if (strpos($pageData['diecut_packing_rosendahls'], 'GluedArea') !== false) {

                $pdf->setcolor("fillstroke", "cmyk", 0, 0.45, 0.4, 0);
                $spot = $pdf->makespotcolor("GluedArea");
            }
            $pdf->restore();


            $gstate = $pdf->create_gstate("overprintstroke=true overprintmode=1");
            $pdf->save();
            $pdf->set_gstate($gstate);

            if (strpos($pageData['diecut_packing_rosendahls'], 'DieCutting') !== false) {
                $pdf->setcolor("fillstroke", "cmyk", 1, 0, 0, 0);
                $spot = $pdf->makespotcolor("DieCutting");
            }
            if (strpos($pageData['diecut_packing_rosendahls'], 'FoldEdge') !== false) {
                $pdf->setcolor("fillstroke", "cmyk", 0, 0, 0, 1);
                $spot = $pdf->makespotcolor("FoldEdge");
            }


            if ($pdf->info_graphics($graphics, "fittingpossible", '') == 1) {
                $pdf->fit_graphics($graphics, 0, 0, $optlist);
            } else {
                print_r($pdf->get_errmsg());
            }
            $pdf->restore();
        }
    }

    private function createWhiteUnderprintForBlocks($data) {
        $found = false;
        $data['pdfData'] = json_decode($data['pdfData'], true);

        foreach ($data['pdfData'] as $pageKey => $pageData) {
            if (is_array($pageData['blocks']) && count($pageData['blocks']) > 0) {
                foreach ($pageData['blocks'] as $blockKey => $block) {
                    if(  isset($block['whiteUnderprintIsActive']) && $block['whiteUnderprintIsActive'] ) {
                        $new_block = $block;
                        $new_block['x'] = 0;
                        $new_block['y'] = 0;
                        $new_block['angle'] = 0;
                        $newData = $data;
                        $newData['pdfData'] = [
                            0=>[
                            'page_width'     => $block['width'],
                            'page_width_pdf' => $block['width'],
                            'page_height'    => $block['height'],
                            'page_height_pdf'=> $block['height'],
                            'blocks'         => [ $new_block ]
                            ]
                        ];
                      

                        $uniqId = uniqid(true);
                        
                        $whiteUnderPrintFile    = ROOT_PATH . $this->whiteUnderprintPdfFolder . "$data[selection]." . $uniqId . ".pdf";
                        $whiteUnderPrintPdfMask = ROOT_PATH . $this->whiteUnderprintPdfFolder . "$data[selection]_mask." . $uniqId . ".tif";
                        $whiteUnderPrintPreview = ROOT_PATH . $this->whiteUnderprintPdfFolder . "$data[selection]." . $uniqId . ".tif";

                        

                        $newData['pdfData'] = json_encode($newData['pdfData']);
                        $newData['whiteunderprintThreshold'] = $block['whiteUnderprintThreshold'];

                        $this->makeWhiteUnderprintPdf( $newData, true, $whiteUnderPrintFile, $whiteUnderPrintPdfMask, $whiteUnderPrintPreview);
                       
                        $found = true;
                        $data['pdfData'][$pageKey]['blocks'][$blockKey]['whiteUnderprintImageSrc'] = $whiteUnderPrintPreview;

                        unlink($whiteUnderPrintPdfMask);
                    }
                }
            }
        }

        if( ! $found ) {
            return null;
        }

        $data['pdfData'] = json_encode($data['pdfData']);

        return $data;
    }

    public function previewwhiteunderprintAction()
    {
        return $this->makeWhiteUnderprintPdf($_POST, false);
    }

    private function makeWhiteUnderprintPdf($data, $local = false, $useWhiteUnderPrintFile = null, $useWhiteUnderPrintPdfMask = null, $useWhiteUnderPrintPreview = null) {

        $this->time       = microtime(true);
        $this->globaltime = microtime(true);

        $file             = "";
        $data['svg']      = array();
        $html5Editor      = 1;

        ob_start();
        $path  = ROOT_PATH . '/data/pdfs/tmp/';
        $error = '';

        $pdf_file_name           = "$data[selection].pdf";        
        $pdf_file_name_tif       = "$data[selection]_mask.tif";
        $pdf_file_name_png       = "$data[selection]_pdfmask.png";
        $pdf_file_name_full_mask = "$data[selection]_full_mask"; // .png extension is added in function
        

        $whiteUnderPrintFile     = $useWhiteUnderPrintFile    == null ? ROOT_PATH . $this->whiteUnderprintPdfFolder . $pdf_file_name     : $useWhiteUnderPrintFile;
        $whiteUnderPrintPdfMask  = $useWhiteUnderPrintPdfMask == null ? ROOT_PATH . $this->whiteUnderprintPdfFolder . $pdf_file_name_tif : $useWhiteUnderPrintPdfMask;
        $whiteUnderPrintPreview  = $useWhiteUnderPrintPreview == null ? ROOT_PATH . $this->whiteUnderprintPdfFolder . $pdf_file_name_png : $useWhiteUnderPrintPreview;
        $whiteUnderPrintFullMask = ROOT_PATH . $this->whiteUnderprintPdfFolder . $pdf_file_name_full_mask;

        $threshold = 1;
        if( isset($data['whiteunderprintThreshold']) ) {
            $threshold = $data['whiteunderprintThreshold'];
        }

        if( isset($data['white_underprint_mask']) ) {
            // we already have the mask
            if( ! file_exists(ROOT_PATH . $this->whiteUnderprintPdfFolder . $data['white_underprint_mask']) ) {
                throw new \Exception('Mask does not exist');
            }

            $this->applyMorphology($threshold, ROOT_PATH . $this->whiteUnderprintPdfFolder . $data['white_underprint_mask'], $whiteUnderPrintPreview);
            
            if (!file_exists($whiteUnderPrintPreview)) {
                throw new \Exception('Couldn`t create output');
            }
    
            $image_base64 = base64_encode(file_get_contents($whiteUnderPrintPreview));

            unlink($whiteUnderPrintPreview);

            return response()->json(array(
                'data' => [
                    'white_underprint_mask' => $data['white_underprint_mask'],
                    'image' => $image_base64
                ],
                'success' => 1
            ));            
        }

        if( ! $local ) {
            // upload them from Action
            $this->uploadFiles($path);
        }

        if( ! file_exists(ROOT_PATH . $this->whiteUnderprintPdfFolder) ) {
            mkdir(ROOT_PATH . $this->whiteUnderprintPdfFolder, 0777, true);
        }

        try {
            $pdi                        = true;
            $svg                        = false;
            $usePdfDesigner             = false;
            $applyWatermarkOnTheSamePdf = isset($data['applyWatermarkOnTheSamePdf']) && $data['applyWatermarkOnTheSamePdf'] ? $data['applyWatermarkOnTheSamePdf'] : false;

            if (isset($data['generateSvgImage']) && $data['generateSvgImage']) {
                $pdi = false;
            }

            $pdfvt      = false;
            $use_pdf_vt = false;

            
            if (isset($data['pdfData']) && strlen($data['pdfData'])) {
                $svg = true;
            }
            if (isset($data['use_pdf']) && $data['use_pdf']) {
                $usePdfDesigner = true;
            }        

            if (isset($data['use_pdf_vt_otp']) && $data['use_pdf_vt_otp']) {
                $use_pdf_vt = true;
            }

            
            $this->startPdf($file, $pdf, $doc, $pdi, $pdfvt, $svg, $use_pdf_vt, $usePdfDesigner, $applyWatermarkOnTheSamePdf);
            
            $this->loadFonts($data, $pdf, $pdfvt, $use_pdf_vt);
            
            $this->createSvgFromJson($data, $doc, $pdf, $path, $local == false);
            
            $pdf->end_document("");
            if ( ! $svg && $pdi && ! $html5Editor) {
                $pdf->close_pdi_document($doc);
            }

            $buf = $pdf->get_buffer();
            $pdf           = null;

            $watermark     = isset($data['watermark']) && $data['watermark'] ? true : false;
            if($applyWatermarkOnTheSamePdf && $watermark){
                $pdf_file_name = "$data[selection]_watermark.pdf";
            }

            $maskColor = $local ? 'black' : 'rgb(0,255,255)';
            
            if (file_put_contents($whiteUnderPrintFile, $buf)) {
                // we have the pdf
                try {                    
                    $this->getPdfMask($whiteUnderPrintFile, $whiteUnderPrintPreview, 1, false, $whiteUnderPrintPdfMask, 
                            $whiteUnderPrintFullMask, $maskColor, 300, $threshold);
                }
                catch(\Exception $e) {
                    return response()->json(array(
                        'data' => ['message' => 'Error creating white underprint'],                    
                        'success' => 0
                    ));
                }

                unlink($whiteUnderPrintFile);

                if( $local == true ) {
                    // don't read the file. it is not needed
                    unlink($whiteUnderPrintFullMask . ".png");
                    
                    return;
                }

                $image_base64 = base64_encode(file_get_contents($whiteUnderPrintPreview));
                $image_preview_base64 = base64_encode(file_get_contents($whiteUnderPrintFullMask . ".png"));

                unlink($whiteUnderPrintPreview);
                unlink($whiteUnderPrintFullMask . ".png");

                return response()->json(array(
                    'data' => [
                        'white_underprint_mask' => $pdf_file_name_tif,
                        'image' => $image_base64,
                        'preview_image' => $image_preview_base64,
                    ],
                    'success' => 1
                ));
            }
            else {
                return response()->json(array(
                    'result' => array('error' => "Error saving white print pdf"),
                ));
            }
        } catch (PDFlibException $e) {
            $error = $e->getMessage();
        } catch (\Exception $e) {
            $error = $e->getMessage();
        }
        ob_end_clean();

        return response()->json(array(
            'result' => array('error' => print_r($error, true)),
        ));
    }
    
    public function scalePrintServicePdf($file, $data)
    {
        try {
            $pdf       = new \PDFlib();
            $maxWidth  = isset($data['maxWidthPrintService']) ? $data['maxWidthPrintService'] : 500;
            $maxHeight = isset($data['maxHeightPrintService']) ? $data['maxHeightPrintService'] : 500;
            if (config('rest.pdf_license_key')) {
                $pdf->set_option("license=" . config('rest.pdf_license_key'));
            }
            $pdf->set_option("errorpolicy=return");
            $pdf->set_option("stringformat=utf8");
            /* This means we must check return values of load_font() etc. */
            $doc        = $pdf->open_pdi_document($file, "");
            $totalPages = $pdf->pcos_get_number($doc, 'length:pages');
            if (!$pdf->begin_document('', '')) {
                throw new \Exception('Error: ' . $pdf->get_errmsg());
            }
            for ($i = 0; $i < $totalPages; $i++) {
                $backgroundPage = $pdf->open_pdi_page($doc, $i + 1, "");
                $oldWidth       = $width = $pdf->pcos_get_number($doc, "pages[$i]/width");
                $oldHeight      = $height = $pdf->pcos_get_number($doc, "pages[$i]/height");
                $ratio          = $height / $width;
                if ($width > $maxWidth || $height > $maxHeight) {
                    if ($maxWidth < $maxHeight) {
                        $width  = $maxWidth;
                        $height = $width * $ratio;
                    } else {
                        $height = $maxHeight;
                        $width  = $height * $ratio;
                    }
                    
                }
                $optList     = "boxsize={" . $width . " " . "$height} fitmethod=meet position=center";
                $trimbox     = "";
                $widthRatio  = $oldWidth / $width;
                $heightRatio = $oldHeight / $height;
                if ($pdf->pcos_get_string($doc, "type:pages[$i]/TrimBox") == 'array') {
                    $trimbox     = array(
                        $pdf->pcos_get_string($doc, "pages[$i]/TrimBox[0]"),
                        $pdf->pcos_get_string($doc, "pages[$i]/TrimBox[1]"),
                        $pdf->pcos_get_string($doc, "pages[$i]/TrimBox[2]"),
                        $pdf->pcos_get_string($doc, "pages[$i]/TrimBox[3]"),
                    );
                    $trimbox_llx = $trimbox[0] / $widthRatio;
                    $trimbox_lly = $trimbox[1] / $heightRatio;
                    $trimbox_urx = $trimbox[2] / $widthRatio;
                    $trimbox_ury = $trimbox[3] / $heightRatio;;
                    $trimbox = " trimbox={" . $trimbox_llx . " " . $trimbox_lly . " " . $trimbox_urx . " " . $trimbox_ury . "}";
                }
                $pdf->begin_page_ext($width, $height, $trimbox);
                $pdf->fit_pdi_page($backgroundPage, 0.0, 0.0, $optList);
                $pdf->end_page_ext("");
                $pdf->close_pdi_page($backgroundPage);
            }
            $pdf->end_document("");
            $pdf->close_pdi_document($doc);
            $buf = $pdf->get_buffer();
            $len = strlen($buf);
            if ($len) {
                file_put_contents($file, $buf);
            }
        } catch (PDFlibException $e) {
        
        } catch (\Exception $e) {
        
        }
    }
}
