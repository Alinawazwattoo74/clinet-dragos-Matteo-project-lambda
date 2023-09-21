<?php

namespace Printq\Rest\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class RestPreviewformularController extends BaseController
{
    protected $pdfSearchPath = '/data/pdfs/tmp';

    protected $pdfResultFolder = '/data/result/';

    protected $helperPdfFolder = '/data/helperpdfs/';

    protected $helperPdfResultFolder = '/data/helperpdfs/result/';

    protected $fontSearchPath = '/data/fonts/';

    protected $time = 0;

    protected $globaltime = 0;

    protected $csv_block_values = array();

    protected $current_line  = 0;
    protected $loaded_images = array();

    protected $MM_TO_PT = 2.834645669;

    protected $packingDiecutColors = [
        'default'    => [
            'DieCutRed'     => [0, 1, 1, 0],
            'DieCutBlue'    => [1, 1, 0, 0],
            'DieCutGreen'   => [0, 0, 1, 0.5],
            'DieCutNoPrint' => [0, 0, 0, 0.28]
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
        ]
    ];

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
        $error = '';
        $path = ROOT_PATH . '/data/pdfs/tmp/';
        $this->uploadFiles($path);
        $filePath             = $data['uuids'][$data['image']['b_pdf__']];
        $tmp = explode('/', urldecode($filePath));
        $file = array_pop($tmp);
        $data['svg']      = array();
        if (file_exists($path. $file)) {
            $result = $this->generatePreview($data, $file);
            return $result;
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

        //$this->uploadFiles($path);

        try {
            $pdi            = true;
            $svg            = false;
            $usePdfDesigner = false;
            $useDistortPdf  = false;
            if (isset($data['generateSvgImage']) && $data['generateSvgImage']) {
                $pdi = false;
            }

            $pdfvt      = false;
            $use_pdf_vt = false;

            if (isset($data['pdfData']) && strlen($data['pdfData'])) {
                $svg = true;
            }
            if (isset($data['pdfvt']) && $data['pdfvt']) {
                $pdfvt = true;
            }
            if (isset($data['use_pdf']) && $data['use_pdf']) {
                $usePdfDesigner = true;
            }

            if (isset($data['use_pdf_vt_otp']) && $data['use_pdf_vt_otp']) {
                $use_pdf_vt = true;
            }
            $this->startPdf($file, $pdf, $doc, $pdi, $pdfvt, $svg, $use_pdf_vt, $usePdfDesigner);

            if (isset($data['watermark']) && $data['watermark']) {
                $this->startPdf($file, $pdf2, $doc2, $pdi, false, $svg, $use_pdf_vt);
            }
            if (isset($data['isPackaging']) && $data['isPackaging']) {
                $this->startPdf($file, $pdf3, $doc, $pdi, $pdfvt, $svg, $use_pdf_vt, false);
            }
            $this->loadFonts($data, $pdf, $pdfvt, $use_pdf_vt);
            if (isset($data['watermark']) && $data['watermark']) {
                $this->loadFonts($data, $pdf2, false, $use_pdf_vt);
            }


            if ($svg) {

                $this->createSvgFromJson($data, $doc, $pdf, $path);
                if (isset($data['isPackaging']) && $data['isPackaging']) {
                    $this->createPdfPackaging($data, $pdf3);
                }

            } else {
                if ($pdi) {
                    $page_length = $pdf->pcos_get_number($doc, 'length:pages');
                    if ($page_length) {
                        if (isset($data['shapeGenerate']) && $data['shapeGenerate']) {
                            $this->createShape($data, $pdf, $doc, false, $pdi);
                            if (isset($data['watermark']) && $data['watermark']) {
                                $this->createShape($data, $pdf2, $doc2, true, $pdi);
                            }
                        } else {
                            $data_watermark = $data;
                            if ($pdfvt) {
                                $this->fillPdfVtBlocks($data, $page_length, $pdf, $doc, $path, $img, false);
                            } else {

                                $this->fillPdfBlocks($data, $page_length, $pdf, $doc, $path, $img, false);
                            }

                            if (isset($data['watermark']) && $data['watermark']) {
                                $this->fillPdfBlocks($data_watermark, $page_length, $pdf2, $doc2, $path, $img, true);
                            }
                        }
                    } //end page_length if
                } else {
                    if (isset($data['shapeGenerate']) && $data['shapeGenerate']) {
                        $this->createShape($data, $pdf, $doc, false, $pdi);
                        if (isset($data['watermark']) && $data['watermark']) {
                            $this->createShape($data, $pdf2, $doc2, true, $pdi);
                        }
                    }
                }
            }
            $pdf->end_document("");
            if ( ! $svg && $pdi) {
                $pdf->close_pdi_document($doc);
            }

            if (isset($data['watermark']) && $data['watermark']) {
                $pdf2->end_document("");
                if ( ! $svg && $pdi) {
                    $pdf2->close_pdi_document($doc2);
                }
            }
            if (isset($data['isPackaging']) && $data['isPackaging']) {
                $pdf3->end_document("");

            }

            $buf = $pdf->get_buffer();
            if (isset($data['watermark']) && $data['watermark']) {
                $buf2 = $pdf2->get_buffer();
            }
            if (isset($data['isPackaging']) && $data['isPackaging']) {
                $buf3 = $pdf3->get_buffer();
            }
            $pdf           = null;
            $image_preview = $data['selection'] . '.pdf';
            $hires         = isset($data['hires']) ? $data['hires'] : '';

            if (file_put_contents(ROOT_PATH . $this->pdfResultFolder . "$data[selection].pdf", $buf)) {

                if (isset($data['use_outline']) && $data['use_outline']) {
                    $this->generateWithoutOutline($data['selection']);
                }
                if (isset($data['watermark']) && $data['watermark']) {
                    file_put_contents(ROOT_PATH . $this->pdfResultFolder . "$data[selection]_watermark.pdf", $buf2);
                    $wtm_image_preview = $data['selection'] . '_watermark' . '.pdf';
                }
                if (isset($data['isPackaging']) && $data['isPackaging']) {

                    file_put_contents(ROOT_PATH . $this->pdfResultFolder . "$data[selection]_packaging.pdf", $buf3);

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
                if (isset($data['watermark']) && $data['watermark'] && $data['watermark']['preview']) {
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

                return $image;
            }
        } catch (\PDFlibException $e) {
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
    protected function startPdf($file, &$pdf, &$doc, $pdi = true, $pdfvt = false, $svg = false, $use_pdf_vt_otp = false, $usePdfDesiner = false)
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
            $optlist = "pdfx=PDF/X-4 pdfvt=PDF/VT-1 usestransparency=true nodenamelist={root recipient} recordlevel=1";
        }

        if ( ! $pdf->begin_document('', $optlist)) {
            throw new \Exception('Error: ' . $pdf->get_errmsg());
        }

        if (( ! $svg || $usePdfDesiner) && $pdi) {

            if ($pdi) {

                if ($pdfvt || $use_pdf_vt_otp) {
                    $pdf->set_info("Creator", "CloudLab");
                    $pdf->set_info("Title", "Business card");
                    # Define output intent profile */
					 $iso  = ROOT_PATH . '/data/ISOcoated.icc';
                    if ($pdf->load_iccprofile( $iso  , "usage=outputintent") == 0) {
                        printf("Error: %s\n", $pdf->get_errmsg());
                        echo("Please install the ICC profile package from " .
                             "www.pdflib.com to run the PDF/VT-1 starter sample.\n");
                        exit(1);
                    }
                }
                $doc = $pdf->open_pdi_document($file, "");
                if ( ! $doc) {
                    throw new \Exception('Error: ' . $pdf->get_errmsg());
                }
            }
        }
    }

    /**
     * @param $data
     * @param $pdf
     *
     * @throws \Exception
     */
    protected function loadFonts(&$data, $pdf, $pdfvt = false, $use_pdf_vt_otp = false)
    {
        // check for provided fonts
        if (isset($data['fonts'])) {
            $fonts = array_unique($data['fonts']);
        } else {
            $fonts = array();
        }
        if ($use_pdf_vt_otp) {
            $fonts[] = 'Helvetica';
        }
        if (count($fonts)) {
            foreach ($fonts as $font) {
                if (file_exists(ROOT_PATH . $this->fontSearchPath . $font . '.ttf') || file_exists(ROOT_PATH . $this->fontSearchPath . $font . '.TTF') || file_exists(ROOT_PATH
                        . $this->fontSearchPath . $font . '.otf') || file_exists(ROOT_PATH . $this->fontSearchPath . $font . '.OTF')) {
                    if (file_exists(ROOT_PATH . $this->fontSearchPath . $font . '.ttf')) {
                        $pdf->set_option("FontOutline={" . $font . "=" . $font . ".ttf}");
                    } elseif (file_exists(ROOT_PATH . $this->fontSearchPath . $font . '.TTF')) {
                        $pdf->set_option("FontOutline={" . $font . "=" . $font . ".TTF}");
                    }
                    if (file_exists(ROOT_PATH . $this->fontSearchPath . $font . '.otf')) {
                        $pdf->set_option("FontOutline={" . $font . "=" . $font . ".otf}");
                    } elseif (file_exists(ROOT_PATH . $this->fontSearchPath . $font . '.OTF')) {
                        $pdf->set_option("FontOutline={" . $font . "=" . $font . ".OTF}");
                    }
                    if ($pdfvt || $use_pdf_vt_otp) {
                        $pdf->load_font($font, "unicode", "embedding") or die (PDF_get_error($pdf));
                    }
                }
            }
        }
        // end fonts
    }

    protected function createSvgFromJson($data, $doc, $pdf, $pathFolder)
    {
        $data['pdfData'] = json_decode($data['pdfData'], true);
        $templatePdf     = null;

        if (isset($data['funke_customer_custom_add_pages']) && $data['funke_customer_custom_add_pages']) {
            $templatePdf = ROOT_PATH . $this->pdfSearchPath . $data['file'];
        }

        foreach ($data['pdfData'] as $pageKey => $pageData) {

            if ($data['use_pdf']) {

                $page   = $pdf->open_pdi_page($doc, $pageKey + 1, "cloneboxes");
                $width  = $pdf->pcos_get_number($doc, "pages[$pageKey]/width");
                $height = $pdf->pcos_get_number($doc, "pages[$pageKey]/height");
                $pdf->begin_page_ext($width, $height, "");
                $pdf->fit_pdi_page($page, 0.0, 0.0, "cloneboxes");

            } else {
                $optList = '';
                $width   = $pageData['page_width'];
                $height  = $pageData['page_height'];

                if (isset($pageData['addTrimBox']) && $pageData['addTrimBox'] && $pageData['trimBox']) {
                    $optList = " trimbox={" . $pageData['trimBox'][0] . " " . $pageData['trimBox'][1] . " " . $pageData['trimBox'][2] . " " . $pageData['trimBox'][3] . "}";;
                }

                $pdf->begin_page_ext($width, $height, $optList);


                if (isset($data['funke_customer_custom_add_pages']) && $data['funke_customer_custom_add_pages']) {

                    $indoc          = $pdf->open_pdi_document($templatePdf, "");
                    $pageBackground = $pdf->open_pdi_page($indoc, ($pageData['originalPage'] + 1), "cloneboxes");

                    if ($pageBackground == 0) {

                        throw new Exception("Error: %s\n", $pageBackground->get_errmsg());
                    }
                    $pdf->fit_pdi_page($pageBackground, 0, 0,
                        "boxsize={" . $width . " " . $height . "} fitmethod=entire cloneboxes");
                }

            }

            $pageEditorWidth  = $width;
            $pageEditorHeight = $height;

            if (is_array($pageData['layout']) && isset($pageData['layout']['src']) && $pageData['layout']['src']) {


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

                                $indoc = $pdf->open_pdi_document(ROOT_PATH . $this->pdfSearchPath . 'tmp/' . $block['src'], "");

                                if (strpos($block['src'], 'additional') !== false) {

                                    if (isset($data['funke_customer_custom_add_pages']) && $data['funke_customer_custom_add_pages']) {
                                        $page_p = $pdf->open_pdi_page($indoc, ($pageData['originalPage'] + 1), "");
                                    } else {
                                        $page_p = $pdf->open_pdi_page($indoc, ($pageKey + 1), "");
                                    }

                                } else {
                                    $page_p = $pdf->open_pdi_page($indoc, 1, "");
                                }


                                if ($page_p == 0) {
                                    throw new \Exception("Error: %s\n", $page_p->get_errmsg());
                                }
                                $flipX = $block['flipX'];
                                $flipY = $block['flipY'];
                                $pdf->fit_pdi_page($page_p, $block['x'], $block['y'],
                                    "boxsize={" . $block['width'] . " " . $block['height'] . "} fitmethod=entire scale={ $flipX $flipY } rotate=" . $block['angle']);
                            }
                            break;
                        case 'image':

                            $imageSrc         = $pathFolder . $block['src'];
                            $cx               = $block['cx'];
                            $cy               = $block['cy'];
                            $cw               = $block['cw'];
                            $ch               = $block['ch'];
                            $flipX            = $block['flipX'];
                            $flipY            = $block['flipY'];
                            $brightness_value = (isset($block['brightness']) && $block['brightness']) ? $block['brightness'] : 0;
                            $contrast_value   = (isset($block['contrast']) && $block['contrast']) ? $block['contrast'] : 0;


                            if (isset($block['filter']) && $block['filter']) {

                                $imageSrc = $this->filterImageDesigner($block['filter'], array('original_file_src' => $imageSrc));

                            }

                            if ($brightness_value != 0 || $contrast_value != 0) {

                                $imageSrc = $this->setBrighnessContrast(array(
                                    'brightness' => $brightness_value / 100,
                                    'contrast'   => $contrast_value / 100
                                ), $imageSrc);

                            }

                            $image   = $pdf->load_image("auto", $imageSrc, "");
                            $optlist = "boxsize={" . $block['width'] . " " . $block['height'] . "}  matchbox={clipping={ $cx $cy $cw $ch }} fitmethod=entire scale={ $flipX $flipY } rotate=" . $block['angle'];

                            $pdf->fit_image($image, $block['x'], $block['y'], $optlist);

                            break;

                            break;
                        case 'textbox':

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

                            if (isset($block['fontFamily']) && strlen($block['fontFamily']) > 0) {
                                $defaultOptlist['fontFamily'] = ' fontname={' . $block['fontFamily'] . '} ';
                            }

                            if (isset($block['fontFamily']) && $block['fontFamily'] !== 'Helvetica') {
                                $defaultOptlist['fontFamily'] .= ' embedding';
                            }
                            if (isset($block['fontSize']) && strlen($block['fontSize']) > 0) {
                                $defaultOptlist['fontSize'] = ' fontsize=' . $block['fontSize'];
                            }

                            if (is_array($block['fillOptions']) && ! empty($block['fillOptions'])) {

                                switch ($block['fillOptions']['colorspace']) {

                                    case 'DeviceCMYK':
                                        $defaultOptlist['fillcolor'] = ' fillcolor={cmyk ' . $block['fillOptions']['originalcolor'] . '}';
                                        break;
                                    case 'DeviceRGB':
                                        $defaultOptlist['fillcolor'] = ' fillcolor={rgb ' . $block['fillOptions']['originalcolor'] . '}';
                                        break;
                                }
                                if (isset($block['fillOptions']['code']) && $block['fillOptions']['code']) {
                                    $spot = $pdf->makespotcolor($block['fillOptions']['code']);
                                    $pdf->setcolor("fill", "spot", $spot, 1.0, 0, 0);

                                }
                                if (isset($block['fillOptions']) && $block['fillOptions'] && isset($block['fillOptions']['spotname']) && $block['fillOptions']['spotname']) {
                                    $defaultOptlist['fillcolor'] = ' fillcolor={spotname {' . $block['fillOptions']['spotname'] . '}  1.0 {cmyk ' . $block['fillOptions']['originalcolor'] . '} }';
                                }
                                if (isset($block['hasRefinement']) && $block['hasRefinement']) {
                                    if (isset($block['fillOptions']) && $block['fillOptions'] && $block['fillOptions']['transparent']) {
                                        $defaultOptlist['transparent'] = ' gstate=' . $gstateRefinementOpacity;
                                    } else {

                                        $defaultOptlist['transparent'] = ' gstate=' . $gstateOverprint;
                                    }
                                }

                            } else {
                                $defaultOptlist['fillcolor'] = ' fillcolor={rgb 0 0 0}';
                            }

                            $lead                      = (float)$block['fontSize'] * (float)$block['lineHeight'] * (float)$block['_fontSizeMult'];
                            $defaultOptlist['leading'] = ' leading=' . $lead;
                            $leadNextLine              = $defaultOptlist['leading'];


                            if (isset($block['textAlign'])) {
                                $defaultOptlist ['alignment'] = ' alignment=' . $block['textAlign'];
                            }

                            //calculateleading for each Line
                            $leadingText = array();

                            foreach ($block['textLines'] as $key => $textLine) {
                                if ($key == 0) {
                                    $leadingText[$key] = (float)$block['_lineHeightsOffsets'][$key]['ascender'];
                                } else {
                                    $leadingText[$key] = (float)$block['_lineHeightsOffsets'][$key]['ascender'] + (float)$block['_lineHeightsOffsets'][$key - 1]['descender'];
                                }


                            }


                            $firstLineMaxFontSize = -1;
                            $pdflibLineWidths     = array();
                            foreach ($block['textLines'] as $key => $textLine) {

                                $text  = '';
                                $chars = preg_split("//u", $textLine, -1, PREG_SPLIT_NO_EMPTY); //replaced the str_split because of unicode

                                $pdflibLineWidths[$key] = 0;
                                if (count($chars)) {
                                    for ($charIndex = 0; $charIndex < count($chars); $charIndex++) {

                                        $text .= $chars[$charIndex];
                                        if ($charIndex == count($chars) - 1 && $key < count($block['textLines']) - 1) {
                                            $text .= '<br>';
                                        }

                                        $currentStyle = $this->getStyleDeclaration($block, $key, $charIndex);

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
                                        if ($currentStyle != $this->getStyleDeclaration($block, $key, $charIndex + 1) || ($charIndex == count($chars) - 1)) {

                                            $text    = preg_replace('#<br\s*/?>#i', "\n", $text);
                                            $text    = strip_tags($text);
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

                                                $optlist['fontStyle'] = ' fontstyle=' . $fontStyleChar;

                                                if (isset($currentStyle['fontFamily']) && strlen($currentStyle['fontFamily']) > 0) {
                                                    $optlist['fontFamily'] = ' fontname={' . $currentStyle['fontFamily'] . '} ';
                                                }

                                                if (isset($currentStyle['fontFamily']) && $currentStyle['fontFamily'] !== 'Helvetica') {
                                                    $optlist['fontFamily'] .= ' embedding';
                                                }
                                                if (isset($currentStyle['fontSize']) && strlen($currentStyle['fontSize']) > 0) {
                                                    $optlist['fontSize'] = ' fontsize=' . ((float)$currentStyle['fontSize']);
                                                }


                                                if (isset($currentStyle['fillOptions']) && is_array($currentStyle['fillOptions']) && ! empty($currentStyle['fillOptions'])) {

                                                    switch ($currentStyle['fillOptions']['colorspace']) {

                                                        case 'DeviceCMYK':
                                                            $optlist['fillcolor'] = ' fillcolor={cmyk ' . $currentStyle['fillOptions']['originalcolor'] . '}';

                                                            if (isset($currentStyle['fillOptions']['spotname']) && $currentStyle['fillOptions']['spotname']) {
                                                                $optlist['fillcolor'] = ' fillcolor={spotname {' . $currentStyle['fillOptions']['spotname'] . '}  1.0 {cmyk ' . $currentStyle['fillOptions']['originalcolor'] . '} }';
                                                            }

                                                            break;
                                                        case 'DeviceRGB':
                                                            $optlist['fillcolor'] = ' fillcolor={rgb ' . $currentStyle['fillOptions']['originalcolor'] . '}';
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


                                            }
                                            if (isset($leadingText[$key])) {
                                                $optlist['leading'] = ' leading=' . $leadingText[$key];
                                            }

                                            $optionText             = $this->getTextOptions($optlist);
                                            $charOptions            = $optlist['fontStyle'] . $optlist['fontFamily'] . $optlist['fontSize'] . $optlist['encoding'];
                                            $width                  = $pdf->info_textline($text, "width", $charOptions);
                                            $pdflibLineWidths[$key] += $width;

                                            $tf = $pdf->add_textflow($tf, $text, $optionText);

                                            $text = '';
                                        }

                                    }
                                } else {

                                    $firstLineMaxFontSize = $block['fontSize'];
                                    $optlist              = $defaultOptlist;

                                    $currentStyle = $this->getStyleDeclaration($block, $key, 0);
                                    if (isset($currentStyle['fontSize']) && strlen($currentStyle['fontSize']) > 0) {
                                        $optlist['fontSize']  = ' fontsize=' . ((float)$currentStyle['fontSize']);
                                        $firstLineMaxFontSize = (float)$currentStyle['fontSize'];
                                    }
                                    if (isset($leadingText[$key])) {
                                        $optlist['leading'] = ' leading=' . $leadingText[$key];
                                    }
                                    if (isset($currentStyle['fontFamily']) && strlen($currentStyle['fontFamily']) > 0) {
                                        $optlist['fontFamily'] = ' fontname={' . $currentStyle['fontFamily'] . '} ';
                                    }

                                    if (isset($currentStyle['fontFamily']) && $currentStyle['fontFamily'] !== 'Helvetica') {
                                        $optlist['fontFamily'] .= ' embedding';
                                    }

                                    $optionText = $this->getTextOptions($optlist);
                                    $tf         = $pdf->add_textflow($tf, "\n", $optionText);
                                }

                            }

                            if (is_array($block['backgroundColorOptions']) && isset($block['backgroundColorOptions']['originalcolor']) && $block['backgroundColorOptions']['originalcolor'] !== 'transparent') {
                                $this->drawBackgroundRectangle($pdf, $block);
                            }

                            if ($firstLineMaxFontSize !== $block['fontSize']) {
                                $firstlinedist = (float)$block['fontSize'] * 0.03 + (float)$firstLineMaxFontSize * (float)$block['_fontSizeMult'] * (1 - (float)$block['fontSizeFraction']);//symplified

                            } else {
                                $firstlinedist = ((float)$firstLineMaxFontSize * (float)$block['_fontSizeMult']) - (float)$block['fontSize'] * $block['fontSizeFraction'];

                            }


                            $optTextFlow .= ' firstlinedist=' . $firstlinedist;


                            $widthOfBlock = $block['width'] > max($pdflibLineWidths) ? $block['width'] : max($pdflibLineWidths);
                            if ($tf) {
                                $pdf->fit_textflow($tf, $block['x'], $block['y'], $block['x'] + $widthOfBlock, $block['y'] + $block['height'], $optTextFlow . ' fitmethod=nofit');
                            }


                            break;
                        case 'graphics':

                            $svg = tempnam("/tmp", "SVG");
                            file_put_contents($svg, $block['svg']);

                            $optlist = "boxsize={ " . $block['width'] . " " . $block['height'] .
                                       "} position={center} fitmethod=entire ";


                            $graphics = $pdf->load_graphics("auto", $svg, "");

                            if (isset($block['fillOptions']) && $block['fillOptions'] && $block['fillOptions']['spotname']) {
                                $color = explode(' ', $block['fillOptions']['originalcolor']);

                                $pdf->setcolor("fill", "cmyk", $color[0], $color[1], $color[2], $color[3]);
                                $spot = $pdf->makespotcolor($block['fillOptions']['spotname']);
                            }

                            if (isset($block['hasRefinement']) && $block['hasRefinement'] && isset($block['angle']) && $block['angle']) {
                                $optlist .= ' rotate=' . $block['angle'];
                            }
                            if ($pdf->info_graphics($graphics, "fittingpossible", '') == 1) {
                                $pdf->fit_graphics($graphics, $block['x'], $block['y'], $optlist);
                            } else {
                                print_r($pdf->get_errmsg());
                            }
                            break;
                        case 'graphics_varnish':

                            $svg = tempnam("/tmp", "SVG");
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


                            if (isset($block['fontFamily']) && strlen($block['fontFamily']) > 0) {
                                $optlist .= ' fontname={' . $block['fontFamily'] . '} ';
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
                    if (isset($block['opacity']) && $block['opacity'] !== 1) {
                        $pdf->restore();
                    }

                }
            }
            if (isset($pageData['diecut_packing']) && $pageData['diecut_packing']) {
                $svgPdf = tempnam("/tmp", "SVG");
                file_put_contents($svgPdf, $pageData['diecut_packing']);

                $packingBlockWidth  = $pageData['page_width'];
                $packingBlockHeight = $pageData['page_height'];


                $optlist = "boxsize={ " . $packingBlockWidth . " " . $packingBlockHeight .
                           "} position={center} fitmethod=entire ";

                $graphics = $pdf->load_graphics("auto", $svgPdf, "");


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
            }

            if (isset($pageData['diecut_packing_rosendahls']) && $pageData['diecut_packing_rosendahls']) {
                $this->diecutPackingRosendahls($pageData, $pdf);
            }
            if (isset($pageData['diecut_packing_general']) && $pageData['diecut_packing_general']) {

                $this->diecutPackingGeneral($data,$pageData, $pdf);
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

            $pdf->end_page_ext("");
        }

    }

    public function createPdfPackaging($data, $pdf, $doc = false, $exist = 0)
    {

        if ($exist) {
            $page = $pdf->open_pdi_page($doc, 1, "");
        }
        try {
            $x_margin     = 0;
            $y_margin     = 0;
            $pixelConvert = 1;
            $svg          = tempnam("/tmp", "SVG");
            file_put_contents($svg, $data['packaging']);

            preg_match('/(packingwidth)=["\']?((?:.(?!["\']?\s+(?:\S+)=|[p]))+.)["\']?/is', $data['packaging'], $packingwidth);
            preg_match('/(packingheight)=["\']?((?:.(?!["\']?\s+(?:\S+)=|[p]))+.)["\']?/is', $data['packaging'], $packingheight);

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

            preg_match('/(trimboxulx)=["\']?((?:.(?!["\']?\s+(?:\S+)=|["\']))+.)["\']?/is', $data['packaging'], $trimboxulx);
            preg_match('/(trimboxuly)=["\']?((?:.(?!["\']?\s+(?:\S+)=|["\']))+.)["\']?/is', $data['packaging'], $trimboxuly);
            preg_match('/(trimboxlrx)=["\']?((?:.(?!["\']?\s+(?:\S+)=|["\']))+.)["\']?/is', $data['packaging'], $trimboxlrx);
            preg_match('/(trimboxlry)=["\']?((?:.(?!["\']?\s+(?:\S+)=|["\']))+.)["\']?/is', $data['packaging'], $trimboxlry);
            preg_match('/(bleedColor)=["\']?((?:.(?!["\']?\s+(?:\S+)=|["\']))+.)["\']?/is', $data['packaging'], $bleedColor);

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

            $client       = (isset($data['client']) && $data['client'] != '' && array_key_exists($data['client'], $this->packingDiecutColors)) ? $data['client'] : 'default';
            $diecutColors = $this->packingDiecutColors[$client];

            $pdf->set_option("FontOutline={Roboto=Roboto.ttf}");
            $pdf->load_font('Roboto', "unicode", "embedding");

            $graphics = $pdf->load_graphics("auto", $svg, "");
            if ($client === 'colordruck') {
                $gstate = $pdf->create_gstate("overprintstroke=true overprintfill=true overprintmode=1");
                $pdf->save();
                $pdf->set_gstate($gstate);
            }

            foreach ($diecutColors as $cType => $cValue) {
                if (strpos($data['packaging'], $cType) !== false) {
                    $pdf->setcolor("fillstroke", "cmyk", $cValue[0], $cValue[1], $cValue[2], $cValue[3]);
                    $spot = $pdf->makespotcolor($cType);
                }
            }

            if (strpos($data['packaging'], 'DieCutBleed') !== false || ! $bleedColor) {
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

            if ($client === 'colordruck') {
                $pdf->restore();
            }

            $pdf->end_page_ext("");

            $pdf->close_graphics($graphics);
            if ($exist) {
                $pdf->close_pdi_page($page);
            }

        } catch (PDFlibException $e) {
            throw new \Exception($e->getMessage());
        }
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

                if (isset($tmpBlock[$i])&&$tmpBlock[$i] && is_array($tmpBlock[$i]) && count($tmpBlock[$i])) {
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
                $blocks = $this->checkEmptyPdfVt($blocks, $data['csv_block_values'][$j]);
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
        $count            = 1;
        /*  if(isset($data['image'])){
                if(is_array($data['image']) && count($data['image'])){
                    foreach($data['image'] as $image){
                        $tmp        = explode( '/', urldecode( $image) );
                        $image_name = array_pop( $tmp );
                        $image_src           = $path . $image_name;
                        $ok = 0;
                        $iterations =0;
                        while(!$ok && $iterations < 3){
                            if(!file_exists($image_src)){
                                sleep(3);
                            }
                            else{
                                $ok=1;
                            }
                            $iterations ++;
                        }
                    }
                }
            }*/
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
        if (isset($data['pages_pdfs']) && $html5Editor) {
            $pages_pdfs = json_decode($data['pages_pdfs'], true);
        } else {
            $pages_pdfs = array();
        }

        if ($use_pdf_vt_otp) {
            $pdf->begin_dpart("");
        }
        $distanceToMove = array();
        for ($j = 1; $j <= $count; $j++) {
            for ($i = 0; $i < $page_length; $i++) {
                $width_diff              = 0;
                $height_diff             = 0;
                $optListTrimbox          = "";
                $use_trimbox_orientation = false;
                $x_page                  = 0;
                $y_page                  = 0;
                $diff_page               = 0;
                $diff_y                  = 0;
                $diff_x                  = 0;
                $diff_x_name             = 0;
                $diff_y_name             = 0;
                if ($use_pdf_vt_otp) {
                    $page = $pdf->open_pdi_page($doc, $i + 1, "cloneboxes pdfvt={scope=global environment={Cloudlab}}");
                } else {
                    if (isset($data['isCouponEditor']) && $data['isCouponEditor'] && isset($data['trimbox_position']) && in_array($data['trimbox_position'],
                            array('tlu', 'tru', 'tl', 'tr', 'tlb', 'trb', 'tnone', 'tuonly', 'tbonly'))) {
                        $trimbox_orientation = $data['trimbox_position'];
                        $page                = $pdf->open_pdi_page($doc, $i + 1, "");

                        if ($pdf->pcos_get_string($doc, "type:pages[$i]/TrimBox") == 'array') {
                            $trimbox      = array(
                                $pdf->pcos_get_string($doc, "pages[$i]/TrimBox[0]"),
                                $pdf->pcos_get_string($doc, "pages[$i]/TrimBox[1]"),
                                $pdf->pcos_get_string($doc, "pages[$i]/TrimBox[2]"),
                                $pdf->pcos_get_string($doc, "pages[$i]/TrimBox[3]")
                            );
                            $total_width  = $pdf->pcos_get_number($doc, "pages[$i]/width");
                            $total_height = $pdf->pcos_get_number($doc, "pages[$i]/height");
                            switch ($trimbox_orientation) {
                                case 'tnone':
                                    $use_trimbox_orientation = true;
                                    $width_diff              = $total_width - ($trimbox[2] - $trimbox[0]);
                                    $height_diff             = $total_height - ($trimbox[3] - $trimbox[1]);
                                    $optListTrimbox          = " trimbox={0 0 " . ($trimbox[2] - $trimbox[0]) . " " . ($trimbox[3] - $trimbox[1]) . "}";;
                                    $y_page      = (-1) * $trimbox[1];
                                    $x_page      = -1 * ($trimbox[0]);
                                    $diff_x_name = ($total_width - $trimbox[2]);
                                    $diff_x      = (-1) * ($total_width - $trimbox[2]) + $trimbox[0];
                                    $diff_y      = (-1) * ($height_diff) + $trimbox[1];
                                    break;
                                case 'tuonly':
                                    $use_trimbox_orientation = true;
                                    $width_diff              = $total_width - ($trimbox[2] - $trimbox[0]);
                                    $height_diff             = $total_height - ($trimbox[3]);
                                    $optListTrimbox          = " trimbox={0 0 " . ($trimbox[2] - $trimbox[0]) . " " . ($trimbox[3] - $trimbox[1]) . "}";;
                                    $y_page = (-1) * $trimbox[1];
                                    $x_page = -1 * ($trimbox[0]);
                                    //$diff_y = $height_diff/2;
                                    $diff_x      = (-1) * ($total_width - $trimbox[2]) + $trimbox[0];
                                    $diff_x_name = ($total_width - $trimbox[2]);
                                    $diff_y_name = ($height_diff);
                                    $diff_y      = (-1) * ($height_diff) + $trimbox[1];
                                    break;
                                case 'tbonly':
                                    $use_trimbox_orientation = true;
                                    $width_diff              = $total_width - ($trimbox[2] - $trimbox[0]);
                                    $height_diff             = $total_height - ($trimbox[3]);
                                    $optListTrimbox          = " trimbox={0  " . $trimbox[1] . " " . ($trimbox[2] - $trimbox[0]) . " " . ($trimbox[3]) . "}";;
                                    $y_page      = 0;
                                    $diff_page   = (-1) * $trimbox[1];
                                    $diff_x_name = ($total_width - $trimbox[2]);
                                    $x_page      = -1 * ($trimbox[0]);
                                    $diff_x      = (-1) * ($total_width - $trimbox[2]) + $trimbox[0];
                                    $diff_y      = (-1) * ($height_diff) + $trimbox[1];
                                    break;
                                case 'tlu':
                                    $use_trimbox_orientation = true;
                                    $width_diff              = $total_width - ($trimbox[2]);
                                    $height_diff             = $total_height - ($trimbox[3]);
                                    $optListTrimbox          = " trimbox={" . $trimbox[0] . " 0 " . ($trimbox[2]) . " " . ($trimbox[3] - $trimbox[1]) . "}";;
                                    $y_page      = (-1) * $trimbox[1];
                                    $diff_y_name = ($height_diff);
                                    $diff_x      = (-1) * ($total_width - $trimbox[2]) + $trimbox[0];
                                    $diff_y      = (-1) * ($height_diff) + $trimbox[1];
                                    break;
                                case 'tru':
                                    $use_trimbox_orientation = true;
                                    $width_diff              = $trimbox[0];
                                    $height_diff             = $total_height - ($trimbox[3]);
                                    $optListTrimbox          = " trimbox={0 0 " . ($trimbox[2] - $trimbox[0]) . " " . ($trimbox[3] - $trimbox[1]) . "}";;
                                    $y_page      = (-1) * $trimbox[1];
                                    $x_page      = -1 * ($trimbox[0]);
                                    $diff_x_name = ($total_width - $trimbox[2]);
                                    $diff_y_name = ($height_diff);
                                    $diff_x      = (-1) * ($total_width - $trimbox[2]) + $trimbox[0];
                                    $diff_y      = (-1) * ($height_diff) + $trimbox[1];
                                    break;
                                case 'tl':
                                    $use_trimbox_orientation = true;
                                    $width_diff              = $total_width - ($trimbox[2]);
                                    $height_diff             = $total_height - ($trimbox[3] - $trimbox[1]);
                                    $optListTrimbox          = " trimbox={" . $trimbox[0] . " 0 " . ($trimbox[2]) . " " . ($trimbox[3] - $trimbox[1]) . "}";;
                                    $y_page = (-1) * $trimbox[1];
                                    $x_page = 0;
                                    break;
                                case 'tr':
                                    $use_trimbox_orientation = true;
                                    $width_diff              = ($trimbox[0]);
                                    $height_diff             = $total_height - ($trimbox[3] - $trimbox[1]);
                                    $optListTrimbox          = " trimbox={0 0 " . ($total_width - $trimbox[0]) . " " . ($trimbox[3] - $trimbox[1]) . "}";;
                                    $y_page      = (-1) * $trimbox[1];
                                    $x_page      = (-1) * $trimbox[0];
                                    $diff_x      = (-1) * ($total_width - $trimbox[2]) + $trimbox[0];
                                    $diff_y      = (-1) * ($height_diff) + $trimbox[1];
                                    $diff_x_name = ($total_width - $trimbox[2]);
                                    break;
                                case 'tlb':
                                    /*$use_trimbox_orientation = true;
                                        $width_diff              = $total_width - ( $trimbox[2] );
                                        $height_diff             = $total_height - ( $trimbox[3] );
                                        $optListTrimbox          = " trimbox={" . $trimbox[0] . " " . $trimbox[1] . " " . ( $trimbox[2] ) . " " . ( $trimbox[3] ) . "}";;
                                        $y_page = 0;
                                        $x_page = 0;*/
                                    $use_trimbox_orientation = true;
                                    $width_diff              = $total_width - ($trimbox[2]);
                                    $height_diff             = $total_height - ($trimbox[3] - $trimbox[1]);
                                    $optListTrimbox          = " trimbox={" . $trimbox[0] . " 0 " . ($trimbox[2]) . " " . ($trimbox[3] - $trimbox[1]) . "}";;
                                    $y_page = (-1) * $trimbox[1];
                                    $x_page = 0;
                                    break;
                                case 'trb':
                                    /* $use_trimbox_orientation = true;
                                        $width_diff              = ( $trimbox[0] );
                                        $height_diff             = $total_height - ( $trimbox[3] );
                                        $optListTrimbox          = " trimbox={0 " . $trimbox[1] . " " . ( $trimbox[2] ) . " " . ( $trimbox[3] ) . "}";;
                                        $y_page = 0;
                                        $x_page = ( - 1 ) * $trimbox[0];*/
                                    $use_trimbox_orientation = true;
                                    $width_diff              = ($trimbox[0]);
                                    $height_diff             = $total_height - ($trimbox[3] - $trimbox[1]);
                                    $optListTrimbox          = " trimbox={0 0 " . ($total_width - $trimbox[0]) . " " . ($trimbox[3] - $trimbox[1]) . "}";;
                                    $y_page      = (-1) * $trimbox[1];
                                    $x_page      = (-1) * $trimbox[0];
                                    $diff_x      = (-1) * ($total_width - $trimbox[2]) + $trimbox[0];
                                    $diff_y      = (-1) * ($height_diff) + $trimbox[1];
                                    $diff_x_name = ($total_width - $trimbox[2]);
                                    break;
                                default:
                                    break;
                            }
                        }
                    } else {

                        $page = $pdf->open_pdi_page($doc, $i + 1, "cloneboxes");
                    }
                }
                $width  = $pdf->pcos_get_number($doc, "pages[$i]/width") - $width_diff;
                $height = $pdf->pcos_get_number($doc, "pages[$i]/height") - $height_diff;
                if ($use_pdf_vt_otp) {
                    $pdf->begin_dpart("");
                }
                if (isset($data['isCouponEditor']) && $data['isCouponEditor']) {
                    $pdf->begin_page_ext($width, $height, $optListTrimbox);
                    if ($use_trimbox_orientation) {
                        $pdf->fit_pdi_page($page, $x_page, $y_page, "");
                    } else {
                        $pdf->fit_pdi_page($page, 0.0, 0.0, "cloneboxes");
                    }
                } else {
                    if ($use_pdf_vt_otp && isset($data['defaultrgb']) && $data['defaultrgb']) {
                        $icc = $pdf->load_iccprofile("sRGB", "");
                        $pdf->begin_page_ext($width, $height, "defaultrgb=" . $icc);
                    } else {
                        $pdf->begin_page_ext($width, $height, "");
                    }

                    $pdf->fit_pdi_page($page, 0.0, 0.0, "cloneboxes");
                }
                $blocks = $this->getBlockCount($pdf, $doc, $i, $data, false);
                $blocks = $this->checkEmpty($blocks);
                $this->moveBlocksHor($pdf, $blocks);
                $this->stickWith($blocks);
                if (isset($data['VerticalAllign']) && $data['VerticalAllign']) {
                    $vertCorrection = 0;
                    if (isset($data['VerticalAllignCorrection']) && $data['VerticalAllignCorrection']) {
                        $vertCorrection = $data['VerticalAllignCorrection'];
                    }
                    $this->verticalAllign($height, $blocks, $vertCorrection);
                }
                $anchors         = $this->getAnchors($blocks);
                $alreadyAnchored = array();
                if (is_array($data['new_blocks']) && count($data['new_blocks'])) {
                    if (isset($data['new_blocks'][$i])) {
                        $blocks = array_merge($blocks, $data['new_blocks'][$i]);
                    }
                }
                if ($html5Editor && version_compare($editorVersion, '0.0') && $allow_blockorder) {
                    $blocks = $this->reorderBlocks($blocks, $data);
                }

                //otp layouts
                if (count($pages_pdfs)) {
                    $page_type = isset($pages_pdfs[$i]['type']) ? $pages_pdfs[$i]['type'] : "";
                    if ($page_type == "layout") {
                        $pdf_name = isset($pages_pdfs[$i]['pdf_path']) ? $pages_pdfs[$i]['pdf_path'] : false;
                        if ($pdf_name) {
                            $src_pdf = ROOT_PATH . $this->pdfSearchPath . $pdf_name;
                            if (file_exists($src_pdf)) {
                                $attach = $pdf->open_pdi_document($src_pdf, '');
                                if ($attach) {
                                    $newPage = $pdf->open_pdi_page($attach, 1, '');
                                    if ($newPage) {
                                        $pdf->fit_pdi_page($newPage, 0, 0, "boxsize={" . $width . " " . $height . "} fitmethod=entire");
                                    }
                                }
                            }
                        }
                    }
                }
                // print_r($data);exit;
                if ( ! empty($blocks)) {
                    if (isset($data['isCouponEditor']) && $data['isCouponEditor']) {
                        $this->moveBlocksForCoupon($pdf, $blocks, $data);
                        $this->moveImageForCoupons($pdf, $blocks, $data);
                    }
                    foreach ($blocks as $block) {
                        $options         = '';
                        $optionsTextflow = '';
                        $from_template   = (isset($data[$block['name'] . '_properties']['from_template']) && $data[$block['name'] . '_properties']['from_template'] == 1) ? true : false;
                        $is_textflow     = (isset($block['type']) && strtolower($block['type']) == "textflow") ? true : false;
                        $is_circle       = isset($data[$block['name'] . '_properties']['circletext']) && $data[$block['name'] . '_properties']['circletext'] ? true : false;
                        $addtional_layer = isset($data[$block['name'] . '_properties']['additional_layer']) && $data[$block['name'] . '_properties']['additional_layer'] ? true : false;
                        if (isset($block['custom']['AlignToName']) && $block['custom']['AlignToName'] && isset($data['isCouponEditor']) && $data['isCouponEditor']) {
                            $d = 0;
                            if (isset($x_page)) {
                                $d = $x_page;
                            }
                            if (isset($data['ah_trimbox_move']) && $data['ah_trimbox_move'] && ! $data['html5Editor']) {
                                $options .= " refpoint={" . ((float)($block['x1']) + $d + $diff_x_name) . " " . (float)($block['y1_orig'] - ($block['y2_orig'] - $block['y1_orig']) - $diff_page + $diff_y_name) . "}";
                            } else {
                                $options .= " refpoint={" . ((float)($block['x1']) + $d) . " " . (float)($block['y1_orig'] - ($block['y2_orig'] - $block['y1_orig']) - $diff_page) . "}";
                            }
                        } else {
                            if (isset($data['ah_trimbox_move']) && $data['ah_trimbox_move'] && ($diff_x || $diff_y) && ! $data['html5Editor']) {
                                $options = " refpoint={" . (float)($block['x1_orig'] + $diff_x) . " " . (float)($block['y1_orig'] + $diff_y) . "}" .
                                           " boxsize={" . (float)($block['x2_orig'] - $block['x1_orig']) . " " . (float)($block['y2'] - $block['y1']) . "}";
                            }
                        }
                        if (isset($block['reorder']) && $block['reorder']) {
                            $options = " refpoint={" . (float)($block['x1_orig'] - $block['crop_left']) . " " . (float)($block['y1'] - $block['crop_buttom']) . "}" .
                                       " boxsize={" . (float)($block['x2_orig'] - $block['x1_orig']) . " " . (float)($block['y2'] - $block['y1']) . "}";
                        } else {
                            $movable   = 0;
                            $resizable = 0;
                            $rotatable = 0;

                            if (isset($data[$block['name'] . '_properties']) && isset($data[$block['name'] . '_properties']['movable']) && (int)$data[$block['name'] . '_properties']['movable'] == 1) {
                                $movable = 1;
                            }
                            if (isset($data[$block['name'] . '_properties']) && isset($data[$block['name'] . '_properties']['resizable']) && (int)$data[$block['name'] . '_properties']['resizable'] == 1) {
                                $resizable = 1;
                            }
                            if (isset($data[$block['name'] . '_properties']) && isset($data[$block['name'] . '_properties']['rotatable']) && (int)$data[$block['name'] . '_properties']['rotatable'] == 1) {
                                $rotatable = 1;
                            }

                            if (($movable || $resizable || $rotatable) && ! $from_template) {
                                $options .= " refpoint={" . (float)($data[$block['name'] . '_properties']['left']) . " " . (float)($data[$block['name'] . '_properties']['top']) . "}";
                            };
                            if (($movable || $resizable || $rotatable) && ! ($from_template && $is_textflow)) {
                                if (isset($data[$block['name'] . '_properties']['width'])) {
                                    $options .= " boxsize={" . (float)($data[$block['name'] . '_properties']['width']) . " " . (float)($data[$block['name'] . '_properties']['height']) . "}";
                                }
                            } else {
                                if ($from_template && ! $is_textflow) {
                                    if (isset($data[$block['name'] . '_properties']['width'])) {
                                        $options .= " boxsize={" . (float)($data[$block['name'] . '_properties']['width']) . " " . (float)($data[$block['name'] . '_properties']['height']) . "}";
                                    }
                                }
                            }
                            if ($rotatable) {
                                if ($from_template && $is_textflow) {
                                    $optionsTextflow = " rotate=" . (float)($data[$block['name'] . '_properties']['rotateAngle']);
                                } else {
                                    if ( ! $is_circle && ! $addtional_layer) {
                                        $options .= " rotate=" . (float)($data[$block['name'] . '_properties']['rotateAngle']);
                                    }
                                }
                            }
                        }
                        if (isset($block['custom']['MoveGroupHor']) && $block['custom']['MoveGroupHor']) {
                            $options = " refpoint={" . (float)($block['x1']) . " " . (float)($block['y1']) . "}";
                        }
                        if (isset($block['custom']['StickWith']) && $block['custom']['StickWith']) {
                            $options = " refpoint={" . (float)($block['x1']) . " " . (float)($block['y1']) . "}" . " boxsize={ " . (float)($block['x2_orig'] - $block['x1_orig']) . " " . abs($block['y2'] - $block['y1']) .
                                       "} position={center} fitmethod=meet";;
                        }
                        if (isset($block['custom']['StickChild']) && $block['custom']['StickChild']) {
                            $options = " refpoint={" . (float)($block['x1']) . " " . (float)($block['y1']) . "}" . " boxsize={ " . (float)($block['x2_orig'] - $block['x1_orig']) . " " . abs($block['y2'] - $block['y1']) . "}";
                        }
                        if (isset($data['VerticalAllign']) && $data['VerticalAllign']) {
                            $options = " refpoint={" . (float)($block['x1']) . " " . (float)($block['y1']) . "}" . " boxsize={ " . (float)($block['x2_orig'] - $block['x1_orig']) . " " . abs($block['y2'] - $block['y1']) . "}";
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
                        $text = trim($text);
                        if (isset($data[$block['name'] . '_properties']['deleted']) && (int)$data[$block['name'] . '_properties']['deleted']) {
                            continue;
                        }
                        if ($activateExclude && isset($data[$block['name'] . '_properties']['excluded']) && (int)$data[$block['name'] . '_properties']['excluded']) {
                            continue;
                        }
                        if (isset($data['html5Editor']) && (int)$data['html5Editor']) {

                            /*$text = str_ireplace('</div><div>', "<br>", $text);
							$text = str_ireplace('<div>', "<br>", $text);
							$text = str_ireplace('</div>', "", $text);*/
                            $text = str_ireplace('<p><br></p>', "<br>", $text);
                            $text = str_ireplace('</p><br>', "<br>", $text);
                            $text = str_ireplace('</p>', "<br>", $text);
                            $text = str_ireplace('<p>', "", $text);
                            $text = str_ireplace('&nbsp;', " ", $text);
                            $text = preg_replace('#<br\s*/?>#i', "\n", $text);
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
                                            if ( ! $from_template) {
                                                $options .= ' backgroundcolor={None}';
                                            }
                                            break;
                                        case 'DeviceRGB':
                                            if ($from_template || $is_circle) {
                                                $params_bck['device'] = "rgb";
                                                $params_bck['color']  = $block_prop['backgroundcolor'];
                                            } else {
                                                $options .= ' backgroundcolor={rgb ' . $block_prop['backgroundcolor'] . '}';
                                            }
                                            break;
                                        case 'DeviceCMYK' :
                                            if ($from_template || $is_circle) {
                                                $params_bck['device'] = "cmyk";
                                                $params_bck['color']  = $block_prop['backgroundcolor'];
                                            } else {
                                                $options .= ' backgroundcolor={cmyk ' . $block_prop['backgroundcolor'] . '}';
                                            }
                                            break;
                                        case 'Separation':
                                            if ($from_template || $is_circle) {
                                                $params_bck['color']  = '{' . $block_prop['bordercolor'] . '} ' . $block_prop['bordercolor_tint'];
                                                $params_bck['device'] = "spotname";

                                            } else {
                                                $options .= ' backgroundcolor={spotname {' . $block_prop['backgroundcolor'] . '} ' . $block_prop['bgcolor_tint'];
                                            }

                                            if (stripos($block_prop['backgroundcolor'], 'pantone ') === false && stripos($block_prop['backgroundcolor'], 'hks ') === false) {
                                                if (isset($block_prop['bgseparation_colorspace']) && strlen($block_prop['bgseparation_colorspace'])) {
                                                    if (isset($block_prop['bgseparation_color']) && strlen($block_prop['bgseparation_color'])) {

                                                        switch ($block_prop['bgseparation_colorspace']) {
                                                            case 'DeviceRGB':
                                                                if ($from_template || $is_circle) {
                                                                    $params_bck['color'] .= ' {rgb ' . $block_prop['bgseparation_color'] . '}';
                                                                } else {
                                                                    $options .= ' {rgb ' . $block_prop['bgseparation_color'] . '}';
                                                                }
                                                                break;
                                                            case 'DeviceCMYK' :
                                                                if ($from_template && $is_circle) {
                                                                    $params_bck['color'] .= ' {cmyk ' . $block_prop['bgseparation_color'] . '}';
                                                                } else {
                                                                    $options .= ' {cmyk ' . $block_prop['bgseparation_color'] . '}';
                                                                }
                                                                break;
                                                        }
                                                    }
                                                }
                                            }
                                            if ( ! $from_template || ! $is_circle) {
                                                $options .= ' } ';
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
                                                if ( ! $from_template || $is_circle) {
                                                    $options .= ' bordercolor={None}';
                                                }
                                                break;
                                            case 'DeviceRGB':
                                                if ($from_template || $is_circle) {
                                                    $params_border['color']  = $block_prop['bordercolor'];
                                                    $params_border['device'] = "rgb";
                                                } else {
                                                    $options .= ' bordercolor={rgb ' . $block_prop['bordercolor'] . '}';
                                                }
                                                break;
                                            case 'DeviceCMYK' :
                                                if ($from_template || $is_circle) {
                                                    $params_border['color']  = $block_prop['bordercolor'];
                                                    $params_border['device'] = "cmyk";
                                                } else {
                                                    $options .= ' bordercolor={cmyk ' . $block_prop['bordercolor'] . '}';
                                                }
                                                break;
                                            case 'Separation':
                                                if ($from_template || $is_circle) {
                                                    $params_border['color']  = '{' . $block_prop['bordercolor'] . '} ' . $block_prop['bordercolor_tint'];
                                                    $params_border['device'] = "spotname";

                                                } else {
                                                    $options .= ' bordercolor={spotname {' . $block_prop['bordercolor'] . '} ' . $block_prop['bordercolor_tint'];
                                                }

                                                if (stripos($block_prop['bordercolor'], 'pantone ') === false && stripos($block_prop['bordercolor'], 'hks ') === false) {
                                                    if (isset($block_prop['borderseparation_colorspace']) && strlen($block_prop['borderseparation_colorspace'])) {
                                                        if (isset($block_prop['borderseparation_color']) && strlen($block_prop['borderseparation_color'])) {

                                                            switch ($block_prop['borderseparation_colorspace']) {
                                                                case 'DeviceRGB':
                                                                    if ($from_template || $is_circle) {
                                                                        $params_border['color'] .= ' {rgb ' . $block_prop['borderseparation_color'] . '}';
                                                                    } else {
                                                                        $options .= ' {rgb ' . $block_prop['borderseparation_color'] . '}';
                                                                    }

                                                                    break;
                                                                case 'DeviceCMYK' :
                                                                    if ($from_template || $is_circle) {
                                                                        $params_border['color'] .= ' {cmyk ' . $block_prop['borderseparation_color'] . '}';
                                                                    } else {
                                                                        $options .= ' {cmyk ' . $block_prop['borderseparation_color'] . '}';
                                                                    }
                                                                    break;
                                                            }
                                                        }
                                                    }
                                                }
                                                if ( ! $from_template || ! $is_circle) {
                                                    $options .= ' } ';
                                                }
                                                break;
                                        }
                                    }
                                }
                                if ( ! $from_template) {
                                    $options .= ' linewidth=' . $borderwidth . ' ';
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
                            if(isset($data[$block['name'] . '_properties']['bold']) && isset($data[$block['name'] . '_properties']['italic'])){
                                if ('1' !== $data[$block['name'] . '_properties']['bold'] && '1' != $data[$block['name'] . '_properties']['italic']) {
                                    $fontstyle = 'normal';
                                }
                                if (isset($data[$block['name'] . '_properties']['bold']) && '1' == $data[$block['name'] . '_properties']['bold']) {
                                    $fontstyle = 'bold';
                                }
                                if (isset($data[$block['name'] . '_properties']['italic']) && '1' == $data[$block['name'] . '_properties']['italic']) {
                                    $fontstyle .= 'italic';
                                }
                            }
                            if (strlen($fontstyle) > 0) {
                                $options .= ' fontstyle=' . $fontstyle;
                            }
                            if (isset($data[$block['name'] . '_properties']['text_block_type']) && $data[$block['name'] . '_properties']['text_block_type'] == 'text' && isset($data['html5Editor'])
                                && (int)$data['html5Editor'] ) {
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

                                    $options .= " textpath={path=" . $path_circle . "} " . $position;
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
                                        if ($from_template && $is_textflow) {
                                            $optionsTextflow .= ' verticalalign=' . $verticalalign;
                                        } else {
                                            $options .= ' verticalalign=' . $verticalalign;
                                        }
                                    }
                                }
                                /*fix from fontsConfiguration otp*/
                                if (isset($data['html5Editor']) && (int)$data['html5Editor']) {
                                    if (isset($data[$block['name'] . '_properties']['lastlinedist']) && strlen($data[$block['name'] . '_properties']['lastlinedist'])) {
                                        if ($from_template && $is_textflow) {
                                            $optionsTextflow .= ' lastlinedist=' . $data[$block['name'] . '_properties']['lastlinedist'];
                                        } else {
                                            $options .= ' lastlinedist=' . $data[$block['name'] . '_properties']['lastlinedist'];
                                        }
                                    }
                                    if (isset($data[$block['name'] . '_properties']['firstlinedist']) && strlen($data[$block['name'] . '_properties']['firstlinedist'])) {
                                        if ($from_template && $is_textflow) {
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
                                if(isset($block['isTextflow']) && $block['isTextflow']){
                                    if(strpos($text, '<fontname') === false){
                                        $text    = str_replace('<', '&#x003C;', $text);
                                        $options .= " charref=true ";
                                    }
                                }
                                if (isset($block['custom']['Hyphen']) && $block['custom']['Hyphen']) {
                                    $text = $this->_hyphen($text, $block);
                                }
                                if ($from_template) {
                                    $options .= ' encoding=unicode';
                                    if (isset($data['clipText']) && $data['clipText']) {
                                        $options .= " fitmethod=clip adjustmethod=clip";
                                    } else {
                                        $options .= ' fitmethod=auto ';
                                    }
                                    $pdf->fit_textline($text, (float)($data[$block['name'] . '_properties']['left']), (float)($data[$block['name'] . '_properties']['top']),
                                        $options);
                                } else {
                                    if (isset($data['clipText']) && $data['clipText']) {
                                        $options .= " fitmethod=clip adjustmethod=clip";
                                    }
                                    // logomark functionalities
                                    $anchor = '';
                                    if (isset($data['logomark_kw']) && isset($block['custom']) && isset($block['custom']['AnchorTarget'])) {
                                        if ( ! empty($block['custom']['AnchorTarget'])) {
                                            $anchor = $block['custom']['AnchorTarget'];
                                            if ($pdf->info_matchbox("$anchor", 1, "exists") == 1) {
                                                $y1        = $pdf->info_matchbox("$anchor", 1, "y1");
                                                $bl_height = $block['y2'] - $block['y1'];
                                                $new_y     = ($y1 - $bl_height);
                                                $margin    = 0;
                                                if ( ! empty($block['custom']['AnchorMargin']) && is_numeric($block['custom']['AnchorMargin'])) {
                                                    $margin = $block['custom']['AnchorMargin'];
                                                    $new_y  -= $margin;
                                                } else {
                                                    $new_y -= 3;
                                                }
                                                $options                                  = "refpoint {" . $block['x1'] . " " . $new_y . "}";
                                                $alreadyAnchored[$block['name']]['new_y'] = $new_y;
                                            } else {
                                                if ( ! empty($anchors['positions']) && array_key_exists($anchor, $anchors['positions'])) {
                                                    $blockSize = $block['y2'] - $block['y1'];
                                                    $new_y     = $anchors['positions'][$anchor]['y2'] - $blockSize;
                                                    if ( ! empty($data[$block['name']])) {
                                                        $distanceToMove[$i] = $new_y - $block['y1'];
                                                    }
                                                    $options = "refpoint {" . $block['x1'] . " " . $new_y . "}";
                                                }
                                            }
                                        }
                                    }
                                    if (isset($data['logomark_kw']) && ! empty($distanceToMove[$i]) && isset($block['custom']) && isset($block['custom']['MoveUp']) && $block['custom']['MoveUp']) {
                                        if ( ! array_key_exists($block['name'], $alreadyAnchored)) {
                                            $blockSize = $block['y2'] - $block['y1'];
                                            $moveUpY   = $block['y1'] + $distanceToMove[$i];
                                            $options   = "refpoint {" . $block['x1'] . " " . $moveUpY . "}";
                                        }
                                    }
                                    if (isset($data['logomark_kw']) && isset($block['custom']) && isset($block['custom']['FitText'])) {
                                        if ($block['custom']['FitText']) {
                                            $fsize       = $pdf->pcos_get_string($doc, "pages[$i]/blocks[$j]/fontsize");
                                            $fontname    = $pdf->pcos_get_string($doc, "pages[$i]/blocks[$j]/fontname");
                                            $newFontSize = $this->getFontSizeToFit($pdf, $block, $text, $fsize, $fontname);
                                            if ( ! empty($newFontSize) && is_numeric($newFontSize)) {
                                                $options .= ' fontsize=' . $newFontSize;
                                                $options .= ' fitmethod=auto ';
                                                $options .= 'avoidbreak=true';
                                            }
                                        }
                                    }
                                    if (isset($data['logomark_kw']) && ! empty($anchors['anchors']) && in_array($block['name'], $anchors['anchors'])) {
                                        $options .= " matchbox={name=" . $block['name'] . "}";
                                    }
                                    $pdf->fill_textblock($page, $block['name'], $text, $options);
                                    if (isset($data['logomark_kw']) && isset($block['custom']) && isset($block['custom']['AnchorTarget'])) {
                                        if ( ! empty($block['custom']['AnchorTarget'])) {
                                            $anchor = $block['custom']['AnchorTarget'];
                                            if ($pdf->info_matchbox("$anchor", 1, "exists") == 1) {
                                                $y1        = $pdf->info_matchbox("$anchor", 1, "y1");
                                                $bl_height = $block['y2'] - $block['y1'];
                                                if (array_key_exists($anchor, $alreadyAnchored)) {
                                                    if ( ! empty($alreadyAnchored[$anchor]['new_y'])) {
                                                        $new_y = $alreadyAnchored[$anchor]['new_y'] - $bl_height;
                                                    } else {
                                                        $new_y = $y1 - $bl_height;
                                                    }
                                                }
                                                $margin = 0;
                                                if ( ! empty($block['custom']['AnchorMargin']) && is_numeric($block['custom']['AnchorMargin'])) {
                                                    $margin = $block['custom']['AnchorMargin'];
                                                    $new_y  -= $margin;
                                                } else {
                                                    $new_y -= 3;
                                                }
                                                $options .= " refpoint {" . $block['x1'] . " " . $new_y . "}";
                                            }
                                        }
                                    }
                                }
                                if ($opacity !== false) {
                                    $pdf->restore();
                                }
                                if (isset($params_border) && ! $is_circle) {
                                    if ($params_border['color'] && $params['device']) {
                                        $this->drawTextBlockBorders($pdf, $params_border);
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

                                if ($from_template) {
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
                                } else {
                                    if (isset($data['clipText']) && $data['clipText']) {
                                        $options .= " fitmethod=clip adjustmethod=clip";
                                    }
                                    $pdf->fill_textblock($page, $block['name'], $text, $options);
                                }
                                if ($opacity !== false) {
                                    $pdf->restore();
                                }
                                if (isset($params_border) && ! $is_circle) {
                                    if ($params_border['color']) {
                                        $this->drawTextBlockBorders($pdf, $params_border);
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
                                } elseif (isset($data['local_images'][$block['name']])) {
                                    if (isset($data['uuids'][$data['local_images'][$block['name']]])) {
                                        $filePath   = $data['uuids'][$data['local_images'][$block['name']]];
                                        $tmp        = explode('/', urldecode($filePath));
                                        $image_name = array_pop($tmp);
        
                                        $image_src           = $path . $image_name;
                                        $filesToBeDeleted [] = $image_src;
                                    }
                                    if (isset($data['center_images']) && $data['center_images']) {
                                        $options .= " position={center} fitmethod=meet ";
                                    }
                                } elseif (isset($data['barcode_images'][$block['name']])) {
                                    $image_src = $path . $data['barcode_images'][$block['name']];
                                    $options   .= " position={center} fitmethod=meet ";
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
                                        $block_image_options = array(
                                            'block_name'              => $block['name'],
                                            'block_width'             => isset($data[$block['name'] . '_properties']['block_width']) ? $data[$block['name'] . '_properties']['block_width'] : '',
                                            'block_height'            => isset($data[$block['name'] . '_properties']['block_height']) ? $data[$block['name'] . '_properties']['block_height'] : '',
                                            'original_image_src'      => $image_src,
                                            'cropX'                   => isset($data[$block['name'] . '_properties']['cropX']) ? $data[$block['name'] . '_properties']['cropX'] : 0,
                                            'cropY'                   => isset($data[$block['name'] . '_properties']['cropY']) ? $data[$block['name'] . '_properties']['cropY'] : 0,
                                            'cropW'                   => isset($data[$block['name'] . '_properties']['cropW']) ? $data[$block['name'] . '_properties']['cropW'] : 0,
                                            'cropH'                   => isset($data[$block['name'] . '_properties']['cropH']) ? $data[$block['name'] . '_properties']['cropH'] : 0,
                                            'resizePercentage_width'  => isset($data[$block['name'] . '_properties']['resizePercentage_width']) ? $data[$block['name'] . '_properties']['resizePercentage_width'] : 100,
                                            'resizePercentage_height' => isset($data[$block['name'] . '_properties']['resizePercentage_height']) ? $data[$block['name'] . '_properties']['resizePercentage_height'] : 100,
                                        );
                                        if ((isset($data['pdfLibCropping']) && $data['pdfLibCropping'] == 1) && ! (isset($data[$block['name'] . '_properties']['circle']) && 1 == $data[$block['name'] . '_properties']['circle'])) {
                                            if ( ! ($block_image_options['cropX'] == 0 && $block_image_options['cropY'] == 0 && $block_image_options['cropW'] == 0 && $block_image_options['cropH'] == 0)) {
                                                $size    = getimagesize($image_src);
                                                $c_llx   = $block_image_options['cropX'];
                                                $c_lly   = $size[1] - $block_image_options['cropY'] - $block_image_options['cropH'];
                                                $c_urx   = $block_image_options['cropX'] + $block_image_options['cropW'];
                                                $c_ury   = $size[1] - $block_image_options['cropY'];
                                                $options .= " matchbox={clipping={ $c_llx $c_lly $c_urx $c_ury }} ";
                                            }
                                        } else {
                                            $isIM = false;
                                            if (isset($data[$block['name'] . '_properties']['other_infos'])) {
                                                if (isset($data[$block['name'] . '_properties']['other_infos']['mime']) && in_array($data[$block['name'] . '_properties']['other_infos']['mime'],
                                                        array('image/jpeg'))) {
                                                    $isIM = true;
                                                }
                                            }

                                            if (isset($data['zoomerRemoved']) && $data['zoomerRemoved'] == 1) {
                                                $crop_image_src = $this->cropBlockImageZoomerRemoved($block_image_options);
                                            } else {
                                                if (isset($data[$block['name'] . '_properties']['alternate_zoom']) && isset($data[$block['name'] . '_properties']['alternate_zoom']) == 1) {
                                                    $crop_image_src = $this->cropBlockImageZoomerRemoved($block_image_options);
                                                } elseif ($isIM) {
                                                    $crop_image_src = $this->cropBlockImageIM($block_image_options);
                                                } else {
                                                    $crop_image_src = $this->cropBlockImage($block_image_options);
                                                }
                                            }
                                            if ($crop_image_src) {
                                                $image_src          = $crop_image_src;
                                                $filesToBeDeleted[] = $image_src;
                                            }
                                        }
                                    }

                                    //apply round borders
                                    if (isset($data[$block['name'] . '_properties']['circle']) && '1' == $data[$block['name'] . '_properties']['circle']) {
                                        $block_image_options = array(
                                            'original_image_src' => $image_src
                                        );
                                        $crop_image_src      = $this->roundImageCorners($block_image_options);
                                        if ($crop_image_src) {
                                            $image_src           = $crop_image_src;
                                            $filesToBeDeleted [] = $image_src;
                                        }
                                    }
                                    if (array_key_exists($image_src, $this->loaded_images)) {
                                        $img = $this->loaded_images[$image_src];
                                    } else {
                                        $img                             = $pdf->load_image('auto', $image_src, '');
                                        $this->loaded_images[$image_src] = $img;
                                    }


                                    if ($opacity !== false) {
                                        $pdf->save();
                                        $gstate = $pdf->create_gstate("opacityfill=" . $data[$block['name'] . '_properties']['opacity']);
                                        $pdf->set_gstate($gstate);
                                    }
                                    /* if( isset( $data['isCouponEditor'] ) && $data['isCouponEditor'] && $html5Editor &&  isset( $data[$block['name'] . '_properties']['alternate_zoom'] ) && ( $data[$block['name'] . '_properties']['alternate_zoom'] )) {
                                            $options .= " fitmethod=auto ";
                                        }*/

                                    if ($from_template) {
                                        if ($img) {
                                            $pdf->fit_image($img, (float)($data[$block['name'] . '_properties']['left']), (float)($data[$block['name'] . '_properties']['top']),
                                                $options);
                                        }
                                    } else {
                                        if (isset($data['logomark_kw']) && ! empty($anchors['anchors']) && in_array($block['name'], $anchors['anchors'])) {
                                            $options .= "matchbox={name=" . $block['name'] . "}";
                                        }
                                        $pdf->fill_imageblock($page, $block['name'], $img, $options);
                                    }
                                    if ($opacity !== false) {
                                        $pdf->restore();
                                    }
                                }
                                break;
                            case 'graphics':
                                if (isset($data['image'][$block['name']])) {
									if (isset($data['uuids'][$data['image'][$block['name']]])) {
                                        $filePath   = $data['uuids'][$data['image'][$block['name']]];
										
                                        $tmp        = explode('/', urldecode($filePath));
                                        $image_name = array_pop($tmp);
        
                                        $image_src           = $path . $image_name;
                                        $filesToBeDeleted [] = $image_src;
                                    }
									
                                    if (file_exists($image_src)) {
                                        $filesToBeDeleted[] = $image_src;
                                        $img                = $pdf->load_graphics('auto', $image_src, '');

                                        if ($opacity !== false) {
                                            $pdf->save();
                                            $gstate = $pdf->create_gstate("opacityfill=" . $data[$block['name'] . '_properties']['opacity'] . " opacitystroke=" . $data[$block['name'] . '_properties']['opacity']);
                                            $pdf->set_gstate($gstate);
                                        }
                                        if ($html5Editor) {
                                            $options .= " position={center} fitmethod=meet ";
                                        }
                                        if ($from_template) {

                                            // if( $pdf->info_graphics( $img, "fittingpossible", '' ) == 1 ) {

                                            $pdf->fit_graphics($img, (float)($data[$block['name'] . '_properties']['left']), (float)($data[$block['name'] . '_properties']['top']),
                                                $options);
                                            // }
                                        } else {

                                            $pdf->fill_graphicsblock($page, $block['name'], $img, $options);
                                        }
                                        if ($opacity !== false) {
                                            $pdf->restore();
                                        }
                                    }
                                } elseif (isset($data['local_images'][$block['name']])) {
                                    
                                    if (isset($data['uuids'][$data['local_images'][$block['name']]])) {
                                        $filePath   = $data['uuids'][$data['local_images'][$block['name']]];
                                        $tmp        = explode('/', urldecode($filePath));
                                        $image_name = array_pop($tmp);
                                        $image_src           = $path . $image_name;
                                        $filesToBeDeleted [] = $image_src;
                                     
                                    }
                                    if ($image_src) {
                                        if ($html5Editor) {
                                            $options .= " position={center} fitmethod=meet ";
                                        }
                                        $img = $pdf->load_graphics('auto', $image_src, '');
                                        if ($opacity !== false) {
                                            $pdf->save();
                                            $gstate = $pdf->create_gstate("opacityfill=" . $data[$block['name'] . '_properties']['opacity'] . " opacitystroke=" . $data[$block['name'] . '_properties']['opacity']);
                                            $pdf->set_gstate($gstate);
                                        }
                                        if ($from_template) {
                                            if ($pdf->info_graphics($img, "fittingpossible", '') == 1) {
                                                $pdf->fit_graphics($img, (float)($data[$block['name'] . '_properties']['left']),
                                                    (float)($data[$block['name'] . '_properties']['top']), $options);
                                            }
                                        } else {
                                            $pdf->fill_graphicsblock($page, $block['name'], $img, $options);
                                        }
                                        if ($opacity !== false) {
                                            $pdf->restore();
                                        }
                                    }
                                }
                                break;
                            case 'pdf':
                                if (isset($data['image'][$block['name']])) {
                                    if (isset($data['uuids'][$data['image'][$block['name']]])) {
                                        $filePath   = $data['uuids'][$data['image'][$block['name']]];
                                        $tmp        = explode('/', urldecode($filePath));
                                        $image_name = array_pop($tmp);
    
                                        $image_src           = $path . $image_name;
                                        $filesToBeDeleted [] = $image_src;
                                        if (isset($data[$block['name'] . '_properties']['auto_position']) && $data[$block['name'] . '_properties']['auto_position'] == 1) {
                                            $position0 = '50';
                                            $position1 = '50';
                                            $options   .= ' position={' . $position0 . ' ' . $position1 . '}';
                                        }
                                    }
                                    if (file_exists($image_src)) {
                                        $attach             = $pdf->open_pdi_document($image_src, '');
                                        if ($attach) {
                                            $newPage = $pdf->open_pdi_page($attach, 1, '');
                                            if ($newPage) {
                                                if ($from_template) {
                                                    $options .= "  position={center} fitmethod=meet";
                                                    $pdf->fit_pdi_page($newPage, (float)($data[$block['name'] . '_properties']['left']),
                                                        (float)($data[$block['name'] . '_properties']['top']), $options);
                                                } else {
                                                    $pdf->fill_pdfblock($page, $block['name'], $newPage, $options);
                                                }
                                            }
                                        }
                                    }
                                } elseif (isset($data['local_images'][$block['name']])) {
                                    if (isset($data['uuids'][$data['local_images'][$block['name']]])) {
                                        $filePath   = $data['uuids'][$data['local_images'][$block['name']]];
                                        $tmp        = explode('/', urldecode($filePath));
                                        $image_name = array_pop($tmp);
        
                                        $image_src           = $path . $image_name;
                                        $filesToBeDeleted [] = $image_src;
                                    }
                                    if (file_exists($image_src)) {
                                        $attach = $pdf->open_pdi_document($image_src, '');
                                        if ($attach) {
                                            $current_page = 1;
                                            if ($addtional_layer) {
                                                $current_page = $i + 1;
                                            }
                                            $newPage = $pdf->open_pdi_page($attach, $current_page, '');
                                            if ($newPage) {
                                                $pdf->fill_pdfblock($page, $block['name'], $newPage, $options);
                                            }
                                        }
                                    }
                                }
                                break;
                        } //end switch
                    } //end foreach
                } //end if
                if ($watermark) {
                    $this->_addWatermark($pdf, $data, $width, $height);
                }

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
                $pdf->close_pdi_page($page);

            }
        }
        //end pages for

        if ($watermark) {
            $this->deleteAllFiles($filesToBeDeleted);
        } else {
            if (( ! isset($data['watermark']) || ! $data['watermark'])) {
                $this->deleteAllFiles($filesToBeDeleted);
            }
        }
        if ($use_pdf_vt_otp) {
            $pdf->end_dpart("");
        }
        $this->loaded_images = array();
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

    protected function generateLivePreview($image, $data)
    {
        $img = $image->getVariables();
        if (isset($img['result']['image']) && ! empty($img['result']['image'])) {

            $written = file_put_contents(ROOT_PATH . '/data/tmp/temp.png', base64_decode($img['result']['image']));
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
                unlink(ROOT_PATH . '/data/tmp/temp.png');
                if ($response->successful()) {

                    $res = json_decode($response->body());
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
                exec(escapeshellcmd('convert ' . $source . ' -color-matrix   "0.393 0.769 0.189 0.349 0.686 0.168 0.272 0.534 0.131" ' . $effect_filename)); //sepia
                break;
            case 'brownie':
                exec(escapeshellcmd('convert ' . $source . ' -color-matrix   "0.5997023498159715 0.34553243048391263 -0.2708298674538042 -0.037703249837783157 0.8609577587992641 0.15059552388459913 0.24113635128153335 -0.07441037908422492 0.44972182064877153"  -channel R -fx "r+0.18600756296" -channel G -fx "g-0.1449741764" -channel B -fx "b-0.02965519716" ' . $effect_filename)); //sepia
                break;
            case 'technicolor':
                exec(escapeshellcmd('convert ' . $source . ' -color-matrix   "1.9125277891456083 -0.8545344976951645 -0.09155508482755585 -0.3087833385928097 1.7658908555458428 -0.10601743074722245 -0.231103377548616 -0.7501899197440212 1.847597816108189"  -channel R -fx "r+0.04624942523" -channel G -fx "g-0.27589039848" -channel B -fx "b+0.1213762387" ' . $effect_filename)); //sepia
                break;
            case 'kodachrome':
                exec(escapeshellcmd('convert ' . $source . ' -color-matrix   "1.1285582396593525 -0.3967382283601348 -0.03992559172921793 -0.16404339962244616 1.0835251566291304 -0.05498805115633132 -0.16786010706155763 -0.5603416277695248 1.6014850761964943"  -channel R -fx "r+0.24991995145" -channel G -fx "g+0.09698983488" -channel B -fx "b+0.13972481597" ' . $effect_filename)); //sepia
                break;
            case 'vintagePinhole':
                exec(escapeshellcmd('convert ' . $source . ' -color-matrix   "0.6279345635605994 0.3202183420819367 -0.03965408211312453  0.02578397704808868 0.6441188644374771 0.03259127616149294 0.0466055556782719 -0.0851232987247891 0.5241648018700465"  -channel R -fx "r+0.03784817974" -channel G -fx "g+0.02926599677" -channel B -fx "b+0.02023211995" ' . $effect_filename)); //sepia
                break;
            case 'Invert':
                //exec('convert '.$source.' -negate '.$effect_filename);
                $effect_filename = $this->effectImage(array(
                    'original_image_src' => $options['original_file_src'],
                    'effect'             => 'invert'
                ));
                break;
            default:
                break;
        }


        return $effect_filename;

    }

    private function setBrighnessContrast($block_property, $image_src)
    {
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

    private function drawBackgroundRectangle($p, $block)
    {
        $device = 'cmyk';
        $color  = explode(" ", $block['backgroundColorOptions']['originalcolor']);

        if (isset($block['backgroundColorOptions']['code']) && $block['backgroundColorOptions']['code']) {

            $spot = $p->makespotcolor($block['backgroundColorOptions']['code']);
            $p->setcolor("fill", "spot", $spot, 1.0, 0, 0);

        } else {
            $p->setcolor("fill", $device, $color[0], $color[1], $color[2], $color[3]);

        }

        $generalOptions = '';

        $rotateAngle = 0;
        $tetha       = deg2rad($rotateAngle);

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

    public function diecutPackingGeneral($data,$pageData, &$pdf)
    {
        if (isset($pageData['diecut_packing_general']) && $pageData['diecut_packing_general']) {
            $svgPdf = tempnam("/tmp", "SVG");
            file_put_contents($svgPdf, $pageData['diecut_packing_general']);

            $client       = (isset($data['client']) && $data['client'] != '' && array_key_exists($data['client'], $this->packingDiecutColors)) ? $data['client'] : 'default';
            $diecutColors = $this->packingDiecutColors[$client];

            preg_match('/(bleedColor)=["\']?((?:.(?!["\']?\s+(?:\S+)=|["\']))+.)["\']?/is', $pageData['diecut_packing_general'], $bleedColor);

            if ( ! (is_array($bleedColor) && count($bleedColor))) {
                $bleedColor = false;
            }

            $optlist = "boxsize={ " . $pageData['page_width'] . " " . $pageData['page_height'] .
                       "} position={center} fitmethod=entire ";

            $graphics = $pdf->load_graphics("auto", $svgPdf, "");

            if ($client === 'colordruck') {
                $gstate = $pdf->create_gstate("overprintstroke=true overprintfill=true overprintmode=1");
                $pdf->save();
                $pdf->set_gstate($gstate);
            }

            foreach ($diecutColors as $cType => $cValue) {
                if (strpos($pageData['diecut_packing_general'], $cType) !== false) {
                    $pdf->setcolor("fillstroke", "cmyk", $cValue[0], $cValue[1], $cValue[2], $cValue[3]);
                    $spot = $pdf->makespotcolor($cType);
                }
            }

            if (strpos($pageData['diecut_packing_general'], 'DieCutBleed') !== false ) {
                $rgbTmp = str_replace(array('rgb(', ')'), '', (string)$bleedColor[2]);
                $rgbTmp = explode(',', $rgbTmp);
                if (isset($rgbTmp[0]) && isset($rgbTmp[1]) && isset($rgbTmp[2])) {
                    $pdf->setcolor("fillstroke", "rgb", $rgbTmp[0] / 255, $rgbTmp[1] / 255, $rgbTmp[2] / 255, 0);
                    $spot = $pdf->makespotcolor("DieCutBleed");
                }

            }

            if ($pdf->info_graphics($graphics, "fittingpossible", '') == 1) {
                $pdf->fit_graphics($graphics, 0, 0, $optlist);
            } else {
                print_r($pdf->get_errmsg());
            }
            if ($client === 'colordruck') {
                $pdf->restore();
            }
        }
    }

    public function diecutPackingRosendahls($pageData, &$pdf)
    {
        if (isset($pageData['diecut_packing_rosendahls']) && $pageData['diecut_packing_rosendahls']) {
            $svgPdf = tempnam("/tmp", "SVG");
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

    public function draw_corner($p, $angle, $x, $y, $crop_mark)
    {
        $p->save();
        $p->translate($x, $y);
        $p->rotate($angle);
        $p->draw_path($crop_mark, 0, 0, "fill stroke");
        $p->restore();
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
                        'isTextflow'  => $isTextflow,
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

    private function checkEmptyPdfVt($blocks, $data)
    {
        if (is_array($blocks) && count($blocks)) {
            $blocksLength = count($blocks);
            for ($i = 0; $i < $blocksLength; $i++) {
                $value = $blocks[$i];
                if ( ! strlen($data[$blocks[$i]['name']])) {
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


    //move blocks based on hidden blocks

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

    //check blocks that are empty and need to be removed from page

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

    private function verticalAllign($pageheight, &$blocks, $vertCorrection)
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
                $blocks[$i]['y1'] += $correction + $vertCorrection;
                $blocks[$i]['y2'] += $correction + $vertCorrection;
            }
        }
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

    private function drawTextBlockBorders($p, $params)
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
            $ax   = $block_prop['left'];
            $ay   = $block_prop['top'];
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
					$data['uuids'][$data['image'][$block['name']]] = $file_name;
                }
            }
        }
    }

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

    private function getPersonalizationFile($text, $file_name, $id)
    {
        $img_r = file_get_contents('http://api.imagepersonalization.com?set=' . $id . '&t=' . urlencode($text) . '&a=24C1F057A8877348E1C3C5C9AE3638F0');
        file_put_contents(ROOT_PATH . '/data/pdfs/tmp/' . $file_name, $img_r);
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

    private function getBarCode($type, $text, $file_name = 'test.jpeg')
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
        $rendererOptions = array();
        $imageResource   = \Zend\Barcode\Barcode::draw(
            $type, 'image', $barcodeOptions, $rendererOptions
        );

        $tmp_folder = ROOT_PATH . '/data/pdfs/tmp/' . $file_name;
        imagejpeg($imageResource, $tmp_folder, 90);

        return true;
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

    private function resizeDraftDown($src, $img, $w, $h, $nw, $nh)
    {
        list($dest, $alphacolor) = $this->createDraftImage($src, $w, $h);
        imagecopyresampled($dest, $img, 0, 0, 0, 0, $w, $h, $nw, $nh);
        imagedestroy($src);
        imagedestroy($img);

        return $dest;
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


                if ($pdfx4_schwar) {
                    $this->startPdfSchwarDownloadPdf(false, $pdf3, $doc, false, true, true, false, false);
                } else {
                    $this->startPdf(false, $pdf3, $doc, false, false, true, false, false);
                }

                $this->createPdfPackaging($data, $pdf3);

                $pdf3->end_document("");

                $buf3 = $pdf3->get_buffer();

                if (file_put_contents(ROOT_PATH . $this->pdfResultFolder . "$data[file]_packaging.pdf", $buf3)) {
                    ob_end_clean();

                    return response()->json(array(
                        'result' => array('file' => $data['file'] . '_packaging.pdf'),
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

    public function createhelperpdfAction()
    {
        ob_start();
        $data            = $_POST;
        $error           = '';
        $filesToDelete   = array();
        $path            = ROOT_PATH . $this->helperPdfFolder;
        $resultPath      = ROOT_PATH . $this->helperPdfResultFolder;
        $fileName        = $data['file'] . '.pdf';
        $pdfPath         = $path . $fileName;
        $filesToDelete[] = $pdfPath;

        try {
            if (isset($data['isPackaging']) && $data['isPackaging']) {

                $this->uploadFiles($path);

                $this->startPdf($pdfPath, $pdf3, $doc, true, false, false, false, false);

                $this->createPdfPackaging($data, $pdf3, $doc, 1);

                $pdf3->end_document("");

                $buf3 = $pdf3->get_buffer();

                $this->deleteAllFiles($filesToDelete);

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

    public function createPdfPackaging_old($data, $pdf)
    {


        $svg = tempnam("/tmp", "SVG");


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

    public function replace_callback($matches)
    {
        $middle = isset($this->csv_block_values[$matches[3]][$this->current_line]) ? $this->csv_block_values[$matches[3]][$this->current_line] : '%' . $matches[3] . '%';
        return $matches[1] . $middle . $matches[4];
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
}
