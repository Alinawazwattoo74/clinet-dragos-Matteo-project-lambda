<?php

namespace Printq\Rest\Http\Controllers;

use Illuminate\Http\Request;

class RestRearrangeController extends BaseController
{
    protected $pdfSearchPath = '/data/pdfs/';
    protected $pdfResultFolder = '/data/result/';
    protected $time = 0;
    protected $globaltime = 0;

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

    public function create(Request $request)
    {
        $data = $request->all();
		try {
			 if (!empty($_FILES)) {
				$tmp_name = $_FILES['rearrange']['tmp_name'];
				$name = $_FILES['rearrange']['name'];
				$path = ROOT_PATH . $this->pdfSearchPath;
				$result = move_uploaded_file($tmp_name, $path . $name);
				if ($result) {
					$_order = array();
					
					parse_str($data["order"], $_order);
					
					$pdf = $this->arrangePdfPages($path.$name,$_order);
					readfile($pdf);
				} else {
					header($_SERVER["SERVER_PROTOCOL"] . " 404 Not Found");
				}

			} else {
				header($_SERVER["SERVER_PROTOCOL"] . " 404 Not Found");
			}
		}catch(\Exception $e){
			header($_SERVER['SERVER_PROTOCOL'] . " 500 ".$e->getMessage());
		}
        die();

    }
	public function arrangePdfPages($infile,$_order = false){
		if($_order)
		{
			try {
				$p = $this->_startPdf();

				
				$indoc = $p->open_pdi_document($infile, "");
				if ($indoc == 0)
					throw new Exception("Error: " . $p->get_errmsg());

				$page_count = (int) $p->pcos_get_number($indoc, "length:pages");
				
				foreach ($_order as $key => $page) {
					/* Dummy page size; will be adjusted later */
					$p->begin_page_ext(10, 10, "");

					$pagehdl = $p->open_pdi_page($indoc, $page, "");
					if ($pagehdl == 0)
						throw new Exception("Error opening page: " . $p->get_errmsg());

					/*
					 * Place the imported page on the output page, and adjust
					 * the page size
					 */
					$p->fit_pdi_page($pagehdl, 0, 0, "adjustpage");
					$p->close_pdi_page($pagehdl);

					$p->end_page_ext("");
				}

					$p->end_document("");

					$buf = $p->get_buffer();
					
					file_put_contents($infile,print_r($buf,true));

				$p->close_pdi_document($indoc);
			}
			catch (PDFlibException $e) {
				die("PDFlib exception occurred:\n" .
					"[" . $e->get_errnum() . "] " . $e->get_apiname() . ": " .
					$e->get_errmsg() . "\n");
			}
			catch (Exception $e) {
				die($e);
			}

			$p = 0;
			return $infile;
		}
	}
	public function _startPdf()
    {
        $pdf = new \PDFlib();
		if (config('rest.pdf_license_key')) {
        	$pdf->set_option("license=" . config('rest.pdf_license_key'));
		}

        $pdf->set_option("errorpolicy=return");
        $pdf->set_option("stringformat=utf8");
        $pdf->set_option("searchpath={" . ROOT_PATH . $this->pdfSearchPath . "}");

        if (!$pdf->begin_document('', '')) {
            throw new \Exception('Error: ' . $pdf->get_errmsg());
        }

        return $pdf;
    }
    public function update($id, $data)
    {
        # code...
    }

    public function delete($id)
    {
        # code...
    }
}