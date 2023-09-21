<?php

namespace Printq\Rest\Http\Controllers;

use Printq\Rest\Services\Oie\PdfHandler;

class RestOieController extends BaseController
{
    protected $pdfSearchPath = '/data/pdfs/';

    protected $pdfResultFolder = '/data/result/';

    protected $time = 0;

    protected $globaltime = 0;

    public function getList() {
        exit;
    }

    public function get( $file ) {
        $f = ROOT_PATH . $this->pdfResultFolder . $file;

        if( file_exists( $f ) ) {
            return response()->json( array(
                                    'data' => base64_encode( file_get_contents( $f ) ),
                                ) );
        }

        return response()->json( array(
                                'data' => false,
                            ) );
    }

    public function create( $data ) {
        #code
    }

    public function oiepreviewAction() {
        $path = ROOT_PATH . '/data/pdfs/tmp/';

        try {

            $this->uploadFiles( $path );

            $data                = $_POST;
            $data['dir']         = $path;
            $data['images'] =  $data['images'];
            $data['destination'] = ROOT_PATH . '/data/result/';
            $data['destination_base'] = ROOT_PATH . '/data/result/';
            $pdfClass            = new PdfHandler( $data );
            $result              = $pdfClass->process();
            $generatedFile       = $result['data']['destination'];
            $this->deleteFiles($data['uuids'],$path);
            if( file_exists( $generatedFile ) ) {
                header( "Cache-Control: public" );
                header( "Content-Description: File Transfer" );
                header( "Content-Disposition: attachment; filename=" . $result['data']['filename'] );
                header( "Content-Type: application/pdf" );
                header( "Content-Transfer-Encoding: binary" );
                readfile( $generatedFile );
            }
            else {
                header( $_SERVER["SERVER_PROTOCOL"] . " 404 Not Found" );
            }
        } catch( \Exception $e ) {
            header( $_SERVER['SERVER_PROTOCOL'] . " 500 " . $e->getMessage() );
        } catch( \PDFlibException $e ) {
            header( $_SERVER['SERVER_PROTOCOL'] . " 500 " . $e->getMessage() );
        }
        exit;
    }

    public function update( $id, $data ) {
        # code...
    }

    public function delete( $id ) {
        # code...
    }

    protected function uploadFiles( $path ) {
        if( $_FILES ) {
            foreach( $_FILES as $up_file ) {
                $tmp_name = $up_file['tmp_name'];
                $name     = $up_file['name'];
                move_uploaded_file( $tmp_name, $path . $name );
            }
        }
    }

    protected function deleteFiles($images, $path)
    {
        if (is_array($images) && count($images)) {
            foreach ($images as $k => $filePath) {
                $tmp        = explode('/', urldecode($filePath));
                $image_name = array_pop($tmp);
                $image_src  = $path . $image_name;
                $file       = $image_src;
                if (file_exists($file) && is_file($file)) {
                    unlink($file);
                }
            }
        }
    }
}