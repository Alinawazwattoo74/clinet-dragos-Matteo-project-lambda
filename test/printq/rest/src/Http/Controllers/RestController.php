<?php

namespace Printq\Rest\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class RestController extends BaseController
{
    public function getList() {
        exit;
    }

    public function get( $file ) {

        $flip = false;
        if( is_array( $file ) ) {

            // all "refresh" requests come here
            $f = $file['file'];
            if( isset( $file['wtm_file'] ) && !empty($file['wtm_file'] ) ) {
                $wtm_file = $file['wtm_file'];
            }
        $helpers_file = false;

        if (isset($file['helpers_file'])) {
            $helpers_file = $file['helpers_file'];
        }

            $page    = $file['page'];
            $quality = $file['res'];
            if( $file['live'] ) {

                //$quality = 72;
            }
            elseif( $file['flip'] ) {

                $flip = true;
                //$quality = 150;
            }
            $device = $file['file_output'];
        }

        $filename = ROOT_PATH . '/data/result/' . $f;
        $image    = str_replace( '.pdf', '', $filename );

        //return image without watermark
        //if watermark is set, the image will be returned in next method calling
        $files = RestController::outputResult( $filename, $image, $flip, $page, $device, $quality, $file['trim_box'], $file['fit_to_page'], $file );

        if( !isset( $wtm_file )  ) {

            if( isset( $file['make_live_preview'] ) && $file['make_live_preview'] ) {
                $livePreview = RestController::generateLivePreview( $files, $file );
                if( $livePreview ) {
                    return response()->json( array(
                                              'result' => array(
                                                  'image' => $livePreview,
                                                  'pdf'   => $f,
                                              )
                                          ) );
                }
            }
            else {

            $result_data = array(
                                              'image' => $files,
                                              'pdf'   => $f,
            );

            if ($helpers_file) {

                $helpers_filename             = ROOT_PATH . '/data/result/' . $helpers_file;
                $helper_image                 = str_replace('.pdf', '', $helpers_filename);
                $helper_files                 = RestController::outputResult(
                    $helpers_filename, $helpers_file, $flip, $page, $device, $quality, $file['trim_box'],
                    $file['fit_to_page'],
                    $file
                );
                $result_data['image_helpers'] = $helper_files;
                $result_data['pdf_helpers']   = $helpers_file;

            }

            return response()->json(
                [
                    'result' => $result_data
                ]);
            }

        }

        //return 2 images, one with watermark and one without watermark
        if( isset( $wtm_file ) ) {
            $wtm_filename = ROOT_PATH . '/data/result/' . $wtm_file;
            $wtm_image    = str_replace( '.pdf', '', $wtm_filename );

            $wtm_files = RestController::outputResult( $wtm_filename, $wtm_image, $flip, $page, $device, $quality, $file['trim_box'], $file['fit_to_page'], $file );

            if( isset( $file['make_live_preview'] ) && $file['make_live_preview'] ) {
                $livePreview = RestController::generateLivePreview( $files, $file );
                //as we discussed live preview not suppose to work with watermark in preview
                if( $livePreview ) {
                    return response()->json( array(
                                              'result' => array(
                                                  'image'     => $livePreview,
                                                  'pdf'       => $f,
                                                  'wtm_image' => $livePreview,
                                                  'wtm_pdf'   => $wtm_file,
                                              )
                                          ) );
                }
            }
            else {
            $result_data = array(
                                          'image'     => $files,
                                          'pdf'       => $f,
                                          'wtm_image' => $wtm_files,
                                          'wtm_pdf'   => $wtm_file,
            );
            if ($helpers_file) {
                $helpers_filename             = ROOT_PATH . '/data/result/' . $helpers_file;
                $helper_image                 = str_replace('.pdf', '', $helpers_filename);
                $helper_files                 = RestController::outputResult(
                    $helpers_filename, $helpers_file, $flip, $page, $device, $quality, $file['trim_box'],
                    $file['fit_to_page'],
                    $file
                );
                $result_data['image_helpers'] = $helper_files;
                $result_data['pdf_helpers']   = $helpers_file;

            }

            return response()->json(
                [
                    'result' => $result_data
                ]);
            }
        }

        return response()->json( array(
                                  'result' => array( 'error' => 'File ' . $f . ' doesn\'t exist.' )
                              ) );

    }

    public static function generateLivePreview( $image, $data ) {

        if( $image ) {

            $written = file_put_contents( ROOT_PATH . '/data/tmp/temp.png', base64_decode( $image ) );
            if( $written ) {

                $response = Http::attach(
                        'attachment', file_get_contents(ROOT_PATH . '/data/tmp/temp.png'), $data['psd_preview']
                    )->post('http://192.162.84.131/preview/public/preview');
                // $client = new Client( 'http://192.162.84.131/preview/public/preview' );
                // $client->setFileUpload( dirname( __FILE__ ) . '/temp.png', 'toPsd' );
                // $client->setParameterPost( array(
                //                                'file' => $data['psd_preview'],
                //                            ) );
                // $client->setMethod( Request::METHOD_POST );

                // $response = $client->send();
                unlink( ROOT_PATH . '/data/tmp/temp.png' );
                if( $response->successful() ) {

                    $res = json_decode( $response->body() );
                    if( isset( $res->result->success ) && $res->result->success ) {

                        return $res->result->file;


                    }
                }
            }
        }
        return false;
    }

    public static function outputResult($filename, $image, $flip, $pages, $device, $quality, $trimBoxStatus, $fitToPage, $data = [])
    {
        $pttopx          = 1.39;
        $previewMaxWidth = 1600;
        $previewMinWidth = 500;
        $ret             = [];
        $devices         = ['jpeg' => 'png', 'png16m' => 'png', 'pngalpha' => 'png'];
        $deviceCairo     = "-png";
        $useTransparency = "";
        if (isset($devices[$device])) {
            $deviceCairo = "-" . $devices[$device];
            if ($device == "pngalpha") {
                $useTransparency = "-transp";
            }
        }
        if (file_exists($filename)) {
            $p       = new \PDFlib();
            $optlist = "";

            if (!$p->begin_document('', $optlist)) {
                throw new \Exception('Error: ' . $p->get_errmsg());
            }

            $indoc = $p->open_pdi_document($filename, "password=".config('rest.watermark_master_password'));
            if (!$indoc) {
                throw new \Exception("Error: " . $p->get_errmsg());
            }
            $p->set_option("errorpolicy=return");
            $p->set_option("stringformat=utf8");
            $pageCount = $p->pcos_get_number($indoc, "length:pages");
            if (!$flip) {
                $firstpage = $pages;
                $lastpage  = $pages;
            } else {
                $firstpage = 1;
                $lastpage  = $pageCount;
            }
            $oldQuality = $quality;
            $quality = str_replace('#', '', $quality);
            for ($page = $firstpage; $page <= $lastpage; $page ++) {
                $i          = $page - 1;
                $level      = $quality / 100;
            $correctionWidth = 0;
            $correctionHeight = 0;
            if($p->pcos_get_string($indoc, "type:pages[$i]/MediaBox") == 'array'){
                $correctionWidth = abs($p->pcos_get_number($indoc, "pages[$i]/MediaBox[0]"));
                $correctionHeight  = abs($p->pcos_get_number($indoc, "pages[$i]/MediaBox[1]"));
                $pdfPageWidth = abs($p->pcos_get_number($indoc, "pages[$i]/MediaBox[2]")) + $correctionWidth;
                $pdfPageHeight =abs(
                        $p->pcos_get_number($indoc, "pages[$i]/MediaBox[3]"))+$correctionHeight;

            }else{
                $pdfPageWidth = $p->pcos_get_number($indoc, "pages[$i]/width");
                $pdfPageHeight = $p->pcos_get_number($indoc, "pages[$i]/height");
            }
            $pageWidth  = round((float) $pdfPageWidth * $pttopx * $level, 0, PHP_ROUND_HALF_ODD);
            $pageHeight = round((float) $pdfPageHeight * $pttopx * $level, 0, PHP_ROUND_HALF_ODD);
            if (( ! isset($data['live']) || ! $data['live']) && (strpos($oldQuality, '#') === false)) {
                //check if total with > 1600 px
                if (($pageWidth > $previewMaxWidth || $pageHeight > $previewMaxWidth)) {
                    $newQualityW = round(($quality * $previewMaxWidth) / $pageWidth, 0, PHP_ROUND_HALF_ODD);
                    $newQualityH = round(($quality * $previewMaxWidth) / $pageHeight, 0, PHP_ROUND_HALF_ODD);
                    $quality     = min($newQualityW, $newQualityH);
                    $level       = $quality / 100;

                    $pageWidth  = round((float) $pdfPageWidth * $pttopx * $level, 0, PHP_ROUND_HALF_ODD);
                    $pageHeight = round((float) $pdfPageHeight * $pttopx * $level, 0, PHP_ROUND_HALF_ODD);
                }else{
                     if (($pageWidth < $previewMinWidth && $pageHeight < $previewMinWidth)) {
                         $newQualityW = round(($quality * $previewMinWidth) / $pageWidth, 0, PHP_ROUND_HALF_ODD);
                         $newQualityH = round(($quality * $previewMinWidth) / $pageHeight, 0, PHP_ROUND_HALF_ODD);
                         $quality     = min($newQualityW, $newQualityH);
                         $level       = $quality / 100;
                         $pageWidth  = round((float) $pdfPageWidth * $pttopx * $level, 0, PHP_ROUND_HALF_ODD);
                         $pageHeight = round((float) $pdfPageHeight * $pttopx * $level, 0, PHP_ROUND_HALF_ODD);
                     }
                 }
                }
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
                    $x = round(($trimbox[0]) * $pttopx * $level, 0, PHP_ROUND_HALF_ODD);
                    $y = round((($pageHeight - ($trimbox[3] * $pttopx * $level))), 0, PHP_ROUND_HALF_ODD);
                        $pageWidth  = round(($trimbox[2] - $trimbox[0]) * $pttopx * $level, 0, PHP_ROUND_HALF_ODD);
                        $pageHeight = round(($trimbox[3] - $trimbox[1]) * $pttopx * $level, 0, PHP_ROUND_HALF_ODD);

                    }
                    $trimboxSizes = " -x $x -y $y -W $pageWidth -H $pageHeight";
                }

                $imageName       = (($page > 1) || $flip) ? $image . $page : $image;
                $firstpageOption = "-singlefile -f $firstpage";
                $lastpageOption  = "-l $lastpage";
                if ($flip) {
                    $firstpageOption = "-singlefile -f $page";
                    $lastpageOption  = "-l $page";
                }
               // $iccprofile = '-icc ' . __DIR__ . '"/ISOcoated.icc"';
                $iccprofile = '';
                $opts       = [
                    'pdftocairo',
                    '-q',
                    $iccprofile,
                    $firstpageOption,
                    $lastpageOption,
                    $deviceCairo,
                    $trimboxSizes,
                    $useTransparency,
                    "-r $quality",
                    "'" . $filename . "'",
                    "'$imageName'"

                ];
                $exec_res   = exec(escapeshellcmd((string) implode(' ', $opts)), $ret);
                if (isset($data['data']['convert_size']) && !empty($data['data']['convert_size'])) {
                    $geometry = $data['data']['convert_size'];
                    exec(escapeshellcmd("convert  $image.png -geometry $geometry $image.png"));
                }
            }
            if (!empty($ret)) {
                return response()->json(
                    [
                        'result' => [$ret, $exec_res, $opts]
                    ]);
            } else {
                if ($flip) {
                    $files = [];
                    $i     = 1;
                    while (file_exists($image . $i . '.png')) {
                        $files[] = base64_encode(file_get_contents($image . $i . '.png'));
                        @unlink($image . $i . '.png');
                        ++ $i;
                    }
                } else {
                    $files = file_exists($imageName . '.png') ? base64_encode(
                        file_get_contents($imageName . '.png')) : '';
                    @unlink($imageName . '.png');
                }
                return $files;
            }
        }

    }


    public function create( Request $request ) {

        $data = $request->all();
        //if new page preview
        if( isset( $data['getPage'] ) && $data['getPage'] )
            return $this->get( $data );
        //if upload
        if( !empty( $_FILES ) ) {
            if( isset( $data["indesign_fonts"] ) && $data["indesign_fonts"] ) {
                $path = ROOT_PATH . '/data/fonts/';
                foreach ($_FILES as $key => $file){
                    $tmp_name = $file['tmp_name'];
                    $name = $file['name'];
                    move_uploaded_file($tmp_name, $path . $name);
                    $result = true;
                }
            }else{
            $tmp_name = $_FILES['sfdUYG']['tmp_name'];
            $name     = $_FILES['sfdUYG']['name'];
            $path     = ROOT_PATH . '/data/pdfs/';
            $result = move_uploaded_file( $tmp_name, $path . $name );
            }
            return response()->json( array(
                                      'result' => array(
                                          'result' => $result,
                                          'path'   => $path . $name
                                      )
                                  ) );
        }

        return response()->json( array(
                                  'result' => array(
                                      'result' => false,
                                      'path'   => ''
                                  )
                              ) );
    }

    public function update( $id, $data ) {
        # code...
    }

    public function delete( $id ) {
        # code...
    }

    public function getImage($file)
    {
        $flip = false;
        if (is_array($file)) {

            // all "refresh" requests come here
            $f = $file['file'];


            $page = $file['page'];
            $quality = $file['res'];
            if ($file['live']) {

                //$quality = 72;
            } elseif ($file['flip']) {

                $flip = true;
                //$quality = 150;
            }
            $device = $file['file_output'];
        }

        $filename = ROOT_PATH . '/data/result/' . $f;
        $image = str_replace('.pdf', '', $filename);

        //return image without watermark
        //if watermark is set, the image will be returned in next method calling
        $files1 = RestController::outputResult(
            $filename, $image, $flip, $page, $device, $quality, $file['trim_box'], $file['fit_to_page'], $file);

        if (isset($f)) {
            return response()->json(
                [
                    'result' => [
                        'image' => $files1,
                        'pdf' => $f,
                        'wtm_image' => $files1,
                        'wtm_pdf' => $f,
                    ]
                ]);
        }


        return response()->json(
            [
                'result' => ['error' => 'File ' . $f . ' doesn\'t exist.']
            ]);

    }
}