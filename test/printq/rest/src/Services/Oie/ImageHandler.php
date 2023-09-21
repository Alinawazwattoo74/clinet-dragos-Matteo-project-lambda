<?php

namespace Printq\Rest\Services\Oie;

class ImageHandler
{

	protected $config = array(
		'path'            => "",
		'cropX'           => 0,
		'cropY'           => 0,
		'cropH'           => 0,
		'cropW'           => 0,
		'x'               => 0,
		'y'               => 0,
		'type'            => "normal",
		'circle_distance' => 0,
		'rotate_angle'    => 0,
		'width'           => 0,
		'height'          => 0,
		'flipX'          => 0 ,
		'isPdf'          => 0 ,
	);

	protected $pdf_width;
	protected $pdf_height;
	protected $pdfImage;

	protected $pdf;

	function __construct( \PDFlib $pdf, $width = 0, $height = 0, $config = array() ) {
		$this->pdf = $pdf;
		foreach ( $config as $key => $value ) {
			$this->$key = $value;
		}

		$this->pdf_width  = $width;
		$this->pdf_height = $height;

	}

	public function initConfig($config = array() ) {
		foreach ( $config as $key => $value ) {
			$this->$key = $value;
		}

	}

	public function __set( $name, $value ) {
		if ( isset( $this->config[ $name ] ) ) {
			$this->config[ $name ] = $value;
		}
	}

	public function __get( $name ) {
		if ( isset( $this->config[ $name ] ) ) {
			return $this->config[ $name ];
		}
	}

	public function placeImage() {
		try {
			if ( ! $this->path ) {
				throw new \Exception( "Error Create PDF: path of image is not specified!" );
			}
			$img_src = $this->path ;
			if ( ! file_exists( $img_src ) ) {
				throw new \Exception( "Error Create PDF: Image doesn't exists!" );
			}
			if($this->isPdf){
				$this->parsePdf($img_src);
			}else {
				$this->parse($img_src);
			}

			switch ( $this->type ) {
				case 'normal':
					$scale = "1 1";;
					if($this->flipX){
						$scale = "-1 1";
						$size = getimagesize($img_src);
						$this->cropX = $size[0] - ($this->cropX+$this->cropW);
					}
					$this->cropImage( $img_src , $scale);
					break;
				case "circle":
					$this->roundedImage( $img_src );
					break;
				case "bottom":
					$scale = "1 -1";
					$this->cropImage( $img_src, $scale );
					break;
					case "apo":
					$scale = "-1 -1";
					$this->cropImage( $img_src, $scale );
					break;
				case "left":
					$scale = "-1 1";
					$this->cropImage( $img_src, $scale );
					break;
				case "top":
					$scale = "1 -1";
					$this->cropImage( $img_src, $scale );
					break;
				case "right":
					$scale = "-1 1";
					$this->cropImage( $img_src, $scale );
					break;
				case "horizontal":
					break;
				default :
					break;
			}
		}
		catch ( \Exception $e ) {
			throw new \Exception($e->getMessage() );
		}
		catch ( \PDFlibException $e ) {
			throw new \Exception($e->getMessage() );
		}

		return $this->pdf;
	}

	public function parse( $img_src ) {
		$size = getimagesize( $img_src );
		$this->cropY = $size[1] - $this->cropY - $this->cropH;
		$this->y     = $this->pdf_height - ( $this->y ) - $this->height;
	}
	public function parsePdf( $img_src ) {
		$attach = $this->pdf->open_pdi_document($img_src, '');
		$size = array(
			$this->pdf->pcos_get_number($attach, "pages[0]/width"),
			$this->pdf->pcos_get_number($attach, "pages[0]/height")
		);
		$this->cropY = $size[1] - $this->cropY - $this->cropH;
		$this->y = $this->pdf_height - ($this->y) - $this->height;
	}

	public function cropImage( $img_src, $scale = "1" ) {
		//$size    = getimagesize( $img_src );

		$options = "";
		if(!$this->pdfImage) {
			if ($this->isPdf) {
				$attach = $this->pdf->open_pdi_document($img_src, '');
				if ($attach) {
					$this->pdfImage = $this->pdf->open_pdi_page($attach, 1, '');
				}
			} else {
				$this->pdfImage = $this->pdf->load_image("auto", $img_src, "");
			}
		}

		$llx     = $this->cropX;
		$lly     = $this->cropY;
		$urx     = ( $this->cropX + $this->cropW );
		$ury     = ( $this->cropY + $this->cropH );
		$width   = $this->width;
		$height  = $this->height;

		switch ((int) $this->rotate_angle ){
			case 90:
				$orientate = 'east';
				break;
			case 180:
				$orientate = 'south';
				break;
			case 270:
				$orientate = 'west';
				break;
			default:
				$orientate = 'north';
				break;
		}
		$options .= " boxsize={  $width $height } fitmethod=entire matchbox={clipping={ $llx $lly $urx $ury }} scale= { $scale } orientate=$orientate ";
		if($this->isPdf){
			if ($this->pdfImage) {
				$this->pdf->fit_pdi_page($this->pdfImage, $this->x, $this->y, $options);
			}
		}else {
			$this->pdf->fit_image($this->pdfImage, $this->x, $this->y, $options);
		}
	}

	public function roundedImage( $img_src ) {
		if ( $this->width == $this->height ) {
				$this->width = $this->width;
				$this->height = $this->height;
			$radius = ( $this->width ) / 2 - $this->circle_distance;
			//start_point
			$this->pdf->moveto( $this->x + $radius, $this->y + $this->circle_distance );
			///right lower corner
			$this->pdf->lineto( $this->x + $this->width - $radius - $this->circle_distance, $this->y + $this->circle_distance );
			$this->pdf->arc( $this->x + $this->width - $radius - $this->circle_distance, $this->y + $radius + $this->circle_distance, $radius, 270, 360 );
			//right  top corner
			$this->pdf->lineto( $this->x + $this->width - $this->circle_distance, $this->y + $this->height - $radius - $this->circle_distance );
			$this->pdf->arc( $this->x + $this->width - $radius - $this->circle_distance, $this->y + $this->height - $radius - $this->circle_distance, $radius, 0, 90 );
			//left top corner
			$this->pdf->lineto( $this->x - $radius - $this->circle_distance, $this->y + $this->height - $this->circle_distance );
			$this->pdf->arc( $this->x + $radius + $this->circle_distance, $this->y + $this->height - $radius - $this->circle_distance, $radius, 90, 180 );
			//left lowe corner
			$this->pdf->lineto( $this->x + $this->circle_distance, $this->y + $radius + $this->circle_distance );
			$this->pdf->arc( $this->x + $radius + $this->circle_distance, $this->y + $radius + $this->circle_distance, $radius, 180, 270 );
			/* Set clipping path to defined path */
			$this->pdf->clip();
			$this->cropImage( $img_src );
		} else {
			throw new \Exception ( "Error Create PDF: width an height must be equal in order to obtain a circle!" );
		}
	}
}
