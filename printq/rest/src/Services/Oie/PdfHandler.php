<?php

namespace Printq\Rest\Services\Oie;

class PdfHandler
{
	/**
	 * @var mixed
	 */
	protected $color;

	/**
	 * @var mixed
	 */
	protected $color_device;

	/**
	 * @var mixed
	 */
	protected $folder;

	/**
	 * @var mixed
	 */
	protected $height;

	/**
	 * @var array
	 */
	protected $images = array();
	protected $outlets = array();
	protected $cutouts = array();
	protected $trimbox = array();
	protected $corners = array();
    protected $panels    = array();
	protected $block_images = array();
	protected $uuids =array();

	/**
	 * @var mixed
	 */
	protected $license;

	/**
	 * @var mixed
	 */
	protected $pdf;

	/**
	 * @var mixed
	 */
	protected $split;

	/**
	 * @var mixed
	 */
	protected $width;

	protected $dir = '';

	protected $filename = '';
	protected $filename_split = '';

	protected $out = false;

	protected $destination = '';
	protected $destination_base = '';
	protected $device ="cmyk";
	protected $colorStroke ="0 0 1 0";
	protected $loadedImages = array();
	protected $pdfLoadedImages = array();

	/**
	 * @param array $config
	 */
	public function __construct($config = array()) {
	    $this->pdf = new \PDFlib();
		$this->images = isset($config['images']) ?  $config['images'] : array();
		$this->uuids = isset($config['uuids']) ?  $config['uuids'] : array();
		$this->outlets = isset($config['outlets']) ? $config['outlets'] : array();
		$this->corners = isset($config['corners']) ? $config['corners'] : array();
		$this->cutouts = isset($config['cutouts']) ? ($config['cutouts']) : 0 ;
		$this->panels = isset($config['panels']) ? $config['panels'] : array();
		$this->trimbox = isset($config['trimbox']) ? $config['trimbox'] : array('left'=>0,'top'=>0,'bottom'=>0,'right'=>0);
		$this->block_images = isset($config['block_images']) ? $config['block_images'] : array();
		$this->width = $config['width'];
		$this->height = $config['height'];
		$this->color_device = $config['color_device'];
		$this->color = trim($config['color'], '#');
		$this->path = isset($config['path']) ? $config['path'] : '';
		$this->split = isset($config['split']) ? $config['split'] : 0;
		$this->dir = isset($config['dir']) ? $config['dir'] : $this->dir;
		$this->filename = isset($config['filename']) ? $config['filename'] : $this->filename;
		$this->filename_split = isset($config['filename_split']) ? $config['filename_split'] : $this->filename_split;
		$this->out = isset($config['out']) ? $config['out'] : $this->out;
		$this->destination = isset($config['destination']) ? $config['destination'] : $this->destination;
		$this->destination_base = isset($config['destination_base']) ? $config['destination_base'] : $this->destination_base;

        if (!$this->width || !$this->height) {
			throw new \Exception("Error Create PDF: width or height are not specified!");
		}

	}

	public function process() {
		$result = array(
			'success' => 0,
			'data' => [],
		);

		try {

			$this->initPdflib();
			$this->placeImages();
			$this->save();
			//$this->split();
         /*   if(is_array($this->panels)&& count($this->panels) >1){
                $this->splitInPanels();
            }*/
			$result['data'] = [
				'filename' => $this->filename,
				'dir' => $this->dir,
				'destination' => $this->destination,
			];

		} catch (\Exception $e) {
			throw new \Exception($e->getMessage());
		} catch (\PDFlibException $e) {
		    throw new \Exception($e->getMessage());
		}

		return $result;
	}


	public function initPdflib() {
        $this->pdf->set_option("errorpolicy=return");
        $this->pdf->set_option("stringformat=utf8");
        $this->pdf->set_option( "license=L900602-0190B3-144087-GQGR32-LB3VA2" );
    }

	/**
	 * @param  $hexStr
	 * @param  $returnAsString
	 * @param  false             $seperator
	 * @return mixed
	 */
	public function hex2RGB($hexStr, $returnAsString = false, $seperator = ',') {
		$hexStr = preg_replace("/[^0-9A-Fa-f]/", '', $hexStr); // Gets a proper hex string
		$rgbArray = array();
		if (strlen($hexStr) === 6) {
			//If a proper hex code, convert using bitwise operation. No overhead... faster
			$colorVal = hexdec($hexStr);
			$rgbArray[0] = (0xFF & ($colorVal >> 0x10)) / 255;
			$rgbArray[1] = (0xFF & ($colorVal >> 0x8)) / 255;
			$rgbArray[2] = (0xFF & $colorVal) / 255;
		} else if (strlen($hexStr) === 3) {
			//if shorthand notation, need some string manipulations
			$rgbArray[0] = hexdec(str_repeat(substr($hexStr, 0, 1), 2)) / 255;
			$rgbArray[1] = hexdec(str_repeat(substr($hexStr, 1, 1), 2)) / 255;
			$rgbArray[2] = hexdec(str_repeat(substr($hexStr, 2, 1), 2)) / 255;
		} else {
			return false; //Invalid hex color code
		}

		return $returnAsString ? implode($seperator, $rgbArray) : $rgbArray; // returns the rgb string or the associative array
	}

	/**
	 * @param array $images
	 */
	public function placeImages() {

		if ($this->pdf->begin_document('', "") === 0) {
			throw new \Exception($this->pdf->get_errmsg());
		}
		$this->pdf->begin_page_ext($this->width, $this->height, "");
		$this->setBackgroundColor();
            
		if (is_array($this->images) && count($this->images)) {
		    $imgHndl = new ImageHandler( $this->pdf, $this->width, $this->height );
            $path = ROOT_PATH . '/data/pdfs/tmp/';
		    foreach ($this->images as $config_image) {
                if (isset($this->uuids[$config_image['uuid']])) {
                    $filePath            = $this->uuids[$config_image['uuid']];
                    $tmp                 = explode('/', urldecode($filePath));
                    $image_name          = array_pop($tmp);
                    $image_src           = $path . $image_name;
                    $config_image['path'] = $image_src;
                }
				 if(file_exists($config_image['path'])&& !empty($config_image['path'])) {
				     $imgHndl->initConfig($config_image);
				     //$image = new ImageHandler( $this->pdf, $this->width, $this->height, $config_image );
                     $imgHndl->placeImage();
                 }
			}
		}

        if(is_array($this->block_images) && count($this->block_images)){
            foreach($this->block_images as $image){
                $this->placeImageBlock($image);
            }
        }
        $gstate = $this->pdf->create_gstate("overprintstroke=true overprintmode=1");
       $this->pdf->save();
        $this->pdf->set_gstate($gstate);
        $this->pdf->setcolor("fillstroke", $this->device, 0, 0, 1, 0);
        $spot = $this->pdf->makespotcolor("CutContour");
        $this->pdf->setcolor("fillstroke", "spot", $spot, 1, 0, 0);
        $this->pdf->setlinewidth(1);
		if(is_array($this->outlets) && count($this->outlets)){
		    foreach($this->outlets  as $outlet){
                $pathinfo           = pathinfo($outlet['filename']);
                $outlet['filename'] = $pathinfo['filename'] . ".pdf";
                $img_src            = ROOT_PATH . '/data/pdfs/tmp/' . $outlet['filename'];
                $attach             = $this->pdf->open_pdi_document($img_src, '');
                if ($attach) {
                    $img     = $this->pdf->open_pdi_page($attach, 1, '');
                    $width   = $outlet['width'];
                    $height  = $outlet['height'];
                    $options = " boxsize={  $width $height }  position={center} fitmethod=meet";
                    $this->pdf->fit_pdi_page($img, $outlet['left'] , $outlet['top'], $options);;
                }
            }
        }
		if(is_array($this->cutouts) && count($this->cutouts)){
		    foreach ($this->cutouts as $cutout){
		        if($cutout['type'] =="rectangle"){
		            $this->placeRectangle($cutout);
                }
               if($cutout['type'] == "circle"){
                $this->placeCircle($cutout);
               }
            }
        }
		if(is_array($this->corners) && count($this->corners)){
		    foreach ($this->corners as $corner){
		        if(isset($corner['isActive']) && $corner['isActive'] !="false" && $corner['isActive']) {
                    $this->placeCorner($corner);
                }
            }
        }
		$this->pdf->restore();

		$this->pdf->end_page_ext("");
		$this->pdf->end_document("");
	}

	public function  placeCorner ($corner){
	    $ax = 0;
	    $ay = 0;
	    $width = 0;
	    $height = 0;
        $generalOptions = ' linecap=projecting linejoin=miter';
        if($corner['subtype'] == "circle" || $corner['subtype'] == 'circle2'){
            $corner['y'] = $corner['x'];
        }
	    switch($corner['type']){
            case 'left_top':
                $ax = $this->trimbox['left'];
                $ay = $this->height-$this->trimbox['top'];
                $width = $corner['x'];
                $height = $corner['y'];
                if($corner['subtype'] =='circle'  ){
                    if($width) {
                        $this->pdf->arc($ax, $ay, $corner['x'], 270, 360);
                        $this->pdf->stroke();
                    }
                }else{
                    if ($corner['subtype'] == 'circle2') {
                        if ($width) {
                            $this->pdf->arc($ax+$corner['x'], $ay-$corner['y'], $corner['x'], 90, 180);
                            $this->pdf->stroke();
                        }
                    }
                }
                break;
              case 'right_top':
                $ax =($this->width- $this->trimbox['right']);
                $ay = $this->height-$this->trimbox['top'];
                $width = -1*$corner['x'];
                $height = $corner['y'];
                  if ($corner['subtype'] == 'circle') {
                      if ($width) {
                          $this->pdf->arc($ax, $ay, $corner['x'], 180, 270);
                          $this->pdf->stroke();
                      }
                  } else {
                      if ($corner['subtype'] == 'circle2') {
                          if ($width) {
                              $this->pdf->arc($ax - $corner['x'], $ay - $corner['y'], $corner['x'], 0, 90);
                              $this->pdf->stroke();
                          }
                      }
                  }

                break;
                case 'right_bottom':
                    $ax = ($this->width - $this->trimbox['right']);
                    $ay     = $this->trimbox['top'];
                    $width  = -1*$corner['x'];
                    $height = -1 * $corner['y'];
                    if ($corner['subtype'] == 'circle') {
                        if ($width) {
                            $this->pdf->arc($ax, $ay, $corner['x'], 90, 180);
                            $this->pdf->stroke();
                        }
                    } else {
                        if ($corner['subtype'] == 'circle2') {
                            if ($width) {
                                $this->pdf->arc($ax - $corner['x'], $ay + $corner['y'], $corner['x'], 270, 360);
                                $this->pdf->stroke();
                            }
                        }
                    }

                break;
                case 'left_bottom':
                    $ax    = $this->trimbox['left'];
                    $ay    =  $this->trimbox['top'];
                    $width = $corner['x'];
                    $height = -1*$corner['y'];
                    if ($corner['subtype'] == 'circle') {
                        if ($width) {
                            $this->pdf->arc($ax, $ay, $corner['x'], 0, 90);
                            $this->pdf->stroke();
                        }
                    } else {
                        if ($corner['subtype'] == 'circle2') {
                            if ($width) {
                                $this->pdf->arc($ax + $corner['x'], $ay + $corner['y'], $corner['x'], 180, 270);
                                $this->pdf->stroke();
                            }
                        }
                    }

                break;
            default: break;
        }
         $x = $ax+ $width;
        //top line
	    $path = $this->pdf->add_path_point(0, $ax, $ay, "move", $generalOptions);
        $path = $this->pdf->add_path_point($path, $x, $ay, "line", "");
        //left line
        if ($corner['subtype'] != "circle" && $corner['subtype'] != 'circle2') {
            $y    = $ay - $height;
            $path = $this->pdf->add_path_point($path, $ax, $y, "line", '');
        }else{
            $y = $ay - $height;
            $path = $this->pdf->add_path_point($path, $ax, $y, "move", $generalOptions);
        }
        $path = $this->pdf->add_path_point($path, $ax, $ay, "line", '');


        $this->pdf->draw_path($path, 0, 0, "stroke");
    }
    public function placeRectangle($rectangle){
        $generalOptions = ' linecap=projecting linejoin=miter';
        $ax = isset($rectangle['left']) ? $rectangle['left'] : 0;
        $ay = isset($rectangle['top']) ? $rectangle['top']:0;
        $width = isset($rectangle['width']) ? $rectangle['width']:0;
        $height = isset($rectangle['height']) ? $rectangle['height']:0;
        $rotateAngle = isset($rectangle['rotateAngle']) ? $rectangle['rotateAngle']:0;
        $tetha = deg2rad($rotateAngle);
        if($width && $height){
            $x    = $ax + $width * cos($tetha);
            $y    = $ay + $width * sin($tetha);
            $path = $this->pdf->add_path_point(0, $ax, $ay, "move",$generalOptions);
            $path = $this->pdf->add_path_point($path, $x, $y, "line", "");
            /*right line*/
            $path  = $this->pdf->add_path_point($path, $x, $y, "move",$generalOptions);
            $tetha = deg2rad($rotateAngle + 90);
            $x     = $x + $height * cos($tetha);
            $y     = $y + $height * sin($tetha);
            $path  = $this->pdf->add_path_point($path, $x, $y, "line", "");
            /*top line*/
            $path  = $this->pdf->add_path_point($path, $x, $y, "move", $generalOptions);
            $tetha = deg2rad($rotateAngle);
            $x     = $x - $width * cos($tetha);
            $y     = $y - $width * sin($tetha);
            /*left line*/
            $path  = $this->pdf->add_path_point($path, $x, $y, "line", "");
            $tetha = deg2rad($rotateAngle+ 90);
            $x     = $x - $height * cos($tetha);
            $y     = $y - $height * sin($tetha);
            $path  = $this->pdf->add_path_point($path, $x, $y, "line", "");
            $this->pdf->draw_path($path, 0, 0, "stroke");
        }
    }
    public function placeCircle($circle){
        $ax     = isset($circle['left']) ? $circle['left'] : 0;
        $ay     = isset($circle['top']) ? $circle['top'] : 0;
        $width  = isset($circle['width']) ? $circle['width'] : 0;
        $height  = isset($circle['height']) ? $circle['height'] : 0;
        if($width){
            $this->pdf->ellipse($ax+$width/2, $ay+ $height/2, $width/2,$height/2);
            $this->pdf->stroke();
        }
    }
    public function placeImageBlock($image){
        $path = ROOT_PATH . '/data/pdfs/tmp/';

            if (isset($this->uuids[$image['uuid']])) {
                $filePath             = $this->uuids[$image['uuid']];
                $tmp                  = explode('/', urldecode($filePath));
                $image_name           = array_pop($tmp);
                $image_src            = $path . $image_name;
                $image['imagePath'] = $image_src;
            }

	    $pathInfo = pathinfo($image['imagePath']);
        $filePath = $path.$pathInfo['basename'];
        $rotateAngle = $image['rotateAngle'];


        if(!in_array($pathInfo['filename'],$this->loadedImages)){
            $this->loadedImages[] = $pathInfo['filename'];
        }
        if(file_exists($filePath)){
            if(array_key_exists( $pathInfo['filename'] ,$this->pdfLoadedImages)){
                $img = $this->loaded_images[$pathInfo['filename']];
            }else {
                if(isset($image['isPdf']) && $image['isPdf']){
                    $attach = $this->pdf->open_pdi_document($filePath, '');
                    if ($attach) {
                        $img= $this->pdf->open_pdi_page($attach, 1, '');
                    }
                }else {
                    $img = $this->pdf->load_image("auto", $filePath, "");
                }
                $this->loaded_images[$pathInfo['filename']] = $img;
            }
            if($img){
                $llx     = $image['cropX'];
                $lly     = $image['cropY'];
                $urx     = ($image['cropX'] + $image['cropW']);
                $ury     = ($image['cropY'] + $image['cropH']);
                $width   = $image['width'];
                $height  = $image['height'];
                $options = " boxsize={  $width $height } rotate=$rotateAngle fitmethod=entire matchbox={clipping={ $llx $lly $urx $ury }} ";
               if(isset($image['isPdf']) && $image['isPdf']){
                   $this->pdf->fit_pdi_page($img, $image['left'], $image['top'], $options);
               }else {
                   $this->pdf->fit_image($img, $image['left'], $image['top'], $options);
               }
            }
        }
    }
    public function placeHelper($helper){
        $path     = ROOT_PATH . '/data/pdfs/tmp/';
        $pathInfo = pathinfo($helper);
        $filePath = $path . $pathInfo['basename'];
        if(file_exists($filePath)) {
            $graphics = $this->pdf->load_graphics("auto", $filePath, "");
            if ($graphics) {
                $width   = $this->width - ($this->trimbox['top'] + $this->trimbox['bottom']);
                $height  = $this->height - ($this->trimbox['left'] + $this->trimbox['right']);
                $options = " boxsize={  $width $height } position={center}   fitmethod=meet ";
                $this->pdf->fit_graphics($graphics, (float)($this->trimbox['left']), (float)($this->trimbox['bottom']), $options);
            }
        }
    }

    /**
	 * @param $dir
	 * @param null   $filename
	 * @param $out
	 */
	public function save() {
		$buf = $this->pdf->get_buffer();
		 $this->destination = $this->getDestination();
		file_put_contents($this->destination, $buf);
		if (!file_exists($this->destination)) {
			throw new \Exception("Error Save PDF:Couldn't save file on disk!");
		}
	}

	protected function getDestination() {
		if (!strlen($this->destination_base)) {
			throw new \Exception("Error Save PDF:Invalid Dir!");
		}
		if (!is_writable($this->destination_base)) {
			throw new \Exception("Error Save PDF:Destination not writeable!");
		}
		if (!strlen($this->destination_base)) {
			throw new \Exception("Error Save PDF:Invalid Filename!");
		}
		$this->destination = rtrim($this->destination_base, '/') . '/' . $this->filename;

		return $this->destination;

	}
	protected function getDestinationSplit() {
		return rtrim($this->destination_base, '/') . '/' . $this->filename_split;
	}



	public function setBackgroundColor() {
		$colors = explode(",", $this->color);
		switch ($this->color_device) {
		case 'rgb':
			$colors = $this->hex2RGB($this->color);
			if (is_array($colors)) {
				$this->pdf->setcolor("fillstroke", $this->color_device, $colors[0], $colors[1], $colors[2], null);
			}
			break;
		case 'cmyk':
			if (is_array($colors) && count($colors) === 4) {
				$this->pdf->setcolor("fillstroke", $this->color_device, $colors[0], $colors[1], $colors[2], $colors[3]);
			}
			break;
		default:
		}

		if (in_array($this->color_device, array('rgb', 'cmyk'))) {
			$this->pdf->rect(0, 0, $this->width, $this->height);
			$this->pdf->fill();
		}
	}

	public function split() {
	    if (!$this->split) {
			return;
		}

		if (!isset($this->split['lines'])) {
			return;
		}

		if (!isset($this->split['enable'])) {
			return;
		}

		if ((int) $this->split['lines'] == 0) {
			return;
		}

		if ((int) $this->split['enable'] == 0) {
			return;
		}
		$destination = $this->destination;
		if ($this->pdf->begin_document( $this->getDestinationSplit(), "") == 0) {
			throw new \Exception("Error: " . $this->pdf->get_errmsg());
		}

		$indoc = $this->pdf->open_pdi_document($destination, "");
		if ($indoc == 0) {
			throw new \Exception("Error: " . $this->pdf->get_errmsg());
		}

        $pagewidth = $this->pdf->pcos_get_number($indoc, "pages[0]/width");
		$pageheight = $this->pdf->pcos_get_number($indoc, "pages[0]/height");
		$split_times = (int) $this->split['lines'] + 1;
        $left_margin = (float) $this->split['margin']['left'];
        $right_margin = (float) $this->split['margin']['right'];

		$split_width = ($pagewidth - $left_margin - $right_margin) / $split_times;
		$page = $this->pdf->open_pdi_page($indoc, 1, "");

		if ($page == 0) {
			throw new \Exception("Error: " . $this->pdf->get_errmsg());
		}

        $stop = 0;
		$start = 0;
		for ($i = 1; $i <= $split_times; $i++) {
            $start += $stop;
            $stop = $split_width; 
            if ( $i == 1 ){
                $stop += $left_margin;
            }
            if ( $i == $split_times ){
                $stop += $right_margin;
            }

			$this->pdf->begin_page_ext($stop, $pageheight, "");
			$this->pdf->fit_pdi_page($page, -1 * $start, 0, "boxsize={" . $stop . " " . $pageheight . "} fitmethod=nofit");
			$this->pdf->end_page_ext("");
		}        
		$this->pdf->close_pdi_page($page);
		$this->pdf->close_pdi_document($indoc);
        $this->pdf->end_document("");
        $this->destination =$this->getDestinationSplit();
        $this->filename = $this->filename_split;

	}

    public function splitInPanels()
    {
        if(is_array($this->panels) && count($this->panels)){
            $destination = $this->destination;
            if ($this->pdf->begin_document($this->getDestinationSplit(), "") == 0) {
                throw new \Exception("Error: " . $this->pdf->get_errmsg());
            }

            $indoc = $this->pdf->open_pdi_document($destination, "");
            if ($indoc == 0) {
                throw new \Exception("Error: " . $this->pdf->get_errmsg());
            }
            $totalTrimWidth  = $this->trimbox['top'] + $this->trimbox['bottom'];
            $totalTrimHeight = $this->trimbox['left'] + $this->trimbox['right'];
            $start =0;
            $page = $this->pdf->open_pdi_page($indoc, 1, "");
            if ($page == 0) {
                throw new \Exception("Error: " . $this->pdf->get_errmsg());
            }
            foreach ($this->panels as $panel){
                $heightPanel = $panel['height'] + $totalTrimHeight;
                $widthPanel = $panel['width'] +  $totalTrimWidth;
                $this->pdf->begin_page_ext($widthPanel, $heightPanel, "");
                $llx = $start;
                $lly = 0;
                $urx = $start+$widthPanel;
                $ury = $heightPanel;
                $this->pdf->fit_pdi_page($page,  0, 0, "boxsize={" . $widthPanel . " " . $heightPanel . "} matchbox={clipping={ $llx $lly $urx $ury }} fitmethod=nofit");
                $this->pdf->end_page_ext("");
                $start += $panel['width'];
            }
        }
        $this->pdf->close_pdi_page($page);
        $this->pdf->close_pdi_document($indoc);
        $this->pdf->end_document("");
        $this->destination = $this->getDestinationSplit();
        $this->filename    = $this->filename_split;
    }
    protected function deleteFiles()
    {
        $images = $this->loadedImages;
        $path = ROOT_PATH . '/data/pdfs/tmp/';
        if (is_array($images) && count($images)) {
            foreach ($images as $k => $image) {
                $file = $path . $image;
                if (file_exists($file)) {
                    unlink($file);
                }

            }
        }
    }
}
