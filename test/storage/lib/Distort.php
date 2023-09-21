<?php

    /**
     * @author marius-turcu
     * Date: 8/10/16
     * Time: 2:31 PM
     */
    class Printq_Distort {

        protected $imageSearchPath    = '/data/pdfs/tmp/';
		
		protected $imageDistortPath   = '/data/pdfs/tmp/distort/';
		
		protected $imageDistortFolder = 'distort/';
		
        protected $fontsSearchPath = '/data/fonts/';

        protected $oneMMToPixel = 3.779528;

        public function createMaskImage( $docW, $docH, $params ) {
            $success = false;
            $result  = null;
            try {
                $image = new \imagick();
                $image->newImage( $docW, $docH, new ImagickPixel( 'transparent' ) );

                $image->setImageVirtualPixelMethod( imagick::VIRTUALPIXELMETHOD_WHITE );
                $image->distortImage( Imagick::DISTORTION_ARC, array(
                    $params['angle'], $params['start_angle'], $params['big_radius'], $params['small_radius']
                ), true );
                $image->writeImage( Mage::getBaseDir() . '/' . $params['mask'] );
                $success = true;
                $result  = $params['mask'];
            } catch( Mage_Exception $e ) {
                $result = $this->__( $e->getMessage() );
            }

            return array(
                'success' => $success,
                'result'  => $result
            );

        }

        public function getNewDimensions( $docW, $docH, $params ) {
            $result = array();
            $image  = new \imagick();
            //create dummy image for find new document width
            $image->newImage( $docW, $docH, new ImagickPixel( 'transparent' ) );

            $image->distortImage( Imagick::DISTORTION_ARC, array(
                $params['angle'], $params['start_angle'], $params['big_radius'], $params['small_radius']
            ), true );

            $result['width']  = $image->getImageWidth();
            $result['height'] = $image->getImageHeight();

            return $result;
        }

        public function getPointCoords( $angle, $hypotenuse, $leftOffset, $topOffset ) {

            $result = array();
            $sign   = - 1;

            if( $angle >= 0 ) {
                $sign = + 1;

            }

            $angle       = abs( $angle );
            $result['x'] = (float)( $leftOffset - (float)( ( sin( deg2rad( $angle ) ) * $hypotenuse ) * $sign ) );

            $result['y']    = (float)( $topOffset - (float)( cos( deg2rad( $angle ) ) * $hypotenuse ) );
            $result['sign'] = $sign;
            return $result;

        }


        public function getPoints(
            $bigAngle, $startAngle, $elementAngle, $smallRadius, $bottomPosition, $elementHeight, $bigRadius, $leftOffset, $filter = array(
                         1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1
                     )
        ) {

            $result = array();

            $origin = $bigAngle / 2;
            if( $filter[0] ) {
                $result[0] = $this->getPointCoords( $origin - $startAngle, $smallRadius + $bottomPosition + $elementHeight, $leftOffset, $bigRadius );
            }
            if( $filter[1] ) {
                $result[1] = $this->getPointCoords( $origin - $startAngle - $elementAngle, $smallRadius + $bottomPosition + $elementHeight, $leftOffset, $bigRadius );
            }
            if( $filter[2] )
                $result[2] = $this->getPointCoords( $origin - $startAngle - $elementAngle, $smallRadius + $bottomPosition, $leftOffset, $bigRadius );
            if( $filter[3] )
                $result[3] = $this->getPointCoords( $origin - $startAngle, $smallRadius + $bottomPosition, $leftOffset, $bigRadius );
            if( $filter[4] ) {


                if( $result[0]['sign'] != $result[1]['sign'] ) {

                    $result[4] = array(
                        'x' => $result[0]['x'], 'y' => $bigRadius - $smallRadius - $bottomPosition - $elementHeight
                    );
                }
                else {
                    $result[4] = $result[0];

                }
            }


            $minX = $result[0]['x'];
            $minY = $result[0]['y'];
            $maxX = $result[0]['x'];
            $maxY = $result[0]['y'];
            for( $i = 1; $i <= 4; $i ++ ) {
                if( $result[$i]['x'] < $minX ) {
                    $minX = $result[$i]['x'];
                }
                if( $result[$i]['x'] > $maxX ) {
                    $maxX = $result[$i]['x'];
                }
                if( $result[$i]['y'] < $minY ) {
                    $minY = $result[$i]['y'];
                }
                if( $result[$i]['y'] > $maxY ) {
                    $maxY = $result[$i]['y'];
                }
            }
            if( $filter[5] )
                //width
                $result[5] = $minX;
            if( $filter[6] )
                //height
                $result[6] = $minY;
            if( $filter[7] )
                //width
                $result[7] = $maxX - $minX;
            if( $filter[8] )
                //height
                $result[8] = $maxY - $minY;
            if( $filter[9] )
                $result[9] = $this->getPointCoords( $bigAngle / 2 - $startAngle - $elementAngle / 2, $smallRadius + $bottomPosition + $elementHeight, $leftOffset, $bigRadius );

            if( $filter[10] )
                $result[10] = $this->getPointCoords( $bigAngle / 2 - $startAngle - $elementAngle / 2, $smallRadius + $bottomPosition, $leftOffset, $bigRadius );


            return $result;
        }

        public function getPath( $startPoint, $endPoint, $maxPoint ) {

            $middlePoint = array();
            $result      = array();

            $middlePoint['x'] = ( $startPoint['x'] + $endPoint['x'] ) / 2;
            $middlePoint['y'] = ( $startPoint['y'] + $endPoint['y'] ) / 2;

            $result['x'] = 2 * $maxPoint['x'] - $middlePoint['x'];
            $result['y'] = 2 * $maxPoint['y'] - $middlePoint['y'];

            return $result;
        }

        public function getPathString( $startPoint, $endPoint, $maxPoint ) {

            $offsetPoint = $this->getPath( $startPoint, $endPoint, $maxPoint );

            $result = 'M' . $startPoint['x'] . ',' . $startPoint['y'] . " Q" . $offsetPoint['x'] . ',' . $offsetPoint['y'] . " " . $endPoint['x'] . ',' . $endPoint['y'];

            return $result;
        }


        public function calculateDistortingParams( $documentWidth, $width, $height, $xCoord, $yCoord, $data ) {
            //step1
            $objPercentWidth = $width / $documentWidth;

            //step2
            $bottomPosition = $yCoord + $data['small_radius'];
            $arcLength      = 2 * pi() * $bottomPosition * ( $data['angle'] / 360 );

            //step3
            $arcb = $arcLength * $objPercentWidth;

            //step4
            $angleArcB = ( $arcb * 180 ) / ( $bottomPosition * pi() );

            //step5
            $objPercentLeft = $xCoord / $documentWidth;

            //step6
            $lArcLeft = $arcLength * $objPercentLeft;

            //step 7
            $angleArcleft = ( $lArcLeft * 180 ) / ( $bottomPosition * pi() );

            //step 8
            $startAngle = $angleArcleft - ( $data['angle'] / 2 ) + $angleArcB / 2;

            return array(
                'angle_arcleft'   => $angleArcleft + $data['start_angle'],
                'angle_arcb'      => $angleArcB,
                'bottom_position' => $bottomPosition,
                'start_angle'     => $startAngle + $data['start_angle']
            );
        }

        public function decomposeMatrix2DW3($matrix)
        {
            $row0x = $matrix[0];
            $row0y = $matrix[1];
            $row1x = $matrix[2];
            $row1y = $matrix[3];

            $scaleX = sqrt($row0x * $row0x + $row0y * $row0y);
            $scaleY = sqrt($row1x * $row1x + $row1y * $row1y);

            // If determinant is negative, one axis was flipped.
            $determinant = $row0x * $row1y - $row0y * $row1x;
            if ($determinant < 0)
                // Flip axis with minimum unit vector dot product.
                if ($row0x < $row1y)
                    $scaleX = -$scaleX;
                else
                    $scaleY = -$scaleY;

            // Renormalize matrix to remove scale.
            if ($scaleX) {
                $row0x *= 1 / $scaleX;
                $row0y *= 1 / $scaleX;
            }

            if ($scaleY) {
                $row1x *= 1 / $scaleY;
                $row1y *= 1 / $scaleY;
            }

            // Compute rotation and renormalize matrix.
            $angle = atan2($row0y, $row0x);

            if ($angle) {
                // Rotate(-angle) = [cos(angle), sin(angle), -sin(angle), cos(angle)]
                //                = [row0x, -row0y, row0y, row0x]
                // Thanks to the normalization above.
                $sn = -$row0y;
                $cs = $row0x;
                $m11 = $row0x;
                $m12 = $row0y;
                $m21 = $row1x;
                $m22 = $row1y;
                $row0x = $cs * $m11 + $sn * $m21;
                $row0y = $cs * $m12 + $sn * $m22;
                $row1x = -$sn * $m11 + $cs * $m21;
                $row1y = -$sn * $m12 + $cs * $m22;
            }

            $m11 = $row0x;
            $m12 = $row0y;
            $m21 = $row1x;
            $m22 = $row1y;

            // Convert into degrees because our rotation functions expect it.
            $angle = $angle * (180 / pi());
            // The requested parameters are then theta,
            // sx, sy, phi,
            return array(
                'translateX' => $matrix[4],
                'translateY' => $matrix[5],
                'rotateZ' => $angle,
                'scaleX' => $scaleX,
                'scaleY' => $scaleY,
                'matrix' => [$m11, $m12, $m21, $m22, 0, 0]
            );

        }

        public function extractNumbers( $type, $string ) {
            $data = array();
            switch ($type) {
                case 'rotate':
                case 'translate':
                    $data[0] = 0;
                    $data[1] = 0;
                    break;
                case 'scale':
                    $data[0] = 1;
                    $data[1] = 1;
                    break;
            }

            if (strpos($string, 'matrix') !== false) {
                $string = str_replace('matrix', '', $string);
                $string = str_replace('(', '', $string);
                $string = str_replace(')', '', $string);
                $matrix = explode(' ', $string);
                $decomposedMatrix = $this->decomposeMatrix2DW3($matrix);

                switch ($type) {
                    case 'translate':
                        $data[0] = $decomposedMatrix['translateX'];
                        $data[1] = $decomposedMatrix['translateY'];
                        break;
                    case 'scale':
                        $data[0] = $decomposedMatrix['scaleX'];
                        $data[1] = $decomposedMatrix['scaleY'];
                        break;
                    case 'rotate':
                        $data[0] = $decomposedMatrix['rotateZ'];
                        $data[1] = $decomposedMatrix['rotateZ'];
                        break;
                }

            } else {
                preg_match('/' . $type . '\(.*?\)/', $string, $result);
                if (isset($result[0])) {
                    preg_match_all('![0-9\-]+(?:[0-9\-\.]+)!', $result[0], $matches);
                    if (is_array($matches[0])) {
                        $data[0] = isset($matches[0][0]) ? $matches[0][0] : $data[0];
                        $data[1] = isset($matches[0][1]) ? $matches[0][1] : $data[1];

                    }
                }
            }


            return $data;
        }

        public function getRotatedPoints( $x0, $y0, $x1, $y1, $rotateAngle ) {
            $x2 = $x0 + ( $x1 - $x0 ) * cos( deg2rad( $rotateAngle ) ) + ( $y1 - $y0 ) * sin( deg2rad( $rotateAngle ) );
            $y2 = $y0 - ( $x1 - $x0 ) * sin( deg2rad( $rotateAngle ) ) + ( $y1 - $y0 ) * cos( deg2rad( $rotateAngle ) );

            return array( 'x' => $x2, 'y' => $y2 );
        }

        public function getBoundingBox( $topLeft, $topRight, $bottomLeft, $bottomRight ) {

            $min_x = min( $topLeft['x'], $topRight['x'], $bottomLeft['x'], $bottomRight['x'] );
            $max_x = max( $topLeft['x'], $topRight['x'], $bottomLeft['x'], $bottomRight['x'] );

            $min_y = min( $topLeft['y'], $topRight['y'], $bottomLeft['y'], $bottomRight['y'] );
            $max_y = max( $topLeft['y'], $topRight['y'], $bottomLeft['y'], $bottomRight['y'] );

            return array(
                0        => array(
                    'x' => $min_x,
                    'y' => $min_y
                ),
                1        => array(
                    'x' => $max_x,
                    'y' => $min_y
                ),
                2        => array(
                    'x' => $max_x,
                    'y' => $max_y
                ),
                3        => array(
                    'x' => $min_x,
                    'y' => $max_y
                ),
                'width'  => $max_x - $min_x,
                'height' => $max_y - $min_y
            );
        }

        public function extractColor( $colorString, $backgroundColor = false ) {

            $cmykColor = 'transparent';

            if( strlen( $colorString ) ) {
                $fontColorArray = explode( ' ', $colorString );
                if( $backgroundColor ) {
                    $cmykColor = '"cmyk(' . ( $fontColorArray[0] * 100 ) . "%," . ( $fontColorArray[1] * 100 ) . "%," . ( $fontColorArray[2] * 100 ) . "%," . ( $fontColorArray[3] * 100 ) . '%)"';
                }
                else {
                    $cmykColor = "cmyk(" . ( $fontColorArray[0] * 100 ) . "%," . ( $fontColorArray[1] * 100 ) . "%," . ( $fontColorArray[2] * 100 ) . "%," . ( $fontColorArray[3] * 100 ) . "%)";
                }
            }

            return $cmykColor;
        }

        public function createBackgroundImage( $width, $height, $cmykColor ) {

            $imageName = md5( $width . $height . $cmykColor ) . '.tiff';
            $success   = false;
            $result    = null;
            $path      = dirname( __DIR__ );
            try {
                $command = 'convert -size ' . ( $width ) . 'x' . ( $height ) . ' xc:' . $cmykColor . ' ' . $path . $this->imageDistortPath . $imageName;
                exec( $command );
                $success = true;
                $result  = array(
                    'imageName' => $this->imageDistortFolder.$imageName
                );

            } catch( Mage_Exception $e ) {
                $result = $this->__( $e->getMessage() );
            }
            return array(
                'success' => $success,
                'result'  => $result
            );
        }

        public function prepareDataDistort( $documentWidth, $documentHeight, $data, $useSmallImageDesigner = 0 ) {

            $result                       = array();
            $multiplier                   = 1;
            $result['start_angle']        = (float)$data['startAngleDistort'];
            $offsetLeft                   = (float)$data['leftOffsetDistort'] * $this->oneMMToPixel;
            $offsetRight                  = (float)$data['rightOffsetDistort'] * $this->oneMMToPixel;
            $offsetTopBottom              = (float)$data['topOffsetDistort'] * $this->oneMMToPixel;
            $result['leftOffsetDistort']  = $data['leftOffsetDistort'];
            $result['rightOffsetDistort'] = $data['rightOffsetDistort'];
            $result['topOffsetDistort']   = $data['topOffsetDistort'];
            $documentWidthWithoutOffset   = $documentWidth - $offsetLeft - $offsetRight;
            $documentHeightWithoutOffset  = $documentHeight - 2 * $offsetTopBottom;
            $result['angle']              = ( $data['angleDistort'] * $documentWidth ) / $documentWidthWithoutOffset;

            $bigRadius     = (float)$data['bigRadiusDistort'] * $this->oneMMToPixel;
            $smallRadius   = (float)$data['smallRadiusDistort'] * $this->oneMMToPixel;
            $diffRadius    = $bigRadius - $smallRadius;
            $distortOffset = ( $diffRadius * 2 * $offsetTopBottom / $documentHeightWithoutOffset ) / 2;

            $result['big_radius']   = ( $bigRadius + $distortOffset ) * $multiplier;
            $result['small_radius'] = ( $smallRadius - $distortOffset ) * $multiplier;

            return $result;
        }

        public function getFontFromTSpan($style){
            $fontFamilly = false;
            preg_match('/font-family:(.*?)\;/', $style, $result);
            if(is_array($result) && count($result)){
                if(isset($result[1]) && $result[1]){
                    $result[1] = str_replace("'",'',$result[1]);
                         return $result[1];
                }

            }
            return $fontFamilly;
        }
        public function getOpacity($style){
            $opacity= 1;
            preg_match('/opacity:(.*?)\;/', $style, $result);
            if(is_array($result) && count($result)){

                if(isset($result[1]) && $result[1]){
                    $result[1] = str_replace("'",'',$result[1]);
                    return $result[1];
                }

            }
            return $opacity;
        }
        public function getFontSizeFromTSpan($style){
            $fontFamilly = false;
            preg_match('/font-size:(.*?)\;/', $style, $result);
            if(is_array($result) && count($result)){
                if(isset($result[1]) && $result[1]){
                    $result[1] = str_replace("'",'',$result[1]);

                    return str_replace('px','',$result[1]);

                }

            }
            return $fontFamilly;
        }

        public function prepareSvg($svg, $data, $useSmallImagesDesigner = 0,$images_info)
        {


            $xml = new SimpleXMLElement($svg);


            $documentWidth = (float)$xml->attributes()->width;
            $documentHeight = (float)$xml->attributes()->height;

            $rootPathImage = dirname(__DIR__) . $this->imageSearchPath;

            $rootPathImageDistort = dirname(__DIR__) . $this->imageDistortPath;

            //if (!is_dir($rootPathImageDistort)) {
            //        mkdir($rootPathImageDistort, 0777, true);
            //}


            $data = $this->prepareDataDistort($documentWidth, $documentHeight, $data, $useSmallImagesDesigner);


            $percentHeight = ($data['big_radius'] - $data['small_radius']) / $documentHeight;

            $newDimension = $this->getNewDimensions($documentWidth, $documentHeight, $data);

            $xml->attributes()->width = $newDimension['width'] / $percentHeight;
            $xml->attributes()->height = $newDimension['height'] / $percentHeight;
            $xml->attributes()->viewBox = '0 0 ' . $newDimension['width'] . ' ' . $newDimension['height'];

            $dimensionWidthPercent = $newDimension['width'] / $documentWidth;
            $dimensionHeightPercent = $newDimension['height'] / $documentHeight;

            $textMultiplayer = 10;
            $masterTranslateX = 0;
            $masterTranslateY = 0;
            $masterScaleX = 1;
            $masterScaleY = 1;
            $masterElement = false;
            if ($xml->g) {
                $masterElement = $xml->g;
                $helperTranslationElement = (isset($masterElement->attributes()->helperTranslationElement) && (string)$masterElement->attributes()->helperTranslationElement) ? 1 : 0;

                if ($helperTranslationElement) {
                    $itemTransform = (string)$masterElement->attributes()->transform;
                    $translate = $this->extractNumbers('translate', $itemTransform);
                    $scale = $this->extractNumbers('scale', $itemTransform);
                    $masterTranslateX = (float)$translate[0];
                    $masterTranslateY = (float)$translate[1];
                    $masterScaleX = (float)$scale[0];
                    $masterScaleY = (float)$scale[1];

                }
            }

            if ($masterElement) {
                /*
                foreach ($masterElement->rect as $key => $xmlElement) {
                    $objectID = (string)$xmlElement->attributes()->objectID;

                    if ($objectID == 'backgroundColor') {
                        $itemTransform = (string)$xmlElement->attributes()->transform;
                        $translate = $this->extractNumbers('translate', $itemTransform);
                        $rotate = $this->extractNumbers('rotate', $itemTransform);
                        $scale = $this->extractNumbers('scale', $itemTransform);
                        $width = (float)$xmlElement->attributes()->width * (float)$scale[0];
                        $height = (float)$xmlElement->attributes()->height * (float)$scale[1];
                        $cmykColorString = (string)$xmlElement->attributes()->cmykColor;
                        $xCoord = (float)$xmlElement->attributes()->x;
                        $yCoord = (float)$xmlElement->attributes()->y;
                        $visibility = (string)$xmlElement->attributes()->visibility == '1' ? 1 : 0;
                        $cmykColor = $this->extractColor($cmykColorString, true);

                        if ($visibility) {

                            $imageResult = $this->createBackgroundImage($width, $height, $cmykColor);

                            if ($imageResult['success']) {

                                $boxContainer = new SimpleXMLElement ('<g></g>');
                                $imageContainer = $boxContainer->addChild('image');
                                $imageUrl = $imageResult['result']['imageName'];

                                $imageContainer->addAttribute('xlink:href', $imageUrl, 'http://www.w3.org/1999/xlink');
                                $imageContainer->addAttribute('x', $xCoord);
                                $imageContainer->addAttribute('y', $yCoord);
                                $imageContainer->addAttribute('width', (string)$xmlElement->attributes()->width);
                                $imageContainer->addAttribute('height', (string)$xmlElement->attributes()->height);

                                $boxContainer->addAttribute('transform', $itemTransform);

                                $this->simplexml_insert_after($boxContainer, $xmlElement);

                                $xmlElement->attributes()->visibility = 'hidden';
                            }
                        }
                    }
                }
                */
                foreach ($masterElement->g as $key => $xmlElement) {

                    $itemTransform = (string)$xmlElement->attributes()->transform;
                    $translate     = $this->extractNumbers('translate', $itemTransform);

                    $rotate        = $this->extractNumbers('rotate', $itemTransform);
                    $scale         = $this->extractNumbers('scale', $itemTransform);

                    $translate[0] = (float)$translate[0] * (float)$masterScaleX + $masterTranslateX;
                    $translate[1] = (float)$translate[1] * (float)$masterScaleY +  $masterTranslateY;
                    $scale[0] *= $masterScaleX;
                    $scale[1] *= $masterScaleY;

                    $itemTransformX = (float)$translate[0];
                    $itemTransformY = (float)$translate[1];
                    $scaleX = (float)$scale[0];
                    $scaleY = (float)$scale[1];
                    $rotateAngle = (float)$rotate[0];

                    foreach ($xmlElement->svg as $svgImageElement) {
                        $imageBlockId  = (string)$svgImageElement->attributes()->objectId;
                        if($imageBlockId){
                            $imageIdxKey = str_replace("image_",'',$imageBlockId);
                            if($images_info[$imageIdxKey]){

                                $translatXSvgImageElement     = (float)$svgImageElement->attributes()->x;
                                $translatYSvgImageElement     = (float)$svgImageElement->attributes()->y;


                                // the position and the size of the actual bounding box of the image
                                $itemTransformX               = $itemTransformX + $translatXSvgImageElement*$masterScaleX;
                                $itemTransformY               = $itemTransformY + $translatYSvgImageElement*$masterScaleY;
                                $itemTransformWidth           = (float)$svgImageElement->attributes()->width * $masterScaleX;
                                $itemTransformHeight          = (float)$svgImageElement->attributes()->height * $masterScaleY;

                                $actualBlockWidth             = $itemTransformWidth;
                                $actualBlockHeight            = $itemTransformHeight;
                                $actualItemTransformY         = $itemTransformY;
                                $itemTransformY               = $documentHeight -$itemTransformY - $itemTransformHeight;

                                //calculate cropping parameters
                                $image_full_width   = $images_info[$imageIdxKey]['cropW']  * $masterScaleX;
                                $image_full_height  = $images_info[$imageIdxKey]['cropH']  * $masterScaleY;

                                $imageRatioX = $images_info[$imageIdxKey]['image_width'] / $image_full_width;
                                $imageRatioY = $images_info[$imageIdxKey]['image_height'] / $image_full_height;

                                //this will be used for cropping the image in the virtual imagemagick canvas
                                $cropX       = (abs($images_info[$imageIdxKey]['cropX']) * $masterScaleX) *$imageRatioX;
                                $cropY       = (abs($images_info[$imageIdxKey]['cropY']) * $masterScaleY) *$imageRatioY;
                                $cropW       = ($itemTransformWidth) *$imageRatioX;
                                $cropH       = ($itemTransformHeight) *$imageRatioY;


                                $imageElement = $svgImageElement->image;

                                $opacity = $this->getOpacity((string)$imageElement->attributes()->style);



                                if($imageElement){
                                    $excludeDistort = (isset($imageElement->attributes()->excludeDistort) && (int)$imageElement->attributes()->excludeDistort) ? 1 : 0;
                                }
                                if ($rotateAngle != 0) {
                                    $excludeDistort = 1;
                                }

                                if ($excludeDistort) {
                                    $itemTransformX = $itemTransformX + $itemTransformWidth/2;
                                    $itemTransformY =  ($itemTransformY+ $itemTransformHeight/2);
                                    $itemTransformWidth = 1;
                                    $itemTransformHeight = 1;
                                }
                                /*
                                if ((int)$rotateAngle != 0 && !$excludeDistort) {
                                        print_r(1);die();
                                    $imageMagick->rotateimage(new ImagickPixel('none'), $rotateAngle);

                                    $topLeft = $this->getRotatedPoints($itemTransformX, $itemTransformY, ($itemTransformX - $width / 2), ($itemTransformY - $height / 2), $rotateAngle);
                                    $topRight = $this->getRotatedPoints($itemTransformX, $itemTransformY, ($itemTransformX + $width / 2), ($itemTransformY - $height / 2), $rotateAngle);
                                    $bottomLeft = $this->getRotatedPoints($itemTransformX, $itemTransformY, ($itemTransformX - $width / 2), ($itemTransformY + $height / 2), $rotateAngle);
                                    $bottomRight = $this->getRotatedPoints($itemTransformX, $itemTransformY, ($itemTransformX + $width / 2), ($itemTransformY + $height / 2), $rotateAngle);
                                    $boundingBox = $this->getBoundingBox($topLeft, $topRight, $bottomLeft, $bottomRight);
                                    $width = $boundingBox['width'];
                                    $height = $boundingBox['height'];
                                    $xCoord = $boundingBox[0]['x'];
                                    $yCoord = $documentHeight - $boundingBox[3]['y'];

                                }*/
                                $distortingPoints = $this->calculateDistortingParams($documentWidth, $itemTransformWidth, $itemTransformHeight, $itemTransformX, $itemTransformY * $percentHeight, $data);
                                $leftOffset = $newDimension['width'] / ($data['angle'] / (($data['angle'] / 2) - $data['start_angle']));
                                $bigRadius = $distortingPoints['bottom_position'] + $itemTransformHeight * $percentHeight;
                                $boxCoords = $this->getPoints($data['angle'], $distortingPoints['angle_arcleft'], $distortingPoints['angle_arcb'], $data['small_radius'], $itemTransformY * $percentHeight, $itemTransformHeight * $percentHeight, $data['big_radius'], $leftOffset);

                                if ($excludeDistort) {

                                    $boxContainerMegaContainer = new SimpleXMLElement ('<g></g>');
                                    $boxContainer              = $boxContainerMegaContainer->addChild('g');
                                    $imageContainer            = $boxContainer->addChild('image');

                                    $imageMagick = new \imagick($rootPathImage . $images_info[$imageIdxKey]['src']);

                                    $imageFormat = $imageMagick->getImageFormat();
                                    $colorSpace = $imageMagick->getImageColorspace();

                                    $image_extension = ($imageFormat == 'PNG' || $colorSpace != imagick::COLORSPACE_CMYK) ? '.png' : '.tiff';

                                    $newImagePath = $rootPathImageDistort . $images_info[$imageIdxKey]['src'] . '_cropped_'  . $image_extension;
                                    $newImageUrl = $this->imageDistortFolder . $images_info[$imageIdxKey]['src'] . '_cropped_'  . $image_extension;

                                    exec('convert ' . $rootPathImage . $images_info[$imageIdxKey]['src'] ."  -crop {$cropW}x{$cropH}+{$cropX}+{$cropY}! " .' -page +0+0 ' . $newImagePath);

                                    $imageContainer->addAttribute('xlink:href', $newImageUrl, 'http://www.w3.org/1999/xlink');
                                    $imageContainer->addAttribute('x', - ($actualBlockWidth* $percentHeight)/2);
                                    $imageContainer->addAttribute('y', -($actualBlockHeight* $percentHeight)/2);

                                    $imageContainer->addAttribute('width', $actualBlockWidth* $percentHeight);
                                    $imageContainer->addAttribute('height',$actualBlockHeight* $percentHeight);




                                    $imageContainer->addAttribute('opacity', $opacity);
                                   // $imageContainer->addAttribute('preserveAspectRatio', 'slice');
                                    //we need to translate back to initial position
                                    $transformElement = 'translate(' . $boxCoords[5] . ' ' . $boxCoords[6] . ') scale(1 1)';

                                    if ((float)$rotateAngle != 0) {
                                        $rotateAngle = $rotateAngle + $data['start_angle'] + $distortingPoints['start_angle'];
                                        $transformElement .= ' rotate(' . $rotateAngle . ' 0 0)';
                                    } elseif ((float)$rotateAngle == 0) {

                                        $transformElement .= ' rotate(' . ($data['start_angle'] + $distortingPoints['start_angle']) . ' 0 0)';
                                    }
                                    $transformMegaElement = "translate(".(-($masterTranslateX)*((1/$masterScaleX)))." ".(-($masterTranslateY)*(1/$masterScaleY)).") scale(".(1/$masterScaleX)." ".(1/$masterScaleY).")";
                                    $boxContainerMegaContainer->addAttribute('transform', $transformMegaElement);
                                    $boxContainer->addAttribute('transform', $transformElement);
                                    $this->simplexml_insert_after($boxContainerMegaContainer, $xmlElement);

                                }else{
                                    $imageMagick = new \imagick($rootPathImage . $images_info[$imageIdxKey]['src']);

                                    $imageFormat = $imageMagick->getImageFormat();
                                    $colorSpace = $imageMagick->getImageColorspace();

                                    $image_extension = ($imageFormat == 'PNG' || $colorSpace != imagick::COLORSPACE_CMYK) ? '.png' : '.tiff';


                                    //make a cache for image
                                    $hashStringImage = md5($cropX.$cropY.$cropW.$cropH.$itemTransformWidth . $excludeDistort . $itemTransformHeight . $itemTransformX . $itemTransformY . $rotateAngle . $images_info[$imageIdxKey]['src'] . $data['start_angle'] . $data['angle'] . $data['small_radius'] . $data['big_radius'] . $data['leftOffsetDistort'] . $data['topOffsetDistort'] . $data['rightOffsetDistort']);

                                    $newImagePath = $rootPathImageDistort . $images_info[$imageIdxKey]['src'] . '_distort_' . $hashStringImage . $image_extension;

                                    $newImageUrl = $this->imageDistortFolder . $images_info[$imageIdxKey]['src'] . '_distort_' . $hashStringImage . $image_extension;


                                    if (!file_exists($newImagePath)) {

                                        $ratio = $imageMagick->getImageHeight() / ($itemTransformHeight * $percentHeight);

                                        exec('convert ' . $rootPathImage . $images_info[$imageIdxKey]['src'] ."  -crop {$cropW}x{$cropH}+{$cropX}+{$cropY}! " .' -virtual-pixel Transparent -distort Arc "' . $distortingPoints['angle_arcb'] . ' ' . $distortingPoints['start_angle'] . ' ' . ($bigRadius * $ratio) . ' ' . ($distortingPoints['bottom_position'] * $ratio) . '"    -page +0+0 ' . $newImagePath);
                                    }
                                    $boxContainer = new SimpleXMLElement ('<g></g>');


                                    $imageContainer = $boxContainer->addChild('image');

                                    $imageContainer->addAttribute('xlink:href', $newImageUrl, 'http://www.w3.org/1999/xlink');
                                    $imageContainer->addAttribute('x', $boxCoords[5]);
                                    $imageContainer->addAttribute('y', $boxCoords[6]);
                                    $imageContainer->addAttribute('width', $boxCoords[7]);
                                    $imageContainer->addAttribute('height', $boxCoords[8]);
                                    $imageContainer->addAttribute('opacity', $opacity);
                                    //we need to translate back to initial position
                                    $transformElement = "translate(".(-($masterTranslateX)*((1/$masterScaleX)))." ".(-($masterTranslateY)*(1/$masterScaleY)).") scale(".(1/$masterScaleX)." ".(1/$masterScaleY).")";
                                    if ((float)$rotateAngle != 0) {
                                        $transformElement .= ' rotate(0 0 0)';
                                    }
                                    $boxContainer->addAttribute('transform', $transformElement);
                                    $this->simplexml_insert_after($boxContainer, $xmlElement);
                                }



                            }
                        }

                    }







                    /*
                    foreach ($xmlElement->image as $image) {

                        $imageSrcWorking = (string)$image->attributes('xlink', true);
                        $imageSrc = str_replace('_working', '', $imageSrcWorking);
                        $width = (float)$image->attributes()->width * $scaleX;
                        $height = (float)$image->attributes()->height * $scaleY;
                        $xCoord = $itemTransformX - $width / 2;
                        $yCoord = $documentHeight - ($itemTransformY + $height / 2);

                        if ($useSmallImagesDesigner) {
                            $imageSrc = $imageSrcWorking;
                        }

                        $excludeDistort = (isset($image->attributes()->excludeDistort) && (string)$image->attributes()->excludeDistort) ? 1 : 0;
                        $exclude = (isset($image->attributes()->exclude) && (string)$image->attributes()->exclude) ? 1 : 0;


                        if ($rotateAngle != 0) {
                            $excludeDistort = 1;
                        }

                        if ($excludeDistort) {
                            $xCoord = $itemTransformX;
                            $yCoord = $documentHeight - $itemTransformY;
                            $width = 1;
                            $height = 1;
                        }

                        if ($exclude) {
                            continue;
                        }

                        if ((int)$rotateAngle != 0 && !$excludeDistort) {

                            $imageMagick->rotateimage(new ImagickPixel('none'), $rotateAngle);

                            $topLeft = $this->getRotatedPoints($itemTransformX, $itemTransformY, ($itemTransformX - $width / 2), ($itemTransformY - $height / 2), $rotateAngle);
                            $topRight = $this->getRotatedPoints($itemTransformX, $itemTransformY, ($itemTransformX + $width / 2), ($itemTransformY - $height / 2), $rotateAngle);
                            $bottomLeft = $this->getRotatedPoints($itemTransformX, $itemTransformY, ($itemTransformX - $width / 2), ($itemTransformY + $height / 2), $rotateAngle);
                            $bottomRight = $this->getRotatedPoints($itemTransformX, $itemTransformY, ($itemTransformX + $width / 2), ($itemTransformY + $height / 2), $rotateAngle);
                            $boundingBox = $this->getBoundingBox($topLeft, $topRight, $bottomLeft, $bottomRight);
                            $width = $boundingBox['width'];
                            $height = $boundingBox['height'];
                            $xCoord = $boundingBox[0]['x'];
                            $yCoord = $documentHeight - $boundingBox[3]['y'];

                        }


                        $distortingPoints = $this->calculateDistortingParams($documentWidth, $width, $height, $xCoord, $yCoord * $percentHeight, $data);


                        $leftOffset = $newDimension['width'] / ($data['angle'] / (($data['angle'] / 2) - $data['start_angle']));

                        $bigRadius = $distortingPoints['bottom_position'] + $height * $percentHeight;
                        $boxCoords = $this->getPoints($data['angle'], $distortingPoints['angle_arcleft'], $distortingPoints['angle_arcb'], $data['small_radius'], $yCoord * $percentHeight, $height * $percentHeight, $data['big_radius'], $leftOffset);

                        if ($excludeDistort) {
                            $width = (float)$image->attributes()->width * $scaleX * $percentHeight;
                            $height = (float)$image->attributes()->height * $scaleY * $percentHeight;
                            $image->attributes('http://www.w3.org/1999/xlink')->href = $imageSrc;
                            $image->attributes()->x = -$width / 2;
                            $image->attributes()->y = -$height / 2;
                            $image->attributes()->width = $width;
                            $image->attributes()->height = $height;
                            $transformElement = 'translate(' . $boxCoords[5] . ' ' . $boxCoords[6] . ') scale(1 1)';

                            if ((float)$rotateAngle != 0) {
                                $rotateAngle = $rotateAngle + $data['start_angle'] + $distortingPoints['start_angle'];
                                $transformElement .= ' rotate(' . $rotateAngle . ' 0 0)';
                            } elseif ((float)$rotateAngle == 0) {

                                $transformElement .= ' rotate(' . ($data['start_angle'] + $distortingPoints['start_angle']) . ' 0 0)';
                            }
                            $xmlElement->attributes()->transform = $transformElement;

                        } else {

                            $imageMagick = new \imagick($rootPathImage . $imageSrc);

                            $imageFormat = $imageMagick->getImageFormat();
                            $colorSpace = $imageMagick->getImageColorspace();

                            $image_extension = ($imageFormat == 'PNG' || $colorSpace != imagick::COLORSPACE_CMYK) ? '.png' : '.tiff';


                            //make a cache for image
                            $hashStringImage = md5($width . $excludeDistort . $height . $xCoord . $yCoord . $rotateAngle . $imageSrc . $data['start_angle'] . $data['angle'] . $data['small_radius'] . $data['big_radius'] . $data['leftOffsetDistort'] . $data['topOffsetDistort'] . $data['rightOffsetDistort']);

                            $newImagePath = $rootPathImageDistort . $imageSrc . '_distort_' . $hashStringImage . $image_extension;

                            $newImageUrl = $this->imageDistortFolder . $imageSrc . '_distort_' . $hashStringImage . $image_extension;

                            if (!file_exists($newImagePath)) {

                                $ratio = $imageMagick->getImageHeight() / ($height * $percentHeight);

                                exec('convert ' . $rootPathImage . $imageSrc . ' -virtual-pixel Transparent -distort Arc "' . $distortingPoints['angle_arcb'] . ' ' . $distortingPoints['start_angle'] . ' ' . ($bigRadius * $ratio) . ' ' . ($distortingPoints['bottom_position'] * $ratio) . '"    -page +0+0 ' . $newImagePath);

                            }


                            $image->attributes('http://www.w3.org/1999/xlink')->href = $newImageUrl;
                            $image->attributes()->x = $boxCoords[5];
                            $image->attributes()->y = $boxCoords[6];
                            $image->attributes()->width = $boxCoords[7];
                            $image->attributes()->height = $boxCoords[8];


                            $xmlElement->attributes()->transform = 'translate(0 0) scale(1 1)';
                        }


                    }
                    */
                    foreach ($xmlElement->text as $text) {

                        if ($text->attributes()->exclude)
                            continue;

                        $fontStyle         = $text->attributes()->style;
                        $fontFamily        = $text->attributes()['font-family'];
                        $masterFontFamilly = $fontFamily;
                        $fontWeight        = (string)$text->attributes()['font-weight'];
                        $fontSize          = (float)$text->attributes()['font-size'] * $scaleX;
                        $masterFontSize    = $fontSize;
                        $fontStyle         = (string)$text->attributes()['font-style'];
                        $textDecoration    = (string)$text->attributes()['text-decoration'];
                        $excludeDistort    = (isset($text->attributes()->excludeDistort) && (string)$text->attributes()->excludeDistort) ? 1 : 0;


                        if($text->attributes()->totalLines){
                            $totalLines = (float)$text->attributes()->totalLines;
                        }

                        $blockCMYK = (string)$text->attributes()->cmykColor;

                        if ($fontSize > 5000)
                            continue;

                        if ($rotateAngle != 0) {
                            $excludeDistort = 1;
                        }
                        $sumRowHeight = 0;
                        $lineindex    = 0;

                        foreach ($text as $key => $tspan) {


                            $inlineFontFamilly = $this->getFontFromTSpan((string)$tspan->attributes()->style);
                            if($inlineFontFamilly){
                                $fontFamily = $inlineFontFamilly;
                            }else{
                                $fontFamily = $masterFontFamilly;
                            }
                            $inlineFontSize = $this->getFontSizeFromTSpan((string)$tspan->attributes()->style);
                            if($inlineFontSize){
                                $fontSize = $inlineFontSize * $scaleX;
                            }else{
                                $fontSize = $masterFontSize;
                            }

                            $boundingBoxWidth   = (float)$tspan->attributes()->boundingBoxWidth * $masterScaleX;
                            $boundingBoxHeight  = (float)$tspan->attributes()->boundingBoxHeight * $masterScaleY;
                            $boundingBoxLeft    = (float)$tspan->attributes()->boundingBoxLeft * $masterScaleX + $masterTranslateX;
                            $boundingBoxTop     = (float)$tspan->attributes()->boundingBoxTop * $masterScaleY + $masterTranslateY;
                            $rowHeight          =  (float)$tspan->attributes()->rowHeight;



                            $offsetWidthPerLine  = $fontSize;
                            $offsetHeightPerLine = $fontSize;
                            $width               = $boundingBoxWidth + $offsetWidthPerLine;
                            $rotHeight           =  $rowHeight * $masterScaleX;
                            $height              = $rotHeight + $offsetHeightPerLine;


                            if(((float)$tspan->attributes()->lineindex + 1)>$lineindex){
                                $sumRowHeight        +=$rotHeight;
                            }



                            $lineindex           = (float)$tspan->attributes()->lineindex + 1;
                            $widthImageText      = $width;
                            $heightImageText     = $height;
                            $tspanX              = (float)$boundingBoxLeft - $offsetWidthPerLine / 2;
                            $tspanY              = $documentHeight - ($boundingBoxTop + $sumRowHeight + $offsetHeightPerLine / 2);

                            $fill                = (string)$tspan->attributes()->fill;
                            $offsetBottom        = ((float)$tspan->attributes()->liftLine) * $scaleX;
                            $xCoord              = $tspanX;
                            $yCoord              = $tspanY;
                            $text_width          = (float)$tspan->attributes()->linewidth * $scaleX;
                            $offset              = 0;
                            $textAlign           = (string)$tspan->attributes()->textAlign;

                            $originalWidth       = (float)$tspan->attributes()->boxOriginalWidth* $masterScaleX;
                            $originalHeight      = (float)$tspan->attributes()->boxOriginalHeight* $masterScaleX;
                            $fontColor           = (string)$tspan->attributes()->cmykColor;

                            if (!$fontColor) {
                                $fontColor = $blockCMYK;
                            }
                            $cmykColor = $this->extractColor($fontColor);
                            $opacity = (string)$tspan->attributes()->opacity !== '' ? (string)$tspan->attributes()->opacity : 1;
                            if ($excludeDistort) {
                                $xCoord = $itemTransformX;
                                $yCoord = $documentHeight - ($itemTransformY);
                                $widthImageText = $originalWidth + $offsetWidthPerLine;
                                $heightImageText = $rowHeight + $offsetHeightPerLine;
                                $width = 1;
                                $height = 1;
                            }

                            $offsetAlign = 0;
                            if ($textAlign == 'center') {
                                $offsetAlign = ($boundingBoxWidth - $text_width)/2;
                                $offset = ($widthImageText - $offsetWidthPerLine - $text_width) / 2;
                            } elseif ($textAlign == 'right') {
                                $offsetAlign = $boundingBoxWidth - $text_width;
                                $offset = $widthImageText - $offsetWidthPerLine - $text_width;
                            }
                            //this is needed for inline editing
                            $tspanXAttribute = (float)$tspan->attributes()->x * $masterScaleX;
                            $offset = $offset + $boundingBoxWidth/2 + $tspanXAttribute - $offsetAlign;

                            $offset += $offsetWidthPerLine / 2;

                            $distortingPoints = $this->calculateDistortingParams($documentWidth, $width, $height, $xCoord, ($yCoord) * $percentHeight, $data);
                            $bigRadius = $distortingPoints['bottom_position'] + ($height) * $percentHeight;
                            $yCoordImagick = (((($heightImageText - $offsetHeightPerLine) / 1.16) - $offsetBottom) + $offsetHeightPerLine / 2) * $textMultiplayer;
                            $yCoordImagickUnderline = ((0.98 * $fontSize + $offsetHeightPerLine / 2)) * $textMultiplayer;
                            $yCoordImagickUnderlineY = ((0.98 * $fontSize) + $fontSize / 15 + $offsetHeightPerLine / 2) * $textMultiplayer;

                            if ($textDecoration == 'underline') {
                                $underlineDraw = ' -draw "rectangle ' . ($offset * $textMultiplayer) . ',' . $yCoordImagickUnderline . ' ' . (($text_width + $offset) * $textMultiplayer) . ',' . ($yCoordImagickUnderlineY) . '"';
                            } else {
                                $underlineDraw = '';
                            }

                            //make a cache for image
                            $hashStringText = md5($xCoord . $yCoord . $textAlign . $width . $height . $fontSize . $fontFamily . $excludeDistort . $cmykColor . $rotateAngle . $textDecoration . $data['start_angle'] . $data['angle'] . $data['small_radius'] . $data['big_radius'] . $data['leftOffsetDistort'] . $data['topOffsetDistort'] . $data['rightOffsetDistort'] . (string)$tspan);
                            $textImagePath = dirname(__DIR__) . $this->imageDistortPath;
                            $textImageName = 'text_image_' . $hashStringText;
                            $extension     = '.tiff';

                            if (!file_exists($textImagePath . $textImageName . $extension)) {
                                $font_path = $this->getFontPath($fontFamily);
                                $command1  = 'convert -size ' . ($widthImageText * $textMultiplayer) . 'x' . ($heightImageText * $textMultiplayer) . ' xc:transparent -font "' . $font_path . '" -pointsize ' . $fontSize . ' -density ' . (72 * $textMultiplayer) . ' -fill "' . $cmykColor . '" -colorspace CMYK  -draw "text ' . ($offset * $textMultiplayer) . ',' . $yCoordImagick . " '" . addslashes((string)$tspan) . "'" . '" ' . $underlineDraw . ' -resample ' . (7.2 * $textMultiplayer) . ' ' . $textImagePath . $textImageName . $extension;
                                exec($command1);
                            }


                            $leftOffset = $newDimension['width'] / ($data['angle'] / (($data['angle'] / 2) - $data['start_angle']));

                            $boxCoords = $this->getPoints($data['angle'], $distortingPoints['angle_arcleft'], $distortingPoints['angle_arcb'], $data['small_radius'], ($yCoord) * $percentHeight, $height * $percentHeight, $data['big_radius'], $leftOffset);


                            if (!$excludeDistort) {

                                if (!file_exists($textImagePath . $textImageName . '_distort' . $extension)) {


                                    $ratio = 1 / $percentHeight;

                                    exec('convert ' . $textImagePath . $textImageName . $extension . ' -virtual-pixel Transparent  -distort Arc "' . $distortingPoints['angle_arcb'] . ' ' . $distortingPoints['start_angle'] . ' ' . ($bigRadius * $ratio) . ' ' . ($distortingPoints['bottom_position'] * $ratio) . '"    -page +0+0 ' . $textImagePath . $textImageName . '_distort' . $extension);


                                }


                                $boxContainer = new SimpleXMLElement ('<g></g>');


                                $imageContainer = $boxContainer->addChild('image');

                                $imageContainer->addAttribute('xlink:href', $this->imageDistortFolder . $textImageName . '_distort' . $extension, 'http://www.w3.org/1999/xlink');
                                $imageContainer->addAttribute('x', $boxCoords[5]);
                                $imageContainer->addAttribute('y', $boxCoords[6]);
                                $imageContainer->addAttribute('width', $boxCoords[7]);
                                $imageContainer->addAttribute('height', $boxCoords[8]);
                                $imageContainer->addAttribute('opacity', $opacity);
                                $imageContainer->addAttribute('exclude', '1');

                                //$transformElement = "translate(0 0) scale(1 1)";
                                //we need to translate back to initial position
                                $transformElement = "translate(".(-($masterTranslateX)*((1/$masterScaleX)))." ".(-($masterTranslateY)*(1/$masterScaleY)).") scale(".(1/$masterScaleX)." ".(1/$masterScaleY).")";

                                if ((float)$rotateAngle != 0) {
                                    $transformElement .= ' rotate(0 0 0)';
                                }

                                $boxContainer->addAttribute('transform', $transformElement);
                                $this->simplexml_insert_after($boxContainer, $xmlElement);
                            } else {
                                $parentBoxContainer = new SimpleXMLElement ('<g></g>');
                                $boxContainer = $parentBoxContainer->addChild('g');
                                $imageContainer = $boxContainer->addChild('image');
                                $transformElement = 'translate(' . $boxCoords[5] . ' ' . $boxCoords[6] . ') scale(1 1)';
                                $transformParentElement = "translate(".(-($masterTranslateX)*((1/$masterScaleX)))." ".(-($masterTranslateY)*(1/$masterScaleY)).") scale(".(1/$masterScaleX)." ".(1/$masterScaleY).")";


                                if ((float)$rotateAngle != 0) {
                                    if ($lineindex == 1) {
                                        $rotateAngle = $rotateAngle + $data['start_angle'] + $distortingPoints['start_angle'];
                                    }
                                    $transformElement .= ' rotate(' . $rotateAngle . ' 0 0)';
                                } elseif ((float)$rotateAngle == 0) {
                                    $transformElement .= ' rotate(' . ($data['start_angle'] + $distortingPoints['start_angle']) . ' 0 0)';
                                }

                                $imageContainer->addAttribute('xlink:href', $this->imageDistortFolder . $textImageName . $extension, 'http://www.w3.org/1999/xlink');
                                $imageContainer->addAttribute('x', (-$widthImageText / 2) * $percentHeight);
                                $imageContainer->addAttribute('y', (-($originalHeight ) / 2 + ($heightImageText - $offsetHeightPerLine/2) * ($lineindex - 1)) * $percentHeight);
                                $imageContainer->addAttribute('width', $widthImageText * $percentHeight);
                                $imageContainer->addAttribute('height', $heightImageText * $percentHeight);
                                $imageContainer->addAttribute('exclude', '1');
                                $imageContainer->addAttribute('opacity', $opacity);
                                $boxContainer->addAttribute('transform', $transformElement);
                                $parentBoxContainer->addAttribute('transform', $transformParentElement);


                                $this->simplexml_insert_after($parentBoxContainer, $xmlElement);
                            }
                        }
                        if ($text->attributes()->visibility) {
                            $text->attributes()->visibility = 'hidden';
                        } else {
                            $text->addAttribute('visibility', 'hidden');
                        }

                    }
                    foreach ($xmlElement->rect as $rect) {

                        $isTextBg = (string)$rect->attributes()->textBg ? 1 : 0;
                        if ($isTextBg) {

                            $xCoord          = (float)$rect->attributes()->absoluteX;
                            $rotateAngle     = (float)$rect->attributes()->rectAngle;
                            $widthElement    = (float)$rect->attributes()->absoluteWidth;
                            $heightElement   = (float)$rect->attributes()->absoluteHeight;
                            $opacity         = (float)$rect->attributes()->opacity;
                            $copyWidthElement   = $widthElement;
                            $copyHeightElement = $heightElement;
                            $yCoord          = $documentHeight - (float)$rect->attributes()->absoluteY - $heightElement;
                            $cmykColorString = (string)$rect->attributes()->bgColorCmyk;
                            $visibility      = (string)$rect->attributes()->visibility == 'hidden' ? 1 : 1;

                            if ($visibility) {
                                $imageResult = $this->createBackgroundImage($widthElement, $heightElement, $this->extractColor($cmykColorString,true));
                                if ($imageResult['success']) {
                                    //we need to distort it
                                    $excludeDistort = (isset($rect->attributes()->excludeDistort) && (string)$rect->attributes()->excludeDistort) ? 1 : 0;
                                    if ($excludeDistort) {
                                        $xCoord         = $xCoord+$widthElement/2;
                                        $yCoord         = $documentHeight - ($itemTransformY);
                                        $widthElement   = 1;
                                        $heightElement  = 1;
                                    }

                                    /*if ((int)$rotateAngle != 0 && !$excludeDistort) {
                                        $image  = new \imagick();
                                        $image->rotateimage(new ImagickPixel('none'), $rotateAngle);

                                        $topLeft               = $this->getRotatedPoints($xCoord, $yCoord, ($xCoord - $widthElement / 2), ($yCoord - $heightElement / 2), $rotateAngle);
                                        $topRight              = $this->getRotatedPoints($xCoord, $yCoord, ($xCoord + $widthElement / 2), ($yCoord - $heightElement / 2), $rotateAngle);
                                        $bottomLeft            = $this->getRotatedPoints($xCoord, $yCoord, ($xCoord - $widthElement / 2), ($yCoord + $heightElement / 2), $rotateAngle);
                                        $bottomRight           = $this->getRotatedPoints($xCoord, $yCoord, ($xCoord + $widthElement / 2), ($yCoord + $heightElement / 2), $rotateAngle);
                                        $boundingBox           = $this->getBoundingBox($topLeft, $topRight, $bottomLeft, $bottomRight);
                                        $widthElement          = $boundingBox['width'];
                                        $heightElement         = $boundingBox['height'];
                                        $xCoord                = $boundingBox[0]['x'];
                                        $yCoord                = $documentHeight - $boundingBox[3]['y'];
                                    }*/

                                    $distortingPoints = $this->calculateDistortingParams($documentWidth, $widthElement, $heightElement, $xCoord, $yCoord * $percentHeight, $data);

                                    $leftOffset = $newDimension['width'] / ($data['angle'] / (($data['angle'] / 2) - $data['start_angle']));
                                    $bigRadius  = $distortingPoints['bottom_position'] + $heightElement * $percentHeight;
                                    $boxCoords  = $this->getPoints($data['angle'], $distortingPoints['angle_arcleft'], $distortingPoints['angle_arcb'], $data['small_radius'], $yCoord * $percentHeight, $heightElement * $percentHeight, $data['big_radius'], $leftOffset);

                                    if ($excludeDistort) {

                                        $boxContainerMegaContainer = new SimpleXMLElement ('<g></g>');
                                        $boxContainer = $boxContainerMegaContainer->addChild('g');


                                        $imageContainer = $boxContainer->addChild('image');

                                        $imageContainer->addAttribute('xlink:href',$imageResult['result']['imageName'], 'http://www.w3.org/1999/xlink');
                                        $imageContainer->addAttribute('x', (-$copyWidthElement*$percentHeight / 2));
                                        $imageContainer->addAttribute('y', -$copyHeightElement*$percentHeight / 2);
                                        $imageContainer->addAttribute('width', $copyWidthElement*$percentHeight);
                                        $imageContainer->addAttribute('height',$copyHeightElement*$percentHeight);
                                        $imageContainer->addAttribute('opacity', $opacity);
                                        //we need to translate back to initial position
                                        $transformElement = 'translate(' . $boxCoords[5] . ' ' . $boxCoords[6] . ') scale(1 1)';

                                        if ((float)$rotateAngle != 0) {
                                            $rotateAngle = $rotateAngle + $data['start_angle'] + $distortingPoints['start_angle'];
                                            $transformElement .= ' rotate(' . $rotateAngle . ' 0 0)';
                                        } elseif ((float)$rotateAngle == 0) {

                                            $transformElement .= ' rotate(' . ($data['start_angle'] + $distortingPoints['start_angle']) . ' 0 0)';
                                        }

                                        $transformMegaElement = "translate(".(-($masterTranslateX)*((1/$masterScaleX)))." ".(-($masterTranslateY)*(1/$masterScaleY)).") scale(".(1/$masterScaleX)." ".(1/$masterScaleY).")";
                                        $boxContainerMegaContainer->addAttribute('transform', $transformMegaElement);
                                        $boxContainer->addAttribute('transform', $transformElement);
                                        $this->simplexml_insert_after($boxContainerMegaContainer, $xmlElement);









                                    } else {
                                        $imageMagick = new \imagick($rootPathImage . $imageResult['result']['imageName']);


                                        $imageFormat     = $imageMagick->getImageFormat();
                                        $colorSpace      = $imageMagick->getImageColorspace();
                                        $image_extension = ($imageFormat == 'PNG' || $colorSpace != imagick::COLORSPACE_CMYK) ? '.png' : '.tiff';


                                        //make a cache for image
                                        $hashStringImage = md5($widthElement . $excludeDistort . $heightElement . $xCoord . $yCoord . $rotateAngle . $imageResult['result']['imageName'] . $data['start_angle'] . $data['angle'] . $data['small_radius'] . $data['big_radius'] . $data['leftOffsetDistort'] . $data['topOffsetDistort'] . $data['rightOffsetDistort']);
                                        $newImagePath    = $rootPathImage . $imageResult['result']['imageName'] . '_distort_' . $hashStringImage . $image_extension;
                                        $newImageUrl     = $imageResult['result']['imageName'] . '_distort_' . $hashStringImage . $image_extension;

                                        if (!file_exists($newImagePath)) {

                                            $ratio = $imageMagick->getImageHeight() / ($heightElement * $percentHeight);

                                            exec('convert ' . $rootPathImage . $imageResult['result']['imageName'] . ' -virtual-pixel Transparent -distort Arc "' . $distortingPoints['angle_arcb'] . ' ' . $distortingPoints['start_angle'] . ' ' . ($bigRadius * $ratio) . ' ' . ($distortingPoints['bottom_position'] * $ratio) . '"    -page +0+0 ' . $newImagePath);

                                        }

                                        $adjustmentContainer   = new SimpleXMLElement ('<g></g>');
                                        $adjustmentContainer->addAttribute('transform', "translate(".(-($masterTranslateX)*((1/$masterScaleX)))." ".(-($masterTranslateY)*(1/$masterScaleY)).") scale(".(1/$masterScaleX)." ".(1/$masterScaleY).")");

                                        $boxContainer   = $adjustmentContainer->addChild('g');

                                        $imageContainer = $boxContainer->addChild('image');

                                        $imageContainer->addAttribute('xlink:href', $newImageUrl, 'http://www.w3.org/1999/xlink');
                                        $imageContainer->addAttribute('x', $boxCoords[5]);
                                        $imageContainer->addAttribute('y', $boxCoords[6]);
                                        $imageContainer->addAttribute('width', (string)$boxCoords[7]);
                                        $imageContainer->addAttribute('height', (string)$boxCoords[8]);
                                        $imageContainer->addAttribute('opacity', $opacity);

                                        $boxContainer->addAttribute('transform', 'translate(0 0) scale(1 1)');

                                        $this->simplexml_insert_after($adjustmentContainer, $xmlElement);
                                    }
                                    if ($rect->attributes()->visibility) {
                                        $rect->attributes()->visibility = 'hidden';
                                    } else {
                                        $rect->addAttribute('visibility', 'hidden');
                                    }
                                }
                            }
                        }
                    }
                }
            }



            return $xml->asXML();

        }
        public function getFontPath($fontFamily){
            $fontFamily = (string)$fontFamily;
            $fontFamily = trim($fontFamily);
            $basePath = dirname(__DIR__) . $this->fontsSearchPath . $fontFamily ;

            if(file_exists($basePath.'.ttf'))
                return $basePath.'.ttf';
            if(file_exists($basePath.'.TTF'))
                return $basePath.'.TTF';
           return null;
        }


        public function simplexml_insert_after( SimpleXMLElement $insert, SimpleXMLElement $target ) {
            $target_dom = dom_import_simplexml( $target );
            $insert_dom = $target_dom->ownerDocument->importNode( dom_import_simplexml( $insert ), true );
            if( $target_dom->nextSibling ) {
                return $target_dom->parentNode->insertBefore( $insert_dom, $target_dom->nextSibling );
            }
            else {
                return $target_dom->parentNode->appendChild( $insert_dom );
            }
        }

        public function getHashString( $data ) {

            $string = '';

            foreach( $data as $element ) {

                if( is_array( $element ) ) {

                    foreach( $element as $elem ) {

                        $string = $string . $elem;

                    }
                }
                else {
                    $string = $string . $element;
                }
            }

            return md5( $string );
        }


    }